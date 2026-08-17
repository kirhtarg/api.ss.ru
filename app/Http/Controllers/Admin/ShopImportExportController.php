<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopBrand;
use App\Models\ShopCategory;
use App\Models\ShopGood;
use App\Models\ShopProperty;
use App\Models\ShopTag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ShopImportExportController extends Controller
{
    /**
     * Экспорт товаров в CSV
     */
    public function exportCsv(Request $request): JsonResponse
    {
        try {
            // Получаем товары для экспорта через обычный index метод с параметром for_export
            $goodsController = new \App\Http\Controllers\Admin\ShopGoodsController;
            $exportRequest = new \Illuminate\Http\Request;
            $exportRequest->merge($request->all());
            $exportRequest->merge(['for_export' => '1']);

            $goodsResponse = $goodsController->index($exportRequest);

            if (! $goodsResponse->getData()->success) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка получения товаров для экспорта: '.($goodsResponse->getData()->message ?? 'Неизвестная ошибка'),
                ], 500);
            }

            $goods = collect($goodsResponse->getData()->data);

            // Создаем CSV файл
            $filename = 'goods_export_'.date('Y-m-d_H-i-s').'.csv';
            $filepath = 'exports/'.$filename;

            // Создаем директорию если не существует
            if (! Storage::disk('public')->exists('exports')) {
                Storage::disk('public')->makeDirectory('exports');
            }

            $csvData = $this->generateCsvData($goods);
            Storage::disk('public')->put($filepath, $csvData);

            return response()->json([
                'success' => true,
                'message' => 'Экспорт завершен',
                'data' => [
                    'filename' => $filename,
                    'download_url' => Storage::disk('public')->url($filepath),
                    'count' => $goods->count(),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка экспорта: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Экспорт товаров в Excel
     */
    public function exportExcel(Request $request): JsonResponse
    {
        // Здесь можно добавить экспорт в Excel используя библиотеку PhpSpreadsheet
        // Пока что возвращаем сообщение о том, что функция в разработке
        return response()->json([
            'success' => false,
            'message' => 'Экспорт в Excel будет доступен в следующей версии',
        ], 501);
    }

    /**
     * Экспорт товаров в YML (Yandex Market Language)
     */
    public function exportYml(Request $request, \App\Services\YmlFeedService $ymlService): JsonResponse
    {
        $result = $ymlService->generate();

        if ($result['success']) {
            return response()->json([
                'success' => true,
                'message' => 'YML фид успешно сгенерирован',
                'data' => $result,
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Ошибка генерации YML: '.$result['message'],
        ], 500);
    }


    /**
     * Получение статуса YML фида
     */
    public function getYmlStatus(): JsonResponse
    {
        try {
            $filename = 'goods_feed.xml';
            $filepath = 'exports/' . $filename;

            $data = [
                'exists' => false,
                'filename' => $filename,
                'schedule' => [
                    'frequency' => DB::table('settings')->where('group', 'shop')->where('key', 'yml_feed_regeneration_frequency')->value('value') ?? 'daily',
                    'time' => DB::table('settings')->where('group', 'shop')->where('key', 'yml_feed_regeneration_time')->value('value') ?? '03:00',
                ],
            ];

            if (Storage::disk('public')->exists($filepath)) {
                $url = Storage::disk('public')->url($filepath);
                $size = Storage::disk('public')->size($filepath);
                $lastModified = Storage::disk('public')->lastModified($filepath);

                $data['exists'] = true;
                $data['download_url'] = $url;
                $data['generated_at'] = date('Y-m-d H:i:s', $lastModified);
                $data['size'] = round($size / 1024, 2) . ' KB';

                // Проверяем наличие на фронтенде
                $frontendPathRelative = config('frontend.path');
                if ($frontendPathRelative) {
                    $frontendBasePath = base_path($frontendPathRelative);
                    $frontendPublicPath = $frontendBasePath . '/public';

                    if (is_dir($frontendPublicPath) && file_exists($frontendPublicPath . '/' . $filename)) {
                        $data['frontend_url'] = config('app.frontend_url') . '/' . $filename;
                    }
                }
            }

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения статуса YML: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Сохранение настроек расписания YML
     */
    public function updateYmlSchedule(Request $request): JsonResponse
    {
        $request->validate([
            'frequency' => 'required|string|in:hourly,daily,weekly',
            'time' => 'required|string|regex:/^[0-9]{2}:[0-9]{2}$/',
        ]);

        try {
            DB::table('settings')
                ->where('group', 'shop')
                ->where('key', 'yml_feed_regeneration_frequency')
                ->update(['value' => $request->frequency]);

            DB::table('settings')
                ->where('group', 'shop')
                ->where('key', 'yml_feed_regeneration_time')
                ->update(['value' => $request->time]);

            return response()->json([
                'success' => true,
                'message' => 'Настройки расписания обновлены',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка сохранения настроек: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function getYandexMarketStockSettings(\App\Services\YandexMarketStockService $stockService): JsonResponse
    {
        $settings = $stockService->getSettings();

        return response()->json([
            'success' => true,
            'data' => [
                ...$settings,
                'auth_token_masked' => $settings['auth_token'] !== '' ? '********' : '',
                'auth_token' => '',
            ],
        ]);
    }

    public function updateYandexMarketStockSettings(Request $request, \App\Services\YandexMarketStockService $stockService): JsonResponse
    {
        $data = $request->validate([
            'campaign_id' => 'nullable|string|max:50',
            'auth_token' => 'nullable|string|max:500',
            'auth_type' => 'nullable|string|in:api_key,oauth',
            'dimension_multipliers' => 'nullable|array',
            'dimension_multipliers.weight' => 'nullable|numeric|gt:0|max:1000000',
            'dimension_multipliers.length' => 'nullable|numeric|gt:0|max:1000000',
            'dimension_multipliers.width' => 'nullable|numeric|gt:0|max:1000000',
            'dimension_multipliers.height' => 'nullable|numeric|gt:0|max:1000000',
        ]);

        $current = $stockService->getSettings();
        if (empty($data['auth_token']) && ! empty($current['auth_token'])) {
            $data['auth_token'] = $current['auth_token'];
        }

        $settings = $stockService->saveSettings($data);

        return response()->json([
            'success' => true,
            'message' => 'Настройки Яндекс Маркета сохранены',
            'data' => [
                ...$settings,
                'auth_token_masked' => $settings['auth_token'] !== '' ? '********' : '',
                'auth_token' => '',
            ],
        ]);
    }

    public function syncYandexMarketStocks(Request $request, \App\Services\YandexMarketStockService $stockService): JsonResponse
    {
        try {
            $run = $stockService->startStockSync($request->user()?->id);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $run->type === 'stocks'
                ? ($run->status === 'pending' ? 'Отправка остатков поставлена в очередь' : 'Отправка остатков уже выполняется')
                : 'Дождитесь завершения текущей сверки остатков',
            'data' => $stockService->serializeRun($run),
        ], 202);
    }

    public function getYandexMarketStockRuns(\App\Services\YandexMarketStockService $stockService): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $stockService->recentRuns()]);
    }

    public function verifyYandexMarketStocks(Request $request, \App\Services\YandexMarketStockService $stockService): JsonResponse
    {
        try {
            $run = $stockService->startStockVerification($request->user()?->id);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $run->type === 'stock_verification' ? 'Проверка остатков поставлена в очередь' : 'Дождитесь завершения текущей операции',
            'data' => $stockService->serializeRun($run),
        ], 202);
    }

    public function getYandexMarketStockRun(int $run, \App\Services\YandexMarketStockService $stockService): JsonResponse
    {
        $model = \App\Models\ShopYandexMarketSyncRun::findOrFail($run);

        return response()->json(['success' => true, 'data' => $stockService->serializeRun($model)]);
    }

    public function auditYandexMarketFeed(Request $request, \App\Services\YandexMarketStockService $stockService, \App\Services\YandexMarketFeedAuditService $auditService): JsonResponse
    {
        $filters = $request->validate([
            'scope' => 'nullable|string|in:all,issues,clean',
            'severity' => 'nullable|string|in:critical,warning',
            'query' => 'nullable|string|max:255',
        ]);
        $path = $stockService->getFeedPath();
        if (! $path) {
            return response()->json(['success' => false, 'message' => 'Файл goods_feed.xml не найден.'], 404);
        }

        return response()->json(['success' => true, 'data' => $auditService->audit($path, $filters)]);
    }

    public function startYmlPublicPreflight(Request $request, \App\Services\YmlPublicPreflightService $preflightService): JsonResponse
    {
        try {
            $run = $preflightService->start($request->user()?->id);

            return response()->json([
                'success' => true,
                'message' => 'Предпроверка витрины запущена в фоне.',
                'data' => app(\App\Services\YandexMarketStockService::class)->serializeRun($run),
            ], 202);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function cancelYmlPublicPreflight(int $run, \App\Services\YmlPublicPreflightService $preflightService): JsonResponse
    {
        try {
            $result = $preflightService->cancel($run);

            return response()->json([
                'success' => true,
                'message' => 'Предпроверка остановлена.',
                'data' => app(\App\Services\YandexMarketStockService::class)->serializeRun($result),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['success' => false, 'message' => 'Запуск предпроверки не найден.'], 404);
        }
    }

    /**
     * Генерация Sitemap
     */
    public function generateSitemap(Request $request): JsonResponse
    {
        try {
            $frontendUrl = config('app.frontend_url', 'https://skateandsnow.ru');
            $frontendUrl = rtrim($frontendUrl, '/');
            $escapeXml = static fn ($value): string => htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $xml = '<?xml version="1.0" encoding="UTF-8"?>'.PHP_EOL;
            $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.PHP_EOL;
            $xml .= '    <!-- build:'.date('c').' env='.config('app.env').' -->'.PHP_EOL;

            // 1. Главная страница
            $xml .= '    <url>'.PHP_EOL;
            $xml .= '        <loc>'.$escapeXml($frontendUrl).'</loc>'.PHP_EOL;
            $xml .= '        <changefreq>daily</changefreq>'.PHP_EOL;
            $xml .= '        <priority>1.0</priority>'.PHP_EOL;
            $xml .= '    </url>'.PHP_EOL;

            $pagesCount = 1;

            // 2. Страницы конструктора
            $constructorPages = \App\Models\ConstructorPage::where('is_published', true)->get();
            foreach ($constructorPages as $page) {
                $slug = trim($page->slug, '/');
                if (empty($slug)) {
                    continue;
                }

                $xml .= '    <url>'.PHP_EOL;
                $xml .= '        <loc>'.$escapeXml($frontendUrl.'/'.$slug).'</loc>'.PHP_EOL;
                $xml .= '        <lastmod>'.$page->updated_at->toIso8601String().'</lastmod>'.PHP_EOL;
                $xml .= '        <changefreq>weekly</changefreq>'.PHP_EOL;
                $xml .= '        <priority>0.8</priority>'.PHP_EOL;
                $xml .= '    </url>'.PHP_EOL;
                $pagesCount++;
            }

            // 3. Категории каталога
            $categories = \App\Models\ShopCategory::where('is_active', true)
                ->whereNotNull('slug')
                ->where('slug', '<>', '')
                ->get();

            // Создаем карту категорий для построения путей
            $categoryMap = [];
            foreach ($categories as $cat) {
                $categoryMap[$cat->id] = $cat;
            }

            // Функция для построения полного пути категории
            $buildCategoryPath = function ($categoryId) use ($categoryMap) {
                $path = [];
                $currentId = $categoryId;
                $seen = []; // Защита от рекурсии

                while ($currentId && isset($categoryMap[$currentId]) && ! isset($seen[$currentId])) {
                    $cat = $categoryMap[$currentId];
                    array_unshift($path, $cat->slug);
                    $seen[$currentId] = true;
                    $currentId = $cat->parent_id;
                }

                // Активная дочерняя категория с отсутствующим/неактивным
                // предком не имеет рабочего публичного иерархического URL.
                if ($currentId) {
                    return '';
                }

                return implode('/', $path);
            };

            foreach ($categories as $category) {
                $path = $buildCategoryPath($category->id);
                if (empty($path)) {
                    continue;
                }

                $xml .= '    <url>'.PHP_EOL;
                $xml .= '        <loc>'.$escapeXml($frontendUrl.'/catalog/'.$path).'</loc>'.PHP_EOL;
                $xml .= '        <lastmod>'.$category->updated_at->toIso8601String().'</lastmod>'.PHP_EOL;
                $xml .= '        <changefreq>weekly</changefreq>'.PHP_EOL;
                $xml .= '        <priority>0.9</priority>'.PHP_EOL;
                $xml .= '    </url>'.PHP_EOL;
                $pagesCount++;
            }

            // 4. Товары
            // Загружаем товары с категориями для формирования красивых ссылок
            // Используем chunk для экономии памяти, если товаров много
            \App\Models\ShopGood::where('is_active', true)
                ->whereNotNull('slug')
                ->where('slug', '<>', '')
                ->whereHas('categories', function ($query) {
                    $query->where('shop_categories.is_active', true)
                        ->whereNotNull('shop_categories.slug')
                        ->where('shop_categories.slug', '<>', '');
                })
                ->with(['categories' => function ($query) {
                    $query->where('shop_categories.is_active', true)
                        ->whereNotNull('shop_categories.slug')
                        ->where('shop_categories.slug', '<>', '')
                        ->select('shop_categories.id', 'shop_categories.slug', 'shop_categories.parent_id');
                }])
                ->chunkById(500, function ($goods) use (&$xml, $frontendUrl, &$pagesCount, $buildCategoryPath, $escapeXml) {
                    foreach ($goods as $good) {
                        $catPath = '';

                        // Пытаемся найти главную категорию
                        if ($good->categories->isNotEmpty()) {
                            $category = $good->categories->first();
                            $catPath = $buildCategoryPath($category->id);
                        }

                        $urlPath = $catPath ? '/catalog/'.$catPath.'/'.$good->slug : '/catalog/'.$good->slug;

                        $xml .= '    <url>'.PHP_EOL;
                        $xml .= '        <loc>'.$escapeXml($frontendUrl.$urlPath).'</loc>'.PHP_EOL;
                        $xml .= '        <lastmod>'.$good->updated_at->toIso8601String().'</lastmod>'.PHP_EOL;
                        $xml .= '        <changefreq>weekly</changefreq>'.PHP_EOL;
                        $xml .= '        <priority>0.7</priority>'.PHP_EOL;
                        $xml .= '    </url>'.PHP_EOL;
                        $pagesCount++;
                    }
                });

            // 5. Только явно разрешенные публичные страницы. Сканирование pages/
            // добавляло checkout, payment-status и внутренние служебные маршруты.
            $staticRoutes = [
                '/catalog', '/advantages', '/club', '/contacts', '/cooperation',
                '/delivery', '/discount-cards', '/faq', '/longrent', '/privacy',
                '/rentbikespb', '/reviews', '/size-chart', '/terms', '/tips',
                '/warranty', '/wholesale',
            ];

            foreach ($staticRoutes as $urlPath) {
                $xml .= '    <url>'.PHP_EOL;
                $xml .= '        <loc>'.$escapeXml($frontendUrl.$urlPath).'</loc>'.PHP_EOL;
                $xml .= '        <changefreq>monthly</changefreq>'.PHP_EOL;
                $xml .= '        <priority>0.5</priority>'.PHP_EOL;
                $xml .= '    </url>'.PHP_EOL;
                $pagesCount++;
            }

            $xml .= '</urlset>';

            $filename = 'sitemap.xml';
            $filepath = 'exports/'.$filename;

            if (! Storage::disk('public')->exists('exports')) {
                Storage::disk('public')->makeDirectory('exports');
            }

            Storage::disk('public')->put($filepath, $xml);

            \Log::info('[FIX:seo] Sitemap generated', [
                'pages_count' => $pagesCount,
                'environment' => config('app.env'),
                'frontend_url' => $frontendUrl,
            ]);

            $url = Storage::disk('public')->url($filepath);
            $size = Storage::disk('public')->size($filepath);
            $lastModified = Storage::disk('public')->lastModified($filepath);

            return response()->json([
                'success' => true,
                'message' => 'Sitemap успешно сгенерирован',
                'data' => [
                    'filename' => $filename,
                    'download_url' => $url,
                    // Nuxt получает sitemap через server route. Статический
                    // public/sitemap.xml затеняет его и поэтому не создается.
                    'frontend_url' => config('app.frontend_url').'/'.$filename,
                    'generated_at' => date('Y-m-d H:i:s', $lastModified),
                    'size' => round($size / 1024, 2).' KB',
                    'pages_count' => $pagesCount,
                ],
            ]);

        } catch (\Exception $e) {
            \Log::error('[FIX:seo] Sitemap generation failed', [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка генерации Sitemap: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получение статуса Sitemap
     */
    public function getSitemapStatus(): JsonResponse
    {
        try {
            $filename = 'sitemap.xml';
            $filepath = 'exports/'.$filename;

            if (Storage::disk('public')->exists($filepath)) {
                $url = Storage::disk('public')->url($filepath);
                $size = Storage::disk('public')->size($filepath);
                $lastModified = Storage::disk('public')->lastModified($filepath);

                $frontendPathRelative = config('frontend.path');
                $frontendUrl = null;

                if ($frontendPathRelative) {
                    $frontendBasePath = base_path($frontendPathRelative);
                    $frontendPublicPath = $frontendBasePath.'/public';

                    if (is_dir($frontendPublicPath) && file_exists($frontendPublicPath.'/'.$filename)) {
                        $frontendUrl = config('app.frontend_url').'/'.$filename;
                    }
                }

                return response()->json([
                    'success' => true,
                    'data' => [
                        'exists' => true,
                        'filename' => $filename,
                        'download_url' => $url,
                        'frontend_url' => $frontendUrl,
                        'generated_at' => date('Y-m-d H:i:s', $lastModified),
                        'size' => round($size / 1024, 2).' KB',
                    ],
                ]);
            } else {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'exists' => false,
                    ],
                ]);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения статуса Sitemap: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Генерация robots.txt
     */
    public function generateRobots(Request $request): JsonResponse
    {
        try {
            $mode = $request->input('mode', 'open'); // 'open' or 'closed'
            $frontendUrl = config('app.frontend_url', 'https://skateandsnow.ru');

            $trackingCleanParams = 'etext&utm_source&utm_medium&utm_campaign&utm_content&utm_term&yclid&ymclid&gclid&fbclid&from&roistat&openstat';

            if ($mode === 'closed') {
                $content = "User-agent: *\n";
                $content .= "Disallow: /\n";
            } else {
                // Open mode
                $content = "User-agent: *\n";
                $content .= "Disallow: /admin\n";
                $content .= "Disallow: /cms\n";
                $content .= "Allow: /\n";
                $content .= "Clean-param: {$trackingCleanParams} /\n";
                $content .= 'Sitemap: '.$frontendUrl."/sitemap.xml\n";
            }

            $filename = 'robots.txt';
            $filepath = 'exports/'.$filename;

            if (! Storage::disk('public')->exists('exports')) {
                Storage::disk('public')->makeDirectory('exports');
            }
            Storage::disk('public')->put($filepath, $content);

            $url = Storage::disk('public')->url($filepath);
            $lastModified = time();

            return response()->json([
                'success' => true,
                'message' => 'robots.txt успешно сгенерирован ('.($mode === 'closed' ? 'закрыт' : 'открыт').')',
                'data' => [
                    'filename' => $filename,
                    'download_url' => $url,
                    'frontend_url' => null,
                    'generated_at' => date('Y-m-d H:i:s', $lastModified),
                    'mode' => $mode,
                    'content' => $content,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка генерации robots.txt: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получение статуса robots.txt
     */
    public function getRobotsStatus(): JsonResponse
    {
        try {
            $filename = 'robots.txt';
            $mode = 'unknown';
            $exists = false;
            $lastModified = null;
            $content = '';

            $filepath = 'exports/'.$filename;
            if (Storage::disk('public')->exists($filepath)) {
                $exists = true;
                $content = Storage::disk('public')->get($filepath);
                $lastModified = Storage::disk('public')->lastModified($filepath);
            }

            if ($exists) {
                // Определяем режим по содержимому
                // Если есть "Disallow: /" в начале строки (или после пробелов) и это конец строки
                if (preg_match('/^\s*Disallow:\s*\/\s*$/m', $content)) {
                    $mode = 'closed';
                } else {
                    $mode = 'open';
                }

                return response()->json([
                    'success' => true,
                    'data' => [
                        'exists' => true,
                        'filename' => $filename,
                        'frontend_url' => null,
                        'generated_at' => date('Y-m-d H:i:s', $lastModified),
                        'mode' => $mode,
                    ],
                ]);
            } else {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'exists' => false,
                        'mode' => 'unknown',
                    ],
                ]);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения статуса robots.txt: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Импорт товаров из CSV
     */
    public function importCsv(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:csv,txt|max:10240', // 10MB
            'update_existing' => 'boolean',
            'skip_errors' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $file = $request->file('file');
            $updateExisting = $request->boolean('update_existing', false);
            $skipErrors = $request->boolean('skip_errors', false);

            $csvData = $this->parseCsvFile($file);
            $result = $this->importGoodsFromCsv($csvData, $updateExisting, $skipErrors);

            return response()->json([
                'success' => true,
                'message' => 'Импорт завершен',
                'data' => $result,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка импорта: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить шаблон для импорта
     */
    public function getTemplate(): JsonResponse
    {
        try {
            $filename = 'goods_import_template.csv';
            $filepath = 'templates/'.$filename;

            // Создаем директорию если не существует
            if (! Storage::disk('public')->exists('templates')) {
                Storage::disk('public')->makeDirectory('templates');
            }

            // Создаем шаблон CSV
            $templateData = $this->generateTemplateCsv();
            Storage::disk('public')->put($filepath, $templateData);

            return response()->json([
                'success' => true,
                'data' => [
                    'filename' => $filename,
                    'download_url' => Storage::disk('public')->url($filepath),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания шаблона: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Применение фильтров для экспорта (аналогично ShopGoodsController@index)
     */
    private function applyExportFilters($query, Request $request): void
    {
        // Поиск
        if ($request->filled('search')) {
            $search = $request->get('search');
            $query->search($search);
        }

        // Фильтр по категории
        if ($request->filled('category_id')) {
            $query->byCategory($request->get('category_id'));
        }

        // Фильтр по множественным категориям
        if ($request->has('categories')) {
            $categoryIds = $request->input('categories');
            if (is_array($categoryIds) && ! empty($categoryIds)) {
                $query->whereHas('categories', function ($q) use ($categoryIds) {
                    $q->whereIn('shop_categories.id', $categoryIds);
                });
            }
        }

        // Фильтр по бренду
        if ($request->filled('brand_id')) {
            $query->byBrand($request->get('brand_id'));
        }

        // Фильтр по множественным брендам
        if ($request->has('brands')) {
            $brandIds = $request->input('brands');
            if (is_array($brandIds) && ! empty($brandIds)) {
                $query->whereHas('brands', function ($q) use ($brandIds) {
                    $q->whereIn('shop_brands.id', $brandIds);
                });
            }
        }

        // Фильтр по тегам
        if ($request->has('tags')) {
            $tagIds = $request->input('tags');
            if (is_array($tagIds) && ! empty($tagIds)) {
                $query->whereHas('tags', function ($q) use ($tagIds) {
                    $q->whereIn('shop_tags.id', $tagIds);
                });
            }
        }

        // Исключение тегов
        if ($request->has('exclude_tags')) {
            $excludeTagIds = $request->input('exclude_tags');
            if (is_array($excludeTagIds) && ! empty($excludeTagIds)) {
                $query->whereDoesntHave('tags', function ($q) use ($excludeTagIds) {
                    $q->whereIn('shop_tags.id', $excludeTagIds);
                });
            }
        }

        // Фильтр по лейблам
        if ($request->has('labels')) {
            $labelIds = $request->input('labels');
            if (is_array($labelIds) && ! empty($labelIds)) {
                $query->whereIn('label_id', $labelIds);
            }
        }

        // Фильтр по свойствам
        if ($request->has('properties')) {
            $properties = $request->input('properties');
            if (is_array($properties)) {
                foreach ($properties as $propertyId => $valueIds) {
                    if (is_array($valueIds) && ! empty($valueIds)) {
                        $query->whereHas('properties', function ($q) use ($propertyId, $valueIds) {
                            $q->where('shop_properties.id', $propertyId)
                                ->whereIn('shop_property_value_id', $valueIds);
                        });
                    }
                }
            }
        }

        // Фильтр по поставщикам
        if ($request->has('suppliers')) {
            $supplierIds = $request->input('suppliers');
            if (is_array($supplierIds) && ! empty($supplierIds)) {
                $query->whereIn('supplier', $supplierIds);
            }
        }

        // Фильтр по демпингу
        if ($request->filled('has_demping')) {
            $hasDemping = $request->boolean('has_demping');
            if ($hasDemping) {
                $query->where('show_demping', true);
            } else {
                $query->where('show_demping', false);
            }
        }

        // Фильтр по демпинговой цене
        if ($request->filled('has_demping_price')) {
            $hasDempingPrice = $request->boolean('has_demping_price');
            if ($hasDempingPrice) {
                $query->whereNotNull('demping_price');
            } else {
                $query->whereNull('demping_price');
            }
        }

        // Фильтр по акционной цене
        if ($request->filled('has_sale_price')) {
            $hasSalePrice = $request->boolean('has_sale_price');
            if ($hasSalePrice) {
                $query->whereNotNull('sale_price');
            } else {
                $query->whereNull('sale_price');
            }
        }

        // Фильтр по лейблу
        if ($request->filled('has_label')) {
            $hasLabel = $request->boolean('has_label');
            if ($hasLabel) {
                $query->whereNotNull('label_id');
            } else {
                $query->whereNull('label_id');
            }
        }

        // Фильтр по тегам
        if ($request->filled('has_tags')) {
            $hasTags = $request->boolean('has_tags');
            if ($hasTags) {
                $query->whereHas('tags');
            } else {
                $query->whereDoesntHave('tags');
            }
        }

        // Фильтр по количеству характеристик
        if ($request->filled('properties_count_type')) {
            $countType = $request->get('properties_count_type');
            if ($countType === 'exact' && $request->filled('properties_count')) {
                $count = (int) $request->get('properties_count');
                $query->whereHas('properties', function ($q) use ($count) {
                    $q->havingRaw('COUNT(*) = ?', [$count]);
                }, '=', $count);
            }
        }

        // Фильтр по артикулу
        if ($request->filled('sku_filter_type')) {
            $skuFilterType = $request->get('sku_filter_type');
            if ($skuFilterType === 'empty') {
                $query->where(function ($q) {
                    $q->whereNull('sku')
                        ->orWhere('sku', '=', '');
                });
            }
        }

        // Фильтр по цене
        if ($request->filled('min_price') || $request->filled('max_price')) {
            if ($request->filled('min_price')) {
                $query->where('price', '>=', $request->get('min_price'));
            }
            if ($request->filled('max_price')) {
                $query->where('price', '<=', $request->get('max_price'));
            }
        }

        // Фильтр по остатку
        if ($request->filled('stock_quantity_min') || $request->filled('stock_quantity_max')) {
            if ($request->filled('stock_quantity_min')) {
                $query->where('stock_quantity', '>=', $request->get('stock_quantity_min'));
            }
            if ($request->filled('stock_quantity_max')) {
                $query->where('stock_quantity', '<=', $request->get('stock_quantity_max'));
            }
        }

        // Фильтр по удаленному остатку
        if ($request->filled('remote_stock_quantity')) {
            $query->where('remote_stock_quantity', $request->get('remote_stock_quantity'));
        }

        // Фильтр по быстрому удаленному остатку
        if ($request->filled('fast_remote_stock_quantity')) {
            $query->where('fast_remote_stock_quantity', $request->get('fast_remote_stock_quantity'));
        }

        // Фильтр по статусу активности
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Фильтр по статусу "Рекомендуемый"
        if ($request->has('is_featured')) {
            $query->where('is_featured', $request->boolean('is_featured'));
        }

        // Фильтр по статусу "Новый"
        if ($request->has('is_new')) {
            $query->where('is_new', $request->boolean('is_new'));
        }

        // Фильтр по статусу "Со скидкой"
        if ($request->has('is_sale')) {
            $query->where('is_sale', $request->boolean('is_sale'));
        }

        // Фильтр по статусу "Доступен к заказу"
        if ($request->has('is_preorder')) {
            $query->where('is_preorder', $request->boolean('is_preorder'));
        }

        // Фильтр по статусу "Показывать"
        if ($request->has('is_show')) {
            $query->where('is_show', $request->boolean('is_show'));
        }

        // Фильтр по наличию вариаций
        if ($request->filled('has_variations')) {
            $hasVariations = $request->boolean('has_variations');
            if ($hasVariations) {
                $query->has('variations');
            } else {
                $query->doesntHave('variations');
            }
        }

        // Фильтр по наличию категорий
        if ($request->filled('has_categories')) {
            $hasCategories = $request->boolean('has_categories');
            if ($hasCategories) {
                $query->has('categories');
            } else {
                $query->doesntHave('categories');
            }
        }

        // Фильтр по наличию брендов
        if ($request->filled('has_brands')) {
            $hasBrands = $request->boolean('has_brands');
            if ($hasBrands) {
                $query->has('brands');
            } else {
                $query->doesntHave('brands');
            }
        }

        // Фильтр по наличию на складе
        if ($request->filled('in_stock')) {
            $inStock = $request->boolean('in_stock');
            if ($inStock) {
                $query->where('stock_quantity', '>', 0);
            } else {
                $query->where('stock_quantity', '<=', 0);
            }
        }

        // Фильтр по атрибутам вариаций (товары С этими атрибутами)
        if ($request->has('variation_attribute_names')) {
            $attributeNames = $request->input('variation_attribute_names');
            if (is_array($attributeNames) && ! empty($attributeNames)) {
                // Фильтруем товары, у которых есть вариации с указанными атрибутами
                $query->whereExists(function ($subQuery) use ($attributeNames) {
                    $subQuery->selectRaw('1')
                        ->from('shop_good_variations as v')
                        ->join('shop_variation_attributes_values as vav', 'v.id', '=', 'vav.variation_id')
                        ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
                        ->join('shop_variation_attributes as a', 'a.id', '=', 'av.attribute_id')
                        ->whereRaw('v.good_id = shop_goods.id')
                        ->whereIn('a.name', $attributeNames)
                        ->limit(1);
                });
            }
        }

        // Исключение атрибутов вариаций (товары БЕЗ этих атрибутов)
        if ($request->has('exclude_variation_attribute_names')) {
            $excludeAttributeNames = $request->input('exclude_variation_attribute_names');
            if (is_array($excludeAttributeNames) && ! empty($excludeAttributeNames)) {
                // Когда применяется фильтр "БЕЗ атрибутов", показываем ТОЛЬКО товары с вариациями,
                // которые НЕ имеют указанные атрибуты
                // Используем подзапрос для точного контроля
                $query->whereExists(function ($subQuery) {
                    $subQuery->selectRaw('1')
                        ->from('shop_good_variations')
                        ->whereColumn('good_id', 'shop_goods.id')
                        ->limit(1);
                })->whereNotExists(function ($subQuery) use ($excludeAttributeNames) {
                    $subQuery->selectRaw('1')
                        ->from('shop_good_variations as v')
                        ->join('shop_variation_attributes_values as vav', 'v.id', '=', 'vav.variation_id')
                        ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
                        ->join('shop_variation_attributes as a', 'a.id', '=', 'av.attribute_id')
                        ->whereColumn('v.good_id', 'shop_goods.id')
                        ->whereIn('a.name', $excludeAttributeNames)
                        ->limit(1);
                });
            }
        }
    }

    /**
     * Генерация CSV данных для экспорта
     */
    private function generateCsvData($goods): string
    {
        $headers = [
            'ID',
            'Название',
            'Артикул',
            'Slug',
            'Описание',
            'Краткое описание',
            'Цена',
            'Акционная цена',
            'Количество на складе',
            'Ширина',
            'Высота',
            'Глубина',
            'Вес',
            'Рейтинг',
            'Количество отзывов',
            'Meta заголовок',
            'Meta описание',
            'Активен',
            'Рекомендуемый',
            'Новый',
            'Со скидкой',
            'Категории',
            'Бренды',
            'Теги',
            'Свойства',
            'Тип',
            'Модель',
            'Год',
            'Дата создания',
            'Дата обновления',
        ];

        $csvData = implode(',', array_map(function ($header) {
            return '"'.str_replace('"', '""', $header).'"';
        }, $headers))."\n";

        foreach ($goods as $good) {
            $row = [
                $good->id,
                $good->name,
                $good->sku,
                $good->slug,
                $good->description,
                $good->short_description,
                $good->price,
                $good->sale_price,
                $good->stock_quantity,
                $good->width,
                $good->height,
                $good->depth,
                $good->weight,
                $good->rating,
                $good->reviews_count,
                $good->meta_title,
                $good->meta_description,
                $good->is_active ? 'Да' : 'Нет',
                $good->is_featured ? 'Да' : 'Нет',
                $good->is_new ? 'Да' : 'Нет',
                $good->is_sale ? 'Да' : 'Нет',
                $good->categories->pluck('name')->join(';'),
                $good->brands->pluck('name')->join(';'),
                $good->tags->pluck('name')->join(';'),
                $good->properties->map(function ($property) {
                    $value = '';
                    if ($property->pivot->shop_property_value_id) {
                        // Получаем значение через связь PropertyValue
                        $propertyValue = \App\Models\Shop\PropertyValue::find($property->pivot->shop_property_value_id);
                        $value = $propertyValue ? $propertyValue->value : '';
                    }

                    return $property->name.':'.$value;
                })->join(';'),
                '', // Тип (заполняется на фронтенде)
                '', // Модель (заполняется на фронтенде)
                '', // Год (заполняется на фронтенде)
                $good->created_at,
                $good->updated_at,
            ];

            $csvData .= implode(',', array_map(function ($value) {
                return '"'.str_replace('"', '""', $value ?? '').'"';
            }, $row))."\n";
        }

        return $csvData;
    }

    /**
     * Генерация шаблона CSV
     */
    private function generateTemplateCsv(): string
    {
        $headers = [
            'Название',
            'Артикул',
            'Slug',
            'Описание',
            'Краткое описание',
            'Цена',
            'Акционная цена',
            'Количество на складе',
            'Ширина',
            'Высота',
            'Глубина',
            'Вес',
            'Meta заголовок',
            'Meta описание',
            'Активен',
            'Рекомендуемый',
            'Новый',
            'Со скидкой',
            'Категории',
            'Бренды',
            'Теги',
            'Свойства',
            'Тип',
            'Модель',
            'Год',
        ];

        $csvData = implode(',', array_map(function ($header) {
            return '"'.str_replace('"', '""', $header).'"';
        }, $headers))."\n";

        // Добавляем пример строки
        $exampleRow = [
            'Пример товара',
            'EXAMPLE-001',
            'example-tovar',
            'Описание товара',
            'Краткое описание',
            '1000.00',
            '800.00',
            '10',
            '10.5',
            '20.0',
            '5.0',
            '1.2',
            'Meta заголовок',
            'Meta описание',
            'Да',
            'Нет',
            'Да',
            'Да',
            'Категория 1;Категория 2',
            'Бренд 1',
            'Тег 1;Тег 2',
            'Цвет:Красный;Размер:L',
            'Тип товара',
            'Модель товара',
            '2024',
        ];

        $csvData .= implode(',', array_map(function ($value) {
            return '"'.str_replace('"', '""', $value).'"';
        }, $exampleRow))."\n";

        return $csvData;
    }

    /**
     * Парсинг CSV файла
     */
    private function parseCsvFile($file): array
    {
        $csvData = [];
        $handle = fopen($file->getPathname(), 'r');

        if ($handle !== false) {
            $headers = fgetcsv($handle);

            while (($row = fgetcsv($handle)) !== false) {
                $csvData[] = array_combine($headers, $row);
            }

            fclose($handle);
        }

        return $csvData;
    }

    /**
     * Импорт товаров из CSV данных
     */
    private function importGoodsFromCsv(array $csvData, bool $updateExisting, bool $skipErrors): array
    {
        $result = [
            'imported' => 0,
            'updated' => 0,
            'errors' => 0,
            'error_details' => [],
        ];

        DB::beginTransaction();

        try {
            foreach ($csvData as $index => $row) {
                try {
                    $this->importGoodFromRow($row, $updateExisting);

                    if ($updateExisting && ShopGood::where('sku', $row['Артикул'])->exists()) {
                        $result['updated']++;
                    } else {
                        $result['imported']++;
                    }

                } catch (\Exception $e) {
                    $result['errors']++;
                    $result['error_details'][] = [
                        'row' => $index + 2, // +2 потому что первая строка - заголовки, а индексация с 0
                        'error' => $e->getMessage(),
                    ];

                    if (! $skipErrors) {
                        throw $e;
                    }
                }
            }

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        return $result;
    }

    /**
     * Импорт одного товара из строки CSV
     */
    private function importGoodFromRow(array $row, bool $updateExisting): void
    {
        $sku = $row['Артикул'] ?? '';

        if (empty($sku)) {
            throw new \Exception('Артикул не может быть пустым');
        }

        $goodData = [
            'name' => $row['Название'] ?? '',
            'sku' => $sku,
            'slug' => $row['Slug'] ?? Str::slug($row['Название'] ?? ''),
            'description' => $row['Описание'] ?? '',
            'short_description' => $row['Краткое описание'] ?? '',
            'price' => (float) ($row['Цена'] ?? 0),
            'sale_price' => ! empty($row['Акционная цена']) ? (float) $row['Акционная цена'] : null,
            'stock_quantity' => (int) ($row['Количество на складе'] ?? 0),
            'width' => ! empty($row['Ширина']) ? (float) $row['Ширина'] : null,
            'height' => ! empty($row['Высота']) ? (float) $row['Высота'] : null,
            'depth' => ! empty($row['Глубина']) ? (float) $row['Глубина'] : null,
            'weight' => ! empty($row['Вес']) ? (float) $row['Вес'] : null,
            'meta_title' => $row['Meta заголовок'] ?? '',
            'meta_description' => $row['Meta описание'] ?? '',
            'is_active' => $this->parseBoolean($row['Активен'] ?? 'Да'),
            'is_featured' => $this->parseBoolean($row['Рекомендуемый'] ?? 'Нет'),
            'is_new' => $this->parseBoolean($row['Новый'] ?? 'Нет'),
            'is_sale' => $this->parseBoolean($row['Со скидкой'] ?? 'Нет'),
        ];

        $existingGood = ShopGood::where('sku', $sku)->first();

        if ($existingGood && $updateExisting) {
            $existingGood->update($goodData);
            $good = $existingGood;
        } elseif (! $existingGood) {
            $good = ShopGood::create($goodData);
        } else {
            throw new \Exception("Товар с артикулом {$sku} уже существует");
        }

        // Обработка категорий
        if (! empty($row['Категории'])) {
            $categoryNames = explode(';', $row['Категории']);
            $categoryIds = [];

            foreach ($categoryNames as $categoryName) {
                $categoryName = trim($categoryName);
                if (! empty($categoryName)) {
                    $category = Category::where('name', $categoryName)->first();
                    if ($category) {
                        $categoryIds[] = $category->id;
                    }
                }
            }

            $good->categories()->sync($categoryIds);
        }

        // Обработка брендов
        if (! empty($row['Бренды'])) {
            $brandNames = explode(';', $row['Бренды']);
            $brandIds = [];

            foreach ($brandNames as $brandName) {
                $brandName = trim($brandName);
                if (! empty($brandName)) {
                    $brand = ShopBrand::where('name', $brandName)->first();
                    if ($brand) {
                        $brandIds[] = $brand->id;
                    }
                }
            }

            $good->brands()->sync($brandIds);
        }

        // Обработка тегов
        if (! empty($row['Теги'])) {
            $tagNames = explode(';', $row['Теги']);
            $tagIds = [];

            foreach ($tagNames as $tagName) {
                $tagName = trim($tagName);
                if (! empty($tagName)) {
                    $tag = ShopTag::where('name', $tagName)->first();
                    if (! $tag) {
                        $tag = ShopTag::create([
                            'name' => $tagName,
                            'slug' => Str::slug($tagName),
                        ]);
                    }
                    $tagIds[] = $tag->id;
                }
            }

            $good->tags()->sync($tagIds);
        }

        // Обработка свойств
        if (! empty($row['Свойства'])) {
            $properties = explode(';', $row['Свойства']);

            foreach ($properties as $property) {
                $property = trim($property);
                if (! empty($property) && strpos($property, ':') !== false) {
                    [$propertyName, $propertyValue] = explode(':', $property, 2);
                    $propertyName = trim($propertyName);
                    $propertyValue = trim($propertyValue);

                    if (! empty($propertyName) && ! empty($propertyValue)) {
                        $propertyModel = ShopProperty::where('name', $propertyName)->first();
                        if (! $propertyModel) {
                            $propertyModel = ShopProperty::create([
                                'name' => $propertyName,
                                'slug' => Str::slug($propertyName),
                            ]);
                        }

                        $good->properties()->syncWithoutDetaching([
                            $propertyModel->id => ['value' => $propertyValue],
                        ]);
                    }
                }
            }
        }
    }

    /**
     * Парсинг булевых значений
     */
    private function parseBoolean(string $value): bool
    {
        $value = strtolower(trim($value));

        return in_array($value, ['да', 'yes', 'true', '1', 'on']);
    }
}
