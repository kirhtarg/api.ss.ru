<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteTextblock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class TextblockController extends Controller
{
    /**
     * Получить все текстовые блоки
     */
    public function index()
    {
        try {
            $textblocks = SiteTextblock::orderBy('id', 'asc')->get();

            return response()->json([
                'success' => true,
                'data' => $textblocks
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения текстовых блоков: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Создать новый текстовый блок
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            Log::info('Создание текстового блока', ['data' => $request->all()]);
            
            $textblock = SiteTextblock::create([
                'name' => $request->name,
                'text' => $request->text ?? null,
                'background_color' => $request->background_color ?? '#ffffff',
                'text_color' => $request->text_color ?? '#000000',
                'link' => $request->link ?? null,
                'link_type' => $request->link_type ?? 'internal',
                'is_active' => $request->is_active ?? true,
            ]);
            
            Log::info('Текстовый блок успешно создан', ['id' => $textblock->id]);

            return response()->json([
                'success' => true,
                'message' => 'Текстовый блок успешно создан',
                'data' => $textblock
            ]);
        } catch (\Exception $e) {
            Log::error('Ошибка создания текстового блока', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => $request->all()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания текстового блока: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить конкретный текстовый блок
     */
    public function show(string $id)
    {
        try {
            $textblock = SiteTextblock::findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $textblock
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения текстового блока: ' . $e->getMessage()
            ], 404);
        }
    }

    /**
     * Обновить текстовый блок
     */
    public function update(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'text' => 'nullable|string',
            'background_color' => ['sometimes', 'string', 'regex:/^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/'],
            'text_color' => ['sometimes', 'string', 'regex:/^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/'],
            'link' => 'nullable|string|max:500',
            'link_type' => 'sometimes|in:internal,external',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $textblock = SiteTextblock::findOrFail($id);
            
            // Подготавливаем данные для обновления
            $updateData = $request->only([
                'name',
                'text',
                'background_color',
                'text_color',
                'link',
                'link_type',
                'is_active'
            ]);
            
            // Если передано null для text или link, сохраняем null
            if ($request->has('text') && $request->text === '') {
                $updateData['text'] = null;
            }
            if ($request->has('link') && $request->link === '') {
                $updateData['link'] = null;
            }
            
            $textblock->update($updateData);

            return response()->json([
                'success' => true,
                'message' => 'Текстовый блок успешно обновлен',
                'data' => $textblock->fresh()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления текстового блока: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Удалить текстовый блок
     */
    public function destroy(string $id)
    {
        try {
            $textblock = SiteTextblock::findOrFail($id);
            $textblock->delete();

            return response()->json([
                'success' => true,
                'message' => 'Текстовый блок успешно удален'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления текстового блока: ' . $e->getMessage()
            ], 500);
        }
    }
}
