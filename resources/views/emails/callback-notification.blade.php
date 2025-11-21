<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Запрос обратного звонка</title>
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
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px 20px;
            margin-bottom: 25px;
            border-radius: 4px;
        }
        .alert-box h2 {
            margin: 0 0 10px 0;
            font-size: 20px;
            color: #856404;
        }
        .alert-box p {
            margin: 5px 0;
            color: #856404;
            font-size: 14px;
        }
        .callback-card {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .callback-card h3 {
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
        .phone-box {
            background-color: #e8f5e9;
            border: 2px solid #4caf50;
            border-radius: 8px;
            padding: 20px;
            margin-top: 20px;
            text-align: center;
        }
        .phone-label {
            font-size: 14px;
            color: #2e7d32;
            margin-bottom: 10px;
        }
        .phone-number {
            font-size: 32px;
            font-weight: 700;
            color: #1b5e20;
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
            .phone-number {
                font-size: 24px;
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
            <!-- Уведомление о запросе обратного звонка -->
            <div class="alert-box">
                <h2>📞 Запрос обратного звонка!</h2>
                <p>Посетитель сайта оставил заявку на обратный звонок. Пожалуйста, свяжитесь с клиентом в ближайшее время.</p>
            </div>

            <!-- Информация о запросе -->
            <div class="callback-card">
                <h3>📋 Информация о запросе</h3>
                
                <div class="info-row">
                    <span class="info-label">Дата запроса:</span>
                    <span class="info-value">{{ \Carbon\Carbon::parse($siteMessage->created_at)->locale('ru')->isoFormat('D MMMM YYYY, HH:mm') }}</span>
                </div>
                
                <div class="info-row">
                    <span class="info-label">Тип:</span>
                    <span class="info-value highlight">Обратный звонок</span>
                </div>
            </div>

            <!-- Информация о клиенте -->
            <div class="callback-card">
                <h3>👤 Информация о клиенте</h3>
                
                <div class="info-row">
                    <span class="info-label">Имя:</span>
                    <span class="info-value">{{ $siteMessage->name }}</span>
                </div>
                
                @if($siteMessage->message)
                <div class="info-row">
                    <span class="info-label">Комментарий:</span>
                    <span class="info-value">{{ $siteMessage->message }}</span>
                </div>
                @endif
            </div>

            <!-- Телефон для звонка -->
            <div class="phone-box">
                <div class="phone-label">Телефон для звонка:</div>
                <div class="phone-number">{!! $siteMessagePhoneLink ?? $siteMessage->phone !!}</div>
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>Это автоматическое уведомление о запросе обратного звонка.</p>
            @if(isset($siteInfo['main_site']) && $siteInfo['main_site'])
                <a href="{{ $siteInfo['main_site'] }}">Перейти на сайт</a>
            @endif
            <p style="margin-top: 15px;">© {{ date('Y') }} {{ $siteInfo['site_name'] ?? 'Интернет-магазин' }}. Все права защищены.</p>
        </div>
    </div>
</body>
</html>

