@php
    $siteName = $siteInfo['site_name'] ?? 'Skate & Snow';
    $mainSite = rtrim($siteInfo['main_site'] ?? config('app.frontend_url', config('app.url')), '/');
    $logoUrl = $siteInfo['site_logo'] ?? null;
    if ($logoUrl && !str_starts_with($logoUrl, 'http://') && !str_starts_with($logoUrl, 'https://')) {
        $logoUrl = $mainSite.'/'.ltrim($logoUrl, '/');
    }

    $methodType = $paymentMethod->type ?? '';
    $methodName = $paymentMethod->name ?? ($order->payment_method ?? 'Способ оплаты');
    $isOnline = in_array($methodType, ['yookassa', 'yandex_pay', 'yandex_split', 'tbank_eacq', 'tbank_dolyame'], true);
    $isTransfer = $methodType === 'transfer';
    $items = method_exists($order, 'getItemsWithDetails') ? $order->getItemsWithDetails() : ($order->items ?? []);
    if (is_string($items)) {
        $items = json_decode($items, true) ?: [];
    }
    $settings = $paymentMethod->settings ?? [];
@endphp
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Изменен способ оплаты заказа №{{ $order->order_number }}</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            background: #f3f4f6;
            color: #1f2937;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
            line-height: 1.55;
        }
        .wrapper {
            width: 100%;
            padding: 24px 12px;
        }
        .container {
            max-width: 680px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 12px 32px rgba(15, 23, 42, 0.10);
        }
        .header {
            background: #111827;
            padding: 28px 28px 24px;
            color: #ffffff;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 24px;
        }
        .brand-logo {
            max-height: 52px;
            max-width: 170px;
            object-fit: contain;
            background: #ffffff;
            border-radius: 10px;
            padding: 8px;
        }
        .brand-name {
            font-size: 18px;
            font-weight: 700;
        }
        .header h1 {
            margin: 0;
            font-size: 25px;
            line-height: 1.2;
            font-weight: 800;
        }
        .header p {
            margin: 10px 0 0;
            color: #d1d5db;
            font-size: 15px;
        }
        .content {
            padding: 28px;
        }
        .notice {
            border-radius: 14px;
            padding: 18px;
            margin-bottom: 22px;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
        }
        .notice h2 {
            margin: 0 0 8px;
            color: #1d4ed8;
            font-size: 18px;
        }
        .notice p {
            margin: 0;
            color: #1e3a8a;
            font-size: 14px;
        }
        .method-card {
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 18px;
            margin-bottom: 22px;
            background: #f9fafb;
        }
        .method-title {
            margin: 0 0 8px;
            font-size: 18px;
            color: #111827;
        }
        .amount {
            font-size: 26px;
            font-weight: 800;
            color: #16a34a;
            margin: 8px 0 0;
        }
        .pay-button {
            display: inline-block;
            margin-top: 18px;
            padding: 14px 24px;
            background: #16a34a;
            color: #ffffff !important;
            text-decoration: none;
            border-radius: 10px;
            font-weight: 800;
            font-size: 15px;
        }
        .link-copy {
            margin-top: 14px;
            padding: 12px;
            border-radius: 10px;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            font-size: 12px;
            color: #4b5563;
            word-break: break-all;
        }
        .bank-box {
            margin-top: 16px;
            border-radius: 12px;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            padding: 14px;
        }
        .bank-row {
            padding: 7px 0;
            border-bottom: 1px solid #f3f4f6;
            font-size: 14px;
        }
        .bank-row:last-child {
            border-bottom: none;
        }
        .bank-row strong {
            display: inline-block;
            min-width: 150px;
            color: #374151;
        }
        .summary {
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            overflow: hidden;
            margin-bottom: 22px;
        }
        .summary-header {
            background: #f9fafb;
            padding: 14px 18px;
            font-weight: 800;
            color: #111827;
            border-bottom: 1px solid #e5e7eb;
        }
        .row {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            padding: 12px 18px;
            border-bottom: 1px solid #f3f4f6;
            font-size: 14px;
        }
        .row:last-child {
            border-bottom: none;
        }
        .label {
            color: #6b7280;
            font-weight: 600;
        }
        .value {
            text-align: right;
            color: #111827;
            font-weight: 700;
        }
        .items {
            margin-top: 12px;
        }
        .item {
            padding: 12px 0;
            border-bottom: 1px solid #f3f4f6;
        }
        .item:last-child {
            border-bottom: none;
        }
        .item-name {
            font-weight: 700;
            color: #111827;
            margin-bottom: 4px;
        }
        .item-meta {
            color: #6b7280;
            font-size: 13px;
        }
        .footer {
            padding: 22px 28px;
            background: #f9fafb;
            border-top: 1px solid #e5e7eb;
            text-align: center;
            color: #6b7280;
            font-size: 13px;
        }
        .footer a {
            color: #2563eb;
            text-decoration: none;
            font-weight: 700;
        }
        @media (max-width: 600px) {
            .content, .header, .footer {
                padding-left: 18px;
                padding-right: 18px;
            }
            .row {
                display: block;
            }
            .value {
                text-align: left;
                margin-top: 4px;
            }
            .bank-row strong {
                display: block;
                min-width: 0;
                margin-bottom: 2px;
            }
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="container">
            <div class="header">
                <div class="brand">
                    @if($logoUrl)
                        <img class="brand-logo" src="{{ $logoUrl }}" alt="{{ $siteName }}">
                    @endif
                    <div class="brand-name">{{ $siteName }}</div>
                </div>
                <h1>Способ оплаты изменен</h1>
                <p>Заказ №{{ $order->order_number }}</p>
            </div>

            <div class="content">
                <div class="notice">
                    <h2>Здравствуйте{{ $order->customer_name ? ', '.$order->customer_name : '' }}!</h2>
                    <p>Мы обновили способ оплаты вашего заказа. Ниже указаны актуальные данные для оплаты.</p>
                </div>

                <div class="method-card">
                    <h3 class="method-title">{{ $methodName }}</h3>
                    <div class="amount">{{ number_format((float) $order->total_amount, 0, ',', ' ') }} ₽</div>

                    @if($isOnline && $paymentUrl)
                        <a class="pay-button" href="{{ $paymentUrl }}">Оплатить заказ</a>
                        <div class="link-copy">
                            Если кнопка не открывается, скопируйте ссылку в браузер:<br>
                            {{ $paymentUrl }}
                        </div>
                    @elseif($isTransfer)
                        <p style="margin: 14px 0 0; color: #374151; font-size: 14px;">
                            Оплатите заказ банковским переводом. PDF-счет приложен к письму.
                        </p>
                        <div class="bank-box">
                            @if(!empty($settings['legal_name']))
                                <div class="bank-row"><strong>Получатель:</strong> {{ $settings['legal_name'] }}</div>
                            @endif
                            @if(!empty($settings['inn']))
                                <div class="bank-row"><strong>ИНН:</strong> {{ $settings['inn'] }}</div>
                            @endif
                            @if(!empty($settings['kpp']))
                                <div class="bank-row"><strong>КПП:</strong> {{ $settings['kpp'] }}</div>
                            @endif
                            @if(!empty($settings['bank_name']))
                                <div class="bank-row"><strong>Банк:</strong> {{ $settings['bank_name'] }}</div>
                            @endif
                            @if(!empty($settings['bik']))
                                <div class="bank-row"><strong>БИК:</strong> {{ $settings['bik'] }}</div>
                            @endif
                            @if(!empty($settings['account_number']))
                                <div class="bank-row"><strong>Расчетный счет:</strong> {{ $settings['account_number'] }}</div>
                            @endif
                            @if(!empty($settings['correspondent_account']))
                                <div class="bank-row"><strong>Корр. счет:</strong> {{ $settings['correspondent_account'] }}</div>
                            @endif
                            <div class="bank-row"><strong>Назначение:</strong> Оплата заказа №{{ $order->order_number }}</div>
                        </div>
                    @else
                        <p style="margin: 14px 0 0; color: #374151; font-size: 14px;">
                            Оплата будет выполнена выбранным способом. Платежная ссылка для него не требуется.
                        </p>
                    @endif
                </div>

                <div class="summary">
                    <div class="summary-header">Детали заказа</div>
                    <div class="row">
                        <div class="label">Номер заказа</div>
                        <div class="value">{{ $order->order_number }}</div>
                    </div>
                    <div class="row">
                        <div class="label">Дата заказа</div>
                        <div class="value">{{ $order->created_at ? $order->created_at->format('d.m.Y H:i') : 'не указана' }}</div>
                    </div>
                    <div class="row">
                        <div class="label">Покупатель</div>
                        <div class="value">{{ $order->customer_name ?: 'не указан' }}</div>
                    </div>
                    <div class="row">
                        <div class="label">Итого к оплате</div>
                        <div class="value">{{ number_format((float) $order->total_amount, 0, ',', ' ') }} ₽</div>
                    </div>
                </div>

                @if(!empty($items))
                    <div class="summary">
                        <div class="summary-header">Состав заказа</div>
                        <div class="items" style="padding: 0 18px;">
                            @foreach($items as $item)
                                @php
                                    $itemName = $item['good_name'] ?? $item['name'] ?? 'Товар';
                                    $quantity = (float) ($item['quantity'] ?? 1);
                                    $price = (float) ($item['price'] ?? $item['final_price'] ?? $item['unit_price'] ?? 0);
                                    $total = (float) ($item['total'] ?? ($price * $quantity));
                                @endphp
                                <div class="item">
                                    <div class="item-name">{{ $itemName }}</div>
                                    @if(!empty($item['variation_name']))
                                        <div class="item-meta">{{ $item['variation_name'] }}</div>
                                    @endif
                                    <div class="item-meta">{{ $quantity }} шт. × {{ number_format($price, 0, ',', ' ') }} ₽ = {{ number_format($total, 0, ',', ' ') }} ₽</div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            <div class="footer">
                <p>Это автоматическое письмо от {{ $siteName }}.</p>
                <p><a href="{{ $mainSite }}">Перейти на сайт магазина</a></p>
                @if($contacts && !empty($contacts->phone))
                    <p>Телефон: {{ $contacts->phone }}</p>
                @endif
                @if($contacts && !empty($contacts->email))
                    <p>Email: {{ $contacts->email }}</p>
                @endif
            </div>
        </div>
    </div>
</body>
</html>
