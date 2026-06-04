@php
    $isSuccess = ($eventType ?? '') === 'payment_received';
    $accent = $isSuccess ? '#16a34a' : '#dc2626';
    $accentDark = $isSuccess ? '#15803d' : '#b91c1c';
    $accentLight = $isSuccess ? '#dcfce7' : '#fee2e2';
    $accentSoft = $isSuccess ? '#f0fdf4' : '#fef2f2';
    $title = $isSuccess ? 'Заказ оплачен' : 'Оплата заказа не прошла';
    $lead = $isSuccess ? 'Получен webhook об успешной оплате заказа.' : 'Получен webhook о неудачной оплате заказа.';
    $paymentObject = $payment_object ?? [];
    $transaction = $transaction ?? null;
    $amountData = is_array($paymentObject['amount'] ?? null) ? ($paymentObject['amount']['value'] ?? $paymentObject['amount']['Value'] ?? null) : ($paymentObject['amount'] ?? null);
    $amount = $amountData ?? ($paymentObject['Amount'] ?? ($transaction->amount ?? $order->total_amount));
    $paymentId = $paymentObject['id'] ?? $paymentObject['paymentId'] ?? $paymentObject['payment_id'] ?? $paymentObject['PaymentId'] ?? ($transaction->transaction_id ?? 'Неизвестен');
    $paymentProvider = $paymentObject['provider'] ?? $paymentObject['payment_method'] ?? $paymentObject['paymentMethod'] ?? ($order->payment_method ?? 'Неизвестен');
    $paymentStatus = $paymentObject['status'] ?? $paymentObject['paymentStatus'] ?? $paymentObject['payment_status'] ?? ($isSuccess ? 'paid' : 'failed');
    $failureCandidates = [
        $transaction->error_message ?? null,
        $paymentObject['failure_reason'] ?? null,
        $paymentObject['reason'] ?? null,
        $paymentObject['reasonCode'] ?? null,
        $paymentObject['code'] ?? null,
        $paymentObject['message'] ?? null,
        $paymentObject['Message'] ?? null,
        $paymentObject['description'] ?? null,
        $paymentObject['Description'] ?? null,
        $paymentObject['details'] ?? null,
        $paymentObject['Details'] ?? null,
        $paymentObject['error'] ?? null,
        $paymentObject['Error'] ?? null,
        $paymentObject['ErrorCode'] ?? null,
        $paymentObject['errorMessage'] ?? null,
        $paymentObject['order']['reason'] ?? null,
        $paymentObject['order']['reasonCode'] ?? null,
        $paymentObject['order']['code'] ?? null,
        $paymentObject['order']['message'] ?? null,
        $paymentObject['order']['description'] ?? null,
        $paymentObject['order']['paymentError'] ?? null,
        $paymentObject['object']['cancellation_details']['reason'] ?? null,
        $paymentObject['object']['reason'] ?? null,
        $paymentObject['object']['reasonCode'] ?? null,
        $paymentObject['object']['message'] ?? null,
        $paymentObject['object']['description'] ?? null,
        $paymentObject['yandex_order_details']['data']['data']['order']['reason'] ?? null,
        $paymentObject['yandex_order_details']['data']['data']['order']['reasonCode'] ?? null,
        $paymentObject['yandex_order_details']['data']['data']['order']['message'] ?? null,
        $paymentObject['yandex_order_details']['data']['data']['order']['description'] ?? null,
        $paymentObject['yandex_order_details']['data']['data']['order']['paymentError'] ?? null,
    ];
    $failureReason = null;
    foreach ($failureCandidates as $candidate) {
        if (is_string($candidate) && trim($candidate) !== '') {
            $failureReason = mb_substr(trim($candidate), 0, 500);
            break;
        }
        if (is_numeric($candidate)) {
            $failureReason = (string) $candidate;
            break;
        }
        if (is_array($candidate)) {
            foreach (['reason', 'reasonCode', 'code', 'message', 'description', 'error', 'errorMessage'] as $key) {
                if (isset($candidate[$key]) && is_scalar($candidate[$key]) && trim((string) $candidate[$key]) !== '') {
                    $failureReason = mb_substr(trim((string) $candidate[$key]), 0, 500);
                    break 2;
                }
            }
        }
    }
    if (!$failureReason) {
        $operations = $paymentObject['yandex_order_details']['data']['data']['operations'] ?? [];
        if (is_array($operations)) {
            foreach ($operations as $operation) {
                if (!is_array($operation)) {
                    continue;
                }
                $operationStatus = $operation['status'] ?? null;
                if ($operationStatus && !in_array($operationStatus, ['FAIL', 'FAILED'], true)) {
                    continue;
                }
                foreach (['reason', 'reasonCode', 'code', 'message', 'description', 'error', 'errorMessage'] as $key) {
                    if (isset($operation[$key]) && is_scalar($operation[$key]) && trim((string) $operation[$key]) !== '') {
                        $failureReason = mb_substr(trim((string) $operation[$key]), 0, 500);
                        break 2;
                    }
                }
            }
        }
    }
    $providerNormalized = mb_strtolower((string) $paymentProvider);
    $isMinorUnitAmount = isset($paymentObject['PaymentId'])
        || str_contains($providerNormalized, 'т-банк')
        || str_contains($providerNormalized, 't-bank')
        || str_contains($providerNormalized, 'tbank');
    if (is_numeric($amount) && $isMinorUnitAmount) {
        $amount = ((float) $amount) / 100;
    }
    $itemsList = method_exists($order, 'getItemsWithDetails') ? $order->getItemsWithDetails() : [];
@endphp
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }} #{{ $order->order_number }}</title>
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
            background: linear-gradient(135deg, {{ $accent }} 0%, {{ $accentDark }} 100%);
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
            background-color: {{ $accentLight }};
            border-left: 4px solid {{ $accent }};
            padding: 15px 20px;
            margin-bottom: 25px;
            border-radius: 4px;
        }
        .alert-box h2 {
            margin: 0 0 10px 0;
            font-size: 20px;
            color: {{ $accentDark }};
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
            border-bottom: 2px solid {{ $accent }};
            padding-bottom: 10px;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e0e0e0;
            gap: 16px;
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
            color: {{ $accent }};
            font-weight: 700;
        }
        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            color: white;
            background-color: {{ $accent }};
            text-transform: uppercase;
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
            background-color: {{ $accent }};
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-weight: 600;
            font-size: 14px;
        }
        @media (max-width: 600px) {
            .content {
                padding: 20px 15px;
            }
            .info-row {
                flex-direction: column;
                gap: 4px;
            }
            .info-value {
                text-align: left;
            }
        }
    </style>
</head>
<body>
    <div class="email-wrapper">
        <div class="header">
            <div class="logo-container">
                @if(isset($siteInfo['site_logo']) && $siteInfo['site_logo'])
                    @php
                        $logoUrl = $siteInfo['site_logo'];
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

        <div class="content">
            <div class="alert-box">
                <h2>{{ $isSuccess ? 'Оплата получена' : 'Оплата отклонена' }}</h2>
                <p>{{ $lead }}</p>
            </div>

            <div class="order-card" style="background: {{ $accentSoft }};">
                <h3>Платеж</h3>
                <div class="info-row">
                    <span class="info-label">Статус:</span>
                    <span class="info-value"><span class="status-badge">{{ $isSuccess ? 'Оплачен' : 'Не оплачен' }}</span></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Сумма:</span>
                    <span class="info-value highlight">{{ number_format((float) $amount, 0, ',', ' ') }} ₽</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Способ оплаты:</span>
                    <span class="info-value">{{ $paymentProvider }}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">ID платежа:</span>
                    <span class="info-value">{{ $paymentId }}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Статус провайдера:</span>
                    <span class="info-value">{{ $paymentStatus }}</span>
                </div>
                @if(!$isSuccess && $failureReason)
                    <div class="info-row">
                        <span class="info-label">Причина:</span>
                        <span class="info-value">{{ $failureReason }}</span>
                    </div>
                @endif
            </div>

            <div class="order-card">
                <h3>Заказ #{{ $order->order_number }}</h3>
                <div class="info-row">
                    <span class="info-label">Дата заказа:</span>
                    <span class="info-value">{{ \Carbon\Carbon::parse($order->created_at)->locale('ru')->isoFormat('D MMMM YYYY, HH:mm') }}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Клиент:</span>
                    <span class="info-value">{{ $order->customer_name ?? 'Не указан' }}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Email:</span>
                    <span class="info-value">{!! $orderEmailLink ?? ($order->customer_email ?? 'Не указан') !!}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Телефон:</span>
                    <span class="info-value">{!! $orderPhoneLink ?? ($order->customer_phone ?? 'Не указан') !!}</span>
                </div>
                <div class="info-row" style="border-top: 2px solid {{ $accent }}; margin-top: 5px; padding-top: 15px;">
                    <span class="info-label" style="font-size: 16px;">Итого заказа:</span>
                    <span class="info-value highlight" style="font-size: 20px;">{{ number_format((float) $order->total_amount, 0, ',', ' ') }} ₽</span>
                </div>
            </div>

            <div class="order-card">
                <h3>Состав заказа</h3>
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
                            @forelse($itemsList as $item)
                                @php
                                    $itemName = $item['good_name'] ?? $item['name'] ?? 'Товар';
                                    $variationName = $item['variation_name'] ?? '';
                                    $quantity = $item['quantity'] ?? 1;
                                    $price = isset($item['price']) ? (float) $item['price'] : 0;
                                    $total = isset($item['total']) ? (float) $item['total'] : $price * (float) $quantity;
                                @endphp
                                <tr>
                                    <td style="padding: 10px; border-bottom: 1px solid #eee; font-size: 14px;">
                                        <div style="font-weight: 500; color: #333;">{{ $itemName }}</div>
                                        @if($variationName)
                                            <div style="font-size: 12px; color: #666; margin-top: 3px;">Вариация: {{ $variationName }}</div>
                                        @endif
                                    </td>
                                    <td style="padding: 10px; text-align: center; border-bottom: 1px solid #eee; font-size: 14px;">{{ $quantity }} шт.</td>
                                    <td style="padding: 10px; text-align: right; border-bottom: 1px solid #eee; font-size: 14px; font-weight: 500;">{{ number_format($total, 0, ',', ' ') }} ₽</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" style="padding: 15px; text-align: center; color: #888;">Товары не найдены</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="footer">
            <p>{{ $isSuccess ? 'Это автоматическое уведомление об успешной оплате.' : 'Это автоматическое уведомление о неудачной оплате.' }}</p>
            @if(isset($siteInfo['main_site']) && $siteInfo['main_site'])
                <a href="{{ $siteInfo['main_site'] }}">Перейти на сайт</a>
            @endif
            <p style="margin-top: 15px;">© {{ date('Y') }} {{ $siteInfo['site_name'] ?? 'Интернет-магазин' }}. Все права защищены.</p>
        </div>
    </div>
</body>
</html>
