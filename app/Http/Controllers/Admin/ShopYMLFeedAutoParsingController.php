<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopYMLFeedAutoParsing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ShopYMLFeedAutoParsingController extends Controller
{
    /**
     * Получить список всех автопарсингов YML фидов
     */
    public function index(): JsonResponse
    {
        try {
            $autoParsings = ShopYMLFeedAutoParsing::orderBy('created_at', 'desc')->get();

            return response()->json([
                'success' => true,
                'data' => $autoParsings,
            ]);
        } catch (\Exception $e) {
            Log::error('ShopYMLFeedAutoParsingController::index - Ошибка: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении списка автопарсингов YML',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить конкретный автопарсинг YML фида
     */
    public function show(int $id): JsonResponse
    {
        try {
            $autoParsing = ShopYMLFeedAutoParsing::findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $autoParsing,
            ]);
        } catch (\Exception $e) {
            Log::error('ShopYMLFeedAutoParsingController::show - Ошибка: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Автопарсинг YML не найден',
                'error' => $e->getMessage(),
            ], 404);
        }
    }

    /**
     * Создать новый автопарсинг YML фида
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'yml_feed_url' => 'required|string|url',
                'auth_username' => 'nullable|string|max:255',
                'auth_password' => 'nullable|string|max:255',
                'field_mapping' => 'nullable|array',
                'parse_options' => 'nullable|array',
                'settings' => 'nullable|array',
            ]);

            $dataToSave = [
                'name' => $validated['name'],
                'description' => isset($validated['description']) && $validated['description'] !== '' ? $validated['description'] : null,
                'yml_feed_url' => $validated['yml_feed_url'],
                'auth_username' => isset($validated['auth_username']) && $validated['auth_username'] !== '' ? $validated['auth_username'] : null,
                'auth_password' => isset($validated['auth_password']) && $validated['auth_password'] !== '' ? $validated['auth_password'] : null,
                'field_mapping' => isset($validated['field_mapping']) ? $validated['field_mapping'] : null,
                'parse_options' => isset($validated['parse_options']) ? $validated['parse_options'] : null,
                'settings' => isset($validated['settings']) ? $validated['settings'] : null,
            ];

            $autoParsing = ShopYMLFeedAutoParsing::create($dataToSave);

            return response()->json([
                'success' => true,
                'message' => 'Автопарсинг YML успешно создан',
                'data' => $autoParsing,
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('ShopYMLFeedAutoParsingController::store - Ошибка: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при создании автопарсинга YML',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Обновить автопарсинг YML фида
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $autoParsing = ShopYMLFeedAutoParsing::findOrFail($id);

            $validated = $request->validate([
                'name' => 'sometimes|required|string|max:255',
                'description' => 'nullable|string',
                'yml_feed_url' => 'sometimes|required|string|url',
                'auth_username' => 'nullable|string|max:255',
                'auth_password' => 'nullable|string|max:255',
                'field_mapping' => 'nullable|array',
                'parse_options' => 'nullable|array',
                'settings' => 'nullable|array',
            ]);

            $allRequestData = $request->all();
            $dataToUpdate = [];

            if (isset($allRequestData['name'])) {
                $dataToUpdate['name'] = $allRequestData['name'];
            }
            if (array_key_exists('description', $allRequestData)) {
                $dataToUpdate['description'] = $allRequestData['description'] !== '' ? $allRequestData['description'] : null;
            }
            if (isset($allRequestData['yml_feed_url'])) {
                $dataToUpdate['yml_feed_url'] = $allRequestData['yml_feed_url'];
            }
            if (array_key_exists('auth_username', $allRequestData)) {
                $dataToUpdate['auth_username'] = $allRequestData['auth_username'] !== '' ? $allRequestData['auth_username'] : null;
            }
            if (array_key_exists('auth_password', $allRequestData)) {
                $dataToUpdate['auth_password'] = $allRequestData['auth_password'] !== '' ? $allRequestData['auth_password'] : null;
            }
            if (array_key_exists('field_mapping', $allRequestData)) {
                $dataToUpdate['field_mapping'] = $allRequestData['field_mapping'];
            }
            if (array_key_exists('parse_options', $allRequestData)) {
                $dataToUpdate['parse_options'] = $allRequestData['parse_options'];
            }
            if (array_key_exists('settings', $allRequestData)) {
                $dataToUpdate['settings'] = $allRequestData['settings'];
            }

            if (empty($dataToUpdate)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Нет данных для обновления',
                ], 400);
            }

            $autoParsing->update($dataToUpdate);

            return response()->json([
                'success' => true,
                'message' => 'Автопарсинг YML успешно обновлен',
                'data' => $autoParsing->fresh(),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('ShopYMLFeedAutoParsingController::update - Ошибка: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при обновлении автопарсинга YML',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Удалить автопарсинг YML фида
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $autoParsing = ShopYMLFeedAutoParsing::findOrFail($id);
            $autoParsing->delete();

            return response()->json([
                'success' => true,
                'message' => 'Автопарсинг YML успешно удален',
            ]);
        } catch (\Exception $e) {
            Log::error('ShopYMLFeedAutoParsingController::destroy - Ошибка: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при удалении автопарсинга YML',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
