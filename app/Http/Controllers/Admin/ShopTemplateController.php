<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ShopTemplateController extends Controller
{
    /**
     * Получить все шаблоны магазина
     */
    public function index(): JsonResponse
    {
        try {
            $templates = ShopTemplate::ordered()->get();

            return response()->json([
                'success' => true,
                'data' => $templates->map(function ($template) {
                    return [
                        'id' => $template->id,
                        'name' => $template->name,
                        'description' => $template->description,
                        'folder_name' => $template->folder_name,
                        'is_active' => $template->is_active,
                        'sort_order' => $template->sort_order,
                        'created_at' => $template->created_at,
                        'updated_at' => $template->updated_at,
                    ];
                }),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения шаблонов магазина: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Создать новый шаблон магазина
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'folder_name' => 'required|string|max:255|unique:shop_templates,folder_name',
                'is_active' => 'boolean',
                'settings' => 'nullable|array',
                'sort_order' => 'integer|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $data = $request->only([
                'name', 'description', 'folder_name', 'is_active', 'settings', 'sort_order',
            ]);

            // Если активируем шаблон, деактивируем все остальные
            if ($data['is_active'] ?? false) {
                ShopTemplate::query()->update(['is_active' => false]);
            }

            $template = ShopTemplate::create($data);

            return response()->json([
                'success' => true,
                'message' => 'Шаблон магазина создан',
                'data' => $template->getTemplateData(),
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания шаблона магазина: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить шаблон магазина по ID
     */
    public function show(ShopTemplate $shopTemplate): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $shopTemplate->getTemplateData(),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения шаблона магазина: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Обновить шаблон магазина
     */
    public function update(Request $request, ShopTemplate $shopTemplate): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'folder_name' => 'required|string|max:255|unique:shop_templates,folder_name,'.$shopTemplate->id,
                'is_active' => 'boolean',
                'settings' => 'nullable|array',
                'sort_order' => 'integer|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $data = $request->only([
                'name', 'description', 'folder_name', 'is_active', 'settings', 'sort_order',
            ]);

            // Если активируем шаблон, деактивируем все остальные
            if ($data['is_active'] ?? false) {
                ShopTemplate::where('id', '!=', $shopTemplate->id)->update(['is_active' => false]);
            }

            $shopTemplate->update($data);

            return response()->json([
                'success' => true,
                'message' => 'Шаблон магазина обновлен',
                'data' => $shopTemplate->getTemplateData(),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления шаблона магазина: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Удалить шаблон магазина
     */
    public function destroy(ShopTemplate $shopTemplate): JsonResponse
    {
        try {
            // Нельзя удалить активный шаблон
            if ($shopTemplate->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'Нельзя удалить активный шаблон',
                ], 422);
            }

            $shopTemplate->delete();

            return response()->json([
                'success' => true,
                'message' => 'Шаблон магазина удален',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления шаблона магазина: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Активировать шаблон магазина
     */
    public function activate(ShopTemplate $shopTemplate): JsonResponse
    {
        try {
            $shopTemplate->activate();

            return response()->json([
                'success' => true,
                'message' => 'Шаблон магазина активирован',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка активации шаблона магазина: '.$e->getMessage(),
            ], 500);
        }
    }
}
