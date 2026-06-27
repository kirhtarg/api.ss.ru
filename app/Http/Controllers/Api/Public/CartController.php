<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Admin\ShopOrdersController;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Setting;
use App\Models\ShopDellinSettings;
use App\Models\ShopCartItem;
use App\Models\ShopGood;
use App\Models\ShopGoodVariation;
use App\Models\ShopOrder;
use App\Models\ShopOrderLog;
use App\Models\ShopPaymentMethod;
use App\Models\ShopPreorder;
use App\Models\ShopRussianPostSettings;
use App\Models\User;
use App\Services\CustomerOrderEmailService;
use App\Services\NotificationService;
use App\Services\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use App\Services\OrderCalculationService;

class CartController extends Controller
{
    /**
     * Получить пользователя из токена Authorization
     */
    private function getUserFromToken(Request $request): ?User
    {
        $token = $request->bearerToken();
        if (! $token) {
            return null;
        }

        $personalAccessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
        if (! $personalAccessToken) {
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

        return $setting ? (int) $setting->value : 2; // По умолчанию режим 2
    }

    /**
     * Получить настройку режима учета удаленного склада
     */
    private function getShopRemoteQ(): int
    {
        $setting = Setting::where('key', 'shop_remote_q')->first();

        return $setting ? (int) $setting->value : 1; // По умолчанию режим 1
    }

    /**
     * Парсить строку удаленного остатка в число
     */
    private function parseRemoteStock(string $remoteStockStr): int
    {
        return (int) preg_replace('/\D/', '', $remoteStockStr) ?: 0;
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
     * Проверить, есть ли быстрый остаток на удаленном складе
     */
    private function hasFastRemoteStock(?string $fastRemoteStockQuantity, int $shopRemoteQ): bool
    {
        // Если shop_remote_q = 1, не учитываем удаленный склад
        if ($shopRemoteQ === 1) {
            return false;
        }

        // Если значение пустое, null или "0"
        if (empty($fastRemoteStockQuantity) || $fastRemoteStockQuantity === '0' || $fastRemoteStockQuantity === '') {
            return false;
        }

        // Если значение - число больше 0
        $parsed = $this->parseRemoteStock($fastRemoteStockQuantity);
        if ($parsed > 0) {
            return true;
        }

        // Если строка содержит что-то (например, ">10"), считаем что есть остаток
        $trimmed = trim($fastRemoteStockQuantity);
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
        if ($showGoodMode === 3) {
            return 99;
        }

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

            // Обновляем цены товаров из актуальных данных перед фильтрацией
            $updatedCartItems = $cartItems->map(function ($item) {
                $this->updateCartItemPrice($item);

                return $item;
            });

            // Фильтруем товары с нулевыми или некорректными ценами
            $validCartItems = $updatedCartItems->filter(function ($item) {
                $price = $item->price;
                $isValid = is_numeric($price) && $price > 0;

                // Логируем фильтрацию
                if (! $isValid) {
                    Log::info('Filtering cart item with invalid price after update', [
                        'good_id' => $item->good_id,
                        'variation_id' => $item->variation_id,
                        'price' => $price,
                        'price_type' => gettype($price),
                        'is_numeric' => is_numeric($price),
                        'is_greater_than_zero' => $price > 0,
                    ]);
                }

                return $isValid;
            });

            $cart = $this->formatCartData($validCartItems);

            return response()->json([
                'success' => true,
                'data' => $cart,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения корзины: '.$e->getMessage(),
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
                'quantity' => 'required|integer|min:1|max:99',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors(),
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

            if (! $good) {
                return response()->json([
                    'success' => false,
                    'message' => 'Товар не найден или неактивен',
                ], 404);
            }

            // Если указана вариация, проверяем её
            if ($variationId) {
                $variation = ShopGoodVariation::where('id', $variationId)
                    ->where('good_id', $goodId)
                    ->where('is_active', true)
                    ->first();

                if (! $variation) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Вариация не найдена или неактивна',
                    ], 404);
                }

                // Проверяем остатки в зависимости от режима
                $stockQuantity = $variation->stock_quantity ?? 0;
                $remoteStockQuantity = $variation->remote_stock_quantity ?? '';
                $fastRemoteStockQuantity = $variation->fast_remote_stock_quantity ?? '';

                // Проверяем наличие удаленного остатка ПЕРВЫМ
                $hasRemoteStock = $this->hasRemoteStock($remoteStockQuantity, $shopRemoteQ);

                if ($hasRemoteStock) {
                    // Если есть удаленный остаток, разрешаем добавление независимо от локального остатка
                    // Продолжаем выполнение функции
                } else {
                    // Проверяем быстрый остаток на удаленном складе
                    // Используем ту же функцию hasRemoteStock, что и для remote_stock_quantity, но передаем shop_remote_q = 2, чтобы обойти проверку shop_remote_q = 1
                    $hasFastRemoteStock = $this->hasRemoteStock($fastRemoteStockQuantity, 2);

                    if ($hasFastRemoteStock) {
                        // Если есть быстрый остаток, разрешаем добавление независимо от локального остатка
                        // Продолжаем выполнение функции
                    } else {
                        // Если нет ни удаленного, ни быстрого остатка, проверяем локальный остаток
                        if ($showGoodMode === 1 && $stockQuantity <= 0) {
                            return response()->json([
                                'success' => false,
                                'message' => 'Товар недоступен для заказа',
                            ], 400);
                        } elseif ($showGoodMode === 2 && $stockQuantity <= 0) {
                            return response()->json([
                                'success' => false,
                                'message' => 'Товар временно отсутствует на складе',
                            ], 400);
                        } elseif ($showGoodMode === 3) {
                            // Режим 3: игнорируем остатки - разрешаем добавление
                        } elseif ($showGoodMode === 4) {
                            // Режим 4: предзаказ - проверяем is_preorder
                            $isPreorder = $variation->good->is_preorder == 1 || $variation->good->is_preorder === true;

                            if (! $isPreorder && $stockQuantity <= 0) {
                                // Если is_preorder = 0 и остаток = 0, блокируем добавление
                                return response()->json([
                                    'success' => false,
                                    'message' => 'Товар недоступен для заказа',
                                    'is_preorder_disabled' => true,
                                ], 400);
                            }

                            // Если is_preorder = 1, разрешаем предзаказ (без проверки авторизации)
                            // Незарегистрированные пользователи могут делать предзаказы
                            // Ограничиваем количество остатками только если есть остаток
                            if ($stockQuantity > 0 && $stockQuantity < $quantity) {
                                Log::info('Mode 4 addToCart variation: limiting quantity to stock', [
                                    'stock_quantity' => $stockQuantity,
                                    'requested_quantity' => $quantity,
                                    'adjusted_quantity' => $stockQuantity,
                                ]);
                                $quantity = $stockQuantity;
                            }
                        } else {
                            // Обычная проверка остатков
                            if ($stockQuantity < $quantity) {
                                return response()->json([
                                    'success' => false,
                                    'message' => 'Недостаточно товара на складе',
                                ], 400);
                            }
                        }
                    }
                }
            } else {
                // Проверяем остатки основного товара в зависимости от режима
                $stockQuantity = $good->stock_quantity ?? 0;
                $remoteStockQuantity = $good->remote_stock_quantity ?? '';
                $fastRemoteStockQuantity = $good->fast_remote_stock_quantity ?? '';

                // Проверяем наличие удаленного остатка ПЕРВЫМ
                $hasRemoteStock = $this->hasRemoteStock($remoteStockQuantity, $shopRemoteQ);

                if ($hasRemoteStock) {
                    // Если есть удаленный остаток, разрешаем добавление независимо от локального остатка
                    // Продолжаем выполнение функции
                } else {
                    // Проверяем быстрый остаток на удаленном складе
                    // Используем ту же функцию hasRemoteStock, что и для remote_stock_quantity, но передаем shop_remote_q = 2, чтобы обойти проверку shop_remote_q = 1
                    $hasFastRemoteStock = $this->hasRemoteStock($fastRemoteStockQuantity, 2);

                    if ($hasFastRemoteStock) {
                        // Если есть быстрый остаток, разрешаем добавление независимо от локального остатка
                        // Продолжаем выполнение функции
                    } else {
                        // Если нет ни удаленного, ни быстрого остатка, проверяем локальный остаток
                        if ($showGoodMode === 1 && $stockQuantity <= 0) {
                            return response()->json([
                                'success' => false,
                                'message' => 'Товар недоступен для заказа',
                            ], 400);
                        } elseif ($showGoodMode === 2 && $stockQuantity <= 0) {
                            return response()->json([
                                'success' => false,
                                'message' => 'Товар временно отсутствует на складе',
                            ], 400);
                        } elseif ($showGoodMode === 3) {
                            // Режим 3: игнорируем остатки - разрешаем добавление
                        } elseif ($showGoodMode === 4) {
                            // Режим 4: предзаказ - проверяем is_preorder
                            $isPreorder = $good->is_preorder == 1 || $good->is_preorder === true;

                            if (! $isPreorder && $stockQuantity <= 0) {
                                // Если is_preorder = 0 и остаток = 0, блокируем добавление
                                return response()->json([
                                    'success' => false,
                                    'message' => 'Товар недоступен для заказа',
                                    'is_preorder_disabled' => true,
                                ], 400);
                            }

                            // Если is_preorder = 1, разрешаем предзаказ (без проверки авторизации)
                            // Незарегистрированные пользователи могут делать предзаказы
                            // Ограничиваем количество остатками только если есть остаток
                            if ($stockQuantity > 0 && $stockQuantity < $quantity) {
                                Log::info('Mode 4 addToCart main: limiting quantity to stock', [
                                    'stock_quantity' => $stockQuantity,
                                    'requested_quantity' => $quantity,
                                    'adjusted_quantity' => $stockQuantity,
                                ]);
                                $quantity = $stockQuantity;
                            }
                        } else {
                            // Обычная проверка остатков
                            if ($stockQuantity < $quantity) {
                                return response()->json([
                                    'success' => false,
                                    'message' => 'Недостаточно товара на складе',
                                ], 400);
                            }
                        }
                    }
                }
            }

            $user = $this->getUserFromToken($request);
            $sessionId = $this->getSessionId($request);

            // Определяем цены - сохраняем и акционную, и обычную
            $regularPrice = $variationId ? $variation->price : $good->price;
            $salePrice = $variationId ? $variation->sale_price : $good->sale_price;

            // Проверяем, что базовая цена не равна 0 (товары с ценой 0 не должны добавляться в корзину)
            if (! $regularPrice || $regularPrice <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Невозможно добавить товар с нулевой ценой в корзину',
                ], 400);
            }

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
                $currentImage = $this->getGoodImage($good, $variationId);
                if ($currentImage) {
                    $existingItem->good_image = $currentImage;
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
                    'good_image' => $this->getGoodImage($good, $variationId),
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
                'data' => $cart,
            ]);

        } catch (\Exception $e) {
            Log::error('Cart addToCart error: '.$e->getMessage(), [
                'good_id' => $request->get('good_id'),
                'variation_id' => $request->get('variation_id'),
                'quantity' => $request->get('quantity'),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка добавления в корзину',
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
                'quantity' => 'required|integer|min:0|max:99',
            ]);

            if ($validator->fails()) {
                Log::error('Validation failed:', $validator->errors()->toArray());

                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $goodId = $request->get('good_id');
            $variationId = $request->get('variation_id');
            $quantity = $request->get('quantity');

            $user = $this->getUserFromToken($request);
            $sessionId = $this->getSessionId($request);

            $cartItem = $this->findCartItem($user, $sessionId, $goodId, $variationId);

            if (! $cartItem) {
                return response()->json([
                    'success' => false,
                    'message' => 'Товар не найден в корзине',
                ], 404);
            }

            // Обновляем цену товара перед проверкой
            $this->updateCartItemPrice($cartItem);

            // Проверяем, что товар имеет корректную цену
            if (! $cartItem->price || $cartItem->price <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Невозможно обновить товар с некорректной ценой',
                ], 400);
            }

            if ($quantity <= 0) {
                // Удаляем товар из корзины
                $cartItem->delete();
            } else {
                // Проверяем остатки перед обновлением количества
                $good = ShopGood::with('tags')->find($goodId);
                if (! $good) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Товар не найден',
                    ], 404);
                }

                // Получаем режим показа товаров при нулевых остатках
                $showGoodMode = $this->getShopShowGoodMode();

                // Получаем параметр shop_remote_q
                $shopRemoteQ = $this->getShopRemoteQ();

                // Получаем остатки в зависимости от режима shop_remote_q
                $remoteStockQuantity = null;
                $fastRemoteStockQuantity = null;
                if ($variationId) {
                    $variation = ShopGoodVariation::find($variationId);
                    if (! $variation) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Вариация не найдена',
                        ], 404);
                    }
                    $localStock = $variation->stock_quantity ?? 0;
                    $remoteStockQuantity = $variation->remote_stock_quantity;
                    $fastRemoteStockQuantity = $variation->fast_remote_stock_quantity ?? '';
                } else {
                    $localStock = $good->stock_quantity ?? 0;
                    $remoteStockQuantity = $good->remote_stock_quantity;
                    $fastRemoteStockQuantity = $good->fast_remote_stock_quantity ?? '';
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
                    // Проверяем быстрый остаток на удаленном складе
                    // Используем ту же функцию hasRemoteStock, что и для remote_stock_quantity, но передаем shop_remote_q = 2, чтобы обойти проверку shop_remote_q = 1
                    $hasFastRemote = $this->hasRemoteStock($fastRemoteStockQuantity, 2);

                    if ($hasFastRemote) {
                        // Если есть быстрый остаток, разрешаем обновление до 99
                        // Максимальное количество для быстрого удаленного склада - 99
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
                                'message' => 'Товар недоступен для заказа',
                            ], 400);
                        } elseif ($showGoodMode === 2 && $maxQuantity <= 0) {
                            return response()->json([
                                'success' => false,
                                'message' => 'Товар временно отсутствует на складе',
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
                                    'message' => 'Недостаточно товара на складе. Доступно: '.$maxQuantity.' шт.',
                                ], 400);
                            }
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
                'data' => $cart,
            ]);

        } catch (\Exception $e) {
            Log::error('Error in updateCartItem:', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления корзины: '.$e->getMessage(),
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
                'variation_id' => 'nullable|integer',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $goodId = $request->get('good_id');
            $variationId = $request->get('variation_id');

            $user = $this->getUserFromToken($request);
            $sessionId = $this->getSessionId($request);

            $cartItem = $this->findCartItem($user, $sessionId, $goodId, $variationId);

            if (! $cartItem) {
                return response()->json([
                    'success' => false,
                    'message' => 'Товар не найден в корзине',
                ], 404);
            }

            $cartItem->delete();

            // Получаем обновленную корзину
            $cartItems = $this->getCartItems($user, $sessionId);
            $cart = $this->formatCartData($cartItems);

            return response()->json([
                'success' => true,
                'message' => 'Товар удален из корзины',
                'data' => $cart,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления из корзины: '.$e->getMessage(),
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
                'data' => ['items' => [], 'subtotal' => 0, 'total_amount' => 0, 'total_quantity' => 0],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка очистки корзины: '.$e->getMessage(),
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
                'birthday_discount_amount' => 'nullable|numeric',
                'total_discount_amount' => 'nullable|numeric',
                'promo_code' => 'nullable|string|max:50',
                'certificate_code' => 'nullable|string|max:255',
                'has_certificate' => 'nullable|boolean',
                'promo_code_id' => 'nullable|integer',
                'use_bonus_points' => 'nullable|boolean',
                'bonus_points_to_use' => 'nullable|integer|min:0',
                'order_bonus_points' => 'nullable|integer|min:0',
                'overtax_amount' => 'nullable|numeric',
                'overtax_text' => 'nullable|string|max:255',
                'items' => 'required|array',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $user = $this->getUserFromToken($request);
            $sessionId = $this->getSessionId($request);

            $cartItems = $this->getCartItems($user, $sessionId);
            $cart = $this->formatCartData($cartItems);

            // Если корзина в БД пуста, но есть items в запросе, используем их
            $requestItems = $request->get('items', []);
            if (empty($cart['items']) && ! empty($requestItems)) {
                // Используем items из запроса
                $cart['items'] = $requestItems;
            }

            if (empty($cart['items'])) {
                Log::warning('Корзина пуста при создании заказа', [
                    'session_id' => $sessionId,
                    'user_id' => $user ? $user->id : null,
                    'request_items_count' => count($requestItems),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Корзина пуста',
                ], 400);
            }

            // Получаем статус "Обрабатывается" по умолчанию
            $pendingStatus = \App\Models\ShopOrderStatus::where('name', 'pending')->orWhere('display_name', 'Обрабатывается')->first();
            if (! $pendingStatus) {
                // Если статус не найден, используем ID 1 по умолчанию
                $pendingStatus = (object) ['id' => 1];
                Log::warning('Статус заказа не найден, используется ID 1 по умолчанию');
            }

            // Получаем ID пользователя из запроса или из токена
            $customerIdFromRequest = $request->get('customer_id');
            $customerIdFromToken = $user ? $user->id : null;

            // Приоритет: сначала из запроса, потом из токена
            $customerId = null;
            if ($customerIdFromRequest) {
                $customerId = is_numeric($customerIdFromRequest) ? (int) $customerIdFromRequest : null;
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

            $certificateData = $this->extractCertificateData($request->all());
            $isCertificateOrder = $certificateData['has_certificate'];

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
            if (! $paymentMethodType && $paymentMethod) {
                $paymentMethodModel = ShopPaymentMethod::where('name', $paymentMethod)->first();
                if ($paymentMethodModel) {
                    $paymentMethodType = $paymentMethodModel->type;
                    $paymentMethodId = $paymentMethodModel->id;
                }
            }

            // Определяем ID способа доставки
            if (! $shippingMethodId && $shippingMethod) {
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
                'is_paid' => $isPaid,
            ]);

            // SECURITY FIX: Пересчитываем цены товаров из БД, чтобы избежать заказов с нулевой ценой
            $rawItems = $request->get('items', array_values($cart['items']));
            $finalItems = [];
            $recalculatedSubtotal = 0;
            $recalculatedRegularSubtotal = 0; // Сумма базовых цен
            $registeredDiscountBaseSubtotal = 0;
            $recalculatedTotalQuantity = 0;
            $registeredDiscountPercent = $customerId ? (float) (Setting::where('key', 'discount_reg')->value('value') ?? 0) : 0;
            $discountToDTextValue = Setting::where('key', 'discount_to_d_text')->value('value');
            $registeredDiscountForSaleAllowed = ! (
                $discountToDTextValue === null ||
                $discountToDTextValue === '' ||
                $discountToDTextValue === '0' ||
                $discountToDTextValue === 0 ||
                $discountToDTextValue === false
            );

            foreach ($rawItems as $item) {
                $goodId = $item['good_id'] ?? null;
                $variationId = $item['variation_id'] ?? null;
                $quantity = $item['quantity'] ?? 1;

                if (! $goodId) {
                    continue;
                }

                $good = ShopGood::find($goodId);
                if (! $good) {
                    continue;
                }

                // Определяем базовые цены
                $dbPrice = $good->price;
                $dbSalePrice = $good->sale_price;
                $dbDempingPrice = $good->demping_price;
                $dbShowDemping = $good->show_demping;

                // Если есть вариация, берем цены из нее
                if ($variationId) {
                    $variation = ShopGoodVariation::find($variationId);
                    if ($variation) {
                        $dbPrice = $variation->price;
                        $dbSalePrice = $variation->sale_price;
                        $dbDempingPrice = $variation->demping_price;
                        $dbShowDemping = $variation->show_demping;
                    }
                }

                $tagDiscount = $this->getGoodTagDiscount($good);
                $tagDiscountPercent = $tagDiscount['percent'];
                $tags = $good->relationLoaded('tags') ? $good->tags : $good->tags()->get();
                $hasTagExtraDiscount = $tagDiscountPercent > 0;
                $hasNoBonusTag = $tags->contains(fn ($tag) => (bool) ($tag->disables_bonuses ?? false));
                $hasNoRegisteredDiscountTag = $tags->contains(fn ($tag) => (bool) ($tag->disables_registered_discount ?? false));

                // Если у тега есть скидка, она считается от базовой цены и игнорирует акцию/демпинг.
                $finalPrice = $dbPrice;
                $showDemping = ($dbShowDemping == 1 || $dbShowDemping === true || $dbShowDemping === '1');

                if ($tagDiscountPercent > 0) {
                    $finalPrice = $this->applyTagExtraDiscount($dbPrice, $tagDiscountPercent);
                    $showDemping = false;
                } elseif ($showDemping && $dbDempingPrice && $dbDempingPrice > 0) {
                    $finalPrice = $dbDempingPrice;
                } elseif ($dbSalePrice && $dbSalePrice > 0 && $dbSalePrice < $dbPrice) {
                    $finalPrice = $dbSalePrice;
                }

                $itemTotal = $finalPrice * $quantity;
                $discountAmount = max(0, ($dbPrice - $finalPrice) * $quantity);
                $hasDiscountPrice = $hasTagExtraDiscount || $showDemping || ($dbSalePrice && $dbSalePrice > 0 && $dbSalePrice < $dbPrice);
                $canApplyRegisteredDiscount = $customerId
                    && $registeredDiscountPercent > 0
                    && ! $showDemping
                    && ! $hasNoBonusTag
                    && ! $hasNoRegisteredDiscountTag
                    && ($registeredDiscountForSaleAllowed || ! $hasDiscountPrice);

                if ($canApplyRegisteredDiscount) {
                    $registeredDiscountBaseSubtotal += \App\Helpers\PriceHelper::roundPrice((float) $finalPrice) * $quantity;
                }

                // Гарантированно получаем и сохраняем параметры вариации
                $variationName = null;
                $variationSku = null;
                if (! empty($variationId)) {
                    $variation = ShopGoodVariation::find($variationId);
                    if ($variation) {
                        $variationName = $this->formatVariationProperties($variation);
                        $variationSku = $variation->sku;
                    }
                }

                // Обновляем поля товара (используем array_merge для сохранения всех данных из фронтенда)
                $newItem = array_merge($item, [
                    'good_name' => $item['good_name'] ?? $item['name'] ?? $good->name,
                    'good_sku' => $item['good_sku'] ?? $good->sku,
                    'variation_name' => $item['variation_name'] ?? $variationName,
                    'variation_sku' => $item['variation_sku'] ?? $variationSku,
                    'price' => $finalPrice, // Final price for display (matches other controllers)
                    'base_price' => $dbPrice,
                    'sale_price' => $dbSalePrice,
                    'demping_price' => $dbDempingPrice,
                    'final_price' => $finalPrice,
                    'show_demping' => $showDemping,
                    'total' => $itemTotal,
                    'discount_amount' => $discountAmount,
                    'tag_discount_percent' => $tagDiscountPercent,
                    'tag_discount_name' => $tagDiscount['name'],
                    'tags' => $tags ? $tags->map(function ($tag) {
                        return [
                            'id' => $tag->id,
                            'name' => $tag->name,
                            'slug' => $tag->slug,
                            'color' => $tag->color,
                            'disables_bonuses' => (bool) $tag->disables_bonuses,
                            'disables_registered_discount' => (bool) $tag->disables_registered_discount,
                            'extra_discount_percent' => (float) $tag->extra_discount_percent,
                            'increased_bonus_percent' => (float) $tag->increased_bonus_percent,
                        ];
                    })->values()->toArray() : [],
                ]);

                $finalItems[] = $newItem;
                $recalculatedSubtotal += $itemTotal;
                $recalculatedRegularSubtotal += $dbPrice * $quantity;
                $recalculatedTotalQuantity += $quantity;
            }

            // Пересчитываем общую сумму
            $deliveryCost = $request->get('delivery_cost', 0);
            $overtaxAmount = (float) $request->get('overtax_amount', 0) + (float) $request->get('payment_surcharge_amount', 0);
            $saleDiscountAmount = $isCertificateOrder
                ? 0
                : \App\Helpers\PriceHelper::roundPrice(max(0, $recalculatedRegularSubtotal - $recalculatedSubtotal));
            $registeredUserDiscountAmount = $isCertificateOrder
                ? 0
                : $this->roundDiscount($registeredDiscountBaseSubtotal * ($registeredDiscountPercent / 100));
            $promoCodeDiscountAmount = $isCertificateOrder ? 0 : (float) $request->get('promo_code_discount_amount', 0);
            $birthdayDiscountAmount = $isCertificateOrder ? 0 : (float) $request->get('birthday_discount_amount', 0);
            $totalDiscount = $isCertificateOrder
                ? 0
                : \App\Helpers\PriceHelper::roundPrice($saleDiscountAmount + $registeredUserDiscountAmount + $promoCodeDiscountAmount + $birthdayDiscountAmount);

            $clientSaleDiscountAmount = (float) $request->get('sale_discount_amount', 0);
            $clientRegisteredDiscountAmount = (float) $request->get('registered_user_discount_amount', 0);
            if (! $isCertificateOrder && (
                abs($saleDiscountAmount - $clientSaleDiscountAmount) > 0.01 ||
                abs($registeredUserDiscountAmount - $clientRegisteredDiscountAmount) > 0.01
            )) {
                Log::warning('Скидки заказа пересчитаны сервером при создании заказа', [
                    'order_number' => $orderNumber,
                    'customer_id' => $customerId,
                    'client_sale_discount_amount' => $clientSaleDiscountAmount,
                    'server_sale_discount_amount' => $saleDiscountAmount,
                    'client_registered_user_discount_amount' => $clientRegisteredDiscountAmount,
                    'server_registered_user_discount_amount' => $registeredUserDiscountAmount,
                    'client_total_discount_amount' => (float) $request->get('total_discount_amount', 0),
                    'server_total_discount_amount' => $totalDiscount,
                ]);
            }

            // РАСЧЕТ ИТОГА: 
            // Суть бага была в том, что recalculatedSubtotal УЖЕ включал акционную скидку, 
            // а потом из него вычитался totalDiscount, в котором ТАКЖЕ сидела акционная скидка.
            // Теперь считаем от БАЗОВОЙ суммы (recalculatedRegularSubtotal).
            $finalSubtotal = $isCertificateOrder ? $recalculatedRegularSubtotal : $recalculatedSubtotal;
            $finalTotalAmount = $recalculatedRegularSubtotal + $overtaxAmount - $totalDiscount;
            
            if ($finalTotalAmount < 0) {
                $finalTotalAmount = 0;
            }

            // ПРИМЕНЯЕМ ОКРУГЛЕНИЕ
            $recalculatedSubtotal = \App\Helpers\PriceHelper::roundPrice($recalculatedSubtotal);
            $finalTotalAmount = \App\Helpers\PriceHelper::roundPrice($finalTotalAmount);

            // Создаем заказ
            $orderData = [
                'order_number' => $orderNumber,
                'user_id' => $customerId,
                'status_id' => $pendingStatus->id, // Статус "Ожидает обработки" (id=1)
                'delivery_status_id' => $deliveryStatusId,
                'is_active' => $isActive,
                'payed' => $isPaid,
                'customer_name' => $request->get('customer_name'),
                'customer_email' => $request->get('customer_email'),
                'customer_phone' => $request->get('customer_phone'),
                'items' => $finalItems, // Используем проверенные товары
                'subtotal' => $finalSubtotal, // Используем пересчитанную сумму
                'discount_amount' => $totalDiscount,
                'sale_discount_amount' => $saleDiscountAmount,
                'registered_user_discount_amount' => $registeredUserDiscountAmount,
                'promo_code_discount_amount' => $promoCodeDiscountAmount,
                'birthday_discount_amount' => $birthdayDiscountAmount,
                'total_discount_amount' => $totalDiscount,
                'promo_code' => $isCertificateOrder ? null : $request->get('promo_code'),
                'certificate_code' => $certificateData['certificate_code'],
                'has_certificate' => $isCertificateOrder,
                'promo_code_id' => $isCertificateOrder ? null : $request->get('promo_code_id'),
                'use_bonus_points' => $isCertificateOrder ? false : $request->get('use_bonus_points', false),
                'bonus_points_to_use' => $isCertificateOrder ? 0 : $request->get('bonus_points_to_use', 0),
                'order_bonus_points' => $isCertificateOrder ? 0 : $request->get('order_bonus_points', 0),
                'overtax_amount' => $overtaxAmount,
                'overtax_text' => $request->get('overtax_text') ?: ($request->get('payment_surcharge_amount', 0) > 0 ? 'Наценка за способ оплаты' : null),

                'delivery_cost' => $deliveryCost,
                'total_amount' => $finalTotalAmount, // Используем пересчитанную общую сумму
                'total_quantity' => $recalculatedTotalQuantity,
                'payment_method' => $request->get('payment_method'),
                'payment_method_id' => $paymentMethodId,
                'shipping_method' => $request->get('shipping_method'),
                'shipping_method_id' => $shippingMethodId,
                'shipping_address' => $request->get('shipping_address'),
                'notes' => $request->get('notes'),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'metadata' => [
                    'delivery_city_code' => $request->get('city_code'),
                    'delivery_city_name' => $request->get('shipping_city') ?? $request->get('customer_city'),
                    'delivery_tariff_code' => $request->get('cdek_tariff_code'),
                    'cdek_tariff_code' => $request->get('cdek_tariff_code'),
                    'cdek_delivery_type' => $request->get('cdek_delivery_type'),
                    'cdek_pvz_code' => $request->get('cdek_pvz_code'),
                    'cdek_delivery_address' => $request->get('cdek_delivery_address'),
                    'cdek_packages' => $request->get('cdek_packages'),
                    'dellin_tariff_code' => $request->get('dellin_tariff_code'),
                    'dellin_delivery_type' => $request->get('dellin_delivery_type'),
                    'dellin_terminal_id' => $request->get('dellin_terminal_id'),
                    'dellin_delivery_address' => $request->get('dellin_delivery_address'),
                    'russianpost_tariff_code' => $request->get('russianpost_tariff_code'),
                    'russianpost_delivery_type' => $request->get('russianpost_delivery_type'),
                    'russianpost_office_id' => $request->get('russianpost_office_id'),
                    'russianpost_postal_code' => $request->get('russianpost_postal_code'),
                    'russianpost_delivery_address' => $request->get('russianpost_delivery_address'),
                    'yandex_delivery_tariff_code' => $request->get('yandex_delivery_tariff_code'),
                    'yandex_delivery_type' => $request->get('yandex_delivery_type'),
                    'yandex_pickup_point_id' => $request->get('yandex_pickup_point_id'),
                    'yandex_delivery_address' => $request->get('yandex_delivery_address'),
                    'yandex_delivery_metadata' => $request->get('yandex_delivery_metadata'),
                ],
            ];

            $order = ShopOrder::create($orderData);

            // Логируем создание заказа
            $userName = null;
            if ($customerId) {
                $customerUser = User::find($customerId);
                $userName = $customerUser ? $customerUser->name : null;
            }
            ShopOrderLog::logOrderCreated($order->id, $userName ?? $request->get('customer_name', 'Покупатель'), ShopOrderLog::SECTION_ORDERS, $order->order_number);
            $this->createExternalDeliveryOrderIfEnabled($order);

            // Создаем запись об использовании промокода, если он был применен
            if (! $isCertificateOrder && $request->get('promo_code_id')) {
                try {
                    $promocode = \App\Models\Promocode::find($request->get('promo_code_id'));
                    if ($promocode) {
                        $sessionId = $request->header('X-Session-ID');
                        $discountAmount = $request->get('promo_code_discount_amount', 0);
                        $appliedTo = [
                            'order_id' => $order->id,
                            'order_number' => $order->order_number,
                            'items' => $request->get('items', []),
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
                    Log::error('Ошибка создания записи об использовании промокода: '.$e->getMessage());
                    // Не прерываем создание заказа, если ошибка с промокодом
                }
            }

            // Списываем бонусы с баланса пользователя через UserBonus (единое хранилище)
            if (! $isCertificateOrder && $customerId && $request->get('use_bonus_points') && $request->get('bonus_points_to_use', 0) > 0) {
                try {
                    $bonusPointsToUse = (int) $request->get('bonus_points_to_use', 0);
                    $userBonus = \App\Models\UserBonus::getOrCreateForUser($customerId);

                    if ($userBonus->points >= $bonusPointsToUse) {
                        $userBonus->spendPoints(
                            $bonusPointsToUse,
                            "Списание бонусов за заказ #{$order->order_number}",
                            $order->id,
                            ['source' => 'checkout']
                        );
                        Log::info('Бонусы списаны через UserBonus', [
                            'user_id' => $customerId,
                            'order_id' => $order->id,
                            'bonus_points_used' => $bonusPointsToUse,
                            'remaining' => $userBonus->fresh()->points,
                        ]);
                    } else {
                        Log::warning('Недостаточно бонусов для списания', [
                            'user_id' => $customerId,
                            'requested' => $bonusPointsToUse,
                            'available' => $userBonus->points,
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::error('Ошибка списания бонусов: '.$e->getMessage());
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
                    $customerMessage .= '💰 <b>Сумма заказа:</b> '.number_format((float)$order->total_amount, 0, ',', ' ')." ₽\n";
                    $customerMessage .= "📦 <b>Товаров:</b> {$order->total_quantity} шт.\n\n";
                    $customerMessage .= "📞 <b>Наш телефон:</b> +7 (999) 123-45-67\n";
                    $customerMessage .= '📧 <b>Email:</b> info@skateandsnow.ru';

                    $telegramService->notifyCustomer(
                        $customerChatId,
                        'order_created',
                        $order->id,
                        $customerMessage
                    );
                }
            } catch (\Exception $e) {
                // Логируем ошибку, но не прерываем создание заказа
                Log::error('Notification error: '.$e->getMessage());
            }

            app(CustomerOrderEmailService::class)->sendOrderConfirmation($order);

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
                    'order_number' => $order->order_number,
                    // Скидки
                    'sale_discount_amount' => $order->sale_discount_amount,
                    'registered_user_discount_amount' => $order->registered_user_discount_amount,
                    'promo_code_discount_amount' => $order->promo_code_discount_amount,
                    'birthday_discount_amount' => $order->birthday_discount_amount,
                    'total_discount_amount' => $order->total_discount_amount,
                    'certificate_code' => $order->certificate_code,
                    'has_certificate' => (bool) ($order->has_certificate ?? false),
                    'bonus_points_to_use' => (int) ($order->bonus_points_to_use ?? 0),
                ],
            ];

            // Если включена двухэтапная оплата и способ оплаты требует одобрения
            if ($isTwoStagePay && $isTwoStagePaymentMethod) {
                $responseData['two_stage_pay'] = true;
                $responseData['message'] = 'Заказ создан. Менеджер проверит наличие товаров и после одобрения вы сможете произвести оплату.';
            }

            return response()->json($responseData);

        } catch (\Exception $e) {
            Log::error('Ошибка создания заказа: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания заказа: '.$e->getMessage(),
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
            $variationImage = \App\Models\ShopGoodImage::where('variation_id', $variationId)
                ->orderByDesc('is_main')
                ->orderBy('sort_order')
                ->orderBy('id')
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

        if (! $sessionId) {
            // Генерируем новый ID сессии
            $sessionId = 'cart_'.uniqid().'_'.time();
        }

        return $sessionId;
    }

    private function getGoodTagDiscount(ShopGood $good): array
    {
        $tags = $good->relationLoaded('tags') ? $good->tags : $good->tags()->get();
        $discountTag = $tags
            ->filter(fn ($tag) => (float) ($tag->extra_discount_percent ?? 0) > 0)
            ->sortByDesc(fn ($tag) => (float) ($tag->extra_discount_percent ?? 0))
            ->first();

        return [
            'percent' => $discountTag ? min(max((float) $discountTag->extra_discount_percent, 0), 100) : 0,
            'name' => $discountTag ? $discountTag->name : null,
        ];
    }

    private function applyTagExtraDiscount(float $basePrice, float $percent): float
    {
        if ($basePrice <= 0 || $percent <= 0) {
            return $basePrice;
        }

        $discount = $basePrice * (min(max($percent, 0), 100) / 100);
        return max(\App\Helpers\PriceHelper::roundPrice($basePrice - $discount), 0.01);
    }

    private function roundDiscount(float $discount): float
    {
        if ($discount <= 0) {
            return 0;
        }

        if (\App\Helpers\PriceHelper::isRound10Enabled()) {
            return floor($discount / 10) * 10;
        }

        return \App\Helpers\PriceHelper::roundPrice($discount);
    }

    /**
     * Получить элементы корзины
     */
    private function getCartItems(?User $user, string $sessionId)
    {
        $query = ShopCartItem::active()->with([
            'good' => function ($query) {
                $query->select('id', 'name', 'slug', 'sku', 'stock_quantity', 'remote_stock_quantity', 'fast_remote_stock_quantity', 'demping_price', 'show_demping', 'price', 'sale_price', 'is_preorder')
                    ->with('tags:id,name,slug,color,disables_bonuses,disables_registered_discount,extra_discount_percent,increased_bonus_percent');
            },
            'variation' => function ($query) {
                $query->select('id', 'good_id', 'name', 'sku', 'stock_quantity', 'remote_stock_quantity', 'fast_remote_stock_quantity', 'demping_price', 'show_demping', 'price', 'sale_price');
            },
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
        if (! $variation) {
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

                    return $propName.': '.$propValue;
                })->join(', ');
            }

            // Если нет атрибутов, возвращаем пустую строку (ПОЛНОСТЬЮ исключаем variation->name)
            return '';

        } catch (\Exception $e) {
            // В случае ошибки возвращаем пустую строку
            return '';
        }
    }

    /**
     * Обновить цену товара в корзине актуальной ценой из каталога
     */
    private function updateCartItemPrice($cartItem): void
    {
        $currentPrice = null;
        $currentSalePrice = null;

        if ($cartItem->variation_id && $cartItem->relationLoaded('variation') && $cartItem->variation) {
            $currentPrice = $cartItem->variation->price;
            $currentSalePrice = $cartItem->variation->sale_price;
        } elseif ($cartItem->relationLoaded('good') && $cartItem->good) {
            $currentPrice = $cartItem->good->price;
            $currentSalePrice = $cartItem->good->sale_price;
        }

        // Обновляем цену в базе данных корзины, если актуальная цена товара существует и отличается от null
        // Важно: если актуальная цена товара существует (> 0), всегда обновляем, даже если она совпадает
        if ($currentPrice !== null && $currentPrice > 0) {
            $cartItem->price = $currentPrice;
            $cartItem->sale_price = $currentSalePrice ?: $currentPrice; // Если sale_price null, используем regular price
            $cartItem->save();
        } elseif ($currentPrice !== null && $currentPrice == 0) {
            // Если актуальная цена товара = 0, устанавливаем цену товара в корзине в 0
            $cartItem->price = 0;
            $cartItem->sale_price = 0;
            $cartItem->save();
        }
        // Если currentPrice === null, оставляем существующую цену в корзине без изменений
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
            $cartKey = $item->good_id.'_'.($item->variation_id ?? 'main');

            // Формируем variation_name с параметрами
            $variationName = '';
            if ($item->variation_id && $item->relationLoaded('variation') && $item->variation) {
                $variationName = $this->formatVariationProperties($item->variation);
            } elseif ($item->variation_name) {
                // Если имя не содержит технического слова "Variation", используем его
                if (stripos($item->variation_name, 'Variation') === false) {
                    $variationName = $item->variation_name;
                }
            }

            // Определяем актуальные данные из связей (good/variation)
            $freshRegularPrice = null;
            $freshSalePrice = null;
            $freshDempingPrice = null;
            $freshShowDemping = false;
            $freshStockQuantity = 0;
            $freshRemoteStock = '';
            $freshFastRemoteStock = '';

            $freshAttributes = []; // Характеристики для фронтенда
            $freshImage = $item->good_image;
            $freshVariationImage = null;

            if ($item->variation_id && $item->relationLoaded('variation') && $item->variation) {
                $freshRegularPrice = $item->variation->price;
                $freshSalePrice = $item->variation->sale_price;
                $freshDempingPrice = $item->variation->demping_price;
                $freshShowDemping = ($item->variation->show_demping == 1 || $item->variation->show_demping === true || $item->variation->show_demping === '1');

                $freshStockQuantity = $item->variation->stock_quantity ?? 0;
                $freshRemoteStock = $item->variation->remote_stock_quantity ?? '';
                $freshFastRemoteStock = $item->variation->fast_remote_stock_quantity ?? '';

                // Загружаем атрибуты для фронтенда
                $dbAttributes = DB::table('shop_variation_attributes_values as vav')
                    ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
                    ->join('shop_variation_attributes as a', 'a.id', '=', 'av.attribute_id')
                    ->where('vav.variation_id', $item->variation_id)
                    ->select('a.name as attr_name', 'av.value as attr_value')
                    ->get();
                
                if ($dbAttributes->count() > 0) {
                    $freshAttributes = $dbAttributes->map(function($a) {
                        return [
                            'name' => $a->attr_name,
                            'value' => $a->attr_value
                        ];
                    })->toArray();
                }
            } elseif ($item->relationLoaded('good') && $item->good) {
                $freshRegularPrice = $item->good->price;
                $freshSalePrice = $item->good->sale_price;
                $freshDempingPrice = $item->good->demping_price;
                $freshShowDemping = ($item->good->show_demping == 1 || $item->good->show_demping === true || $item->good->show_demping === '1');

                $freshStockQuantity = $item->good->stock_quantity ?? 0;
                $freshRemoteStock = $item->good->remote_stock_quantity ?? '';
                $freshFastRemoteStock = $item->good->fast_remote_stock_quantity ?? '';
            }

            if ($item->relationLoaded('good') && $item->good) {
                $currentImage = $this->getGoodImage($item->good, $item->variation_id);
                if ($currentImage) {
                    $freshImage = $currentImage;
                    if ($item->variation_id) {
                        $freshVariationImage = $currentImage;
                    }
                }
            }

            // Используем актуальные цены, если они доступны, иначе из корзины
            $regularPrice = $freshRegularPrice !== null ? \App\Helpers\PriceHelper::roundPrice($freshRegularPrice) : \App\Helpers\PriceHelper::roundPrice($item->price);
            $salePrice = $freshSalePrice !== null ? \App\Helpers\PriceHelper::roundPrice($freshSalePrice) : ($item->sale_price ? \App\Helpers\PriceHelper::roundPrice($item->sale_price) : null);

            // Получаем демпинговую цену и флаг активации
            $dempingPrice = null;
            $showDemping = false;

            if ($freshShowDemping && $freshDempingPrice && $freshDempingPrice > 0) {
                $dempingPrice = \App\Helpers\PriceHelper::roundPrice($freshDempingPrice);
                $showDemping = true;
            }

            // Получаем теги и флаг предзаказа
            $tags = [];
            $isPreorder = false;
            if ($item->relationLoaded('good') && $item->good) {
                $tags = $item->good->tags ?? [];
                $isPreorder = $item->good->is_preorder == 1 || $item->good->is_preorder === true;
            }

            $items[$cartKey] = [
                'good_id' => $item->good_id,
                'variation_id' => $item->variation_id,
                'quantity' => $item->quantity,
                'price' => $regularPrice, // Обычная цена
                'sale_price' => $salePrice, // Акционная цена
                'demping_price' => $dempingPrice, // Демпинговая цена
                'show_demping' => $showDemping, // Флаг активации демпинга
                'total' => \App\Helpers\PriceHelper::roundPrice($item->total), // Total пересчитается на фронте или при следующем сохранении, но пока отдаем как есть
                'good_name' => $item->good_name,
                'variation_name' => $variationName,
                'attributes' => $freshAttributes, // Характеристики для чекаута
                'good_sku' => $item->good_sku,
                'good_image' => $freshImage,
                'variation_image' => $freshVariationImage,
                'good_slug' => $item->good ? $item->good->slug : '',
                'stock_quantity' => $freshStockQuantity,
                'remote_stock_quantity' => $freshRemoteStock,
                'fast_remote_stock_quantity' => $freshFastRemoteStock,
                'tags' => $tags,
                'is_preorder' => $isPreorder,
            ];

            // Если цены изменились, имеет смысл пересчитать total для корректного subtotal
            // Но пока оставим как есть, так как total в item зависит от логики скидок
            $subtotal += $item->total;
            $totalQuantity += $item->quantity;
        }

        return [
            'items' => $items,
            'subtotal' => \App\Helpers\PriceHelper::roundPrice($subtotal),
            'total_amount' => \App\Helpers\PriceHelper::roundPrice($subtotal), // Пока без скидок
            'total_quantity' => $totalQuantity,
        ];
    }

    /**
     * Получить путь к изображению (относительный, без домена)
     */
    private function getImageUrl($filePath): ?string
    {
        if (! $filePath) {
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
            return '/'.$filePath;
        }

        // Возвращаем относительный путь к файлу в папке public/images/
        return '/images/'.$filePath;
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
                'notes' => 'nullable|string|max:1000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $goodId = $request->get('good_id');
            $variationId = $request->get('variation_id');
            $quantity = $request->get('quantity');

            // Проверяем существование товара
            $good = ShopGood::where('id', $goodId)
                ->where('is_active', true)
                ->first();

            if (! $good) {
                return response()->json([
                    'success' => false,
                    'message' => 'Товар не найден или неактивен',
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

                if (! $variation) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Вариация не найдена или неактивна',
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
                'notes' => $request->get('notes'),
            ]);

            // Отправляем уведомления администраторам о предзаказе
            try {
                $notificationService = app(NotificationService::class);
                $notificationService->notifyPreorderCreated($preorder);
            } catch (\Exception $e) {
                Log::error('Preorder notification error: '.$e->getMessage());
            }

            // Отправляем email клиенту о принятом предзаказе
            if ($preorder->customer_email) {
                try {
                    $siteInfo = \App\Services\SiteInfoService::getSiteInfoForEmail();

                    Mail::send('emails.preorder-confirmation', [
                        'preorder' => $preorder,
                        'siteInfo' => $siteInfo,
                    ], function ($mail) use ($preorder, $siteInfo) {
                        $siteName = $siteInfo['site_name'] ?? 'Интернет-магазин';
                        $mail->to($preorder->customer_email)
                            ->subject("Ваш предзаказ принят - {$siteName}");
                    });

                    Log::info('Preorder confirmation email sent to: '.$preorder->customer_email);
                } catch (\Exception $e) {
                    // Логируем ошибку, но не прерываем создание предзаказа
                    Log::error('Preorder confirmation email error: '.$e->getMessage());
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
                    'total' => $preorder->total,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания предзаказа: '.$e->getMessage(),
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
                'data' => $preorders,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения предзаказов: '.$e->getMessage(),
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

            if (! $contact) {
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
            Log::error('Ошибка получения контактов для email: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Генерирует уникальный номер заказа
     */
    private function generateUniqueOrderNumber()
    {
        $datePart = date('Ymd');
        $prefix = 'SS-'.$datePart.'-';
        $attempts = 0;
        do {
            $randomPart = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $orderNumber = $prefix.$randomPart;
            $exists = ShopOrder::where('order_number', $orderNumber)->exists();
            $attempts++;
        } while ($exists && $attempts < 5);
        if ($exists) {
            $orderNumber = $prefix.uniqid();
        }

        return $orderNumber;
    }

    private function extractCertificateData(array $payload): array
    {
        $certificateCode = trim((string) ($payload['certificate_code'] ?? ''));
        $hasCertificate = $certificateCode !== '' || (bool) ($payload['has_certificate'] ?? false);

        return [
            'certificate_code' => $hasCertificate ? $certificateCode : null,
            'has_certificate' => $hasCertificate,
        ];
    }

    /**
     * Обогащает данные заказа названиями товаров
     */
    private function enrichOrderItems($order)
    {
        if (! $order->items || ! is_array($order->items)) {
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

                        // Если есть вариация, получаем её параметры для обогащения названия товара
                        if (! empty($item['variation_id'])) {
                            $varName = $item['variation_name'] ?? null;

                            $variation = ShopGoodVariation::find($item['variation_id']);
                            if (! $varName && $variation) {
                                $varName = $this->formatVariationProperties($variation);
                            }

                            if ($varName) {
                                $enrichedItem['name'] = $good->name.' ('.$varName.')';
                                $enrichedItem['good_name'] = $good->name.' ('.$varName.')';
                                $enrichedItem['variation_name'] = $varName;
                                if ($variation && $variation->sku) {
                                    $enrichedItem['variation_sku'] = $variation->sku;
                                }
                            }
                        }
                    }
                } catch (\Exception $e) {
                    Log::error('Error enriching item: '.$e->getMessage());
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

    private function createExternalDeliveryOrderIfEnabled(ShopOrder $order): void
    {
        try {
            $metadata = is_array($order->metadata) ? $order->metadata : [];
            $shippingMethod = mb_strtolower((string) ($order->shipping_method ?? ''));

            $isDellin = ! empty($metadata['dellin_delivery_type'])
                || str_contains($shippingMethod, 'делов')
                || str_contains($shippingMethod, 'dellin');

            $isRussianPost = ! empty($metadata['russianpost_delivery_type'])
                || str_contains($shippingMethod, 'почт')
                || str_contains($shippingMethod, 'russianpost');

            if ($isDellin) {
                $settings = ShopDellinSettings::getActive();
                if (! $settings?->create_order_in_account) {
                    return;
                }

                $response = app(ShopOrdersController::class)->createDellinOrder(new Request(), $order->id);
                $data = $response->getData(true);
                if (! ($data['success'] ?? false)) {
                    $this->logExternalDeliveryCreationError($order, 'Деловые линии', $data['message'] ?? 'Заявка не создана');
                }

                return;
            }

            if ($isRussianPost) {
                $settings = ShopRussianPostSettings::getActive();
                if (! $settings?->create_order_in_account) {
                    return;
                }

                $response = app(ShopOrdersController::class)->createRussianPostOrder(new Request(), $order->id);
                $data = $response->getData(true);
                if (! ($data['success'] ?? false)) {
                    $this->logExternalDeliveryCreationError($order, 'Почта России', $data['message'] ?? 'Отправление не создано');
                }
            }
        } catch (\Throwable $e) {
            Log::error('Ошибка автоматического создания заявки доставки: '.$e->getMessage(), [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
            ]);
            $this->logExternalDeliveryCreationError($order, 'Доставка', $e->getMessage());
        }
    }

    private function logExternalDeliveryCreationError(ShopOrder $order, string $provider, string $message): void
    {
        ShopOrderLog::createLog($order->id, "Ошибка создания заявки {$provider}", [
            'action_color' => '#FFFFFF',
            'action_bg_color' => '#DC2626',
            'section' => ShopOrderLog::SECTION_DELIVERY,
            'comment' => $message,
            'info' => "Заказ № {$order->order_number}",
        ]);
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
                'payment_method' => $order->payment_method,
            ]);

            if (! $order->items) {
                Log::warning('У заказа нет товаров для обновления остатков', [
                    'order_id' => $order->id,
                ]);

                return;
            }

            $items = is_string($order->items) ? json_decode($order->items, true) : $order->items;

            if (! is_array($items)) {
                Log::warning('Товары заказа не являются массивом', [
                    'order_id' => $order->id,
                    'items' => $order->items,
                ]);

                return;
            }

            foreach ($items as $item) {
                $goodId = $item['good_id'] ?? null;
                $quantity = $item['quantity'] ?? 0;
                $variationId = $item['variation_id'] ?? null;

                if (! $goodId || $quantity <= 0) {
                    Log::warning('Пропускаем товар с некорректными данными', [
                        'good_id' => $goodId,
                        'quantity' => $quantity,
                        'variation_id' => $variationId,
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
                'order_id' => $order->id,
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка при обновлении остатков товаров для заказа с оплатой при получении: '.$e->getMessage(), [
                'order_id' => $order->id,
                'error' => $e->getTraceAsString(),
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

            if (! $good) {
                Log::warning('Товар не найден для обновления остатка', [
                    'good_id' => $goodId,
                    'order_id' => $orderId,
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
                'order_id' => $orderId,
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка при обновлении остатка товара для заказа с оплатой при получении: '.$e->getMessage(), [
                'good_id' => $goodId,
                'order_id' => $orderId,
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
            if (! Schema::hasTable('shop_good_variations') || ! Schema::hasColumn('shop_good_variations', 'stock_quantity')) {
                Log::info('Таблица вариаций или поле stock_quantity не найдены, пропускаем обновление вариации', [
                    'variation_id' => $variationId,
                    'order_id' => $orderId,
                ]);

                return;
            }

            $variation = DB::table('shop_good_variations')->find($variationId);

            if (! $variation) {
                Log::warning('Вариация товара не найдена для обновления остатка', [
                    'variation_id' => $variationId,
                    'order_id' => $orderId,
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
                'order_id' => $orderId,
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка при обновлении остатка вариации товара для заказа с оплатой при получении: '.$e->getMessage(), [
                'variation_id' => $variationId,
                'order_id' => $orderId,
            ]);
        }
    }
}
