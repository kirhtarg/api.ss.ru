<?php

namespace App\Mail;

use App\Models\Contact;
use App\Models\Setting;
use App\Models\ShopPaymentMethod;
use App\Services\InvoicePdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentMethodChangedMail extends Mailable
{
    use Queueable, SerializesModels;

    public $order;

    public $paymentMethod;

    public $paymentUrl;

    public $contacts;

    public $siteInfo;

    public function __construct($order, $paymentMethod, ?string $paymentUrl, $contacts = null, ?array $siteInfo = null)
    {
        $this->order = $order;
        $this->paymentMethod = $paymentMethod;
        $this->paymentUrl = $paymentUrl;
        $this->contacts = $contacts;
        $this->siteInfo = $siteInfo ?? [];
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Изменен способ оплаты заказа №'.$this->order->order_number,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.payment-method-changed',
        );
    }

    public function attachments(): array
    {
        if (!$this->isTransferPaymentMethod()) {
            return [];
        }

        try {
            $pdfService = new InvoicePdfService;
            $settings = $this->paymentMethod ? ($this->paymentMethod->settings ?? []) : [];
            $contact = Contact::where('is_main', 1)->first();
            $mainAddress = $contact ? $contact->mainAddress() : null;
            $mainPhone = $contact ? $contact->mainPhone() : null;
            $orderItems = method_exists($this->order, 'getItemsWithDetails')
                ? $this->order->getItemsWithDetails()
                : (is_array($this->order->items ?? null) ? $this->order->items : []);

            $pdfContent = $pdfService->generatePdf([
                'order_id' => $this->order->order_number ?? $this->order->id,
                'date' => $this->order->created_at ? $this->order->created_at->format('d.m.Y') : date('d.m.Y'),
                'total_amount' => $this->order->total_amount ?? 0,
                'with_vat' => isset($settings['with_vat']) ? (bool) $settings['with_vat'] : true,
                'settings' => $settings,
                'contact' => $contact,
                'main_address' => $mainAddress,
                'main_phone' => $mainPhone,
                'order_items' => $orderItems,
                'customer_name' => $this->order->customer_name ?? 'Покупатель',
                'customer_inn' => '',
                'customer_address' => $this->order->shipping_address ?? '',
                'customer_phone' => $this->order->customer_phone ?? '',
                'promo_code_discount_amount' => $this->order->promo_code_discount_amount ?? 0,
                'bonus_points_to_use' => $this->order->bonus_points_to_use ?? 0,
                'birthday_discount_amount' => $this->order->birthday_discount_amount ?? 0,
                'sale_discount_amount' => $this->order->sale_discount_amount ?? 0,
                'registered_user_discount_amount' => $this->order->registered_user_discount_amount ?? 0,
                'delivery_cost' => $this->order->delivery_cost ?? 0,
            ]);

            return [
                Attachment::fromData(
                    fn () => $pdfContent,
                    'schet-'.($this->order->order_number ?? $this->order->id).'.pdf'
                )->withMime('application/pdf'),
            ];
        } catch (\Exception $e) {
            \Log::error('Ошибка генерации PDF счета для письма о смене оплаты: '.$e->getMessage());

            return [];
        }
    }

    public function isOnlinePaymentMethod(): bool
    {
        return $this->paymentMethod && in_array($this->paymentMethod->type, ['yookassa', 'yandex_pay', 'yandex_split', 'tbank_eacq', 'tbank_dolyame'], true);
    }

    public function isTransferPaymentMethod(): bool
    {
        return $this->paymentMethod && $this->paymentMethod->type === 'transfer';
    }
}
