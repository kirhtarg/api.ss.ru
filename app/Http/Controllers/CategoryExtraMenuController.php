<?php

namespace App\Http\Controllers;

use App\Models\ShopCategory;
use App\Models\ShopCategoryExtraMenu;
use App\Models\ShopCategoryExtraMenuFilter;
use App\Models\ShopCategoryExtraMenuSection;
use App\Models\ShopCategoryExtraMenuSectionItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class CategoryExtraMenuController extends Controller
{
    /**
     * Получить экстра-меню категории
     */
    public function show($categoryId): JsonResponse
    {
        try {
            $category = ShopCategory::find($categoryId);

            if (! $category) {
                return response()->json([
                    'success' => false,
                    'message' => 'Категория не найдена',
                ], 404);
            }

            // Проверяем существование таблицы
            if (! Schema::hasTable('shop_category_extra_menus')) {
                // Если таблицы нет, возвращаем пустое экстра-меню
                return response()->json([
                    'success' => true,
                    'data' => [
                        'id' => null,
                        'category_id' => $categoryId,
                        'is_active' => false,
                        'title' => null,
                        'filters' => [],
                        'sections' => [],
                    ],
                ]);
            }

            $extraMenu = ShopCategoryExtraMenu::with([
                'filters',
                'sections.items.category',
            ])->where('category_id', $categoryId)->first();

            if (! $extraMenu) {
                // Создаем пустое экстра-меню, если его нет
                $extraMenu = ShopCategoryExtraMenu::create([
                    'category_id' => $categoryId,
                    'is_active' => false,
                    'title' => null,
                ]);
                $extraMenu->load(['filters', 'sections.items.category']);
            }

            return response()->json([
                'success' => true,
                'data' => $extraMenu,
            ]);
        } catch (\Exception $e) {
            // Если ошибка связана с отсутствием таблицы, возвращаем пустое экстра-меню
            if (str_contains($e->getMessage(), "doesn't exist") || str_contains($e->getMessage(), 'Base table or view not found')) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'id' => null,
                        'category_id' => $categoryId,
                        'is_active' => false,
                        'title' => null,
                        'filters' => [],
                        'sections' => [],
                    ],
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении экстра-меню: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Сохранить экстра-меню категории
     */
    public function update(Request $request, $categoryId): JsonResponse
    {
        try {
            // Проверяем существование таблицы
            if (! Schema::hasTable('shop_category_extra_menus')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Таблицы экстра-меню еще не созданы. Пожалуйста, запустите миграции: php artisan migrate',
                ], 503);
            }

            $category = ShopCategory::find($categoryId);

            if (! $category) {
                return response()->json([
                    'success' => false,
                    'message' => 'Категория не найдена',
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'is_active' => 'boolean',
                'title' => 'nullable|string|max:255',
                'filters' => 'nullable|array',
                'filters.*.id' => 'nullable|integer|exists:shop_category_extra_menu_filters,id',
                'filters.*.type' => 'required|in:price,characteristic',
                'filters.*.is_active' => 'boolean',
                'filters.*.sort_order' => 'integer',
                'filters.*.price_min' => 'nullable|numeric|min:0',
                'filters.*.price_max' => 'nullable|numeric|min:0',
                'filters.*.characteristic_name' => 'nullable|string|max:255',
                'sections' => 'nullable|array',
                'sections.*.id' => 'nullable|integer|exists:shop_category_extra_menu_sections,id',
                'sections.*.title' => 'required|string|max:255',
                'sections.*.sort_order' => 'integer',
                'sections.*.items' => 'nullable|array',
                'sections.*.items.*.id' => 'nullable|integer|exists:shop_category_extra_menu_section_items,id',
                'sections.*.items.*.category_id' => 'required|integer|exists:shop_categories,id',
                'sections.*.items.*.sort_order' => 'integer',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors(),
                ], 422);
            }

            DB::beginTransaction();

            // Получаем или создаем экстра-меню
            $extraMenu = ShopCategoryExtraMenu::firstOrCreate(
                ['category_id' => $categoryId],
                ['is_active' => false, 'title' => null]
            );

            // Обновляем основные данные
            $extraMenu->update([
                'is_active' => $request->input('is_active', false),
                'title' => $request->input('title'),
            ]);

            // Обновляем фильтры
            if ($request->has('filters')) {
                $filterIds = [];
                foreach ($request->input('filters', []) as $filterData) {
                    if (isset($filterData['id'])) {
                        $filter = ShopCategoryExtraMenuFilter::find($filterData['id']);
                        if ($filter && $filter->extra_menu_id === $extraMenu->id) {
                            $filter->update($filterData);
                            $filterIds[] = $filter->id;
                        }
                    } else {
                        $filter = ShopCategoryExtraMenuFilter::create(array_merge($filterData, [
                            'extra_menu_id' => $extraMenu->id,
                        ]));
                        $filterIds[] = $filter->id;
                    }
                }
                // Удаляем фильтры, которых нет в запросе
                ShopCategoryExtraMenuFilter::where('extra_menu_id', $extraMenu->id)
                    ->whereNotIn('id', $filterIds)
                    ->delete();
            }

            // Обновляем подразделы
            if ($request->has('sections')) {
                $sectionIds = [];
                foreach ($request->input('sections', []) as $sectionData) {
                    if (isset($sectionData['id'])) {
                        $section = ShopCategoryExtraMenuSection::find($sectionData['id']);
                        if ($section && $section->extra_menu_id === $extraMenu->id) {
                            $section->update([
                                'title' => $sectionData['title'],
                                'sort_order' => $sectionData['sort_order'] ?? 0,
                            ]);
                            $sectionIds[] = $section->id;
                        }
                    } else {
                        $section = ShopCategoryExtraMenuSection::create([
                            'extra_menu_id' => $extraMenu->id,
                            'title' => $sectionData['title'],
                            'sort_order' => $sectionData['sort_order'] ?? 0,
                        ]);
                        $sectionIds[] = $section->id;
                    }

                    // Обновляем элементы подраздела
                    if (isset($sectionData['items'])) {
                        $itemIds = [];
                        foreach ($sectionData['items'] as $itemData) {
                            // Проверяем, что категория не дублируется в других подразделах
                            $existingItem = ShopCategoryExtraMenuSectionItem::where('category_id', $itemData['category_id'])
                                ->where('section_id', '!=', $section->id)
                                ->whereIn('section_id', function ($query) use ($extraMenu) {
                                    $query->select('id')
                                        ->from('shop_category_extra_menu_sections')
                                        ->where('extra_menu_id', $extraMenu->id);
                                })
                                ->first();

                            if ($existingItem) {
                                continue; // Пропускаем дубликат
                            }

                            if (isset($itemData['id'])) {
                                $item = ShopCategoryExtraMenuSectionItem::find($itemData['id']);
                                if ($item && $item->section_id === $section->id) {
                                    $item->update($itemData);
                                    $itemIds[] = $item->id;
                                }
                            } else {
                                $item = ShopCategoryExtraMenuSectionItem::create(array_merge($itemData, [
                                    'section_id' => $section->id,
                                ]));
                                $itemIds[] = $item->id;
                            }
                        }
                        // Удаляем элементы, которых нет в запросе
                        ShopCategoryExtraMenuSectionItem::where('section_id', $section->id)
                            ->whereNotIn('id', $itemIds)
                            ->delete();
                    }
                }
                // Удаляем подразделы, которых нет в запросе
                ShopCategoryExtraMenuSection::where('extra_menu_id', $extraMenu->id)
                    ->whereNotIn('id', $sectionIds)
                    ->delete();
            }

            DB::commit();

            $extraMenu->load(['filters', 'sections.items.category']);

            return response()->json([
                'success' => true,
                'message' => 'Экстра-меню успешно сохранено',
                'data' => $extraMenu,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при сохранении экстра-меню: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить список характеристик (свойств) товаров для выбора
     */
    public function getCharacteristics(): JsonResponse
    {
        try {
            if (! Schema::hasTable('shop_properties')) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                ]);
            }

            $properties = DB::table('shop_properties')
                ->select('id', 'name')
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
                ->map(function ($property) {
                    return [
                        'id' => $property->id,
                        'name' => $property->name,
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $properties,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => true,
                'data' => [],
            ]);
        }
    }

    /**
     * Получить дочерние категории для выбора в подразделы
     */
    public function getChildCategories($categoryId): JsonResponse
    {
        try {
            $category = ShopCategory::find($categoryId);

            if (! $category) {
                return response()->json([
                    'success' => false,
                    'message' => 'Категория не найдена',
                ], 404);
            }

            // Получаем подкатегории первого уровня
            $children = ShopCategory::where('parent_id', $categoryId)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $children,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении подкатегорий: '.$e->getMessage(),
            ], 500);
        }
    }
}
