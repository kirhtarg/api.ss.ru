<?php

namespace App\Services;

use App\Helpers\PriceHelper;
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
                'properties.values:id,property_id,value',
                'variations' => function ($q) {
                    $q->select(
                        'id',
                        'good_id',
                        'price',
                        'sale_price',
                        'demping_price',
                        'show_demping',
                        'stock_quantity',
                        'remote_stock_quantity',
                        'fast_remote_stock_quantity',
                        'weight',
                        'length',
                        'width',
                        'height',
                        'is_active'
                    )
                        ->where('is_active', true)
                        ->with('images:id,variation_id,file_path,alt_text,is_main,sort_order');
                },
            ]);

            $count = 0;
            $query->chunk(200, function ($goods) use ($handle, &$count) {
                foreach ($goods as $good) {
                    if ($this->writeOfferToHandle($handle, $good)) {
                        $count++;
                    }
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

    private function writeOfferToHandle($handle, $good): bool
    {
        $priceData = $this->getOfferPriceData($good);

        // Пропускаем товары без имени или без актуальной положительной цены.
        if (empty($good->name) || $priceData === null) {
            return false;
        }

        // В поисковый YML передаем только доступные к продаже позиции. Нулевой
        // итоговый остаток означает, что карточка не должна участвовать в
        // товарных предложениях Яндекса и создавать проверку "нет в наличии".
        // Для товаров с вариациями остаток хранится в вариациях.
        $stock = $this->getOfferStockValue($good);
        if ($stock <= 0) {
            return false;
        }

        $available = 'true';
        $logisticsData = $this->getOfferLogisticsData($good, $priceData['item'] ?? null);

        fwrite($handle, '            <offer id="' . $good->id . '" available="' . $available . '">' . PHP_EOL);

        $url = $this->getMainSiteUrl() . '/catalog/' . ($good->slug ?? $good->id);
        fwrite($handle, '                <url>' . htmlspecialchars($url) . '</url>' . PHP_EOL);
        fwrite($handle, '                <count>' . $stock . '</count>' . PHP_EOL);

        // Цена. Для товаров с вариациями выгружаем минимальную актуальную цену активной вариации.
        $price = $priceData['price'];
        $oldPrice = $priceData['oldprice'];

        fwrite($handle, '                <price>' . $price . '</price>' . PHP_EOL);
        if ($oldPrice !== null) {
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

        if ($good->relationLoaded('properties')) {
            $params = $good->properties
                ->groupBy('id')
                ->map(function ($properties) {
                    $property = $properties->first();
                    $values = $properties
                        ->map(function ($entry) {
                            $valueId = $entry->pivot?->shop_property_value_id;
                            return $entry->values->firstWhere('id', $valueId)?->value;
                        })
                        ->filter(fn ($value) => $value !== null && trim((string) $value) !== '')
                        ->map(fn ($value) => html_entity_decode(trim((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8'))
                        ->unique()
                        ->values();

                    return ['name' => $property->name, 'value' => $values->implode(', ')];
                })
                ->filter(fn ($param) => trim((string) $param['name']) !== '' && $param['value'] !== '');

            foreach ($params as $param) {
                fwrite($handle, '                <param name="'.htmlspecialchars(html_entity_decode($param['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_XML1, 'UTF-8').'">'.htmlspecialchars($param['value'], ENT_QUOTES | ENT_XML1, 'UTF-8').'</param>'.PHP_EOL);
            }
        }

        if ($logisticsData['weight'] !== null) {
            fwrite($handle, '                <weight>' . $this->formatYmlNumber($logisticsData['weight']) . '</weight>' . PHP_EOL);
        }

        if ($logisticsData['dimensions'] !== null) {
            fwrite($handle, '                <dimensions>' . htmlspecialchars($logisticsData['dimensions']) . '</dimensions>' . PHP_EOL);
        }

        fwrite($handle, '            </offer>' . PHP_EOL);

        return true;
    }

    private function getOfferPriceData(ShopGood $good): ?array
    {
        $candidates = [];

        if ($good->relationLoaded('variations') && $good->variations->isNotEmpty()) {
            $activeVariations = $good->variations->filter(fn ($variation) => $variation->is_active);
            $inStockVariations = $activeVariations->filter(fn ($variation) => $this->getItemStockValue($variation) > 0);

            foreach (($inStockVariations->isNotEmpty() ? $inStockVariations : $activeVariations) as $variation) {
                $priceData = $this->getItemPriceData($variation);
                if ($priceData !== null) {
                    $candidates[] = $priceData;
                }
            }
        }

        if (empty($candidates)) {
            $priceData = $this->getItemPriceData($good);
            if ($priceData !== null) {
                $candidates[] = $priceData;
            }
        }

        if (empty($candidates)) {
            return null;
        }

        usort($candidates, fn ($a, $b) => $a['numeric_price'] <=> $b['numeric_price']);

        return [
            'price' => $candidates[0]['price'],
            'oldprice' => $candidates[0]['oldprice'],
            'item' => $candidates[0]['item'],
        ];
    }

    private function getItemPriceData($item): ?array
    {
        $basePrice = $this->positivePrice($item->price ?? null);
        $salePrice = $this->positivePrice($item->sale_price ?? null);
        $dempingPrice = $this->positivePrice($item->demping_price ?? null);
        $showDemping = (bool) ($item->show_demping ?? false);

        $currentPrice = null;

        if ($showDemping && $dempingPrice !== null) {
            $currentPrice = $dempingPrice;
        } elseif ($salePrice !== null) {
            $currentPrice = $salePrice;
        } else {
            $currentPrice = $basePrice;
        }

        if ($currentPrice === null) {
            return null;
        }

        // Публичный YML обязан повторять цену витрины. Настройка изменения
        // цены в Яндекс Seller относится к API Seller и не должна влиять на
        // каталог, который Яндекс сверяет с публичной карточкой.
        $feedCurrentPrice = PriceHelper::roundPrice($currentPrice);
        if ($feedCurrentPrice <= 0) {
            return null;
        }
        $feedBasePrice = $basePrice !== null ? PriceHelper::roundPrice($basePrice) : null;
        $oldPrice = ($feedBasePrice !== null && $feedBasePrice > $feedCurrentPrice) ? $feedBasePrice : null;

        return [
            'numeric_price' => $feedCurrentPrice,
            'price' => $this->formatYmlPrice($feedCurrentPrice),
            'oldprice' => $oldPrice !== null ? $this->formatYmlPrice($oldPrice) : null,
            'item' => $item,
        ];
    }

    private function positivePrice($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $price = (float) str_replace(',', '.', (string) $value);

        return $price > 0 ? $price : null;
    }

    private function formatYmlPrice(float $price): string
    {
        $formatted = number_format($price, 2, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }

    private function getOfferStockValue(ShopGood $good): int
    {
        if ($good->relationLoaded('variations') && $good->variations->isNotEmpty()) {
            return $good->variations
                ->filter(fn ($variation) => $variation->is_active)
                ->sum(fn ($variation) => $this->getItemStockValue($variation));
        }

        return $this->getItemStockValue($good);
    }

    private function getItemStockValue($item): int
    {
        return $this->normalizeNumericStock($item->stock_quantity ?? 0)
            + $this->normalizeRemoteStockPresence($item->remote_stock_quantity ?? null)
            + $this->normalizeRemoteStockPresence($item->fast_remote_stock_quantity ?? null);
    }

    private function normalizeNumericStock($value): int
    {
        return max(0, (int) $value);
    }

    private function normalizeRemoteStockPresence($value): int
    {
        $stockValue = trim((string) ($value ?? ''));

        if ($stockValue === '' || $stockValue === '0') {
            return 0;
        }

        return 10;
    }

    private function getOfferLogisticsData(ShopGood $good, $preferredItem = null): array
    {
        // Вариации наследуют логистику основной карточки: в магазине габариты ведутся у товара.
        $weight = $this->positiveDimensionValue($good->shipping_weight ?? null)
            ?? $this->positiveDimensionValue($good->weight ?? null);
        $length = $this->positiveDimensionValue($good->shipping_length ?? null)
            ?? $this->positiveDimensionValue($good->length ?? null)
            ?? $this->positiveDimensionValue($good->depth ?? null);
        $width = $this->positiveDimensionValue($good->shipping_width ?? null)
            ?? $this->positiveDimensionValue($good->width ?? null);
        $height = $this->positiveDimensionValue($good->shipping_height ?? null)
            ?? $this->positiveDimensionValue($good->height ?? null);

        $multipliers = $this->getMarketSettings()['dimension_multipliers'];
        $weight = $weight !== null ? $weight * $multipliers['weight'] : null;
        $length = $length !== null ? $length * $multipliers['length'] : null;
        $width = $width !== null ? $width * $multipliers['width'] : null;
        $height = $height !== null ? $height * $multipliers['height'] : null;

        $dimensions = null;
        if ($length !== null && $width !== null && $height !== null) {
            $dimensions = implode('/', [
                $this->formatYmlNumber($length),
                $this->formatYmlNumber($width),
                $this->formatYmlNumber($height),
            ]);
        }

        return [
            'weight' => $weight,
            'dimensions' => $dimensions,
        ];
    }

    private function positiveDimensionValue($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $number = (float) str_replace(',', '.', (string) $value);

        return $number > 0 ? $number : null;
    }

    private function formatYmlNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }

    private function copyToFrontend($filename)
    {
        $frontendPathRelative = config('frontend.path');
        if (! $frontendPathRelative) {
            return;
        }

        $sourcePath = Storage::disk('public')->path('exports/' . $filename);
        $destinationPath = frontend_public_path($filename);
        $destinationDirectory = dirname($destinationPath);

        if (! is_dir($destinationDirectory)) {
            throw new \RuntimeException('Не найдена public-директория фронтенда для публикации YML: ' . $destinationDirectory);
        }

        // Сначала пишем временный файл в той же директории, затем атомарно
        // заменяем опубликованный. Робот Яндекса не получит частичный XML.
        $temporaryPath = $destinationPath . '.tmp';
        if (! copy($sourcePath, $temporaryPath)) {
            throw new \RuntimeException('Не удалось скопировать YML на витрину: ' . $temporaryPath);
        }

        if (! rename($temporaryPath, $destinationPath)) {
            @unlink($temporaryPath);
            throw new \RuntimeException('Не удалось опубликовать YML на витрине: ' . $destinationPath);
        }

        clearstatcache(true, $destinationPath);
        if (! is_file($destinationPath) || filesize($destinationPath) !== filesize($sourcePath)) {
            throw new \RuntimeException('Проверка опубликованной копии YML не пройдена: ' . $destinationPath);
        }
    }

}
