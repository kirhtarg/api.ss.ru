<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Новая ссылка для оплаты заказа №{{ $order->order_number }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            color: #333;
            line-height: 1.4;
            background-color: #f5f5f5;
        }
        .email-container {
            max-width: 800px;
            margin: 0 auto;
            background-color: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #333;
            padding-bottom: 20px;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: bold;
            color: #333;
        }
        .header h2 {
            margin: 10px 0 0 0;
            font-size: 18px;
            color: #666;
        }
        .warning-box {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
        }
        .warning-box h3 {
            margin: 0 0 10px 0;
            color: #856404;
            font-size: 18px;
        }
        .payment-link {
            background-color: #e8f5e8;
            border: 1px solid #c3e6cb;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
            text-align: center;
        }
        .payment-button {
            display: inline-block;
            background-color: #28a745;
            color: white;
            padding: 15px 30px;
            text-decoration: none;
            border-radius: 5px;
            font-weight: bold;
            margin: 10px 0;
        }
        .payment-button:hover {
            background-color: #218838;
        }
        .order-info {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }
        .order-info table {
            width: 100%;
            border-collapse: collapse;
        }
        .order-info td {
            padding: 8px 0;
            border-bottom: 1px solid #dee2e6;
        }
        .order-info td:first-child {
            font-weight: bold;
            width: 40%;
        }
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #dee2e6;
            color: #666;
            font-size: 14px;
            text-align: center;
        }
        .contact-info {
            background-color: #e9ecef;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="header">
            <h1>Новая ссылка для оплаты</h1>
            <h2>Заказ №{{ $order->order_number }}</h2>
        </div>

        <div class="warning-box">
            <h3>⚠️ Предыдущая ссылка на оплату устарела</h3>
            <p>Срок действия предыдущей платежной ссылки истек. Мы создали новую ссылку для оплаты вашего заказа.</p>
        </div>

        <div class="order-info">
            <h3>Информация о заказе:</h3>
            <table>
                <tr>
                    <td>Номер заказа:</td>
                    <td>{{ $order->order_number }}</td>
                </tr>
                <tr>
                    <td>Дата заказа:</td>
                    <td>{{ $order->created_at ? $order->created_at->format('d.m.Y H:i') : 'Не указана' }}</td>
                </tr>
                <tr>
                    <td>Сумма к оплате:</td>
                    <td><strong>{{ number_format($order->total_amount, 2, ',', ' ') }} ₽</strong></td>
                </tr>
                <tr>
                    <td>Покупатель:</td>
                    <td>{{ $order->customer_name }}</td>
                </tr>
                <tr>
                    <td>Email:</td>
                    <td>{{ $order->customer_email }}</td>
                </tr>
                <tr>
                    <td>Телефон:</td>
                    <td>{{ $order->customer_phone }}</td>
                </tr>
            </table>
        </div>

        <div class="payment-link">
            <h3>Ссылка для оплаты:</h3>
            <p>Нажмите на кнопку ниже для перехода к оплате:</p>
            <a href="{{ $payment_url }}" class="payment-button">
                💳 Оплатить заказ
            </a>
            <p><small>Если кнопка не работает, скопируйте и вставьте эту ссылку в браузер:</small></p>
            <p><small>{{ $payment_url }}</small></p>
        </div>

        <div class="warning-box">
            <p><strong>Важно:</strong> Новая ссылка действительна ограниченное время. Рекомендуем оплатить заказ в ближайшее время.</p>
        </div>

        @if($contacts)
        <div class="contact-info">
            <h4>Контакты для связи:</h4>
            @if($contacts->phone)
            <p>📞 Телефон: <strong>{{ $contacts->phone }}</strong></p>
            @endif
            @if($contacts->email)
            <p>📧 Email: <strong>{{ $contacts->email }}</strong></p>
            @endif
        </div>
        @endif

        <div class="footer">
            <p>Это автоматическое сообщение от {{ $siteInfo['site_name'] ?? 'Skate & Snow' }}</p>
            <p>Пожалуйста, не отвечайте на это письмо</p>
        </div>
    </div>
</body>
</html>