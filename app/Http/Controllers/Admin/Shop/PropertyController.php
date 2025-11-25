<?php

namespace App\Http\Controllers\Admin\Shop;

use App\Http\Controllers\Controller;
use App\Models\Shop\Property;
use App\Models\Shop\PropertyValue;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
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

            return response()->json([
                'success' => true,
                'data' => $properties
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки свойств: ' . $e->getMessage()
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
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Генерируем slug для проверки
            $slug = \Illuminate\Support\Str::slug($request->name);

            // Нормализуем название: только первое слово с большой буквы
            $normalizedName = mb_strtolower($request->name);
            $normalizedName = mb_strtoupper(mb_substr($normalizedName, 0, 1)) . mb_substr($normalizedName, 1);
            
            // Проверяем, существует ли свойство с таким же именем (без учета регистра) или slug
            $property = Property::where(function($query) use ($request, $slug) {
                $query->whereRaw('LOWER(name) = ?', [strtolower($request->name)])
                      ->orWhere('slug', $slug);
            })->first();

            if (!$property) {
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
                'data' => $property->load('values')
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания свойства: ' . $e->getMessage()
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
                    'message' => 'Свойство не является типом "выбор"'
                ], 400);
            }

            $values = $property->values()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get();

            return response()->json([
                'success' => true,
                'values' => $values
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки значений: ' . $e->getMessage()
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
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Нормализуем название: только первое слово с большой буквы
            $normalizedName = mb_strtolower($request->name);
            $normalizedName = mb_strtoupper(mb_substr($normalizedName, 0, 1)) . mb_substr($normalizedName, 1);
            
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
            } else {
                // Если тип изменился с "выбор" на другой, удаляем значения
                $property->values()->delete();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Свойство успешно обновлено',
                'data' => $property->load('values')
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления свойства: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Удалить свойство
     */
    public function destroy(Property $property): JsonResponse
    {
        try {
            DB::beginTransaction();

            // Удаляем все значения свойства
            $property->values()->delete();
            
            // Удаляем само свойство
            $property->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Свойство успешно удалено'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления свойства: ' . $e->getMessage()
            ], 500);
        }
    }
}
