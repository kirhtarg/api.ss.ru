<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Заявка на отмену заказа #{{ $order->order_number }}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f5f5f5;
            color: #333;
            line-height: 1.6;
        }
        .email-wrapper {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
        }
        .header {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            padding: 30px 20px;
            text-align: center;
            color: white;
        }
        .logo-container {
            margin-bottom: 15px;
        }
        .logo-container img {
            max-height: 60px;
            max-width: 200px;
            height: auto;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 600;
            color: white;
        }
        .header p {
            margin: 10px 0 0 0;
            font-size: 14px;
            opacity: 0.9;
        }
        .content {
            padding: 30px 20px;
        }
        .alert-box {
            background-color: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 15px 20px;
            margin-bottom: 25px;
            border-radius: 4px;
        }
        .alert-box h2 {
            margin: 0 0 10px 0;
            font-size: 20px;
            color: #92400e;
        }
        .alert-box p {
            margin: 5px 0;
            color: #78350f;
            font-size: 14px;
        }
        .order-card {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .order-card h3 {
            margin: 0 0 15px 0;
            font-size: 18px;
            color: #333;
            border-bottom: 2px solid #f59e0b;
            padding-bottom: 10px;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e0e0e0;
        }
        .info-row:last-child {
            border-bottom: none;
        }
        .info-label {
            font-weight: 600;
            color: #666;
            font-size: 14px;
        }
        .info-value {
            color: #333;
            font-size: 14px;
            text-align: right;
        }
        .highlight {
            color: #f59e0b;
            font-weight: 600;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        .items-table th,
        .items-table td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
            font-size: 14px;
        }
        .items-table th {
            background-color: #f8f9fa;
            font-weight: 600;
            color: #666;
        }
        .items-table .text-right {
            text-align: right;
        }
        .items-table .text-center {
            text-align: center;
        }
        .total-row {
            background-color: #fef3c7;
            font-weight: 600;
        }
        .footer {
            background-color: #f8f9fa;
            padding: 25px 20px;
            text-align: center;
            border-top: 1px solid #e0e0e0;
        }
        .footer p {
            margin: 8px 0;
            font-size: 12px;
            color: #666;
        }
        .footer a {
            display: inline-block;
            margin-top: 15px;
            padding: 12px 30px;
            background-color: #f59e0b;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-weight: 600;
            font-size: 14px;
        }
        .footer a:hover {
            background-color: #d97706;
        }
        @media (max-width: 600px) {
            .content {
                padding: 20px 15px;
            }
            .info-row {
                flex-direction: column;
            }
            .info-value {
                text-align: left;
                margin-top: 5px;
            }
        }
    </style>
</head>
<body>
    <div class="email-wrapper">
        <!-- Header с логотипом и названием -->
        <div class="header">
            <div class="logo-container">
                @if(isset($siteInfo['site_logo']) && $siteInfo['site_logo'])
                    @php
                        $logoUrl = $siteInfo['site_logo'];
                        // Если это не полный URL, строим путь из main_site
                        if (!str_starts_with($logoUrl, 'http://') && !str_starts_with($logoUrl, 'https://')) {
                            $mainSite = rtrim($siteInfo['main_site'] ?? config('app.url'), '/');
                            $logoUrl = $mainSite . '/' . ltrim($logoUrl, '/');
                        }
                    @endphp
                    <img src="{{ $logoUrl }}" alt="Логотип {{ $siteInfo['site_name'] ?? 'Магазина' }}">
                @endif
            </div>
            <h1>{{ $siteInfo['site_name'] ?? 'Интернет-магазин' }}</h1>
            @if(isset($siteInfo['main_site']) && $siteInfo['main_site'])
                <p><a href="{{ $siteInfo['main_site'] }}" style="color: white; text-decoration: underline;">{{ $siteInfo['main_site'] }}</a></p>
            @endif
        </div>

        <!-- Основной контент -->
        <div class="content">
            <!-- Уведомление о заявке на отмену -->
            <div class="alert-box">
                <h2>⚠️ Заявка на отмену оплаченного заказа!</h2>
                <p>Пользователь отправил заявку на отмену оплаченного заказа. Требуется обработка заявки администратором.</p>
            </div>

            <!-- Информация о заказе -->
            <div class="order-card">
                <h3>📋 Информация о заказе</h3>
                
                <div class="info-row">
                    <span class="info-label">Номер заказа:</span>
                    <span class="info-value highlight">#{{ $order->order_number }}</span>
                </div>
                
                <div class="info-row">
                    <span class="info-label">Дата заказа:</span>
                    <span class="info-value">{{ \Carbon\Carbon::parse($order->created_at)->locale('ru')->isoFormat('D MMMM YYYY, HH:mm') }}</span>
                </div>
                
                <div class="info-row">
                    <span class="info-label">Дата заявки на отмену:</span>
                    <span class="info-value highlight">{{ \Carbon\Carbon::now()->locale('ru')->isoFormat('D MMMM YYYY, HH:mm') }}</span>
                </div>
                
                <div class="info-row">
                    <span class="info-label">Статус:</span>
                    <span class="info-value">{{ $order->status->display_name ?? $order->status->name ?? 'Не указан' }}</span>
                </div>
                
                <div class="info-row">
                    <span class="info-label">Статус оплаты:</span>
                    <span class="info-value highlight">Оплачен</span>
                </div>
            </div>

            <!-- Информация о клиенте -->
            <div class="order-card">
                <h3>👤 Информация о клиенте</h3>
                
                <div class="info-row">
                    <span class="info-label">Имя:</span>
                    <span class="info-value">{{ $order->customer_name }}</span>
                </div>
                
                <div class="info-row">
                    <span class="info-label">Email:</span>
                    <span class="info-value">{!! isset($orderEmailLink) ? $orderEmailLink : $order->customer_email !!}</span>
                </div>
                
                <div class="info-row">
                    <span class="info-label">Телефон:</span>
                    <span class="info-value">{!! $orderPhoneLink ?? $order->customer_phone !!}</span>
                </div>
            </div>

            <!-- Информация о доставке -->
            <div class="order-card">
                <h3>🚚 Информация о доставке</h3>
                
                <div class="info-row">
                    <span class="info-label">Способ доставки:</span>
                    <span class="info-value">{{ $order->deliveryMethod->name ?? $order->shipping_method ?? 'Не указано' }}</span>
                </div>
                
                @if($order->shipping_address)
                <div class="info-row">
                    <span class="info-label">Адрес доставки:</span>
                    <span class="info-value">{{ $order->shipping_address }}</span>
                </div>
                @endif
            </div>

            <!-- Товары в заказе -->
            <div class="order-card">
                <h3>🛍️ Товары в заказе</h3>
                <table class="items-table">
                    <thead>
                        <tr>
                            <th>Товар</th>
                            <th class="text-center">Кол-во</th>
                            <th class="text-right">Цена</th>
                            <th class="text-right">Сумма</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $items = is_string($order->items) ? json_decode($order->items, true) : $order->items;
                            $items = is_array($items) ? $items : [];
                        @endphp
                        @foreach($items as $item)
                        <tr>
                            <td>
                                <div>
                                    <div style="font-weight: 600;">{{ $item['good_name'] ?? 'Товар' }}</div>
                                    @if(isset($item['variation_name']) && $item['variation_name'])
                                        <div style="font-size: 12px; color: #666;">{{ $item['variation_name'] }}</div>
                                    @endif
                                    @if(isset($item['variation_sku']) && $item['variation_sku'])
                                        <div style="font-size: 11px; color: #999;">Артикул: {{ $item['variation_sku'] }}</div>
                                    @endif
                                </div>
                            </td>
                            <td class="text-center">{{ $item['quantity'] ?? 1 }} шт.</td>
                            <td class="text-right">{{ number_format($item['price'] ?? 0, 0, ',', ' ') }} ₽</td>
                            <td class="text-right">{{ number_format($item['total'] ?? ($item['price'] ?? 0) * ($item['quantity'] ?? 1), 0, ',', ' ') }} ₽</td>
                        </tr>
                        @endforeach
                        <tr class="total-row">
                            <td colspan="3" style="text-align: right; font-weight: 600;">Итого:</td>
                            <td class="text-right" style="font-weight: 600;">{{ number_format($order->total_amount, 0, ',', ' ') }} ₽</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Дополнительная информация -->
            @if($order->notes)
            <div class="order-card">
                <h3>📝 Комментарий к заказу</h3>
                <p style="color: #666; font-size: 14px;">{{ $order->notes }}</p>
            </div>
            @endif
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>Это автоматическое уведомление о заявке на отмену оплаченного заказа.</p>
            @if(isset($siteInfo['main_site']) && $siteInfo['main_site'])
                <a href="{{ $siteInfo['main_site'] }}">Перейти на сайт</a>
            @endif
            <p style="margin-top: 15px;">© {{ date('Y') }} {{ $siteInfo['site_name'] ?? 'Интернет-магазин' }}. Все права защищены.</p>
        </div>
    </div>
</body>
</html>

