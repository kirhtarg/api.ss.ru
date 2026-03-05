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
            if (! $user) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }

            $balance = $this->bonusService->getBonusBalance($user);

            return response()->json([
                'success' => true,
                'data' => [
                    'balance' => $balance,
                    'user_id' => $user->id,
                ],
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения бонусов: '.$e->getMessage(),
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
            if (! $user) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }

            $transactions = $this->bonusService->getBonusTransactions($user);

            return response()->json([
                'success' => true,
                'data' => $transactions,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения истории бонусов: '.$e->getMessage(),
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
            if (! $user) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }

            // Создаем или обновляем бонусы пользователя
            $userBonus = \App\Models\UserBonus::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'points' => 0,
                    'total_earned' => 0,
                    'total_spent' => 0,
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
                'metadata' => json_encode(['test' => true]),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Тестовые бонусы созданы',
                'data' => [
                    'balance' => $userBonus->points,
                    'added_points' => $testPoints,
                ],
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания тестовых данных: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Добавляет CORS заголовки к ответу
     */
    private function addCorsHeaders($response)
    {
        return $response
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Methods', 'POST, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN, X-XSRF-TOKEN')
            ->header('Access-Control-Allow-Credentials', 'true');
    }

    /**
     * Списывает бонусы с баланса пользователя
     */
    public function deductBonuses(Request $request): JsonResponse
    {
        try {
            // Отладочная информация
            $authHeader = $request->header('Authorization');
            $token = $request->bearerToken();

            $user = Auth::user();
            if (! $user) {
                // Попробуем авторизовать пользователя вручную
                if ($token) {
                    $user = \Laravel\Sanctum\PersonalAccessToken::findToken($token)?->tokenable;
                }

                if (! $user) {
                    return $this->addCorsHeaders(response()->json([
                        'success' => false,
                        'message' => 'Пользователь не авторизован',
                        'debug' => [
                            'auth_header' => $authHeader,
                            'bearer_token' => $token ? 'present' : 'missing',
                            'user_id' => $user ? $user->id : 'null',
                        ],
                    ], 401));
                }
            }

            $points = $request->input('points');
            if (! $points || $points <= 0) {
                return $this->addCorsHeaders(response()->json([
                    'success' => false,
                    'message' => 'Некорректное количество бонусов',
                ], 400));
            }

            // Используем BonusService для списания бонусов
            $result = $this->bonusService->deductBonuses($user->id, $points);

            if ($result['success']) {
                return $this->addCorsHeaders(response()->json([
                    'success' => true,
                    'message' => 'Бонусы успешно списаны',
                    'remaining_points' => $result['remaining_points'],
                ]));
            } else {
                return $this->addCorsHeaders(response()->json([
                    'success' => false,
                    'message' => $result['message'],
                ], 400));
            }

        } catch (\Exception $e) {
            return $this->addCorsHeaders(response()->json([
                'success' => false,
                'message' => 'Ошибка при списании бонусов: '.$e->getMessage(),
            ], 500));
        }
    }
}
