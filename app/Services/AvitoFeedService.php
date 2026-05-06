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
    protected $allowedBrands = [];
    protected $allowedModels = [];
    protected $currentCategoryBrands = [];
    protected $currentCategoryModels = [];
    protected $currentHierarchy = [];
    protected $globalHierarchy = [];
    
    protected $lastBrand = null;
    protected $lastModel = null;

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

        // Загрузка иерархии брендов и моделей
        $brandsInput = Setting::where('key', 'avito_brand_whitelist')->value('value') ?: '';
        $modelsInput = Setting::where('key', 'avito_model_whitelist')->value('value') ?: '';
        $brandExternalUrl = Setting::where('key', 'avito_brand_external_url')->value('value') ?: '';
        $modelExternalUrl = Setting::where('key', 'avito_model_external_url')->value('value') ?: '';
        
        $this->globalHierarchy = $this->parseWhitelist($brandsInput, 'brand');
        
        if ($brandExternalUrl) {
            $externalBrands = $this->fetchExternalWhitelist($brandExternalUrl);
            if ($externalBrands) {
                $this->globalHierarchy = $this->mergeHierarchies($this->globalHierarchy, $this->parseWhitelist($externalBrands, 'brand'));
            }
        }

        $this->globalHierarchy = $this->mergeHierarchies($this->globalHierarchy, $this->parseWhitelist($modelsInput, 'model'));

        if ($modelExternalUrl) {
            $externalModels = $this->fetchExternalWhitelist($modelExternalUrl);
            if ($externalModels) {
                $this->globalHierarchy = $this->mergeHierarchies($this->globalHierarchy, $this->parseWhitelist($externalModels, 'model'));
            }
        }
        
        // Для обратной совместимости (если где-то еще используются)
        $this->allowedBrands = array_keys($this->globalHierarchy);
        $this->allowedModels = isset($this->globalHierarchy['*']) ? array_keys($this->globalHierarchy['*']['models']) : [];

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
        $this->addChildSafe($ad, 'FeedVersion', '1.0.3'); 

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

        $categoryBrands = [];
        $categoryModels = [];
        while ($category) {
            $mappingData = $this->mapping[$category->id] ?? null;
            
            // Если маппинг - массив (новый формат: ['path' => '...', 'brands' => '...', 'models' => '...'])
            if (is_array($mappingData)) {
                if (empty($avitoCategory) || $avitoCategory === 'Спорт и отдых') {
                    $avitoCategory = $mappingData['path'] ?? 'Спорт и отдых';
                }
                if (!empty($mappingData['brands'])) {
                    $categoryBrands = $this->mergeHierarchies($categoryBrands, $this->parseWhitelist($mappingData['brands'], 'brand'));
                }
                if (!empty($mappingData['models'])) {
                    $categoryModels = $this->mergeHierarchies($categoryModels, $this->parseWhitelist($mappingData['models'], 'model'));
                }

                if (!empty($mappingData['external_url'])) {
                    $externalData = $this->fetchExternalWhitelist($mappingData['external_url']);
                    if ($externalData) {
                        $categoryBrands = $this->mergeHierarchies($categoryBrands, $this->parseWhitelist($externalData, 'brand'));
                    }
                }

                // Если путь найден, прекращаем поиск по родителям для пути
                if (!empty($mappingData['path'])) break;
            } 
            // Старый формат - просто строка
            elseif (!empty($mappingData)) {
                $avitoCategory = $mappingData;
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

        $defaultTags = ['Category', 'GoodsType', 'GoodsSubType', 'GoodsSubCategory', 'ProductType'];

        // Настраиваем авто-определение тегов по конкретным частям пути
        foreach ($parts as $p) {
            $pLower = mb_strtolower($p);
            
            if (mb_stripos($pLower, 'велосипеды') !== false) {
                $defaultTags = ['Category', 'VehicleType', 'GoodsSubCategory', 'GoodsType', 'GoodsSubType'];
                break;
            }
            if (mb_stripos($pLower, 'туризм') !== false) {
                $defaultTags = ['Category', 'GoodsType', 'TourismType', 'GoodsSubType', 'ProductType'];
                break;
            }
            if (mb_stripos($pLower, 'ролики') !== false || mb_stripos($pLower, 'скейтборд') !== false) {
                $defaultTags = ['Category', 'GoodsType', 'GoodsSubCategory', 'GoodsSubType'];
                break;
            }
            if (mb_stripos($pLower, 'зимний спорт') !== false || mb_stripos($pLower, 'зимние виды спорта') !== false) {
                $defaultTags = ['Category', 'GoodsType', 'WinterSportType', 'GoodsSubType', 'ProductType'];
                break;
            }
            if (mb_stripos($pLower, 'дайвинг') !== false || mb_stripos($pLower, 'водный спорт') !== false) {
                $defaultTags = ['Category', 'GoodsType', 'WaterSportType', 'GoodsSubType', 'ProductType'];
                break;
            }
            if (mb_stripos($pLower, 'фитнес') !== false || mb_stripos($pLower, 'тренажеры') !== false) {
                $defaultTags = ['Category', 'GoodsType', 'FitnessType', 'GoodsSubType', 'ProductType'];
                break;
            }
        }

        $cat = $parts[0] ?? '';
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
        
        // Проверка на категорию Велосипеды для особого формирования заголовка и тега Model
        $isBicycle = false;
        foreach ($parts as $p) {
            if (mb_stripos(mb_strtolower($p), 'велосипеды') !== false) {
                $isBicycle = true;
                break;
            }
        }

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

        // Сброс состояния валидации для нового объявления
        $this->lastBrand = null;
        $this->lastModel = null;
        
        // Подготовка иерархии для текущей категории (уже распарсено в цикле выше)
        $catHierarchy = $this->mergeHierarchies($categoryBrands, $categoryModels);
        $this->currentHierarchy = $this->mergeHierarchies($this->globalHierarchy, $catHierarchy);

        // Бренд
        $brand = $good->brands->first();
        $brandName = $brand ? $brand->name : 'Другой';
        
        // Валидация бренда через иерархию (поиск в белых списках)
        $validatedBrand = $this->validateHierarchy('Brand', $brandName);
        $this->addChildSafe($ad, 'Brand', $validatedBrand);
        
        // Обработка модели для велосипедов
        if ($isBicycle) {
            $extractedModel = $this->extractModelName($good->name, $brand ? $brand->name : '');
            if ($extractedModel) {
                // Валидация модели через иерархию
                $validatedModel = $this->validateHierarchy('Model', $extractedModel);
                $this->addChildSafe($ad, 'Model', $validatedModel);
            }
        }

        // Динамические параметры (характеристики)
        $wheelDiameter = '';
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
                    if (isset($this->propertyMapping[$property->id])) {
                        $tagName = trim($this->propertyMapping[$property->id]);
                        
                        // Если в маппинге пусто - значит пользователь хочет исключить это свойство
                        if ($tagName === '') {
                            continue;
                        }

                        if (preg_match('/^[a-zA-Z0-9_]+$/', $tagName)) {
                            $this->addChildSafe($ad, $tagName, $value);
                            
                            // Запоминаем диаметр колес для заголовка велосипеда
                            if ($tagName === 'WheelDiameter') {
                                $wheelDiameter = $value;
                            }
                            
                            continue; // Переходим к следующему свойству
                        }
                    }

                    // Выводим только то, что явно замаплено в интерфейсе (пункт 1 выше)
                }
            }
        }

        $this->addChildSafe($ad, 'Title', $title);
        
        // Добавляем обязательные поля, если они еще не добавлены через маппинг или теги
        if (!isset($ad->Condition)) $this->addChildSafe($ad, 'Condition', 'Новое');
        if (!isset($ad->AdType)) $this->addChildSafe($ad, 'AdType', 'Товар приобретен на продажу');
        if (!isset($ad->ContactMethod)) $this->addChildSafe($ad, 'ContactMethod', 'По телефону и в сообщениях');

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

    /**
     * Безопасное добавление дочернего узла с поддержкой pipe-разделителя и белого списка
     */
    protected function addChildSafe($parent, $name, $value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Список тегов, которые не нужно разбивать по пайпу
        $noSplit = ['Brand', 'Model', 'WheelDiameter', 'Title', 'Address', 'Description'];
        
        // Фильтрация Brand и Model через белый список (с учетом иерархии)
        if ($name === 'Brand' || $name === 'Model') {
            $value = $this->validateHierarchy($name, $value);
        }

        // Если это строка и содержит пайп, и тег не в списке исключений, разбиваем на Option
        if (is_string($value) && str_contains($value, '|') && !in_array($name, $noSplit)) {
            $node = $parent->addChild($name);
            $options = explode('|', $value);
            foreach ($options as $option) {
                $option = trim($option);
                if ($option !== '') {
                    $node->addChild('Option', htmlspecialchars($option));
                }
            }
            return $node;
        }

        return $parent->addChild($name, htmlspecialchars((string)$value));
    }


    /**
     * Парсинг списка (брендов, моделей) из разных форматов (запятые, новые строки, XML теги авито)
     * Поддерживает иерархию: <brand name="X"><model name="Y"><god name="Z"/></model></brand>
     */
    private function parseWhitelist($input, $defaultType = 'brand')
    {
        if (empty($input)) return [];

        $hierarchy = [];

        // 1. Попытка парсинга как XML (иерархический формат)
        if (str_contains($input, '<')) {
            try {
                // Обертываем в корень, чтобы SimpleXML мог прочитать последовательность тегов
                $xmlString = '<?xml version="1.0" encoding="UTF-8"?><root>' . $input . '</root>';
                $prevErrors = libxml_use_internal_errors(true);
                $xml = simplexml_load_string($xmlString);
                
                if ($xml) {
                    // Парсим бренды и вложенные модели
                    foreach ($xml->xpath('//brand') as $brandNode) {
                        $brandName = (string)$brandNode['name'];
                        if (!$brandName) continue;
                        
                        if (!isset($hierarchy[$brandName])) {
                            $hierarchy[$brandName] = ['models' => []];
                        }
                        
                        foreach ($brandNode->model as $modelNode) {
                            $modelName = (string)$modelNode['name'];
                            if (!$modelName) continue;
                            
                            if (!isset($hierarchy[$brandName]['models'][$modelName])) {
                                $hierarchy[$brandName]['models'][$modelName] = ['years' => []];
                            }
                            
                            foreach ($modelNode->god as $godNode) {
                                $yearName = (string)$godNode['name'];
                                if ($yearName) {
                                    $hierarchy[$brandName]['models'][$modelName]['years'][] = $yearName;
                                }
                            }
                        }
                    }
                    
                    // Парсим модели вне брендов (глобальные для этой строки)
                    foreach ($xml->xpath('/root/model') as $modelNode) {
                        $modelName = (string)$modelNode['name'];
                        if ($modelName) {
                            if (!isset($hierarchy['*'])) $hierarchy['*'] = ['models' => []];
                            $hierarchy['*']['models'][$modelName] = ['years' => []];
                        }
                    }
                }
                libxml_use_internal_errors($prevErrors);
            } catch (\Exception $e) {
                // Игнорируем ошибки XML
            }
        }

        // 2. Если иерархия пуста или есть текстовые части, парсим как плоский список
        $plainText = strip_tags($input);
        $lines = preg_split('/[,\n\r|]+/', $plainText);
        foreach ($lines as $line) {
            $item = trim($line);
            if (empty($item)) continue;
            
            if ($defaultType === 'brand') {
                if (!isset($hierarchy[$item])) {
                    $hierarchy[$item] = ['models' => []];
                }
            } else {
                if (!isset($hierarchy['*'])) $hierarchy['*'] = ['models' => []];
                if (!isset($hierarchy['*']['models'][$item])) {
                    $hierarchy['*']['models'][$item] = ['years' => []];
                }
            }
        }

        return $hierarchy;
    }

    /**
     * Рекурсивное слияние двух иерархий вайтлистов
     */
    private function mergeHierarchies($h1, $h2)
    {
        if (empty($h2)) return $h1;
        if (empty($h1)) return $h2;

        foreach ($h2 as $brand => $data) {
            if (!isset($h1[$brand])) {
                $h1[$brand] = $data;
            } else {
                foreach ($data['models'] as $model => $mdata) {
                    if (!isset($h1[$brand]['models'][$model])) {
                        $h1[$brand]['models'][$model] = $mdata;
                    } else {
                        $h1[$brand]['models'][$model]['years'] = array_unique(array_merge(
                            $h1[$brand]['models'][$model]['years'] ?? [],
                            $mdata['years'] ?? []
                        ));
                    }
                }
            }
        }
        return $h1;
    }

    /**
     * Валидация значения относительно иерархии (стейт-зависимая)
     */
    private function validateHierarchy($type, $value)
    {
        $hierarchy = $this->currentHierarchy;
        if (empty($hierarchy)) return $value;

        $trimmedValue = mb_strtolower(trim($value));

        if ($type === 'Brand') {
            foreach ($hierarchy as $bName => $bData) {
                if ($bName === '*') continue;
                if (mb_strtolower(trim($bName)) === $trimmedValue) {
                    $this->lastBrand = $bName;
                    return $bName;
                }
            }
            $this->lastBrand = 'Другой';
            return 'Другой';
        }

        if ($type === 'Model') {
            $brand = $this->lastBrand;
            
            // 1. Ищем в иерархии конкретного бренда
            if ($brand && isset($hierarchy[$brand]) && !empty($hierarchy[$brand]['models'])) {
                $models = $hierarchy[$brand]['models'];
                foreach ($models as $mName => $mData) {
                    if (mb_strtolower(trim($mName)) === $trimmedValue) {
                        $this->lastModel = $mName;
                        return $mName;
                    }
                }
                
                // Fallback на "Другая" или "Другой" ВНУТРИ бренда
                foreach ($models as $mName => $mData) {
                    $low = mb_strtolower(trim($mName));
                    if ($low === 'другая' || $low === 'другой') {
                        $this->lastModel = $mName;
                        return $mName;
                    }
                }
            }
            
            // 2. Ищем в глобальном пуле моделей (*)
            if (isset($hierarchy['*']['models'])) {
                foreach ($hierarchy['*']['models'] as $mName => $mData) {
                    if (mb_strtolower(trim($mName)) === $trimmedValue) {
                        $this->lastModel = $mName;
                        return $mName;
                    }
                }
            }
            
            // Если модель не найдена в вайтлисте
            $fallback = 'Другая';
            // Если мы в категории велосипедов, точно ставим "Другая"
            if ($this->isBicycle($this->lastCategory)) {
                $fallback = 'Другая';
            }
            
            Log::debug("Avito Hierarchy: Model '{$value}' (brand '{$brand}') NOT FOUND. Using fallback: {$fallback}");
            
            $this->lastModel = $fallback;
            return $fallback;
        }

        return $value;
    }

    /**
     * Извлечение модели из названия товара
     */
    protected function extractModelName(string $name, string $brand): string
    {
        if (!$name || !$brand) {
            return '';
        }

        // Находим позицию бренда в названии (регистронезависимо)
        $brandIndex = stripos($name, $brand);

        if ($brandIndex === false) {
            // Если бренд не найден в названии, пробуем взять все название
            $model = $name;
        } else {
            // Модель и год - это часть после бренда
            $model = trim(substr($name, $brandIndex + strlen($brand)));
        }

        if (!$model) {
            return '';
        }

        // Удаляем общие префиксы категорий
        $prefixes = ['Велосипед', 'Сноуборд', 'Ботинки', 'Крепления', 'Маска', 'Шлем', 'Защита', 'Лыжи'];
        foreach ($prefixes as $prefix) {
            $model = trim(str_ireplace($prefix, '', $model));
        }

        // Модель - все остальное после удаления года
        $year = $this->extractYearFromText($model);
        if ($year) {
            return trim(str_replace($year, '', $model));
        }

        return $model;
    }

    /**
     * Функция извлечения года из текста
     */
    protected function extractYearFromText(string $text): string
    {
        if (!$text) {
            return '';
        }

        // Ищем 4-значные числа в диапазоне лет
        preg_match_all('/\b(20\d{2}|19\d{2})\b/', $text, $matches);

        if (!empty($matches[0])) {
            return $matches[0][0];
        }

        return '';
    }
    /**
     * Загрузка внешнего списка с кешированием на 24 часа
     */
    private function fetchExternalWhitelist($url)
    {
        if (empty($url)) return null;

        $cacheDir = storage_path('app/avito_cache');
        if (!file_exists($cacheDir)) {
            mkdir($cacheDir, 0777, true);
        }

        $hash = md5($url);
        $cacheFile = $cacheDir . '/' . $hash . '.xml';
        
        // Кеш на 24 часа
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 86400)) {
            return file_get_contents($cacheFile);
        }

        try {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 10,
                    'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36\r\n"
                ]
            ]);
            
            $content = @file_get_contents($url, false, $context);
            
            if ($content) {
                file_put_contents($cacheFile, $content);
                return $content;
            }
        } catch (\Exception $e) {
            Log::error('Avito Feed: Failed to fetch external whitelist from ' . $url . ': ' . $e->getMessage());
        }

        // Если не удалось загрузить, но есть старый кеш - используем его
        if (file_exists($cacheFile)) {
            return file_get_contents($cacheFile);
        }

        return null;
    }
}
