<?php

namespace App\Services;

use App\Models\ShopGood;
use App\Models\Setting;
use App\Models\Contact;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class AvitoFeedService
{
    protected $baseUrl;
    protected $mapping;
    protected $propertyMapping;
    protected $tags;
    protected $descBefore;
    protected $descAfter;

    public function __construct()
    {
        $this->baseUrl = config('app.frontend_url', env('FRONTEND_URL', ''));
        $mappingJson = Setting::where('key', 'avito_category_mapping')->value('value');
        $this->mapping = is_array($mappingJson) ? $mappingJson : (json_decode($mappingJson, true) ?: []);

        $propMappingJson = Setting::where('key', 'avito_property_mapping')->value('value');
        $this->propertyMapping = is_array($propMappingJson) ? $propMappingJson : (json_decode($propMappingJson, true) ?: []);

        $tagsJson = Setting::where('key', 'avito_tags')->value('value');
        $this->tags = is_array($tagsJson) ? $tagsJson : (json_decode($tagsJson, true) ?: []);

        $this->descBefore = Setting::where('key', 'avito_description_before')->value('value') ?: '';
        $this->descAfter = Setting::where('key', 'avito_description_after')->value('value') ?: '';
    }

    /**
     * Генерация XML фида для Авито
     * @param string|null $path Путь для сохранения (опционально)
     * @param \Illuminate\Support\Collection|null $goods Колекция товаров (опционально)
     * @return array ['content' => string, 'count' => int]
     */
    public function generate($path = null, $goods = null)
    {
        ini_set('memory_limit', '2048M');
        ini_set('max_execution_time', '7200');

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
                        'variations.attributeValues.attribute',
                        'properties'
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
                    'variations.attributeValues.attribute',
                    'properties'
                ]);
            }

            $valueIds = [];
            foreach ($goods as $g) {
                if ($g->relationLoaded('properties')) {
                    foreach ($g->properties as $p) {
                        if (isset($p->pivot->shop_property_value_id)) {
                            $valueIds[] = $p->pivot->shop_property_value_id;
                        }
                    }
                }
            }
            $valueIds = array_unique($valueIds);
            $this->propertyValuesMap = \App\Models\Shop\PropertyValue::whereIn('id', $valueIds)->pluck('value', 'id')->toArray();

            foreach ($goods as $good) {
                $this->addAd($xml, $good);
            }

            $xmlContent = $xml->asXML();

            if ($path) {
                Storage::disk('public')->put($path, $xmlContent);
            }

            return [
                'count' => $goods->count(),
                'content' => $xmlContent
            ];
        }
        catch (\Exception $e) {
            Log::error('Avito Feed Generation Error: ' . $e->getMessage());
            return [
                'count' => 0,
                'content' => '',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Добавить объявление (Ad) в XML
     */
    protected function addAd(\SimpleXMLElement $xml, $good)
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

        // Разделяем путь категории Авито (формат: "Хобби и отдых > Спорт и отдых > Зимний спорт > ...")
        $parts = array_map('trim', explode('>', $avitoCategory));

        // Определяем Category — это ВТОРОЙ уровень дерева Авито (не корневой "Хобби и отдых")
        // Допустимые верхние категории: Спорт и отдых, Охота и рыбалка, Велосипеды, Музыкальные инструменты, и т.д.
        // Если путь начинается с корневого уровня (Хобби и отдых, Личные вещи, и т.д.) — пропускаем его
        $topLevelRoots = [
            'Хобби и отдых', 'Личные вещи', 'Для дома и дачи', 'Электроника',
            'Готовый бизнес и оборудование', 'Транспорт', 'Недвижимость',
            'Услуги', 'Работа', 'Животные', 'Подработка'
        ];

        if (count($parts) >= 2 && in_array($parts[0], $topLevelRoots)) {
            // Пропускаем корневой уровень — Category берём из parts[1]
            $categoryValue = $parts[1];
            $subParts = array_slice($parts, 2);
        } else {
            // Путь уже начинается с допустимой категории
            $categoryValue = $parts[0];
            $subParts = array_slice($parts, 1);
        }

        $this->addChildSafe($ad, 'Category', $categoryValue);

        // Подкатегория → GoodsSubType (если есть)
        if (!empty($subParts)) {
            // Берём самый глубокий (leaf) элемент как GoodsSubType
            $this->addChildSafe($ad, 'GoodsSubType', end($subParts));
        }

        // ГЕО: берем из основного контакта
        $mainContact = Contact::getMainContact();
        $mainAddress = $mainContact ? $mainContact->mainAddress() : null;
        
        if ($mainAddress) {
            $address = $mainAddress->address_short ?: $mainAddress->address;
            
            // Очистка адреса от HTML и лишней информации
            $address = strip_tags($address ?? '');
            $address = str_replace('&nbsp;', ' ', $address);
            
            // Если есть "Часы работы", берем всё до них
            if (mb_stripos($address, 'Часы работы') !== false) {
                $addressParts = explode('Часы работы', $address);
                $address = trim($addressParts[0], " \t\n\r\0\x0B. -");
            }

            $this->addChildSafe($ad, 'Address', trim($address));
            
            if ($mainAddress->latitude) {
                $this->addChildSafe($ad, 'Latitude', $mainAddress->latitude);
            }
            if ($mainAddress->longitude) {
                $this->addChildSafe($ad, 'Longitude', $mainAddress->longitude);
            }
        } else {
            $this->addChildSafe($ad, 'Address', Setting::where('key', 'contact_address')->value('value') ?: 'Москва');
        }
        
        // Заголовок — Avito ограничивает 50 символами
        $title = mb_substr($good->name, 0, 50);
        $this->addChildSafe($ad, 'Title', $title);

        // Описание с форматированием
        $description = $this->formatDescription($good);
        $this->addCData($ad, 'Description', $description);

        // Цена: минимальная среди активных вариаций или цена товара
        $price = (int)$this->getMinPrice($good);
        $this->addChildSafe($ad, 'Price', $price);

        // Изображения
        $images = $ad->addChild('Images');
        $allImages = $this->getImages($good);
        foreach ($allImages as $imgUrl) {
            $image = $images->addChild('Image');
            $image->addAttribute('url', $imgUrl);
        }

        // Бренд
        $brand = $good->brands->first();
        if ($brand && !empty($brand->name)) {
            $this->addChildSafe($ad, 'Brand', $brand->name);
        } else {
            $this->addChildSafe($ad, 'Brand', 'Без бренда');
        }

        // Динамические параметры (например, VehicleType для Велосипедов)
        // Берем из карточки товара те характеристики, которые названы по-английски или с префиксом Avito:
        if ($good->relationLoaded('properties') && $good->properties) {
            foreach ($good->properties as $property) {
                // Пытаемся получить строковое значение свойства
                $value = null;
                if (isset($property->pivot->shop_property_value_id)) {
                    $valId = $property->pivot->shop_property_value_id;
                    $value = $this->propertyValuesMap[$valId] ?? null;
                }

                if ($value !== null && $value !== '') {
                    $propName = trim($property->name);
                    
                    // 1. Свойство замаплено в интерфейсе
                    if (isset($this->propertyMapping[$property->id]) && !empty($this->propertyMapping[$property->id])) {
                        $tagName = trim($this->propertyMapping[$property->id]);
                        if (preg_match('/^[a-zA-Z0-9_]+$/', $tagName)) {
                            $this->addChildSafe($ad, $tagName, $value);
                            continue; // Переходим к следующему свойству
                        }
                    }

                    // 2. Если Имя свойства строго на английском (например: VehicleType, SportType)
                    if (preg_match('/^[A-Z][a-zA-Z0-9]*$/', $propName)) {
                        $this->addChildSafe($ad, $propName, $value);
                    }
                    // 3. Если есть префикс "Avito: " (например: "Avito: VehicleType")
                    elseif (str_starts_with($propName, 'Avito:')) {
                        $tagName = trim(substr($propName, 6));
                        if (preg_match('/^[a-zA-Z0-9_]+$/', $tagName)) {
                            $this->addChildSafe($ad, $tagName, $value);
                        }
                    }
                }
            }
        }

        $this->addChildSafe($ad, 'Condition', 'Новое');
        $this->addChildSafe($ad, 'AdType', 'Товар приобретен на продажу');
        $this->addChildSafe($ad, 'ContactMethod', 'По телефону и в сообщениях');

        // Применяем постоянные теги
        if (!empty($this->tags)) {
            foreach ($this->tags as $tag) {
                if (!($tag['apply'] ?? false)) continue;
                
                $tagName = $tag['name'] ?? null;
                $tagType = $tag['type'] ?? 'value';
                
                // Для обычных тегов имя обязательно, для сложных XML - нет (т.к. имя внутри XML)
                if ($tagType !== 'complex' && !$tagName) continue;

                if ($tagType === 'value' && isset($tag['value'])) {
                    $this->addChildSafe($ad, $tagName, $tag['value']);
                } 
                elseif ($tagType === 'group' && !empty($tag['options'])) {
                    $node = $ad->addChild($tagName);
                    foreach ($tag['options'] as $option) {
                        if (!empty($option)) {
                            $this->addChildSafe($node, 'Option', $option);
                        }
                    }
                }
                elseif ($tagType === 'complex' && !empty($tag['complex_xml'])) {
                    try {
                        $fragment = new \SimpleXMLElement($tag['complex_xml']);
                        $toDom = dom_import_simplexml($ad);
                        $fromDom = dom_import_simplexml($fragment);
                        $toDom->appendChild($toDom->ownerDocument->importNode($fromDom, true));
                    } catch (\Exception $e) {
                        $identifier = $tagName ?: 'unnamed complex tag';
                        \Illuminate\Support\Facades\Log::error("AvitoFeedService: Failed to add complex tag {$identifier}: " . $e->getMessage());
                    }
                }
            }
        }
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

    protected function formatDescription($good)
    {
        $allowedTags = '<p><br><strong><em><ul><ol><li>';
        $desc = strip_tags($good->description ?? '', $allowedTags);
        $brief = strip_tags($good->brief_description ?? '', $allowedTags);
        
        $fullDesc = '';
        if ($brief) {
            $fullDesc .= $brief;
            if ($desc) {
                $fullDesc .= "<br><br>" . $desc;
            }
        } else {
            $fullDesc = $desc;
        }
        
        $extra = "";
        if ($good->sku) {
            $extra .= "<strong>Артикул:</strong> {$good->sku}<br>";
        }
        
        if ($good->variations->count() > 0) {
            $activeVariations = $good->variations->filter(fn($v) => $v->is_active);
            if ($activeVariations->count() > 0) {
                $extra .= "<strong>Доступные варианты:</strong><br>";
                foreach ($activeVariations as $v) {
                    $attrString = $v->attributes_string;
                    $extra .= "- " . ($attrString ?: $v->name);
                    if ($v->sku) {
                        $extra .= " (Артикул: {$v->sku})";
                    }
                    $extra .= "<br>";
                }
            }
        }
        
        $result = $extra ? ($extra . "<br><br>" . $fullDesc) : $fullDesc;
        
        // Добавляем глобальные обертки
        if ($this->descBefore) {
            $result = $this->descBefore . "<br><br>" . $result;
        }
        if ($this->descAfter) {
            $result = $result . "<br><br>" . $this->descAfter;
        }
        
        return $result;
    }

    protected function addCData(\SimpleXMLElement $node, $name, $value)
    {
        $child = $node->addChild($name);
        $dom = dom_import_simplexml($child);
        $owner = $dom->ownerDocument;
        $dom->appendChild($owner->createCDATASection($value));
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
        $base = rtrim($this->baseUrl, '/');

        // Если есть вариации, собираем картинки из всех вариаций
        if ($good->variations->count() > 0) {
            foreach ($good->variations as $variation) {
                if ($variation->is_active && $variation->images) {
                    foreach ($variation->images as $img) {
                        $path = ltrim($img->file_path ?? $img->url, '/');
                        if ($path) {
                            $url = $base . '/' . $path;
                            if (!in_array($url, $urls)) {
                                $urls[] = $url;
                            }
                        }
                        if (count($urls) >= 10) break 2;
                    }
                }
            }
        }

        // Если картинок из вариаций нет или их меньше 10, добавляем из основного товара
        if (count($urls) < 10) {
            foreach ($good->images as $img) {
                $path = ltrim($img->file_path ?? $img->url, '/');
                if ($path) {
                    $url = $base . '/' . $path;
                    if (!in_array($url, $urls)) {
                        $urls[] = $url;
                    }
                }
                if (count($urls) >= 10) break;
            }
        }

        // Если всё ещё нет изображений, берем логотип сайта как заглушку
        if (empty($urls)) {
            $logo = Setting::where('key', 'site_logo')->value('value');
            if ($logo) {
                $urls[] = $base . '/' . ltrim($logo, '/');
            }
        }

        return $urls;
    }
}
