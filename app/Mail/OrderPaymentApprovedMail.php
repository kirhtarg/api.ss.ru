<?php

namespace App\Mail;

use App\Models\Contact;
use App\Models\ShopPaymentMethod;
use App\Services\InvoicePdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderPaymentApprovedMail extends Mailable
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
        $subject = 'Заказ №'.$this->order->order_number.' готов к оплате';

        return new Envelope(
            subject: $subject,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.order-payment-approved',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        $attachments = [];

        // Если способ оплаты - банковский перевод, добавляем PDF счет
        if ($this->isTransferPaymentMethod()) {
            try {
                $pdfService = new InvoicePdfService;

                // Получаем способ оплаты для настроек
                $paymentMethod = ShopPaymentMethod::find($this->order->payment_method_id);
                $settings = $paymentMethod ? ($paymentMethod->settings ?? []) : [];

                // Получаем данные контакта
                $contact = Contact::where('is_main', 1)->first();
                $mainAddress = $contact ? $contact->mainAddress() : null;
                $mainPhone = $contact ? $contact->mainPhone() : null;

                // Получаем товары заказа
                $orderItems = [];
                if (method_exists($this->order, 'getItemsWithDetails')) {
                    $orderItems = $this->order->getItemsWithDetails();
                } elseif (isset($this->order->items) && is_array($this->order->items)) {
                    $orderItems = $this->order->items;
                }

                // Подготавливаем данные для PDF
                $data = [
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
                ];

                // Генерируем PDF
                $pdfContent = $pdfService->generatePdf($data);

                // Добавляем PDF как вложение
                $attachments[] = Attachment::fromData(
                    fn () => $pdfContent,
                    'schet-'.($this->order->order_number ?? $this->order->id).'.pdf'
                )->withMime('application/pdf');

            } catch (\Exception $e) {
                \Log::error('Ошибка генерации PDF счета для письма: '.$e->getMessage());
                // Не прерываем отправку письма, если PDF не удалось сгенерировать
            }
        }

        return $attachments;
    }

    /**
     * Проверка, является ли способ оплаты банковским переводом
     */
    private function isTransferPaymentMethod(): bool
    {
        // Проверяем по названию способа оплаты
        if ($this->order->payment_method === 'Банковский перевод') {
            return true;
        }

        // Проверяем по ID способа оплаты
        if (isset($this->order->payment_method_id)) {
            $paymentMethod = ShopPaymentMethod::find($this->order->payment_method_id);

            return $paymentMethod && $paymentMethod->type === 'transfer';
        }

        return false;
    }
}
