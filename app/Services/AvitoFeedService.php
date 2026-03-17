<?php

namespace App\Services;

use App\Models\ShopGood;
use App\Models\Setting;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class AvitoFeedService
{
    protected $baseUrl;
    protected $mapping;

    public function __construct()
    {
        $this->baseUrl = config('app.frontend_url', env('FRONTEND_URL', ''));
        $mappingJson = Setting::where('key', 'avito_category_mapping')->value('value');
        $this->mapping = is_array($mappingJson) ? $mappingJson : (json_decode($mappingJson, true) ?: []);
    }

    /**
     * Генерация XML фида для Авито
     * @param string|null $path Путь для сохранения (опционально)
     * @param \Illuminate\Support\Collection|null $goods Колекция товаров (опционально)
     * @return string XML контент
     */
    public function generate($path = null, $goods = null)
    {
        Log::error('AvitoFeedService: Starting feed generation (v5 - external goods support)');
        try {
            $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><Ads target="Avito" formatVersion="3"></Ads>');

            // Если товары не переданы, получаем только активные товары
            if (!$goods) {
                $goods = ShopGood::where('is_active', true)
                    ->where('is_show', true)
                    ->with([
                        'categories', 
                        'brands', 
                        'images', 
                        'variations', 
                        'variations.images', 
                        'variations.attributeValues', 
                        'variations.attributeValues.attribute'
                    ])
                    ->get();
            } else {
                // Если товары переданы, убеждаемся что загружены нужные связи (для тех у кого они еще не загружены)
                $goods->loadMissing([
                    'categories', 
                    'brands', 
                    'images', 
                    'variations', 
                    'variations.images', 
                    'variations.attributeValues', 
                    'variations.attributeValues.attribute'
                ]);
            }

            foreach ($goods as $good) {
                $this->addAd($xml, $good);
            }

            $xmlContent = $xml->asXML();

            if ($path) {
                Storage::disk('public')->put($path, $xmlContent);
            }

            return $xmlContent;
        }
        catch (\Exception $e) {
            Log::error('Avito Feed Generation Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Добавить объявление (Ad) в XML
     */
    /**
     * Добавить объявление (Ad) в XML
     */
    protected function addAd(\SimpleXMLElement $xml, ShopGood $good)
    {
        $ad = $xml->addChild('Ad');

        $this->addChildSafe($ad, 'Id', "g_{$good->id}");

        // Категория Авито из маппинга (с поиском по родителям)
        $avitoCategory = 'Спорт и отдых';
        $category = $good->categories->first();

        while ($category) {
            if (isset($this->mapping[$category->id]) && !empty($this->mapping[$category->id])) {
                $avitoCategory = $this->mapping[$category->id];
                break;
            }
            $category = $category->parent;
        }

        // Разделяем путь категории Авито
        $parts = explode(' > ', $avitoCategory);
        $this->addChildSafe($ad, 'Category', $parts[0] ?? 'Спорт и отдых');
        if (isset($parts[1]))
            $this->addChildSafe($ad, 'GoodsCategory', $parts[1]);
        if (isset($parts[2]))
            $this->addChildSafe($ad, 'GoodsType', $parts[2]);
        if (isset($parts[3]))
            $this->addChildSafe($ad, 'ProductType', $parts[3]);

        $this->addChildSafe($ad, 'Address', Setting::where('key', 'contact_address')->value('value') ?: 'Москва');
        
        // Заголовок без вариаций
        $this->addChildSafe($ad, 'Title', $good->name);

        // Описание с перечислением вариаций
        $description = "";
        if ($good->sku) {
            $description .= "Артикул: {$good->sku}\n\n";
        }
        $description .= strip_tags($good->description ?: $good->name);

        // Добавляем вариации в описание
        if ($good->variations->count() > 0) {
            $activeVariations = $good->variations->filter(fn($v) => $v->is_active);
            if ($activeVariations->count() > 0) {
                $description .= "\n\nВарианты в наличии:\n";
                foreach ($activeVariations as $v) {
                    $attrString = $v->attributes_string;
                    $description .= "- " . ($attrString ?: $v->name);
                    if ($v->sku) {
                        $description .= " (Артикул: {$v->sku})";
                    }
                    $description .= "\n";
                }
            }
        }
        
        $this->addChildSafe($ad, 'Description', $description);

        // Цена: минимальная среди активных вариаций или цена товара
        $price = $this->getMinPrice($good);
        $this->addChildSafe($ad, 'Price', (int)$price);

        // Изображения (только от основного товара)
        $images = $ad->addChild('Images');
        $allImages = $this->getImages($good);
        foreach ($allImages as $imgUrl) {
            $image = $images->addChild('Image');
            $image->addAttribute('url', $imgUrl);
        }

        $this->addChildSafe($ad, 'Condition', 'Новое');
        $this->addChildSafe($ad, 'ListingFee', 'Package'); 
        $this->addChildSafe($ad, 'ContactMethod', 'ByPhone'); 
    }

    /**
     * Получить минимальную цену среди товара и его вариаций
     */
    private function getMinPrice(ShopGood $good)
    {
        $prices = [];
        
        // Цена самого товара
        $prices[] = $this->calculateFinalPrice($good);

        // Цены активных вариаций
        foreach ($good->variations as $v) {
            if ($v->is_active) {
                $prices[] = $this->calculateFinalPrice($v);
            }
        }

        $prices = array_filter($prices, fn($p) => $p > 0);
        
        return !empty($prices) ? min($prices) : 0;
    }

    /**
     * Расчет финальной цены для объекта (Good или Variation) по приоритету
     */
    private function calculateFinalPrice($item)
    {
        if ($item->show_demping && $item->demping_price > 0) {
            return $item->demping_price;
        }

        if ($item->sale_price > 0) {
            return $item->sale_price;
        }

        return $item->price;
    }

    protected function addChildSafe(\SimpleXMLElement $node, $name, $value)
    {
        $child = $node->addChild($name);
        if ($value !== null && $value !== '') {
            $child[0] = (string)$value;
        }
        return $child;
    }

    protected function getImages($good)
    {
        $urls = [];
        $images = $good->images;

        $base = rtrim($this->baseUrl, '/');

        foreach ($images as $img) {
            $path = ltrim($img->file_path, '/');
            $urls[] = $base . '/' . $path;
        }

        return $urls;
    }
}
