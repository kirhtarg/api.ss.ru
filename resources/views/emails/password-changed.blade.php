<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Пароль изменён</title>
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
        .success-container {
            background-color: #d4edda;
            border: 2px solid #28a745;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            margin: 20px 0;
        }
        .success-icon {
            font-size: 48px;
            margin: 10px 0;
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
        .warning {
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
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
            <p>Уведомление о безопасности</p>
        </div>

        <div class="message">
            <h2>Здравствуйте, {{ $user->name }}!</h2>
        </div>

        <div class="success-container">
            <div class="success-icon">✅</div>
            <h3 style="color: #155724; margin: 0;">Пароль успешно изменён</h3>
            <p style="color: #155724; margin: 10px 0 0 0;">
                Ваш пароль был успешно обновлён {{ now()->format('d.m.Y в H:i') }}
            </p>
        </div>

        <div class="warning">
            <strong>⚠️ Не вы изменяли пароль?</strong><br>
            Если вы не запрашивали изменение пароля, ваш аккаунт мог быть скомпрометирован. 
            Пожалуйста, немедленно свяжитесь с нашей службой поддержки.
        </div>

        <div class="message">
            <p>Теперь вы можете войти в свой аккаунт с новым паролем.</p>
        </div>

        <div class="footer">
            <p>С уважением,<br>Команда {{ $siteInfo['site_name'] ?? 'Skate & Snow' }}</p>
            <p>Это письмо отправлено автоматически, пожалуйста, не отвечайте на него.</p>
        </div>
    </div>
</body>
</html>








