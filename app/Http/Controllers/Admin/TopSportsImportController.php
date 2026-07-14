<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class TopSportsImportController extends Controller
{
    private const ALLOWED_HOST = 'b2b.topsports.ru';

    private const CATALOG_PATH = '/api/catalog/csv';

    private const MAX_FILE_SIZE = 125829120; // 120 MB

    public function downloadCsv(Request $request)
    {
        $validated = $request->validate([
            'username' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url:https', 'max:2048'],
        ]);

        $catalogUrl = $this->validateCatalogUrl($validated['url']);
        if ($catalogUrl === null) {
            return response()->json([
                'success' => false,
                'message' => 'Разрешен только метод https://b2b.topsports.ru/api/catalog/csv.',
            ], 422);
        }

        $tempPath = null;

        try {
            $loginResponse = Http::acceptJson()
                ->asJson()
                ->timeout(30)
                ->connectTimeout(10)
                ->withOptions($this->httpOptions())
                ->post('https://'.self::ALLOWED_HOST.'/api/login', [
                    'username' => $validated['username'],
                    'password' => $validated['password'],
                ]);

            if ($loginResponse->status() === 401 || $loginResponse->status() === 403) {
                return response()->json([
                    'success' => false,
                    'message' => 'TopSports отклонил логин или API-пароль.',
                ], 401);
            }

            if (! $loginResponse->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось авторизоваться в API TopSports (HTTP '.$loginResponse->status().').',
                ], 502);
            }

            $token = trim((string) $loginResponse->json('token'));
            if ($token === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'API TopSports не вернул токен авторизации.',
                ], 502);
            }

            $tempDirectory = storage_path('app/temp');
            if (! is_dir($tempDirectory) && ! mkdir($tempDirectory, 0755, true) && ! is_dir($tempDirectory)) {
                throw new \RuntimeException('Не удалось создать временную директорию импорта.');
            }

            $tempPath = $tempDirectory.'/topsports_'.Str::uuid().'.csv';
            $catalogResponse = Http::withToken($token)
                ->accept('text/csv, text/plain, application/octet-stream, */*')
                ->timeout(180)
                ->connectTimeout(15)
                ->withOptions($this->httpOptions([
                    'allow_redirects' => false,
                    'sink' => $tempPath,
                ]))
                ->get($catalogUrl);

            if (! $catalogResponse->successful()) {
                $status = $catalogResponse->status();
                $this->deleteTempFile($tempPath);
                $tempPath = null;

                return response()->json([
                    'success' => false,
                    'message' => $status === 401 || $status === 403
                        ? 'TopSports не разрешил загрузку каталога. Проверьте доступ API.'
                        : 'Не удалось загрузить CSV-каталог TopSports (HTTP '.$status.').',
                ], $status === 401 || $status === 403 ? 401 : 502);
            }

            $fileSize = is_file($tempPath) ? filesize($tempPath) : 0;
            if ($fileSize === false || $fileSize <= 0) {
                throw new \RuntimeException('TopSports вернул пустой CSV-файл.');
            }

            if ($fileSize > self::MAX_FILE_SIZE) {
                return response()->json([
                    'success' => false,
                    'message' => 'CSV-файл TopSports превышает допустимый размер 120 МБ.',
                ], 413);
            }

            $prefix = (string) file_get_contents($tempPath, false, null, 0, 512);
            if (preg_match('/^\s*(?:<!doctype\s+html|<html|\{\s*"(?:error|message)"\s*:)/i', $prefix)) {
                throw new \RuntimeException('TopSports вернул ответ с ошибкой вместо CSV-файла.');
            }

            $download = response()->download(
                $tempPath,
                'topsports_catalog_'.now()->format('Y-m-d_H-i-s').'.csv',
                [
                    'Content-Type' => 'text/csv; charset=UTF-8',
                    'Cache-Control' => 'no-store, private',
                    'X-Content-Type-Options' => 'nosniff',
                ]
            );
            $download->deleteFileAfterSend(true);
            $tempPath = null;

            return $download;
        } catch (ConnectionException $e) {
            report($e);

            $sslMessage = str_contains(strtolower($e->getMessage()), 'certificate')
                || str_contains(strtolower($e->getMessage()), 'curl error 60');

            return response()->json([
                'success' => false,
                'message' => $sslMessage
                    ? 'Не удалось проверить SSL-сертификат TopSports. Настройте TOPSPORTS_CA_BUNDLE или проверку SSL для локальной среды.'
                    : 'Не удалось подключиться к API TopSports. Проверьте доступность сервиса и сетевое подключение.',
            ], 502);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Не удалось получить CSV TopSports. Подробности записаны в журнал сервера.',
            ], 502);
        } finally {
            if ($tempPath !== null) {
                $this->deleteTempFile($tempPath);
            }
        }
    }

    private function validateCatalogUrl(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        $path = '/'.ltrim((string) ($parts['path'] ?? ''), '/');
        $port = $parts['port'] ?? null;

        if (
            $scheme !== 'https'
            || $host !== self::ALLOWED_HOST
            || ($port !== null && (int) $port !== 443)
            || rtrim($path, '/') !== self::CATALOG_PATH
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
        ) {
            return null;
        }

        return 'https://'.self::ALLOWED_HOST.self::CATALOG_PATH
            .(isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '');
    }

    private function httpOptions(array $options = []): array
    {
        $caBundlePath = trim((string) config('services.topsports.ca_bundle_path', ''));
        $verifySetting = config('services.topsports.verify_ssl', true);
        $verifySsl = filter_var($verifySetting, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        $options['verify'] = $caBundlePath !== '' ? $caBundlePath : ($verifySsl ?? true);

        return $options;
    }

    private function deleteTempFile(?string $path): void
    {
        if ($path && is_file($path)) {
            @unlink($path);
        }
    }
}
