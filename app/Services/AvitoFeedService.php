<?php

namespace App\Services;

use App\Models\ShopGood;
use App\Models\ShopCategory;
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
    protected $defaultDelivery;
    protected $propertyValuesMap;
    protected $categoryParents;
    protected $categoryDepths;
    protected $allCategoriesById;

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
        $this->defaultDelivery = Setting::where('key', 'avito_default_delivery')->value('value') ?: '';

        // Предварительная загрузка всех категорий для эффективного поиска самой глубокой вложенности
        $allCats = ShopCategory::all();
        $this->allCategoriesById = $allCats->keyBy('id');
        $this->categoryParents = $allCats->pluck('parent_id', 'id')->toArray();
        $this->categoryDepths = [];

        foreach ($this->categoryParents as $id => $parentId) {
            $depth = 0;
            $currId = $id;
            $visited = []; // Защита от циклов
            while (isset($this->categoryParents[$currId]) && $this->categoryParents[$currId]) {
                if (in_array($currId, $visited)) {
                    break;
                }
                $visited[] = $currId;
                $depth++;
                $currId = $this->categoryParents[$currId];
                if ($depth > 20) {
                    break;
                }
            }
            $this->categoryDepths[$id] = $depth;
        }
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
                        'variations.shopSupplier',
                        'properties',
                        'shopSupplier'
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
                    'variations.shopSupplier',
                    'properties',
                    'shopSupplier'
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
        
        // Находим категорию с максимальной вложенностью среди всех категорий товара
        $category = null;
        if ($good->categories->isNotEmpty()) {
            $maxDepth = -1;
            foreach ($good->categories as $cat) {
                $depth = $this->categoryDepths[$cat->id] ?? 0;
                if ($depth > $maxDepth) {
                    $maxDepth = $depth;
                    $category = $cat;
                }
            }
        }

        while ($category) {
            if (isset($this->mapping[$category->id]) && !empty($this->mapping[$category->id])) {
                $avitoCategory = $this->mapping[$category->id];
                break;
            }
            // Используем кеш родителей, чтобы избежать N+1 запросов к БД
            $parentId = $this->categoryParents[$category->id] ?? null;
            $category = $parentId ? ($this->allCategoriesById[$parentId] ?? null) : null;
        }

        // Разделяем путь категории Авито (формат: "Хобби и отдых > Спорт и отдых > Зимний спорт > ...")
        // Также поддерживаем различные варианты тире как разделителей
        $avitoCategory = str_replace([' - ', ' – ', ' — '], ' > ', $avitoCategory);
        $parts = array_map('trim', explode('>', $avitoCategory));

        $originalRoot = count($parts) > 0 ? $parts[0] : '';

        // Корневые категории Авито не должны попадать в тег <Category>
        // Авито начинает иерархию XML со второго уровня (например, "Спорт и отдых", а не "Хобби и отдых")
        $topLevelRoots = [
            'Хобби и отдых', 'Личные вещи', 'Для дома и дачи', 'Электроника',
            'Готовый бизнес и оборудование', 'Транспорт', 'Недвижимость',
            'Услуги', 'Работа', 'Животные', 'Подработка'
        ];

        if (count($parts) >= 2 && in_array($parts[0], $topLevelRoots)) {
            // Отрезаем корневой элемент, чтобы parts[0] стал реальной Category
            $parts = array_values(array_slice($parts, 1));
        }

        $defaultTags = ['Category', 'GoodsType', 'GoodsSubType', 'GoodsSubCategory'];

        $cat = $parts[0] ?? '';
        $subCat = $parts[1] ?? '';

        // Настраиваем авто-определение тегов по конкретным категориям
        if (mb_stripos($avitoCategory, 'Велосипеды') !== false) {
            $defaultTags = ['Category', 'VehicleType', 'GoodsSubCategory', 'GoodsType', 'GoodsSubType'];
        } 
        
        // Для категории "Спорт и отдых" и её подкатегорий
        if (mb_stripos($avitoCategory, 'Спорт и отдых') !== false) {
            if (mb_stripos($avitoCategory, 'Дайвинг и водный спорт') !== false) {
                $defaultTags = ['Category', 'GoodsType', 'WaterSportType', 'GoodsSubType'];
            } elseif (mb_stripos($avitoCategory, 'Зимний спорт') !== false || mb_stripos($avitoCategory, 'Зимние виды спорта') !== false) {
                $defaultTags = ['Category', 'GoodsType', 'WinterSportType', 'GoodsSubType'];
            } elseif (mb_stripos($avitoCategory, 'Туризм и отдых на природе') !== false) {
                // Category > GoodsType > TourismType > GoodsSubType
                $defaultTags = ['Category', 'GoodsType', 'TourismType', 'GoodsSubType'];
            } elseif (mb_stripos($avitoCategory, 'Фитнес и тренажеры') !== false || mb_stripos($avitoCategory, 'Фитнесс и тренажеры') !== false) {
                $defaultTags = ['Category', 'GoodsType', 'FitnessType', 'GoodsSubType'];
            } elseif (mb_stripos($avitoCategory, 'Ролики и скейтбординг') !== false) {
                // Для скейтбординга: Category > GoodsType > GoodsSubCategory > GoodsSubType
                $defaultTags = ['Category', 'GoodsType', 'GoodsSubCategory', 'GoodsSubType'];
            }
        }

        if ($originalRoot === 'Транспорт' || $cat === 'Транспорт') {
            $defaultTags = ['Category', 'GoodsType', 'ProductSubType'];
        }

        foreach ($parts as $index => $part) {
            if ($part === '') {
                continue;
            }

            // Если в строке указан кастомный тег (например, "VehicleType:Горные")
            if (strpos($part, ':') !== false) {
                list($customTag, $value) = explode(':', $part, 2);
                $this->addChildSafe($ad, trim($customTag), trim($value));
            } else {
                // Иначе используем стандартный тег для этого уровня
                if (isset($defaultTags[$index])) {
                    $this->addChildSafe($ad, $defaultTags[$index], $part);
                }
            }
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
            
            if ($mainAddress->longitude) {
                $this->addChildSafe($ad, 'Latitude', $mainAddress->longitude);
            }
            if ($mainAddress->latitude) {
                $this->addChildSafe($ad, 'Longitude', $mainAddress->latitude);
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

        // Добавляем способы доставки (ПВЗ, Курьер и т.д.)
        $deliveryOptionsStr = '';
        $supplier = $good->shopSupplier;

        // Если у основного товара нет поставщика, пробуем найти его в вариациях
        if (!$supplier && $good->variations->isNotEmpty()) {
            foreach ($good->variations as $variation) {
                if ($variation->shopSupplier) {
                    $supplier = $variation->shopSupplier;
                    break;
                }
            }
        } elseif ($supplier) {
            $source = 'main';
        }

        if ($supplier && !empty($supplier->avito_delivery_options)) {
            $deliveryOptionsStr = $supplier->avito_delivery_options;
        } elseif (!empty($this->defaultDelivery)) {
            $deliveryOptionsStr = $this->defaultDelivery;
            $source = 'default';
        }

        if (!empty($deliveryOptionsStr)) {
            $deliveryNode = $ad->addChild('Delivery');
            $options = array_map('trim', explode(',', $deliveryOptionsStr));
            foreach ($options as $option) {
                if (!empty($option)) {
                    $this->addChildSafe($deliveryNode, 'Option', $option);
                }
            }
        }

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
        
        // Цена самого товара (только если есть в наличии)
        if ($this->isInStock($good)) {
            $prices[] = $this->calculateFinalPrice($good);
        }

        // Цены активных вариаций (только если есть в наличии)
        foreach ($good->variations as $v) {
            if ($v->is_active && $this->isInStock($v)) {
                $prices[] = $this->calculateFinalPrice($v);
            }
        }

        $prices = array_filter($prices, fn($p) => $p > 0);
        
        // Если ничего нет в наличии, возвращаем базовую цену товара как fallback
        return !empty($prices) ? min($prices) : $this->calculateFinalPrice($good);
    }

    /**
     * Проверка наличия товара или вариации
     */
    private function isInStock($item)
    {
        if ($item->stock_quantity > 0) return true;
        
        if ($this->hasValueStock($item->remote_stock_quantity)) return true;
        if ($this->hasValueStock($item->fast_remote_stock_quantity)) return true;
        
        return false;
    }

    /**
     * Проверка строкового или числового значения остатка
     */
    private function hasValueStock($value)
    {
        if (empty($value)) return false;
        if (is_numeric($value) && (int)$value <= 0) return false;
        if ($value === '0') return false;
        
        // Если это строка типа ">10", то это считается наличием
        return true;
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
        $desc = $this->cleanHtml($good->description ?? '');
        $brief = $this->cleanHtml($good->brief_description ?? '');
        
        $fullDesc = '';
        if ($brief) {
            $fullDesc .= $brief;
            if ($desc) {
                $fullDesc .= "<br>" . $desc;
            }
        } else {
            $fullDesc = $desc;
        }

        // 1. Название товара
        $result = "<strong>" . $good->name . "</strong><br>";

        // 2. Список вариаций в наличии (Атрибуты - Цена)
        if ($good->variations->count() > 0) {
            $stockVariations = $good->variations->filter(function($v) {
                return $v->is_active && $this->isInStock($v);
            });

            if ($stockVariations->count() > 0) {
                foreach ($stockVariations as $v) {
                    $attrString = $v->attributes_string;
                    $priceVal = $this->calculateFinalPrice($v);
                    $price = number_format($priceVal, 0, '.', ' ');
                    $result .= ($attrString ?: $v->name) . " - " . $price . " руб.<br>";
                }
            }
        }

        // 3. Артикул и доп. информация
        if ($good->sku) {
            $result .= "<strong>Артикул:</strong> {$good->sku}<br>";
        }
        
        // 4. Основное описание
        $result .= $fullDesc;
        
        // 5. Глобальные обертки
        if ($this->descBefore) {
            $result = $this->descBefore . "<br>" . $result;
        }
        if ($this->descAfter) {
            $result = $result . "<br>" . $this->descAfter;
        }
        
        // Заменяем все множественные <br> на одинарные
        $result = preg_replace('/(<br\s*\/?>\s*)+/i', '<br>', $result);
        
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

    /**
     * Очистка HTML для Авито с сохранением структуры (переносы строк вместо блоков)
     */
    private function cleanHtml($html)
    {
        if (empty($html)) return '';

        // Декодируем сущности (например, &quot;)
        $html = htmlspecialchars_decode($html);

        // Специальная обработка для структуры <div class="title">...</div><div class="val">...</div>
        // Добавляем двоеточие между названием характеристики и значением
        $html = preg_replace('/<\/div>\s*<div[^>]*class="val"[^>]*>/i', ': ', $html);

        // Заменяем закрывающие теги блочных элементов на <br>, чтобы текст не слипался
        $blockTags = ['</div>', '</tr>', '</td>', '</li>', '</p>', '</h1>', '</h2>', '</h3>', '</h4>', '</h5>', '</h6>'];
        $html = str_ireplace($blockTags, '<br>', $html);

        // Оставляем только разрешенные Авито теги
        $allowedTags = '<p><br><strong><em><ul><ol><li>';
        $cleaned = strip_tags($html, $allowedTags);

        // Удаляем дублирующиеся <br> и лишние пробелы
        $cleaned = preg_replace('/(<br\s*\/?>\s*)+/i', '<br>', $cleaned);
        $cleaned = preg_replace('/[ \t]+/', ' ', $cleaned);
        
        return trim($cleaned);
    }

    protected function getImages($good)
    {
        $urls = [];
        $base = rtrim($this->baseUrl, '/');

        // Если есть вариации, собираем картинки
        if ($good->variations->count() > 0) {
            $activeVariations = $good->variations->filter(fn($v) => $v->is_active);

            // Пытаемся сгруппировать по цвету
            $byColor = [];
            foreach ($activeVariations as $v) {
                $colorValue = null;
                // Ищем атрибут "Цвет"
                foreach ($v->attributeValues as $av) {
                    if ($av->attribute && mb_strtolower($av->attribute->name) === 'цвет') {
                        $colorValue = mb_strtolower(trim($av->value));
                        break;
                    }
                }

                if ($colorValue) {
                    $byColor[$colorValue][] = $v;
                }
            }

            if (!empty($byColor)) {
                // Если есть цвета, берем по одному фото на каждый цвет
                foreach ($byColor as $color => $variations) {
                    foreach ($variations as $v) {
                        if ($v->images->count() > 0) {
                            $img = $v->images->first();
                            $path = ltrim($img->file_path ?? $img->url, '/');
                            if ($path) {
                                $url = $base . '/' . $path;
                                if (!in_array($url, $urls)) {
                                    $urls[] = $url;
                                }
                            }
                            break; // Взяли одно фото для этого цвета, переходим к следующему
                        }
                    }
                    if (count($urls) >= 10) break;
                }
            } else {
                // Если атрибута цвет нет, берем картинки из всех активных вариаций (старая логика)
                foreach ($activeVariations as $v) {
                    if ($v->images) {
                        foreach ($v->images as $img) {
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
