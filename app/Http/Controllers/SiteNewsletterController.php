<?php

namespace App\Http\Controllers;

use App\Models\SiteNewsletterSubscriber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Log;

class SiteNewsletterController extends Controller
{
    /**
     * Подписка на новости
     */
    public function subscribe(Request $request)
    {
        // Rate limiting: максимум 5 попыток в минуту с одного IP
        $key = 'newsletter_subscribe:' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            return response()->json([
                'success' => false,
                'message' => "Слишком много попыток подписки. Попробуйте через {$seconds} секунд."
            ], 429);
        }

        // Валидация
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|max:255'
        ], [
            'email.required' => 'Email обязателен для заполнения',
            'email.email' => 'Введите корректный email адрес',
            'email.max' => 'Email не должен превышать 255 символов'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first('email')
            ], 422);
        }

        $email = strtolower(trim($request->email));

        try {
            // Проверяем, не подписан ли уже этот email
            $existingSubscriber = SiteNewsletterSubscriber::where('email', $email)->first();

            if ($existingSubscriber) {
                if ($existingSubscriber->is_active) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Этот email уже подписан на новости'
                    ], 409);
                } else {
                    // Реактивируем подписку
                    $existingSubscriber->update([
                        'is_active' => true,
                        'subscribed_at' => now(),
                        'unsubscribed_at' => null,
                        'ip_address' => $request->ip(),
                        'user_agent' => $request->userAgent()
                    ]);

                    Log::info('Newsletter subscription reactivated', [
                        'email' => $email,
                        'ip' => $request->ip()
                    ]);

                    return response()->json([
                        'success' => true,
                        'message' => 'Подписка успешно возобновлена!'
                    ]);
                }
            }

            // Создаем новую подписку
            SiteNewsletterSubscriber::create([
                'email' => $email,
                'is_active' => true,
                'subscribed_at' => now(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent()
            ]);

            Log::info('New newsletter subscription', [
                'email' => $email,
                'ip' => $request->ip()
            ]);

            // Увеличиваем счетчик попыток
            RateLimiter::hit($key, 60);

            return response()->json([
                'success' => true,
                'message' => 'Подписка на новости успешно оформлена!'
            ]);

        } catch (\Exception $e) {
            Log::error('Newsletter subscription error', [
                'email' => $email,
                'error' => $e->getMessage(),
                'ip' => $request->ip()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Произошла ошибка при оформлении подписки. Попробуйте позже.'
            ], 500);
        }
    }

    /**
     * Отписка от новостей
     */
    public function unsubscribe(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Неверный email адрес'
            ], 422);
        }

        $email = strtolower(trim($request->email));

        try {
            $subscriber = SiteNewsletterSubscriber::where('email', $email)->first();

            if (!$subscriber) {
                return response()->json([
                    'success' => false,
                    'message' => 'Подписка с таким email не найдена'
                ], 404);
            }

            if (!$subscriber->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'Вы уже отписаны от новостей'
                ], 409);
            }

            $subscriber->update([
                'is_active' => false,
                'unsubscribed_at' => now()
            ]);

            Log::info('Newsletter unsubscription', [
                'email' => $email,
                'ip' => $request->ip()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Вы успешно отписались от новостей'
            ]);

        } catch (\Exception $e) {
            Log::error('Newsletter unsubscription error', [
                'email' => $email,
                'error' => $e->getMessage(),
                'ip' => $request->ip()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Произошла ошибка при отписке. Попробуйте позже.'
            ], 500);
        }
    }

    /**
     * Получение списка подписчиков (для админки)
     */
    public function index(Request $request)
    {
        $query = SiteNewsletterSubscriber::query();

        // Фильтрация по статусу
        if ($request->has('status')) {
            if ($request->status === 'active') {
                $query->active();
            } elseif ($request->status === 'inactive') {
                $query->inactive();
            }
        }

        // Поиск по email
        if ($request->has('search')) {
            $query->where('email', 'like', '%' . $request->search . '%');
        }

        // Сортировка
        $sortBy = $request->get('sort_by', 'subscribed_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        // Пагинация
        $perPage = $request->get('per_page', 15);
        $subscribers = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $subscribers
        ]);
    }
}
