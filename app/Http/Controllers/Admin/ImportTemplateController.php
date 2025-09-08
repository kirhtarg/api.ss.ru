<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ImportTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class ImportTemplateController extends Controller
{
    /**
     * Получить список всех шаблонов
     */
    public function index(): JsonResponse
    {
        try {
            $templates = ImportTemplate::orderBy('name')->get();
            
            return response()->json([
                'success' => true,
                'data' => $templates
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении списка шаблонов',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить конкретный шаблон
     */
    public function show(int $id): JsonResponse
    {
        try {
            $template = ImportTemplate::findOrFail($id);
            
            return response()->json([
                'success' => true,
                'data' => $template
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Шаблон не найден',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Создать новый шаблон
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:import_templates,name',
                'description' => 'nullable|string|max:1000',
                'settings' => 'required|array',
                'is_default' => 'boolean'
            ]);

            // Если устанавливаем как шаблон по умолчанию, снимаем флаг с других
            if ($validated['is_default'] ?? false) {
                ImportTemplate::where('is_default', true)->update(['is_default' => false]);
            }

            $template = ImportTemplate::create($validated);

            return response()->json([
                'success' => true,
                'message' => 'Шаблон успешно создан',
                'data' => $template
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при создании шаблона',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Обновить шаблон
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $template = ImportTemplate::findOrFail($id);

            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:import_templates,name,' . $id,
                'description' => 'nullable|string|max:1000',
                'settings' => 'required|array',
                'is_default' => 'boolean'
            ]);

            // Если устанавливаем как шаблон по умолчанию, снимаем флаг с других
            if ($validated['is_default'] ?? false) {
                ImportTemplate::where('is_default', true)
                    ->where('id', '!=', $id)
                    ->update(['is_default' => false]);
            }

            $template->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Шаблон успешно обновлен',
                'data' => $template
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при обновлении шаблона',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Удалить шаблон
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $template = ImportTemplate::findOrFail($id);

            // Проверяем, не является ли это последним шаблоном
            if (ImportTemplate::count() <= 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'Нельзя удалить последний шаблон'
                ], 400);
            }

            // Если удаляем шаблон по умолчанию, устанавливаем другой как default
            if ($template->is_default) {
                $newDefault = ImportTemplate::where('id', '!=', $id)->first();
                if ($newDefault) {
                    $newDefault->update(['is_default' => true]);
                }
            }

            $template->delete();

            return response()->json([
                'success' => true,
                'message' => 'Шаблон успешно удален'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при удалении шаблона',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Дублировать шаблон (сохранить как)
     */
    public function duplicate(Request $request, int $id): JsonResponse
    {
        try {
            $originalTemplate = ImportTemplate::findOrFail($id);
            
            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:import_templates,name',
                'description' => 'nullable|string|max:1000'
            ]);

            $newTemplate = ImportTemplate::create([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? $originalTemplate->description,
                'settings' => $originalTemplate->settings,
                'is_default' => false
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Шаблон успешно скопирован',
                'data' => $newTemplate
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при копировании шаблона',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Установить шаблон по умолчанию
     */
    public function setDefault(int $id): JsonResponse
    {
        try {
            $template = ImportTemplate::findOrFail($id);
            $template->setAsDefault();

            return response()->json([
                'success' => true,
                'message' => 'Шаблон установлен по умолчанию',
                'data' => $template
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при установке шаблона по умолчанию',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
