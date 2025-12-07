<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopGoogleSheetsAutoParsing;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class ShopGoogleSheetsAutoParsingController extends Controller
{
    /**
     * Получить список всех автопарсингов
     */
    public function index(): JsonResponse
    {
        try {
            $autoParsings = ShopGoogleSheetsAutoParsing::orderBy('created_at', 'desc')->get();
            
            return response()->json([
                'success' => true,
                'data' => $autoParsings
            ]);
        } catch (\Exception $e) {
            Log::error('ShopGoogleSheetsAutoParsingController::index - Ошибка: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении списка автопарсингов',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить конкретный автопарсинг
     */
    public function show(int $id): JsonResponse
    {
        try {
            $autoParsing = ShopGoogleSheetsAutoParsing::findOrFail($id);
            
            return response()->json([
                'success' => true,
                'data' => $autoParsing
            ]);
        } catch (\Exception $e) {
            Log::error('ShopGoogleSheetsAutoParsingController::show - Ошибка: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Автопарсинг не найден',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Создать новый автопарсинг
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'google_sheet_url' => 'required|string|url',
                'field_mapping' => 'nullable|array',
                'skip_rows' => 'nullable|integer|min:0',
                'sheet_number' => 'nullable|integer|min:1',
                'settings' => 'nullable|array'
            ]);

            // Логируем полученные данные для отладки
            Log::info('ShopGoogleSheetsAutoParsingController::store - Полученные данные:', [
                'name' => $validated['name'] ?? 'не указано',
                'description' => $validated['description'] ?? 'не указано',
                'google_sheet_url' => $validated['google_sheet_url'] ?? 'не указано',
                'field_mapping' => isset($validated['field_mapping']) ? 'передан (размер: ' . count($validated['field_mapping']) . ')' : 'не передан',
                'skip_rows' => $validated['skip_rows'] ?? 'не указано',
                'sheet_number' => $validated['sheet_number'] ?? 'не указано',
                'settings' => isset($validated['settings']) ? 'передан (размер: ' . count($validated['settings']) . ')' : 'не передан'
            ]);

            // Убеждаемся, что все поля переданы и правильно обработаны
            // Laravel автоматически сериализует JSON через casts в модели
            $dataToSave = [
                'name' => $validated['name'],
                'description' => isset($validated['description']) && $validated['description'] !== '' ? $validated['description'] : null,
                'google_sheet_url' => $validated['google_sheet_url'],
                'field_mapping' => isset($validated['field_mapping']) ? $validated['field_mapping'] : null,
                'skip_rows' => isset($validated['skip_rows']) && $validated['skip_rows'] !== null ? (int)$validated['skip_rows'] : 0,
                'sheet_number' => isset($validated['sheet_number']) && $validated['sheet_number'] !== null ? (int)$validated['sheet_number'] : 1,
                'settings' => isset($validated['settings']) ? $validated['settings'] : null
            ];
            
            // Логируем все данные перед сохранением
            Log::info('ShopGoogleSheetsAutoParsingController::store - Полные данные для сохранения:', [
                'dataToSave' => $dataToSave,
                'skip_rows_value' => $dataToSave['skip_rows'],
                'sheet_number_value' => $dataToSave['sheet_number'],
                'field_mapping_is_array' => is_array($dataToSave['field_mapping']),
                'field_mapping_count' => is_array($dataToSave['field_mapping']) ? count($dataToSave['field_mapping']) : 0,
                'settings_is_array' => is_array($dataToSave['settings']),
                'settings_count' => is_array($dataToSave['settings']) ? count($dataToSave['settings']) : 0
            ]);

            // Логируем данные перед сохранением
            Log::info('ShopGoogleSheetsAutoParsingController::store - Данные для сохранения:', [
                'name' => $dataToSave['name'],
                'skip_rows' => $dataToSave['skip_rows'],
                'sheet_number' => $dataToSave['sheet_number'],
                'field_mapping_type' => gettype($dataToSave['field_mapping']),
                'field_mapping_empty' => empty($dataToSave['field_mapping']),
                'settings_type' => gettype($dataToSave['settings']),
                'settings_empty' => empty($dataToSave['settings'])
            ]);

            $autoParsing = ShopGoogleSheetsAutoParsing::create($dataToSave);

            // Логируем сохраненные данные из БД
            Log::info('ShopGoogleSheetsAutoParsingController::store - Сохранено в БД:', [
                'id' => $autoParsing->id,
                'name' => $autoParsing->name,
                'skip_rows' => $autoParsing->skip_rows,
                'sheet_number' => $autoParsing->sheet_number,
                'field_mapping_type' => gettype($autoParsing->field_mapping),
                'field_mapping_keys' => is_array($autoParsing->field_mapping) ? count($autoParsing->field_mapping) : 0,
                'settings_type' => gettype($autoParsing->settings),
                'settings_keys' => is_array($autoParsing->settings) ? count($autoParsing->settings) : 0
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Автопарсинг успешно создан',
                'data' => $autoParsing
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('ShopGoogleSheetsAutoParsingController::store - Ошибка: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при создании автопарсинга',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Обновить автопарсинг
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $autoParsing = ShopGoogleSheetsAutoParsing::findOrFail($id);

            // При обновлении принимаем все поля, даже если они не переданы
            $validated = $request->validate([
                'name' => 'sometimes|required|string|max:255',
                'description' => 'nullable|string',
                'google_sheet_url' => 'sometimes|required|string|url',
                'field_mapping' => 'nullable|array',
                'skip_rows' => 'nullable|integer|min:0',
                'sheet_number' => 'nullable|integer|min:1',
                'settings' => 'nullable|array'
            ]);
            
            // Получаем все данные из запроса, включая те, которые не прошли валидацию
            $allRequestData = $request->all();

            // Убеждаемся, что все поля переданы и правильно обработаны
            // При обновлении ВСЕГДА обновляем все переданные поля
            $dataToUpdate = [];
            
            // Обновляем только те поля, которые переданы в запросе
            if (isset($allRequestData['name'])) {
                $dataToUpdate['name'] = $allRequestData['name'];
            }
            if (array_key_exists('description', $allRequestData)) {
                $dataToUpdate['description'] = $allRequestData['description'] !== '' ? $allRequestData['description'] : null;
            }
            if (isset($allRequestData['google_sheet_url'])) {
                $dataToUpdate['google_sheet_url'] = $allRequestData['google_sheet_url'];
            }
            if (array_key_exists('field_mapping', $allRequestData)) {
                $dataToUpdate['field_mapping'] = $allRequestData['field_mapping'];
            }
            if (array_key_exists('skip_rows', $allRequestData)) {
                $dataToUpdate['skip_rows'] = (int)$allRequestData['skip_rows'];
            }
            if (array_key_exists('sheet_number', $allRequestData)) {
                $dataToUpdate['sheet_number'] = (int)$allRequestData['sheet_number'];
            }
            if (array_key_exists('settings', $allRequestData)) {
                $dataToUpdate['settings'] = $allRequestData['settings'];
            }
            
            // Логируем данные перед обновлением
            Log::info('ShopGoogleSheetsAutoParsingController::update - Полученные данные:', [
                'id' => $id,
                'allRequestData_keys' => array_keys($allRequestData),
                'allRequestData' => $allRequestData
            ]);
            
            Log::info('ShopGoogleSheetsAutoParsingController::update - Данные для обновления:', [
                'id' => $id,
                'dataToUpdate' => $dataToUpdate,
                'dataToUpdate_keys' => array_keys($dataToUpdate),
                'skip_rows' => $dataToUpdate['skip_rows'] ?? 'не передано',
                'sheet_number' => $dataToUpdate['sheet_number'] ?? 'не передано',
                'field_mapping' => isset($dataToUpdate['field_mapping']) ? 'передан (тип: ' . gettype($dataToUpdate['field_mapping']) . ', размер: ' . (is_array($dataToUpdate['field_mapping']) ? count($dataToUpdate['field_mapping']) : 0) . ')' : 'не передан',
                'settings' => isset($dataToUpdate['settings']) ? 'передан (тип: ' . gettype($dataToUpdate['settings']) . ', размер: ' . (is_array($dataToUpdate['settings']) ? count($dataToUpdate['settings']) : 0) . ')' : 'не передан'
            ]);

            if (empty($dataToUpdate)) {
                Log::warning('ShopGoogleSheetsAutoParsingController::update - Нет данных для обновления');
                return response()->json([
                    'success' => false,
                    'message' => 'Нет данных для обновления'
                ], 400);
            }

            $autoParsing->update($dataToUpdate);
            
            // Логируем сохраненные данные
            Log::info('ShopGoogleSheetsAutoParsingController::update - Сохранено в БД:', [
                'id' => $autoParsing->id,
                'skip_rows' => $autoParsing->skip_rows,
                'sheet_number' => $autoParsing->sheet_number,
                'field_mapping_type' => gettype($autoParsing->field_mapping),
                'field_mapping_count' => is_array($autoParsing->field_mapping) ? count($autoParsing->field_mapping) : 0,
                'settings_type' => gettype($autoParsing->settings),
                'settings_count' => is_array($autoParsing->settings) ? count($autoParsing->settings) : 0
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Автопарсинг успешно обновлен',
                'data' => $autoParsing->fresh()
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('ShopGoogleSheetsAutoParsingController::update - Ошибка: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при обновлении автопарсинга',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Удалить автопарсинг
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $autoParsing = ShopGoogleSheetsAutoParsing::findOrFail($id);
            $autoParsing->delete();

            return response()->json([
                'success' => true,
                'message' => 'Автопарсинг успешно удален'
            ]);
        } catch (\Exception $e) {
            Log::error('ShopGoogleSheetsAutoParsingController::destroy - Ошибка: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при удалении автопарсинга',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
