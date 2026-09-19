<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\ShopGood;
use App\Models\ShopGoodVariation;
use App\Models\ShopOrder;
use App\Models\ShopOrderLog;
use App\Models\ShopDeliveryStatus;
use App\Models\ShopOrderStatus;
use App\Models\ShopPaymentStatus;
use App\Models\ShopStock;
use App\Models\ShopWarehouse;
use App\Models\YcpCheckoutSession;
use App\Services\OrderCalculationService;
use App\Services\Partner\PartnerDeliveryService;
use App\Services\Partner\PartnerStockAvailabilityService;
use App\Services\StockReservationService;
use App\Services\YcpSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class YcpController extends Controller
{
    private ?array $settingsCache = null;
    private ?string $mainSiteUrlCache = null;

    public function __construct(
        private readonly PartnerStockAvailabilityService $availability,
        private readonly OrderCalculationService $calculation,
        private readonly PartnerDeliveryService $deliveryCalculator,
        private readonly StockReservationService $reservations,
        private readonly YcpSettingsService $settings,
    ) {}

    public function warehouses(Request $request): JsonResponse
    {
        $pagination = $request->validate(['limit' => ['required', 'integer', 'min:1', 'max:1000'], 'offset' => ['required', 'integer', 'min:0']]);
        $query = ShopWarehouse::query()->active()->ordered();
        $total = (clone $query)->count();
        $warehouses = $query->offset($pagination['offset'])->limit($pagination['limit'])->get();
        $yandexDelivery = $this->settingsData()['delivery_mode'] === 'yandex';
        return response()->json(['warehouses' => $warehouses->map(fn (ShopWarehouse $warehouse) => [
            'id' => (string) $warehouse->id,
            'title' => $warehouse->name,
            'address' => $warehouse->address ?? '',
            'phone' => $warehouse->phone ?? '',
            'description' => $warehouse->description ?? '',
            'self_pickup_options' => ['enabled' => false],
            'ycp_delivery_options' => ['enabled' => $yandexDelivery],
        ])->values(), 'total_count' => $total]);
    }

    public function basketCheck(Request $request): JsonResponse
    {
        $input = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.id' => ['required', 'string', 'max:100'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'offers_id_from_merchant_center' => ['required', 'boolean'],
            'locality' => ['required', 'string', 'max:255'],
            'is_health_check' => ['required', 'boolean'],
        ]);

        $result = [];
        foreach ($input['items'] as $requested) {
            [$good, $variation] = $this->resolveOffer($requested['id'], (bool) ($input['offers_id_from_merchant_center'] ?? false));
            if (! $good || ! $good->is_active || ($variation && ! $variation->is_active)) {
                return response()->json(['error' => 'Товар недоступен: '.$requested['id']], 404);
            }
            $activeVariations = $good->variations()->where('is_active', true)->with('stock')->get();
            $quantity = $variation
                ? $this->availability->quantity($good, $variation)
                : ($activeVariations->isNotEmpty()
                    ? (int) $activeVariations->sum(fn ($candidate) => $this->availability->quantity($good, $candidate))
                    : $this->availability->quantity($good));
            if ($quantity < (int) $requested['quantity']) {
                return response()->json(['error' => 'Недостаточный остаток: '.$requested['id']], 409);
            }

            $variants = $activeVariations->load('attributeValues.attribute', 'images');
            $result[] = $this->serializeOffer($good, $variation, $quantity, $variants);
        }

        return response()->json(['items' => $result]);
    }

    public function deliveryOptions(Request $request): JsonResponse
    {
        if ($this->settingsData()['delivery_mode'] !== 'merchant') {
            return response()->json(['code' => 'NOT_SUPPORTED', 'message' => 'Yandex calculates delivery in the selected mode'], 404);
        }

        $deliveryRequest = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'string'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'delivery_target' => ['required', 'array'],
            'delivery_target.delivery_method' => ['required', 'in:courier,pickup_point'],
            'delivery_target.address' => ['required', 'array'],
            'delivery_target.address.locality' => ['required', 'string', 'max:255'],
            'delivery_target.address.pickup_point_id' => ['nullable', 'string', 'max:100'],
        ]);

        $items = $this->resolveCheckoutItems($deliveryRequest['items']);
        $subtotal = round(array_sum(array_column($items, 'total')), 2);
        $methods = \App\Models\ShopDeliveryMethod::query()->active()->ordered()->get();
        if ($methods->isEmpty()) {
            return response()->json(['code' => 'DELIVERY_UNAVAILABLE', 'message' => 'No active delivery methods'], 422);
        }
        $options = [];
        $locality = $deliveryRequest['delivery_target']['address']['locality'];
        foreach ($methods as $method) {
            if ($method->type === 'cdek') {
                try {
                    $city = collect($this->deliveryCalculator->cities($locality))
                        ->sortBy(fn (array $candidate) => mb_strtolower((string) $candidate['name']) === mb_strtolower($locality) ? 0 : 1)
                        ->first();
                    if ($city && ! empty($city['code'])) {
                        $quote = $this->deliveryCalculator->calculate([
                            'method_id' => $method->id,
                            'city_code' => $city['code'],
                            'pvz_code' => $deliveryRequest['delivery_target']['address']['pickup_point_id'] ?? null,
                        ], $items, $subtotal);
                        foreach ($quote['available_tariffs'] as $tariff) {
                            $from = now()->addDays(max(1, (int) ($tariff['period_min'] ?? 1)))->toDateString();
                            $to = now()->addDays(max(1, (int) ($tariff['period_max'] ?? $tariff['period_min'] ?? 1)))->toDateString();
                            $options[] = [
                                'id' => 'ss-delivery-'.$method->id.'-'.$tariff['code'],
                                'delivery_date_interval' => ['start_interval' => ['date' => $from], 'end_interval' => ['date' => $to], 'time_zone' => 3],
                                'cost' => round((float) $tariff['amount'], 2),
                            ];
                        }
                        continue;
                    }
                } catch (\Throwable $e) {
                    Log::warning('YCP CDEK delivery options calculation failed', ['method_id' => $method->id, 'message' => $e->getMessage()]);
                }
                continue;
            }

            $options[] = [
                'id' => (string) $method->id,
                'delivery_date_interval' => ['start_interval' => ['date' => now()->addDays(1)->toDateString()], 'end_interval' => ['date' => now()->addDays(7)->toDateString()], 'time_zone' => 3],
                'cost' => round((float) $method->getDeliveryCost($subtotal), 2),
            ];
        }
        return response()->json(['delivery_options' => array_slice($options, 0, 100)]);
    }

    public function checkout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['required', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.id' => ['required', 'string', 'max:100'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.regular_price' => ['required', 'numeric', 'min:0'],
            'items.*.final_price' => ['required', 'numeric', 'min:0'],
            'warehouse_id' => ['required', 'string', 'max:100'],
            'customer' => ['required', 'array'],
            'customer.full_name' => ['required', 'string', 'max:255'],
            'customer.phone' => ['required', 'string', 'max:64'],
            'customer.email' => ['nullable', 'email', 'max:255'],
            'delivery' => ['required', 'array'],
            'delivery.delivery_method' => ['required', 'string', 'max:100'],
            'delivery.service_type' => ['required', 'string', 'max:100'],
            'delivery.ycp_delivery_option_id' => ['nullable', 'string', 'max:150'],
            'delivery.price' => ['required', 'numeric', 'min:0'],
            'delivery.address' => ['nullable'],
            'delivery.delivery_date_interval' => ['nullable', 'array'],
        ]);

        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        try {
            $order = DB::transaction(function () use ($data, $hash): ShopOrder {
                $session = YcpCheckoutSession::query()->where('session_id', $data['session_id'])->lockForUpdate()->first();
                if ($session?->shop_order_id) {
                    if (! hash_equals((string) $session->request_hash, $hash)) {
                        throw new ConflictHttpException('session_id was already used with different checkout data');
                    }
                    return ShopOrder::query()->findOrFail($session->shop_order_id);
                }

                $merchantCalculatesDelivery = $this->settingsData()['delivery_mode'] === 'merchant';
                $warehouse = $merchantCalculatesDelivery ? null : ShopWarehouse::query()->active()->find($data['warehouse_id']);
                if (! $merchantCalculatesDelivery && ! $warehouse) {
                    throw new ConflictHttpException('Selected warehouse is unavailable');
                }
                $items = $this->resolveCheckoutItems($data['items']);
                foreach ($data['items'] as $index => $requestedItem) {
                    $checkedItem = $items[$index] ?? null;
                    if (! $checkedItem) continue;
                    if (abs((float) $requestedItem['final_price'] - (float) $checkedItem['final_price']) > 0.01
                        || abs((float) $requestedItem['regular_price'] - (float) $checkedItem['base_price']) > 0.01) {
                        throw new ConflictHttpException('Product price has changed; check the basket again');
                    }
                }
                foreach ($items as $item) {
                    if ($merchantCalculatesDelivery) {
                        $good = ShopGood::query()->find($item['good_id']);
                        $variation = ! empty($item['variation_id']) ? ShopGoodVariation::query()->find($item['variation_id']) : null;
                        if (! $good || $this->availability->quantity($good, $variation) < (int) $item['quantity']) {
                            throw new ConflictHttpException('Insufficient available stock');
                        }
                        continue;
                    }
                    $stock = ShopStock::query()->where('good_id', $item['good_id'])->where('warehouse_id', $warehouse->id)
                        ->when($item['variation_id'], fn ($q) => $q->where('variation_id', $item['variation_id']), fn ($q) => $q->whereNull('variation_id'))->first();
                    $legacy = ShopStock::query()->where('good_id', $item['good_id'])
                        ->when($item['variation_id'], fn ($q) => $q->where('variation_id', $item['variation_id']), fn ($q) => $q->whereNull('variation_id'))->exists();
                    $available = $stock
                        ? max(0, $stock->quantity - $stock->reserved_quantity)
                        : (! $legacy && ($warehouse->is_default || ShopWarehouse::query()->active()->count() === 1)
                            ? max(0, (int) ($item['variation_id'] ? ShopGoodVariation::find($item['variation_id'])?->stock_quantity : ShopGood::find($item['good_id'])?->stock_quantity))
                            : 0);
                    if ($available < (int) $item['quantity']) throw new ConflictHttpException('Insufficient stock at selected warehouse');
                }
                $subtotal = round(array_sum(array_column($items, 'total')), 2);
                $serviceType = (string) $data['delivery']['service_type'];
                $deliveryOptionId = (string) ($data['delivery']['ycp_delivery_option_id'] ?? '');
                $serviceMethodId = null;
                if (ctype_digit($deliveryOptionId)) {
                    $serviceMethodId = (int) $deliveryOptionId;
                } elseif (preg_match('/^ss-delivery-(\d+)-/', $deliveryOptionId, $serviceMatch)) {
                    $serviceMethodId = (int) $serviceMatch[1];
                }
                $localMethodType = match ($serviceType) {
                    'cdek' => 'cdek',
                    'russian_post' => 'post',
                    'self_pickup' => 'pickup',
                    default => $data['delivery']['delivery_method'],
                };
                $deliveryMethod = \App\Models\ShopDeliveryMethod::query()->active()->find($serviceMethodId)
                    ?? \App\Models\ShopDeliveryMethod::query()->active()->where('type', $localMethodType)->first()
                    ?? \App\Models\ShopDeliveryMethod::getDefault();
                if (! $deliveryMethod) {
                    throw new ConflictHttpException('Selected delivery method is unavailable');
                }
                $deliveryPrice = (float) $data['delivery']['price'];
                if ($merchantCalculatesDelivery && $deliveryMethod->type !== 'cdek') {
                    $deliveryPrice = (float) $deliveryMethod->getDeliveryCost($subtotal);
                }
                $orderNumber = $this->generateOrderNumber();
                $order = ShopOrder::create([
                    'order_number' => $orderNumber,
                    'status_id' => ShopOrderStatus::query()->where('is_active', true)->orderBy('sort_order')->value('id') ?? 1,
                    'customer_name' => $data['customer']['full_name'],
                    'customer_email' => $data['customer']['email'] ?? null,
                    'customer_phone' => $data['customer']['phone'],
                    'items' => $items,
                    'subtotal' => $subtotal,
                    'delivery_cost' => $deliveryPrice,
                    'total_amount' => round($subtotal + $deliveryPrice, 2),
                    'total_quantity' => array_sum(array_column($items, 'quantity')),
                    'payment_method' => 'ycp',
                    'shipping_method' => $data['delivery']['service_display_name'] ?? $deliveryMethod->name,
                    'shipping_method_id' => $deliveryMethod->id,
                    'shipping_address' => $this->formatAddress($data['delivery']['address'] ?? null),
                    'metadata' => ['source' => 'ycp', 'ycp_session_id' => $data['session_id'], 'warehouse_id' => $warehouse?->id, 'delivery' => $data['delivery']],
                ]);
                $this->reservations->reserveForOrder($order, $items, 30, 'YCP', $warehouse?->id);
                ShopOrderLog::logOrderCreated($order->id, $data['customer']['full_name'], ShopOrderLog::SECTION_CHECKOUT, $order->order_number, 'Yandex Commerce Protocol');
                YcpCheckoutSession::updateOrCreate(['session_id' => $data['session_id']], [
                    'shop_order_id' => $order->id,
                    'ycp_order_id' => null,
                    'status' => 'checkout_created',
                    'request_hash' => $hash,
                    'snapshot' => ['order_number' => $order->order_number, 'items' => $items, 'delivery_cost' => $deliveryPrice],
                ]);
                return $order;
            }, 3);
        } catch (ConflictHttpException $e) {
            return response()->json(['code' => 'CHECKOUT_CONFLICT', 'message' => $e->getMessage()], 409);
        }

        return response()->json(['order_number' => (string) $order->order_number], 201);
    }

    public function placed(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['required', 'string', 'max:255'],
            'order_id' => ['required', 'string', 'max:100'],
            'order_number' => ['nullable', 'integer'],
            'payment_method' => ['required', 'in:online,on_delivery'],
            'online_payment_method' => ['nullable', 'string', 'max:100'],
        ]);

        DB::transaction(function () use ($data): void {
            $session = YcpCheckoutSession::query()->where('session_id', $data['session_id'])->lockForUpdate()->firstOrFail();
            if ($session->status === 'placed') {
                return;
            }
            if ($session->status !== 'checkout_created') {
                abort(409, 'YCP checkout session is not active');
            }
            $order = ShopOrder::query()->lockForUpdate()->findOrFail($session->shop_order_id);
            $payStatus = ShopPaymentStatus::query()->where('is_active', true)->where('name', $data['payment_method'] === 'online' ? 'paid' : 'pending')->value('id');
            $order->update([
                'payment_status_id' => $payStatus,
                'payed' => $data['payment_method'] === 'online',
                'metadata' => array_merge($order->metadata ?? [], ['ycp_payment_method' => $data['payment_method'], 'ycp_online_payment_method' => $data['online_payment_method'] ?? null]),
            ]);
            if ($data['payment_method'] === 'online') {
                ShopOrderLog::logOrderPaid($order->id, 'Yandex Commerce Protocol', 'Оплата подтверждена YCP', ShopOrderLog::SECTION_PAYMENT, $order->order_number);
            }
            $this->reservations->commitForOrder($order);
            $session->update(['status' => 'placed', 'ycp_order_id' => $data['order_id']]);
        }, 3);

        return response()->json(null, 200);
    }

    public function cancelCheckout(Request $request): JsonResponse
    {
        $sessionId = (string) $request->query('session_id', '');
        if ($sessionId === '') return response()->json(['code' => 'INVALID_REQUEST'], 400);
        $this->cancelBySession($sessionId, 'checkout_cancelled');
        return response()->json(null, 200);
    }

    public function cancelOrder(Request $request): JsonResponse
    {
        $orderId = (string) $request->query('order_id', '');
        $session = YcpCheckoutSession::query()->where('ycp_order_id', $orderId)->first();
        if (! $session) return response()->json(null, 200);
        $this->cancelBySession($session->session_id, 'order_cancelled');
        return response()->json(null, 200);
    }

    public function delivered(Request $request): JsonResponse
    {
        if ($this->settingsData()['delivery_mode'] !== 'yandex') {
            return response()->json(['error' => 'Yandex-managed delivery is disabled'], 409);
        }

        $orderId = (string) $request->query('order_id', '');
        if ($orderId === '') return response()->json(['error' => 'order_id is required'], 400);
        $data = $request->validate([
            'purchased_items' => ['required', 'array'],
            'purchased_items.*.id' => ['required', 'string', 'max:100'],
            'purchased_items.*.quantity' => ['required', 'integer', 'min:0'],
        ]);

        $session = YcpCheckoutSession::query()->where('ycp_order_id', $orderId)->first();
        if (! $session || ! $session->shop_order_id) return response()->json(['error' => 'Order not found'], 404);

        $result = DB::transaction(function () use ($session, $data): array {
            $order = ShopOrder::query()->lockForUpdate()->find($session->shop_order_id);
            if (! $order) return ['error' => 'Order not found'];
            if ($order->status?->is_cancelled || $session->status === 'order_cancelled') return ['error' => 'Order is cancelled'];

            $purchased = collect($data['purchased_items'])->groupBy('id')->map(fn ($rows) => (int) $rows->sum('quantity'));
            $orderedItems = collect($order->items ?? [])->groupBy(function (array $item): string {
                return ! empty($item['variation_id']) ? 'variation_'.$item['variation_id'] : 'good_'.$item['good_id'];
            })->map(fn ($rows) => array_replace($rows->first(), ['quantity' => (int) $rows->sum('quantity')]));
            $allowedIds = [];
            foreach ($orderedItems as $offerId => $item) {
                $merchantId = (string) (! empty($item['variation_id']) ? $item['variation_id'] : $item['good_id']);
                $allowedIds[] = $offerId;
                $allowedIds[] = $merchantId;
                $deliveredCount = (int) ($purchased->get($offerId) ?? $purchased->get($merchantId) ?? 0);
                $quantity = (int) ($item['quantity'] ?? 0);
                if ($deliveredCount > $quantity) return ['error' => 'Delivered quantity exceeds ordered quantity'];
            }
            if ($purchased->keys()->diff($allowedIds)->isNotEmpty()) return ['error' => 'Delivered items do not match the order'];

            $metadata = $order->metadata ?? [];
            $statuses = $metadata['ycp_delivery_statuses'] ?? [];
            if (! is_array($statuses) || $statuses === []) $statuses = [['status' => 'new', 'timestamp' => $order->created_at?->timestamp ?? now()->timestamp]];
            if (end($statuses)['status'] !== 'delivered') {
                $statuses[] = ['status' => 'delivered', 'timestamp' => now()->timestamp];
            }
            $metadata['ycp_delivery_statuses'] = $statuses;
            $metadata['ycp_purchased_items'] = $data['purchased_items'];
            $order->update([
                'delivery_status_id' => ShopDeliveryStatus::query()->where('name', 'delivered')->value('id') ?? $order->delivery_status_id,
                'metadata' => $metadata,
            ]);
            $session->update(['status' => 'delivered']);
            ShopOrderLog::createLog($order->id, 'Заказ доставлен (YCP)', [
                'user_name' => 'Yandex Commerce Protocol', 'section' => ShopOrderLog::SECTION_DELIVERY,
                'info' => 'Заказ № '.$order->order_number,
            ]);
            return ['success' => true];
        }, 3);

        if (isset($result['error'])) return response()->json(['error' => $result['error']], 409);
        return response()->json(null, 200);
    }

    public function order(Request $request): JsonResponse
    {
        $orderId = (string) $request->query('order_id', '');
        if ($orderId === '') return response()->json(['error' => 'order_id is required'], 400);
        $session = YcpCheckoutSession::query()->where('ycp_order_id', $orderId)->first();
        $order = $session?->shop_order_id ? ShopOrder::query()->find($session->shop_order_id) : null;
        if (! $order) return response()->json(['error' => 'Order not found'], 404);

        $metadata = $order->metadata ?? [];
        $statuses = $metadata['ycp_delivery_statuses'] ?? null;
        if (! is_array($statuses) || $statuses === []) {
            $statuses = [['status' => 'new', 'timestamp' => $order->created_at?->timestamp ?? now()->timestamp]];
            $deliveryStatus = $order->deliveryStatus?->name;
            $mappedStatus = match ($deliveryStatus) {
                'transferred_to_courier' => 'in_progress',
                'in_transit' => 'in_progress',
                'at_pickup_point' => 'arrived_to_pickup_point',
                'delivered' => 'delivered',
                'cancelled' => 'cancelled',
                default => null,
            };
            if ($mappedStatus) $statuses[] = ['status' => $mappedStatus, 'timestamp' => $order->updated_at?->timestamp ?? now()->timestamp];
        }
        $items = collect($order->items ?? [])->map(function (array $item) use ($metadata): array {
            $id = ! empty($item['variation_id']) ? 'variation_'.$item['variation_id'] : 'good_'.$item['good_id'];
            $delivered = collect($metadata['ycp_purchased_items'] ?? [])->first(fn (array $purchased) => in_array((string) ($purchased['id'] ?? ''), [$id, (string) ($item['variation_id'] ?? $item['good_id'])], true));
            $quantity = (int) ($item['quantity'] ?? 0);
            return ['id' => $id, 'quantity' => $quantity, 'refused_count' => max(0, $quantity - (int) ($delivered['quantity'] ?? $quantity))];
        })->values();
        $response = ['items' => $items, 'delivery_statuses' => $statuses];
        $trackingUrl = $metadata['tracking_url'] ?? null;
        if (is_string($trackingUrl) && filter_var($trackingUrl, FILTER_VALIDATE_URL)) $response['tracking_url'] = $trackingUrl;
        return response()->json($response);
    }

    private function cancelBySession(string $sessionId, string $status): void
    {
        DB::transaction(function () use ($sessionId, $status): void {
            $session = YcpCheckoutSession::query()->where('session_id', $sessionId)->lockForUpdate()->first();
            if (! $session || ! $session->shop_order_id) return;
            $order = ShopOrder::query()->lockForUpdate()->find($session->shop_order_id);
            if (! $order || $order->payed) return;
            $cancelled = ShopOrderStatus::query()->where('is_active', true)->where('is_cancelled', true)->value('id');
            if ($cancelled) $order->update(['status_id' => $cancelled]);
            $this->reservations->releaseForOrder($order);
            $metadata = $order->metadata ?? [];
            $statuses = $metadata['ycp_delivery_statuses'] ?? [];
            if (! is_array($statuses) || $statuses === []) $statuses = [['status' => 'new', 'timestamp' => $order->created_at?->timestamp ?? now()->timestamp]];
            if (end($statuses)['status'] !== 'cancelled') $statuses[] = ['status' => 'cancelled', 'timestamp' => now()->timestamp];
            $metadata['ycp_delivery_statuses'] = $statuses;
            $order->update(['metadata' => $metadata]);
            $session->update(['status' => $status]);
        }, 3);
    }

    private function resolveOffer(string $id, bool $fromFeed): array
    {
        if ($fromFeed && ctype_digit($id)) {
            return [ShopGood::query()->find($id), null];
        }
        if (preg_match('/^good_(\d+)$/', $id, $m)) return [ShopGood::query()->find($m[1]), null];
        if (preg_match('/^variation_(\d+)$/', $id, $m)) {
            $variation = ShopGoodVariation::query()->with('good')->find($m[1]);
            return [$variation?->good, $variation];
        }
        if (ctype_digit($id)) return [ShopGood::query()->find($id), null];
        return [null, null];
    }

    private function resolveCheckoutItems(array $requestedItems): array
    {
        $items = [];
        foreach ($requestedItems as $requested) {
            [$good, $variation] = $this->resolveOffer((string) $requested['id'], false);
            if (! $good || ! $good->is_active || ($variation && ! $variation->is_active)) abort(409, 'Product is unavailable');
            if (! $variation && $good->variations()->where('is_active', true)->exists()) abort(409, 'Select a product variation');
            $quantity = (int) $requested['quantity'];
            if ($this->availability->quantity($good, $variation) < $quantity) abort(409, 'Insufficient stock');
            $price = $this->calculation->calculateFinalUnitPrice($good, $variation);
            $unit = round((float) $price['final_price'], 2);
            $items[] = [
                'good_id' => $good->id, 'good_name' => $good->name, 'good_sku' => $good->sku,
                'variation_id' => $variation?->id, 'variation_name' => $variation?->display_name, 'variation_sku' => $variation?->sku,
                'quantity' => $quantity, 'price' => $unit, 'base_price' => round((float) $price['base_price'], 2),
                'sale_price' => round((float) $price['sale_price'], 2), 'final_price' => $unit, 'total' => round($unit * $quantity, 2),
            ];
        }
        return $items;
    }

    private function serializeOffer(ShopGood $good, ?ShopGoodVariation $selectedVariation, int $available, $variants): array
    {
        $priceItem = $selectedVariation;
        if (! $priceItem && $variants->isNotEmpty()) {
            $priceItem = $variants->filter(fn ($candidate) => $this->availability->quantity($good, $candidate) > 0)
                ->sortBy(fn ($candidate) => (float) $this->calculation->calculateFinalUnitPrice($good, $candidate)['final_price'])
                ->first() ?? $variants->first();
        }
        $priceInfo = $this->calculation->calculateFinalUnitPrice($good, $priceItem);
        $id = $selectedVariation ? 'variation_'.$selectedVariation->id : 'good_'.$good->id;
        $imagePath = $selectedVariation?->images?->sortBy('sort_order')->sortByDesc('is_main')->first()?->file_path
            ?? $good->images()->orderByDesc('is_main')->orderBy('sort_order')->value('file_path');
        $imageUrl = $this->publicImageUrl($imagePath);
        $url = $this->mainSiteUrl().'/catalog/'.($good->slug ?: $good->id);
        $item = [
            'id' => $id,
            'name' => $good->name,
            'regular_price' => round((float) $priceInfo['base_price'], 2),
            'final_price' => round((float) $priceInfo['final_price'], 2),
            'warehouses' => $this->warehouseQuantities($good, $selectedVariation),
            'dimensions' => $this->dimensions($selectedVariation ?: $good),
            'img' => $imageUrl,
            'url' => $url,
            'characteristics' => $selectedVariation ? $this->variationCharacteristics($selectedVariation) : [],
            'variations' => $variants->map(fn (ShopGoodVariation $variation) => [
                'id' => 'variation_'.$variation->id,
                'name' => $good->name,
                'regular_price' => round((float) $this->calculation->calculateFinalUnitPrice($good, $variation)['base_price'], 2),
                'final_price' => round((float) $this->calculation->calculateFinalUnitPrice($good, $variation)['final_price'], 2),
                'warehouses' => $this->warehouseQuantities($good, $variation),
                'dimensions' => $this->dimensions($variation),
                'img' => $this->publicImageUrl($variation->images->sortBy('sort_order')->sortByDesc('is_main')->first()?->file_path) ?: $imageUrl,
                'url' => $url,
                'characteristics' => $this->variationCharacteristics($variation),
            ])->values(),
        ];
        return $item;
    }

    private function warehouseQuantities(ShopGood $good, ?ShopGoodVariation $variation): array
    {
        if ($this->settingsData()['delivery_mode'] === 'merchant') {
            $activeVariations = $good->relationLoaded('variations') ? $good->variations->where('is_active', true) : $good->variations()->where('is_active', true)->with('stock')->get();
            $quantity = $variation
                ? $this->availability->quantity($good, $variation)
                : ($activeVariations->isNotEmpty()
                    ? (int) $activeVariations->sum(fn ($candidate) => $this->availability->quantity($good, $candidate))
                    : $this->availability->quantity($good));
            return [['id' => 'merchant', 'available_quantity' => $quantity]];
        }
        $warehouses = ShopWarehouse::query()->active()->ordered()->get();
        if (! $variation) {
            $variants = $good->relationLoaded('variations') ? $good->variations->where('is_active', true) : $good->variations()->where('is_active', true)->with('stock')->get();
            if ($variants->isNotEmpty()) {
                $defaultWarehouseId = $warehouses->firstWhere('is_default', true)?->id
                    ?? ($warehouses->count() === 1 ? $warehouses->first()?->id : null);
                return $warehouses->map(fn ($warehouse) => [
                    'id' => (string) $warehouse->id,
                    'available_quantity' => (int) $variants->sum(function ($candidate) use ($warehouse, $defaultWarehouseId): int {
                        $stockRows = collect($candidate->stock);
                        if ($stockRows->isEmpty()) {
                            return (string) $warehouse->id === (string) $defaultWarehouseId ? max(0, (int) $candidate->stock_quantity) : 0;
                        }
                        return (int) $stockRows->where('warehouse_id', $warehouse->id)->sum(fn ($row) => max(0, (int) $row->quantity - (int) $row->reserved_quantity));
                    }),
                ])->values()->all();
            }
        }
        $stocks = $variation?->stock()->get() ?? $good->stock()->whereNull('variation_id')->get();
        $legacyQuantity = max(0, (int) ($variation?->stock_quantity ?? $good->stock_quantity));
        return $warehouses->map(function (ShopWarehouse $warehouse) use ($good, $variation, $stocks, $legacyQuantity, $warehouses): array {
            $stock = ShopStock::query()->where('warehouse_id', $warehouse->id)->where('good_id', $good->id)
                ->when($variation, fn ($q) => $q->where('variation_id', $variation->id), fn ($q) => $q->whereNull('variation_id'))->first();
            $quantity = $stock
                ? max(0, $stock->quantity - $stock->reserved_quantity)
                : ($stocks->isEmpty() && ($warehouse->is_default || $warehouses->count() === 1) ? $legacyQuantity : 0);
            return ['id' => (string) $warehouse->id, 'available_quantity' => $quantity];
        })->values()->all();
    }

    private function dimensions($model): array
    {
        return [
            'weight' => (float) ($model->shipping_weight ?: $model->weight ?: 0),
            'depth' => (float) ($model->shipping_length ?: $model->length ?: $model->depth ?: 0),
            'width' => (float) ($model->shipping_width ?: $model->width ?: 0),
            'height' => (float) ($model->shipping_height ?: $model->height ?: 0),
        ];
    }

    private function variationCharacteristics(ShopGoodVariation $variation): array
    {
        return $variation->attributeValues->map(fn ($value) => [
            'code' => Str::upper(Str::slug($value->attribute?->name ?? ('attribute_'.$value->attribute_id), '_')),
            'name' => $value->attribute?->name ?? '',
            'display_type' => 'text',
            'properties' => ['value' => $value->value],
        ])->values()->all();
    }

    private function publicImageUrl(?string $path): string
    {
        if (! $path) return '';
        if (preg_match('/^https?:\/\//i', $path)) return $path;
        $clean = ltrim($path, '/');
        if (str_starts_with($clean, 'images/')) return $this->mainSiteUrl().'/'.$clean;
        if (str_starts_with($clean, 'storage/')) return rtrim((string) config('app.url'), '/').'/'.$clean;
        return $this->mainSiteUrl().'/images/'.$clean;
    }

    private function mainSiteUrl(): string
    {
        if ($this->mainSiteUrlCache !== null) return $this->mainSiteUrlCache;
        $configured = trim((string) DB::table('settings')->where('group', 'shop')->where('key', 'main_site')->value('value'));
        if ($configured === '') $configured = trim((string) config('app.frontend_url', ''));
        if ($configured === '') $configured = (string) config('app.url');
        if (! preg_match('/^https?:\/\//i', $configured)) $configured = 'https://'.$configured;
        return $this->mainSiteUrlCache = rtrim($configured, '/');
    }

    private function formatAddress(mixed $address): ?string
    {
        if (is_string($address)) return trim($address) ?: null;
        if (! is_array($address)) return null;
        return collect([
            $address['locality'] ?? null,
            $address['address'] ?? null,
            isset($address['apartment']) ? 'кв. '.$address['apartment'] : null,
            isset($address['entrance']) ? 'подъезд '.$address['entrance'] : null,
        ])->filter(fn ($part) => is_string($part) && trim($part) !== '')->implode(', ') ?: null;
    }

    private function settingsData(): array
    {
        return $this->settingsCache ??= $this->settings->get();
    }

    private function generateOrderNumber(): string
    {
        do { $number = 'SS-YCP-'.now()->format('Ymd').'-'.Str::upper(Str::random(6)); }
        while (ShopOrder::query()->where('order_number', $number)->exists());
        return $number;
    }
}
