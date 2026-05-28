<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Заказ №{{ $order->order_number }} готов к оплате</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            color: #333;
            line-height: 1.4;
            background-color: #f5f5f5;
        }
        .email-container {
            max-width: 800px;
            margin: 0 auto;
            background-color: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #333;
            padding-bottom: 20px;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: bold;
            color: #333;
        }
        .header h2 {
            margin: 10px 0 0 0;
            font-size: 18px;
            color: #666;
        }
        .intro-text {
            background-color: #e8f5e8;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
            border-left: 4px solid #28a745;
        }
        .payment-info {
            background-color: #fff3cd;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
            border-left: 4px solid #ffc107;
        }
        .payment-link {
            display: inline-block;
            margin-top: 15px;
            padding: 12px 24px;
            background-color: #28a745;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-weight: bold;
        }
        .payment-link:hover {
            background-color: #218838;
        }
        .order-info {
            margin-bottom: 30px;
        }
        .order-info h3 {
            margin: 0 0 15px 0;
            font-size: 16px;
            color: #333;
        }
        .order-info p {
            margin: 5px 0;
            font-size: 14px;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }
        .items-table th,
        .items-table td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
            font-size: 14px;
        }
        .items-table th {
            background-color: #f5f5f5;
            font-weight: bold;
        }
        .items-table .text-right {
            text-align: right;
        }
        .items-table .text-center {
            text-align: center;
        }
        .footer {
            margin-top: 40px;
            text-align: center;
            font-size: 12px;
            color: #666;
            border-top: 1px solid #ddd;
            padding-top: 20px;
        }
    </style>
</head>
<body>
    <div class="email-container">
        <!-- Header -->
        <div class="header">
            @if($siteInfo && isset($siteInfo['site_logo']) && $siteInfo['site_logo'])
                <img src="{{ $siteInfo['site_logo'] }}" alt="{{ $siteInfo['site_name'] ?? 'Интернет-магазин' }}" style="max-width: 200px; margin-bottom: 20px;">
            @endif
            <h1 style="margin: 0; font-size: 28px; color: #333; font-weight: bold;">{{ $siteInfo['site_name'] ?? 'Интернет-магазин' }}</h1>
            @if($siteInfo && isset($siteInfo['site_description']))
                <p style="margin: 10px 0 0 0; font-size: 16px; color: #666; font-style: italic;">{{ $siteInfo['site_description'] }}</p>
            @endif
        </div>

        <!-- Вводный текст -->
        <div class="intro-text">
            <h2 style="margin: 0 0 15px 0; color: #155724; font-size: 20px;">
                Здравствуйте{{ $order->customer_name ? ', ' . $order->customer_name : '' }}! 👋
            </h2>
            <p style="margin: 0 0 15px 0; color: #155724; font-size: 16px; line-height: 1.5;">
                Отличные новости! Ваш заказ проверен менеджером и готов к оплате.
            </p>
        </div>

        <!-- Информация об оплате -->
        <div class="payment-info">
            <h3 style="margin: 0 0 15px 0; color: #856404; font-size: 18px; font-weight: bold;">
                💳 Оплата заказа
            </h3>
            @php
                $isTransfer = false;
                $paymentUrl = $order->payment_url ?? null;
                
                if (isset($order->payment_method_id)) {
                    $paymentMethod = \App\Models\ShopPaymentMethod::find($order->payment_method_id);
                    if ($paymentMethod) {
                        $isTransfer = $paymentMethod->type === 'transfer';
                    }
                } elseif ($order->payment_method === 'Банковский перевод') {
                    $isTransfer = true;
                }
            @endphp
            
            @if($isTransfer)
                <p style="margin: 0 0 15px 0; color: #856404; font-size: 14px; line-height: 1.5;">
                    К данному письму прикреплен счет на оплату в формате PDF с банковскими реквизитами и суммой к оплате.
                </p>
                @php
                    $invoiceUrl = config('app.url') . '/api/payment-methods/transfer/invoice/pdf?order_id=' . urlencode($order->order_number ?? $order->id) . '&amount=' . urlencode($order->total_amount ?? 0);
                @endphp
                <div style="text-align: center; margin: 20px 0;">
                    <a href="{{ $invoiceUrl }}" style="display: inline-block; padding: 12px 24px; background-color: #007bff; color: white; text-decoration: none; border-radius: 5px; font-weight: bold;">
                        📄 Скачать счет на оплату (PDF)
                    </a>
                </div>
                <p style="margin: 0 0 15px 0; color: #856404; font-size: 14px; line-height: 1.5;">
                    Вы также можете скачать счет в личном кабинете в разделе "Мои заказы".
                </p>
                <p style="margin: 0; color: #856404; font-size: 14px; line-height: 1.5;">
                    Пожалуйста, произведите оплату по указанным реквизитам.
                </p>
            @elseif($paymentUrl)
                <p style="margin: 0 0 15px 0; color: #856404; font-size: 14px; line-height: 1.5;">
                    Вы можете произвести оплату заказа по ссылке ниже или в личном кабинете в разделе "Мои заказы".
                </p>
                <a href="{{ $paymentUrl }}" class="payment-link" style="display: inline-block; margin-top: 15px; padding: 12px 24px; background-color: #28a745; color: white; text-decoration: none; border-radius: 5px; font-weight: bold;">
                    Перейти к оплате
                </a>
            @else
                <p style="margin: 0; color: #856404; font-size: 14px; line-height: 1.5;">
                    Вы можете произвести оплату заказа в личном кабинете в разделе "Мои заказы".
                </p>
            @endif
        </div>

        <div class="header">
            <h1>ИНФОРМАЦИЯ О ЗАКАЗЕ</h1>
            <h2>№ {{ $order->order_number }}</h2>
        </div>

        <div class="order-info">
            <h3>Информация о заказе:</h3>
            <p><strong>Дата заказа:</strong> {{ \Carbon\Carbon::parse($order->created_at)->locale('ru')->isoFormat('D MMMM YYYY, HH:mm') }}</p>
            <p><strong>Статус:</strong> {{ $order->status->display_name ?? $order->status->name ?? 'Неизвестно' }}</p>
            <p><strong>Способ доставки:</strong> {{ $order->shipping_method ?? 'Не указано' }}</p>
            <p><strong>Способ оплаты:</strong> {{ $order->payment_method ?? 'Не указано' }}</p>
            @if($order->shipping_address)
                <p><strong>Адрес доставки:</strong> {{ $order->shipping_address }}</p>
            @endif
        </div>

        <table class="items-table">
            <thead>
                <tr>
                    <th>№</th>
                    <th>Наименование товара</th>
                    <th class="text-center">Кол-во</th>
                    <th class="text-right">Цена</th>
                    <th class="text-right">Сумма</th>
                </tr>
            </thead>
            <tbody>
                @php
                    $orderItems = method_exists($order, 'getItemsWithDetails')
                        ? $order->getItemsWithDetails()
                        : $order->items;
                    if (is_string($orderItems)) {
                        $orderItems = json_decode($orderItems, true);
                    }
                    if (!is_array($orderItems)) {
                        $orderItems = [];
                    }
                @endphp
                @if(!empty($orderItems))
                    @foreach($orderItems as $index => $item)
                        @php
                            $quantity = (float) ($item['quantity'] ?? 1);
                            $itemPrice = (float) ($item['final_price'] ?? $item['unit_price'] ?? $item['price'] ?? 0);
                            $itemTotal = (float) ($item['total'] ?? ($itemPrice * $quantity));
                        @endphp
                        <tr>
                            <td class="text-center">{{ $index + 1 }}</td>
                            <td>
                                @php
                                    $itemName = $item['good_name'] ?? $item['name'] ?? 'Товар';
                                    $variationName = $item['variation_name'] ?? '';
                                    $sku = $item['variation_sku'] ?? $item['good_sku'] ?? $item['sku'] ?? '';
                                @endphp
                                <div style="font-weight: 500;">{{ $itemName }}</div>
                                @if(!empty($variationName) && $variationName !== $itemName)
                                    <div style="font-size: 12px; color: #666; margin-top: 2px;">Вариация: {{ $variationName }}</div>
                                @endif
                                @if(!empty($sku))
                                    <div style="font-size: 11px; color: #888;">Арт: {{ $sku }}</div>
                                @endif
                            </td>
                            <td class="text-center">{{ $quantity }}</td>
                            <td class="text-right">{{ number_format($itemPrice, 0, ',', ' ') }} ₽</td>
                            <td class="text-right">{{ number_format($itemTotal, 0, ',', ' ') }} ₽</td>
                        </tr>
                    @endforeach
                @else
                    <tr>
                        <td colspan="5" class="text-center">Товары не найдены</td>
                    </tr>
                @endif
            </tbody>
        </table>

        <div class="order-info">
            <p><strong>Итого к оплате:</strong> <span style="font-size: 18px; font-weight: bold;">{{ number_format($order->total_amount ?? 0, 0, ',', ' ') }} ₽</span></p>
        </div>

        @php
            $isCdek = false;
            $methodName = strtolower($order->shipping_method ?? '');
            if (!empty($order->deliveryMethod) && isset($order->deliveryMethod->type)) {
                $isCdek = $order->deliveryMethod->type === 'cdek';
            } elseif ($methodName) {
                $isCdek = strpos($methodName, 'сдэк') !== false || strpos($methodName, 'cdek') !== false;
            }
            $isCod = false;
            $paymentName = strtolower($order->payment_method ?? '');
            if ($paymentName) {
                $isCod = strpos($paymentName, 'получении') !== false || strpos($paymentName, 'наложенный') !== false;
            }
        @endphp
        @if($isCdek && $isCod)
        <div style="margin: 20px 0; padding: 12px 14px; background: #FFF7ED; border: 1px solid #FDBA74; border-radius: 6px; color: #9A3412;">
            Доставка через СДЭК с оплатой при получении: стоимость доставки и страхования оплачивается при получении. Итоговая сумма у курьера может отличаться от суммы товаров.
        </div>
        @endif

        <div class="footer">
            <p>Письмо отправлено {{ \Carbon\Carbon::now()->locale('ru')->isoFormat('D MMMM YYYY, HH:mm') }}</p>
            <p>Спасибо за ваш заказ!</p>
            <p>
                <a href="{{ $siteInfo['main_site'] ?? config('app.url') }}" style="color: #28a745; text-decoration: none; font-weight: bold;">
                    Посетить интернет-магазин
                </a>
            </p>
        </div>
    </div>
</body>
</html>
