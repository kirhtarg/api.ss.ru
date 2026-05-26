<?php

namespace App\Services;

use App\Mail\OrderInvoiceMail;
use App\Models\Contact;
use App\Models\ShopOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CustomerOrderEmailService
{
    public function sendOrderConfirmation(ShopOrder $order): bool
    {
        if (empty($order->customer_email)) {
            Log::warning('Customer order email skipped: empty customer email', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
            ]);

            return false;
        }

        if (! $this->markEmailPending($order)) {
            return false;
        }

        try {
            $contacts = Contact::where('is_main', 1)->get();
            $siteInfo = SiteInfoService::getSiteInfoForEmail();

            $enrichedOrder = clone $order;
            if (method_exists($order, 'getItemsWithDetails')) {
                $enrichedOrder->items = $order->getItemsWithDetails();
            }

            Mail::to($order->customer_email)->send(new OrderInvoiceMail($enrichedOrder, $contacts, $siteInfo));

            $this->markEmailSent($order);

            Log::info('Customer order confirmation email sent', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'email' => $order->customer_email,
            ]);

            return true;
        } catch (\Exception $e) {
            $this->markEmailFailed($order, $e->getMessage());

            Log::error('Customer order confirmation email error: '.$e->getMessage(), [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'email' => $order->customer_email,
            ]);

            return false;
        }
    }

    private function markEmailPending(ShopOrder $order): bool
    {
        $affected = DB::table('shop_orders')
            ->where('id', $order->id)
            ->where(function ($q) {
                $q->whereNull('metadata')
                    ->orWhere(function ($q) {
                        $q->where(function ($q) {
                            $q->whereRaw("JSON_EXTRACT(metadata, '$.customer_order_confirmation_sent') IS NULL")
                                ->orWhereRaw("JSON_EXTRACT(metadata, '$.customer_order_confirmation_sent') = false");
                        })->where(function ($q) {
                            $q->whereRaw("JSON_EXTRACT(metadata, '$.customer_order_confirmation_pending') IS NULL")
                                ->orWhereRaw("JSON_EXTRACT(metadata, '$.customer_order_confirmation_pending') = false");
                        });
                    });
            })
            ->update([
                'metadata' => DB::raw("JSON_SET(COALESCE(metadata, '{}'), '$.customer_order_confirmation_pending', true)"),
            ]);

        return $affected > 0;
    }

    private function markEmailSent(ShopOrder $order): void
    {
        DB::table('shop_orders')
            ->where('id', $order->id)
            ->update([
                'metadata' => DB::raw("JSON_SET(COALESCE(metadata, '{}'), '$.customer_order_confirmation_sent', true, '$.customer_order_confirmation_pending', false, '$.customer_order_confirmation_sent_at', '".now()->toDateTimeString()."')"),
            ]);
    }

    private function markEmailFailed(ShopOrder $order, string $message): void
    {
        $safeMessage = json_encode(mb_substr($message, 0, 500), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        DB::table('shop_orders')
            ->where('id', $order->id)
            ->update([
                'metadata' => DB::raw("JSON_SET(COALESCE(metadata, '{}'), '$.customer_order_confirmation_pending', false, '$.customer_order_confirmation_error', {$safeMessage})"),
            ]);
    }
}
