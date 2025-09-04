<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Подтверждение регистрации</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f8f9fa;
            line-height: 1.6;
        }
        
        /* Динамические стили на основе site_color1 и site_color2 */
        @php
            $color1 = $siteInfo['site_color1'] ?? '#1a1a1a';
            $color2 = $siteInfo['site_color2'] ?? '#b8860b';
            $gradient = "linear-gradient(135deg, {$color1} 0%, {$color2} 100%)";
        @endphp
        
        .email-container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }
        
        .header {
            background: {{ $gradient }};
            padding: 40px 30px;
            text-align: center;
            color: white;
        }
        
        .logo {
            max-width: 120px;
            height: auto;
            margin-bottom: 20px;
            border-radius: 8px;
        }
        
        .site-name {
            font-size: 28px;
            font-weight: bold;
            margin: 0 0 10px 0;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }
        
        .site-description {
            font-size: 16px;
            opacity: 0.9;
            margin: 0;
        }
        
        .content {
            padding: 40px 30px;
        }
        
        .greeting {
            font-size: 24px;
            color: #333;
            margin: 0 0 20px 0;
            font-weight: 600;
        }
        
        .message {
            font-size: 16px;
            color: #555;
            margin: 0 0 30px 0;
        }
        
        .verification-button {
            display: inline-block;
            background: {{ $gradient }};
            color: white;
            text-decoration: none;
            padding: 16px 32px;
            border-radius: 8px;
            font-size: 18px;
            font-weight: 600;
            text-align: center;
            margin: 20px 0;
            box-shadow: 0 4px 15px rgba(184, 134, 11, 0.3);
            transition: all 0.3s ease;
        }
        
        .verification-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(184, 134, 11, 0.4);
        }
        
        .verification-link {
            word-break: break-all;
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 6px;
            border-left: 4px solid #b8860b;
            font-family: monospace;
            font-size: 14px;
            color: #666;
            margin: 20px 0;
        }
        
        .warning {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 6px;
            padding: 15px;
            margin: 20px 0;
            color: #856404;
        }
        
        .footer {
            background-color: #f8f9fa;
            padding: 30px;
            text-align: center;
            border-top: 1px solid #e9ecef;
        }
        
        .footer-text {
            color: #666;
            font-size: 14px;
            margin: 0 0 10px 0;
        }
        
        .footer-link {
            color: #b8860b;
            text-decoration: none;
        }
        
        .footer-link:hover {
            text-decoration: underline;
        }
        
        .divider {
            height: 1px;
            background: {{ $gradient }};
            margin: 30px 0;
        }
        
        @media (max-width: 600px) {
            .email-container {
                margin: 0;
                box-shadow: none;
            }
            
            .header, .content, .footer {
                padding: 20px;
            }
            
            .site-name {
                font-size: 24px;
            }
            
            .greeting {
                font-size: 20px;
            }
            
            .verification-button {
                display: block;
                width: 100%;
                box-sizing: border-box;
            }
        }
    </style>
</head>
<body>
    <div class="email-container">
        <!-- Header -->
        <div class="header">
            @if(isset($siteInfo['site_logo']) && $siteInfo['site_logo'])
                <img src="{{ config('app.url') }}/storage/{{ $siteInfo['site_logo'] }}" alt="Логотип" class="logo">
            @endif
            
            <h1 class="site-name">
                {{ $siteInfo['site_name'] ?? config('app.name') }}
            </h1>
            
            @if(isset($siteInfo['site_description']) && $siteInfo['site_description'])
                <p class="site-description">{{ $siteInfo['site_description'] }}</p>
            @endif
        </div>
        
        <!-- Content -->
        <div class="content">
            <h2 class="greeting">Здравствуйте, {{ $user->name }}!</h2>
            
            <p class="message">
                Спасибо за регистрацию на нашем сайте! Мы рады приветствовать вас в нашем сообществе.
            </p>
            
            <p class="message">
                Для завершения регистрации и активации вашего аккаунта, пожалуйста, подтвердите ваш email адрес, нажав на кнопку ниже:
            </p>
            
            <div style="text-align: center;">
                <a href="{{ $verificationUrl }}" class="verification-button">
                    Подтвердить Email
                </a>
            </div>
            
            <div class="divider"></div>
            
            <p class="message">
                Если кнопка не работает, скопируйте и вставьте следующую ссылку в адресную строку браузера:
            </p>
            
            <div class="verification-link">
                {{ $verificationUrl }}
            </div>
            
            <div class="warning">
                <strong>Важно:</strong> Ссылка действительна в течение 24 часов. Если вы не подтвердите email в течение этого времени, вам потребуется зарегистрироваться заново.
            </div>
            
            <p class="message">
                Если вы не регистрировались на нашем сайте, просто проигнорируйте это письмо.
            </p>
        </div>
        
        <!-- Footer -->
        <div class="footer">
            <p class="footer-text">
                С уважением,<br>
                Команда {{ $siteInfo['site_name'] ?? config('app.name') }}
            </p>
            
            @if(isset($siteInfo['main_site']) && $siteInfo['main_site'])
                <p class="footer-text">
                    <a href="{{ $siteInfo['main_site'] }}" class="footer-link">
                        {{ $siteInfo['main_site'] }}
                    </a>
                </p>
            @endif
            
            <p class="footer-text" style="font-size: 12px; color: #999;">
                Это автоматическое письмо, не отвечайте на него.
            </p>
        </div>
    </div>
</body>
</html>
