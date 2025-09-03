<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteTemplate;
use App\Models\SiteMenu;
use App\Models\SiteAuthBlock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SiteTemplateController extends Controller
{
    /**
     * Получить все шаблоны сайта
     */
    public function index(): JsonResponse
    {
        try {
            $templates = SiteTemplate::with(['menuTemplate', 'authTemplate'])
                ->ordered()
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => $templates
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения шаблонов: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Создать новый шаблон сайта
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'folder_name' => 'required|string|max:255|unique:site_templates',
                'menu_template_id' => 'nullable|exists:site_menus,id',
                'auth_template_id' => 'nullable|exists:site_auth_blocks,id',
                'is_active' => 'boolean',
                'settings' => 'nullable|array',
                'sort_order' => 'integer|min:0',
            ]);

            $template = SiteTemplate::create($validated);
            
            // Если это активный шаблон, деактивируем все остальные
            if ($template->is_active) {
                $template->activate();
            }
            
            return response()->json([
                'success' => true,
                'data' => $template->load(['menuTemplate', 'authTemplate']),
                'message' => 'Шаблон успешно создан'
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания шаблона: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить шаблон сайта по ID
     */
    public function show(SiteTemplate $siteTemplate): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $siteTemplate->load(['menuTemplate', 'authTemplate'])
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения шаблона: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Обновить шаблон сайта
     */
    public function update(Request $request, SiteTemplate $siteTemplate): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'folder_name' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('site_templates')->ignore($siteTemplate->id)
                ],
                'menu_template_id' => 'nullable|exists:site_menus,id',
                'auth_template_id' => 'nullable|exists:site_auth_blocks,id',
                'is_active' => 'boolean',
                'settings' => 'nullable|array',
                'sort_order' => 'integer|min:0',
            ]);

            $siteTemplate->update($validated);
            
            // Если это активный шаблон, деактивируем все остальные
            if ($siteTemplate->is_active) {
                $siteTemplate->activate();
            }
            
            return response()->json([
                'success' => true,
                'data' => $siteTemplate->load(['menuTemplate', 'authTemplate']),
                'message' => 'Шаблон успешно обновлен'
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления шаблона: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Удалить шаблон сайта
     */
    public function destroy(SiteTemplate $siteTemplate): JsonResponse
    {
        try {
            // Нельзя удалить активный шаблон
            if ($siteTemplate->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'Нельзя удалить активный шаблон'
                ], 400);
            }
            
            $siteTemplate->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Шаблон успешно удален'
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления шаблона: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Активировать шаблон сайта
     */
    public function activate(SiteTemplate $siteTemplate): JsonResponse
    {
        try {
            $siteTemplate->activate();
            
            return response()->json([
                'success' => true,
                'message' => 'Шаблон успешно активирован'
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка активации шаблона: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить доступные шаблоны меню
     */
    public function getMenuTemplates(): JsonResponse
    {
        try {
            $menus = SiteMenu::active()->ordered()->get();
            
            return response()->json([
                'success' => true,
                'data' => $menus
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения шаблонов меню: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить доступные шаблоны блоков авторизации
     */
    public function getAuthTemplates(): JsonResponse
    {
        try {
            $authBlocks = SiteAuthBlock::active()->ordered()->get();
            
            return response()->json([
                'success' => true,
                'data' => $authBlocks
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения шаблонов авторизации: ' . $e->getMessage()
            ], 500);
        }
    }
}
