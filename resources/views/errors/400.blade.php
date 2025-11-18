<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>400 - Неверный запрос</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            color: #333;
        }

        .error-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 600px;
            width: 100%;
            padding: 60px 40px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .error-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
        }

        .logo-container {
            margin-bottom: 30px;
        }

        .logo-container img {
            max-width: 200px;
            max-height: 80px;
            height: auto;
            object-fit: contain;
        }

        .error-code {
            font-size: 120px;
            font-weight: 900;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            line-height: 1;
            margin-bottom: 20px;
        }

        .error-title {
            font-size: 32px;
            font-weight: 700;
            color: #1a1a1a;
            margin-bottom: 15px;
        }

        .error-description {
            font-size: 18px;
            color: #666;
            line-height: 1.6;
            margin-bottom: 40px;
        }

        .error-icon {
            width: 120px;
            height: 120px;
            margin: 0 auto 30px;
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a6f 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: pulse 2s ease-in-out infinite;
        }

        .error-icon svg {
            width: 60px;
            height: 60px;
            fill: white;
        }

        @keyframes pulse {
            0%, 100% {
                transform: scale(1);
                box-shadow: 0 0 0 0 rgba(255, 107, 107, 0.7);
            }
            50% {
                transform: scale(1.05);
                box-shadow: 0 0 0 20px rgba(255, 107, 107, 0);
            }
        }

        .actions {
            display: flex;
            gap: 15px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 14px 28px;
            font-size: 16px;
            font-weight: 600;
            text-decoration: none;
            border-radius: 10px;
            transition: all 0.3s ease;
            cursor: pointer;
            border: none;
            font-family: inherit;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.6);
        }

        .btn-secondary {
            background: #f5f5f5;
            color: #333;
            border: 2px solid #e0e0e0;
        }

        .btn-secondary:hover {
            background: #e8e8e8;
            border-color: #d0d0d0;
            transform: translateY(-2px);
        }

        .btn svg {
            width: 20px;
            height: 20px;
        }

        @media (max-width: 640px) {
            .error-container {
                padding: 40px 20px;
            }

            .error-code {
                font-size: 80px;
            }

            .error-title {
                font-size: 24px;
            }

            .error-description {
                font-size: 16px;
            }

            .actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="error-container">
        @if($siteLogo)
        <div class="logo-container">
            <img src="{{ $siteLogo }}" alt="Логотип сайта" onerror="this.style.display='none'">
        </div>
        @endif

        <div class="error-icon">
            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
            </svg>
        </div>

        <div class="error-code">400</div>
        <h1 class="error-title">Неверный запрос</h1>
        <p class="error-description">
            Сервер не может обработать ваш запрос из-за некорректного синтаксиса. 
            Пожалуйста, проверьте правильность введенных данных и попробуйте снова.
        </p>

        <div class="actions">
            <button onclick="window.history.back()" class="btn btn-secondary">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M19 12H5M12 19l-7-7 7-7"/>
                </svg>
                Вернуться назад
            </button>
            @if($mainSiteUrl)
            <a href="{{ $mainSiteUrl }}" class="btn btn-primary">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                    <polyline points="9 22 9 12 15 12 15 22"/>
                </svg>
                На главную страницу
            </a>
            @endif
        </div>
    </div>
</body>
</html>

