<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Новое сообщение на сайте</title>
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
        .message-text {
            background-color: white;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
            font-size: 14px;
            line-height: 1.6;
            color: #333;
            white-space: pre-wrap;
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
                    <img src="{{ $siteInfo['site_logo'] }}" alt="Логотип {{ $siteInfo['site_name'] ?? 'Магазина' }}">
                @endif
            </div>
            <h1>{{ $siteInfo['site_name'] ?? 'Интернет-магазин' }}</h1>
            @if(isset($siteInfo['main_site']) && $siteInfo['main_site'])
                <p><a href="{{ $siteInfo['main_site'] }}" style="color: white; text-decoration: underline;">{{ $siteInfo['main_site'] }}</a></p>
            @endif
        </div>

        <!-- Основной контент -->
        <div class="content">
            <!-- Уведомление о новом сообщении -->
            <div class="alert-box">
                <h2>💬 Новое сообщение на сайте!</h2>
                <p>Поступило новое сообщение от посетителя сайта, требующее обработки.</p>
            </div>

            <!-- Информация о сообщении -->
            <div class="message-card">
                <h3>📋 Информация о сообщении</h3>
                
                <div class="info-row">
                    <span class="info-label">Дата отправки:</span>
                    <span class="info-value">{{ \Carbon\Carbon::parse($siteMessage->created_at)->locale('ru')->isoFormat('D MMMM YYYY, HH:mm') }}</span>
                </div>
                
                <div class="info-row">
                    <span class="info-label">Тип:</span>
                    <span class="info-value highlight">Сообщение на сайте</span>
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
                    <span class="info-label">Телефон:</span>
                    <span class="info-value highlight">{!! $siteMessagePhoneLink ?? $siteMessage->phone !!}</span>
                </div>
            </div>

            <!-- Текст сообщения -->
            @if($siteMessage->message)
            <div class="message-card">
                <h3>💭 Текст сообщения</h3>
                <div class="message-text">{{ $siteMessage->message }}</div>
            </div>
            @endif
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>Это автоматическое уведомление о новом сообщении на сайте.</p>
            @if(isset($siteInfo['main_site']) && $siteInfo['main_site'])
                <a href="{{ $siteInfo['main_site'] }}">Перейти на сайт</a>
            @endif
            <p style="margin-top: 15px;">© {{ date('Y') }} {{ $siteInfo['site_name'] ?? 'Интернет-магазин' }}. Все права защищены.</p>
        </div>
    </div>
</body>
</html>

