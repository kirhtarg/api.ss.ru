<?php

namespace App\Services;

use App\Jobs\ProcessYmlPublicPreflightJob;
use App\Models\ShopYandexMarketSyncRun;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class YmlPublicPreflightService
{
    private const BATCH_SIZE = 25;
    private const ISSUE_LIMIT = 100;

    public function start(?int $userId = null): ShopYandexMarketSyncRun
    {
        $active = ShopYandexMarketSyncRun::query()
            ->where('type', 'yml_preflight')
            ->whereIn('status', ['pending', 'running'])
            ->latest()
            ->first();

        if ($active) {
            return $active;
        }

        $path = $this->feedPath();
        $run = ShopYandexMarketSyncRun::create([
            'user_id' => $userId,
            'type' => 'yml_preflight',
            'status' => 'pending',
            'total' => $this->countOffers($path),
            'meta' => [
                'offset' => 0,
                'clean' => 0,
                'warnings' => 0,
                'critical' => 0,
                'issues' => [],
                'mirror' => null,
            ],
        ]);

        ProcessYmlPublicPreflightJob::dispatch($run->id);

        return $run;
    }

    public function cancel(int $runId): ShopYandexMarketSyncRun
    {
        $run = ShopYandexMarketSyncRun::query()
            ->where('type', 'yml_preflight')
            ->findOrFail($runId);

        if (in_array($run->status, ['pending', 'running'], true)) {
            $run->update([
                'status' => 'cancelled',
                'finished_at' => now(),
                'error_message' => 'Остановлено пользователем.',
            ]);
        }

        return $run->fresh();
    }

    public function process(ShopYandexMarketSyncRun $run): void
    {
        $run->refresh();
        if ($run->status === 'cancelled') {
            return;
        }

        $path = $this->feedPath();
        $meta = (array) $run->meta;
        $offset = (int) ($meta['offset'] ?? 0);

        if ($offset === 0) {
            $meta['mirror'] = $this->inspectPublicMirror($path);
        }

        $offers = $this->readOffers($path, $offset, self::BATCH_SIZE);
        if ($offers === []) {
            $run->update([
                'status' => 'completed',
                'meta' => $meta,
                'finished_at' => now(),
            ]);
            return;
        }

        $started = ShopYandexMarketSyncRun::query()
            ->whereKey($run->id)
            ->whereIn('status', ['pending', 'running'])
            ->update(['status' => 'running', 'started_at' => $run->started_at ?: now(), 'error_message' => null]);
        if (! $started) {
            return;
        }
        $run->refresh();
        $responses = Http::pool(function ($pool) use ($offers) {
            foreach ($offers as $offer) {
                $pool->as((string) $offer['id'])->timeout(15)->connectTimeout(5)->get($offer['url']);
                if ($offer['picture'] !== '') {
                    $pool->as('picture:'.$offer['id'])->timeout(15)->connectTimeout(5)->head($offer['picture']);
                }
            }
        });

        foreach ($offers as $offer) {
            $issues = $this->inspectPublicOffer(
                $offer,
                $responses[(string) $offer['id']] ?? null,
                $responses['picture:'.$offer['id']] ?? null,
            );
            if ($issues === []) {
                $meta['clean'] = (int) ($meta['clean'] ?? 0) + 1;
                $run->increment('succeeded');
            } else {
                $hasCritical = collect($issues)->contains('severity', 'critical');
                $meta[$hasCritical ? 'critical' : 'warnings'] = (int) ($meta[$hasCritical ? 'critical' : 'warnings'] ?? 0) + 1;
                if ($hasCritical) {
                    $run->increment('failed');
                } else {
                    $run->increment('succeeded');
                }
                foreach ($issues as $issue) {
                    if (count($meta['issues']) >= self::ISSUE_LIMIT) break;
                    $meta['issues'][] = ['id' => $offer['id'], 'name' => $offer['name'], 'url' => $offer['url']] + $issue;
                }
            }
        }

        $meta['offset'] = $offset + count($offers);
        $run->update(['processed' => $meta['offset'], 'meta' => $meta]);
        $run->refresh();
        if ($run->status === 'cancelled') {
            return;
        }
        ProcessYmlPublicPreflightJob::dispatch($run->id);
    }

    private function feedPath(): string
    {
        $path = Storage::disk('public')->path('exports/goods_feed.xml');
        if (! is_file($path)) throw new \RuntimeException('Файл goods_feed.xml не найден. Сначала сформируйте YML-фид.');
        return $path;
    }

    private function countOffers(string $path): int
    {
        $reader = new \XMLReader();
        $reader->open($path, null, LIBXML_NONET | LIBXML_COMPACT);
        $count = 0;
        while ($reader->read()) if ($reader->nodeType === \XMLReader::ELEMENT && $reader->name === 'offer') $count++;
        $reader->close();
        return $count;
    }

    private function readOffers(string $path, int $offset, int $limit): array
    {
        $reader = new \XMLReader();
        $reader->open($path, null, LIBXML_NONET | LIBXML_COMPACT);
        $items = []; $index = 0;
        while ($reader->read()) {
            if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->name !== 'offer') continue;
            if ($index++ < $offset) continue;
            $xml = simplexml_load_string($reader->readOuterXML());
            if ($xml) $items[] = ['id' => (string) $xml['id'], 'name' => trim((string) $xml->name), 'url' => trim((string) $xml->url), 'price' => (float) $xml->price, 'picture' => trim((string) $xml->picture)];
            if (count($items) >= $limit) break;
        }
        $reader->close();
        return $items;
    }

    private function inspectPublicMirror(string $path): array
    {
        $url = rtrim((string) config('app.frontend_url'), '/') . '/goods_feed.xml';
        try {
            $response = Http::timeout(30)->connectTimeout(5)->get($url);
            if (! $response->successful()) return ['ok' => false, 'message' => "Публичный YML вернул HTTP {$response->status()}"];
            return ['ok' => hash('sha256', $response->body()) === hash_file('sha256', $path), 'url' => $url, 'message' => 'Публичная копия отличается от файла API'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'url' => $url, 'message' => 'Не удалось проверить публичную копию: '.$e->getMessage()];
        }
    }

    private function inspectPublicOffer(array $offer, $response, $pictureResponse): array
    {
        if (! $response || ! $response->successful()) return [['severity' => 'critical', 'message' => 'Карточка недоступна: HTTP '.($response?->status() ?? 0)]];
        if ($offer['picture'] === '') return [['severity' => 'warning', 'message' => 'В YML не передано изображение']];
        if (! $pictureResponse || ! $pictureResponse->successful() || ! str_starts_with(strtolower((string) $pictureResponse->header('Content-Type')), 'image/')) {
            return [['severity' => 'critical', 'message' => 'Изображение YML недоступно или не является файлом изображения']];
        }
        $schema = $this->productSchema($response->body());
        if (! $schema) return [['severity' => 'critical', 'message' => 'На карточке нет Schema.org Product']];
        $data = (array) ($schema['offers'] ?? []);
        $availability = (string) ($data['availability'] ?? '');
        if (! str_ends_with($availability, 'InStock')) return [['severity' => 'critical', 'message' => 'На карточке указано отсутствие товара']];
        $pagePrice = isset($data['price']) ? (float) $data['price'] : (float) ($data['lowPrice'] ?? 0);
        if ($pagePrice <= 0) return [['severity' => 'critical', 'message' => 'На карточке не указана цена в Schema.org']];
        if (abs($pagePrice - $offer['price']) > 0.01) return [['severity' => 'critical', 'message' => "Цена витрины {$pagePrice}, YML {$offer['price']}"]];
        return [];
    }

    private function productSchema(string $html): ?array
    {
        preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/si', $html, $matches);
        foreach ($matches[1] ?? [] as $json) {
            $data = json_decode(trim($json), true);
            if (($data['@type'] ?? null) === 'Product') return $data;
        }
        return null;
    }
}
