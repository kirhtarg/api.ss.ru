<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Добро пожаловать!</title>
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
        }
    </style>
</head>
<body>
    <div class="email-container">
        <!-- Header -->
        <div class="header">
            @if(isset($siteInfo['site_logo']) && $siteInfo['site_logo'])
                @php
                    $logoUrl = $siteInfo['site_logo'];
                    // Если это не полный URL, строим путь из main_site
                    if (!str_starts_with($logoUrl, 'http://') && !str_starts_with($logoUrl, 'https://')) {
                        $mainSite = rtrim($siteInfo['main_site'] ?? config('app.url'), '/');
                        $logoUrl = $mainSite . '/' . ltrim($logoUrl, '/');
                    }
                @endphp
                <img src="{{ $logoUrl }}" alt="Логотип" class="logo">
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
                Ваша регистрация успешно завершена, и ваш аккаунт активирован. Теперь вы можете пользоваться всеми возможностями нашего сайта.
            </p>
            
            @if(isset($bonusAmount) && $bonusAmount > 0)
            <div style="background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%); border: 2px solid #28a745; border-radius: 10px; padding: 25px; margin: 25px 0; text-align: center; box-shadow: 0 4px 15px rgba(40, 167, 69, 0.2);">
                <div style="font-size: 48px; margin-bottom: 15px;">🎁</div>
                <h3 style="color: #155724; margin: 0 0 15px 0; font-size: 22px; font-weight: 600;">Приветственный подарок!</h3>
                <p style="color: #155724; margin: 0 0 10px 0; font-size: 18px; line-height: 1.5;">
                    На ваш счет уже начислено
                </p>
                <div style="background-color: #ffffff; border-radius: 8px; padding: 15px; margin: 15px 0; display: inline-block;">
                    <div style="font-size: 32px; font-weight: bold; color: #28a745; margin: 0;">
                        {{ number_format($bonusAmount, 0, ',', ' ') }} баллов
                    </div>
                </div>
                <p style="color: #155724; margin: 15px 0 0 0; font-size: 16px; line-height: 1.5;">
                    Используйте их для получения скидок на покупки в нашем магазине!
                </p>
            </div>
            @endif
            
            <div class="divider"></div>
            
            <div style="background-color: #e7f3ff; border: 1px solid #b3d9ff; border-radius: 8px; padding: 20px; margin: 25px 0;">
                <h3 style="color: #004085; margin: 0 0 15px 0; font-size: 18px; font-weight: 600;">🔐 Ваши данные для входа:</h3>
                <p style="color: #004085; margin: 0 0 10px 0; font-size: 16px; line-height: 1.6;">
                    <strong>Email:</strong> {{ $user->email }}
                </p>
                <p style="color: #004085; margin: 0; font-size: 16px; line-height: 1.6;">
                    <strong>Пароль:</strong> Используйте пароль, который вы указали при регистрации
                </p>
                <p style="color: #004085; margin: 15px 0 0 0; font-size: 14px; line-height: 1.6;">
                    Для входа на сайт используйте ваш email и введенный при регистрации пароль.
                </p>
            </div>
            
            <p class="message">
                Если у вас возникнут вопросы, наша служба поддержки всегда готова помочь.
            </p>
            
            <p class="message">
                Желаем вам приятных покупок!
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

