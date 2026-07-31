<?php

namespace App\Services\Partner;

use App\Models\ShopOrder;
use App\Models\ShopPaymentMethod;
use App\Models\ShopPaymentTransaction;
use App\Services\TbankPaymentService;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class PartnerPaymentService
{
    private const SUPPORTED_TYPES = ['cash', 'transfer', 'tbank_eacq', 'tbank_dolyame'];

    public function create(ShopOrder $order, ?int $methodId): array
    {
        $method = $methodId
            ? ShopPaymentMethod::query()->active()->find($methodId)
            : ShopPaymentMethod::getDefault();
        if (! $method || ! in_array($method->type, self::SUPPORTED_TYPES, true)) {
            throw new UnprocessableEntityHttpException('Active payment method is unavailable for Partner API');
        }

        $order->update(['payment_method_id' => $method->id, 'payment_method' => $method->name]);
        Log::info('Partner payment creation started', [
            'order_id' => $order->id,
            'payment_method_id' => $method->id,
            'payment_type' => $method->type,
            'amount' => (float) $order->total_amount,
        ]);

        if (in_array($method->type, ['cash', 'transfer'], true)) {
            return ['status' => 'pending', 'payment_url' => null, 'transaction_id' => null, 'method' => $method];
        }

        $transaction = ShopPaymentTransaction::create([
            'order_id' => $order->id,
            'payment_method_id' => $method->id,
            'status' => 'pending',
            'amount' => $order->total_amount,
        ]);
        $result = (new TbankPaymentService($method->settings ?? []))->initiatePayment($order->fresh());

        $safeResponse = collect($result)->except(['request_data', 'raw_body'])->all();
        if (! ($result['success'] ?? false)) {
            $transaction->markAsFailed($result['message'] ?? 'Payment gateway rejected the request', $safeResponse);
            Log::warning('Partner payment creation failed', [
                'order_id' => $order->id,
                'payment_transaction_id' => $transaction->id,
                'payment_type' => $method->type,
                'message' => $result['message'] ?? null,
            ]);
            throw new UnprocessableEntityHttpException($result['message'] ?? 'Payment could not be created');
        }

        $transaction->update([
            'transaction_id' => $result['transaction_id'] ?? null,
            'request_data' => ['provider' => $method->type, 'order_number' => $order->order_number],
            'response_data' => $safeResponse,
        ]);
        $order->update(['payment_url' => $result['payment_url'] ?? null]);

        Log::info('Partner payment creation completed', [
            'order_id' => $order->id,
            'payment_transaction_id' => $transaction->id,
            'gateway_transaction_id' => $result['transaction_id'] ?? null,
        ]);

        return [
            'status' => 'pending',
            'payment_url' => $result['payment_url'] ?? null,
            'transaction_id' => $result['transaction_id'] ?? null,
            'method' => $method,
        ];
    }

    public function options(): array
    {
        return ShopPaymentMethod::query()->active()->ordered()
            ->whereIn('type', self::SUPPORTED_TYPES)
            ->get(['id', 'name', 'type', 'description', 'is_default'])
            ->all();
    }
}
