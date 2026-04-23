<?php

namespace App\Services;

use App\Models\ShopCategory;
use App\Models\ShopGood;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class YmlFeedService
{
    /**
     * Генерация YML фида
     * 
     * @return array
     */
    public function generate()
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

            fwrite($handle, '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL);
            fwrite($handle, '<yml_catalog date="' . date('Y-m-d H:i') . '">' . PHP_EOL);
            fwrite($handle, '    <!-- build:' . date('c') . ' env=' . config('app.env') . ' -->' . PHP_EOL);
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
            ShopCategory::chunk(100, function ($categories) use ($handle) {
                foreach ($categories as $category) {
                    $parentId = $category->parent_id ? ' parentId="' . $category->parent_id . '"' : '';
                    fwrite($handle, '            <category id="' . $category->id . '"' . $parentId . '>' . htmlspecialchars($category->name) . '</category>' . PHP_EOL);
                }
            });
            fwrite($handle, '        </categories>' . PHP_EOL);

            // Товары
            fwrite($handle, '        <offers>' . PHP_EOL);

            // Загружаем товары порциями для экономии памяти
            $query = ShopGood::active()->with([
                'categories:id,name',
                'brands:id,name',
                'images:id,good_id,file_path,alt_text,is_main,sort_order',
                'variations' => function ($q) {
                    $q->select('id', 'good_id')
                        ->with('images:id,variation_id,file_path,alt_text,is_main,sort_order');
                },
            ]);

            $count = 0;
            $query->chunk(200, function ($goods) use ($handle, &$count) {
                foreach ($goods as $good) {
                    $this->writeOfferToHandle($handle, $good);
                    $count++;
                }
                unset($goods);
            });

            fwrite($handle, '        </offers>' . PHP_EOL);
            fwrite($handle, '    </shop>' . PHP_EOL);
            fwrite($handle, '</yml_catalog>');

            fclose($handle);

            // Копируем на фронтенд, если путь настроен
            $this->copyToFrontend($filename);

            $size = Storage::disk('public')->size($filepath);
            $lastModified = Storage::disk('public')->lastModified($filepath);

            return [
                'success' => true,
                'filename' => $filename,
                'generated_at' => date('Y-m-d H:i:s', $lastModified),
                'size' => round($size / 1024, 2) . ' KB',
                'count' => $count,
                'download_url' => Storage::disk('public')->url($filepath),
            ];

        } catch (\Exception $e) {
            Log::error('YML Export Error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
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

        if ($mainSite !== '') {
            $cached = rtrim($mainSite, '/');
            return $cached;
        }

        $frontendUrl = config('app.frontend_url');
        $frontendUrl = is_string($frontendUrl) ? trim($frontendUrl) : '';

        if ($frontendUrl === '') {
            return rtrim(config('app.url'), '/'); // Fallback to app url
        }

        $cached = rtrim($frontendUrl, '/');
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
        if (empty($good->price) || empty($good->name)) {
            return;
        }

        // Определяем доступность
        $available = ($good->stock_quantity > 0) ? 'true' : 'false';

        fwrite($handle, '            <offer id="' . $good->id . '" available="' . $available . '">' . PHP_EOL);

        $url = $this->getMainSiteUrl() . '/product/' . ($good->slug ?? $good->id);
        fwrite($handle, '                <url>' . htmlspecialchars($url) . '</url>' . PHP_EOL);

        // Цена
        $price = $good->sale_price ?? $good->price;
        $oldPrice = ($good->sale_price && $good->sale_price < $good->price) ? $good->price : null;

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
        if ($good->images->isNotEmpty()) {
            $images = $good->images;
        } elseif ($good->variations->isNotEmpty()) {
            foreach ($good->variations as $variation) {
                if ($variation->images->isNotEmpty()) {
                    $images = $variation->images;
                    break;
                }
            }
        }

        if ($images->isNotEmpty()) {
            $images = $images->sortBy('sort_order')->sortByDesc('is_main');
            foreach ($images as $image) {
                $imgUrl = $this->getYmlImageUrl($image->file_path);
                if ($imgUrl) {
                    fwrite($handle, '                <picture>' . htmlspecialchars($imgUrl) . '</picture>' . PHP_EOL);
                }
            }
        }

        $name = htmlspecialchars($good->name);
        $name = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{2028}\x{2029}]/u', '', $name);
        fwrite($handle, '                <name>' . $name . '</name>' . PHP_EOL);

        // Бренд
        if ($good->brands->isNotEmpty()) {
            $brandName = $good->brands->first()->name;
            $brandName = htmlspecialchars($brandName);
            $brandName = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{2028}\x{2029}]/u', '', $brandName);
            fwrite($handle, '                <vendor>' . $brandName . '</vendor>' . PHP_EOL);
        }

        if (!empty($good->description)) {
            $description = strip_tags($good->description);
            $description = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{2028}\x{2029}]/u', '', $description);
            fwrite($handle, '                <description><![CDATA[' . $description . ']]></description>' . PHP_EOL);
        }

        // Добавляем тэг count (остаток)
        fwrite($handle, '                <count>' . ($good->stock_quantity ?? 0) . '</count>' . PHP_EOL);

        fwrite($handle, '            </offer>' . PHP_EOL);
    }

    private function copyToFrontend($filename)
    {
        $frontendPathRelative = config('frontend.path');
        if ($frontendPathRelative) {
            $frontendBasePath = base_path($frontendPathRelative);
            $frontendPublicPath = $frontendBasePath . '/public';

            if (is_dir($frontendPublicPath)) {
                $sourcePath = Storage::disk('public')->path('exports/' . $filename);
                $destPath = $frontendPublicPath . '/' . $filename;
                @copy($sourcePath, $destPath);
            }
        }
    }
}
