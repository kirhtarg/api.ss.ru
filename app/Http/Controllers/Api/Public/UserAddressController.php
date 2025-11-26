<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\ShopUserAddress;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class UserAddressController extends Controller
{
    /**
     * Получить все адреса пользователя
     */
    public function index(): JsonResponse
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Пользователь не авторизован'
                ], 401);
            }

            $addresses = ShopUserAddress::where('user_id', $user->id)
                ->orderBy('name', 'asc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $addresses
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении адресов: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Создать новый адрес
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Пользователь не авторизован'
                ], 401);
            }

            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'city' => 'required|string|max:255',
                'postal_code' => 'nullable|string|max:10',
                'street' => 'required|string|max:255',
                'house' => 'required|string|max:50',
                'apartment' => 'nullable|string|max:50',
                'entrance' => 'nullable|string|max:50',
                'intercom' => 'nullable|string|max:50',
                'comment' => 'nullable|string|max:1000',
                'is_default' => 'boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            $data = $request->all();
            $data['user_id'] = $user->id;

            // Если устанавливается как адрес по умолчанию, сбрасываем флаг у других адресов
            if ($data['is_default'] ?? false) {
                ShopUserAddress::where('user_id', $user->id)
                    ->update(['is_default' => false]);
            }

            $address = ShopUserAddress::create($data);

            return response()->json([
                'success' => true,
                'message' => 'Адрес успешно создан',
                'data' => $address
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при создании адреса: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Обновить адрес
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Пользователь не авторизован'
                ], 401);
            }

            $address = ShopUserAddress::where('id', $id)
                ->where('user_id', $user->id)
                ->first();

            if (!$address) {
                return response()->json([
                    'success' => false,
                    'message' => 'Адрес не найден'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'city' => 'required|string|max:255',
                'postal_code' => 'nullable|string|max:10',
                'street' => 'required|string|max:255',
                'house' => 'required|string|max:50',
                'apartment' => 'nullable|string|max:50',
                'entrance' => 'nullable|string|max:50',
                'intercom' => 'nullable|string|max:50',
                'comment' => 'nullable|string|max:1000',
                'is_default' => 'boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            $data = $request->all();

            // Если устанавливается как адрес по умолчанию, сбрасываем флаг у других адресов
            if ($data['is_default'] ?? false) {
                ShopUserAddress::where('user_id', $user->id)
                    ->where('id', '!=', $id)
                    ->update(['is_default' => false]);
            }

            $address->update($data);

            return response()->json([
                'success' => true,
                'message' => 'Адрес успешно обновлен',
                'data' => $address
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при обновлении адреса: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Удалить адрес
     */
    public function destroy($id): JsonResponse
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Пользователь не авторизован'
                ], 401);
            }

            $address = ShopUserAddress::where('id', $id)
                ->where('user_id', $user->id)
                ->first();

            if (!$address) {
                return response()->json([
                    'success' => false,
                    'message' => 'Адрес не найден'
                ], 404);
            }

            $address->delete();

            return response()->json([
                'success' => true,
                'message' => 'Адрес успешно удален'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при удалении адреса: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Установить адрес по умолчанию
     */
    public function setDefault($id): JsonResponse
    {
        try {
            \Log::info('setDefault called', ['address_id' => $id]);
            
            $user = Auth::user();
            if (!$user) {
                \Log::error('User not authenticated');
                return response()->json([
                    'success' => false,
                    'message' => 'Пользователь не авторизован'
                ], 401);
            }

            \Log::info('User authenticated', ['user_id' => $user->id]);

            $address = ShopUserAddress::where('id', $id)
                ->where('user_id', $user->id)
                ->first();

            if (!$address) {
                \Log::error('Address not found', ['address_id' => $id, 'user_id' => $user->id]);
                return response()->json([
                    'success' => false,
                    'message' => 'Адрес не найден'
                ], 404);
            }

            \Log::info('Address found', ['address' => $address->toArray()]);

            // Сбрасываем флаг у всех адресов пользователя
            $updatedCount = ShopUserAddress::where('user_id', $user->id)
                ->update(['is_default' => false]);
            
            \Log::info('Reset default flags', ['updated_count' => $updatedCount]);

            // Устанавливаем выбранный адрес как адрес по умолчанию
            $address->is_default = true;
            $address->save();
            
            \Log::info('Set address as default', ['address_id' => $id]);
            
            // Обновляем объект из базы данных
            $address->refresh();
            
            \Log::info('Address after update', ['address' => $address->toArray()]);

            return response()->json([
                'success' => true,
                'message' => 'Адрес установлен по умолчанию',
                'data' => $address
            ]);
        } catch (\Exception $e) {
            \Log::error('Error in setDefault', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'address_id' => $id
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при установке адреса по умолчанию: ' . $e->getMessage()
            ], 500);
        }
    }
}