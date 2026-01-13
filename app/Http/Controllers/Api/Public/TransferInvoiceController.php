<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\ShopPaymentMethod;
use App\Models\Contact;
use App\Models\ShopOrder;
use App\Services\InvoiceExcelService;
use App\Services\InvoicePdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TransferInvoiceController extends Controller
{
    /**
     * Генерация PDF счета для банковского перевода
     */
    public function generateInvoice(Request $request)
    {
        try {
            $orderId = $request->get('order_id', 'TEST123');
            $amount = $request->get('amount', 0);
            
            // Получаем способ оплаты типа "transfer"
            $paymentMethod = ShopPaymentMethod::where('type', 'transfer')
                ->where('is_active', true)
                ->first();
            
            if (!$paymentMethod) {
                return response()->json([
                    'success' => false,
                    'message' => 'Способ оплаты "Банковский перевод" не найден'
                ], 404);
            }
            
            $settings = $paymentMethod->settings ?? [];
            
            // Параметры из URL для тестирования (переопределяют настройки из БД)
            $orgTypeFromUrl = $request->get('org_type');
            $withVatFromUrl = $request->get('with_vat');
            
            // Определяем тип организации и НДС (приоритет у параметров URL для тестирования)
            if ($orgTypeFromUrl !== null && $orgTypeFromUrl !== '') {
                $organizationType = $orgTypeFromUrl;
            } else {
                $organizationType = $settings['organization_type'] ?? 'OOO';
            }
            
            if ($withVatFromUrl !== null && $withVatFromUrl !== '') {
                // Обрабатываем разные варианты: '1', 'true', true, '0', 'false', false
                // Если передано '0' или 'false', то НДС = false, иначе проверяем на true
                if ($withVatFromUrl === '0' || $withVatFromUrl === 'false' || $withVatFromUrl === false || $withVatFromUrl === 0) {
                    $withVat = false;
                } else {
                    $withVat = in_array($withVatFromUrl, ['1', 'true', true, 1], true);
                }
            } else {
                $withVat = isset($settings['with_vat']) ? (bool)$settings['with_vat'] : true;
            }
            
            // Получаем данные контакта для дополнительной информации
            $contact = Contact::where('is_main', 1)->first();
            $mainAddress = $contact ? $contact->mainAddress() : null;
            $mainPhone = $contact ? $contact->mainPhone() : null;
            
            // Пытаемся получить заказ из базы данных
            $order = null;
            $orderItems = [];
            $customerName = 'Покупатель (данные будут заполнены при оформлении заказа)';
            $customerInn = '';
            $customerAddress = '';
            $customerPhone = '';
            
            if ($orderId !== 'TEST123' && (is_numeric($orderId) || is_string($orderId))) {
                $order = ShopOrder::where('id', $orderId)
                    ->orWhere('order_number', $orderId)
                    ->first();
                
                if ($order) {
                    $orderItems = $order->getItemsWithDetails();
                    $amount = $order->total_amount ?? $amount;
                    if ($order->customer_name) {
                        $customerName = $order->customer_name;
                        if ($order->customer_phone) {
                            $customerPhone = $order->customer_phone;
                        }
                        if ($order->shipping_address) {
                            $customerAddress = $order->shipping_address;
                        }
                    }
                }
            }
            
            // Если это тест или заказ не найден, используем тестовые данные
            if ($orderId === 'TEST123' || (!$order && $orderId !== 'TEST123')) {
                // Для теста создаем несколько товаров с разными суммами
                if ($orderId === 'TEST123') {
                    $orderItems = [
                        [
                            'good_name' => 'Сноуборд Burton Custom X',
                            'quantity' => 1,
                            'price' => 45000,
                            'total' => 45000,
                            'unit' => 'шт'
                        ],
                        [
                            'good_name' => 'Крепления для сноуборда Union Force',
                            'quantity' => 1,
                            'price' => 18000,
                            'total' => 18000,
                            'unit' => 'шт'
                        ],
                        [
                            'good_name' => 'Ботинки для сноуборда Vans Aura',
                            'quantity' => 1,
                            'price' => 22000,
                            'total' => 22000,
                            'unit' => 'шт'
                        ],
                        [
                            'good_name' => 'Шлем Giro Ledge',
                            'quantity' => 1,
                            'price' => 8500,
                            'total' => 8500,
                            'unit' => 'шт'
                        ],
                        [
                            'good_name' => 'Очки Oakley Flight Deck',
                            'quantity' => 2,
                            'price' => 12000,
                            'total' => 24000,
                            'unit' => 'шт'
                        ]
                    ];
                    // Пересчитываем общую сумму
                    $amount = array_sum(array_column($orderItems, 'total'));
                } else {
                    $orderItems = [
                        [
                            'good_name' => 'Тестовый товар',
                            'quantity' => 1,
                            'price' => $amount,
                            'total' => $amount
                        ]
                    ];
                }
            }
            
            // Получаем данные о скидках и доставке из заказа, если он передан
            $promoCodeDiscount = 0;
            $bonusDiscount = 0;
            $birthdayDiscount = 0;
            $deliveryCost = 0;
            if ($order) {
                $promoCodeDiscount = (float)($order->promo_code_discount_amount ?? 0);
                $bonusDiscount = (float)($order->bonus_points_to_use ?? 0); // Скидка от списанных бонусов (1 бонус = 1 рубль)
                $birthdayDiscount = (float)($order->birthday_discount_amount ?? 0);
                // Получаем стоимость доставки из заказа
                $deliveryCost = isset($order->delivery_cost) ? (float)$order->delivery_cost : 0;
                if ($deliveryCost < 0) {
                    $deliveryCost = 0;
                }
            }
            
            // Формируем HTML для счета в зависимости от типа
            // Используем шаблон ИП без НДС для всех случаев без НДС (и ИП, и ООО)
            // Используем шаблон ООО с НДС только для случаев с НДС
            if (!$withVat) {
                // Без НДС (ИП или ООО на УСН)
                $html = $this->generateInvoiceHtmlIP($orderId, $amount, $settings, $contact, $mainAddress, $mainPhone, $orderItems, $customerName, $customerInn, $customerAddress, $customerPhone, $promoCodeDiscount, $bonusDiscount, $deliveryCost, $birthdayDiscount);
            } else {
                // С НДС (ООО с НДС)
                $html = $this->generateInvoiceHtmlOOO($orderId, $amount, $settings, $contact, $mainAddress, $mainPhone, $orderItems, $customerName, $customerInn, $customerAddress, $customerPhone, $promoCodeDiscount, $bonusDiscount, $deliveryCost, $birthdayDiscount);
            }
            
            // Возвращаем HTML, который можно распечатать как PDF
            return response($html)
                ->header('Content-Type', 'text/html; charset=utf-8')
                ->header('Content-Disposition', 'inline; filename="invoice-' . $orderId . '.html"');
                
        } catch (\Exception $e) {
            \Log::error('Ошибка генерации счета: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка генерации счета',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Генерация HTML для счета ООО с НДС
     */
    private function generateInvoiceHtmlOOO(
        $orderId,
        $amount,
        $settings,
        $contact,
        $mainAddress,
        $mainPhone,
        $orderItems = [],
        $customerName = 'Покупатель (данные будут заполнены при оформлении заказа)',
        $customerInn = '',
        $customerAddress = '',
        $customerPhone = '',
        $promoCodeDiscount = 0,
        $bonusPointsToUse = 0,
        $deliveryCost = 0,
        $birthdayDiscount = 0
    ) {
        $legalName = $settings['legal_name'] ?? ($contact->legal_name ?? 'Не указано');
        $inn = $settings['inn'] ?? ($contact->inn ?? 'Не указано');
        $kpp = $settings['kpp'] ?? 'Не указано';
        $legalAddress = $settings['legal_address'] ?? ($contact->legal_address ?? ($mainAddress ? $mainAddress->address : 'Не указано'));
        $phone = $mainPhone ? ($mainPhone->phone_number ?? $mainPhone->phone ?? '') : '';
        $bankName = $settings['bank_name'] ?? 'Не указано';
        $bik = $settings['bik'] ?? 'Не указано';
        $accountNumber = $settings['account_number'] ?? 'Не указано';
        $correspondentAccount = $settings['correspondent_account'] ?? 'Не указано';
        
        // Рассчитываем суммы товаров (без доставки)
        $itemsSum = 0;
        if (!empty($orderItems)) {
            foreach ($orderItems as $item) {
                $itemsSum += (float)($item['total'] ?? 0);
            }
        } else {
            $itemsSum = (float)$amount;
        }
        
        // Итоговая сумма = товары + доставка (ВАЖНО: доставка должна быть включена в Итого)
        $deliveryCost = (float)$deliveryCost;
        // Явно добавляем доставку к сумме товаров
        $totalSum = (float)$itemsSum + (float)$deliveryCost;
        
        // НДС 20%
        $vatRate = 20;
        $vatAmount = $totalSum * $vatRate / (100 + $vatRate);
        $totalWithVat = $totalSum;
        
        // Форматируем скидки для отображения
        $formattedPromoCodeDiscount = \App\Helpers\PriceHelper::formatPrice($promoCodeDiscount);
        $formattedBonusDiscount = \App\Helpers\PriceHelper::formatPrice($bonusPointsToUse);
        
        // Форматируем итоговую сумму (товары + доставка) для отображения в строке "Итого"
        $formattedTotalSum = \App\Helpers\PriceHelper::formatPrice($totalSum);
        $formattedVatAmount = \App\Helpers\PriceHelper::formatPrice($vatAmount);
        $currentDate = date('d.m.Y');
        $currentDateFull = date('d.m.Y H:i');
        
        // Сумма прописью (используем итоговую сумму с учетом скидок)
        $finalAmount = $totalSum - $promoCodeDiscount - $bonusPointsToUse - $birthdayDiscount;
        $amountInWords = $this->numberToWords($finalAmount);

        // Количество наименований
        $itemsCount = count($orderItems);
        if ($itemsCount == 0) {
            $itemsCount = 1;
        }
        
        $html = <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Счет на оплату №{$orderId}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Arial', 'Helvetica', sans-serif;
            font-size: 11pt;
            line-height: 1.4;
            color: #000;
            padding: 20px;
            background: #fff;
        }
        .invoice {
            max-width: 800px;
            margin: 0 auto;
            background: #fff;
        }
        .bank-header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
            border: 1px solid #000;
        }
        .bank-header-table td {
            padding: 8px 10px;
            border: 1px solid #000;
            font-size: 10pt;
            vertical-align: top;
        }
        .bank-header-table td:first-child {
            width: 50%;
        }
        .bank-header-table .bank-name {
            font-weight: bold;
            font-size: 11pt;
        }
        .recipient-line {
            margin-bottom: 20px;
            font-size: 11pt;
            font-weight: bold;
        }
        .invoice-title {
            text-align: center;
            margin-bottom: 20px;
            font-size: 16pt;
            font-weight: bold;
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .data-table td {
            padding: 8px 10px;
            vertical-align: top;
            font-size: 10pt;
        }
        .data-table td:first-child {
            width: 15%;
            font-weight: bold;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
            border: 1px solid #000;
        }
        .items-table th,
        .items-table td {
            padding: 8px 6px;
            border: 1px solid #000;
            font-size: 10pt;
        }
        .items-table th {
            background: #f5f5f5;
            font-weight: bold;
            text-align: center;
        }
        .items-table td:first-child {
            text-align: center;
            width: 4%;
        }
        .items-table td:nth-child(2) {
            width: 45%;
        }
        .items-table td:nth-child(3),
        .items-table td:nth-child(4) {
            text-align: center;
            width: 8%;
        }
        .items-table td:nth-child(5),
        .items-table td:nth-child(6) {
            text-align: right;
            width: 12%;
        }
        .total-section {
            text-align: right;
            margin-bottom: 15px;
            font-size: 11pt;
        }
        .total-section .total-line {
            margin-bottom: 5px;
        }
        .total-section .total-label {
            display: inline-block;
            min-width: 200px;
            text-align: right;
            margin-right: 10px;
        }
        .total-section .total-value {
            display: inline-block;
            min-width: 120px;
            text-align: right;
            font-weight: bold;
        }
        .amount-in-words {
            margin: 15px 0;
            font-size: 10pt;
            line-height: 1.6;
        }
        .signatures {
            margin-top: 40px;
            display: flex;
            justify-content: space-between;
        }
        .signature-block {
            width: 45%;
        }
        .signature-line {
            margin-bottom: 40px;
            font-size: 10pt;
        }
        .signature-name {
            font-weight: bold;
            margin-top: 5px;
        }
        .stamp-placeholder {
            width: 45%;
            text-align: center;
            font-size: 9pt;
            color: #999;
            border: 1px dashed #ccc;
            padding: 20px;
        }
        @media print {
            body {
                padding: 10px;
            }
            .invoice {
                max-width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="invoice">
        <!-- Таблица с банковскими реквизитами (верхняя часть) -->
        <table class="bank-header-table">
            <tr>
                <td>
                    <div class="bank-name">{$bankName}</div>
                    <div>Банк получателя</div>
                    <div>ИНН {$inn}</div>
                    <div>КПП {$kpp}</div>
                    <div>{$legalName}</div>
                </td>
                <td>
                    <div>БИК: {$bik}</div>
HTML;
        
        if ($correspondentAccount && $correspondentAccount !== 'Не указано') {
            $html .= <<<HTML
                    <div>Сч. №: {$correspondentAccount}</div>
HTML;
        }
        
        $html .= <<<HTML
                    <div>Сч. №: {$accountNumber}</div>
                </td>
            </tr>
        </table>
        
        <!-- Получатель -->
        <div class="recipient-line">
            Получатель: {$legalName}
        </div>
        
        <!-- Заголовок счета -->
        <div class="invoice-title">
            Счет № {$orderId} от {$currentDate} г.
        </div>
        
        <!-- Поставщик и Клиент -->
        <table class="data-table">
            <tr>
                <td>Поставщик:</td>
                <td>
                    {$legalName}, ИНН: {$inn}, КПП: {$kpp}, адрес: {$legalAddress}
HTML;
        
        if ($phone) {
            $html .= <<<HTML
                    , тел.: {$phone}
HTML;
        }
        
        $html .= <<<HTML
                </td>
            </tr>
            <tr>
                <td>Клиент:</td>
                <td>
                    {$customerName}
HTML;
        
        if ($customerInn) {
            $html .= <<<HTML
                    , ИНН: {$customerInn}
HTML;
        }
        
        if ($customerAddress) {
            $html .= <<<HTML
                    , адрес: {$customerAddress}
HTML;
        }
        
        if ($customerPhone) {
            $html .= <<<HTML
                    , тел.: {$customerPhone}
HTML;
        }
        
        $html .= <<<HTML
                </td>
            </tr>
            <tr>
                <td>Основание:</td>
                <td>Заказ №{$orderId} от {$currentDate} года</td>
            </tr>
        </table>
        
        <!-- Таблица с товарами -->
        <table class="items-table">
            <thead>
                <tr>
                    <th>№</th>
                    <th>Наименование работ, услуг</th>
                    <th>Кол-во</th>
                    <th>Ед</th>
                    <th>Цена</th>
                    <th>Сумма</th>
                </tr>
            </thead>
            <tbody>
HTML;
        
        // Добавляем товары в таблицу
        if (!empty($orderItems)) {
            $itemNumber = 1;
            foreach ($orderItems as $item) {
                $itemName = $item['good_name'] ?? 'Товар';
                if (!empty($item['variation_name'])) {
                    $itemName .= ' (' . $item['variation_name'] . ')';
                }
                $quantity = $item['quantity'] ?? 1;
                $total = $item['total'] ?? 0;
                
                // Финальная цена за единицу товара (уже с учетом всех скидок)
                $finalPricePerUnit = $quantity > 0 ? ($total / $quantity) : 0;
                
                $formattedPrice = \App\Helpers\PriceHelper::formatPrice((float)$finalPricePerUnit);
                $formattedTotal = \App\Helpers\PriceHelper::formatPrice((float)$total);
                
                $html .= <<<HTML
                <tr>
                    <td>{$itemNumber}</td>
                    <td>{$itemName}</td>
                    <td>{$quantity}</td>
                    <td>шт</td>
                    <td>{$formattedPrice}</td>
                    <td>{$formattedTotal}</td>
                </tr>
HTML;
                $itemNumber++;
            }
        } else {
            // Если товаров нет, показываем одну строку с суммой товаров (без доставки)
            $formattedItemsSum = \App\Helpers\PriceHelper::formatPrice($itemsSum);
            $html .= <<<HTML
                <tr>
                    <td>1</td>
                    <td>Товары по заказу №{$orderId}</td>
                    <td>1</td>
                    <td>шт</td>
                    <td>{$formattedItemsSum}</td>
                    <td>{$formattedItemsSum}</td>
                </tr>
HTML;
        }
        
        // Добавляем доставку в таблицу товаров, если она не нулевая
        if ($deliveryCost > 0) {
            $formattedDeliveryCost = \App\Helpers\PriceHelper::formatPrice($deliveryCost);
            $html .= <<<HTML
                <tr>
                    <td>{$itemNumber}</td>
                    <td>Доставка</td>
                    <td>1</td>
                    <td>шт</td>
                    <td>{$formattedDeliveryCost}</td>
                    <td>{$formattedDeliveryCost}</td>
                </tr>
HTML;
        }
        
        $html .= <<<HTML
            </tbody>
        </table>
        
        <!-- Итого с НДС -->
        <div class="total-section">
            <div class="total-line">
                <span class="total-label">Итого, вкл. НДС {$vatRate}%:</span>
                <span class="total-value">{$formattedTotalSum}</span>
            </div>
HTML;
        
        // Добавляем строки со скидками после "Итого"
        if ($promoCodeDiscount > 0) {
            $html .= <<<HTML
            <div class="total-line" style="color: #dc2626;">
                <span class="total-label">Скидка по промокоду:</span>
                <span class="total-value">-{$formattedPromoCodeDiscount}</span>
            </div>
HTML;
        }

        if ($bonusPointsToUse > 0) {
            $html .= <<<HTML
            <div class="total-line" style="color: #dc2626;">
                <span class="total-label">Скидка по списанию бонусов:</span>
                <span class="total-value">-{$formattedBonusDiscount}</span>
            </div>
HTML;
        }

        if ($birthdayDiscount > 0) {
            $formattedBirthdayDiscount = \App\Helpers\PriceHelper::formatPrice($birthdayDiscount);
            $html .= <<<HTML
            <div class="total-line" style="color: #dc2626;">
                <span class="total-label">Скидка ко дню рождения:</span>
                <span class="total-value">-{$formattedBirthdayDiscount}</span>
            </div>
HTML;
        }
        
        $html .= <<<HTML
            <div class="total-line" style="font-weight: bold;">
                <span class="total-label">Всего к оплате:</span>
                <span class="total-value">{$formattedFinalAmount}</span>
            </div>
            <div class="total-line">
                <span class="total-label">НДС {$vatRate}%:</span>
                <span class="total-value">{$formattedVatAmount}</span>
            </div>
        </div>
        
        <!-- Сумма прописью -->
        <div class="amount-in-words">
            <div>Всего наименований {$itemsCount} на сумму {$formattedFinalAmount} руб.</div>
            <div><strong>{$amountInWords}</strong></div>
        </div>
        
        <!-- Подписи -->
        <div class="signatures">
            <div class="signature-block">
                <div class="signature-line">
                    <div>Генеральный директор</div>
                    <div style="margin-top: 30px; border-top: 1px solid #000; width: 200px;"></div>
                    <div class="signature-name"></div>
                </div>
                <div class="signature-line">
                    <div>Главный бухгалтер</div>
                    <div style="margin-top: 30px; border-top: 1px solid #000; width: 200px;"></div>
                    <div class="signature-name"></div>
                </div>
            </div>
            <div class="stamp-placeholder">
                М.П.<br>
                (Печать)
            </div>
        </div>
    </div>
</body>
</html>
HTML;
        
        return $html;
    }
    
    /**
     * Генерация HTML для счета ИП без НДС
     */
    private function generateInvoiceHtmlIP(
        $orderId,
        $amount,
        $settings,
        $contact,
        $mainAddress,
        $mainPhone,
        $orderItems = [],
        $customerName = 'Покупатель (данные будут заполнены при оформлении заказа)',
        $customerInn = '',
        $customerAddress = '',
        $customerPhone = '',
        $promoCodeDiscount = 0,
        $bonusPointsToUse = 0,
        $deliveryCost = 0,
        $birthdayDiscount = 0
    ) {
        $legalName = $settings['legal_name'] ?? ($contact->legal_name ?? 'Не указано');
        $inn = $settings['inn'] ?? ($contact->inn ?? 'Не указано');
        $legalAddress = $settings['legal_address'] ?? ($contact->legal_address ?? ($mainAddress ? $mainAddress->address : 'Не указано'));
        $phone = $mainPhone ? ($mainPhone->phone_number ?? $mainPhone->phone ?? '') : '';
        $bankName = $settings['bank_name'] ?? 'Не указано';
        $bik = $settings['bik'] ?? 'Не указано';
        $accountNumber = $settings['account_number'] ?? 'Не указано';
        $correspondentAccount = $settings['correspondent_account'] ?? 'Не указано';
        
        // Рассчитываем суммы товаров (без доставки)
        $itemsSum = 0;
        if (!empty($orderItems)) {
            foreach ($orderItems as $item) {
                $itemsSum += (float)($item['total'] ?? 0);
            }
        } else {
            $itemsSum = (float)$amount;
        }
        
        // Итоговая сумма = товары + доставка (ВАЖНО: доставка должна быть включена в Итого)
        $deliveryCost = (float)$deliveryCost;
        // Явно добавляем доставку к сумме товаров
        $totalSum = (float)$itemsSum + (float)$deliveryCost;
        
        // Форматируем скидки для отображения
        $formattedPromoCodeDiscount = \App\Helpers\PriceHelper::formatPrice($promoCodeDiscount);
        $formattedBonusDiscount = \App\Helpers\PriceHelper::formatPrice($bonusPointsToUse);
        
        // Форматируем итоговую сумму (товары + доставка) для отображения в строке "Итого"
        $formattedTotalSum = \App\Helpers\PriceHelper::formatPrice($totalSum);
        $currentDate = date('d.m.Y');
        
        // Сумма прописью (используем итоговую сумму с учетом скидок)
        $finalAmount = $totalSum - $promoCodeDiscount - $bonusPointsToUse - $birthdayDiscount;
        $amountInWords = $this->numberToWords($finalAmount);

        // Количество наименований
        $itemsCount = count($orderItems);
        if ($itemsCount == 0) {
            $itemsCount = 1;
        }
        
        $html = <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Счет на оплату №{$orderId}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Arial', 'Helvetica', sans-serif;
            font-size: 11pt;
            line-height: 1.4;
            color: #000;
            padding: 20px;
            background: #fff;
        }
        .invoice {
            max-width: 800px;
            margin: 0 auto;
            background: #fff;
        }
        .bank-header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
            border: 1px solid #000;
        }
        .bank-header-table td {
            padding: 8px 10px;
            border: 1px solid #000;
            font-size: 10pt;
            vertical-align: top;
        }
        .bank-header-table td:first-child {
            width: 50%;
        }
        .bank-header-table .bank-name {
            font-weight: bold;
            font-size: 11pt;
        }
        .recipient-line {
            margin-bottom: 20px;
            font-size: 11pt;
            font-weight: bold;
        }
        .invoice-title {
            text-align: center;
            margin-bottom: 20px;
            font-size: 16pt;
            font-weight: bold;
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .data-table td {
            padding: 8px 10px;
            vertical-align: top;
            font-size: 10pt;
        }
        .data-table td:first-child {
            width: 15%;
            font-weight: bold;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
            border: 1px solid #000;
        }
        .items-table th,
        .items-table td {
            padding: 8px 6px;
            border: 1px solid #000;
            font-size: 10pt;
        }
        .items-table th {
            background: #f5f5f5;
            font-weight: bold;
            text-align: center;
        }
        .items-table td:first-child {
            text-align: center;
            width: 4%;
        }
        .items-table td:nth-child(2) {
            width: 45%;
        }
        .items-table td:nth-child(3),
        .items-table td:nth-child(4) {
            text-align: center;
            width: 8%;
        }
        .items-table td:nth-child(5),
        .items-table td:nth-child(6) {
            text-align: right;
            width: 12%;
        }
        .total-section {
            text-align: right;
            margin-bottom: 15px;
            font-size: 11pt;
            font-weight: bold;
        }
        .amount-in-words {
            margin: 15px 0;
            font-size: 10pt;
            line-height: 1.6;
        }
        .vat-note {
            margin: 15px 0;
            font-size: 10pt;
            font-style: italic;
        }
        .invoice-note {
            margin: 15px 0;
            font-size: 10pt;
            border-top: 1px solid #000;
            padding-top: 5px;
        }
        .signature-block {
            margin-top: 40px;
            display: flex;
            justify-content: space-between;
        }
        .signature-left {
            width: 45%;
        }
        .signature-right {
            width: 45%;
            text-align: right;
        }
        .signature-line {
            margin-bottom: 40px;
            font-size: 10pt;
        }
        .signature-name {
            font-weight: bold;
            margin-top: 5px;
        }
        @media print {
            body {
                padding: 10px;
            }
            .invoice {
                max-width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="invoice">
        <!-- Таблица с банковскими реквизитами (верхняя часть) -->
        <table class="bank-header-table">
            <tr>
                <td>
                    <div class="bank-name">{$bankName}</div>
                    <div>Банк получателя</div>
                    <div>ИНН {$inn}</div>
                    <div>КПП</div>
                    <div>{$legalName}</div>
                </td>
                <td>
                    <div>БИК: {$bik}</div>
HTML;
        
        if ($correspondentAccount && $correspondentAccount !== 'Не указано') {
            $html .= <<<HTML
                    <div>Сч. №: {$correspondentAccount}</div>
HTML;
        }
        
        $html .= <<<HTML
                    <div>Сч. №: {$accountNumber}</div>
                </td>
            </tr>
        </table>
        
        <!-- Получатель -->
        <div class="recipient-line">
            Получатель: {$legalName}
        </div>
        
        <!-- Заголовок счета -->
        <div class="invoice-title">
            Счет № {$orderId} от {$currentDate} г.
        </div>
        
        <!-- Поставщик и Клиент -->
        <table class="data-table">
            <tr>
                <td>Поставщик:</td>
                <td>
                    {$legalName}, ИНН: {$inn}, адрес: {$legalAddress}
HTML;
        
        if ($phone) {
            $html .= <<<HTML
                    , тел.: {$phone}
HTML;
        }
        
        $html .= <<<HTML
                </td>
            </tr>
            <tr>
                <td>Клиент:</td>
                <td>
                    {$customerName}
HTML;
        
        if ($customerInn) {
            $html .= <<<HTML
                    , ИНН: {$customerInn}
HTML;
        }
        
        if ($customerAddress) {
            $html .= <<<HTML
                    , адрес: {$customerAddress}
HTML;
        }
        
        if ($customerPhone) {
            $html .= <<<HTML
                    , тел.: {$customerPhone}
HTML;
        }
        
        $html .= <<<HTML
                </td>
            </tr>
            <tr>
                <td>Основание:</td>
                <td>Заказ №{$orderId} от {$currentDate} года</td>
            </tr>
        </table>
        
        <!-- Таблица с товарами -->
        <table class="items-table">
            <thead>
                <tr>
                    <th>№</th>
                    <th>Наименование работ, услуг</th>
                    <th>Кол-во</th>
                    <th>Ед</th>
                    <th>Цена</th>
                    <th>Сумма</th>
                </tr>
            </thead>
            <tbody>
HTML;
        
        // Добавляем товары в таблицу
        if (!empty($orderItems)) {
            $itemNumber = 1;
            foreach ($orderItems as $item) {
                $itemName = $item['good_name'] ?? 'Товар';
                if (!empty($item['variation_name'])) {
                    $itemName .= ' (' . $item['variation_name'] . ')';
                }
                $quantity = $item['quantity'] ?? 1;
                $total = $item['total'] ?? 0;
                
                // Финальная цена за единицу товара (уже с учетом всех скидок)
                $finalPricePerUnit = $quantity > 0 ? ($total / $quantity) : 0;
                
                $formattedPrice = \App\Helpers\PriceHelper::formatPrice((float)$finalPricePerUnit);
                $formattedTotal = \App\Helpers\PriceHelper::formatPrice((float)$total);
                
                $html .= <<<HTML
                <tr>
                    <td>{$itemNumber}</td>
                    <td>{$itemName}</td>
                    <td>{$quantity}</td>
                    <td>шт</td>
                    <td>{$formattedPrice}</td>
                    <td>{$formattedTotal}</td>
                </tr>
HTML;
                $itemNumber++;
            }
        } else {
            // Если товаров нет, показываем одну строку с суммой товаров (без доставки)
            $formattedItemsSum = \App\Helpers\PriceHelper::formatPrice($itemsSum);
            $html .= <<<HTML
                <tr>
                    <td>1</td>
                    <td>Товары по заказу №{$orderId}</td>
                    <td>1</td>
                    <td>шт</td>
                    <td>{$formattedItemsSum}</td>
                    <td>{$formattedItemsSum}</td>
                </tr>
HTML;
        }
        
        // Добавляем доставку в таблицу товаров, если она не нулевая
        if ($deliveryCost > 0) {
            $formattedDeliveryCost = \App\Helpers\PriceHelper::formatPrice($deliveryCost);
            $html .= <<<HTML
                <tr>
                    <td>{$itemNumber}</td>
                    <td>Доставка</td>
                    <td>1</td>
                    <td>шт</td>
                    <td>{$formattedDeliveryCost}</td>
                    <td>{$formattedDeliveryCost}</td>
                </tr>
HTML;
        }
        
        $html .= <<<HTML
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="5" style="text-align: right; font-weight: bold; padding-right: 10px;">Итого:</td>
                    <td style="text-align: right; font-weight: bold;">{$formattedTotalSum}</td>
                </tr>
HTML;
        
        // Добавляем строки со скидками после "Итого"
        if ($promoCodeDiscount > 0) {
            $html .= <<<HTML
                <tr>
                    <td colspan="5" style="text-align: right; padding-right: 10px; color: #dc2626;">Скидка по промокоду:</td>
                    <td style="text-align: right; color: #dc2626;">-{$formattedPromoCodeDiscount}</td>
                </tr>
HTML;
        }

        if ($bonusPointsToUse > 0) {
            $html .= <<<HTML
                <tr>
                    <td colspan="5" style="text-align: right; padding-right: 10px; color: #dc2626;">Скидка по списанию бонусов:</td>
                    <td style="text-align: right; color: #dc2626;">-{$formattedBonusDiscount}</td>
                </tr>
HTML;
        }

        if ($birthdayDiscount > 0) {
            $formattedBirthdayDiscount = \App\Helpers\PriceHelper::formatPrice($birthdayDiscount);
            $html .= <<<HTML
                <tr>
                    <td colspan="5" style="text-align: right; padding-right: 10px; color: #dc2626;">Скидка ко дню рождения:</td>
                    <td style="text-align: right; color: #dc2626;">-{$formattedBirthdayDiscount}</td>
                </tr>
HTML;
        }
        
        // Итоговая сумма к оплате (с учетом всех скидок)
        $formattedFinalAmount = \App\Helpers\PriceHelper::formatPrice($finalAmount);
        $html .= <<<HTML
                <tr>
                    <td colspan="5" style="text-align: right; font-weight: bold; padding-right: 10px;">Всего к оплате:</td>
                    <td style="text-align: right; font-weight: bold;">{$formattedFinalAmount}</td>
                </tr>
            </tfoot>
        </table>
        
        <!-- Сумма прописью -->
        <div class="amount-in-words">
            <div>Всего наименований {$itemsCount} на сумму {$formattedFinalAmount} руб.</div>
            <div><strong>{$amountInWords}</strong></div>
        </div>
        
        <!-- Примечание о НДС -->
        <div class="vat-note">
            НДС не облагается в связи с применением УСН на основании статьи 346.11 НК РФ
        </div>
        
        <!-- Примечание о счете -->
        <div class="invoice-note">
            Счёт за услуги
        </div>
        
        <!-- Подписи -->
        <div class="signature-block">
            <div class="signature-left">
                <div class="signature-line">
                    <div>Индивидуальный предприниматель</div>
                </div>
            </div>
            <div class="signature-right">
                <div class="signature-line">
                    <div class="signature-name"></div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
HTML;
        
        return $html;
    }
    
    /**
     * Преобразование числа в пропись (русский язык)
     */
    private function numberToWords($number)
    {
        $number = (float)$number;
        $rubles = floor($number);
        $kopecks = round(($number - $rubles) * 100);
        
        $rublesWords = $this->num2str($rubles);
        $result = mb_ucfirst($rublesWords) . ' ' . $this->morph($rubles, 'рубль', 'рубля', 'рублей');
        
        if ($kopecks > 0) {
            $kopecksWords = $this->num2str($kopecks);
            $result .= ' ' . $kopecksWords . ' ' . $this->morph($kopecks, 'копейка', 'копейки', 'копеек');
        } else {
            $result .= ' 00 копеек';
        }
        
        return $result;
    }
    
    private function num2str($number)
    {
        $number = (int)$number;
        if ($number == 0) {
            return 'ноль';
        }
        
        $ten = [
            ['', 'один', 'два', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять'],
            ['', 'одна', 'две', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять'],
        ];
        $a20 = ['десять', 'одиннадцать', 'двенадцать', 'тринадцать', 'четырнадцать', 'пятнадцать', 'шестнадцать', 'семнадцать', 'восемнадцать', 'девятнадцать'];
        $tens = [2 => 'двадцать', 'тридцать', 'сорок', 'пятьдесят', 'шестьдесят', 'семьдесят', 'восемьдесят', 'девяносто'];
        $hundreds = ['', 'сто', 'двести', 'триста', 'четыреста', 'пятьсот', 'шестьсот', 'семьсот', 'восемьсот', 'девятьсот'];
        $unit = [
            ['', '', '', 0], // единицы (индекс 0)
            ['тысяча', 'тысячи', 'тысяч', 1], // тысячи (индекс 1) - женский род
            ['миллион', 'миллиона', 'миллионов', 0], // миллионы (индекс 2)
            ['миллиард', 'миллиарда', 'миллиардов', 0], // миллиарды (индекс 3)
        ];
        
        $out = [];
        
        // Разбиваем число на группы по 3 цифры справа налево
        $milliards = floor($number / 1000000000) % 1000;
        $millions = floor($number / 1000000) % 1000;
        $thousands = floor($number / 1000) % 1000;
        $units = $number % 1000;
        
        // Обрабатываем миллиарды
        if ($milliards > 0) {
            $this->processGroup($milliards, 3, 0, $ten, $a20, $tens, $hundreds, $unit, $out);
        }
        
        // Обрабатываем миллионы
        if ($millions > 0) {
            $this->processGroup($millions, 2, 0, $ten, $a20, $tens, $hundreds, $unit, $out);
        }
        
        // Обрабатываем тысячи
        if ($thousands > 0) {
            $this->processGroup($thousands, 1, 1, $ten, $a20, $tens, $hundreds, $unit, $out);
        }
        
        // Обрабатываем единицы
        if ($units > 0 || count($out) == 0) {
            $this->processGroup($units, 0, 0, $ten, $a20, $tens, $hundreds, $unit, $out);
        }
        
        return trim(preg_replace('/ {2,}/', ' ', join(' ', $out)));
    }
    
    private function processGroup($value, $unitIndex, $gender, $ten, $a20, $tens, $hundreds, $unit, &$out)
    {
        if ($value == 0) {
            return;
        }
        
        list($i1, $i2, $i3) = [
            floor($value / 100),
            floor(($value % 100) / 10),
            $value % 10
        ];
        
        // Сотни
        if ($i1 > 0) {
            $out[] = $hundreds[$i1];
        }
        
        // Десятки и единицы
        if ($i2 > 1) {
            $out[] = $tens[$i2];
            if ($i3 > 0) {
                $out[] = $ten[$gender][$i3];
            }
        } elseif ($i2 == 1) {
            $out[] = $a20[$i3];
        } elseif ($i3 > 0) {
            $out[] = $ten[$gender][$i3];
        }
        
        // Единица измерения (тысяча, миллион и т.д.)
        if ($unitIndex > 0 && isset($unit[$unitIndex])) {
            $out[] = $this->morph($value, $unit[$unitIndex][0], $unit[$unitIndex][1], $unit[$unitIndex][2]);
        }
    }
    
    private function morph($n, $f1, $f2, $f5)
    {
        $n = abs($n) % 100;
        $n1 = $n % 10;
        if ($n > 10 && $n < 20) return $f5;
        if ($n1 > 1 && $n1 < 5) return $f2;
        if ($n1 == 1) return $f1;
        return $f5;
    }
    
    /**
     * Генерация Excel файла счета
     */
    public function generateInvoiceExcel(Request $request)
    {
        try {
            $orderId = $request->get('order_id', 'TEST123');
            $amount = $request->get('amount', 0);
            
            // Получаем способ оплаты типа "transfer"
            $paymentMethod = ShopPaymentMethod::where('type', 'transfer')
                ->where('is_active', true)
                ->first();
            
            if (!$paymentMethod) {
                return response()->json([
                    'success' => false,
                    'message' => 'Способ оплаты "Банковский перевод" не найден'
                ], 404);
            }
            
            $settings = $paymentMethod->settings ?? [];
            
            // Параметры из URL
            $orgTypeFromUrl = $request->get('org_type');
            $withVatFromUrl = $request->get('with_vat');
            
            if ($orgTypeFromUrl !== null && $orgTypeFromUrl !== '') {
                $organizationType = $orgTypeFromUrl;
            } else {
                $organizationType = $settings['organization_type'] ?? 'OOO';
            }
            
            if ($withVatFromUrl !== null && $withVatFromUrl !== '') {
                if ($withVatFromUrl === '0' || $withVatFromUrl === 'false' || $withVatFromUrl === false || $withVatFromUrl === 0) {
                    $withVat = false;
                } else {
                    $withVat = in_array($withVatFromUrl, ['1', 'true', true, 1], true);
                }
            } else {
                $withVat = isset($settings['with_vat']) ? (bool)$settings['with_vat'] : true;
            }
            
            // Получаем данные
            $contact = Contact::where('is_main', 1)->first();
            $mainAddress = $contact ? $contact->mainAddress() : null;
            $mainPhone = $contact ? $contact->mainPhone() : null;
            
            $order = null;
            $orderItems = [];
            $customerName = 'Покупатель';
            $customerInn = '';
            $customerAddress = '';
            $customerPhone = '';
            
            if ($orderId !== 'TEST123' && (is_numeric($orderId) || is_string($orderId))) {
                $order = ShopOrder::where('id', $orderId)
                    ->orWhere('order_number', $orderId)
                    ->first();
                
                if ($order) {
                    $orderItems = $order->getItemsWithDetails();
                    $amount = $order->total_amount ?? $amount;
                    if ($order->customer_name) {
                        $customerName = $order->customer_name;
                        if ($order->customer_phone) {
                            $customerPhone = $order->customer_phone;
                        }
                        if ($order->shipping_address) {
                            $customerAddress = $order->shipping_address;
                        }
                    }
                }
            }
            
            if (!$order && $orderId !== 'TEST123') {
                $orderItems = [
                    [
                        'good_name' => 'Тестовый товар',
                        'quantity' => 1,
                        'price' => $amount,
                        'total' => $amount
                    ]
                ];
            }
            
            // Определяем путь к шаблону
            $templatePath = base_path('schet-na-oplatu-blank-dlya-ip.xls');
            if (!file_exists($templatePath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Шаблон Excel не найден'
                ], 404);
            }
            
            // Подготавливаем данные
            $data = [
                'order_id' => $orderId,
                'date' => date('d.m.Y'),
                'total_amount' => $amount,
                'with_vat' => $withVat,
                'settings' => $settings,
                'contact' => $contact,
                'main_address' => $mainAddress,
                'main_phone' => $mainPhone,
                'order_items' => $orderItems,
                'customer_name' => $customerName,
                'customer_inn' => $customerInn,
                'customer_address' => $customerAddress,
                'customer_phone' => $customerPhone,
            ];
            
            // Заполняем шаблон
            $excelService = new InvoiceExcelService();
            $spreadsheet = $excelService->fillTemplate($templatePath, $data);
            
            // Сохраняем во временный файл
            $filename = 'invoice-' . $orderId . '-' . time() . '.xlsx';
            $filepath = storage_path('app/temp/' . $filename);
            
            if (!is_dir(dirname($filepath))) {
                mkdir(dirname($filepath), 0755, true);
            }
            
            $excelService->saveExcel($spreadsheet, $filepath);
            
            // Возвращаем файл
            return response()->download($filepath, $filename)->deleteFileAfterSend(true);
            
        } catch (\Exception $e) {
            \Log::error('Ошибка генерации Excel счета: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Внутренняя ошибка сервера',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Генерация PDF файла счета из Excel
     */
    public function generateInvoicePdf(Request $request)
    {
        try {
            // Используем тот же код, что и для Excel, но конвертируем в PDF
            $orderId = $request->get('order_id', 'TEST123');
            $amount = $request->get('amount', 0);
            
            $paymentMethod = ShopPaymentMethod::where('type', 'transfer')
                ->where('is_active', true)
                ->first();
            
            if (!$paymentMethod) {
                return response()->json([
                    'success' => false,
                    'message' => 'Способ оплаты "Банковский перевод" не найден'
                ], 404);
            }
            
            $settings = $paymentMethod->settings ?? [];
            
            $orgTypeFromUrl = $request->get('org_type');
            $withVatFromUrl = $request->get('with_vat');
            
            if ($orgTypeFromUrl !== null && $orgTypeFromUrl !== '') {
                $organizationType = $orgTypeFromUrl;
            } else {
                $organizationType = $settings['organization_type'] ?? 'OOO';
            }
            
            if ($withVatFromUrl !== null && $withVatFromUrl !== '') {
                if ($withVatFromUrl === '0' || $withVatFromUrl === 'false' || $withVatFromUrl === false || $withVatFromUrl === 0) {
                    $withVat = false;
                } else {
                    $withVat = in_array($withVatFromUrl, ['1', 'true', true, 1], true);
                }
            } else {
                $withVat = isset($settings['with_vat']) ? (bool)$settings['with_vat'] : true;
            }
            
            $contact = Contact::where('is_main', 1)->first();
            $mainAddress = $contact ? $contact->mainAddress() : null;
            $mainPhone = $contact ? $contact->mainPhone() : null;
            
            $order = null;
            $orderItems = [];
            $customerName = 'Покупатель';
            $customerInn = '';
            $customerAddress = '';
            $customerPhone = '';
            
            if ($orderId !== 'TEST123' && (is_numeric($orderId) || is_string($orderId))) {
                $order = ShopOrder::where('id', $orderId)
                    ->orWhere('order_number', $orderId)
                    ->first();
                
                if ($order) {
                    $orderItems = $order->getItemsWithDetails();
                    $amount = $order->total_amount ?? $amount;
                    if ($order->customer_name) {
                        $customerName = $order->customer_name;
                        if ($order->customer_phone) {
                            $customerPhone = $order->customer_phone;
                        }
                        if ($order->shipping_address) {
                            $customerAddress = $order->shipping_address;
                        }
                    }
                }
            }
            
            // Если это тест или заказ не найден, используем тестовые данные
            if ($orderId === 'TEST123' || (!$order && $orderId !== 'TEST123')) {
                // Для теста создаем несколько товаров с разными суммами
                if ($orderId === 'TEST123') {
                    $orderItems = [
                        [
                            'good_name' => 'Сноуборд Burton Custom X',
                            'quantity' => 1,
                            'price' => 45000,
                            'total' => 45000,
                            'unit' => 'шт'
                        ],
                        [
                            'good_name' => 'Крепления для сноуборда Union Force',
                            'quantity' => 1,
                            'price' => 18000,
                            'total' => 18000,
                            'unit' => 'шт'
                        ],
                        [
                            'good_name' => 'Ботинки для сноуборда Vans Aura',
                            'quantity' => 1,
                            'price' => 22000,
                            'total' => 22000,
                            'unit' => 'шт'
                        ],
                        [
                            'good_name' => 'Шлем Giro Ledge',
                            'quantity' => 1,
                            'price' => 8500,
                            'total' => 8500,
                            'unit' => 'шт'
                        ],
                        [
                            'good_name' => 'Очки Oakley Flight Deck',
                            'quantity' => 2,
                            'price' => 12000,
                            'total' => 24000,
                            'unit' => 'шт'
                        ]
                    ];
                    // Пересчитываем общую сумму
                    $amount = array_sum(array_column($orderItems, 'total'));
                } else {
                    $orderItems = [
                        [
                            'good_name' => 'Тестовый товар',
                            'quantity' => 1,
                            'price' => $amount,
                            'total' => $amount
                        ]
                    ];
                }
            }
            
            // Получаем данные о скидках из заказа, если он передан
            $promoCodeDiscount = 0;
            $bonusDiscount = 0;
            $birthdayDiscount = 0;
            if ($order) {
                $promoCodeDiscount = $order->promo_code_discount_amount ?? 0;
                $bonusDiscount = $order->bonus_points_to_use ?? 0; // Скидка от списанных бонусов (1 бонус = 1 рубль)
                $birthdayDiscount = $order->birthday_discount_amount ?? 0;
            }
            
            $data = [
                'order_id' => $orderId,
                'date' => date('d.m.Y'),
                'total_amount' => $amount,
                'with_vat' => $withVat,
                'settings' => $settings,
                'contact' => $contact,
                'main_address' => $mainAddress,
                'main_phone' => $mainPhone,
                'order_items' => $orderItems,
                'customer_name' => $customerName,
                'customer_inn' => $customerInn,
                'customer_address' => $customerAddress,
                'customer_phone' => $customerPhone,
                'promo_code_discount_amount' => $promoCodeDiscount,
                'bonus_points_to_use' => $bonusDiscount,
                'birthday_discount_amount' => $birthdayDiscount,
            ];
            
            // Генерируем PDF напрямую
            $pdfService = new InvoicePdfService();
            $pdfContent = $pdfService->generatePdf($data);
            
            $filename = 'invoice-' . $orderId . '-' . time() . '.pdf';
            
            return response($pdfContent, 200)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'inline; filename="' . $filename . '"');
            
        } catch (\Exception $e) {
            \Log::error('Ошибка генерации PDF счета: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Внутренняя ошибка сервера',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
