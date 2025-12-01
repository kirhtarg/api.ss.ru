<?php

namespace App\Http\Controllers;

use App\Models\SiteMessage;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class SiteMessageController extends Controller
{
    /**
     * Получить список сообщений
     */
    public function index(Request $request): JsonResponse
    {
        $query = SiteMessage::query();

        // Фильтр по типу
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        // Фильтр по статусу обработки
        if ($request->has('is_processed')) {
            $query->where('is_processed', $request->boolean('is_processed'));
        }

        // Сортировка
        $query->orderBy('created_at', 'desc');

        $messages = $query->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $messages
        ]);
    }

    /**
     * Создать новое сообщение
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'message' => 'nullable|string|max:1000',
            'type' => 'required|in:callback,message'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        // Проверяем таймаут между сообщениями (1 минута)
        $phone = $request->phone;
        $ipAddress = $request->ip();
        
        // Проверяем по номеру телефона ИЛИ по IP-адресу
        $lastMessage = SiteMessage::where(function($query) use ($phone, $ipAddress) {
            $query->where('phone', $phone)
                  ->orWhere('ip_address', $ipAddress);
        })
        ->where('created_at', '>=', now()->subMinute())
        ->first();

        if ($lastMessage) {
            return response()->json([
                'success' => false,
                'message' => 'Пожалуйста, подождите минуту перед отправкой следующего сообщения'
            ], 429);
        }

        $messageData = $request->all();
        $messageData['ip_address'] = $ipAddress;
        
        $message = SiteMessage::create($messageData);

        // Отправляем уведомления о новом сообщении на сайте
        try {
            $notificationService = app(\App\Services\NotificationService::class);
            $notificationService->notifySiteMessage($message);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Site message notification error: ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Сообщение успешно отправлено',
            'data' => $message
        ], 201);
    }

    /**
     * Получить конкретное сообщение
     */
    public function show(int $id): JsonResponse
    {
        $message = SiteMessage::find($id);

        if (!$message) {
            return response()->json([
                'success' => false,
                'message' => 'Сообщение не найдено'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $message
        ]);
    }

    /**
     * Обновить сообщение
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $message = SiteMessage::find($id);

        if (!$message) {
            return response()->json([
                'success' => false,
                'message' => 'Сообщение не найдено'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|string|max:20',
            'message' => 'nullable|string|max:1000',
            'type' => 'sometimes|in:callback,message',
            'is_processed' => 'sometimes|boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        $message->update($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Сообщение обновлено',
            'data' => $message
        ]);
    }

    /**
     * Удалить сообщение
     */
    public function destroy(int $id): JsonResponse
    {
        $message = SiteMessage::find($id);

        if (!$message) {
            return response()->json([
                'success' => false,
                'message' => 'Сообщение не найдено'
            ], 404);
        }

        $message->delete();

        return response()->json([
            'success' => true,
            'message' => 'Сообщение удалено'
        ]);
    }

    /**
     * Отметить как обработанное
     */
    public function markAsProcessed(int $id): JsonResponse
    {
        $message = SiteMessage::find($id);

        if (!$message) {
            return response()->json([
                'success' => false,
                'message' => 'Сообщение не найдено'
            ], 404);
        }

        $message->update([
            'is_processed' => true,
            'processed_at' => now()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Сообщение отмечено как обработанное',
            'data' => $message
        ]);
    }

    /**
     * Получить статистику
     */
    public function stats(): JsonResponse
    {
        $stats = [
            'total' => SiteMessage::count(),
            'callbacks' => SiteMessage::callbacks()->count(),
            'messages' => SiteMessage::messages()->count(),
            'unprocessed' => SiteMessage::unprocessed()->count(),
            'processed' => SiteMessage::processed()->count(),
            'today' => SiteMessage::whereDate('created_at', today())->count(),
            'this_week' => SiteMessage::whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])->count(),
            'this_month' => SiteMessage::whereMonth('created_at', now()->month)->count()
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }
}
