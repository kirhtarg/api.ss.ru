<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VK Авторизация</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
            background-color: #f5f5f5;
        }
        .container {
            text-align: center;
            padding: 20px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #3498db;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 2s linear infinite;
            margin: 0 auto 20px;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .error {
            color: #e74c3c;
        }
        .success {
            color: #27ae60;
        }
    </style>
</head>
<body>
    <div class="container">
        @if(isset($error))
            <div class="error">
                <h2>Ошибка авторизации</h2>
                <p>{{ $error }}</p>
                <button onclick="window.close()">Закрыть</button>
            </div>
        @elseif(isset($success) && $success)
            <div class="spinner"></div>
            <h2>Завершение авторизации...</h2>
            <p>Пожалуйста, подождите</p>
            
            <script>
                // Отправляем POST запрос с полученными данными
                fetch('{{ url("/api/auth/vk/sdk-callback") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({
                        code: '{{ $code }}',
                        device_id: '{{ $deviceId }}'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Успешная авторизация
                        if (window.opener) {
                            // Отправляем данные в родительское окно
                            window.opener.postMessage({
                                type: 'VK_AUTH_SUCCESS',
                                data: data
                            }, '*');
                            window.close();
                        } else {
                            // Если нет родительского окна, перенаправляем
                            window.location.href = '{{ config("app.frontend_url") }}/auth/vk/callback?token=' + data.data.token + '&user=' + btoa(JSON.stringify(data.data.user));
                        }
                    } else {
                        // Ошибка авторизации
                        if (window.opener) {
                            window.opener.postMessage({
                                type: 'VK_AUTH_ERROR',
                                error: data.message
                            }, '*');
                            window.close();
                        } else {
                            alert('Ошибка авторизации: ' + data.message);
                        }
                    }
                })
                .catch(error => {
                    console.error('VK Auth Error:', error);
                    if (window.opener) {
                        window.opener.postMessage({
                            type: 'VK_AUTH_ERROR',
                            error: 'Ошибка сети'
                        }, '*');
                        window.close();
                    } else {
                        alert('Ошибка сети');
                    }
                });
            </script>
        @endif
    </div>
</body>
</html>
