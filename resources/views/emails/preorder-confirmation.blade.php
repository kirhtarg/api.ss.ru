<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ваш предзаказ принят</title>
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
        .success-box {
            background-color: #e8f5e9;
            border-left: 4px solid #4caf50;
            padding: 20px;
            margin-bottom: 25px;
            border-radius: 4px;
        }
        .success-box h2 {
            margin: 0 0 10px 0;
            font-size: 20px;
            color: #2e7d32;
        }
        .success-box p {
            margin: 5px 0;
            color: #1b5e20;
            font-size: 14px;
        }
        .preorder-card {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .preorder-card h3 {
            margin: 0 0 15px 0;
            font-size: 18px;
            color: #333;
            border-bottom: 2px solid #667eea;
            padding-bottom: 10px;
        }
        .product-info {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid #e0e0e0;
        }
        .product-info:last-child {
            border-bottom: none;
        }
        .product-image {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 8px;
            background-color: #e0e0e0;
        }
        .product-details {
            flex: 1;
        }
        .product-name {
            font-size: 16px;
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
        }
        .product-variation {
            font-size: 14px;
            color: #666;
            margin-bottom: 8px;
        }
        .product-sku {
            font-size: 12px;
            color: #999;
            margin-bottom: 8px;
        }
        .product-quantity {
            font-size: 14px;
            color: #333;
            margin-bottom: 5px;
        }
        .product-price {
            font-size: 18px;
            font-weight: 700;
            color: #667eea;
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
        .total-box {
            background-color: #f0f4ff;
            border: 2px solid #667eea;
            border-radius: 8px;
            padding: 20px;
            margin-top: 20px;
            text-align: center;
        }
        .total-label {
            font-size: 14px;
            color: #666;
            margin-bottom: 5px;
        }
        .total-amount {
            font-size: 28px;
            font-weight: 700;
            color: #667eea;
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
            .product-info {
                flex-direction: column;
            }
            .product-image {
                width: 100%;
                height: 200px;
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
            <!-- Уведомление о принятом предзаказе -->
            <div class="success-box">
                <h2>✅ Ваш предзаказ принят!</h2>
                <p>Спасибо за ваш предзаказ! Мы получили вашу заявку и уведомим вас, как только товар поступит на склад.</p>
            </div>

            <!-- Информация о предзаказе -->
            <div class="preorder-card">
                <h3>📦 Информация о предзаказе</h3>
                
                <div class="product-info">
                    @if($preorder->good_image)
                        <img src="{{ $preorder->good_image }}" alt="{{ $preorder->good_name }}" class="product-image">
                    @endif
                    <div class="product-details">
                        <div class="product-name">{{ $preorder->good_name }}</div>
                        @if($preorder->variation_name)
                            <div class="product-variation">Вариация: {{ $preorder->variation_name }}</div>
                        @endif
                        @if($preorder->good_sku)
                            <div class="product-sku">Артикул: {{ $preorder->good_sku }}</div>
                        @endif
                        <div class="product-quantity">Количество: {{ $preorder->quantity }} шт.</div>
                        <div class="product-price">
                            @if($preorder->price == 0)
                                цена уточняется
                            @else
                                {{ number_format($preorder->price, 0, ',', ' ') }} ₽ за шт.
                            @endif
                        </div>
                    </div>
                </div>
                
                <div class="info-row">
                    <span class="info-label">Дата оформления:</span>
                    <span class="info-value">{{ \Carbon\Carbon::parse($preorder->created_at)->locale('ru')->isoFormat('D MMMM YYYY, HH:mm') }}</span>
                </div>
                
                <div class="info-row">
                    <span class="info-label">Статус:</span>
                    <span class="info-value highlight">Ожидает поступления</span>
                </div>
                
                @if($preorder->notes)
                <div class="info-row">
                    <span class="info-label">Ваш комментарий:</span>
                    <span class="info-value">{{ $preorder->notes }}</span>
                </div>
                @endif
            </div>

            <!-- Итоговая сумма -->
            <div class="total-box">
                <div class="total-label">Сумма предзаказа</div>
                <div class="total-amount">
                    @if($preorder->total == 0)
                        цена уточняется
                    @else
                        {{ number_format($preorder->total, 0, ',', ' ') }} ₽
                    @endif
                </div>
            </div>

            <!-- Информация для клиента -->
            <div style="background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin-top: 20px; border-radius: 4px;">
                <p style="margin: 0; color: #856404; font-size: 14px;">
                    <strong>📧 Что дальше?</strong><br>
                    Мы свяжемся с вами по указанному email или телефону, как только товар поступит на склад. 
                    Вы сможете завершить оформление заказа и выбрать удобный способ доставки.
                </p>
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>Это автоматическое подтверждение о принятии вашего предзаказа.</p>
            @if(isset($siteInfo['main_site']) && $siteInfo['main_site'])
                <a href="{{ $siteInfo['main_site'] }}">Перейти на сайт</a>
            @endif
            <p style="margin-top: 15px;">© {{ date('Y') }} {{ $siteInfo['site_name'] ?? 'Интернет-магазин' }}. Все права защищены.</p>
        </div>
    </div>
</body>
</html>

