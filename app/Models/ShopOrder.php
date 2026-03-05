<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShopOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_number',
        'user_id',
        'status_id',
        'payment_status_id',
        'payed',
        'pay_agree',
        'is_active',
        'delivery_status_id',
        'customer_name',
        'customer_email',
        'customer_phone',
        'items',
        'subtotal',
        'discount_amount',
        'sale_discount_amount',
        'registered_user_discount_amount',
        'promo_code_discount_amount',
        'birthday_discount_amount',
        'total_discount_amount',
        'promo_code',
        'promo_code_id',
        'use_bonus_points',
        'bonus_points_to_use',
        'order_bonus_points',
        'delivery_cost',
        'total_amount',
        'total_quantity',
        'overtax_amount',
        'overtax_text',
        'payment_method',
        'payment_method_id',
        'yandex_pay_order_id',
        'payment_url',
        'yookassa_payment_id',
        'shipping_method',
        'shipping_method_id',
        'shipping_address',
        'cdek_order_uuid',
        'delivery_status',
        'notes',
        'comment',
        'cancellation_request',
        'ip_address',
        'user_agent',
        'metadata',
        'surcharge_enabled',
        'surcharge_value',
        'surcharge_type',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    protected $casts = [
        'items' => 'array',
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'sale_discount_amount' => 'decimal:2',
        'registered_user_discount_amount' => 'decimal:2',
        'promo_code_discount_amount' => 'decimal:2',
        'birthday_discount_amount' => 'decimal:2',
        'total_discount_amount' => 'decimal:2',
        'delivery_cost' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'total_quantity' => 'integer',
        'use_bonus_points' => 'boolean',
        'bonus_points_to_use' => 'integer',
        'order_bonus_points' => 'integer',
        'overtax_amount' => 'decimal:2',
        'payed' => 'boolean',
        'pay_agree' => 'boolean',
        'cancellation_request' => 'boolean',
        'is_active' => 'boolean',
        'delivery_status_id' => 'integer',
        'metadata' => 'array',
        'surcharge_enabled' => 'boolean',
        'surcharge_value' => 'decimal:2',
        'surcharge_type' => 'string',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function status()
    {
        return $this->belongsTo(ShopOrderStatus::class, 'status_id');
    }

    public function paymentMethod()
    {
        return $this->belongsTo(ShopPaymentMethod::class, 'payment_method_id');
    }

    public function paymentStatus()
    {
        return $this->belongsTo(ShopPaymentStatus::class, 'payment_status_id');
    }

    public function deliveryMethod()
    {
        return $this->belongsTo(ShopDeliveryMethod::class, 'shipping_method_id');
    }

    public function deliveryStatus()
    {
        return $this->belongsTo(ShopDeliveryStatus::class, 'delivery_status_id');
    }

    public function logs()
    {
        return $this->hasMany(ShopOrderLog::class, 'order_id')->orderBy('created_at', 'desc');
    }

    public function getItemsWithDetails()
    {
        if (! $this->items) {
            return [];
        }

        $items = is_string($this->items) ? json_decode($this->items, true) : $this->items;

        if (! is_array($items)) {
            return [];
        }

        return array_map(function ($item) {
            $goodId = $item['good_id'] ?? null;
            $variationId = $item['variation_id'] ?? null;

            $goodInfo = $this->getGoodInfo($goodId);

            $quantity = $item['quantity'] ?? 1;
            $price = $item['price'] ?? 0;
            $salePrice = $item['sale_price'] ?? $goodInfo['sale_price'] ?? null;

            $total = $item['total'] ?? 0;
            if ($total == 0) {
                if ($salePrice && $salePrice > 0) {
                    $total = $salePrice * $quantity;
                } else {
                    $total = $price * $quantity;
                }
            }

            $weight = null;
            $length = null;
            $width = null;
            $height = null;

            try {
                if ($variationId) {
                    $variationDimensions = DB::table('shop_good_variations')
                        ->select('weight', 'length', 'width', 'height')
                        ->where('id', (int) $variationId)
                        ->first();

                    if ($variationDimensions) {
                        $weight = $variationDimensions->weight;
                        $length = $variationDimensions->length;
                        $width = $variationDimensions->width;
                        $height = $variationDimensions->height;
                    }
                }

                if ($goodId && ($weight === null && $length === null && $width === null && $height === null)) {
                    $goodDimensions = DB::table('shop_goods')
                        ->select('weight', 'depth', 'width', 'height')
                        ->where('id', (int) $goodId)
                        ->first();

                    if ($goodDimensions) {
                        $weight = $goodDimensions->weight;
                        $length = $goodDimensions->depth;
                        $width = $goodDimensions->width;
                        $height = $goodDimensions->height;
                    }
                }
            } catch (\Exception $e) {
                Log::error('Ошибка получения габаритов товара для заказа: '.$e->getMessage());
            }

            $result = [
                'id' => $item['id'] ?? null,
                'good_id' => $goodId,
                'good_name' => $item['good_name'] ?? $goodInfo['name'] ?? 'Неизвестный товар',
                'good_image' => $item['good_image'] ?? $goodInfo['image'] ?? null,
                'good_sku' => $item['good_sku'] ?? $goodInfo['sku'] ?? null,
                'good_slug' => $item['good_slug'] ?? $goodInfo['slug'] ?? null,
                'variation_id' => $variationId,
                'variation_name' => $item['variation_name'] ?? null,
                'variation_sku' => $item['variation_sku'] ?? null,
                'quantity' => $quantity,
                'price' => $price,
                'sale_price' => $salePrice,
                'discount_amount' => $item['discount_amount'] ?? 0,
                'bonus_points' => $item['bonus_points'] ?? 0,
                'total' => $total,
                'weight' => isset($item['weight']) ? $item['weight'] : ($weight !== null ? (float) $weight : null),
                'length' => isset($item['length']) ? $item['length'] : ($length !== null ? (float) $length : null),
                'width' => isset($item['width']) ? $item['width'] : ($width !== null ? (float) $width : null),
                'height' => isset($item['height']) ? $item['height'] : ($height !== null ? (float) $height : null),
            ];

            return $result;
        }, $items);
    }

    private function getGoodInfo($goodId)
    {
        if (! $goodId) {
            return [
                'name' => null,
                'image' => null,
                'sku' => null,
                'slug' => null,
                'sale_price' => null,
            ];
        }

        try {
            // Сначала получаем основную информацию о товаре (всегда нужна)
            $good = DB::table('shop_goods')
                ->select('name', 'sku', 'slug', 'sale_price')
                ->where('id', (int) $goodId)
                ->first();

            if (! $good) {
                return [
                    'name' => null,
                    'image' => null,
                    'sku' => null,
                    'slug' => null,
                    'sale_price' => null,
                ];
            }

            // Получаем главное изображение товара
            $mainImage = DB::table('shop_good_images')
                ->where('good_id', (int) $goodId)
                ->whereNull('variation_id')
                ->where('is_main', true)
                ->value('file_path');

            // Если главного изображения нет, берем первое доступное
            if (! $mainImage) {
                $mainImage = DB::table('shop_good_images')
                    ->where('good_id', (int) $goodId)
                    ->whereNull('variation_id')
                    ->orderBy('sort_order')
                    ->value('file_path');
            }

            $imageUrl = null;
            if ($mainImage) {
                // Добавляем ведущий слэш если его нет
                $imageUrl = $mainImage;
                if (! str_starts_with($imageUrl, '/')) {
                    $imageUrl = '/'.$imageUrl;
                }
            }

            return [
                'name' => $good->name ?? null,
                'image' => $imageUrl,
                'sku' => $good->sku ?? null,
                'slug' => $good->slug ?? null,
                'sale_price' => $good->sale_price ?? null,
            ];
        } catch (\Exception $e) {
            Log::error('Ошибка получения информации о товаре: '.$e->getMessage());

            return [
                'name' => null,
                'image' => null,
                'sku' => null,
                'slug' => null,
                'sale_price' => null,
            ];
        }
    }
}
