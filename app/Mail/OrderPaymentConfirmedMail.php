<?php

namespace App\Mail;

use App\Models\ShopPaymentMethod;
use App\Services\InvoicePdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderPaymentConfirmedMail extends Mailable
{
    use Queueable, SerializesModels;

    public $order;

    public $contacts;

    public $siteInfo;

    /**
     * Create a new message instance.
     */
    public function __construct($order, $contacts, $siteInfo = null)
    {
        $this->order = $order;
        $this->contacts = $contacts;
        $this->siteInfo = $siteInfo;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Подтверждение оплаты заказа №'.$this->order->order_number,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.order-payment-confirmed',
            with: [
                'order' => $this->order,
                'contacts' => $this->contacts,
                'siteInfo' => $this->siteInfo,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     */
    public function attachments(): array
    {
        $attachments = [];

        // Прикрепляем PDF счет/накладную
        try {
            $pdfService = new InvoicePdfService;
            $pdfData = [
                'order_id' => $this->order->order_number,
                'date' => date('d.m.Y'),
                'total_amount' => $this->order->total_amount,
                'with_vat' => false, // Для подтверждения оплаты используем формат без НДС
                'settings' => $this->getPaymentMethodSettings(),
                'contact' => $this->contacts,
                'main_address' => $this->contacts ? $this->contacts['legal_address'] : null,
                'main_phone' => $this->contacts ? $this->contacts['phone'] : null,
                'order_items' => $this->order->items,
                'customer_name' => $this->order->customer_name,
                'customer_inn' => '',
                'customer_address' => $this->order->shipping_address,
                'customer_phone' => $this->order->customer_phone,
                'promo_code_discount_amount' => $this->order->promo_code_discount_amount ?? 0,
                'bonus_points_to_use' => $this->order->bonus_points_to_use ?? 0,
                'birthday_discount_amount' => $this->order->birthday_discount_amount ?? 0,
                'delivery_cost' => $this->order->delivery_cost ?? 0,
            ];

            $pdfContent = $pdfService->generatePdf($pdfData);

            $attachments[] = Attachment::fromData(fn () => $pdfContent, 'nakladnaya-'.$this->order->order_number.'.pdf')
                ->withMime('application/pdf');
        } catch (\Exception $e) {
            // Если не удалось создать PDF, продолжаем без вложения
            \Log::error('Failed to generate PDF for payment confirmation email: '.$e->getMessage());
        }

        return $attachments;
    }

    /**
     * Получить настройки способа оплаты
     */
    private function getPaymentMethodSettings()
    {
        try {
            $paymentMethod = ShopPaymentMethod::where('name', $this->order->payment_method)
                ->orWhere('id', $this->order->payment_method_id)
                ->first();

            return $paymentMethod ? $paymentMethod->settings ?? [] : [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Проверить, является ли способ оплаты банковским переводом
     */
    private function isTransferPaymentMethod(): bool
    {
        try {
            $paymentMethod = ShopPaymentMethod::where('name', $this->order->payment_method)
                ->orWhere('id', $this->order->payment_method_id)
                ->first();

            return $paymentMethod && $paymentMethod->type === 'transfer';
        } catch (\Exception $e) {
            return false;
        }
    }
}
