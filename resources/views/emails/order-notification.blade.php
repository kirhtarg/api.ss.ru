<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Новый заказ #{{ $order->order_number }}</title>
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
            background-color: #e3f2fd;
            border-left: 4px solid #2196f3;
            padding: 15px 20px;
            margin-bottom: 25px;
            border-radius: 4px;
        }
        .alert-box h2 {
            margin: 0 0 10px 0;
            font-size: 20px;
            color: #1976d2;
        }
        .alert-box p {
            margin: 5px 0;
            color: #424242;
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
            border-bottom: 2px solid #667eea;
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
            color: #667eea;
            font-weight: 600;
        }
        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .status-paid {
            background-color: #4caf50;
            color: white;
        }
        .status-unpaid {
            background-color: #ff9800;
            color: white;
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
            background-color: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-weight: 600;
            font-size: 14px;
        }
        .footer a:hover {
            background-color: #5568d3;
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
            <!-- Уведомление о новом заказе -->
            <div class="alert-box">
                <h2>🆕 Новый заказ создан!</h2>
                <p>Поступил новый заказ, требующий обработки.</p>
            </div>

            <!-- Информация о заказе -->
            <div class="order-card">
                <h3>📋 Информация о заказе</h3>
                
                <div class="info-row">
                    <span class="info-label">Номер заказа:</span>
                    <span class="info-value highlight">#{{ $order->order_number }}</span>
                </div>
                
                <div class="info-row">
                    <span class="info-label">Дата создания:</span>
                    <span class="info-value">{{ \Carbon\Carbon::parse($order->created_at)->locale('ru')->isoFormat('D MMMM YYYY, HH:mm') }}</span>
                </div>
                
                <div class="info-row">
                    <span class="info-label">Количество товаров:</span>
                    <span class="info-value highlight">{{ $order->total_quantity }} шт.</span>
                </div>
                
                <div class="info-row">
                    <span class="info-label">Способ доставки:</span>
                    <span class="info-value">{{ $order->shipping_method ?? 'Не указан' }}</span>
                </div>
                
                <div class="info-row">
                    <span class="info-label">Способ оплаты:</span>
                    <span class="info-value">{{ $order->payment_method ?? 'Не указан' }}</span>
                </div>
                
                <div class="info-row">
                    <span class="info-label">Статус оплаты:</span>
                    <span class="info-value">
                        <span class="status-badge {{ $order->payed ? 'status-paid' : 'status-unpaid' }}">
                            {{ $order->payed ? 'Оплачен' : 'Не оплачен' }}
                        </span>
                    </span>
                </div>
                
                <div class="info-row">
                    <span class="info-label">Сумма заказа:</span>
                    <span class="info-value highlight" style="font-size: 18px; font-weight: 700;">
                        {{ number_format($order->total_amount, 0, ',', ' ') }} ₽
                    </span>
                </div>

                @php
                    $method = strtolower($order->shipping_method ?? '');
                    $isCdek = ($method && (str_contains($method, 'сдэк') || str_contains($method, 'cdek')));
                    $cdekSettings = \App\Models\ShopCdekSettings::getActive();
                    $customerPays = $cdekSettings && ($cdekSettings->customer_pays_delivery ?? false);
                    $approxCost = isset($order->metadata['delivery_cost']) ? (float)$order->metadata['delivery_cost'] : 0;
                @endphp
                @if($isCdek && $customerPays)
                <div style="margin-top: 10px; padding: 12px; background: #FFF8E1; border: 1px solid #FFE082; border-radius: 6px;">
                    <span style="color: #8D6E63; font-weight: 700;">
                        ВНИМАНИЕ! При получении заказа Вам необходимо будет оплатить доставку{!! $approxCost > 0 ? ', ориентировочная цена доставки — ' . number_format($approxCost, 0, ',', ' ') . ' ₽' : '' !!}.
                    </span>
                </div>
                @endif
            </div>

            <!-- Товары в заказе -->
            <div class="order-card">
                <h3>🛍️ Состав заказа</h3>
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse; margin-top: 10px;">
                        <thead>
                            <tr style="background-color: #f0f2f5;">
                                <th style="padding: 10px; text-align: left; border-bottom: 2px solid #ddd; font-size: 14px; color: #555;">Товар</th>
                                <th style="padding: 10px; text-align: center; border-bottom: 2px solid #ddd; font-size: 14px; color: #555;">Кол-во</th>
                                <th style="padding: 10px; text-align: right; border-bottom: 2px solid #ddd; font-size: 14px; color: #555;">Сумма</th>
                            </tr>
                        </thead>
                        <tbody>
                            @if($order->items && (is_array($order->items) || is_string($order->items)))
                                @php
                                    $itemsList = is_string($order->items) ? json_decode($order->items, true) : $order->items;
                                @endphp
                                @if(is_array($itemsList) && count($itemsList) > 0)
                                    @foreach($itemsList as $item)
                                        <tr>
                                            <td style="padding: 10px; border-bottom: 1px solid #eee; font-size: 14px;">
                                                @php
                                                    $itemName = $item['good_name'] ?? $item['name'] ?? $item['title'] ?? $item['product_name'] ?? 'Товар';
                                                    $variationName = '';
                                                    if (isset($item['variation_name']) && $item['variation_name'] && $item['variation_name'] !== $itemName) {
                                                        $variationName = $item['variation_name'];
                                                    }
                                                    $sku = $item['variation_sku'] ?? $item['good_sku'] ?? $item['sku'] ?? '';
                                                @endphp
                                                <div style="font-weight: 500; color: #333;">{{ $itemName }}</div>
                                                @if(!empty($variationName))
                                                    <div style="font-size: 12px; color: #666; margin-top: 3px;">Вариация: {{ $variationName }}</div>
                                                @endif
                                                @if(!empty($sku))
                                                    <div style="font-size: 12px; color: #888; font-family: monospace;">Арт: {{ $sku }}</div>
                                                @endif
                                            </td>
                                            <td style="padding: 10px; text-align: center; border-bottom: 1px solid #eee; font-size: 14px;">
                                                {{ $item['quantity'] ?? 1 }} шт.
                                            </td>
                                            <td style="padding: 10px; text-align: right; border-bottom: 1px solid #eee; font-size: 14px; font-weight: 500;">
                                                {{ number_format($item['total'] ?? 0, 0, ',', ' ') }} ₽
                                            </td>
                                        </tr>
                                    @endforeach
                                @endif
                            @else
                                <tr>
                                    <td colspan="3" style="padding: 15px; text-align: center; color: #888;">Товары не найдены</td>
                                </tr>
                            @endif
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Информация о клиенте -->
            <div class="order-card">
                <h3>👤 Информация о клиенте</h3>
                
                <div class="info-row">
                    <span class="info-label">Имя:</span>
                    <span class="info-value">{{ $order->customer_name ?? 'Не указано' }}</span>
                </div>
                
                <div class="info-row">
                    <span class="info-label">Email:</span>
                    <span class="info-value">{{ $order->customer_email ?? 'Не указан' }}</span>
                </div>
                
                <div class="info-row">
                    <span class="info-label">Телефон:</span>
                    <span class="info-value">{!! $orderPhoneLink ?? ($order->customer_phone ?? 'Не указан') !!}</span>
                </div>
                
                @if($order->shipping_address)
                <div class="info-row">
                    <span class="info-label">Адрес доставки:</span>
                    <span class="info-value">{{ $order->shipping_address }}</span>
                </div>
                @endif
                
                @if($order->notes)
                <div class="info-row">
                    <span class="info-label">Комментарий:</span>
                    <span class="info-value">{{ $order->notes }}</span>
                </div>
                @endif
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>Это автоматическое уведомление о новом заказе.</p>
            @if(isset($siteInfo['main_site']) && $siteInfo['main_site'])
                <a href="{{ $siteInfo['main_site'] }}">Перейти на сайт</a>
            @endif
            <p style="margin-top: 15px;">© {{ date('Y') }} {{ $siteInfo['site_name'] ?? 'Интернет-магазин' }}. Все права защищены.</p>
        </div>
    </div>
</body>
</html>

