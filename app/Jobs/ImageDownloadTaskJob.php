<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class ImageDownloadTaskJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $taskId;

    protected array $urls;

    protected string $storagePath;

    protected array $options;

    public function __construct(string $taskId, array $urls, string $storagePath, array $options = [])
    {
        $this->taskId = $taskId;
        $this->urls = $urls;
        $this->storagePath = $storagePath;
        $this->options = $options;
        $this->onQueue('images-download');
    }

    public function handle(): void
    {
        $metaKey = $this->metaKey();
        $errorsKey = $this->errorsKey();
        $recentKey = $this->recentKey();
        $pathsKey = $this->pathsKey();

        $frontendPublicPath = frontend_public_path();
        $naming = $this->options['naming'] ?? 'hash';
        $timeout = (int) ($this->options['timeout'] ?? 60);
        $connectTimeout = (int) ($this->options['connect_timeout'] ?? 10);
        $concurrency = max(1, min(20, (int) ($this->options['concurrency'] ?? 12)));
        $resize = $this->options['resize'] ?? 'no_change';
        $width = $this->options['width'] ?? null;
        $height = $this->options['height'] ?? null;
        $optimize = (bool) ($this->options['optimize'] ?? true);

        Redis::hMSet($metaKey, [
            'status' => 'running',
            'total' => count($this->urls),
            'queued' => count($this->urls),
            'running' => 0,
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
            'started_at' => time(),
            'updated_at' => time(),
        ]);
        Redis::expire($metaKey, 172800);
        Redis::expire($errorsKey, 172800);
        Redis::expire($recentKey, 172800);
        Redis::expire($pathsKey, 172800);

        $queue = [];
        foreach ($this->urls as $index => $imageUrlRaw) {
            $imageUrl = $this->normalizeUrl((string) $imageUrlRaw);
            if (! $imageUrl) {
                Redis::rpush($errorsKey, json_encode(['url' => $imageUrlRaw, 'error' => 'invalid_url'], JSON_UNESCAPED_UNICODE));
                Redis::hincrby($metaKey, 'failed', 1);
                Redis::hincrby($metaKey, 'queued', -1);

                continue;
            }
            $urlPath = parse_url($imageUrl, PHP_URL_PATH);
            $extension = strtolower(pathinfo($urlPath ?? '', PATHINFO_EXTENSION)) ?: 'jpg';
            if ($naming === 'original') {
                $fileBase = pathinfo($urlPath ?? '', PATHINFO_FILENAME) ?: 'image_'.$index;
                $fileBase = preg_replace('/[^\p{L}\p{N}._-]/u', '_', $fileBase);
                $fileName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileBase).'.'.$extension;
            } else {
                $fileName = hash('sha256', $imageUrl.$index).'.'.$extension;
            }
            $relativePath = rtrim($this->storagePath, '/').'/'.$fileName;
            $absolutePath = $frontendPublicPath.'/'.ltrim($relativePath, '/');
            $normalizedAbsolutePath = realpath($absolutePath) ?: $absolutePath;
            $dir = dirname($normalizedAbsolutePath);
            if (! is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            if (file_exists($normalizedAbsolutePath)) {
                Redis::hset($pathsKey, $imageUrl, $relativePath);
                Redis::hincrby($metaKey, 'success', 1);
                Redis::hincrby($metaKey, 'queued', -1);
                Redis::hincrby($metaKey, 'skipped', 1);
                Redis::rpush($recentKey, json_encode(['url' => $imageUrl, 'status' => 'skip', 'path' => $relativePath], JSON_UNESCAPED_UNICODE));
                Redis::ltrim($recentKey, -200, -1);

                continue;
            }
            $queue[] = [
                'url' => $imageUrl,
                'relativePath' => $relativePath,
                'absolutePath' => $normalizedAbsolutePath,
            ];
        }

        if (count($queue) > 1) {
            shuffle($queue);
        }

        $mh = curl_multi_init();
        $handles = [];
        $active = null;

        $createHandle = function ($item) use ($timeout, $connectTimeout) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $item['url']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            if (defined('CURL_IPRESOLVE_V4')) {
                curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            }
            if (defined('CURL_HTTP_VERSION_2TLS')) {
                curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2TLS);
            } else {
                curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
            }
            curl_setopt($ch, CURLOPT_TCP_KEEPALIVE, 1);
            curl_setopt($ch, CURLOPT_PRIVATE, json_encode($item));

            return $ch;
        };

        $nextIndex = 0;
        for (; $nextIndex < count($queue) && count($handles) < $concurrency; $nextIndex++) {
            $item = $queue[$nextIndex];
            $ch = $createHandle($item);
            $handles[(int) $ch] = $ch;
            curl_multi_add_handle($mh, $ch);
            Redis::hincrby($metaKey, 'running', 1);
            Redis::hincrby($metaKey, 'queued', -1);
        }
        if (function_exists('curl_multi_setopt')) {
            if (defined('CURLMOPT_MAX_HOST_CONNECTIONS')) {
                curl_multi_setopt($mh, CURLMOPT_MAX_HOST_CONNECTIONS, min($concurrency, 6));
            }
        }

        do {
            do {
                $mrc = curl_multi_exec($mh, $active);
            } while ($mrc === CURLM_CALL_MULTI_PERFORM);
            while ($info = curl_multi_info_read($mh)) {
                $ch = $info['handle'];
                $content = curl_multi_getcontent($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);
                $item = json_decode(curl_getinfo($ch, CURLINFO_PRIVATE), true) ?: [];
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
                unset($handles[(int) $ch]);
                Redis::hincrby($metaKey, 'running', -1);

                $url = $item['url'] ?? '';
                if ($content === false || $httpCode !== 200) {
                    Log::warning('ImageDownloadTaskJob: download failed', [
                        'url' => $url,
                        'http_code' => $httpCode,
                        'error' => $error,
                    ]);
                    Redis::rpush($errorsKey, json_encode(['url' => $url, 'error' => $error ?: "HTTP $httpCode"], JSON_UNESCAPED_UNICODE));
                    Redis::ltrim($errorsKey, -200, -1);
                    Redis::hincrby($metaKey, 'skipped', 1);
                    Redis::rpush($recentKey, json_encode(['url' => $url, 'status' => 'skip'], JSON_UNESCAPED_UNICODE));
                    Redis::ltrim($recentKey, -200, -1);
                } else {
                    Log::info('ImageDownloadTaskJob: downloaded image', [
                        'url' => $url,
                        'path' => $item['absolutePath'] ?? null,
                        'http_code' => $httpCode,
                    ]);
                    file_put_contents($item['absolutePath'], $content);
                    if ($optimize || $resize !== 'no_change') {
                        PostProcessImage::dispatch($item['absolutePath'], $resize, $width, $height);
                    }
                    Redis::hset($pathsKey, $url, $item['relativePath']);
                    Redis::hincrby($metaKey, 'success', 1);
                    Redis::rpush($recentKey, json_encode(['url' => $url, 'status' => 'ok', 'path' => $item['relativePath']], JSON_UNESCAPED_UNICODE));
                    Redis::ltrim($recentKey, -200, -1);
                }

                if ($nextIndex < count($queue)) {
                    $nextItem = $queue[$nextIndex++];
                    $chNext = $createHandle($nextItem);
                    $handles[(int) $chNext] = $chNext;
                    curl_multi_add_handle($mh, $chNext);
                    Redis::hincrby($metaKey, 'running', 1);
                    Redis::hincrby($metaKey, 'queued', -1);
                }
                Redis::hset($metaKey, 'updated_at', time());
            }
            if ($active) {
                curl_multi_select($mh, 0.5);
            }
        } while ($active || ! empty($handles));
        curl_multi_close($mh);

        Redis::hMSet($metaKey, [
            'status' => 'done',
            'finished_at' => time(),
            'updated_at' => time(),
        ]);
    }

    protected function normalizeUrl(?string $url): ?string
    {
        if (! $url) {
            return null;
        }
        $url = trim($url);
        if (! preg_match('~^https?://~i', $url)) {
            return null;
        }

        return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }

    protected function metaKey(): string
    {
        return "imgdl:{$this->taskId}:meta";
    }

    protected function errorsKey(): string
    {
        return "imgdl:{$this->taskId}:errors";
    }

    protected function recentKey(): string
    {
        return "imgdl:{$this->taskId}:recent";
    }

    protected function pathsKey(): string
    {
        return "imgdl:{$this->taskId}:paths";
    }
}
