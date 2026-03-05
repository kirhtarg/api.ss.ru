<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserBonus;
use App\Models\UserBonusTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class UserBonusController extends Controller
{
    /**
     * Получить бонусы пользователя
     */
    public function show($userId): JsonResponse
    {
        try {
            $user = User::find($userId);

            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Пользователь не найден',
                ], 404);
            }

            // Получаем или создаем бонусы для пользователя
            $userBonus = UserBonus::getOrCreateForUser($user->id);

            return response()->json([
                'success' => true,
                'data' => [
                    'points' => $userBonus->points,
                    'balance' => $userBonus->points,
                    'total_earned' => $userBonus->total_earned,
                    'total_spent' => $userBonus->total_spent,
                    'available_points' => $userBonus->getAvailablePoints(),
                    'user_id' => $user->id,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Ошибка получения бонусов пользователя: '.$e->getMessage(), [
                'user_id' => $userId,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения бонусов: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Обновить бонусы пользователя
     */
    public function update(Request $request, $userId): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'points' => 'required|integer|min:0',
                'description' => 'nullable|string|max:500',
                'adjustment_type' => 'nullable|in:set,add,subtract',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $user = User::find($userId);

            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Пользователь не найден',
                ], 404);
            }

            // Получаем или создаем бонусы для пользователя
            $userBonus = UserBonus::getOrCreateForUser($user->id);
            $oldPoints = $userBonus->points;
            $newPoints = (int) $request->points;
            $adjustmentType = $request->input('adjustment_type', 'set');
            $description = $request->input('description', 'Ручное изменение бонусов администратором');

            DB::beginTransaction();

            try {
                // Вычисляем изменение в зависимости от типа
                $pointsChange = 0;
                switch ($adjustmentType) {
                    case 'set':
                        // Устанавливаем точное значение
                        $pointsChange = $newPoints - $oldPoints;
                        $userBonus->points = $newPoints;
                        break;
                    case 'add':
                        // Добавляем к текущему значению
                        $pointsChange = $newPoints;
                        $userBonus->points += $pointsChange;
                        $userBonus->total_earned += $pointsChange;
                        break;
                    case 'subtract':
                        // Вычитаем из текущего значения
                        if ($userBonus->points < $newPoints) {
                            throw new \Exception('Недостаточно бонусов для списания. Текущий баланс: '.$userBonus->points);
                        }
                        $pointsChange = -$newPoints;
                        $userBonus->points += $pointsChange;
                        $userBonus->total_spent += abs($pointsChange);
                        break;
                }

                // Обновляем статистику только для операций add/subtract
                if ($adjustmentType === 'set') {
                    // При установке точного значения обновляем статистику в зависимости от изменения
                    if ($pointsChange > 0) {
                        $userBonus->total_earned += $pointsChange;
                    } elseif ($pointsChange < 0) {
                        $userBonus->total_spent += abs($pointsChange);
                    }
                }

                $userBonus->save();

                // Создаем транзакцию для истории
                if ($pointsChange != 0) {
                    $transactionType = $pointsChange > 0 ? 'earn' : 'spend';
                    if ($adjustmentType === 'set' && $pointsChange < 0) {
                        $transactionType = 'spend';
                    }

                    UserBonusTransaction::create([
                        'user_id' => $user->id,
                        'type' => $transactionType,
                        'points' => $pointsChange,
                        'description' => $description,
                        'order_id' => null,
                        'metadata' => [
                            'admin_adjustment' => true,
                            'adjustment_type' => $adjustmentType,
                            'old_points' => $oldPoints,
                            'new_points' => $userBonus->points,
                        ],
                    ]);
                }

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Бонусы успешно обновлены',
                    'data' => [
                        'points' => $userBonus->points,
                        'balance' => $userBonus->points,
                        'total_earned' => $userBonus->total_earned,
                        'total_spent' => $userBonus->total_spent,
                        'available_points' => $userBonus->getAvailablePoints(),
                        'user_id' => $user->id,
                        'points_change' => $pointsChange,
                    ],
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            Log::error('Ошибка обновления бонусов пользователя: '.$e->getMessage(), [
                'user_id' => $userId,
                'request_data' => $request->all(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления бонусов: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить историю транзакций бонусов пользователя
     */
    public function transactions($userId): JsonResponse
    {
        try {
            $user = User::find($userId);

            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Пользователь не найден',
                ], 404);
            }

            $transactions = UserBonusTransaction::where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->paginate(50);

            return response()->json([
                'success' => true,
                'data' => $transactions,
            ]);
        } catch (\Exception $e) {
            Log::error('Ошибка получения истории транзакций: '.$e->getMessage(), [
                'user_id' => $userId,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения истории транзакций: '.$e->getMessage(),
            ], 500);
        }
    }
}
