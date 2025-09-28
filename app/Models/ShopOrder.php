<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ShopOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_number',
        'user_id',
        'status_id',
        'customer_name',
        'customer_email',
        'customer_phone',
        'items',
        'subtotal',
        'discount_amount',
        'total_amount',
        'total_quantity',
        'payment_method',
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
        'total_amount' => 'decimal:2',
        'total_quantity' => 'integer',
        'metadata' => 'array'
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($order) {
            if (empty($order->order_number)) {
                $order->order_number = self::generateOrderNumber();
            }
        });
    }

    /**
     * Пользователь, создавший заказ
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Статус заказа
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(ShopOrderStatus::class, 'status_id');
    }

    /**
     * Accessor для получения названия статуса
     */
    public function getStatusNameAttribute()
    {
        return $this->status ? $this->status->name : 'Неизвестно';
    }

    /**
     * Генерация номера заказа
     */
    public static function generateOrderNumber(): string
    {
        $date = now()->format('Ymd');
        $lastOrder = self::whereDate('created_at', today())
            ->orderBy('id', 'desc')
            ->first();
        
        $sequence = $lastOrder ? (int) substr($lastOrder->order_number, -3) + 1 : 1;
        
        return 'ORD-' . $date . '-' . str_pad($sequence, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Scope для заказов пользователя
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope для заказов по статусу
     */
    public function scopeByStatus($query, $statusId)
    {
        return $query->where('status_id', $statusId);
    }

    /**
     * Scope для поиска по номеру заказа
     */
    public function scopeByOrderNumber($query, $orderNumber)
    {
        return $query->where('order_number', 'like', "%{$orderNumber}%");
    }

    /**
     * Scope для поиска по клиенту
     */
    public function scopeByCustomer($query, $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('customer_name', 'like', "%{$search}%")
              ->orWhere('customer_email', 'like', "%{$search}%")
              ->orWhere('customer_phone', 'like', "%{$search}%");
        });
    }

    /**
     * Scope для сортировки
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    /**
     * Получить товары заказа с дополнительной информацией
     */
    public function getItemsWithDetails()
    {
        $items = $this->items ?? [];
        $goodIds = collect($items)->pluck('good_id')->unique();
        
        if ($goodIds->isEmpty()) {
            return $items;
        }

        $goods = ShopGood::whereIn('id', $goodIds)
            ->with(['images' => function ($query) {
                $query->where('is_main', true)->limit(1);
            }])
            ->get()
            ->keyBy('id');

        return collect($items)->map(function ($item) use ($goods) {
            $good = $goods->get($item['good_id']);
            if ($good) {
                $item['good_name'] = $good->name;
                $item['good_sku'] = $good->sku;
                $item['good_image'] = $good->images->first() ? 
                    $this->getImageUrl($good->images->first()->file_path) : null;
            }
            return $item;
        })->toArray();
    }

    /**
     * Получить URL изображения
     */
    private function getImageUrl($filePath)
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
}
