<?php

namespace App\Models;

use App\Helpers\PriceHelper;
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
        'certificate_code',
        'has_certificate',
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
        'dellin_order_id',
        'russianpost_order_id',
        'russianpost_barcode',
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
        'manager_id',
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
        'has_certificate' => 'boolean',
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

    public function manager()
    {
        return $this->belongsTo(User::class, 'manager_id');
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

    public function packages()
    {
        return $this->hasMany(ShopOrderPackage::class, 'order_id')->orderBy('number');
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

        $isCertificateOrder = ! empty(trim((string) ($this->certificate_code ?? ''))) || (bool) ($this->has_certificate ?? false);

        $resultItems = array_map(function ($item) use ($isCertificateOrder) {
            $goodId = $item['good_id'] ?? null;
            $variationId = $item['variation_id'] ?? null;

            $goodInfo = $this->getGoodInfo($goodId);
            $itemTags = $item['tags'] ?? [];
            if (empty($itemTags) && $goodId) {
                try {
                    $itemTags = DB::table('shop_tags')
                        ->join('shop_good_tags', 'shop_good_tags.shop_tag_id', '=', 'shop_tags.id')
                        ->where('shop_good_tags.shop_good_id', (int) $goodId)
                        ->select('shop_tags.id', 'shop_tags.name', 'shop_tags.slug', 'shop_tags.color', 'shop_tags.disables_bonuses', 'shop_tags.disables_registered_discount', 'shop_tags.extra_discount_percent', 'shop_tags.increased_bonus_percent')
                        ->get()
                        ->map(fn ($tag) => (array) $tag)
                        ->toArray();
                } catch (\Exception $e) {
                    $itemTags = [];
                }
            }
            $tagDiscount = collect($itemTags)
                ->filter(fn ($tag) => (float) ($tag['extra_discount_percent'] ?? 0) > 0)
                ->sortByDesc(fn ($tag) => (float) ($tag['extra_discount_percent'] ?? 0))
                ->first();
            
            // Если variation_name или variation_sku отсутствуют, пробуем достать из БД
            $variationName = $item['variation_name'] ?? null;
            $variationSku = $item['variation_sku'] ?? null;
            $attributes = $item['attributes'] ?? null;

            // Если название содержит техническое "Variation", считаем его отсутствующим, чтобы принудительно обновить из БД
            if ($variationName && stripos($variationName, 'Variation') !== false) {
                $variationName = null;
            }

            if ($variationId && (empty($variationName) || empty($variationSku) || empty($attributes))) {
                try {
                    $variation = DB::table('shop_good_variations')->where('id', (int)$variationId)->first();
                    if ($variation) {
                        // ПОЛНОСТЬЮ исключаем variation->name, используем ТОЛЬКО атрибуты
                        $dbAttributes = DB::table('shop_variation_attributes_values as vav')
                            ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
                            ->join('shop_variation_attributes as a', 'a.id', '=', 'av.attribute_id')
                            ->where('vav.variation_id', $variation->id)
                            ->select('a.name as attr_name', 'av.value as attr_value')
                            ->get();
                        
                        if ($dbAttributes->count() > 0) {
                            $variationName = $dbAttributes->map(function ($a) {
                                return $a->attr_name.': '.$a->attr_value;
                            })->implode(', ');
                            
                            $attributes = $dbAttributes->map(function($a) {
                                return [
                                    'name' => $a->attr_name,
                                    'value' => $a->attr_value
                                ];
                            })->toArray();
                        }
                        if (empty($variationSku)) {
                            $variationSku = $variation->sku ?? null;
                        }
                    }
                } catch (\Exception $e) {
                    Log::error('Ошибка подтягивания данных вариации в ShopOrder: ' . $e->getMessage());
                }
            }

            $quantity = (int) ($item['quantity'] ?? 1);
            $rawStoredPrice = (float) ($item['price'] ?? 0);
            $storedPrice = PriceHelper::roundPrice($rawStoredPrice);
            $basePrice = PriceHelper::roundPrice((float) ($item['base_price'] ?? $item['regular_price'] ?? $item['original_price'] ?? $rawStoredPrice));
            // Цена заказа фиксируется в момент оформления. Текущая sale_price товара
            // из каталога не должна задним числом менять уже созданный заказ.
            $salePrice = array_key_exists('sale_price', $item) && (float) $item['sale_price'] > 0
                ? PriceHelper::roundPrice((float) $item['sale_price'])
                : null;
            $finalPrice = PriceHelper::roundPrice((float) ($item['final_price'] ?? $rawStoredPrice));

            $total = PriceHelper::roundPrice($finalPrice * $quantity);
            if ($isCertificateOrder) {
                $total = PriceHelper::roundPrice($basePrice * $quantity);
                $salePrice = null;
                $finalPrice = $basePrice;
                $storedPrice = $basePrice;
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
                        $weight = $this->positiveDimensionValue($variationDimensions->weight ?? null);
                        $length = $this->positiveDimensionValue($variationDimensions->length ?? null);
                        $width = $this->positiveDimensionValue($variationDimensions->width ?? null);
                        $height = $this->positiveDimensionValue($variationDimensions->height ?? null);
                    }
                }

                if ($goodId && ($weight === null || $length === null || $width === null || $height === null)) {
                    $goodDimensions = DB::table('shop_goods')
                        ->select('weight', 'depth', 'width', 'height')
                        ->where('id', (int) $goodId)
                        ->first();

                    if ($goodDimensions) {
                        $weight = $weight ?: $this->positiveDimensionValue($goodDimensions->weight ?? null);
                        $length = $length ?: $this->positiveDimensionValue($goodDimensions->depth ?? null);
                        $width = $width ?: $this->positiveDimensionValue($goodDimensions->width ?? null);
                        $height = $height ?: $this->positiveDimensionValue($goodDimensions->height ?? null);
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
                'variation_name' => $variationName,
                'variation_sku' => $variationSku,
                'attributes' => $attributes,
                'quantity' => $quantity,
                'price' => $finalPrice,
                'raw_stored_price' => $rawStoredPrice,
                'stored_price' => $storedPrice,
                'base_price' => $basePrice,
                'sale_price' => $salePrice,
                'final_price' => $finalPrice,
                'unit_price' => $finalPrice,
                'customer_unit_price' => array_key_exists('customer_unit_price', $item)
                    ? (float) $item['customer_unit_price']
                    : null,
                'customer_total' => array_key_exists('customer_total', $item)
                    ? (float) $item['customer_total']
                    : null,
                'registered_discount_amount' => (float) ($item['registered_discount_amount'] ?? 0),
                'discount_amount' => $isCertificateOrder ? 0 : ($item['discount_amount'] ?? 0),
                'tag_discount_percent' => (float) ($item['tag_discount_percent'] ?? ($tagDiscount['extra_discount_percent'] ?? 0)),
                'tag_discount_name' => $item['tag_discount_name'] ?? ($tagDiscount['name'] ?? null),
                'tags' => $itemTags,
                'bonus_points' => $item['bonus_points'] ?? 0,
                'total' => $total,
                'show_demping' => (bool) ($item['show_demping'] ?? false),
                'demping_price' => isset($item['demping_price']) ? (float) $item['demping_price'] : null,
                'weight' => $this->positiveDimensionValue($item['weight'] ?? null) ?? ($weight !== null ? (float) $weight : null),
                'length' => $this->positiveDimensionValue($item['length'] ?? $item['depth'] ?? null) ?? ($length !== null ? (float) $length : null),
                'width' => $this->positiveDimensionValue($item['width'] ?? null) ?? ($width !== null ? (float) $width : null),
                'height' => $this->positiveDimensionValue($item['height'] ?? null) ?? ($height !== null ? (float) $height : null),
            ];

            return $result;
        }, $items);

        return $this->ensureCustomerPrices($resultItems, $isCertificateOrder);
    }

    private function ensureCustomerPrices(array $items, bool $isCertificateOrder): array
    {
        if ($isCertificateOrder || ! $items) {
            return $items;
        }

        $hasStoredCustomerPrices = collect($items)->every(
            fn (array $item) => $item['customer_unit_price'] !== null && $item['customer_total'] !== null
        );
        if ($hasStoredCustomerPrices) {
            return $items;
        }

        $discountCents = max(0, (int) round((float) ($this->registered_user_discount_amount ?? 0) * 100));
        $legacyNoBonusTags = collect(explode(',', (string) (Setting::where('key', 'tag_no_bonus')->value('value') ?? '')))
            ->map(fn ($value) => mb_strtolower(trim($value)))
            ->filter();
        $discountToSale = Setting::where('key', 'discount_to_d_text')->value('value');
        $discountToSaleAllowed = ! in_array($discountToSale, [null, '', 0, '0', false], true);
        $eligible = [];
        $eligibleBaseCents = 0;

        foreach ($items as $index => &$item) {
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $unitPrice = max(0, (float) ($item['final_price'] ?? $item['unit_price'] ?? $item['price'] ?? 0));
            $lineCents = max(0, (int) round($unitPrice * $quantity * 100));
            $item['customer_unit_price'] = round($unitPrice, 2);
            $item['customer_total'] = round($lineCents / 100, 2);

            $tags = collect($item['tags'] ?? []);
            $blockedByTag = $tags->contains(function ($tag) use ($legacyNoBonusTags) {
                $tag = (array) $tag;
                $name = mb_strtolower(trim((string) ($tag['name'] ?? '')));
                $slug = mb_strtolower(trim((string) ($tag['slug'] ?? '')));

                return (bool) ($tag['disables_bonuses'] ?? false)
                    || (bool) ($tag['disables_registered_discount'] ?? false)
                    || $legacyNoBonusTags->contains($name)
                    || $legacyNoBonusTags->contains($slug);
            });
            $hasSalePrice = isset($item['sale_price'])
                && (float) $item['sale_price'] > 0
                && (float) $item['sale_price'] < (float) ($item['base_price'] ?? $unitPrice);
            $isDumping = ! empty($item['show_demping']) && (float) ($item['demping_price'] ?? 0) > 0;

            if (! $blockedByTag && ! $isDumping && ($discountToSaleAllowed || ! $hasSalePrice) && $lineCents > 0) {
                $eligible[] = $index;
                $eligibleBaseCents += $lineCents;
            }
        }
        unset($item);

        $remainingDiscount = min($discountCents, $eligibleBaseCents);
        $remainingBase = $eligibleBaseCents;
        foreach ($eligible as $position => $index) {
            $quantity = max(1, (int) ($items[$index]['quantity'] ?? 1));
            $lineCents = max(0, (int) round((float) $items[$index]['final_price'] * $quantity * 100));
            $lineDiscount = $position === array_key_last($eligible) || $remainingBase <= 0
                ? $remainingDiscount
                : min($remainingDiscount, (int) round($remainingDiscount * ($lineCents / $remainingBase)));
            $customerTotal = max(0, $lineCents - $lineDiscount);
            $items[$index]['registered_discount_amount'] = round($lineDiscount / 100, 2);
            $items[$index]['customer_total'] = round($customerTotal / 100, 2);
            $items[$index]['customer_unit_price'] = round(($customerTotal / 100) / $quantity, 2);
            $remainingDiscount -= $lineDiscount;
            $remainingBase -= $lineCents;
        }

        return $items;
    }

    private function positiveDimensionValue($value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $value = (float) $value;

        return $value > 0 ? $value : null;
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
