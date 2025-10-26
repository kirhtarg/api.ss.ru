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
        'total_discount_amount',
        'promo_code',
        'promo_code_id',
        'use_bonus_points',
        'bonus_points_to_use',
        'order_bonus_points',
        'delivery_cost',
        'total_amount',
        'total_quantity',
        'payment_method',
        'payment_method_id',
        'yandex_pay_order_id',
        'shipping_method',
        'shipping_address',
        'notes',
        'ip_address',
        'user_agent',
        'metadata'
    ];

    protected $casts = [
        'items' => 'array',
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'sale_discount_amount' => 'decimal:2',
        'registered_user_discount_amount' => 'decimal:2',
        'promo_code_discount_amount' => 'decimal:2',
        'total_discount_amount' => 'decimal:2',
        'delivery_cost' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'total_quantity' => 'integer',
        'use_bonus_points' => 'boolean',
        'bonus_points_to_use' => 'integer',
        'order_bonus_points' => 'integer',
        'payment_status_id' => 'integer',
        'delivery_status_id' => 'integer',
        'metadata' => 'array'
    ];


    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function status()
    {
        return $this->belongsTo(ShopOrderStatus::class, 'status_id');
    }

    public function paymentStatus()
    {
        return $this->belongsTo(ShopPaymentStatus::class, 'payment_status_id');
    }

    public function deliveryStatus()
    {
        return $this->belongsTo(ShopDeliveryStatus::class, 'delivery_status_id');
    }

    public function getItemsWithDetails()
    {
        if (!$this->items) {
            return [];
        }

        $items = is_string($this->items) ? json_decode($this->items, true) : $this->items;
        
        if (!is_array($items)) {
            return [];
        }

        // Обогащаем каждый товар дополнительной информацией
        return array_map(function ($item) {
            // Получаем полную информацию о товаре из базы данных
            $goodId = $item['good_id'] ?? null;
            
            
            $goodInfo = $this->getGoodInfo($goodId);
            
            $quantity = $item['quantity'] ?? 1;
            $price = $item['price'] ?? 0;
            $salePrice = $item['sale_price'] ?? $goodInfo['sale_price'] ?? null;
            
            // Рассчитываем итоговую стоимость
            $total = $item['total'] ?? 0;
            if ($total == 0) {
                // Если итоговая стоимость не указана, рассчитываем её
                if ($salePrice && $salePrice > 0) {
                    $total = $salePrice * $quantity;
                } else {
                    $total = $price * $quantity;
                }
            }
            
            $result = [
                'id' => $item['id'] ?? null,
                'good_id' => $goodId,
                'good_name' => $item['good_name'] ?? $goodInfo['name'] ?? 'Неизвестный товар',
                'good_image' => $item['good_image'] ?? $goodInfo['image'] ?? null,
                'good_sku' => $item['good_sku'] ?? $goodInfo['sku'] ?? null,
                'variation_id' => $item['variation_id'] ?? null,
                'variation_name' => $item['variation_name'] ?? null,
                'variation_sku' => $item['variation_sku'] ?? null,
                'quantity' => $quantity,
                'price' => $price,
                'sale_price' => $salePrice,
                'discount_amount' => $item['discount_amount'] ?? 0,
                'bonus_points' => $item['bonus_points'] ?? 0,
                'total' => $total,
            ];
            
            return $result;
        }, $items);
    }

    private function getGoodInfo($goodId)
    {
        if (!$goodId) {
            return [
                'name' => null, 
                'image' => null, 
                'sku' => null, 
                'sale_price' => null
            ];
        }

        try {
            // Получаем информацию о товаре с изображением
            $good = DB::table('shop_goods')
                ->leftJoin('shop_good_images', function($join) {
                    $join->on('shop_goods.id', '=', 'shop_good_images.good_id')
                         ->whereNull('shop_good_images.variation_id')
                         ->where('shop_good_images.is_main', true);
                })
                ->select(
                    'shop_goods.name', 
                    'shop_goods.sku', 
                    'shop_goods.sale_price',
                    'shop_good_images.file_path as image_path'
                )
                ->where('shop_goods.id', (int)$goodId)
                ->first();

            // Если главного изображения нет, берем первое доступное
            if (!$good || !$good->image_path) {
                $good = DB::table('shop_goods')
                    ->leftJoin('shop_good_images', function($join) {
                        $join->on('shop_goods.id', '=', 'shop_good_images.good_id')
                             ->whereNull('shop_good_images.variation_id');
                    })
                    ->select(
                        'shop_goods.name', 
                        'shop_goods.sku', 
                        'shop_goods.sale_price',
                        'shop_good_images.file_path as image_path'
                    )
                    ->where('shop_goods.id', (int)$goodId)
                    ->orderBy('shop_good_images.sort_order')
                    ->first();
            }

            $imageUrl = null;
            if ($good && $good->image_path) {
                // Добавляем ведущий слэш если его нет
                $imageUrl = $good->image_path;
                if (!str_starts_with($imageUrl, '/')) {
                    $imageUrl = '/' . $imageUrl;
                }
            }

            return [
                'name' => $good->name ?? null,
                'image' => $imageUrl,
                'sku' => $good->sku ?? null,
                'sale_price' => $good->sale_price ?? null
            ];
        } catch (\Exception $e) {
            Log::error('Ошибка получения информации о товаре: ' . $e->getMessage());
            return [
                'name' => null, 
                'image' => null, 
                'sku' => null, 
                'sale_price' => null
            ];
        }
    }
}