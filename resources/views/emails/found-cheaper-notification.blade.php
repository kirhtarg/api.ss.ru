<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Нашел дешевле</title>
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
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
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
            background-color: #dbeafe;
            border-left: 4px solid #3b82f6;
            padding: 15px 20px;
            margin-bottom: 25px;
            border-radius: 4px;
        }
        .alert-box h2 {
            margin: 0 0 10px 0;
            font-size: 20px;
            color: #1e40af;
        }
        .alert-box p {
            margin: 5px 0;
            color: #1e40af;
            font-size: 14px;
        }
        .message-card {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .message-card h3 {
            margin: 0 0 15px 0;
            font-size: 18px;
            color: #333;
            border-bottom: 2px solid #3b82f6;
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
            color: #3b82f6;
            font-weight: 600;
        }
        .good-link-box {
            background-color: #eff6ff;
            border: 2px solid #3b82f6;
            border-radius: 8px;
            padding: 15px;
            margin: 15px 0;
            text-align: center;
        }
        .good-link-label {
            font-size: 12px;
            color: #1e40af;
            font-weight: 600;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .good-link {
            font-size: 14px;
            color: #3b82f6;
            word-break: break-all;
            text-decoration: none;
        }
        .good-link:hover {
            text-decoration: underline;
        }
        .price-box {
            background-color: #fef3c7;
            border: 2px solid #f59e0b;
            border-radius: 8px;
            padding: 15px;
            margin: 15px 0;
            text-align: center;
        }
        .price-label {
            font-size: 12px;
            color: #92400e;
            font-weight: 600;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .price-value {
            font-size: 24px;
            font-weight: 700;
            color: #92400e;
        }
        .contact-box {
            background-color: #f0f9ff;
            border: 2px solid #0ea5e9;
            border-radius: 8px;
            padding: 15px;
            margin: 15px 0;
            text-align: center;
        }
        .contact-label {
            font-size: 12px;
            color: #0c4a6e;
            font-weight: 600;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .contact-value {
            font-size: 16px;
            font-weight: 600;
            color: #0c4a6e;
        }
        .footer {
            background-color: #f8f9fa;
            padding: 20px;
            text-align: center;
            border-top: 1px solid #e0e0e0;
            font-size: 12px;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="email-wrapper">
        <!-- Header -->
        <div class="header">
            @if(isset($siteInfo) && isset($siteInfo['logo_url']) && $siteInfo['logo_url'])
                <div class="logo-container">
                    <img src="{{ $siteInfo['logo_url'] }}" alt="{{ $siteInfo['site_name'] ?? 'Логотип' }}">
                </div>
            @endif
            <h1>💰 Нашел дешевле</h1>
            <p>Новое сообщение от клиента</p>
        </div>

        <!-- Content -->
        <div class="content">
            <!-- Alert Box -->
            <div class="alert-box">
                <h2>⚠️ Требуется рассмотрение</h2>
                <p>Клиент сообщил о более выгодной цене на аналогичный товар. Необходимо проверить предложение и принять решение.</p>
            </div>

            <!-- Информация о запросе -->
            <div class="message-card">
                <h3>📋 Информация о запросе</h3>
                
                <div class="info-row">
                    <span class="info-label">Дата запроса:</span>
                    <span class="info-value">{{ \Carbon\Carbon::parse($siteMessage->created_at)->locale('ru')->isoFormat('D MMMM YYYY, HH:mm') }}</span>
                </div>
                
                <div class="info-row">
                    <span class="info-label">Тип:</span>
                    <span class="info-value highlight">Нашел дешевле</span>
                </div>
            </div>

            <!-- Информация о клиенте -->
            <div class="message-card">
                <h3>👤 Информация о клиенте</h3>
                
                <div class="info-row">
                    <span class="info-label">Имя:</span>
                    <span class="info-value">{{ $siteMessage->name }}</span>
                </div>
                
                <div class="info-row">
                    <span class="info-label">Email:</span>
                    <span class="info-value">{{ $siteMessage->email }}</span>
                </div>
                
                @if($siteMessage->phone)
                <div class="info-row">
                    <span class="info-label">Телефон:</span>
                    <span class="info-value">{!! $siteMessagePhoneLink ?? $siteMessage->phone !!}</span>
                </div>
                @endif
                
                @if($siteMessage->message)
                <div class="info-row">
                    <span class="info-label">Комментарий:</span>
                    <span class="info-value">{{ $siteMessage->message }}</span>
                </div>
                @endif
            </div>

            <!-- Информация о товаре со страницы -->
            @if($siteMessage->good && $siteMessage->good->name)
            <div class="message-card" style="background-color: #f0fdf4; border: 2px solid #22c55e;">
                <h3 style="border-bottom: 2px solid #22c55e;">🛍️ Товар со страницы</h3>
                
                <div class="info-row">
                    <span class="info-label">Название:</span>
                    <span class="info-value" style="color: #166534; font-weight: 600;">{{ $siteMessage->good->name }}</span>
                </div>
                
                @if($siteMessage->good->slug)
                @php
                    $catalogPathSetting = \App\Models\Setting::where('key', 'shop_catalog_path')->first();
                    $catalogPath = $catalogPathSetting && $catalogPathSetting->value 
                        ? '/' . ltrim($catalogPathSetting->value, '/') 
                        : '/catalog';
                    $baseUrl = config('app.frontend_url', config('app.url', 'https://skateandsnow.ru'));
                    $goodUrl = $baseUrl . $catalogPath . '/' . $siteMessage->good->slug;
                @endphp
                <div class="info-row">
                    <span class="info-label">Ссылка:</span>
                    <span class="info-value">
                        <a href="{{ $goodUrl }}" target="_blank" style="color: #22c55e; text-decoration: none; font-weight: 600;">Открыть товар →</a>
                    </span>
                </div>
                @endif
            </div>
            @endif

            <!-- Ссылка на товар -->
            @if($siteMessage->good_link)
            <div class="good-link-box">
                <div class="good-link-label">Ссылка на аналогичный товар</div>
                <a href="{{ $siteMessage->good_link }}" target="_blank" class="good-link">{{ $siteMessage->good_link }}</a>
            </div>
            @endif

            <!-- Указанная цена -->
            @if($siteMessage->good_price)
            <div class="price-box">
                <div class="price-label">Указанная цена</div>
                <div class="price-value">{{ number_format($siteMessage->good_price, 2, ',', ' ') }} ₽</div>
            </div>
            @endif
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>Это автоматическое уведомление от системы управления сайтом.</p>
            @if(isset($siteInfo) && isset($siteInfo['site_name']))
                <p>&copy; {{ date('Y') }} {{ $siteInfo['site_name'] }}. Все права защищены.</p>
            @endif
        </div>
    </div>
</body>
</html>

