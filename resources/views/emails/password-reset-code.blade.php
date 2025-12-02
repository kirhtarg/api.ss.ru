<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Код для восстановления пароля</title>
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
        .code-container {
            background-color: #e3f2fd;
            border: 2px dashed #2196f3;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            margin: 20px 0;
        }
        .verification-code {
            font-size: 36px;
            font-weight: bold;
            color: #1976d2;
            letter-spacing: 8px;
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
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
        .info {
            background-color: #e8f4fd;
            border: 1px solid #bee5eb;
            color: #0c5460;
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
            <p>Восстановление пароля</p>
        </div>

        <div class="message">
            <h2>Здравствуйте, {{ $user->name }}!</h2>
            <p>Вы запросили сброс пароля для вашей учётной записи. Введите следующий код для подтверждения:</p>
        </div>

        <div class="code-container">
            <p><strong>Ваш код восстановления:</strong></p>
            <div class="verification-code">{{ $code }}</div>
        </div>

        <div class="warning">
            <strong>⚠️ Важно:</strong> Этот код действителен в течение 15 минут. Не передавайте его третьим лицам.
        </div>

        <div class="info">
            <strong>ℹ️ Совет:</strong> После ввода кода вам будет предложено создать новый надёжный пароль. Убедитесь, что он содержит:
            <ul style="margin: 10px 0 0 0;">
                <li>Минимум 8 символов</li>
                <li>Заглавные и строчные буквы</li>
                <li>Цифры</li>
                <li>Специальные символы (!@#$%^&*)</li>
            </ul>
        </div>

        <div class="message">
            <p>Если вы не запрашивали сброс пароля, просто проигнорируйте это письмо. Ваш пароль останется неизменным.</p>
        </div>

        <div class="footer">
            <p>С уважением,<br>Команда {{ $siteInfo['site_name'] ?? 'Skate & Snow' }}</p>
            <p>Это письмо отправлено автоматически, пожалуйста, не отвечайте на него.</p>
        </div>
    </div>
</body>
</html>







