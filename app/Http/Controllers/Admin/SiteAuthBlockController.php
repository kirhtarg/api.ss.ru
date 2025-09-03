<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteAuthBlock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteAuthBlockController extends Controller
{
    /**
     * Получить все шаблоны блоков авторизации
     */
    public function index(): JsonResponse
    {
        try {
            $authBlocks = SiteAuthBlock::ordered()->get();
            
            return response()->json([
                'success' => true,
                'data' => $authBlocks
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения шаблонов блоков авторизации: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Создать новый шаблон блока авторизации
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'template_name' => 'required|string|max:255|unique:site_auth_blocks',
                'is_active' => 'boolean',
                'settings' => 'nullable|array',
                'sort_order' => 'integer|min:0',
            ]);

            $authBlock = SiteAuthBlock::create($validated);
            
            return response()->json([
                'success' => true,
                'data' => $authBlock,
                'message' => 'Шаблон блока авторизации успешно создан'
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания шаблона блока авторизации: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить шаблон блока авторизации по ID
     */
    public function show(SiteAuthBlock $siteAuthBlock): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $siteAuthBlock
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения шаблона блока авторизации: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Обновить шаблон блока авторизации
     */
    public function update(Request $request, SiteAuthBlock $siteAuthBlock): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'template_name' => 'required|string|max:255|unique:site_auth_blocks,template_name,' . $siteAuthBlock->id,
                'is_active' => 'boolean',
                'settings' => 'nullable|array',
                'sort_order' => 'integer|min:0',
            ]);

            $siteAuthBlock->update($validated);
            
            return response()->json([
                'success' => true,
                'data' => $siteAuthBlock,
                'message' => 'Шаблон блока авторизации успешно обновлен'
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления шаблона блока авторизации: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Удалить шаблон блока авторизации
     */
    public function destroy(SiteAuthBlock $siteAuthBlock): JsonResponse
    {
        try {
            // Проверяем, используется ли шаблон в активных шаблонах сайта
            if ($siteAuthBlock->siteTemplates()->where('is_active', true)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Нельзя удалить шаблон блока авторизации, который используется в активном шаблоне сайта'
                ], 400);
            }
            
            $siteAuthBlock->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Шаблон блока авторизации успешно удален'
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления шаблона блока авторизации: ' . $e->getMessage()
            ], 500);
        }
    }
}
