<?php

namespace App\Http\Controllers\Admin\Shop;

use App\Http\Controllers\Controller;
use App\Models\Shop\Property;
use App\Models\Shop\PropertyValue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PropertyController extends Controller
{
    /**
     * Получить список всех свойств
     */
    public function index(): JsonResponse
    {
        try {
            $properties = Property::with('values')
                ->orderBy('name')
                ->get();

            // Добавляем счетчики товаров для каждой характеристики
            $properties = $properties->map(function ($property) {
                $goodsCount = DB::table('shop_good_properties')
                    ->where('property_id', $property->id)
                    ->whereNotNull('good_id')
                    ->distinct('good_id')
                    ->count('good_id');

                $property->goods_count = $goodsCount;

                // Добавляем счетчик привязанных категорий
                $categoriesCount = DB::table('shop_category_property')
                    ->where('property_id', $property->id)
                    ->count();

                $property->categories_count = $categoriesCount;

                // Добавляем счетчики для значений
                if ($property->values) {
                    $property->values = $property->values->map(function ($value) {
                        $valueGoodsCount = DB::table('shop_good_properties')
                            ->where('property_id', $value->property_id)
                            ->where('shop_property_value_id', $value->id)
                            ->whereNotNull('good_id')
                            ->distinct('good_id')
                            ->count('good_id');

                        $value->goods_count = $valueGoodsCount;

                        return $value;
                    });
                }

                return $property;
            });

            return response()->json([
                'success' => true,
                'data' => $properties,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки свойств: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Создать новое свойство
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'property_type' => 'required|in:string,color,select',
            'values' => 'required_if:property_type,select|array',
            'values.*.value' => 'required_if:property_type,select|string|max:255',
            'values.*.color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Генерируем slug для проверки
            $slug = \Illuminate\Support\Str::slug($request->name);

            // Нормализуем название: только первое слово с большой буквы
            $normalizedName = mb_strtolower($request->name);
            $normalizedName = mb_strtoupper(mb_substr($normalizedName, 0, 1)).mb_substr($normalizedName, 1);

            // Проверяем, существует ли свойство с таким же именем (без учета регистра) или slug
            $property = Property::where(function ($query) use ($request, $slug) {
                $query->whereRaw('LOWER(name) = ?', [strtolower($request->name)])
                    ->orWhere('slug', $slug);
            })->first();

            if (! $property) {
                // Если свойства нет, создаем новое
                $property = Property::create([
                    'name' => $normalizedName,
                    'description' => $request->description,
                    'property_type' => $request->property_type,
                    'sort_order' => 0,
                    'slug' => $slug, // Явно указываем slug, чтобы избежать дубликатов
                ]);
            } else {
                // Если свойство существует, обновляем его название на нормализованное (если оно отличается)
                if ($property->name !== $normalizedName) {
                    $property->update([
                        'name' => $normalizedName,
                        'description' => $request->description ?? $property->description,
                        'property_type' => $request->property_type ?? $property->property_type,
                    ]);
                } else {
                    // Если название уже правильное, обновляем только описание и тип
                    $property->update([
                        'description' => $request->description ?? $property->description,
                        'property_type' => $request->property_type ?? $property->property_type,
                    ]);
                }
            }

            // Если тип "выбор", создаем значения
            if ($request->property_type === 'select' && $request->has('values')) {
                foreach ($request->values as $index => $valueData) {
                    PropertyValue::create([
                        'property_id' => $property->id,
                        'value' => $valueData['value'],
                        'color' => $valueData['color'] ?? null,
                        'sort_order' => $index,
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Свойство успешно создано',
                'data' => $property->load('values'),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания свойства: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить значения для свойства типа "выбор"
     */
    public function getValues(Property $property): JsonResponse
    {
        try {
            if ($property->property_type !== 'select') {
                return response()->json([
                    'success' => false,
                    'message' => 'Свойство не является типом "выбор"',
                ], 400);
            }

            $values = $property->values()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get();

            return response()->json([
                'success' => true,
                'values' => $values,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки значений: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Обновить свойство
     */
    public function update(Request $request, Property $property): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'property_type' => 'required|in:string,color,select',
            'values' => 'required_if:property_type,select|array',
            'values.*.value' => 'required_if:property_type,select|string|max:255',
            'values.*.color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Нормализуем название: только первое слово с большой буквы
            $normalizedName = mb_strtolower($request->name);
            $normalizedName = mb_strtoupper(mb_substr($normalizedName, 0, 1)).mb_substr($normalizedName, 1);

            // Сохраняем старый тип для проверки изменения
            $oldPropertyType = $property->property_type;

            $property->update([
                'name' => $normalizedName,
                'description' => $request->description,
                'property_type' => $request->property_type,
            ]);

            // Обновляем значения для типа "выбор"
            if ($request->property_type === 'select' && $request->has('values')) {
                // Удаляем старые значения
                $property->values()->delete();

                // Создаем новые
                foreach ($request->values as $index => $valueData) {
                    PropertyValue::create([
                        'property_id' => $property->id,
                        'value' => $valueData['value'],
                        'color' => $valueData['color'] ?? null,
                        'sort_order' => $index,
                    ]);
                }
            } elseif ($oldPropertyType !== $request->property_type) {
                // Удаляем значения только если тип действительно изменился
                // (например, было "select", стало "string" или "color")
                $property->values()->delete();
            }
            // Если тип не изменился и это не "select", значения не трогаем

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Свойство успешно обновлено',
                'data' => $property->load('values'),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления свойства: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить количество товаров с данной характеристикой
     */
    public function getGoodsCount(Property $property): JsonResponse
    {
        try {
            $goodsCount = DB::table('shop_good_properties')
                ->where('property_id', $property->id)
                ->whereNotNull('good_id')
                ->distinct('good_id')
                ->count('good_id');

            return response()->json([
                'success' => true,
                'count' => $goodsCount,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения количества товаров: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Удалить свойство
     */
    public function destroy(Property $property): JsonResponse
    {
        try {
            // Проверяем, есть ли товары с этой характеристикой
            $goodsCount = DB::table('shop_good_properties')
                ->where('property_id', $property->id)
                ->whereNotNull('good_id')
                ->distinct('good_id')
                ->count('good_id');

            if ($goodsCount > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Невозможно удалить характеристику',
                    'has_goods' => true,
                    'goods_count' => $goodsCount,
                    'error' => "У характеристики есть привязанные товары ({$goodsCount}). При удалении характеристика будет удалена у всех этих товаров.",
                ], 422);
            }

            DB::beginTransaction();

            // Удаляем все значения свойства
            $property->values()->delete();

            // Удаляем связи с товарами (если есть)
            DB::table('shop_good_properties')
                ->where('property_id', $property->id)
                ->delete();

            // Удаляем само свойство
            $property->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Свойство успешно удалено',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления свойства: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Принудительно удалить свойство (даже если есть привязанные товары)
     */
    public function forceDestroy(Property $property): JsonResponse
    {
        try {
            DB::beginTransaction();

            // Удаляем связи с товарами
            DB::table('shop_good_properties')
                ->where('property_id', $property->id)
                ->delete();

            // Удаляем все значения свойства
            $property->values()->delete();

            // Удаляем само свойство
            $property->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Свойство успешно удалено',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления свойства: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить категории характеристики
     */
    public function getCategories(Property $property): JsonResponse
    {
        try {
            $categories = $property->categories()->get();

            return response()->json([
                'success' => true,
                'categories' => $categories,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения категорий: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Привязать категории к характеристике
     */
    public function syncCategories(Request $request, Property $property): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'category_ids' => 'required|array',
            'category_ids.*' => 'required|integer|exists:shop_categories,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $property->categories()->sync($request->category_ids);

            $categories = $property->categories()->get();

            return response()->json([
                'success' => true,
                'message' => 'Категории успешно привязаны',
                'categories' => $categories,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка привязки категорий: '.$e->getMessage(),
            ], 500);
        }
    }
}
