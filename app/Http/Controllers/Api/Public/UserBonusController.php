<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Services\BonusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserBonusController extends Controller
{
    protected BonusService $bonusService;

    public function __construct(BonusService $bonusService)
    {
        $this->bonusService = $bonusService;
    }

    /**
     * Получить баланс бонусов пользователя
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }

            \Log::info('UserBonusController: Getting bonus balance for user', ['user_id' => $user->id]);
            
            $balance = $this->bonusService->getBonusBalance($user);

            \Log::info('UserBonusController: Balance retrieved', ['balance' => $balance]);

            return response()->json([
                'success' => true,
                'data' => [
                    'balance' => $balance,
                    'user_id' => $user->id
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('UserBonusController: Error getting bonus balance', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения бонусов: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить историю транзакций бонусов
     */
    public function transactions(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }

            $transactions = $this->bonusService->getBonusTransactions($user);

            return response()->json([
                'success' => true,
                'data' => $transactions
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения истории бонусов: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Создать тестовые данные бонусов (только для отладки)
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }

            // Создаем или обновляем бонусы пользователя
            $userBonus = \App\Models\UserBonus::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'points' => 0,
                    'total_earned' => 0,
                    'total_spent' => 0
                ]
            );

            // Добавляем тестовые бонусы
            $testPoints = $request->input('points', 100);
            $userBonus->points += $testPoints;
            $userBonus->total_earned += $testPoints;
            $userBonus->save();

            // Создаем транзакцию
            $userBonus->transactions()->create([
                'type' => 'earn',
                'points' => $testPoints,
                'description' => 'Тестовые бонусы для отладки',
                'metadata' => json_encode(['test' => true])
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Тестовые бонусы созданы',
                'data' => [
                    'balance' => $userBonus->points,
                    'added_points' => $testPoints
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('UserBonusController: Error creating test data', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания тестовых данных: ' . $e->getMessage()
            ], 500);
        }
    }
}
