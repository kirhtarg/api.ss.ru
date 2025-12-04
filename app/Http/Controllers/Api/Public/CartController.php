<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\ShopGood;
use App\Models\ShopGoodVariation;
use App\Models\ShopOrder;
use App\Models\ShopOrderStatus;
use App\Models\ShopOrderLog;
use App\Models\ShopCartItem;
use App\Models\ShopPaymentMethod;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\ShopPreorder;
use App\Models\User;
use App\Models\Setting;
use App\Services\TelegramService;
use App\Services\NotificationService;
use App\Mail\OrderInvoiceMail;
use App\Models\Contact;
use Illuminate\Support\Facades\Mail;
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
     * Получить настройку режима учета удаленного склада
     */
    private function getShopRemoteQ(): int
    {
        $setting = Setting::where('key', 'shop_remote_q')->first();
        return $setting ? (int)$setting->value : 1; // По умолчанию режим 1
    }

    /**
     * Парсить строку удаленного остатка в число
     */
    private function parseRemoteStock(string $remoteStockStr): int
    {
        return (int)preg_replace('/\D/', '', $remoteStockStr) ?: 0;
    }

    /**
     * Проверить, есть ли остаток на удаленном складе
     */
    private function hasRemoteStock(?string $remoteStockQuantity, int $shopRemoteQ): bool
    {
        // Если shop_remote_q = 1, не учитываем удаленный склад
        if ($shopRemoteQ === 1) {
            return false;
        }

        // Если значение пустое, null или "0"
        if (empty($remoteStockQuantity) || $remoteStockQuantity === '0' || $remoteStockQuantity === '') {
            return false;
        }

        // Если значение - число больше 0
        $parsed = $this->parseRemoteStock($remoteStockQuantity);
        if ($parsed > 0) {
            return true;
        }

        // Если строка содержит что-то (например, ">10"), считаем что есть остаток
        $trimmed = trim($remoteStockQuantity);
        if ($trimmed !== '' && $trimmed !== '0') {
            return true;
        }

        return false;
    }

    /**
     * Получить максимальное количество для обновления корзины
     */
    private function getMaxQuantityForUpdate(int $localStock, int $remoteStock, int $shopRemoteQ, int $showGoodMode): int
    {
        // Если режим 3 (всегда доступен), нет ограничений
        if ($showGoodMode === 3) return 99;

        // shop_remote_q = 1: не учитывать удаленный склад (текущее поведение)
        if ($shopRemoteQ === 1) {
            return $localStock > 0 ? $localStock : 99;
        }

        // shop_remote_q = 2: не ограничивать количество
        if ($shopRemoteQ === 2) {
            return 99;
        }

        // shop_remote_q = 3: складывать остатки и ограничивать по сумме
        if ($shopRemoteQ === 3) {
            $totalStock = $localStock + $remoteStock;
            return $totalStock > 0 ? $totalStock : 0;
        }

        // Fallback для других значений
        return $localStock > 0 ? $localStock : 99;
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
            
            // Получаем параметр shop_remote_q
            $shopRemoteQ = $this->getShopRemoteQ();

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
                $remoteStockQuantity = $variation->remote_stock_quantity ?? '';
                
                // Проверяем наличие удаленного остатка ПЕРВЫМ
                $hasRemoteStock = $this->hasRemoteStock($remoteStockQuantity, $shopRemoteQ);
                
                if ($hasRemoteStock) {
                    // Если есть удаленный остаток, разрешаем добавление независимо от локального остатка
                    // Продолжаем выполнение функции
                } else {
                    // Если нет удаленного остатка, проверяем локальный остаток
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
                        // Режим 4: предзаказ - проверяем is_preorder
                        $isPreorder = $variation->good->is_preorder == 1 || $variation->good->is_preorder === true;
                        
                        if (!$isPreorder && $stockQuantity <= 0) {
                            // Если is_preorder = 0 и остаток = 0, блокируем добавление
                            return response()->json([
                                'success' => false,
                                'message' => 'Товар недоступен для заказа',
                                'is_preorder_disabled' => true
                            ], 400);
                        }
                        
                        // Если is_preorder = 1, разрешаем предзаказ
                        $user = $this->getUserFromToken($request);
                        if (!$user) {
                            return response()->json([
                                'success' => false,
                                'message' => 'Для предзаказа необходимо авторизоваться',
                                'requires_auth' => true
                            ], 401);
                        }
                        // Ограничиваем количество остатками только если есть остаток
                        if ($stockQuantity > 0 && $stockQuantity < $quantity) {
                            Log::info('Mode 4 addToCart variation: limiting quantity to stock', [
                                'stock_quantity' => $stockQuantity,
                                'requested_quantity' => $quantity,
                                'adjusted_quantity' => $stockQuantity
                            ]);
                            $quantity = $stockQuantity;
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
            } else {
                // Проверяем остатки основного товара в зависимости от режима
                $stockQuantity = $good->stock_quantity ?? 0;
                $remoteStockQuantity = $good->remote_stock_quantity ?? '';
                
                // Проверяем наличие удаленного остатка ПЕРВЫМ
                $hasRemoteStock = $this->hasRemoteStock($remoteStockQuantity, $shopRemoteQ);
                
                if ($hasRemoteStock) {
                    // Если есть удаленный остаток, разрешаем добавление независимо от локального остатка
                    // Продолжаем выполнение функции
                } else {
                    // Если нет удаленного остатка, проверяем локальный остаток
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
                        // Режим 4: предзаказ - проверяем is_preorder
                        $isPreorder = $good->is_preorder == 1 || $good->is_preorder === true;
                        
                        if (!$isPreorder && $stockQuantity <= 0) {
                            // Если is_preorder = 0 и остаток = 0, блокируем добавление
                            return response()->json([
                                'success' => false,
                                'message' => 'Товар недоступен для заказа',
                                'is_preorder_disabled' => true
                            ], 400);
                        }
                        
                        // Если is_preorder = 1, разрешаем предзаказ
                        $user = $this->getUserFromToken($request);
                        if (!$user) {
                            return response()->json([
                                'success' => false,
                                'message' => 'Для предзаказа необходимо авторизоваться',
                                'requires_auth' => true
                            ], 401);
                        }
                        // Ограничиваем количество остатками только если есть остаток
                        if ($stockQuantity > 0 && $stockQuantity < $quantity) {
                            Log::info('Mode 4 addToCart main: limiting quantity to stock', [
                                'stock_quantity' => $stockQuantity,
                                'requested_quantity' => $quantity,
                                'adjusted_quantity' => $stockQuantity
                            ]);
                            $quantity = $stockQuantity;
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
            }

            $user = $this->getUserFromToken($request);
            $sessionId = $this->getSessionId($request);

            // Определяем цены - сохраняем и акционную, и обычную
            $regularPrice = $variationId ? $variation->price : $good->price;
            $salePrice = $variationId ? $variation->sale_price : $good->sale_price;
            
            // Определяем финальную цену с учетом демпинга
            // Приоритет: демпинговая (если show_demping = 1 или true) > акционная > базовая
            if ($variationId) {
                // Для вариации проверяем демпинг
                $isDempingActive = ($variation->show_demping == 1 || $variation->show_demping === true || $variation->show_demping === '1');
                if ($isDempingActive && $variation->demping_price && $variation->demping_price > 0) {
                    $finalPrice = \App\Helpers\PriceHelper::roundPrice($variation->demping_price);
                } elseif ($salePrice && $salePrice > 0 && $salePrice < $regularPrice) {
                    $finalPrice = \App\Helpers\PriceHelper::roundPrice($salePrice);
                } else {
                    $finalPrice = \App\Helpers\PriceHelper::roundPrice($regularPrice);
                }
            } else {
                // Для основного товара (без вариаций) проверяем демпинг
                $isDempingActive = ($good->show_demping == 1 || $good->show_demping === true || $good->show_demping === '1');
                if ($isDempingActive && $good->demping_price && $good->demping_price > 0) {
                    $finalPrice = \App\Helpers\PriceHelper::roundPrice($good->demping_price);
                } elseif ($salePrice && $salePrice > 0 && $salePrice < $regularPrice) {
                    $finalPrice = \App\Helpers\PriceHelper::roundPrice($salePrice);
                } else {
                    $finalPrice = \App\Helpers\PriceHelper::roundPrice($regularPrice);
                }
            }
            
            $total = \App\Helpers\PriceHelper::roundPrice($finalPrice * $quantity);

            // Ищем существующий элемент корзины
            $existingItem = $this->findCartItem($user, $sessionId, $goodId, $variationId);

            if ($existingItem) {
                // Обновляем количество существующего товара
                $existingItem->quantity += $quantity;
                $existingItem->price = \App\Helpers\PriceHelper::roundPrice($regularPrice); // Сохраняем обычную цену
                $existingItem->sale_price = $salePrice ? \App\Helpers\PriceHelper::roundPrice($salePrice) : null; // Сохраняем акционную цену
                $existingItem->total = \App\Helpers\PriceHelper::roundPrice($finalPrice * $existingItem->quantity); // Используем финальную цену для total
                // Обновляем variation_name с параметрами
                if ($variationId) {
                    $existingItem->variation_name = $this->formatVariationProperties($variation);
                }
                // Поля stock_quantity и remote_stock_quantity не сохраняются в таблице shop_cart_items
                // Они используются только на фронтенде для отображения остатков
                $existingItem->save();
            } else {
                // Создаем новый элемент корзины
                ShopCartItem::create([
                    'user_id' => $user ? $user->id : null,
                    'session_id' => $user ? null : $sessionId,
                    'good_id' => $goodId,
                    'variation_id' => $variationId,
                    'quantity' => $quantity,
                    'price' => \App\Helpers\PriceHelper::roundPrice($regularPrice), // Сохраняем обычную цену
                    'sale_price' => $salePrice ? \App\Helpers\PriceHelper::roundPrice($salePrice) : null, // Сохраняем акционную цену
                    'total' => $total, // Используем финальную цену для total (уже округлено)
                    'good_name' => $good->name,
                    'variation_name' => $variationId ? $this->formatVariationProperties($variation) : null,
                    'good_sku' => $variationId ? $variation->sku : $good->sku,
                    'good_image' => $this->getGoodImage($good, $variationId)
                    // Поля stock_quantity и remote_stock_quantity не сохраняются в таблице shop_cart_items
                    // Они используются только на фронтенде для отображения остатков
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
                Log::error('Validation failed:', $validator->errors()->toArray());
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
                // Проверяем остатки перед обновлением количества
                $good = ShopGood::find($goodId);
                if (!$good) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Товар не найден'
                    ], 404);
                }

                // Получаем режим показа товаров при нулевых остатках
                $showGoodMode = $this->getShopShowGoodMode();

                // Получаем параметр shop_remote_q
                $shopRemoteQ = $this->getShopRemoteQ();

                // Получаем остатки в зависимости от режима shop_remote_q
                $remoteStockQuantity = null;
                if ($variationId) {
                    $variation = ShopGoodVariation::find($variationId);
                    if (!$variation) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Вариация не найдена'
                        ], 404);
                    }
                    $localStock = $variation->stock_quantity ?? 0;
                    $remoteStockQuantity = $variation->remote_stock_quantity;
                } else {
                    $localStock = $good->stock_quantity ?? 0;
                    $remoteStockQuantity = $good->remote_stock_quantity;
                }

                // Проверяем наличие удаленного остатка ПЕРВЫМ
                $hasRemote = $this->hasRemoteStock($remoteStockQuantity, $shopRemoteQ);

                if ($hasRemote) {
                    // Если есть удаленный остаток, разрешаем обновление до 99
                    // Максимальное количество для удаленного склада - 99
                    $maxQuantity = 99;
                    if ($quantity > $maxQuantity) {
                        $quantity = $maxQuantity; // Ограничиваем до 99
                    }
                } else {
                    // Если нет удаленного остатка, используем стандартную логику
                    $remoteStock = $this->parseRemoteStock($remoteStockQuantity ?? '');
                    $maxQuantity = $this->getMaxQuantityForUpdate($localStock, $remoteStock, $shopRemoteQ, $showGoodMode);

                    // Проверяем остатки в зависимости от режима
                    if ($showGoodMode === 1 && $maxQuantity <= 0) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Товар недоступен для заказа'
                        ], 400);
                    } elseif ($showGoodMode === 2 && $maxQuantity <= 0) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Товар временно отсутствует на складе'
                        ], 400);
                    } elseif ($showGoodMode === 3) {
                        // Режим 3: игнорируем остатки - разрешаем обновление
                    } elseif ($showGoodMode === 4) {
                        // Режим 4: предзаказ - ограничиваем количество в корзине остатками
                        if ($maxQuantity < $quantity) {
                            $quantity = $maxQuantity; // Устанавливаем максимальное количество
                        }
                    } else {
                        // Обычная проверка остатков
                        if ($maxQuantity < $quantity) {
                            return response()->json([
                                'success' => false,
                                'message' => 'Недостаточно товара на складе. Доступно: ' . $maxQuantity . ' шт.'
                            ], 400);
                        }
                    }
                }

                // Обновляем количество
                // Пересчитываем финальную цену с учетом демпинга
                $regularPrice = $cartItem->price;
                $salePrice = $cartItem->sale_price;
                $finalPrice = $regularPrice;
                
                if ($variationId) {
                    // Для вариации проверяем демпинг
                    $variation = ShopGoodVariation::find($variationId);
                    if ($variation) {
                        $isDempingActive = ($variation->show_demping == 1 || $variation->show_demping === true || $variation->show_demping === '1');
                        if ($isDempingActive && $variation->demping_price && $variation->demping_price > 0) {
                            $finalPrice = \App\Helpers\PriceHelper::roundPrice($variation->demping_price);
                        } elseif ($salePrice && $salePrice > 0 && $salePrice < $regularPrice) {
                            $finalPrice = \App\Helpers\PriceHelper::roundPrice($salePrice);
                        } else {
                            $finalPrice = \App\Helpers\PriceHelper::roundPrice($regularPrice);
                        }
                    }
                } else {
                    // Для основного товара проверяем демпинг
                    $isDempingActive = ($good->show_demping == 1 || $good->show_demping === true || $good->show_demping === '1');
                    if ($isDempingActive && $good->demping_price && $good->demping_price > 0) {
                        $finalPrice = \App\Helpers\PriceHelper::roundPrice($good->demping_price);
                    } elseif ($salePrice && $salePrice > 0 && $salePrice < $regularPrice) {
                        $finalPrice = \App\Helpers\PriceHelper::roundPrice($salePrice);
                    } else {
                        $finalPrice = \App\Helpers\PriceHelper::roundPrice($regularPrice);
                    }
                }

                $cartItem->quantity = $quantity;
                $cartItem->total = \App\Helpers\PriceHelper::roundPrice($finalPrice * $quantity);
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
            Log::error('Error in updateCartItem:', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
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
        Log::info('=== НАЧАЛО СОЗДАНИЯ ЗАКАЗА ===');
        Log::info('Request data:', $request->all());

        try {
            $validator = Validator::make($request->all(), [
                'customer_name' => 'required|string|max:255',
                'customer_email' => 'required|email|max:255',
                'customer_phone' => 'nullable|string|max:20',
                'customer_id' => 'nullable|integer',
                'payment_method' => 'required|string|max:100',
                'payment_method_id' => 'nullable|integer|exists:shop_payment_methods,id',
                'shipping_method' => 'required|string|max:100',
                'shipping_address' => 'nullable|string',
                'notes' => 'nullable|string',
                'subtotal' => 'nullable|numeric',
                'total_amount' => 'nullable|numeric',
                'delivery_cost' => 'nullable|numeric',
                'sale_discount_amount' => 'nullable|numeric',
                'registered_user_discount_amount' => 'nullable|numeric',
                'promo_code_discount_amount' => 'nullable|numeric',
                'total_discount_amount' => 'nullable|numeric',
                'promo_code' => 'nullable|string|max:50',
                'promo_code_id' => 'nullable|integer',
                'use_bonus_points' => 'nullable|boolean',
                'bonus_points_to_use' => 'nullable|integer|min:0',
                'order_bonus_points' => 'nullable|integer|min:0',
                'items' => 'required|array'
            ]);

            if ($validator->fails()) {
                Log::error('Ошибка валидации заказа:', $validator->errors()->toArray());
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

            // Если корзина в БД пуста, но есть items в запросе, используем их
            $requestItems = $request->get('items', []);
            if (empty($cart['items']) && !empty($requestItems)) {
                Log::info('Корзина в БД пуста, но есть items в запросе. Используем items из запроса.', [
                    'items_count' => count($requestItems),
                    'session_id' => $sessionId,
                    'user_id' => $user ? $user->id : null
                ]);
                // Используем items из запроса
                $cart['items'] = $requestItems;
            }

            if (empty($cart['items'])) {
                Log::warning('Корзина пуста при создании заказа', [
                    'session_id' => $sessionId,
                    'user_id' => $user ? $user->id : null,
                    'request_items_count' => count($requestItems)
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Корзина пуста'
                ], 400);
            }

            // Получаем статус "Обрабатывается" по умолчанию
            $pendingStatus = \App\Models\ShopOrderStatus::where('name', 'pending')->orWhere('display_name', 'Обрабатывается')->first();
            if (!$pendingStatus) {
                // Если статус не найден, используем ID 1 по умолчанию
                $pendingStatus = (object)['id' => 1];
                Log::warning('Статус заказа не найден, используется ID 1 по умолчанию');
            }

            // Получаем ID пользователя из запроса или из токена
            $customerIdFromRequest = $request->get('customer_id');
            $customerIdFromToken = $user ? $user->id : null;

            // Приоритет: сначала из запроса, потом из токена
            $customerId = null;
            if ($customerIdFromRequest) {
                $customerId = is_numeric($customerIdFromRequest) ? (int)$customerIdFromRequest : null;
            } elseif ($customerIdFromToken) {
                $customerId = $customerIdFromToken;
            }

            // Логируем для отладки
            Log::info('=== СОЗДАНИЕ ЗАКАЗА ===');
            Log::info('customer_id из запроса:', ['customer_id' => $customerIdFromRequest, 'type' => gettype($customerIdFromRequest)]);
            Log::info('user из токена:', ['user_id' => $customerIdFromToken, 'type' => gettype($customerIdFromToken)]);
            Log::info('final customer_id:', ['customer_id' => $customerId, 'type' => gettype($customerId)]);
            Log::info('total_discount_amount:', ['amount' => $request->get('total_discount_amount')]);
            Log::info('bonus_points_to_use:', ['points' => $request->get('bonus_points_to_use')]);

            // Генерируем уникальный номер заказа
            $orderNumber = $this->generateUniqueOrderNumber();

            // Определяем статусы для оплаты при получении
            $paymentMethod = $request->get('payment_method');
            $paymentMethodId = $request->get('payment_method_id');
            $shippingMethod = $request->get('shipping_method');
            $shippingMethodId = $request->get('shipping_method_id');

            // Определяем тип способа оплаты
            $paymentMethodType = null;
            $paymentMethodModel = null;
            
            if ($paymentMethodId) {
                // Получаем способ оплаты по ID
                $paymentMethodModel = ShopPaymentMethod::find($paymentMethodId);
                if ($paymentMethodModel) {
                    $paymentMethodType = $paymentMethodModel->type;
                }
            }
            
            // Если не нашли по ID, пытаемся найти по названию
            if (!$paymentMethodType && $paymentMethod) {
                $paymentMethodModel = ShopPaymentMethod::where('name', $paymentMethod)->first();
                if ($paymentMethodModel) {
                    $paymentMethodType = $paymentMethodModel->type;
                    $paymentMethodId = $paymentMethodModel->id;
                }
            }

            // Определяем ID способа доставки
            if (!$shippingMethodId && $shippingMethod) {
                $shippingMethodModel = \App\Models\ShopDeliveryMethod::where('name', $shippingMethod)->first();
                if ($shippingMethodModel) {
                    $shippingMethodId = $shippingMethodModel->id;
                }
            }

            // Определяем is_active и paid в зависимости от типа оплаты
            // Все заказы создаются активными (is_active=true)
            // При успешной оплате будет установлено paid=true через webhook
            $isActive = true;
            $isPaid = false;

            // delivery_status_id зависит от типа доставки
            $deliveryStatusId = 1; // Создан (по умолчанию)

            // Если самовывоз - delivery_status_id = 5
            if (stripos($shippingMethod, 'самовывоз') !== false) {
                $deliveryStatusId = 5;
            }

            Log::info('Статусы заказа', [
                'payment_method' => $paymentMethod,
                'payment_method_id' => $paymentMethodId,
                'payment_method_type' => $paymentMethodType,
                'shipping_method' => $shippingMethod,
                'delivery_status_id' => $deliveryStatusId,
                'is_active' => $isActive,
                'is_paid' => $isPaid
            ]);

            // Создаем заказ
            $order = ShopOrder::create([
                'order_number' => $orderNumber,
                'user_id' => $customerId,
                'status_id' => $pendingStatus->id, // Статус "Ожидает обработки" (id=1)
                'delivery_status_id' => $deliveryStatusId,
                'is_active' => $isActive,
                'payed' => $isPaid,
                'customer_name' => $request->get('customer_name'),
                'customer_email' => $request->get('customer_email'),
                'customer_phone' => $request->get('customer_phone'),
                'items' => $request->get('items', array_values($cart['items'])),
                'subtotal' => $request->get('subtotal', $cart['subtotal']),
                'discount_amount' => $request->get('total_discount_amount', 0),
                'sale_discount_amount' => $request->get('sale_discount_amount', 0),
                'registered_user_discount_amount' => $request->get('registered_user_discount_amount', 0),
                'promo_code_discount_amount' => $request->get('promo_code_discount_amount', 0),
                'total_discount_amount' => $request->get('total_discount_amount', 0),
                'promo_code' => $request->get('promo_code'),
                'promo_code_id' => $request->get('promo_code_id'),
                'use_bonus_points' => $request->get('use_bonus_points', false),
                'bonus_points_to_use' => $request->get('bonus_points_to_use', 0),
                'order_bonus_points' => $request->get('order_bonus_points', 0),
                'delivery_cost' => $request->get('delivery_cost', 0),
                'total_amount' => $request->get('total_amount', $cart['total_amount']),
                'total_quantity' => $cart['total_quantity'],
                'payment_method' => $request->get('payment_method'),
                'payment_method_id' => $paymentMethodId,
                'shipping_method' => $request->get('shipping_method'),
                'shipping_method_id' => $shippingMethodId,
                'shipping_address' => $request->get('shipping_address'),
                'notes' => $request->get('notes'),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'metadata' => [
                    // Дополнительные метаданные, если нужны
                ]
            ]);

            // Логируем создание заказа
            $userName = null;
            if ($customerId) {
                $customerUser = User::find($customerId);
                $userName = $customerUser ? $customerUser->name : null;
            }
            ShopOrderLog::logOrderCreated($order->id, $userName ?? $request->get('customer_name', 'Покупатель'), ShopOrderLog::SECTION_ORDERS, $order->order_number);

            // Создаем запись об использовании промокода, если он был применен
            if ($request->get('promo_code_id')) {
                try {
                    $promocode = \App\Models\Promocode::find($request->get('promo_code_id'));
                    if ($promocode) {
                        $sessionId = $request->header('X-Session-ID');
                        $discountAmount = $request->get('promo_code_discount_amount', 0);
                        $appliedTo = [
                            'order_id' => $order->id,
                            'order_number' => $order->order_number,
                            'items' => $request->get('items', [])
                        ];
                        
                        $promocode->recordUsage(
                            $customerId,
                            $sessionId,
                            $order->id,
                            $discountAmount,
                            $appliedTo
                        );
                    }
                } catch (\Exception $e) {
                    Log::error('Ошибка создания записи об использовании промокода: ' . $e->getMessage());
                    // Не прерываем создание заказа, если ошибка с промокодом
                }
            }

            // Списываем бонусы с баланса пользователя, если они используются
            if ($customerId && $request->get('use_bonus_points') && $request->get('bonus_points_to_use', 0) > 0) {
                try {
                    $bonusPointsToUse = $request->get('bonus_points_to_use', 0);

                    // Получаем пользователя
                    $customer = User::find($customerId);
                    if ($customer) {
                        // Проверяем, что у пользователя достаточно бонусов
                        if ($customer->bonus_points >= $bonusPointsToUse) {
                            // Списываем бонусы
                            $customer->bonus_points -= $bonusPointsToUse;
                            $customer->save();

                            Log::info('Бонусы списаны с баланса пользователя', [
                                'user_id' => $customerId,
                                'bonus_points_used' => $bonusPointsToUse,
                                'remaining_bonus_points' => $customer->bonus_points
                            ]);
                        } else {
                            Log::warning('Недостаточно бонусов для списания', [
                                'user_id' => $customerId,
                                'requested_bonus_points' => $bonusPointsToUse,
                                'available_bonus_points' => $customer->bonus_points
                            ]);
                        }
                    }
                } catch (\Exception $e) {
                    Log::error('Ошибка списания бонусов: ' . $e->getMessage());
                    // Не прерываем создание заказа из-за ошибки списания бонусов
                }
            }

            // Обновляем остатки товаров для оплаты при получении
            $this->updateStockQuantitiesForOrder($order);

            // Очищаем корзину после создания заказа
            $query = ShopCartItem::active();

            if ($user) {
                $query->forUser($user->id);
            } else {
                $query->forSession($sessionId);
            }

            $query->delete();

            // Отправляем уведомления через систему оповещений
            try {
                $notificationService = app(NotificationService::class);
                $notificationService->notifyOrderCreated($order);

                // Также отправляем уведомление клиенту через Telegram (если указан chat_id)
                $customerChatId = $request->get('telegram_chat_id');
                if ($customerChatId) {
                    $telegramService = app(TelegramService::class);
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
                Log::error('Notification error: ' . $e->getMessage());
            }

            // Отправляем email с накладной
            try {
                $contacts = $this->getShopContacts();
                $siteInfo = \App\Services\SiteInfoService::getSiteInfoForEmail();

                // Обогащаем данные товаров названиями
                $enrichedOrder = $this->enrichOrderItems($order);

                // Отладочная информация о товарах в заказе
                Log::info('Order items for email:', [
                    'order_id' => $order->id,
                    'items' => $enrichedOrder->items,
                    'items_count' => is_array($enrichedOrder->items) ? count($enrichedOrder->items) : 'not array'
                ]);

                Mail::to($order->customer_email)->send(new OrderInvoiceMail($enrichedOrder, $contacts, $siteInfo));
                Log::info('Invoice email sent to: ' . $order->customer_email);
            } catch (\Exception $e) {
                // Логируем ошибку, но не прерываем создание заказа
                Log::error('Email notification error: ' . $e->getMessage());
            }

            // Проверяем настройку two_stage_pay
            $twoStagePay = \App\Models\Setting::where('key', 'two_stage_pay')->first();
            $isTwoStagePay = $twoStagePay && ($twoStagePay->value === '1' || $twoStagePay->value === true);
            
            // Проверяем, является ли способ оплаты банковским переводом, Яндекс.Пэй или Ю-мани
            $paymentMethodId = $request->get('payment_method_id');
            $paymentMethodName = $request->get('payment_method');
            $isTwoStagePaymentMethod = false;
            
            if ($paymentMethodId) {
                $paymentMethodModel = \App\Models\ShopPaymentMethod::find($paymentMethodId);
                if ($paymentMethodModel) {
                    $isTwoStagePaymentMethod = in_array($paymentMethodModel->type, ['transfer', 'yandex_pay', 'yandex_split', 'yookassa']);
                }
            } elseif ($paymentMethodName === 'Банковский перевод') {
                $isTwoStagePaymentMethod = true;
            }
            
            $responseData = [
                'success' => true,
                'message' => 'Заказ создан успешно',
                'data' => [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number
                ]
            ];
            
            // Если включена двухэтапная оплата и способ оплаты требует одобрения
            if ($isTwoStagePay && $isTwoStagePaymentMethod) {
                $responseData['two_stage_pay'] = true;
                $responseData['message'] = 'Заказ создан. Менеджер проверит наличие товаров и после одобрения вы сможете произвести оплату.';
            }
            
            return response()->json($responseData);

        } catch (\Exception $e) {
            Log::error('Ошибка создания заказа: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);
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
            'good:id,slug,stock_quantity,remote_stock_quantity,demping_price,show_demping',
            // Загружаем только саму вариацию; атрибуты подтянем отдельно при форматировании, если нужно
            'variation:id,name,sku,stock_quantity,remote_stock_quantity,demping_price,show_demping'
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

        $query->where('good_id', $goodId);
        
        if ($variationId === null) {
            $query->whereNull('variation_id');
        } else {
            $query->where('variation_id', $variationId);
        }

        return $query->first();
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
        $rows = DB::table('shop_variation_attributes_values as vav')
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

            // Используем цены из корзины (уже сохранены при добавлении)
            $regularPrice = \App\Helpers\PriceHelper::roundPrice($item->price);
            $salePrice = $item->sale_price ? \App\Helpers\PriceHelper::roundPrice($item->sale_price) : null;

            // Получаем демпинговую цену и флаг активации
            $dempingPrice = null;
            $showDemping = false;
            if ($item->variation_id && $item->relationLoaded('variation') && $item->variation) {
                // Проверяем show_demping как 1 или true, и наличие демпинговой цены
                $isDempingActive = ($item->variation->show_demping == 1 || $item->variation->show_demping === true || $item->variation->show_demping === '1');
                if ($isDempingActive && $item->variation->demping_price && $item->variation->demping_price > 0) {
                    $dempingPrice = \App\Helpers\PriceHelper::roundPrice($item->variation->demping_price);
                    $showDemping = true;
                }
            } elseif ($item->relationLoaded('good') && $item->good) {
                // Проверяем show_demping как 1 или true, и наличие демпинговой цены
                $isDempingActive = ($item->good->show_demping == 1 || $item->good->show_demping === true || $item->good->show_demping === '1');
                if ($isDempingActive && $item->good->demping_price && $item->good->demping_price > 0) {
                    $dempingPrice = \App\Helpers\PriceHelper::roundPrice($item->good->demping_price);
                    $showDemping = true;
                }
            }

            // Получаем остатки товара
            $stockQuantity = 0;
            $remoteStockQuantity = '';
            if ($item->variation_id && $item->relationLoaded('variation') && $item->variation) {
                $stockQuantity = $item->variation->stock_quantity ?? 0;
                $remoteStockQuantity = $item->variation->remote_stock_quantity ?? '';
            } elseif ($item->relationLoaded('good') && $item->good) {
                $stockQuantity = $item->good->stock_quantity ?? 0;
                $remoteStockQuantity = $item->good->remote_stock_quantity ?? '';
            }

            $items[$cartKey] = [
                'good_id' => $item->good_id,
                'variation_id' => $item->variation_id,
                'quantity' => $item->quantity,
                'price' => $regularPrice, // Обычная цена
                'sale_price' => $salePrice, // Акционная цена
                'demping_price' => $dempingPrice, // Демпинговая цена
                'show_demping' => $showDemping, // Флаг активации демпинга
                'total' => \App\Helpers\PriceHelper::roundPrice($item->total),
                'good_name' => $item->good_name,
                'variation_name' => $variationName,
                'good_sku' => $item->good_sku,
                'good_image' => $item->good_image,
                'good_slug' => $item->good ? $item->good->slug : '',
                'stock_quantity' => $stockQuantity,
                'remote_stock_quantity' => $remoteStockQuantity
            ];

            $subtotal += $item->total;
            $totalQuantity += $item->quantity;
        }

        return [
            'items' => $items,
            'subtotal' => \App\Helpers\PriceHelper::roundPrice($subtotal),
            'total_amount' => \App\Helpers\PriceHelper::roundPrice($subtotal), // Пока без скидок
            'total_quantity' => $totalQuantity
        ];
    }

    /**
     * Получить путь к изображению (относительный, без домена)
     */
    private function getImageUrl($filePath): ?string
    {
        if (!$filePath) {
            return null;
        }

        // Если в пути есть полный URL, извлекаем только относительный путь
        if (preg_match('/https?:\/\/[^\/]+(.*)/', $filePath, $matches)) {
            $filePath = $matches[1];
        }

        // Убираем лишний слэш в начале
        $filePath = ltrim($filePath, '/');

        // Если путь начинается с images/, возвращаем с ведущим слэшем
        if (str_starts_with($filePath, 'images/')) {
            return '/' . $filePath;
        }

        // Возвращаем относительный путь к файлу в папке public/images/
        return '/images/' . $filePath;
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

            // Определяем SKU товара (может быть null)
            $goodSku = null;
            if ($variation) {
                $goodSku = $variation->sku ?? $good->sku ?? null;
            } else {
                $goodSku = $good->sku ?? null;
            }

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
                'good_sku' => $goodSku,
                'good_image' => $this->getGoodImage($good),
                'status' => 'pending',
                'customer_name' => $request->get('customer_name'),
                'customer_email' => $request->get('customer_email'),
                'customer_phone' => $request->get('customer_phone'),
                'notes' => $request->get('notes')
            ]);

            // Отправляем уведомления администраторам о предзаказе
            try {
                $notificationService = app(NotificationService::class);
                $notificationService->notifyPreorderCreated($preorder);
            } catch (\Exception $e) {
                Log::error('Preorder notification error: ' . $e->getMessage());
            }

            // Отправляем email клиенту о принятом предзаказе
            if ($preorder->customer_email) {
                try {
                    $siteInfo = \App\Services\SiteInfoService::getSiteInfoForEmail();
                    
                    Mail::send('emails.preorder-confirmation', [
                        'preorder' => $preorder,
                        'siteInfo' => $siteInfo
                    ], function ($mail) use ($preorder, $siteInfo) {
                        $siteName = $siteInfo['site_name'] ?? 'Интернет-магазин';
                        $mail->to($preorder->customer_email)
                            ->subject("Ваш предзаказ принят - {$siteName}");
                    });
                    
                    Log::info('Preorder confirmation email sent to: ' . $preorder->customer_email);
                } catch (\Exception $e) {
                    // Логируем ошибку, но не прерываем создание предзаказа
                    Log::error('Preorder confirmation email error: ' . $e->getMessage());
                }
            }

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

    /**
     * Получить контакты магазина для email
     */
    private function getShopContacts()
    {
        try {
            $contact = Contact::with(['addresses', 'phones', 'socials'])
                ->where('is_main', 1)
                ->first();

            if (!$contact) {
                return null;
            }

            // Получаем основные данные
            $mainAddress = $contact->mainAddress();
            $mainPhone = $contact->mainPhone();

            // Формируем данные для накладной
            return [
                'name' => $contact->name,
                'short_name' => $contact->short_name,
                'legal_name' => $contact->legal_name,
                'inn' => $contact->inn,
                'ogrn' => $contact->ogrnip, // Используем ogrnip как ogrn
                'kpp' => null, // KPP не хранится в таблице
                'address' => $mainAddress ? $mainAddress->address : null,
                'phone' => $mainPhone ? $mainPhone->phone : null,
                'email' => null, // Email не хранится в таблице contacts
                'legal_address' => $contact->legal_address,
            ];

        } catch (\Exception $e) {
            Log::error('Ошибка получения контактов для email: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Генерирует уникальный номер заказа
     */
    private function generateUniqueOrderNumber()
    {
        $date = date('Ymd');
        $prefix = 'ORD-' . $date . '-';

        // Получаем следующий ID заказа из таблицы shop_orders
        $nextOrderId = ShopOrder::max('id') + 1;

        // Если заказов еще нет, начинаем с 1
        if (!$nextOrderId) {
            $nextOrderId = 1;
        }

        // Форматируем номер с ID заказа
        $orderNumber = $prefix . str_pad($nextOrderId, 4, '0', STR_PAD_LEFT);

        // Проверяем уникальность (на случай race condition)
        $counter = 1;
        while (ShopOrder::where('order_number', $orderNumber)->exists()) {
            $orderNumber = $prefix . str_pad($nextOrderId + $counter, 4, '0', STR_PAD_LEFT);
            $counter++;
        }

        return $orderNumber;
    }

    /**
     * Обогащает данные заказа названиями товаров
     */
    private function enrichOrderItems($order)
    {
        if (!$order->items || !is_array($order->items)) {
            return $order;
        }

        $enrichedItems = [];
        foreach ($order->items as $item) {
            $enrichedItem = $item;

            // Получаем название товара по good_id
            if (isset($item['good_id'])) {
                try {
                    $good = ShopGood::find($item['good_id']);
                    if ($good) {
                        $enrichedItem['name'] = $good->name;
                        $enrichedItem['good_name'] = $good->name;

                        // Если есть вариация, получаем её название
                        if (isset($item['variation_id']) && $item['variation_id']) {
                            $variation = ShopGoodVariation::find($item['variation_id']);
                            if ($variation && $variation->name) {
                                $enrichedItem['name'] = $good->name . ' (' . $variation->name . ')';
                                $enrichedItem['good_name'] = $good->name . ' (' . $variation->name . ')';
                            }
                        }
                    }
                } catch (\Exception $e) {
                    Log::error('Error enriching item: ' . $e->getMessage());
                }
            }

            // Пересчитываем сумму товара
            $quantity = $item['quantity'] ?? 1;
            $price = $item['price'] ?? 0;
            $enrichedItem['total'] = $price * $quantity;

            $enrichedItems[] = $enrichedItem;
        }

        // Создаем копию заказа с обогащенными данными
        $enrichedOrder = clone $order;
        $enrichedOrder->items = $enrichedItems;

        return $enrichedOrder;
    }

    /**
     * Обновление остатков товаров для заказа с оплатой при получении
     */
    private function updateStockQuantitiesForOrder(ShopOrder $order): void
    {
        try {
            Log::info('Обновление остатков товаров для заказа с оплатой при получении', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'payment_method' => $order->payment_method
            ]);

            if (!$order->items) {
                Log::warning('У заказа нет товаров для обновления остатков', [
                    'order_id' => $order->id
                ]);
                return;
            }

            $items = is_string($order->items) ? json_decode($order->items, true) : $order->items;

            if (!is_array($items)) {
                Log::warning('Товары заказа не являются массивом', [
                    'order_id' => $order->id,
                    'items' => $order->items
                ]);
                return;
            }

            foreach ($items as $item) {
                $goodId = $item['good_id'] ?? null;
                $quantity = $item['quantity'] ?? 0;
                $variationId = $item['variation_id'] ?? null;

                if (!$goodId || $quantity <= 0) {
                    Log::warning('Пропускаем товар с некорректными данными', [
                        'good_id' => $goodId,
                        'quantity' => $quantity,
                        'variation_id' => $variationId
                    ]);
                    continue;
                }

                // Обновляем остаток основного товара
                $this->updateGoodStockForOrder($goodId, $quantity, $order->id);

                // Если есть вариация, обновляем её остаток
                if ($variationId) {
                    $this->updateVariationStockForOrder($variationId, $quantity, $order->id);
                }
            }

            Log::info('Остатки товаров успешно обновлены для заказа с оплатой при получении', [
                'order_id' => $order->id
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка при обновлении остатков товаров для заказа с оплатой при получении: ' . $e->getMessage(), [
                'order_id' => $order->id,
                'error' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Обновление остатка основного товара для заказа с оплатой при получении
     */
    private function updateGoodStockForOrder(int $goodId, int $quantity, int $orderId): void
    {
        try {
            $good = ShopGood::find($goodId);

            if (!$good) {
                Log::warning('Товар не найден для обновления остатка', [
                    'good_id' => $goodId,
                    'order_id' => $orderId
                ]);
                return;
            }

            $currentStock = $good->stock_quantity ?? 0;
            $newStock = max(0, $currentStock - $quantity); // Не уходим в минус

            $good->update(['stock_quantity' => $newStock]);

            Log::info('Остаток товара обновлен для заказа с оплатой при получении', [
                'good_id' => $goodId,
                'good_name' => $good->name,
                'old_stock' => $currentStock,
                'quantity_ordered' => $quantity,
                'new_stock' => $newStock,
                'order_id' => $orderId
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка при обновлении остатка товара для заказа с оплатой при получении: ' . $e->getMessage(), [
                'good_id' => $goodId,
                'order_id' => $orderId
            ]);
        }
    }

    /**
     * Обновление остатка вариации товара для заказа с оплатой при получении
     */
    private function updateVariationStockForOrder(int $variationId, int $quantity, int $orderId): void
    {
        try {
            // Проверяем, есть ли таблица вариаций и поле stock_quantity
            if (!Schema::hasTable('shop_good_variations') || !Schema::hasColumn('shop_good_variations', 'stock_quantity')) {
                Log::info('Таблица вариаций или поле stock_quantity не найдены, пропускаем обновление вариации', [
                    'variation_id' => $variationId,
                    'order_id' => $orderId
                ]);
                return;
            }

            $variation = DB::table('shop_good_variations')->find($variationId);

            if (!$variation) {
                Log::warning('Вариация товара не найдена для обновления остатка', [
                    'variation_id' => $variationId,
                    'order_id' => $orderId
                ]);
                return;
            }

            $currentStock = $variation->stock_quantity ?? 0;
            $newStock = max(0, $currentStock - $quantity); // Не уходим в минус

            DB::table('shop_good_variations')
                ->where('id', $variationId)
                ->update(['stock_quantity' => $newStock]);

            Log::info('Остаток вариации товара обновлен для заказа с оплатой при получении', [
                'variation_id' => $variationId,
                'old_stock' => $currentStock,
                'quantity_ordered' => $quantity,
                'new_stock' => $newStock,
                'order_id' => $orderId
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка при обновлении остатка вариации товара для заказа с оплатой при получении: ' . $e->getMessage(), [
                'variation_id' => $variationId,
                'order_id' => $orderId
            ]);
        }
    }
}
