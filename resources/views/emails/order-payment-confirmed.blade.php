<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Подтверждение оплаты заказа №{{ $order->order_number }}</title>
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
            border-bottom: 2px solid #28a745;
            padding-bottom: 20px;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: bold;
            color: #28a745;
        }
        .header h2 {
            margin: 10px 0 0 0;
            font-size: 18px;
            color: #666;
        }
        .company-info {
            margin-bottom: 30px;
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 5px;
        }
        .company-info h3 {
            margin: 0 0 15px 0;
            font-size: 16px;
            color: #333;
        }
        .info-table {
            width: 100%;
            margin-bottom: 20px;
        }
        .info-table td {
            padding: 8px 0;
            vertical-align: top;
        }
        .info-table .label {
            font-weight: bold;
            width: 200px;
            color: #555;
        }
        .order-details {
            margin-bottom: 30px;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            overflow: hidden;
        }
        .order-details table {
            width: 100%;
            border-collapse: collapse;
        }
        .order-details th,
        .order-details td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #dee2e6;
        }
        .order-details th {
            background-color: #f8f9fa;
            font-weight: bold;
            color: #333;
        }
        .order-details .text-right {
            text-align: right;
        }
        .total-section {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 2px solid #dee2e6;
            text-align: right;
        }
        .total-section .total-row {
            margin-bottom: 10px;
            font-size: 16px;
        }
        .total-section .grand-total {
            font-size: 18px;
            font-weight: bold;
            color: #28a745;
        }
        .status-success {
            background-color: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
            border: 1px solid #c3e6cb;
        }
        .status-success strong {
            font-size: 18px;
        }
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #dee2e6;
            text-align: center;
            color: #666;
            font-size: 14px;
        }
        .contact-info {
            background-color: #e9ecef;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
        @media (max-width: 600px) {
            .email-container {
                padding: 15px;
            }
            .info-table .label {
                width: 150px;
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
    <div class="email-container">
        <!-- Заголовок -->
        <div class="header">
            <h1>✅ Оплата получена!</h1>
            <h2>Заказ №{{ $order->order_number }}</h2>
        </div>

        <!-- Сообщение об успешной оплате -->
        <div class="status-success">
            <strong>🎉 Ваш заказ успешно оплачен!</strong><br>
            Мы получили оплату и приступаем к обработке вашего заказа.
        </div>

        <!-- Информация о компании -->
        @if($contacts)
        <div class="company-info">
            <h3>Информация о продавце</h3>
            <table class="info-table">
                <tr>
                    <td class="label">Название:</td>
                    <td>{{ $contacts['name'] ?? 'Не указано' }}</td>
                </tr>
                @if($contacts['phone'])
                <tr>
                    <td class="label">Телефон:</td>
                    <td>{{ $contacts['phone'] }}</td>
                </tr>
                @endif
                @if($contacts['legal_address'])
                <tr>
                    <td class="label">Адрес:</td>
                    <td>{{ $contacts['legal_address'] }}</td>
                </tr>
                @endif
            </table>
        </div>
        @endif

        <!-- Информация о заказе -->
        <table class="info-table">
            <tr>
                <td class="label">Номер заказа:</td>
                <td><strong>{{ $order->order_number }}</strong></td>
            </tr>
            <tr>
                <td class="label">Дата оплаты:</td>
                <td>{{ now()->format('d.m.Y H:i') }}</td>
            </tr>
            <tr>
                <td class="label">Способ оплаты:</td>
                <td>{{ $order->payment_method ?? 'Не указан' }}</td>
            </tr>
            <tr>
                <td class="label">Статус оплаты:</td>
                <td><span style="color: #28a745; font-weight: bold;">✅ Оплачен</span></td>
            </tr>
            @if($order->shipping_method)
            <tr>
                <td class="label">Способ доставки:</td>
                <td>{{ $order->shipping_method }}</td>
            </tr>
            @endif
            @if($order->shipping_address)
            <tr>
                <td class="label">Адрес доставки:</td>
                <td>{{ $order->shipping_address }}</td>
            </tr>
            @endif
        </table>

        <!-- Информация о покупателе -->
        <h3 style="margin: 30px 0 15px 0; color: #333;">Информация о покупателе</h3>
        <table class="info-table">
            <tr>
                <td class="label">Имя:</td>
                <td>{{ $order->customer_name }}</td>
            </tr>
            <tr>
                <td class="label">Email:</td>
                <td>{{ $order->customer_email }}</td>
            </tr>
            @if($order->customer_phone)
            <tr>
                <td class="label">Телефон:</td>
                <td>{{ $order->customer_phone }}</td>
            </tr>
            @endif
        </table>

        <!-- Состав заказа -->
        @php
            $orderItems = method_exists($order, 'getItemsWithDetails') ? $order->getItemsWithDetails() : ($order->items ?? []);
        @endphp
        @if($orderItems && is_array($orderItems) && count($orderItems) > 0)
        <h3 style="margin: 30px 0 15px 0; color: #333;">Состав заказа</h3>
        <div class="order-details">
            <table>
                <thead>
                    <tr>
                        <th>Наименование</th>
                        <th>Количество</th>
                        <th>Цена</th>
                        <th>Сумма</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($orderItems as $item)
                    @php
                        $quantity = (float) ($item['quantity'] ?? 1);
                        $itemPrice = (float) ($item['final_price'] ?? $item['unit_price'] ?? $item['price'] ?? 0);
                        $itemTotal = (float) ($item['total'] ?? ($itemPrice * $quantity));
                    @endphp
                    <tr>
                        <td>
                            {{ $item['good_name'] ?? $item['name'] ?? 'Товар' }}
                            @if(!empty($item['variation_name']))
                                <br><small style="color: #666;">{{ $item['variation_name'] }}</small>
                            @endif
                        </td>
                        <td>{{ $quantity }} шт.</td>
                        <td class="text-right">{{ number_format($itemPrice, 0, ',', ' ') }} ₽</td>
                        <td class="text-right">{{ number_format($itemTotal, 0, ',', ' ') }} ₽</td>
                    </tr>
                    @endforeach

                    <!-- Доставка -->
                    @if($order->delivery_cost > 0)
                    <tr>
                        <td colspan="3" style="text-align: right; font-weight: bold;">Доставка:</td>
                        <td class="text-right">{{ number_format($order->delivery_cost, 0, ',', ' ') }} ₽</td>
                    </tr>
                    @endif

                    <!-- Скидки -->
                    @if($order->promo_code_discount_amount > 0)
                    <tr style="color: #dc3545;">
                        <td colspan="3" style="text-align: right;">Скидка по промокоду:</td>
                        <td class="text-right">-{{ number_format($order->promo_code_discount_amount, 0, ',', ' ') }} ₽</td>
                    </tr>
                    @endif

                    @if($order->birthday_discount_amount > 0)
                    <tr style="color: #dc3545;">
                        <td colspan="3" style="text-align: right;">Скидка ко дню рождения:</td>
                        <td class="text-right">-{{ number_format($order->birthday_discount_amount, 0, ',', ' ') }} ₽</td>
                    </tr>
                    @endif

                    @if($order->bonus_points_to_use > 0)
                    <tr style="color: #dc3545;">
                        <td colspan="3" style="text-align: right;">Скидка по списанию бонусов:</td>
                        <td class="text-right">-{{ number_format($order->bonus_points_to_use, 0, ',', ' ') }} ₽</td>
                    </tr>
                    @endif
                </tbody>
            </table>
        </div>
        @endif

        <!-- Итого -->
        <div class="total-section">
            <div class="total-row grand-total">
                Итого к оплате: {{ number_format($order->total_amount, 0, ',', ' ') }} ₽
            </div>
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

        <!-- Информация о следующих шагах -->
        <div class="contact-info">
            <h3 style="margin: 0 0 10px 0; color: #333;">Что происходит дальше?</h3>
            <ul style="margin: 0; padding-left: 20px;">
                <li>Мы приступаем к сборке вашего заказа</li>
                <li>Вы получите уведомление о готовности к отправке</li>
                <li>При необходимости мы свяжемся с вами для уточнения деталей</li>
            </ul>
        </div>

        <!-- Контактная информация -->
        <div class="contact-info">
            <strong>Есть вопросы?</strong><br>
            Свяжитесь с нами:
            @if($contacts && $contacts['phone'])
                Телефон: {{ $contacts['phone'] }}<br>
            @endif
            Email: {{ $contacts['email'] ?? 'info@skateandsnow.ru' }}
        </div>

        <!-- Футер -->
        <div class="footer">
            <p>Спасибо за покупку в нашем магазине!</p>
            <p>С уважением, команда {{ $siteInfo['site_name'] ?? 'Skate and Snow' }}</p>
        </div>
    </div>
</body>
</html>
