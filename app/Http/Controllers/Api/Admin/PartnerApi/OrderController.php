<?php

namespace App\Http\Controllers\Api\Admin\PartnerApi;

use App\Http\Controllers\Controller;
use App\Models\PartnerOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'partner_id' => ['nullable', 'integer', 'exists:partners,id'],
            'status' => ['nullable', 'string', 'max:40'],
            'commission_status' => ['nullable', Rule::in(['pending', 'recognized', 'paid', 'cancelled'])],
            'search' => ['nullable', 'string', 'max:128'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        Log::debug('Partner order admin list requested', [
            'user_id' => $request->user()?->id,
            'filters' => $validated,
        ]);

        $orders = PartnerOrder::query()
            ->with([
                'partner:id,public_id,code,name',
                'shopOrder:id,order_number,status_id,payment_status_id,customer_name,customer_email,customer_phone,total_quantity,payment_method,payment_method_id,shipping_method,shipping_method_id,shipping_address,delivery_cost,total_amount,created_at',
                'shopOrder.status:id,name,color',
                'shopOrder.paymentStatus:id,name,color',
            ])
            ->when($validated['partner_id'] ?? null, fn ($query, $partnerId) => $query->where('partner_id', $partnerId))
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($validated['commission_status'] ?? null, fn ($query, $status) => $query->where('commission_status', $status))
            ->when($validated['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($searchQuery) use ($search): void {
                    $searchQuery->where('external_order_id', 'like', "%{$search}%")
                        ->orWhere('public_id', 'like', "%{$search}%")
                        ->orWhereHas('shopOrder', fn ($shopOrder) => $shopOrder->where('order_number', 'like', "%{$search}%"));
                });
            })
            ->when($validated['date_from'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($validated['date_to'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '<=', $date))
            ->latest('id')
            ->paginate($validated['per_page'] ?? 25);

        return response()->json(['success' => true, 'data' => $orders]);
    }

    public function show(Request $request, PartnerOrder $order): JsonResponse
    {
        Log::debug('Partner order admin card requested', [
            'user_id' => $request->user()?->id,
            'partner_order_id' => $order->id,
        ]);

        $order->load([
            'partner:id,public_id,code,name',
            'shopOrder.status',
            'shopOrder.paymentStatus',
            'shopOrder.paymentMethod',
            'shopOrder.deliveryMethod',
            'shopOrder.packages',
            'commissions',
        ]);
        $order->makeHidden(['idempotency_key', 'request_hash']);

        return response()->json(['success' => true, 'data' => $order]);
    }
}
