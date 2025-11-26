<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ваши учетные данные</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f4f4f4;
        }
        .container {
            background-color: #ffffff;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        .logo {
            font-size: 24px;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 10px;
        }
        .credentials-container {
            background-color: #f8f9fa;
            border: 2px solid #28a745;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
        }
        .credential-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #dee2e6;
        }
        .credential-item:last-child {
            border-bottom: none;
        }
        .credential-label {
            font-weight: bold;
            color: #495057;
        }
        .credential-value {
            font-family: 'Courier New', monospace;
            background-color: #e9ecef;
            padding: 5px 10px;
            border-radius: 4px;
            color: #212529;
        }
        .message {
            text-align: center;
            margin: 20px 0;
        }
        .footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #dee2e6;
            color: #6c757d;
            font-size: 14px;
        }
        .success-badge {
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
            text-align: center;
        }
        .warning {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
        .discount-info {
            background-color: #e7f3ff;
            border: 1px solid #b8daff;
            color: #004085;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">{{ $siteInfo['site_name'] ?? 'Skate & Snow' }}</div>
            <p>Добро пожаловать в наш клуб!</p>
        </div>

        <div class="success-badge">
            <h2>🎉 Регистрация успешно завершена!</h2>
            <p>Ваша дисконтная карта уже активна!</p>
        </div>

        <div class="message">
            <h2>Здравствуйте, {{ $user->name }}!</h2>
            <p>Ваш аккаунт успешно создан. Сохраните эти данные для входа в личный кабинет:</p>
        </div>

        <div class="credentials-container">
            <h3 style="margin-top: 0; color: #28a745;">Ваши учетные данные:</h3>
            <div class="credential-item">
                <span class="credential-label">Имя:</span>
                <span class="credential-value">{{ $user->name }}</span>
            </div>
            <div class="credential-item">
                <span class="credential-label">Email (логин):</span>
                <span class="credential-value">{{ $user->email }}</span>
            </div>
            <div class="credential-item">
                <span class="credential-label">Пароль:</span>
                <span class="credential-value">{{ $password }}</span>
            </div>
        </div>

        <div class="discount-info">
            <h3 style="margin-top: 0;">💳 Ваша дисконтная карта</h3>
            <p><strong>Статус:</strong> Активна</p>
            <p><strong>Уровень:</strong> СЕРЕБРО</p>
            <p>Теперь вы можете накапливать бонусы за покупки и получать скидки!</p>
        </div>

        @if(isset($bonusAmount) && $bonusAmount > 0)
        <div style="background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%); border: 2px solid #28a745; border-radius: 10px; padding: 25px; margin: 25px 0; text-align: center; box-shadow: 0 4px 15px rgba(40, 167, 69, 0.2);">
            <div style="font-size: 48px; margin-bottom: 15px;">🎁</div>
            <h3 style="color: #155724; margin: 0 0 15px 0; font-size: 22px; font-weight: 600;">Приветственные бонусы начислены!</h3>
            <p style="color: #155724; margin: 0 0 10px 0; font-size: 18px; line-height: 1.5;">
                Вам начислено
            </p>
            <div style="background-color: #ffffff; border-radius: 8px; padding: 15px; margin: 15px 0; display: inline-block;">
                <div style="font-size: 32px; font-weight: bold; color: #28a745; margin: 0;">
                    {{ number_format($bonusAmount, 0, ',', ' ') }} бонусных баллов
                </div>
            </div>
            <p style="color: #155724; margin: 15px 0 0 0; font-size: 16px; line-height: 1.5;">
                <strong>Бонусы уже на вашем счету и готовы к использованию!</strong><br>
                Вы можете потратить их прямо сейчас при оформлении заказа.
            </p>
        </div>
        @endif

        <div class="warning">
            <strong>🔒 Важно:</strong> Сохраните эти данные в надежном месте. Для безопасности рекомендуем сменить пароль после первого входа.
        </div>

        <div class="message">
            <p>Теперь вы можете:</p>
            <ul style="text-align: left; max-width: 400px; margin: 0 auto;">
                <li>Входить в личный кабинет</li>
                <li>Отслеживать заказы</li>
                <li>Накапливать бонусы</li>
                <li>Получать персональные скидки</li>
                <li>Участвовать в акциях</li>
            </ul>
        </div>

        <div class="footer">
            <p>С уважением,<br>Команда {{ $siteInfo['site_name'] ?? 'Skate & Snow' }}</p>
            <p>Это письмо отправлено автоматически, пожалуйста, не отвечайте на него.</p>
        </div>
    </div>
</body>
</html>
