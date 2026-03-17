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
     * @return string XML контент
     */
    public function generate($path = null)
    {
        try {
            $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><Ads target="Avito" formatVersion="3"></Ads>');

            // Получаем только активные товары
            $goods = ShopGood::where('is_active', true)
                ->where('is_show', true)
                ->with(['categories', 'brands', 'images', 'variations', 'variations.images'])
                ->get();

            foreach ($goods as $good) {
                if ($good->variations->count() > 0) {
                    foreach ($good->variations as $variation) {
                        if ($variation->is_active) {
                            $this->addAd($xml, $good, $variation);
                        }
                    }
                }
                else {
                    $this->addAd($xml, $good);
                }
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
    protected function addAd(\SimpleXMLElement $xml, ShopGood $good, $variation = null)
    {
        $ad = $xml->addChild('Ad');

        $id = $variation ? "v_{$variation->id}" : "g_{$good->id}";
        $ad->addChild('Id', htmlspecialchars($id));

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
        $ad->addChild('Category', htmlspecialchars($parts[0] ?? 'Спорт и отдых'));
        if (isset($parts[1]))
            $ad->addChild('GoodsCategory', htmlspecialchars($parts[1]));
        if (isset($parts[2]))
            $ad->addChild('GoodsType', htmlspecialchars($parts[2]));
        if (isset($parts[3]))
            $ad->addChild('ProductType', htmlspecialchars($parts[3]));

        $ad->addChild('Address', htmlspecialchars(Setting::where('key', 'contact_address')->value('value') ?: 'Москва'));
        $title = $variation ? "{$good->name} ({$variation->name})" : $good->name;
        $ad->addChild('Title', htmlspecialchars($title));
        $ad->addChild('Description', htmlspecialchars(strip_tags($good->description ?: $good->name)));
        $ad->addChild('Price', (int)($variation ? ($variation->price ?: $good->price) : $good->price));

        // Изображения
        $images = $ad->addChild('Images');
        $allImages = $this->getImages($good, $variation);
        foreach ($allImages as $imgUrl) {
            $image = $images->addChild('Image');
            $image->addAttribute('url', htmlspecialchars($imgUrl));
        }

        $ad->addChild('Condition', 'Новое');
        $ad->addChild('ListingFee', 'Package'); // Или по настройке
        $ad->addChild('ContactMethod', 'ByPhone'); // Или по настройке
    }

    protected function getImages($good, $variation = null)
    {
        $urls = [];
        $images = $variation && $variation->images->count() > 0 ? $variation->images : $good->images;

        foreach ($images as $img) {
            $urls[] = $this->baseUrl . $img->file_path;
        }

        return $urls;
    }
}
