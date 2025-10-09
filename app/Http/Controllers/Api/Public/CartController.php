<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\ShopGood;
use App\Models\ShopGoodVariation;
use App\Models\ShopOrder;
use App\Models\ShopOrderStatus;
use App\Models\ShopCartItem;
use App\Models\ShopPreorder;
use App\Models\User;
use App\Models\Setting;
use App\Services\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class CartController extends Controller
{
    /**
     * Получить пользователя из токена Authorization
     */
    private function getUserFromToken(Request $request): ?User
    {
        $token = $request->bearerToken();
        if (!$token) {
            return null;
        }

        $personalAccessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
        if (!$personalAccessToken) {
            return null;
        }

        return $personalAccessToken->tokenable;
    }

    /**
     * Получить настройку режима показа товаров при нулевых остатках
     */
    private function getShopShowGoodMode(): int
    {
        $setting = Setting::where('key', 'shop_show_good_mode')->first();
        return $setting ? (int)$setting->value : 2; // По умолчанию режим 2
    }

    /**
     * Получить корзину пользователя
     */
    public function getCart(Request $request): JsonResponse
    {
        try {
            $user = $this->getUserFromToken($request);
            $sessionId = $this->getSessionId($request);
            
            $cartItems = $this->getCartItems($user, $sessionId);
            $cart = $this->formatCartData($cartItems);

            return response()->json([
                'success' => true,
                'data' => $cart
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения корзины: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Добавить товар в корзину
     */
    public function addToCart(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'good_id' => 'required|integer|exists:shop_goods,id',
                'variation_id' => 'nullable|integer|exists:shop_good_variations,id',
                'quantity' => 'required|integer|min:1|max:99'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            $goodId = $request->get('good_id');
            $variationId = $request->get('variation_id');
            $quantity = $request->get('quantity');

            // Получаем режим показа товаров при нулевых остатках
            $showGoodMode = $this->getShopShowGoodMode();

            // Проверяем существование товара
            $good = ShopGood::where('id', $goodId)
                ->where('is_active', true)
                ->first();

            if (!$good) {
                return response()->json([
                    'success' => false,
                    'message' => 'Товар не найден или неактивен'
                ], 404);
            }

            // Если указана вариация, проверяем её
            if ($variationId) {
                $variation = ShopGoodVariation::where('id', $variationId)
                    ->where('good_id', $goodId)
                    ->where('is_active', true)
                    ->first();

                if (!$variation) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Вариация не найдена или неактивна'
                    ], 404);
                }

                // Проверяем остатки в зависимости от режима
                $stockQuantity = $variation->stock_quantity ?? 0;
                if ($showGoodMode === 1 && $stockQuantity <= 0) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Товар недоступен для заказа'
                    ], 400);
                } elseif ($showGoodMode === 2 && $stockQuantity <= 0) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Товар временно отсутствует на складе'
                    ], 400);
                } elseif ($showGoodMode === 3) {
                    // Режим 3: игнорируем остатки - разрешаем добавление
                } elseif ($showGoodMode === 4) {
                    // Режим 4: предзаказ - проверяем авторизацию
                    $user = $this->getUserFromToken($request);
                    if (!$user) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Для предзаказа необходимо авторизоваться',
                            'requires_auth' => true
                        ], 401);
                    }
                } else {
                    // Обычная проверка остатков
                    if ($stockQuantity < $quantity) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Недостаточно товара на складе'
                        ], 400);
                    }
                }
            } else {
                // Проверяем остатки основного товара в зависимости от режима
                $stockQuantity = $good->stock_quantity ?? 0;
                if ($showGoodMode === 1 && $stockQuantity <= 0) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Товар недоступен для заказа'
                    ], 400);
                } elseif ($showGoodMode === 2 && $stockQuantity <= 0) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Товар временно отсутствует на складе'
                    ], 400);
                } elseif ($showGoodMode === 3) {
                    // Режим 3: игнорируем остатки - разрешаем добавление
                } elseif ($showGoodMode === 4) {
                    // Режим 4: предзаказ - проверяем авторизацию
                    $user = $this->getUserFromToken($request);
                    if (!$user) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Для предзаказа необходимо авторизоваться',
                            'requires_auth' => true
                        ], 401);
                    }
                } else {
                    // Обычная проверка остатков
                    if ($stockQuantity < $quantity) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Недостаточно товара на складе'
                        ], 400);
                    }
                }
            }

            $user = $this->getUserFromToken($request);
            $sessionId = $this->getSessionId($request);

            // Определяем цену
            $price = $variationId ? $variation->final_price : $good->final_price;
            $total = $price * $quantity;

            // Ищем существующий элемент корзины
            $existingItem = $this->findCartItem($user, $sessionId, $goodId, $variationId);

            if ($existingItem) {
                // Обновляем количество существующего товара
                $existingItem->quantity += $quantity;
                $existingItem->total = $existingItem->price * $existingItem->quantity;
                // Обновляем variation_name с параметрами
                if ($variationId) {
                    $existingItem->variation_name = $this->formatVariationProperties($variation);
                }
                $existingItem->save();
            } else {
                // Создаем новый элемент корзины
                ShopCartItem::create([
                    'user_id' => $user ? $user->id : null,
                    'session_id' => $user ? null : $sessionId,
                    'good_id' => $goodId,
                    'variation_id' => $variationId,
                    'quantity' => $quantity,
                    'price' => $price,
                    'total' => $total,
                    'good_name' => $good->name,
                    'variation_name' => $variationId ? $this->formatVariationProperties($variation) : null,
                    'good_sku' => $variationId ? $variation->sku : $good->sku,
                    'good_image' => $this->getGoodImage($good, $variationId)
                ]);
            }

            // Получаем обновленную корзину
            $cartItems = $this->getCartItems($user, $sessionId);
            $cart = $this->formatCartData($cartItems);

            return response()->json([
                'success' => true,
                'message' => 'Товар добавлен в корзину',
                'data' => $cart
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка добавления в корзину: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Обновить количество товара в корзине
     */
    public function updateCartItem(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'good_id' => 'required|integer',
                'variation_id' => 'nullable|integer',
                'quantity' => 'required|integer|min:0|max:99'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            $goodId = $request->get('good_id');
            $variationId = $request->get('variation_id');
            $quantity = $request->get('quantity');

            $user = $this->getUserFromToken($request);
            $sessionId = $this->getSessionId($request);

            $cartItem = $this->findCartItem($user, $sessionId, $goodId, $variationId);

            if (!$cartItem) {
                return response()->json([
                    'success' => false,
                    'message' => 'Товар не найден в корзине'
                ], 404);
            }

            if ($quantity <= 0) {
                // Удаляем товар из корзины
                $cartItem->delete();
            } else {
                // Обновляем количество
                $cartItem->quantity = $quantity;
                $cartItem->total = $cartItem->price * $quantity;
                $cartItem->save();
            }

            // Получаем обновленную корзину
            $cartItems = $this->getCartItems($user, $sessionId);
            $cart = $this->formatCartData($cartItems);

            return response()->json([
                'success' => true,
                'message' => 'Корзина обновлена',
                'data' => $cart
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления корзины: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Удалить товар из корзины
     */
    public function removeFromCart(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'good_id' => 'required|integer',
                'variation_id' => 'nullable|integer'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            $goodId = $request->get('good_id');
            $variationId = $request->get('variation_id');

            $user = $this->getUserFromToken($request);
            $sessionId = $this->getSessionId($request);

            $cartItem = $this->findCartItem($user, $sessionId, $goodId, $variationId);

            if (!$cartItem) {
                return response()->json([
                    'success' => false,
                    'message' => 'Товар не найден в корзине'
                ], 404);
            }

            $cartItem->delete();

            // Получаем обновленную корзину
            $cartItems = $this->getCartItems($user, $sessionId);
            $cart = $this->formatCartData($cartItems);

            return response()->json([
                'success' => true,
                'message' => 'Товар удален из корзины',
                'data' => $cart
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления из корзины: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Очистить корзину
     */
    public function clearCart(Request $request): JsonResponse
    {
        try {
            $user = $this->getUserFromToken($request);
            $sessionId = $this->getSessionId($request);

            $query = ShopCartItem::active();
            
            if ($user) {
                $query->forUser($user->id);
            } else {
                $query->forSession($sessionId);
            }
            
            $query->delete();

            return response()->json([
                'success' => true,
                'message' => 'Корзина очищена',
                'data' => ['items' => [], 'subtotal' => 0, 'total_amount' => 0, 'total_quantity' => 0]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка очистки корзины: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Создать заказ из корзины
     */
    public function createOrder(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'customer_name' => 'required|string|max:255',
                'customer_email' => 'required|email|max:255',
                'customer_phone' => 'required|string|max:20',
                'payment_method' => 'required|string|max:100',
                'shipping_method' => 'required|string|max:100',
                'shipping_address' => 'nullable|string',
                'notes' => 'nullable|string'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = $this->getUserFromToken($request);
            $sessionId = $this->getSessionId($request);

            $cartItems = $this->getCartItems($user, $sessionId);
            $cart = $this->formatCartData($cartItems);

            if (empty($cart['items'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Корзина пуста'
                ], 400);
            }

            // Получаем статус "Обрабатывается" по умолчанию
            $pendingStatus = \App\Models\ShopOrderStatus::where('name', 'Обрабатывается')->first();
            if (!$pendingStatus) {
                // Если статус не найден, создаем его
                $pendingStatus = \App\Models\ShopOrderStatus::create([
                    'name' => 'Обрабатывается',
                    'color' => 'yellow',
                    'is_active' => true,
                    'sort_order' => 10,
                ]);
            }

            // Создаем заказ
            $order = ShopOrder::create([
                'user_id' => $user ? $user->id : null,
                'status_id' => $pendingStatus->id,
                'customer_name' => $request->get('customer_name'),
                'customer_email' => $request->get('customer_email'),
                'customer_phone' => $request->get('customer_phone'),
                'items' => array_values($cart['items']),
                'subtotal' => $cart['subtotal'],
                'discount_amount' => 0, // Пока без скидок
                'total_amount' => $cart['total_amount'],
                'total_quantity' => $cart['total_quantity'],
                'payment_method' => $request->get('payment_method'),
                'shipping_method' => $request->get('shipping_method'),
                'shipping_address' => $request->get('shipping_address'),
                'notes' => $request->get('notes'),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent()
            ]);

            // Очищаем корзину после создания заказа
            $query = ShopCartItem::active();
            
            if ($user) {
                $query->forUser($user->id);
            } else {
                $query->forSession($sessionId);
            }
            
            $query->delete();

            // Отправляем уведомления в Telegram
            try {
                $telegramService = app(TelegramService::class);
                
                // Уведомление администратору
                $telegramService->notifyAdminNewOrder($order);
                
                // Уведомление клиенту (если указан chat_id)
                $customerChatId = $request->get('telegram_chat_id');
                if ($customerChatId) {
                    $customerMessage = "✅ <b>Заказ #{$order->order_number} принят</b>\n\n";
                    $customerMessage .= "Спасибо за ваш заказ! Мы получили вашу заявку и в ближайшее время свяжемся с вами для подтверждения.\n\n";
                    $customerMessage .= "💰 <b>Сумма заказа:</b> " . number_format($order->total_amount, 0, ',', ' ') . " ₽\n";
                    $customerMessage .= "📦 <b>Товаров:</b> {$order->total_quantity} шт.\n\n";
                    $customerMessage .= "📞 <b>Наш телефон:</b> +7 (999) 123-45-67\n";
                    $customerMessage .= "📧 <b>Email:</b> info@skateandsnow.ru";
                    
                    $telegramService->notifyCustomer(
                        $customerChatId,
                        'order_created',
                        $order->id,
                        $customerMessage
                    );
                }
            } catch (\Exception $e) {
                // Логируем ошибку, но не прерываем создание заказа
                \Log::error('Telegram notification error: ' . $e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'Заказ создан успешно',
                'data' => [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания заказа: ' . $e->getMessage()
            ], 500);
        }
    }


    /**
     * Получить изображение товара
     */
    private function getGoodImage(ShopGood $good, ?int $variationId = null): ?string
    {
        // Если есть вариация, ищем её изображения
        if ($variationId) {
            $variationImage = \App\Models\ShopGoodImage::whereNull('good_id')
                ->where('variation_id', $variationId)
                ->where('is_main', true)
                ->first();

            if ($variationImage) {
                return $this->getImageUrl($variationImage->file_path);
            }
        }

        // Ищем основное изображение товара
        $mainImage = $good->images()->where('is_main', true)->first();
        if ($mainImage) {
            return $this->getImageUrl($mainImage->file_path);
        }

        // Берем первое изображение товара
        $firstImage = $good->images()->first();
        if ($firstImage) {
            return $this->getImageUrl($firstImage->file_path);
        }

        return null;
    }

    /**
     * Получить ID сессии для незарегистрированных пользователей
     */
    private function getSessionId(Request $request): string
    {
        $sessionId = $request->header('X-Session-ID');
        
        if (!$sessionId) {
            // Генерируем новый ID сессии
            $sessionId = 'cart_' . uniqid() . '_' . time();
        }
        
        return $sessionId;
    }

    /**
     * Получить элементы корзины
     */
    private function getCartItems(?User $user, string $sessionId)
    {
        $query = ShopCartItem::active()->with([
            'good:id,slug',
            // Загружаем только саму вариацию; атрибуты подтянем отдельно при форматировании, если нужно
            'variation:id,name,sku'
        ]);
        
        if ($user) {
            $query->forUser($user->id);
        } else {
            $query->forSession($sessionId);
        }
        
        return $query->ordered()->get();
    }

    /**
     * Найти элемент корзины
     */
    private function findCartItem(?User $user, string $sessionId, int $goodId, ?int $variationId)
    {
        $query = ShopCartItem::active();
        
        if ($user) {
            $query->forUser($user->id);
        } else {
            $query->forSession($sessionId);
        }
        
        return $query->where('good_id', $goodId)
                    ->where('variation_id', $variationId)
                    ->first();
    }

    /**
     * Форматировать параметры вариации
     */
    private function formatVariationProperties($variation): string
    {
        if (!$variation) {
            return '';
        }
        
        try {
        
        // Новая схема: формируем строку из атрибутов вариации
        $rows = \Illuminate\Support\Facades\DB::table('shop_variation_attributes_values as vav')
            ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
            ->join('shop_variation_attributes as a', 'a.id', '=', 'av.attribute_id')
            ->where('vav.variation_id', $variation->id)
            ->select('a.name as attribute_name', 'av.value as value_value')
            ->orderBy('a.name')
            ->get();

        if ($rows->count() > 0) {
            return $rows->map(function ($row) {
                $propName = $row->attribute_name ?? '';
                $propValue = $row->value_value ?? '';
                return $propName . ': ' . $propValue;
            })->join(', ');
        }

        // Если нет атрибутов, возвращаем название вариации или пустую строку
        return $variation->name ?? '';
        
        } catch (\Exception $e) {
            // В случае ошибки возвращаем название вариации или пустую строку
            return $variation->name ?? '';
        }
    }

    /**
     * Форматировать данные корзины для фронтенда
     */
    private function formatCartData($cartItems): array
    {
        $items = [];
        $subtotal = 0;
        $totalQuantity = 0;

        foreach ($cartItems as $item) {
            $cartKey = $item->good_id . '_' . ($item->variation_id ?? 'main');
            
            // Формируем variation_name с параметрами
            $variationName = '';
            if ($item->variation_id && $item->relationLoaded('variation') && $item->variation) {
                $variationName = $this->formatVariationProperties($item->variation);
            } elseif ($item->variation_name) {
                // Fallback для старых элементов корзины
                $variationName = $item->variation_name;
            }
            
            $items[$cartKey] = [
                'good_id' => $item->good_id,
                'variation_id' => $item->variation_id,
                'quantity' => $item->quantity,
                'price' => $item->price,
                'total' => $item->total,
                'good_name' => $item->good_name,
                'variation_name' => $variationName,
                'good_sku' => $item->good_sku,
                'good_image' => $item->good_image,
                'good_slug' => $item->good ? $item->good->slug : ''
            ];
            
            $subtotal += $item->total;
            $totalQuantity += $item->quantity;
        }

        return [
            'items' => $items,
            'subtotal' => $subtotal,
            'total_amount' => $subtotal, // Пока без скидок
            'total_quantity' => $totalQuantity
        ];
    }

    /**
     * Получить полный URL изображения
     */
    private function getImageUrl($filePath): ?string
    {
        if (!$filePath) {
            return null;
        }

        // Убираем возможные префиксы API сервера
        $cleanPath = $filePath;
        
        // Если в пути есть полный URL, извлекаем только относительный путь
        if (preg_match('/https?:\/\/[^\/]+(.*)/', $filePath, $matches)) {
            $cleanPath = $matches[1];
        }
        
        // Если это уже полный URL, проверяем домен
        if (str_starts_with($cleanPath, 'http')) {
            // Заменяем старый домен на новый фронтенд домен
            $frontendUrl = config('app.frontend_url', 'https://admin.skateandsnow.ru');
            $oldDomains = [
                'https://ss75.kirhtarg.ru',
                'https://api.ss.ru',
                'https://ss75-api.kirhtarg.ru'
            ];
            
            foreach ($oldDomains as $oldDomain) {
                if (str_starts_with($cleanPath, $oldDomain)) {
                    return str_replace($oldDomain, $frontendUrl, $cleanPath);
                }
            }
            
            // Если это другой домен, возвращаем как есть
            return $cleanPath;
        }

        // Убираем лишний префикс images/ если он уже есть
        $cleanPath = ltrim($cleanPath, '/');
        if (str_starts_with($cleanPath, 'images/')) {
            // Возвращаем полный URL с фронтенда
            $frontendUrl = config('app.frontend_url', 'https://admin.skateandsnow.ru');
            return $frontendUrl . '/' . $cleanPath;
        }

        // Возвращаем полный URL к файлу в папке public/images/ на фронтенде
        $frontendUrl = config('app.frontend_url', 'https://admin.skateandsnow.ru');
        return $frontendUrl . '/images/' . $cleanPath;
    }

    /**
     * Добавить товар в предзаказы
     */
    public function addToPreorder(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'good_id' => 'required|integer|exists:shop_goods,id',
                'variation_id' => 'nullable|integer|exists:shop_good_variations,id',
                'quantity' => 'required|integer|min:1|max:99',
                'customer_name' => 'nullable|string|max:255',
                'customer_email' => 'nullable|email|max:255',
                'customer_phone' => 'nullable|string|max:20',
                'notes' => 'nullable|string|max:1000'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            $goodId = $request->get('good_id');
            $variationId = $request->get('variation_id');
            $quantity = $request->get('quantity');

            // Проверяем существование товара
            $good = ShopGood::where('id', $goodId)
                ->where('is_active', true)
                ->first();

            if (!$good) {
                return response()->json([
                    'success' => false,
                    'message' => 'Товар не найден или неактивен'
                ], 404);
            }

            // Инициализируем переменную вариации
            $variation = null;
            
            // Если указана вариация, проверяем её
            if ($variationId) {
                $variation = ShopGoodVariation::where('id', $variationId)
                    ->where('good_id', $goodId)
                    ->where('is_active', true)
                    ->first();

                if (!$variation) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Вариация не найдена или неактивна'
                    ], 404);
                }
            }

            $user = $this->getUserFromToken($request);
            $sessionId = $this->getSessionId($request);

            // Определяем цену
            $price = $good->sale_price ?? $good->price;
            if ($variationId && $variation) {
                $price = $variation->sale_price ?? $variation->price;
            }

            $total = $price * $quantity;

            // Создаем предзаказ
            $preorder = ShopPreorder::create([
                'user_id' => $user ? $user->id : null,
                'session_id' => $user ? null : $sessionId,
                'good_id' => $goodId,
                'variation_id' => $variationId,
                'quantity' => $quantity,
                'price' => $price,
                'total' => $total,
                'good_name' => $good->name,
                'variation_name' => $variation ? $variation->name : null,
                'good_sku' => $variation ? ($variation->sku ?? $good->sku) : $good->sku,
                'good_image' => $this->getGoodImage($good),
                'status' => 'pending',
                'customer_name' => $request->get('customer_name'),
                'customer_email' => $request->get('customer_email'),
                'customer_phone' => $request->get('customer_phone'),
                'notes' => $request->get('notes')
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Товар добавлен в предзаказы',
                'data' => [
                    'preorder_id' => $preorder->id,
                    'good_name' => $preorder->good_name,
                    'variation_name' => $preorder->variation_name,
                    'quantity' => $preorder->quantity,
                    'price' => $preorder->price,
                    'total' => $preorder->total
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания предзаказа: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить предзаказы пользователя
     */
    public function getPreorders(Request $request): JsonResponse
    {
        try {
            $user = $this->getUserFromToken($request);
            $sessionId = $this->getSessionId($request);

            $query = ShopPreorder::with(['good', 'variation'])
                ->active()
                ->ordered();

            if ($user) {
                $query->forUser($user->id);
            } else {
                $query->forSession($sessionId);
            }

            $preorders = $query->get();

            return response()->json([
                'success' => true,
                'data' => $preorders
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения предзаказов: ' . $e->getMessage()
            ], 500);
        }
    }
}
