<?php

namespace App\Services\Partner;

use App\Exceptions\PartnerPaymentConflictException;
use App\Models\Partner;
use App\Models\PartnerOrder;
use App\Models\PartnerPaymentIdempotency;
use App\Models\ShopOrder;
use App\Models\ShopPaymentMethod;
use App\Models\ShopPaymentTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class PartnerPaymentService
{
    private const SUPPORTED_TYPES = ['cash', 'transfer', 'tbank_eacq', 'tbank_dolyame'];

    public function __construct(
        private readonly PartnerTbankGateway $gateway,
        private readonly PartnerSignatureCanonicalizer $canonicalizer,
    ) {}

    public function create(Partner $partner, PartnerOrder $partnerOrder, ?int $methodId, string $idempotencyKey): array
    {
        $requestHash = hash('sha256', $this->canonicalizer->payload([
            'partner_order_id' => (int) $partnerOrder->id,
            'payment_method_id' => $methodId,
        ]));

        try {
            return DB::transaction(function () use ($partner, $partnerOrder, $methodId, $idempotencyKey, $requestHash): array {
                $lockedPartnerOrder = PartnerOrder::query()
                    ->where('partner_id', $partner->id)
                    ->whereKey($partnerOrder->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $order = ShopOrder::query()->lockForUpdate()->findOrFail($lockedPartnerOrder->shop_order_id);
                $this->assertPayable($lockedPartnerOrder, $order);

                $existing = PartnerPaymentIdempotency::query()
                    ->where('partner_id', $partner->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    if ((int) $existing->partner_order_id !== (int) $lockedPartnerOrder->id
                        || ! hash_equals($existing->request_hash, $requestHash)) {
                        Log::warning('[FIX:partner-payment-idempotency] Partner payment idempotency conflict', [
                            'partner_id' => $partner->id,
                            'partner_order_id' => $lockedPartnerOrder->id,
                            'existing_partner_order_id' => $existing->partner_order_id,
                        ]);
                        throw new PartnerPaymentConflictException('idempotency_conflict');
                    }

                    return array_merge($existing->result ?? [], ['idempotent_replay' => true]);
                }

                $ledger = PartnerPaymentIdempotency::create([
                    'partner_id' => $partner->id,
                    'partner_order_id' => $lockedPartnerOrder->id,
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                    'status' => 'processing',
                ]);
                $result = $this->initialize($order, $methodId);
                $ledger->update([
                    'status' => $result['status'] === 'failed' ? 'failed' : 'completed',
                    'payment_transaction_id' => $result['local_transaction_id'] ?? null,
                    'result' => $this->safeResult($result),
                ]);

                return array_merge($result, ['idempotent_replay' => false]);
            }, 3);
        } catch (QueryException $exception) {
            $existing = PartnerPaymentIdempotency::query()
                ->where('partner_id', $partner->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if (! $existing) {
                throw $exception;
            }
            if ((int) $existing->partner_order_id !== (int) $partnerOrder->id
                || ! hash_equals($existing->request_hash, $requestHash)) {
                Log::warning('[FIX:partner-payment-idempotency] Partner payment idempotency race conflict', [
                    'partner_id' => $partner->id,
                    'partner_order_id' => $partnerOrder->id,
                    'existing_partner_order_id' => $existing->partner_order_id,
                ]);
                throw new PartnerPaymentConflictException('idempotency_conflict');
            }

            return array_merge($existing->result ?? [], ['idempotent_replay' => true]);
        }
    }

    public function latestState(PartnerOrder $order): ?array
    {
        return PartnerPaymentIdempotency::query()
            ->where('partner_order_id', $order->id)
            ->latest('id')
            ->value('result');
    }

    public function createForIsolatedTest(ShopOrder $order, ?int $methodId): array
    {
        return DB::transaction(fn (): array => $this->initialize($order, $methodId), 3);
    }

    private function initialize(ShopOrder $order, ?int $methodId): array
    {
        $method = $methodId ? ShopPaymentMethod::query()->active()->find($methodId) : ShopPaymentMethod::getDefault();
        if (! $method || ! in_array($method->type, self::SUPPORTED_TYPES, true)) {
            throw new UnprocessableEntityHttpException('Active payment method is unavailable for Partner API');
        }

        $order->update(['payment_method_id' => $method->id, 'payment_method' => $method->name]);
        $methodData = ['id' => $method->id, 'name' => $method->name, 'type' => $method->type];
        if (in_array($method->type, ['cash', 'transfer'], true)) {
            return ['status' => 'pending', 'payment_url' => null, 'transaction_id' => null, 'method' => $methodData];
        }

        $pending = ShopPaymentTransaction::query()
            ->where('order_id', $order->id)
            ->where('payment_method_id', $method->id)
            ->where('status', 'pending')
            ->latest('id')
            ->first();
        if ($pending && $order->payment_url) {
            return [
                'status' => 'pending', 'payment_url' => $order->payment_url,
                'transaction_id' => $pending->transaction_id, 'local_transaction_id' => $pending->id,
                'method' => $methodData,
            ];
        }

        $transaction = ShopPaymentTransaction::create([
            'order_id' => $order->id, 'payment_method_id' => $method->id,
            'status' => 'pending', 'amount' => $order->total_amount,
        ]);
        try {
            $gatewayResult = $this->gateway->initiate($method, $order->fresh());
        } catch (\Throwable $exception) {
            $gatewayResult = ['success' => false, 'message' => 'Payment gateway is temporarily unavailable'];
            Log::warning('Partner payment gateway call failed', [
                'order_id' => $order->id,
                'payment_transaction_id' => $transaction->id,
                'exception' => get_class($exception),
            ]);
        }
        $safeGateway = collect($gatewayResult)->except(['request_data', 'raw_body', 'token', 'password'])->all();
        if (! ($gatewayResult['success'] ?? false)) {
            $message = (string) ($gatewayResult['message'] ?? 'Payment gateway rejected the request');
            $transaction->markAsFailed($message, $safeGateway);
            Log::warning('Partner payment initialization failed', [
                'order_id' => $order->id, 'payment_transaction_id' => $transaction->id,
                'payment_type' => $method->type,
            ]);

            return [
                'status' => 'failed', 'payment_url' => null, 'transaction_id' => null,
                'local_transaction_id' => $transaction->id, 'method' => $methodData,
                'error' => ['code' => 'payment_initialization_failed', 'message' => $message, 'retryable' => true],
            ];
        }

        $transaction->update([
            'transaction_id' => $gatewayResult['transaction_id'] ?? null,
            'request_data' => ['provider' => $method->type, 'order_number' => $order->order_number],
            'response_data' => $safeGateway,
        ]);
        $order->update(['payment_url' => $gatewayResult['payment_url'] ?? null]);

        return [
            'status' => 'pending', 'payment_url' => $gatewayResult['payment_url'] ?? null,
            'transaction_id' => $gatewayResult['transaction_id'] ?? null,
            'local_transaction_id' => $transaction->id, 'method' => $methodData,
        ];
    }

    private function assertPayable(PartnerOrder $partnerOrder, ShopOrder $order): void
    {
        if ($order->payed || $partnerOrder->status === 'paid') {
            throw new PartnerPaymentConflictException('order_already_paid');
        }
        if (in_array($partnerOrder->status, ['cancelled', 'completed'], true)) {
            throw new PartnerPaymentConflictException('order_not_payable');
        }
    }

    private function safeResult(array $result): array
    {
        return collect($result)->except(['local_transaction_id'])->all();
    }

    public function options(): array
    {
        return ShopPaymentMethod::query()->active()->ordered()
            ->whereIn('type', self::SUPPORTED_TYPES)
            ->get(['id', 'name', 'type', 'description', 'is_default'])->all();
    }
}
