<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class SearchController extends Controller
{
    public function search(Request $request)
    {
        $query = $request->get('q', '');
        $limit = min($request->get('limit', 10), 50); // Максимум 50 результатов


        if (strlen($query) < 2) {
            return response()->json([
                'data' => [
                    'products' => [],
                    'categories' => [],
                    'brands' => []
                ]
            ]);
        }

        // Получаем настройки для включения в ключ кеша
        $shopShowGoodMode = Setting::where('key', 'shop_show_good_mode')->first();
        $showGoodMode = $shopShowGoodMode ? (int)$shopShowGoodMode->value : 2;
        $shopRemoteQ = Setting::where('key', 'shop_remote_q')->first();
        $remoteQ = $shopRemoteQ ? (int)$shopRemoteQ->value : 1;
        
        // ВРЕМЕННО: Отключаем кеш для отладки
        // Кэшируем результаты на 5 минут (ключ включает настройки фильтрации)
        // $cacheKey = 'search_' . md5($query . '_' . $limit . '_' . $showGoodMode . '_' . $remoteQ);
        
        // $results = Cache::remember($cacheKey, 300, function () use ($query, $limit) {
            $products = $this->searchProducts($query, $limit);
            $categories = $this->searchCategories($query, $limit);
            $brands = $this->searchBrands($query, $limit);
            
            // Получаем общее количество результатов для каждого типа
            $totalProducts = $this->getTotalProductsCount($query);
            $totalCategories = $this->getTotalCategoriesCount($query);
            $totalBrands = $this->getTotalBrandsCount($query);
            
            $results = [
                'products' => $products,
                'categories' => $categories,
                'brands' => $brands,
                'total' => [
                    'products' => $totalProducts,
                    'categories' => $totalCategories,
                    'brands' => $totalBrands
                ]
            ];
        // });

        return response()->json(['data' => $results]);
    }

    private function searchProducts($query, $limit)
    {
        try {
            // Экранируем запрос для безопасности
            $escapedQuery = DB::getPdo()->quote($query);
            $escapedQuery = trim($escapedQuery, "'");
            
            // Разбиваем запрос на слова для полнотекстового поиска
            // Используем оригинальный запрос для разбивки, чтобы сохранить регистр
            $words = preg_split('/\s+/', $query);
            $words = array_filter($words, function($word) {
                $trimmed = trim($word);
                return mb_strlen($trimmed) >= 2; // Минимум 2 символа для слова
            });
            $words = array_map('trim', $words); // Убираем пробелы
            $words = array_values($words);
            
            // Экранируем слова для использования в LIKE запросах
            // Преобразуем к нижнему регистру для case-insensitive поиска
            $escapedWords = array_map(function($word) {
                $quoted = DB::getPdo()->quote(mb_strtolower($word));
                return trim($quoted, "'");
            }, $words);
            
            // Также преобразуем полный запрос к нижнему регистру
            $escapedQueryLower = mb_strtolower($escapedQuery);
            
            // Проверяем, есть ли FULLTEXT индекс (если нет, используем LIKE как fallback)
            $hasFulltextIndex = $this->checkFulltextIndex('shop_goods', ['name', 'description', 'short_description']);
            
            $productsQuery = DB::table('shop_goods')
                ->where('is_active', true);
            
            // Используем экранированные слова для LIKE запросов
            $wordsForLike = isset($escapedWords) && count($escapedWords) > 0 ? $escapedWords : $words;
            
            // Подготавливаем FULLTEXT запрос для релевантности (если есть индекс)
            $useFulltext = false;
            $fulltextQuery = '';
            
            if ($hasFulltextIndex && count($words) > 0) {
                $fulltextWords = array_map(function($word) {
                    $cleanWord = mb_strtolower($word);
                    $cleanWord = preg_replace('/[+\-><()~*"@]/', '', $cleanWord);
                    $cleanWord = trim($cleanWord);
                    if (mb_strlen($cleanWord) >= 2) {
                        return '+' . $cleanWord;
                    }
                    return '';
                }, $words);
                $fulltextWords = array_filter($fulltextWords);
                
                if (count($fulltextWords) > 0) {
                    $fulltextQuery = implode(' ', $fulltextWords);
                    $useFulltext = true;
                }
            }
            
            // Условия поиска
            if (count($wordsForLike) > 0) {
                // Требуем вхождения всех слов из запроса (AND логика)
                $productsQuery->where(function ($q) use ($wordsForLike, $escapedQueryLower) {
                    // Все слова должны быть найдены в текстовых полях (AND между словами)
                    $q->where(function($allWordsQuery) use ($wordsForLike) {
                        foreach ($wordsForLike as $word) {
                            $allWordsQuery->where(function($wordQuery) use ($word) {
                                $wordQuery->whereRaw('LOWER(name) LIKE ?', ["%{$word}%"])
                                          ->orWhereRaw('LOWER(description) LIKE ?', ["%{$word}%"])
                                          ->orWhereRaw('LOWER(short_description) LIKE ?', ["%{$word}%"]);
                            });
                        }
                    })
                    // ИЛИ артикул совпадает
                    ->orWhereRaw('LOWER(sku) LIKE ?', ["%{$escapedQueryLower}%"])
                    ->orWhereExists(function ($subQuery) use ($escapedQueryLower) {
                        $subQuery->select(DB::raw(1))
                                 ->from('shop_good_variations')
                                 ->whereColumn('shop_good_variations.good_id', 'shop_goods.id')
                                 ->where('shop_good_variations.is_active', true)
                                 ->whereRaw('LOWER(shop_good_variations.sku) LIKE ?', ["%{$escapedQueryLower}%"]);
                    });
                });
            } else {
                // Если нет слов, используем простой поиск
                $productsQuery->where(function ($q) use ($escapedQueryLower) {
                    $q->whereRaw('LOWER(name) LIKE ?', ["%{$escapedQueryLower}%"])
                      ->orWhereRaw('LOWER(description) LIKE ?', ["%{$escapedQueryLower}%"])
                      ->orWhereRaw('LOWER(short_description) LIKE ?', ["%{$escapedQueryLower}%"])
                      ->orWhereRaw('LOWER(sku) LIKE ?', ["%{$escapedQueryLower}%"])
                      ->orWhereExists(function ($subQuery) use ($escapedQueryLower) {
                          $subQuery->select(DB::raw(1))
                                   ->from('shop_good_variations')
                                   ->whereColumn('shop_good_variations.good_id', 'shop_goods.id')
                                   ->where('shop_good_variations.is_active', true)
                                   ->whereRaw('LOWER(shop_good_variations.sku) LIKE ?', ["%{$escapedQueryLower}%"]);
                      });
                });
            }
            
            // Применяем фильтрацию по остаткам (используем подзапросы, БЕЗ JOIN и GROUP BY)
            // ВАЖНО: применяем ПЕРЕД selectRaw, чтобы фильтр применялся к базовому запросу
            $this->applyStockFilter($productsQuery);
            
            // Добавляем релевантность для сортировки
            if ($useFulltext) {
                $productsQuery->selectRaw('shop_goods.*,
                    (CASE 
                        WHEN LOWER(sku) = ? THEN 100
                        WHEN LOWER(sku) LIKE ? THEN 80
                        WHEN LOWER(name) = ? THEN 90
                        WHEN LOWER(name) LIKE ? THEN 70
                        WHEN MATCH(name, description, short_description) AGAINST(? IN BOOLEAN MODE) > 0 THEN 60
                        WHEN LOWER(description) LIKE ? THEN 50
                        WHEN LOWER(short_description) LIKE ? THEN 40
                        ELSE 10
                    END) as relevance', [
                    $escapedQueryLower,
                    "{$escapedQueryLower}%",
                    $escapedQueryLower,
                    "{$escapedQueryLower}%",
                    $fulltextQuery,
                    "%{$escapedQueryLower}%",
                    "%{$escapedQueryLower}%"
                ]);
            } else {
                $productsQuery->selectRaw('shop_goods.*,
                    (CASE 
                        WHEN LOWER(sku) = ? THEN 100
                        WHEN LOWER(sku) LIKE ? THEN 80
                        WHEN LOWER(name) = ? THEN 90
                        WHEN LOWER(name) LIKE ? THEN 70
                        WHEN LOWER(description) LIKE ? THEN 50
                        WHEN LOWER(short_description) LIKE ? THEN 40
                        ELSE 10
                    END) as relevance', [
                    $escapedQueryLower,
                    "{$escapedQueryLower}%",
                    $escapedQueryLower,
                    "{$escapedQueryLower}%",
                    "%{$escapedQueryLower}%",
                    "%{$escapedQueryLower}%"
                ]);
            }
            
            // Сортируем по релевантности (убывание), затем по названию
            $products = $productsQuery
                ->orderBy('relevance', 'desc')
                ->orderBy('name', 'asc')
                ->limit($limit)
                ->get();
            
            // Логируем для отладки
            \Log::info('=== SEARCH DEBUG ===');
            \Log::info('Search query: ' . $query);
            \Log::info('Search results count: ' . $products->count());
            if ($products->count() > 0) {
                foreach ($products->take(5) as $product) {
                    \Log::info('Product: id=' . $product->id . ', name=' . substr($product->name, 0, 50) . ', stock=' . ($product->stock_quantity ?? 'N/A') . ', remote_stock=' . ($product->remote_stock_quantity ?? 'N/A'));
                }
            }
            \Log::info('=== END SEARCH DEBUG ===');
            
            $products = $products->map(function ($product) use ($escapedQuery) {
                    // Получаем первое изображение для каждого товара
                    $firstImage = DB::table('shop_good_images')
                        ->where('good_id', $product->id)
                        ->orderBy('id')
                        ->first();
                    
                    // Проверяем наличие вариаций у товара
                    $hasVariations = DB::table('shop_good_variations')
                        ->where('good_id', $product->id)
                        ->where('is_active', true)
                        ->exists();
                    
                    // Проверяем, был ли поиск по артикулу вариации
                    $foundVariationId = null;
                    if ($product->sku !== $escapedQuery) {
                        // Если поиск не по основному артикулу, ищем в вариациях
                        $variation = DB::table('shop_good_variations')
                            ->where('good_id', $product->id)
                            ->where('is_active', true)
                            ->where('sku', 'LIKE', "%{$escapedQuery}%")
                            ->first();
                        
                        if ($variation) {
                            $foundVariationId = $variation->id;
                        }
                    }
                    
                    return [
                        'id' => $product->id,
                        'name' => $product->name,
                        'price' => $product->sale_price ? $product->sale_price : $product->price,
                        'original_price' => $product->sale_price ? $product->price : null,
                        'sku' => $product->sku,
                        'image' => $firstImage ? $this->getImageUrl($firstImage->file_path) : null,
                        'slug' => $product->slug,
                        'description' => $product->description,
                        'found_variation_id' => $foundVariationId,
                        'has_variations' => $hasVariations,
                        'relevance' => $product->relevance ?? 0
                    ];
                });

            return $products->toArray();
        } catch (\Exception $e) {
            // Логируем ошибку для отладки
            \Log::error('Search error: ' . $e->getMessage());
            \Log::error('Search trace: ' . $e->getTraceAsString());
            
            // Fallback на простой LIKE поиск при ошибке
            try {
                return $this->searchProductsFallback($query, $limit);
            } catch (\Exception $fallbackError) {
                \Log::error('Fallback search error: ' . $fallbackError->getMessage());
            return [];
            }
        }
    }
    
    /**
     * Проверка наличия FULLTEXT индекса
     * Проверяет наличие любого FULLTEXT индекса, который включает все указанные колонки
     */
    private function checkFulltextIndex($table, $columns)
    {
        try {
            // Получаем все FULLTEXT индексы для таблицы
            $indexes = DB::select("
                SELECT DISTINCT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) as cols
                FROM information_schema.statistics
                WHERE table_schema = DATABASE()
                AND table_name = ?
                AND INDEX_TYPE = 'FULLTEXT'
                GROUP BY INDEX_NAME
            ", [$table]);
            
            // Проверяем, есть ли индекс, который включает все нужные колонки
            foreach ($indexes as $index) {
                $indexColumns = explode(',', $index->cols);
                $indexColumns = array_map('trim', $indexColumns);
                
                // Проверяем, что все нужные колонки присутствуют в индексе
                $allColumnsPresent = true;
                foreach ($columns as $column) {
                    if (!in_array($column, $indexColumns)) {
                        $allColumnsPresent = false;
                        break;
                    }
                }
                
                if ($allColumnsPresent) {
                    return true;
                }
            }
            
            return false;
        } catch (\Exception $e) {
            // Если ошибка, считаем что индекса нет
            return false;
        }
    }
    
    /**
     * Fallback поиск при ошибке полнотекстового поиска
     */
    private function searchProductsFallback($query, $limit)
    {
        $escapedQuery = DB::getPdo()->quote($query);
        $escapedQuery = trim($escapedQuery, "'");
        $escapedQueryLower = mb_strtolower($escapedQuery);
        
        // Разбиваем запрос на слова
        $words = preg_split('/\s+/', $query);
        $words = array_filter($words, function($word) {
            return mb_strlen(trim($word)) >= 2;
        });
        $words = array_map('trim', $words);
        $words = array_values($words);
        
        // Экранируем слова для LIKE запросов (case-insensitive)
        $escapedWords = array_map(function($word) {
            $quoted = DB::getPdo()->quote(mb_strtolower($word));
            return trim($quoted, "'");
        }, $words);
        
        $productsQuery = DB::table('shop_goods')
            ->where('is_active', true);
        
        // Требуем вхождения всех слов из запроса (case-insensitive)
        if (count($escapedWords) > 0) {
            $productsQuery->where(function ($q) use ($escapedWords, $escapedQueryLower) {
                // Для текстовых полей требуем вхождения всех слов (AND, case-insensitive)
                foreach ($escapedWords as $word) {
                    $q->where(function($wordQuery) use ($word) {
                        $wordQuery->whereRaw('LOWER(name) LIKE ?', ["%{$word}%"])
                                  ->orWhereRaw('LOWER(description) LIKE ?', ["%{$word}%"])
                                  ->orWhereRaw('LOWER(short_description) LIKE ?', ["%{$word}%"]);
                    });
                }
                
                // Артикул ищется по полному запросу (OR, case-insensitive)
                $q->orWhereRaw('LOWER(sku) LIKE ?', ["%{$escapedQueryLower}%"])
                  ->orWhereExists(function ($subQuery) use ($escapedQueryLower) {
                      $subQuery->select(DB::raw(1))
                               ->from('shop_good_variations')
                               ->whereColumn('shop_good_variations.good_id', 'shop_goods.id')
                               ->where('shop_good_variations.is_active', true)
                               ->whereRaw('LOWER(shop_good_variations.sku) LIKE ?', ["%{$escapedQueryLower}%"]);
                  });
            });
        } else {
            $productsQuery->where(function ($q) use ($escapedQueryLower) {
                $q->whereRaw('LOWER(name) LIKE ?', ["%{$escapedQueryLower}%"])
                  ->orWhereRaw('LOWER(description) LIKE ?', ["%{$escapedQueryLower}%"])
                  ->orWhereRaw('LOWER(short_description) LIKE ?', ["%{$escapedQueryLower}%"])
                  ->orWhereRaw('LOWER(sku) LIKE ?', ["%{$escapedQueryLower}%"])
                  ->orWhereExists(function ($subQuery) use ($escapedQueryLower) {
                      $subQuery->select(DB::raw(1))
                               ->from('shop_good_variations')
                               ->whereColumn('shop_good_variations.good_id', 'shop_goods.id')
                               ->where('shop_good_variations.is_active', true)
                               ->whereRaw('LOWER(shop_good_variations.sku) LIKE ?', ["%{$escapedQueryLower}%"]);
                  });
            });
        }
        
        // Применяем фильтрацию по остаткам (учитывает параметры сайта) - ПОСЛЕ условий поиска
        $this->applyStockFilter($productsQuery);
        
        $products = $productsQuery
            ->selectRaw('shop_goods.*, 
                (CASE 
                    WHEN LOWER(sku) = ? THEN 100
                    WHEN LOWER(sku) LIKE ? THEN 80
                    WHEN LOWER(name) = ? THEN 90
                    WHEN LOWER(name) LIKE ? THEN 70
                    ELSE 10
                END) as relevance', [
                $escapedQueryLower,
                "{$escapedQueryLower}%",
                $escapedQueryLower,
                "{$escapedQueryLower}%"
            ])
            ->orderBy('relevance', 'desc')
            ->orderBy('name', 'asc')
            ->limit($limit)
            ->get()
            ->map(function ($product) use ($escapedQueryLower) {
                $firstImage = DB::table('shop_good_images')
                    ->where('good_id', $product->id)
                    ->orderBy('id')
                    ->first();
                
                // Проверяем наличие вариаций у товара
                $hasVariations = DB::table('shop_good_variations')
                    ->where('good_id', $product->id)
                    ->where('is_active', true)
                    ->exists();
                
                $foundVariationId = null;
                if (mb_strtolower($product->sku) !== $escapedQueryLower) {
                    $variation = DB::table('shop_good_variations')
                        ->where('good_id', $product->id)
                        ->where('is_active', true)
                        ->whereRaw('LOWER(sku) LIKE ?', ["%{$escapedQueryLower}%"])
                        ->first();
                    
                    if ($variation) {
                        $foundVariationId = $variation->id;
                    }
                }
                
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'price' => $product->sale_price ? $product->sale_price : $product->price,
                    'original_price' => $product->sale_price ? $product->price : null,
                    'sku' => $product->sku,
                    'image' => $firstImage ? $this->getImageUrl($firstImage->file_path) : null,
                    'slug' => $product->slug,
                    'description' => $product->description,
                    'found_variation_id' => $foundVariationId,
                    'has_variations' => $hasVariations,
                    'relevance' => $product->relevance ?? 0
                ];
            });
        
        return $products->toArray();
    }

    private function searchCategories($query, $limit)
    {
        try {
            $escapedQuery = DB::getPdo()->quote($query);
            $escapedQuery = trim($escapedQuery, "'");
            $escapedQueryLower = mb_strtolower($escapedQuery);
            
            // Разбиваем оригинальный запрос на слова
            $words = preg_split('/\s+/', $query);
            $words = array_filter($words, function($word) {
                return mb_strlen(trim($word)) >= 2;
            });
            $words = array_map('trim', $words);
            $words = array_values($words);
            
            // Экранируем слова для LIKE запросов (case-insensitive)
            $escapedWords = array_map(function($word) {
                $quoted = DB::getPdo()->quote(mb_strtolower($word));
                return trim($quoted, "'");
            }, $words);
            
            $hasFulltextIndex = $this->checkFulltextIndex('shop_categories', ['name', 'description']);
            
            $categoriesQuery = DB::table('shop_categories')
                ->where('is_active', true);
            
            if ($hasFulltextIndex && count($words) > 0) {
                // Добавляем + перед каждым словом для обязательного вхождения (case-insensitive)
                $fulltextWords = array_map(function($word) {
                    $cleanWord = mb_strtolower($word);
                    $cleanWord = preg_replace('/[+\-><()~*"@]/', '', $cleanWord);
                    $cleanWord = trim($cleanWord);
                    if (mb_strlen($cleanWord) >= 2) {
                        return '+' . $cleanWord;
                    }
                    return '';
                }, $words);
                $fulltextWords = array_filter($fulltextWords);
                
                if (count($fulltextWords) > 0) {
                    $fulltextQuery = implode(' ', $fulltextWords);
                    
                    $categoriesQuery->whereRaw("MATCH(name, description) AGAINST(? IN BOOLEAN MODE)", [$fulltextQuery]);
                    
                    $categoriesQuery->selectRaw('shop_categories.*, 
                        (CASE 
                            WHEN LOWER(name) = ? THEN 90
                            WHEN LOWER(name) LIKE ? THEN 70
                            ELSE MATCH(name, description) AGAINST(? IN BOOLEAN MODE) * 10
                        END) as relevance', [
                        $escapedQueryLower,
                        "{$escapedQueryLower}%",
                        $fulltextQuery
                    ]);
                } else {
                    $hasFulltextIndex = false;
                }
            }
            
            if (!$hasFulltextIndex || count($words) == 0) {
                // Требуем вхождения всех слов из запроса (case-insensitive)
                if (count($escapedWords) > 0) {
                    $categoriesQuery->where(function ($q) use ($escapedWords) {
                        foreach ($escapedWords as $word) {
                            $q->where(function($wordQuery) use ($word) {
                                $wordQuery->whereRaw('LOWER(name) LIKE ?', ["%{$word}%"])
                                          ->orWhereRaw('LOWER(description) LIKE ?', ["%{$word}%"]);
                            });
                        }
                    });
                } else {
                    $categoriesQuery->where(function ($q) use ($escapedQueryLower) {
                        $q->whereRaw('LOWER(name) LIKE ?', ["%{$escapedQueryLower}%"])
                          ->orWhereRaw('LOWER(description) LIKE ?', ["%{$escapedQueryLower}%"]);
                    });
                }
                
                $categoriesQuery->selectRaw('shop_categories.*, 
                    (CASE 
                        WHEN LOWER(name) = ? THEN 90
                        WHEN LOWER(name) LIKE ? THEN 70
                        WHEN LOWER(description) LIKE ? THEN 50
                        ELSE 10
                    END) as relevance', [
                    $escapedQueryLower,
                    "{$escapedQueryLower}%",
                    "%{$escapedQueryLower}%"
                ]);
            }
            
            $categories = $categoriesQuery
                ->orderBy('relevance', 'desc')
                ->orderBy('name', 'asc')
                ->limit($limit)
                ->get()
                ->map(function ($category) {
                    return [
                        'id' => $category->id,
                        'name' => $category->name,
                        'description' => $category->description,
                        'image' => $category->image ? $this->getImageUrl($category->image) : null,
                        'slug' => $category->slug,
                        'relevance' => $category->relevance ?? 0
                    ];
                });

            return $categories->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    private function searchBrands($query, $limit)
    {
        try {
            $escapedQuery = DB::getPdo()->quote($query);
            $escapedQuery = trim($escapedQuery, "'");
            $escapedQueryLower = mb_strtolower($escapedQuery);
            
            // Разбиваем оригинальный запрос на слова
            $words = preg_split('/\s+/', $query);
            $words = array_filter($words, function($word) {
                return mb_strlen(trim($word)) >= 2;
            });
            $words = array_map('trim', $words);
            $words = array_values($words);
            
            // Экранируем слова для LIKE запросов (case-insensitive)
            $escapedWords = array_map(function($word) {
                $quoted = DB::getPdo()->quote(mb_strtolower($word));
                return trim($quoted, "'");
            }, $words);
            
            $hasFulltextIndex = $this->checkFulltextIndex('shop_brands', ['name', 'description']);
            
            $brandsQuery = DB::table('shop_brands')
                ->where('is_active', true);
            
            if ($hasFulltextIndex && count($words) > 0) {
                // Добавляем + перед каждым словом для обязательного вхождения (case-insensitive)
                $fulltextWords = array_map(function($word) {
                    $cleanWord = mb_strtolower($word);
                    $cleanWord = preg_replace('/[+\-><()~*"@]/', '', $cleanWord);
                    $cleanWord = trim($cleanWord);
                    if (mb_strlen($cleanWord) >= 2) {
                        return '+' . $cleanWord;
                    }
                    return '';
                }, $words);
                $fulltextWords = array_filter($fulltextWords);
                
                if (count($fulltextWords) > 0) {
                    $fulltextQuery = implode(' ', $fulltextWords);
                    
                    $brandsQuery->whereRaw("MATCH(name, description) AGAINST(? IN BOOLEAN MODE)", [$fulltextQuery]);
                    
                    $brandsQuery->selectRaw('shop_brands.*, 
                        (CASE 
                            WHEN LOWER(name) = ? THEN 90
                            WHEN LOWER(name) LIKE ? THEN 70
                            ELSE MATCH(name, description) AGAINST(? IN BOOLEAN MODE) * 10
                        END) as relevance', [
                        $escapedQueryLower,
                        "{$escapedQueryLower}%",
                        $fulltextQuery
                    ]);
                } else {
                    $hasFulltextIndex = false;
                }
            }
            
            if (!$hasFulltextIndex || count($words) == 0) {
                // Требуем вхождения всех слов из запроса (case-insensitive)
                if (count($escapedWords) > 0) {
                    $brandsQuery->where(function ($q) use ($escapedWords) {
                        foreach ($escapedWords as $word) {
                            $q->where(function($wordQuery) use ($word) {
                                $wordQuery->whereRaw('LOWER(name) LIKE ?', ["%{$word}%"])
                                          ->orWhereRaw('LOWER(description) LIKE ?', ["%{$word}%"]);
                            });
                        }
                    });
                } else {
                    $brandsQuery->where(function ($q) use ($escapedQueryLower) {
                        $q->whereRaw('LOWER(name) LIKE ?', ["%{$escapedQueryLower}%"])
                          ->orWhereRaw('LOWER(description) LIKE ?', ["%{$escapedQueryLower}%"]);
                    });
                }
                
                $brandsQuery->selectRaw('shop_brands.*, 
                    (CASE 
                        WHEN LOWER(name) = ? THEN 90
                        WHEN LOWER(name) LIKE ? THEN 70
                        WHEN LOWER(description) LIKE ? THEN 50
                        ELSE 10
                    END) as relevance', [
                    $escapedQueryLower,
                    "{$escapedQueryLower}%",
                    "%{$escapedQueryLower}%"
                ]);
            }
            
            $brands = $brandsQuery
                ->orderBy('relevance', 'desc')
                ->orderBy('name', 'asc')
                ->limit($limit)
                ->get()
                ->map(function ($brand) {
                    return [
                        'id' => $brand->id,
                        'name' => $brand->name,
                        'description' => $brand->description,
                        'logo' => $brand->logo ? $this->getImageUrl($brand->logo) : null,
                        'slug' => $brand->slug,
                        'relevance' => $brand->relevance ?? 0
                    ];
                });

            return $brands->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Получить полный URL изображения
     */
    private function getImageUrl($filePath)
    {
        if (!$filePath) {
            return null;
        }

        // Если это уже полный URL, возвращаем как есть
        if (str_starts_with($filePath, 'http')) {
            return $filePath;
        }

        // Убираем лишний префикс images/ если он уже есть
        $cleanPath = ltrim($filePath, '/');
        if (str_starts_with($cleanPath, 'images/')) {
            return '/' . $cleanPath;
        }

        // Возвращаем путь к файлу в папке public/images/
        return '/images/' . $cleanPath;
    }

    /**
     * Применить фильтрацию по остаткам к запросу товаров (адаптировано для Query Builder)
     */
    private function applyStockFilter($query) {
        $shopShowGoodMode = Setting::where('key', 'shop_show_good_mode')->first();
        $showGoodMode = $shopShowGoodMode ? (int)$shopShowGoodMode->value : 2;

        $shopRemoteQ = Setting::where('key', 'shop_remote_q')->first();
        $remoteQ = $shopRemoteQ ? (int)$shopRemoteQ->value : 1;

        $shopShowQuantity = Setting::where('key', 'shop_show_quantity')->first();
        $showQuantity = $shopShowQuantity ? (int)$shopShowQuantity->value : 1;

        $hidden0Price = Setting::where('key', 'hidden_0_price')->first();
        $hide0Price = $hidden0Price ? (int)$hidden0Price->value : 0;

        // Фильтр 1: shop_show_good_mode === 1 - не показывать товары с остатком = 0
        if ($showGoodMode === 1) {
            // Используем подзапросы для проверки остатков (БЕЗ JOIN и GROUP BY)
            // Логика: показываем товар, если у него есть остаток (локальный ИЛИ удаленный) ИЛИ есть вариации с остатком
            // ВАЖНО: используем те же имена полей, что и в каталоге (без префикса shop_goods)
            $query->where(function($mainQuery) use ($remoteQ) {
                // Условие 1: остаток на локальном складе товара > 0
                $mainQuery->where('stock_quantity', '>', 0);

                if ($remoteQ === 2 || $remoteQ === 3) {
                    // Условие 2: остаток на удаленном складе товара (не null, не пустая строка, не "0")
                    $mainQuery->orWhere(function($remoteCondition) {
                        $remoteCondition->whereNotNull('remote_stock_quantity')
                            ->where('remote_stock_quantity', '!=', '0')
                            ->where('remote_stock_quantity', '!=', '')
                            ->whereRaw('LENGTH(TRIM(remote_stock_quantity)) > 0');
                    });
                }

                // Условие 3: есть вариации с остатком (подзапрос)
                // ВАЖНО: не проверяем is_active для вариаций, так как неактивные товары уже отфильтрованы основным запросом
                $mainQuery->orWhereExists(function($varQ) use ($remoteQ) {
                    $varQ->select(DB::raw(1))
                         ->from('shop_good_variations')
                         ->whereColumn('shop_good_variations.good_id', 'shop_goods.id')
                         ->where(function($subVarQ) use ($remoteQ) {
                             // Вариация с остатком на локальном складе
                             $subVarQ->where('shop_good_variations.stock_quantity', '>', 0);

                             if ($remoteQ === 2 || $remoteQ === 3) {
                                 // ИЛИ вариация с остатком на удаленном складе
                                 $subVarQ->orWhere(function($remoteVarQ) {
                                     $remoteVarQ->whereNotNull('shop_good_variations.remote_stock_quantity')
                                         ->where('shop_good_variations.remote_stock_quantity', '!=', '0')
                                         ->where('shop_good_variations.remote_stock_quantity', '!=', '')
                                         ->whereRaw('LENGTH(TRIM(shop_good_variations.remote_stock_quantity)) > 0');
                                 });
                             }
                         });
                });
            });
            
            // Логируем для отладки
            \Log::info('Stock filter applied: shop_show_good_mode=1, remoteQ=' . $remoteQ);
        }

        // Фильтр для режима 4: показывать товары с остатком > 0 ИЛИ товары с остатком = 0 и is_preorder = 1
        if ($showGoodMode === 4) {
            $query->where(function($mainQuery) use ($remoteQ) {
                // Условие 1: остаток на локальном складе товара > 0
                $mainQuery->where('stock_quantity', '>', 0);

                if ($remoteQ === 2 || $remoteQ === 3) {
                    // Условие 2: остаток на удаленном складе товара (не null, не пустая строка, не "0")
                    $mainQuery->orWhere(function($remoteCondition) {
                        $remoteCondition->whereNotNull('remote_stock_quantity')
                            ->where('remote_stock_quantity', '!=', '0')
                            ->where('remote_stock_quantity', '!=', '')
                            ->whereRaw('LENGTH(TRIM(remote_stock_quantity)) > 0');
                    });
                }

                // Условие 3: товар с остатком = 0, но is_preorder = 1
                $mainQuery->orWhere(function($preorderCondition) {
                    $preorderCondition->where('stock_quantity', '<=', 0)
                        ->where(function($preorderSubCondition) {
                            $preorderSubCondition->where('is_preorder', '=', 1)
                                ->orWhere('is_preorder', '=', true);
                        });
                });

                // Условие 4: есть вариации с остатком (подзапрос)
                $mainQuery->orWhereExists(function($varQ) use ($remoteQ) {
                    $varQ->select(DB::raw(1))
                         ->from('shop_good_variations')
                         ->whereColumn('shop_good_variations.good_id', 'shop_goods.id')
                         ->where(function($subVarQ) use ($remoteQ) {
                             // Вариация с остатком на локальном складе
                             $subVarQ->where('shop_good_variations.stock_quantity', '>', 0);

                             if ($remoteQ === 2 || $remoteQ === 3) {
                                 // ИЛИ вариация с остатком на удаленном складе
                                 $subVarQ->orWhere(function($remoteVarQ) {
                                     $remoteVarQ->whereNotNull('shop_good_variations.remote_stock_quantity')
                                         ->where('shop_good_variations.remote_stock_quantity', '!=', '0')
                                         ->where('shop_good_variations.remote_stock_quantity', '!=', '')
                                         ->whereRaw('LENGTH(TRIM(shop_good_variations.remote_stock_quantity)) > 0');
                                 });
                             }
                         });
                });

                // Условие 5: все вариации без остатка, но is_preorder = 1 у товара
                // Показываем товар, если у него is_preorder = 1 и нет вариаций с остатком
                $mainQuery->orWhere(function($preorderVarCondition) use ($remoteQ) {
                    $preorderVarCondition->where(function($preorderCheck) {
                        $preorderCheck->where('is_preorder', '=', 1)
                            ->orWhere('is_preorder', '=', true);
                    })
                    ->where(function($noStockVarCondition) use ($remoteQ) {
                        // Проверяем, что нет вариаций с остатком
                        $noStockVarCondition->whereNotExists(function($varQ) use ($remoteQ) {
                            $varQ->select(DB::raw(1))
                                 ->from('shop_good_variations')
                                 ->whereColumn('shop_good_variations.good_id', 'shop_goods.id')
                                 ->where(function($subVarQ) use ($remoteQ) {
                                     $subVarQ->where('shop_good_variations.stock_quantity', '>', 0);
                                     if ($remoteQ === 2 || $remoteQ === 3) {
                                         $subVarQ->orWhere(function($remoteVarQ) {
                                             $remoteVarQ->whereNotNull('shop_good_variations.remote_stock_quantity')
                                                 ->where('shop_good_variations.remote_stock_quantity', '!=', '0')
                                                 ->where('shop_good_variations.remote_stock_quantity', '!=', '')
                                                 ->whereRaw('LENGTH(TRIM(shop_good_variations.remote_stock_quantity)) > 0');
                                         });
                                     }
                                 });
                        });
                    });
                });
            });
            
            // Логируем для отладки
            \Log::info('Stock filter applied: shop_show_good_mode=4 (preorder mode), remoteQ=' . $remoteQ);
        }

        // Фильтр 2: shop_show_quantity === 3 - не показывать товары с пустым или 0 остатком на удаленном складе
        // Применяется только если shop_show_good_mode !== 1 и !== 4 (чтобы не конфликтовать с основным фильтром)
        if ($showQuantity === 3 && $showGoodMode !== 1 && $showGoodMode !== 4) {
            $query->where(function($q) use ($remoteQ) {
                // Показываем товары, у которых:
                // 1. Есть остаток на локальном складе
                $q->where('stock_quantity', '>', 0)
                  // 2. ИЛИ есть остаток на удаленном складе (не null, не пустая строка, не "0")
                  ->orWhere(function($remoteCondition) {
                      $remoteCondition->whereNotNull('remote_stock_quantity')
                          ->where('remote_stock_quantity', '!=', '0')
                          ->whereRaw('LENGTH(TRIM(remote_stock_quantity)) > 0');
                  })
                  // 3. ИЛИ есть вариации с остатком
                  ->orWhereExists(function($varQ) use ($remoteQ) {
                      $varQ->select(DB::raw(1))
                           ->from('shop_good_variations')
                           ->whereColumn('shop_good_variations.good_id', 'shop_goods.id')
                           ->where(function($subVarQ) use ($remoteQ) {
                               $subVarQ->where('shop_good_variations.stock_quantity', '>', 0);
                               
                               if ($remoteQ === 2 || $remoteQ === 3) {
                                   $subVarQ->orWhere(function($remoteVarQ) {
                                       $remoteVarQ->whereNotNull('shop_good_variations.remote_stock_quantity')
                                           ->where('shop_good_variations.remote_stock_quantity', '!=', '0')
                                           ->whereRaw('LENGTH(TRIM(shop_good_variations.remote_stock_quantity)) > 0');
                                   });
                               }
                           });
                  });
            });
        }

        // Фильтр 3: hidden_0_price === 1 - не показывать товары с ценой = 0
        if ($hide0Price === 1) {
            $query->where(function($q) {
                // Показываем товары, у которых цена > 0 (проверяем и price, и sale_price)
                $q->where(function($priceQ) {
                    $priceQ->where('price', '>', 0)
                           ->orWhere('sale_price', '>', 0);
                })
                // ИЛИ есть вариации с ценой > 0
                ->orWhereExists(function($varQ) {
                    $varQ->select(DB::raw(1))
                         ->from('shop_good_variations')
                         ->whereColumn('shop_good_variations.good_id', 'shop_goods.id')
                         ->where('shop_good_variations.is_active', true)
                         ->where(function($varPriceQ) {
                             $varPriceQ->where('shop_good_variations.price', '>', 0)
                                       ->orWhere('shop_good_variations.sale_price', '>', 0);
                         });
                });
            });
        }
    }

    /**
     * Получить общее количество товаров по запросу
     */
    private function getTotalProductsCount($query)
    {
        try {
            $escapedQuery = DB::getPdo()->quote($query);
            $escapedQuery = trim($escapedQuery, "'");
            
            // Разбиваем оригинальный запрос на слова
            $words = preg_split('/\s+/', $query);
            $words = array_filter($words, function($word) {
                return mb_strlen(trim($word)) >= 2;
            });
            $words = array_map('trim', $words);
            $words = array_values($words);
            
            // Экранируем слова для LIKE запросов (case-insensitive)
            $escapedWords = array_map(function($word) {
                $quoted = DB::getPdo()->quote(mb_strtolower($word));
                return trim($quoted, "'");
            }, $words);
            
            $escapedQueryLower = mb_strtolower($escapedQuery);
            
            $hasFulltextIndex = $this->checkFulltextIndex('shop_goods', ['name', 'description', 'short_description']);
            
            $countQuery = DB::table('shop_goods')
                ->where('is_active', true);
            
            $this->applyStockFilter($countQuery);
            
            if ($hasFulltextIndex && count($words) > 0) {
                // Добавляем + перед каждым словом для обязательного вхождения (case-insensitive)
                $fulltextWords = array_map(function($word) {
                    $cleanWord = mb_strtolower($word);
                    $cleanWord = preg_replace('/[+\-><()~*"@]/', '', $cleanWord);
                    $cleanWord = trim($cleanWord);
                    if (mb_strlen($cleanWord) >= 2) {
                        return '+' . $cleanWord;
                    }
                    return '';
                }, $words);
                $fulltextWords = array_filter($fulltextWords);
                
                if (count($fulltextWords) > 0) {
                    $fulltextQuery = implode(' ', $fulltextWords);
                    
                    $countQuery->where(function ($q) use ($fulltextQuery, $escapedQueryLower, $escapedWords) {
                        $q->whereRaw("MATCH(name, description, short_description) AGAINST(? IN BOOLEAN MODE)", [$fulltextQuery])
                          ->orWhereRaw('LOWER(sku) LIKE ?', ["%{$escapedQueryLower}%"])
                          ->orWhereExists(function ($subQuery) use ($escapedQueryLower) {
                          $subQuery->select(DB::raw(1))
                                   ->from('shop_good_variations')
                                   ->whereColumn('shop_good_variations.good_id', 'shop_goods.id')
                                   ->where('shop_good_variations.is_active', true)
                                       ->whereRaw('LOWER(shop_good_variations.sku) LIKE ?', ["%{$escapedQueryLower}%"]);
                          })
                          // Fallback на LIKE если FULLTEXT не нашел (case-insensitive)
                          ->orWhere(function($likeQuery) use ($escapedWords) {
                              foreach ($escapedWords as $word) {
                                  $likeQuery->where(function($wordQuery) use ($word) {
                                      $wordQuery->whereRaw('LOWER(name) LIKE ?', ["%{$word}%"])
                                                ->orWhereRaw('LOWER(description) LIKE ?', ["%{$word}%"])
                                                ->orWhereRaw('LOWER(short_description) LIKE ?', ["%{$word}%"]);
                                  });
                              }
                      });
                });
                } else {
                    // Если после очистки слов не осталось, используем LIKE
                    $hasFulltextIndex = false;
                }
            }
            
            if (!$hasFulltextIndex || count($words) == 0) {
                // Требуем вхождения всех слов из запроса (case-insensitive)
                if (count($escapedWords) > 0) {
                    $countQuery->where(function ($q) use ($escapedWords, $escapedQueryLower) {
                        // Все слова должны быть найдены в текстовых полях
                        $q->where(function($allWordsQuery) use ($escapedWords) {
                            foreach ($escapedWords as $word) {
                                $allWordsQuery->where(function($wordQuery) use ($word) {
                                    $wordQuery->whereRaw('LOWER(name) LIKE ?', ["%{$word}%"])
                                              ->orWhereRaw('LOWER(description) LIKE ?', ["%{$word}%"])
                                              ->orWhereRaw('LOWER(short_description) LIKE ?', ["%{$word}%"]);
                                });
                            }
                        })
                        // Артикул точно совпадает
                        ->orWhereRaw('LOWER(sku) LIKE ?', ["%{$escapedQueryLower}%"])
                        ->orWhereExists(function ($subQuery) use ($escapedQueryLower) {
                            $subQuery->select(DB::raw(1))
                                     ->from('shop_good_variations')
                                     ->whereColumn('shop_good_variations.good_id', 'shop_goods.id')
                                     ->where('shop_good_variations.is_active', true)
                                     ->whereRaw('LOWER(shop_good_variations.sku) LIKE ?', ["%{$escapedQueryLower}%"]);
                        });
                    });
                } else {
                    $countQuery->where(function ($q) use ($escapedQueryLower) {
                        $q->whereRaw('LOWER(name) LIKE ?', ["%{$escapedQueryLower}%"])
                          ->orWhereRaw('LOWER(description) LIKE ?', ["%{$escapedQueryLower}%"])
                          ->orWhereRaw('LOWER(short_description) LIKE ?', ["%{$escapedQueryLower}%"])
                          ->orWhereRaw('LOWER(sku) LIKE ?', ["%{$escapedQueryLower}%"])
                          ->orWhereExists(function ($subQuery) use ($escapedQueryLower) {
                              $subQuery->select(DB::raw(1))
                                       ->from('shop_good_variations')
                                       ->whereColumn('shop_good_variations.good_id', 'shop_goods.id')
                                       ->where('shop_good_variations.is_active', true)
                                       ->whereRaw('LOWER(shop_good_variations.sku) LIKE ?', ["%{$escapedQueryLower}%"]);
                          });
                    });
                }
            }
            
            return $countQuery->count();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Получить общее количество категорий по запросу
     */
    private function getTotalCategoriesCount($query)
    {
        try {
            $escapedQuery = DB::getPdo()->quote($query);
            $escapedQuery = trim($escapedQuery, "'");
            $escapedQueryLower = mb_strtolower($escapedQuery);
            
            // Разбиваем оригинальный запрос на слова
            $words = preg_split('/\s+/', $query);
            $words = array_filter($words, function($word) {
                return mb_strlen(trim($word)) >= 2;
            });
            $words = array_map('trim', $words);
            $words = array_values($words);
            
            // Экранируем слова для LIKE запросов (case-insensitive)
            $escapedWords = array_map(function($word) {
                $quoted = DB::getPdo()->quote(mb_strtolower($word));
                return trim($quoted, "'");
            }, $words);
            
            $hasFulltextIndex = $this->checkFulltextIndex('shop_categories', ['name', 'description']);
            
            $countQuery = DB::table('shop_categories')
                ->where('is_active', true);
            
            if ($hasFulltextIndex && count($words) > 0) {
                // Добавляем + перед каждым словом для обязательного вхождения (case-insensitive)
                $fulltextWords = array_map(function($word) {
                    $cleanWord = mb_strtolower($word);
                    $cleanWord = preg_replace('/[+\-><()~*"@]/', '', $cleanWord);
                    $cleanWord = trim($cleanWord);
                    if (mb_strlen($cleanWord) >= 2) {
                        return '+' . $cleanWord;
                    }
                    return '';
                }, $words);
                $fulltextWords = array_filter($fulltextWords);
                
                if (count($fulltextWords) > 0) {
                    $fulltextQuery = implode(' ', $fulltextWords);
                    $countQuery->whereRaw("MATCH(name, description) AGAINST(? IN BOOLEAN MODE)", [$fulltextQuery]);
                } else {
                    $hasFulltextIndex = false;
                }
            }
            
            if (!$hasFulltextIndex || count($words) == 0) {
                // Требуем вхождения всех слов из запроса (case-insensitive)
                if (count($escapedWords) > 0) {
                    $countQuery->where(function ($q) use ($escapedWords) {
                        foreach ($escapedWords as $word) {
                            $q->where(function($wordQuery) use ($word) {
                                $wordQuery->whereRaw('LOWER(name) LIKE ?', ["%{$word}%"])
                                          ->orWhereRaw('LOWER(description) LIKE ?', ["%{$word}%"]);
                            });
                        }
                    });
                } else {
                    $countQuery->where(function ($q) use ($escapedQueryLower) {
                        $q->whereRaw('LOWER(name) LIKE ?', ["%{$escapedQueryLower}%"])
                          ->orWhereRaw('LOWER(description) LIKE ?', ["%{$escapedQueryLower}%"]);
                    });
                }
            }
            
            return $countQuery->count();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Получить общее количество брендов по запросу
     */
    private function getTotalBrandsCount($query)
    {
        try {
            $escapedQuery = DB::getPdo()->quote($query);
            $escapedQuery = trim($escapedQuery, "'");
            $escapedQueryLower = mb_strtolower($escapedQuery);
            
            // Разбиваем оригинальный запрос на слова
            $words = preg_split('/\s+/', $query);
            $words = array_filter($words, function($word) {
                return mb_strlen(trim($word)) >= 2;
            });
            $words = array_map('trim', $words);
            $words = array_values($words);
            
            // Экранируем слова для LIKE запросов (case-insensitive)
            $escapedWords = array_map(function($word) {
                $quoted = DB::getPdo()->quote(mb_strtolower($word));
                return trim($quoted, "'");
            }, $words);
            
            $hasFulltextIndex = $this->checkFulltextIndex('shop_brands', ['name', 'description']);
            
            $countQuery = DB::table('shop_brands')
                ->where('is_active', true);
            
            if ($hasFulltextIndex && count($words) > 0) {
                // Добавляем + перед каждым словом для обязательного вхождения (case-insensitive)
                $fulltextWords = array_map(function($word) {
                    $cleanWord = mb_strtolower($word);
                    $cleanWord = preg_replace('/[+\-><()~*"@]/', '', $cleanWord);
                    $cleanWord = trim($cleanWord);
                    if (mb_strlen($cleanWord) >= 2) {
                        return '+' . $cleanWord;
                    }
                    return '';
                }, $words);
                $fulltextWords = array_filter($fulltextWords);
                
                if (count($fulltextWords) > 0) {
                    $fulltextQuery = implode(' ', $fulltextWords);
                    $countQuery->whereRaw("MATCH(name, description) AGAINST(? IN BOOLEAN MODE)", [$fulltextQuery]);
                } else {
                    $hasFulltextIndex = false;
                }
            }
            
            if (!$hasFulltextIndex || count($words) == 0) {
                // Требуем вхождения всех слов из запроса (case-insensitive)
                if (count($escapedWords) > 0) {
                    $countQuery->where(function ($q) use ($escapedWords) {
                        foreach ($escapedWords as $word) {
                            $q->where(function($wordQuery) use ($word) {
                                $wordQuery->whereRaw('LOWER(name) LIKE ?', ["%{$word}%"])
                                          ->orWhereRaw('LOWER(description) LIKE ?', ["%{$word}%"]);
                            });
                        }
                    });
                } else {
                    $countQuery->where(function ($q) use ($escapedQueryLower) {
                        $q->whereRaw('LOWER(name) LIKE ?', ["%{$escapedQueryLower}%"])
                          ->orWhereRaw('LOWER(description) LIKE ?', ["%{$escapedQueryLower}%"]);
                    });
                }
            }
            
            return $countQuery->count();
        } catch (\Exception $e) {
            return 0;
        }
    }
}
