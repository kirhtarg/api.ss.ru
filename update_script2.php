<?php
$file = 'f:\Work\Projects\SS\api.ss.ru\app\Http\Controllers\Admin\ShopGoodsController.php';
$content = file_get_contents($file);

$search3 = <<<'SEARCH3'
        // Ч˜сключение атрибутов вариаций (товары БЕЗ этих атрибутов)
        if ($request->has('exclude_variation_attribute_names')) {
            $excludeAttributeNames = $request->input('exclude_variation_attribute_names');
            // Обрабатываем как массив, так и строку
            if (! is_array($excludeAttributeNames)) {
                $excludeAttributeNames = [$excludeAttributeNames];
            }
            if (! empty($excludeAttributeNames)) {
                // Когда применяется фильтр "БЕЗ атрибутов", показываем ТОЛЬКО товары с вариациями,
                // которые НЕ имеют указанные атрибуты.
                // Чтобы исключить все товары, у которых есть ХОТЯ БЫ ОДНА вариация с ЛЮБЫМ из исключаемых атрибутов:
                $query->whereExists(function ($subQuery) {
                    $subQuery->selectRaw('1')
                        ->from('shop_good_variations')
                        ->whereColumn('good_id', 'shop_goods.id')
                        ->limit(1);
                })->whereNotExists(function ($subQuery) use ($excludeAttributeNames) {
                    $subQuery->selectRaw('1')
                        ->from('shop_good_variations as v')
                        ->whereColumn('v.good_id', 'shop_goods.id')
                        ->where(function ($q) use ($excludeAttributeNames) {
                            foreach ($excludeAttributeNames as $name) {
                                // Используем orWhereExists, чтобы исключить товар, если у него есть хотя бы одна вариация с любым из исключаемых атрибутов
                                $q->orWhereExists(function ($attrSubQuery) use ($name) {
                                    $attrSubQuery->selectRaw('1')
                                        ->from('shop_variation_attributes_values as vav')
                                        ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
                                        ->join('shop_variation_attributes as a', 'a.id', '=', 'av.attribute_id')
                                        ->whereColumn('vav.variation_id', 'v.id')
                                        ->where('a.name', $name);
                                });
                            }
                        });
                });
            }
        }
SEARCH3;

$replace3 = $search3 . "\n\n" . <<<'REPLACE3'
        // Фильтр по габаритам и весу
        if ($request->has('dimensions_weight_filter')) {
            $filterValue = $request->input('dimensions_weight_filter');
            switch ($filterValue) {
                case 'empty_dimensions':
                    $query->where(function($q) {
                        $q->whereNull('width')->orWhere('width', 0)
                          ->orWhereNull('height')->orWhere('height', 0)
                          ->orWhereNull('depth')->orWhere('depth', 0);
                    });
                    break;
                case 'empty_weight':
                    $query->where(function($q) {
                        $q->whereNull('weight')->orWhere('weight', 0);
                    });
                    break;
                case 'empty_dimensions_and_weight':
                    $query->where(function($q) {
                        $q->whereNull('width')->orWhere('width', 0)
                          ->orWhereNull('height')->orWhere('height', 0)
                          ->orWhereNull('depth')->orWhere('depth', 0)
                          ->orWhereNull('weight')->orWhere('weight', 0);
                    });
                    break;
                case 'not_empty_dimensions':
                    $query->whereNotNull('width')->where('width', '>', 0)
                          ->whereNotNull('height')->where('height', '>', 0)
                          ->whereNotNull('depth')->where('depth', '>', 0);
                    break;
                case 'not_empty_weight':
                    $query->whereNotNull('weight')->where('weight', '>', 0);
                    break;
                case 'not_empty_dimensions_and_weight':
                    $query->whereNotNull('width')->where('width', '>', 0)
                          ->whereNotNull('height')->where('height', '>', 0)
                          ->whereNotNull('depth')->where('depth', '>', 0)
                          ->whereNotNull('weight')->where('weight', '>', 0);
                    break;
                case 'all':
                default:
                    // Без фильтра
                    break;
            }
        }

        // Фильтр по изображениям
        if ($request->has('images_filter')) {
            $imagesFilter = $request->input('images_filter');
            switch ($imagesFilter) {
                case 'with_main_images':
                    $query->has('images');
                    break;
                case 'with_variation_images':
                    $query->whereHas('variations', function($q) {
                        $q->has('images');
                    });
                    break;
                case 'without_main_images':
                    $query->doesntHave('images');
                    break;
                case 'without_variation_images':
                    $query->whereHas('variations', function($q) {
                        $q->doesntHave('images');
                    });
                    break;
                case 'all':
                default:
                    break;
            }
        }
REPLACE3;

$content = str_replace($search3, $replace3, $content);
file_put_contents($file, $content);
echo "Added new filters successfully!";
