<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\TelegramNotification;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class TelegramNotificationController extends Controller
{
    private $telegramService;

    public function __construct(TelegramService $telegramService)
    {
        $this->telegramService = $telegramService;
    }

    /**
     * Получить список уведомлений
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = TelegramNotification::with('order');

            // Фильтрация по статусу
            if ($request->has('status')) {
                $query->where('status', $request->get('status'));
            }

            // Фильтрация по типу
            if ($request->has('type')) {
                $query->where('type', $request->get('type'));
            }

            // Фильтрация по заказу
            if ($request->has('order_id')) {
                $query->where('order_id', $request->get('order_id'));
            }

            // Поиск
            if ($request->has('search') && $request->get('search')) {
                $search = $request->get('search');
                $query->where(function($q) use ($search) {
                    $q->where('message', 'like', "%{$search}%")
                      ->orWhere('chat_id', 'like', "%{$search}%")
                      ->orWhereHas('order', function($orderQuery) use ($search) {
                          $orderQuery->where('order_number', 'like', "%{$search}%");
                      });
                });
            }

            // Сортировка
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Пагинация
            $perPage = $request->get('per_page', 15);
            $notifications = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $notifications->items(),
                'pagination' => [
                    'current_page' => $notifications->currentPage(),
                    'last_page' => $notifications->lastPage(),
                    'per_page' => $notifications->perPage(),
                    'total' => $notifications->total()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения уведомлений',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить уведомление по ID
     */
    public function show(int $id): JsonResponse
    {
        try {
            $notification = TelegramNotification::with('order')->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $notification
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Уведомление не найдено',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Отправить тестовое уведомление
     */
    public function sendTest(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'chat_id' => 'required|string',
                'message' => 'required|string|max:4000'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            $result = $this->telegramService->sendMessage(
                $request->get('chat_id'),
                $request->get('message')
            );

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => 'Тестовое уведомление отправлено успешно',
                    'data' => $result
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка отправки уведомления',
                    'error' => $result['error'] ?? 'Unknown error'
                ], 400);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка отправки тестового уведомления',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Повторить отправку уведомления
     */
    public function retry(int $id): JsonResponse
    {
        try {
            $notification = TelegramNotification::findOrFail($id);

            if ($notification->status === 'sent') {
                return response()->json([
                    'success' => false,
                    'message' => 'Уведомление уже отправлено'
                ], 400);
            }

            if ($notification->attempts >= 3) {
                return response()->json([
                    'success' => false,
                    'message' => 'Превышено максимальное количество попыток'
                ], 400);
            }

            $result = $this->telegramService->sendMessage(
                $notification->chat_id,
                $notification->message
            );

            if ($result['success']) {
                $notification->markAsSent();
                return response()->json([
                    'success' => true,
                    'message' => 'Уведомление отправлено успешно'
                ]);
            } else {
                $notification->markAsFailed($result['error'] ?? 'Unknown error');
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка отправки уведомления',
                    'error' => $result['error'] ?? 'Unknown error'
                ], 400);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка повторной отправки уведомления',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Обработать все ожидающие уведомления
     */
    public function processPending(): JsonResponse
    {
        try {
            $processed = $this->telegramService->processPendingNotifications();

            return response()->json([
                'success' => true,
                'message' => "Обработано уведомлений: {$processed}",
                'processed' => $processed
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обработки уведомлений',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить статистику уведомлений
     */
    public function stats(): JsonResponse
    {
        try {
            $stats = [
                'total' => TelegramNotification::count(),
                'pending' => TelegramNotification::pending()->count(),
                'sent' => TelegramNotification::sent()->count(),
                'failed' => TelegramNotification::failed()->count(),
                'by_type' => TelegramNotification::selectRaw('type, count(*) as count')
                    ->groupBy('type')
                    ->get()
                    ->pluck('count', 'type'),
                'by_status' => TelegramNotification::selectRaw('status, count(*) as count')
                    ->groupBy('status')
                    ->get()
                    ->pluck('count', 'status')
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения статистики',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Удалить уведомление
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $notification = TelegramNotification::findOrFail($id);
            $notification->delete();

            return response()->json([
                'success' => true,
                'message' => 'Уведомление удалено успешно'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления уведомления',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
