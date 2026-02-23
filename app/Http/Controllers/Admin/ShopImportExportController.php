<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopGood;
use App\Models\ShopBrand;
use App\Models\ShopTag;
use App\Models\ShopProperty;
use App\Models\ShopCategory;
use App\Models\AdminPage;
use App\Models\ConstructorPage;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
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
            $goodsController = new \App\Http\Controllers\Admin\ShopGoodsController();
            $exportRequest = new \Illuminate\Http\Request();
            $exportRequest->merge($request->all());
            $exportRequest->merge(['for_export' => '1']);

            $goodsResponse = $goodsController->index($exportRequest);

            if (!$goodsResponse->getData()->success) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка получения товаров для экспорта: ' . ($goodsResponse->getData()->message ?? 'Неизвестная ошибка')
                ], 500);
            }

            $goods = collect($goodsResponse->getData()->data);

            // Создаем CSV файл
            $filename = 'goods_export_' . date('Y-m-d_H-i-s') . '.csv';
            $filepath = 'exports/' . $filename;

            // Создаем директорию если не существует
            if (!Storage::disk('public')->exists('exports')) {
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
                    'count' => $goods->count()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка экспорта: ' . $e->getMessage()
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
            'message' => 'Экспорт в Excel будет доступен в следующей версии'
        ], 501);
    }

    /**
     * Экспорт товаров в YML (Yandex Market Language)
     */
    public function exportYml(Request $request): JsonResponse
    {
        try {
            // Увеличиваем лимиты для генерации большого файла
            ini_set('memory_limit', '512M');
            set_time_limit(300);

            $filename = 'goods_feed.xml';
            $filepath = 'exports/' . $filename;

            // Создаем директорию если не существует
            if (!Storage::disk('public')->exists('exports')) {
                Storage::disk('public')->makeDirectory('exports');
            }

            // Удаляем старый файл перед генерацией, чтобы избежать артефактов
            if (Storage::disk('public')->exists($filepath)) {
                Storage::disk('public')->delete($filepath);
            }

            // Используем прямой доступ к файлу для потоковой записи
            $fullPath = Storage::disk('public')->path($filepath);
            $handle = fopen($fullPath, 'w');
            
            if (!$handle) {
                throw new \Exception("Не удалось открыть файл для записи: $fullPath");
            }

            // Пишем заголовок
            fwrite($handle, '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL);
            fwrite($handle, '<yml_catalog date="' . date('Y-m-d H:i') . '">' . PHP_EOL);
            fwrite($handle, '    <shop>' . PHP_EOL);

            // Название и базовый URL магазина
            $shopSettings = DB::table('settings')
                ->where('group', 'shop')
                ->whereIn('key', ['site_name'])
                ->pluck('value', 'key')
                ->toArray();

            $shopName = $shopSettings['site_name'] ?? 'Skate & Snow';
            $mainSite = $this->getMainSiteUrl();

            fwrite($handle, '        <name>' . htmlspecialchars($shopName) . '</name>' . PHP_EOL);
            fwrite($handle, '        <company>' . htmlspecialchars($shopName) . '</company>' . PHP_EOL);
            fwrite($handle, '        <url>' . htmlspecialchars($mainSite) . '</url>' . PHP_EOL);
            fwrite($handle, '        <currencies>' . PHP_EOL);
            fwrite($handle, '            <currency id="RUR" rate="1"/>' . PHP_EOL);
            fwrite($handle, '        </currencies>' . PHP_EOL);

            // Категории
            fwrite($handle, '        <categories>' . PHP_EOL);
            ShopCategory::chunk(100, function($categories) use ($handle) {
                foreach ($categories as $category) {
                    $parentId = $category->parent_id ? ' parentId="' . $category->parent_id . '"' : '';
                    fwrite($handle, '            <category id="' . $category->id . '"' . $parentId . '>' . htmlspecialchars($category->name) . '</category>' . PHP_EOL);
                }
            });
            fwrite($handle, '        </categories>' . PHP_EOL);

            // Товары
            fwrite($handle, '        <offers>' . PHP_EOL);
            
            // Загружаем товары порциями для экономии памяти
            // Используем те же связи, что и в ShopGoodsController
            $query = ShopGood::with([
                'categories:id,name',
                'brands:id,name',
                'images:id,good_id,file_path,alt_text,is_main,sort_order',
                'variations' => function($q) {
                    $q->select('id', 'good_id')
                      ->with('images:id,variation_id,file_path,alt_text,is_main,sort_order');
                }
            ]);

            // Если нужно, можно добавить фильтр активности
            // $query->where('is_active', true);

            $count = 0;
            $query->chunk(200, function($goods) use ($handle, &$count) {
                foreach ($goods as $good) {
                    $this->writeOfferToHandle($handle, $good);
                    $count++;
                }
                // Освобождаем память
                unset($goods);
            });
            
            fwrite($handle, '        </offers>' . PHP_EOL);
            fwrite($handle, '    </shop>' . PHP_EOL);
            fwrite($handle, '</yml_catalog>');
            
            fclose($handle);

            // URL фида на фронтенде (Nuxt отдаёт goods_feed.xml через серверный маршрут)
            $frontendUrl = $this->getMainSiteUrl() . '/' . $filename;
            
            // Получаем метаданные файла
            $url = Storage::disk('public')->url($filepath);
            $size = Storage::disk('public')->size($filepath);
            $lastModified = Storage::disk('public')->lastModified($filepath);

            return response()->json([
                'success' => true,
                'message' => 'YML фид успешно сгенерирован',
                'data' => [
                    'filename' => $filename,
                    'download_url' => $url,
                    'frontend_url' => $frontendUrl,
                    'generated_at' => date('Y-m-d H:i:s', $lastModified),
                    'size' => round($size / 1024, 2) . ' KB',
                    'count' => $count
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка генерации YML: ' . $e->getMessage()
            ], 500);
        }
    }

    private function getMainSiteUrl(): string
    {
        static $cached = null;

        if ($cached !== null) {
            return $cached;
        }

        $mainSite = DB::table('settings')
            ->where('group', 'shop')
            ->where('key', 'main_site')
            ->value('value');

        $mainSite = is_string($mainSite) ? trim($mainSite) : '';

        // Если в настройках магазина не задан main_site, используем FRONTEND_URL из окружения
        if ($mainSite === '') {
            $frontendEnv = env('FRONTEND_URL');
            if (!is_string($frontendEnv) || trim($frontendEnv) === '') {
                throw new \RuntimeException('Не задан ни main_site в настройках магазина, ни FRONTEND_URL в окружении');
            }
            $mainSite = trim($frontendEnv);
        }

        $cached = rtrim($mainSite, '/');

        return $cached;
    }

    private function getYmlImageUrl($filePath): ?string
    {
        if (!$filePath) {
            return null;
        }

        if (str_starts_with($filePath, 'http')) {
            return $filePath;
        }

        $frontendBase = $this->getMainSiteUrl();
        $cleanPath = ltrim($filePath, '/');

        if (str_starts_with($cleanPath, 'images/')) {
            return $frontendBase . '/' . $cleanPath;
        }

        if (str_starts_with($cleanPath, 'storage/')) {
            $apiBase = rtrim(config('app.url'), '/');
            return $apiBase . '/' . $cleanPath;
        }

        return $frontendBase . '/images/' . $cleanPath;
    }

    private function writeOfferToHandle($handle, $good): void
    {
        // Пропускаем товары без цены или имени
        if (empty($good->price) || empty($good->name)) return;
        
        // Определяем доступность
        $available = ($good->stock_quantity > 0) ? 'true' : 'false';
        
        fwrite($handle, '            <offer id="' . $good->id . '" available="' . $available . '">' . PHP_EOL);
        
        $url = $this->getMainSiteUrl() . '/product/' . ($good->slug ?? $good->id);
        fwrite($handle, '                <url>' . htmlspecialchars($url) . '</url>' . PHP_EOL);
        
        // Цена
        $price = $good->sale_price ?? $good->price;
        $oldPrice = $good->sale_price ? $good->price : null;
        
        fwrite($handle, '                <price>' . $price . '</price>' . PHP_EOL);
        if ($oldPrice) {
            fwrite($handle, '                <oldprice>' . $oldPrice . '</oldprice>' . PHP_EOL);
        }
        
        fwrite($handle, '                <currencyId>RUR</currencyId>' . PHP_EOL);
        
        // Категория (берем первую из списка)
        if ($good->categories->isNotEmpty()) {
            $categoryId = $good->categories->first()->id;
            fwrite($handle, '                <categoryId>' . $categoryId . '</categoryId>' . PHP_EOL);
        }
        
        // Изображения
        $images = collect();
        
        // 1. Пробуем взять изображения самого товара
        if ($good->images->isNotEmpty()) {
            $images = $good->images;
        } 
        // 2. Если у товара нет изображений, ищем в вариациях
        elseif ($good->variations->isNotEmpty()) {
            foreach ($good->variations as $variation) {
                if ($variation->images->isNotEmpty()) {
                    $images = $variation->images;
                    break; // Берем изображения из первой попавшейся вариации с картинками
                }
            }
        }

        if ($images->isNotEmpty()) {
            $images = $images->sortBy('sort_order')->sortByDesc('is_main');

            foreach ($images as $image) {
                $path = $image->file_path;
                $imgUrl = $this->getYmlImageUrl($path);
                if ($imgUrl) {
                    fwrite($handle, '                <picture>' . htmlspecialchars($imgUrl) . '</picture>' . PHP_EOL);
                }
            }
        }
        
        fwrite($handle, '                <name>' . htmlspecialchars($good->name) . '</name>' . PHP_EOL);
        
        // Бренд
        if ($good->brands->isNotEmpty()) {
            $brandName = $good->brands->first()->name;
            fwrite($handle, '                <vendor>' . htmlspecialchars($brandName) . '</vendor>' . PHP_EOL);
        }
        
        if (!empty($good->description)) {
            $description = strip_tags($good->description);
            // Удаляем недопустимые символы
            $description = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $description);
            fwrite($handle, '                <description><![CDATA[' . $description . ']]></description>' . PHP_EOL);
        }

        fwrite($handle, '            </offer>' . PHP_EOL);
    }

    /**
     * Получение статуса YML фида
     */
    public function getYmlStatus(): JsonResponse
     {
         try {
             $filename = 'goods_feed.xml';
             $filepath = 'exports/' . $filename;
             
             if (Storage::disk('public')->exists($filepath)) {
                 $url = Storage::disk('public')->url($filepath);
                 $size = Storage::disk('public')->size($filepath);
                 $lastModified = Storage::disk('public')->lastModified($filepath);
                 
                 // Проверяем наличие на фронтенде
                 $frontendPathRelative = config('frontend.path');
                 $frontendUrl = null;
                 
                 if ($frontendPathRelative) {
                     $frontendBasePath = base_path($frontendPathRelative);
                     $frontendPublicPath = $frontendBasePath . '/public';
                     
                     if (is_dir($frontendPublicPath) && file_exists($frontendPublicPath . '/' . $filename)) {
                         $frontendUrl = config('app.frontend_url') . '/' . $filename;
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
                         'size' => round($size / 1024, 2) . ' KB'
                     ]
                 ]);
             } else {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'exists' => false
                    ]
                ]);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения статуса YML: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Генерация Sitemap
     */
    public function generateSitemap(Request $request): JsonResponse
    {
        try {
            $frontendUrl = config('app.frontend_url', 'https://skateandsnow.ru');
            $xml = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
            $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . PHP_EOL;

            // 1. Главная страница
            $xml .= '    <url>' . PHP_EOL;
            $xml .= '        <loc>' . $frontendUrl . '</loc>' . PHP_EOL;
            $xml .= '        <changefreq>daily</changefreq>' . PHP_EOL;
            $xml .= '        <priority>1.0</priority>' . PHP_EOL;
            $xml .= '    </url>' . PHP_EOL;

            $pagesCount = 1;

            // 2. Страницы конструктора
            $constructorPages = \App\Models\ConstructorPage::where('is_published', true)->get();
            foreach ($constructorPages as $page) {
                $slug = trim($page->slug, '/');
                if (empty($slug)) continue;

                $xml .= '    <url>' . PHP_EOL;
                $xml .= '        <loc>' . $frontendUrl . '/' . $slug . '</loc>' . PHP_EOL;
                $xml .= '        <lastmod>' . $page->updated_at->toIso8601String() . '</lastmod>' . PHP_EOL;
                $xml .= '        <changefreq>weekly</changefreq>' . PHP_EOL;
                $xml .= '        <priority>0.8</priority>' . PHP_EOL;
                $xml .= '    </url>' . PHP_EOL;
                $pagesCount++;
            }

            // 3. Категории каталога
            $categories = \App\Models\ShopCategory::where('is_active', true)->get();
            
            // Создаем карту категорий для построения путей
            $categoryMap = [];
            foreach ($categories as $cat) {
                $categoryMap[$cat->id] = $cat;
            }

            // Функция для построения полного пути категории
            $buildCategoryPath = function($categoryId) use ($categoryMap) {
                $path = [];
                $currentId = $categoryId;
                $seen = []; // Защита от рекурсии
                
                while ($currentId && isset($categoryMap[$currentId]) && !isset($seen[$currentId])) {
                    $cat = $categoryMap[$currentId];
                    array_unshift($path, $cat->slug);
                    $seen[$currentId] = true;
                    $currentId = $cat->parent_id;
                }
                return implode('/', $path);
            };

            foreach ($categories as $category) {
                $path = $buildCategoryPath($category->id);
                if (empty($path)) continue;

                $xml .= '    <url>' . PHP_EOL;
                $xml .= '        <loc>' . $frontendUrl . '/catalog/' . $path . '</loc>' . PHP_EOL;
                $xml .= '        <lastmod>' . $category->updated_at->toIso8601String() . '</lastmod>' . PHP_EOL;
                $xml .= '        <changefreq>weekly</changefreq>' . PHP_EOL;
                $xml .= '        <priority>0.9</priority>' . PHP_EOL;
                $xml .= '    </url>' . PHP_EOL;
                $pagesCount++;
            }

            // 4. Товары
            // Загружаем товары с категориями для формирования красивых ссылок
            // Используем chunk для экономии памяти, если товаров много
            \App\Models\ShopGood::where('is_active', true)
                ->with(['categories' => function($query) {
                    $query->select('shop_categories.id', 'shop_categories.slug', 'shop_categories.parent_id');
                }])
                ->chunk(500, function($goods) use (&$xml, $frontendUrl, &$pagesCount, $buildCategoryPath) {
                    foreach ($goods as $good) {
                        $catPath = '';
                        
                        // Пытаемся найти главную категорию
                        if ($good->categories->isNotEmpty()) {
                            $category = $good->categories->first();
                            $catPath = $buildCategoryPath($category->id);
                        }
                        
                        $urlPath = $catPath ? '/catalog/' . $catPath . '/' . $good->slug : '/catalog/' . $good->slug;
                        
                        $xml .= '    <url>' . PHP_EOL;
                        $xml .= '        <loc>' . $frontendUrl . $urlPath . '</loc>' . PHP_EOL;
                        $xml .= '        <lastmod>' . $good->updated_at->toIso8601String() . '</lastmod>' . PHP_EOL;
                        $xml .= '        <changefreq>weekly</changefreq>' . PHP_EOL;
                        $xml .= '        <priority>0.7</priority>' . PHP_EOL;
                        $xml .= '    </url>' . PHP_EOL;
                        $pagesCount++;
                    }
                });

            // 5. Статические страницы Nuxt (из файловой системы)
            $frontendPathRelative = config('frontend.path');
            if ($frontendPathRelative) {
                $frontendBasePath = base_path($frontendPathRelative);
                $pagesDir = $frontendBasePath . '/pages';
                
                if (is_dir($pagesDir)) {
                    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($pagesDir));
                    
                    foreach ($iterator as $file) {
                        if ($file->isFile() && $file->getExtension() === 'vue') {
                            $relativePath = str_replace($pagesDir, '', $file->getPathname());
                            $relativePath = str_replace('\\', '/', $relativePath);
                            
                            // Исключаем системные и админские папки
                            if (preg_match('#^/(admin|auth|cms|profile|user-dashboard)#', $relativePath)) continue;
                            
                            $filename = $file->getBasename('.vue');
                            
                            // Исключаем динамические маршруты и скрытые файлы
                            if (str_starts_with($filename, '[') || str_starts_with($filename, '_')) continue;
                            if (str_starts_with($filename, 'error')) continue;
                            
                            // Формируем URL
                            $urlPath = dirname($relativePath);
                            $urlPath = str_replace('\\', '/', $urlPath); // Fix for Windows
                            
                            if ($urlPath === '.') $urlPath = '';
                            if ($urlPath === '/') $urlPath = '';
                            
                            // Обработка index.vue
                            if ($filename === 'index') {
                                if ($urlPath === '') continue; // Главная уже добавлена
                            } else {
                                $urlPath .= '/' . $filename;
                            }
                            
                            // Нормализация пути (убираем двойные слеши)
                            $urlPath = str_replace('//', '/', $urlPath);
                            
                            $xml .= '    <url>' . PHP_EOL;
                            $xml .= '        <loc>' . $frontendUrl . $urlPath . '</loc>' . PHP_EOL;
                            $xml .= '        <lastmod>' . date('c', $file->getMTime()) . '</lastmod>' . PHP_EOL;
                            $xml .= '        <changefreq>monthly</changefreq>' . PHP_EOL;
                            $xml .= '        <priority>0.5</priority>' . PHP_EOL;
                            $xml .= '    </url>' . PHP_EOL;
                            $pagesCount++;
                        }
                    }
                }
            }

            $xml .= '</urlset>';

            $filename = 'sitemap.xml';
            $filepath = 'exports/' . $filename;

            if (!Storage::disk('public')->exists('exports')) {
                Storage::disk('public')->makeDirectory('exports');
            }

            Storage::disk('public')->put($filepath, $xml);

            // Копируем на фронтенд
            $frontendPathRelative = config('frontend.path');
            $frontendPublicUrl = null;

            if ($frontendPathRelative) {
                $frontendBasePath = base_path($frontendPathRelative);
                $frontendPublicPath = $frontendBasePath . '/public';

                if (!file_exists($frontendPublicPath)) {
                    @mkdir($frontendPublicPath, 0755, true);
                }

                if (is_dir($frontendPublicPath)) {
                    $frontendFilepath = $frontendPublicPath . '/' . $filename;
                    file_put_contents($frontendFilepath, $xml);
                    $frontendPublicUrl = config('app.frontend_url') . '/' . $filename;
                }
            }

             $url = Storage::disk('public')->url($filepath);
             $size = Storage::disk('public')->size($filepath);
             $lastModified = Storage::disk('public')->lastModified($filepath);

             return response()->json([
                 'success' => true,
                 'message' => 'Sitemap успешно сгенерирован',
                 'data' => [
                     'filename' => $filename,
                     'download_url' => $url,
                     'frontend_url' => $frontendPublicUrl,
                     'generated_at' => date('Y-m-d H:i:s', $lastModified),
                     'size' => round($size / 1024, 2) . ' KB',
                     'pages_count' => $pagesCount
                 ]
             ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка генерации Sitemap: ' . $e->getMessage()
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
            $filepath = 'exports/' . $filename;

            if (Storage::disk('public')->exists($filepath)) {
                $url = Storage::disk('public')->url($filepath);
                $size = Storage::disk('public')->size($filepath);
                $lastModified = Storage::disk('public')->lastModified($filepath);

                $frontendPathRelative = config('frontend.path');
                $frontendUrl = null;

                if ($frontendPathRelative) {
                    $frontendBasePath = base_path($frontendPathRelative);
                    $frontendPublicPath = $frontendBasePath . '/public';

                    if (is_dir($frontendPublicPath) && file_exists($frontendPublicPath . '/' . $filename)) {
                        $frontendUrl = config('app.frontend_url') . '/' . $filename;
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
                        'size' => round($size / 1024, 2) . ' KB'
                    ]
                ]);
            } else {
                 return response()->json([
                    'success' => true,
                    'data' => [
                        'exists' => false
                    ]
                ]);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения статуса Sitemap: ' . $e->getMessage()
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
            
            if ($mode === 'closed') {
                $content = "User-agent: *\n";
                $content .= "Disallow: /\n";
            } else {
                // Open mode
                $content = "User-agent: *\n";
                $content .= "Disallow: /admin\n";
                $content .= "Disallow: /cms\n";
                $content .= "Allow: /\n";
                $content .= "Sitemap: " . $frontendUrl . "/sitemap.xml\n";
            }
            
            $filename = 'robots.txt';
            $filepath = 'exports/' . $filename;
            
            // Сохраняем локально (для истории/бекапа)
            if (!Storage::disk('public')->exists('exports')) {
                Storage::disk('public')->makeDirectory('exports');
            }
            Storage::disk('public')->put($filepath, $content);
            
            // Копируем на фронтенд
            $frontendPathRelative = config('frontend.path');
            $frontendPublicUrl = null;
            $copiedToFrontend = false;

            if ($frontendPathRelative) {
                $frontendBasePath = base_path($frontendPathRelative);
                $frontendPublicPath = $frontendBasePath . '/public';

                if (!is_dir($frontendPublicPath)) {
                     // Пытаемся создать, если нет (для локальной разработки)
                     @mkdir($frontendPublicPath, 0755, true);
                }

                if (is_dir($frontendPublicPath)) {
                    $frontendFilepath = $frontendPublicPath . '/' . $filename;
                    file_put_contents($frontendFilepath, $content);
                    $frontendPublicUrl = config('app.frontend_url') . '/' . $filename;
                    $copiedToFrontend = true;
                }
            }
            
            $url = Storage::disk('public')->url($filepath);
            $lastModified = time(); // Мы только что создали

            return response()->json([
                'success' => true,
                'message' => 'robots.txt успешно сгенерирован (' . ($mode === 'closed' ? 'закрыт' : 'открыт') . ')',
                'data' => [
                    'filename' => $filename,
                    'download_url' => $url,
                    'frontend_url' => $frontendPublicUrl,
                    'generated_at' => date('Y-m-d H:i:s', $lastModified),
                    'mode' => $mode,
                    'content' => $content
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка генерации robots.txt: ' . $e->getMessage()
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
            // Проверяем сначала на фронтенде, так как это "боевой" файл
            $frontendPathRelative = config('frontend.path');
            $frontendUrl = null;
            $mode = 'unknown';
            $exists = false;
            $lastModified = null;
            $content = '';

            if ($frontendPathRelative) {
                $frontendBasePath = base_path($frontendPathRelative);
                $frontendPublicPath = $frontendBasePath . '/public';
                $frontendFilepath = $frontendPublicPath . '/' . $filename;

                if (is_dir($frontendPublicPath) && file_exists($frontendFilepath)) {
                    $exists = true;
                    $content = file_get_contents($frontendFilepath);
                    $lastModified = filemtime($frontendFilepath);
                    $frontendUrl = config('app.frontend_url') . '/' . $filename;
                }
            }
            
            // Если на фронте нет, проверяем локально в экспортах
            if (!$exists) {
                $filepath = 'exports/' . $filename;
                if (Storage::disk('public')->exists($filepath)) {
                    $exists = true;
                    $content = Storage::disk('public')->get($filepath);
                    $lastModified = Storage::disk('public')->lastModified($filepath);
                }
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
                        'frontend_url' => $frontendUrl,
                        'generated_at' => date('Y-m-d H:i:s', $lastModified),
                        'mode' => $mode
                    ]
                ]);
            } else {
                 return response()->json([
                    'success' => true,
                    'data' => [
                        'exists' => false,
                        'mode' => 'unknown' 
                    ]
                ]);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения статуса robots.txt: ' . $e->getMessage()
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
            'skip_errors' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
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
                'data' => $result
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка импорта: ' . $e->getMessage()
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
            $filepath = 'templates/' . $filename;

            // Создаем директорию если не существует
            if (!Storage::disk('public')->exists('templates')) {
                Storage::disk('public')->makeDirectory('templates');
            }

            // Создаем шаблон CSV
            $templateData = $this->generateTemplateCsv();
            Storage::disk('public')->put($filepath, $templateData);

            return response()->json([
                'success' => true,
                'data' => [
                    'filename' => $filename,
                    'download_url' => Storage::disk('public')->url($filepath)
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания шаблона: ' . $e->getMessage()
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
            if (is_array($categoryIds) && !empty($categoryIds)) {
                $query->whereHas('categories', function($q) use ($categoryIds) {
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
            if (is_array($brandIds) && !empty($brandIds)) {
                $query->whereHas('brands', function($q) use ($brandIds) {
                    $q->whereIn('shop_brands.id', $brandIds);
                });
            }
        }

        // Фильтр по тегам
        if ($request->has('tags')) {
            $tagIds = $request->input('tags');
            if (is_array($tagIds) && !empty($tagIds)) {
                $query->whereHas('tags', function($q) use ($tagIds) {
                    $q->whereIn('shop_tags.id', $tagIds);
                });
            }
        }

        // Исключение тегов
        if ($request->has('exclude_tags')) {
            $excludeTagIds = $request->input('exclude_tags');
            if (is_array($excludeTagIds) && !empty($excludeTagIds)) {
                $query->whereDoesntHave('tags', function($q) use ($excludeTagIds) {
                    $q->whereIn('shop_tags.id', $excludeTagIds);
                });
            }
        }

        // Фильтр по лейблам
        if ($request->has('labels')) {
            $labelIds = $request->input('labels');
            if (is_array($labelIds) && !empty($labelIds)) {
                $query->whereIn('label_id', $labelIds);
            }
        }

        // Фильтр по свойствам
        if ($request->has('properties')) {
            $properties = $request->input('properties');
            if (is_array($properties)) {
                foreach ($properties as $propertyId => $valueIds) {
                    if (is_array($valueIds) && !empty($valueIds)) {
                        $query->whereHas('properties', function($q) use ($propertyId, $valueIds) {
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
            if (is_array($supplierIds) && !empty($supplierIds)) {
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
                $query->whereHas('properties', function($q) use ($count) {
                    $q->havingRaw('COUNT(*) = ?', [$count]);
                }, '=', $count);
            }
        }

        // Фильтр по артикулу
        if ($request->filled('sku_filter_type')) {
            $skuFilterType = $request->get('sku_filter_type');
            if ($skuFilterType === 'empty') {
                $query->where(function($q) {
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
            if (is_array($attributeNames) && !empty($attributeNames)) {
                // Фильтруем товары, у которых есть вариации с указанными атрибутами
                $query->whereExists(function($subQuery) use ($attributeNames) {
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
            if (is_array($excludeAttributeNames) && !empty($excludeAttributeNames)) {
                // Когда применяется фильтр "БЕЗ атрибутов", показываем ТОЛЬКО товары с вариациями,
                // которые НЕ имеют указанные атрибуты
                // Используем подзапрос для точного контроля
                $query->whereExists(function($subQuery) {
                    $subQuery->selectRaw('1')
                        ->from('shop_good_variations')
                        ->whereColumn('good_id', 'shop_goods.id')
                        ->limit(1);
                })->whereNotExists(function($subQuery) use ($excludeAttributeNames) {
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
            'Дата обновления'
        ];

        $csvData = implode(',', array_map(function($header) {
            return '"' . str_replace('"', '""', $header) . '"';
        }, $headers)) . "\n";

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
                $good->properties->map(function($property) {
                    $value = '';
                    if ($property->pivot->shop_property_value_id) {
                        // Получаем значение через связь PropertyValue
                        $propertyValue = \App\Models\Shop\PropertyValue::find($property->pivot->shop_property_value_id);
                        $value = $propertyValue ? $propertyValue->value : '';
                    }
                    return $property->name . ':' . $value;
                })->join(';'),
                '', // Тип (заполняется на фронтенде)
                '', // Модель (заполняется на фронтенде)
                '', // Год (заполняется на фронтенде)
                $good->created_at,
                $good->updated_at
            ];

            $csvData .= implode(',', array_map(function($value) {
                return '"' . str_replace('"', '""', $value ?? '') . '"';
            }, $row)) . "\n";
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
            'Год'
        ];

        $csvData = implode(',', array_map(function($header) {
            return '"' . str_replace('"', '""', $header) . '"';
        }, $headers)) . "\n";

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
            '2024'
        ];

        $csvData .= implode(',', array_map(function($value) {
            return '"' . str_replace('"', '""', $value) . '"';
        }, $exampleRow)) . "\n";

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
            'error_details' => []
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
                        'error' => $e->getMessage()
                    ];
                    
                    if (!$skipErrors) {
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
            'sale_price' => !empty($row['Акционная цена']) ? (float) $row['Акционная цена'] : null,
            'stock_quantity' => (int) ($row['Количество на складе'] ?? 0),
            'width' => !empty($row['Ширина']) ? (float) $row['Ширина'] : null,
            'height' => !empty($row['Высота']) ? (float) $row['Высота'] : null,
            'depth' => !empty($row['Глубина']) ? (float) $row['Глубина'] : null,
            'weight' => !empty($row['Вес']) ? (float) $row['Вес'] : null,
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
        } elseif (!$existingGood) {
            $good = ShopGood::create($goodData);
        } else {
            throw new \Exception("Товар с артикулом {$sku} уже существует");
        }

        // Обработка категорий
        if (!empty($row['Категории'])) {
            $categoryNames = explode(';', $row['Категории']);
            $categoryIds = [];
            
            foreach ($categoryNames as $categoryName) {
                $categoryName = trim($categoryName);
                if (!empty($categoryName)) {
                    $category = Category::where('name', $categoryName)->first();
                    if ($category) {
                        $categoryIds[] = $category->id;
                    }
                }
            }
            
            $good->categories()->sync($categoryIds);
        }

        // Обработка брендов
        if (!empty($row['Бренды'])) {
            $brandNames = explode(';', $row['Бренды']);
            $brandIds = [];
            
            foreach ($brandNames as $brandName) {
                $brandName = trim($brandName);
                if (!empty($brandName)) {
                    $brand = ShopBrand::where('name', $brandName)->first();
                    if ($brand) {
                        $brandIds[] = $brand->id;
                    }
                }
            }
            
            $good->brands()->sync($brandIds);
        }

        // Обработка тегов
        if (!empty($row['Теги'])) {
            $tagNames = explode(';', $row['Теги']);
            $tagIds = [];
            
            foreach ($tagNames as $tagName) {
                $tagName = trim($tagName);
                if (!empty($tagName)) {
                    $tag = ShopTag::where('name', $tagName)->first();
                    if (!$tag) {
                        $tag = ShopTag::create([
                            'name' => $tagName,
                            'slug' => Str::slug($tagName)
                        ]);
                    }
                    $tagIds[] = $tag->id;
                }
            }
            
            $good->tags()->sync($tagIds);
        }

        // Обработка свойств
        if (!empty($row['Свойства'])) {
            $properties = explode(';', $row['Свойства']);
            
            foreach ($properties as $property) {
                $property = trim($property);
                if (!empty($property) && strpos($property, ':') !== false) {
                    [$propertyName, $propertyValue] = explode(':', $property, 2);
                    $propertyName = trim($propertyName);
                    $propertyValue = trim($propertyValue);
                    
                    if (!empty($propertyName) && !empty($propertyValue)) {
                        $propertyModel = ShopProperty::where('name', $propertyName)->first();
                        if (!$propertyModel) {
                            $propertyModel = ShopProperty::create([
                                'name' => $propertyName,
                                'slug' => Str::slug($propertyName)
                            ]);
                        }
                        
                        $good->properties()->syncWithoutDetaching([
                            $propertyModel->id => ['value' => $propertyValue]
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
