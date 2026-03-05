<?php

namespace App\Http\Controllers;

use App\Models\SiteMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        // Загружаем связь с товаром
        $query->with('good:id,name,slug');

        $messages = $query->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $messages,
        ]);
    }

    /**
     * Создать новое сообщение
     */
    public function store(Request $request): JsonResponse
    {
        $type = $request->type ?? 'callback';

        // Правила валидации в зависимости от типа
        $rules = [
            'name' => 'required|string|max:255',
            'type' => 'required|in:callback,message,found_cheaper',
        ];

        if ($type === 'found_cheaper') {
            $rules['email'] = 'required|email|max:255';
            $rules['good_link'] = 'required|url|max:500';
            $rules['good_price'] = 'nullable|numeric|min:0';
            $rules['phone'] = 'nullable|string|max:20';
            $rules['message'] = 'nullable|string|max:1000';
            $rules['good_id'] = 'nullable|integer|exists:shop_goods,id';
        } else {
            $rules['phone'] = 'nullable|string|max:20';
            $rules['email'] = 'nullable|email|max:255';
            $rules['message'] = 'nullable|string|max:1000';
            $rules['good_id'] = 'nullable|integer|exists:shop_goods,id';
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Проверяем таймаут между сообщениями (1 минута)
        $phone = $request->phone;
        $email = $request->email;
        $ipAddress = $request->ip();

        // Проверяем по номеру телефона, email ИЛИ по IP-адресу
        $lastMessage = SiteMessage::where(function ($query) use ($phone, $email, $ipAddress) {
            if ($phone) {
                $query->where('phone', $phone);
            }
            if ($email) {
                $query->orWhere('email', $email);
            }
            $query->orWhere('ip_address', $ipAddress);
        })
            ->where('created_at', '>=', now()->subMinute())
            ->first();

        if ($lastMessage) {
            return response()->json([
                'success' => false,
                'message' => 'Пожалуйста, подождите минуту перед отправкой следующего сообщения',
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
            \Illuminate\Support\Facades\Log::error('Site message notification error: '.$e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Сообщение успешно отправлено',
            'data' => $message,
        ], 201);
    }

    /**
     * Получить конкретное сообщение
     */
    public function show(int $id): JsonResponse
    {
        $message = SiteMessage::find($id);

        if (! $message) {
            return response()->json([
                'success' => false,
                'message' => 'Сообщение не найдено',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $message,
        ]);
    }

    /**
     * Обновить сообщение
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $message = SiteMessage::find($id);

        if (! $message) {
            return response()->json([
                'success' => false,
                'message' => 'Сообщение не найдено',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|string|max:20',
            'email' => 'sometimes|email|max:255',
            'message' => 'nullable|string|max:1000',
            'type' => 'sometimes|in:callback,message,found_cheaper',
            'is_processed' => 'sometimes|boolean',
            'good_link' => 'sometimes|url|max:500',
            'good_price' => 'sometimes|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        $message->update($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Сообщение обновлено',
            'data' => $message,
        ]);
    }

    /**
     * Удалить сообщение
     */
    public function destroy(int $id): JsonResponse
    {
        $message = SiteMessage::find($id);

        if (! $message) {
            return response()->json([
                'success' => false,
                'message' => 'Сообщение не найдено',
            ], 404);
        }

        $message->delete();

        return response()->json([
            'success' => true,
            'message' => 'Сообщение удалено',
        ]);
    }

    /**
     * Отметить как обработанное
     */
    public function markAsProcessed(int $id): JsonResponse
    {
        $message = SiteMessage::find($id);

        if (! $message) {
            return response()->json([
                'success' => false,
                'message' => 'Сообщение не найдено',
            ], 404);
        }

        $message->update([
            'is_processed' => true,
            'processed_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Сообщение отмечено как обработанное',
            'data' => $message,
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
            'found_cheaper' => SiteMessage::foundCheaper()->count(),
            'unprocessed' => SiteMessage::unprocessed()->count(),
            'processed' => SiteMessage::processed()->count(),
            'today' => SiteMessage::whereDate('created_at', today())->count(),
            'this_week' => SiteMessage::whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])->count(),
            'this_month' => SiteMessage::whereMonth('created_at', now()->month)->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }
}
