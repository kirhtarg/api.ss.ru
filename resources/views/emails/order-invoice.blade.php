<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Накладная №{{ $order->order_number }}</title>
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
        .company-info {
            margin-bottom: 30px;
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 5px;
        }
        .company-info h3 {
            margin: 0 0 15px 0;
            font-size: 16px;
            color: #333;
        }
        .company-info p {
            margin: 8px 0;
            font-size: 14px;
        }
        .order-info {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
            gap: 20px;
        }
        .order-details, .customer-info {
            flex: 1;
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
        }
        .order-details h3, .customer-info h3 {
            margin: 0 0 10px 0;
            font-size: 16px;
            color: #333;
        }
        .order-details p, .customer-info p {
            margin: 5px 0;
            font-size: 14px;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
            background-color: white;
        }
        .items-table th,
        .items-table td {
            border: 1px solid #ddd;
            padding: 12px 8px;
            text-align: left;
            font-size: 14px;
        }
        .items-table th {
            background-color: #f5f5f5;
            font-weight: bold;
            color: #333;
        }
        .items-table .text-right {
            text-align: right;
        }
        .items-table .text-center {
            text-align: center;
        }
        .totals {
            margin-top: 20px;
        }
        .totals table {
            width: 100%;
            border-collapse: collapse;
        }
        .totals td {
            padding: 8px 15px;
            border: none;
            font-size: 14px;
        }
        .totals .label {
            font-weight: bold;
            color: #333;
        }
        .totals .amount {
            text-align: right;
            font-weight: bold;
        }
        .totals .total-row {
            border-top: 2px solid #333;
            font-size: 16px;
            font-weight: bold;
            background-color: #f8f9fa;
        }
        .discounts {
            margin-top: 20px;
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
        }
        .discounts h4 {
            margin: 0 0 10px 0;
            font-size: 16px;
            color: #333;
        }
        .discounts table {
            width: 100%;
            border-collapse: collapse;
        }
        .discounts td {
            padding: 5px 10px;
            border: none;
            font-size: 13px;
        }
        .discounts .label {
            color: #666;
        }
        .discounts .amount {
            text-align: right;
            color: #e53e3e;
            font-weight: bold;
        }
        .footer {
            margin-top: 40px;
            text-align: center;
            font-size: 12px;
            color: #666;
            border-top: 1px solid #ddd;
            padding-top: 20px;
        }
        .cta-button {
            display: inline-block;
            background-color: #007bff;
            color: white;
            padding: 12px 24px;
            text-decoration: none;
            border-radius: 5px;
            font-weight: bold;
            margin: 20px 0;
        }
        .cta-button:hover {
            background-color: #0056b3;
        }
        @media (max-width: 600px) {
            .order-info {
                flex-direction: column;
            }
            .email-container {
                padding: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="email-container">
        <!-- Логотип и название магазина -->
        <div class="store-header" style="text-align: center; margin-bottom: 30px; padding: 20px; background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); border-radius: 8px;">
            @if(isset($siteInfo['site_logo']) && $siteInfo['site_logo'])
                @php
                    $logoUrl = $siteInfo['site_logo'];
                    // Если это не полный URL, строим путь из main_site
                    if (!str_starts_with($logoUrl, 'http://') && !str_starts_with($logoUrl, 'https://')) {
                        $mainSite = rtrim($siteInfo['main_site'] ?? config('app.url'), '/');
                        $logoUrl = $mainSite . '/' . ltrim($logoUrl, '/');
                    }
                @endphp
                <img src="{{ $logoUrl }}" alt="Логотип" style="max-height: 60px; margin-bottom: 15px;">
            @endif
            <h1 style="margin: 0; font-size: 28px; color: #333; font-weight: bold;">{{ $siteInfo['site_name'] ?? 'Интернет-магазин' }}</h1>
            @if($siteInfo['site_description'])
                <p style="margin: 10px 0 0 0; font-size: 16px; color: #666; font-style: italic;">{{ $siteInfo['site_description'] }}</p>
            @endif
        </div>

        <!-- Вводный текст с благодарностью -->
        <div class="intro-text" style="background-color: #e8f5e8; padding: 20px; border-radius: 8px; margin-bottom: 30px; border-left: 4px solid #28a745;">
            <h2 style="margin: 0 0 15px 0; color: #155724; font-size: 20px;">
                Здравствуйте{{ $order->customer_name ? ', ' . $order->customer_name : '' }}! 👋
            </h2>
            <p style="margin: 0 0 15px 0; color: #155724; font-size: 16px; line-height: 1.5;">
                Спасибо за ваш заказ! Мы получили его и в ближайшее время свяжемся с вами для подтверждения деталей. 
                Ниже представлена подробная информация о вашем заказе.
            </p>
            @php
                $twoStagePay = \App\Models\Setting::where('key', 'two_stage_pay')->first();
                $isTwoStagePay = $twoStagePay && ($twoStagePay->value === '1' || $twoStagePay->value === true);
                $isTwoStagePaymentMethod = false;
                $isTransfer = false;
                
                if (isset($order->payment_method_id)) {
                    $paymentMethod = \App\Models\ShopPaymentMethod::find($order->payment_method_id);
                    if ($paymentMethod) {
                        $isTwoStagePaymentMethod = in_array($paymentMethod->type, ['transfer', 'yandex_pay', 'yandex_split', 'yookassa']);
                        $isTransfer = $paymentMethod->type === 'transfer';
                    }
                } elseif ($order->payment_method === 'Банковский перевод') {
                    $isTwoStagePaymentMethod = true;
                    $isTransfer = true;
                }
            @endphp
            
            @if($isTwoStagePay && $isTwoStagePaymentMethod && (!$order->pay_agree || $order->pay_agree == 0))
                <div style="background-color: #fff3cd; padding: 15px; border-radius: 5px; margin: 15px 0; border-left: 4px solid #ffc107;">
                    <p style="margin: 0 0 10px 0; color: #856404; font-size: 16px; font-weight: bold;">
                        ⏳ Ожидание одобрения менеджера
                    </p>
                    <p style="margin: 0; color: #856404; font-size: 14px; line-height: 1.5;">
                        Менеджер проверит наличие товаров в заказе. После одобрения заказа вы получите уведомление и сможете произвести оплату в личном кабинете или по ссылке, приложенной к письму.
                    </p>
                </div>
            @elseif($isTransfer && (!$isTwoStagePay || ($isTwoStagePay && $order->pay_agree)))
                <div style="background-color: #fff3cd; padding: 15px; border-radius: 5px; margin: 15px 0; border-left: 4px solid #ffc107;">
                    <p style="margin: 0 0 10px 0; color: #856404; font-size: 16px; font-weight: bold;">
                        💳 Счет на оплату
                    </p>
                    <p style="margin: 0; color: #856404; font-size: 14px; line-height: 1.5;">
                        @if($isTwoStagePay && $order->pay_agree)
                            К данному письму прикреплен счет на оплату в формате PDF с банковскими реквизитами и суммой к оплате. 
                            Пожалуйста, произведите оплату по указанным реквизитам.
                        @else
                            К данному письму прикреплен счет на оплату в формате PDF с банковскими реквизитами и суммой к оплате. 
                            Пожалуйста, произведите оплату по указанным реквизитам.
                        @endif
                    </p>
                </div>
            @endif
            <p style="margin: 15px 0 0 0; color: #155724; font-size: 14px;">
                Посетите наш <a href="{{ $siteInfo['main_site'] ?? config('app.url') }}" style="color: #28a745; text-decoration: none; font-weight: bold;">интернет-магазин</a> 
                для просмотра новых товаров и акций!
            </p>
        </div>

        <div class="header">
            <h1>ИНФОРМАЦИЯ О ЗАКАЗЕ</h1>
            <h2>№ {{ $order->order_number }}</h2>
        </div>

        <div class="company-info">
            <h3>Реквизиты магазина:</h3>
            @if($contacts)
                <p><strong>Юридическое лицо:</strong> {{ $contacts['legal_name'] ?? 'Не указано' }}</p>
                <p><strong>Юридический адрес:</strong> {{ $contacts['legal_address'] ?? 'Не указано' }}</p>
                @if(!empty($contacts['inn']))
                    <p><strong>ИНН:</strong> {{ $contacts['inn'] }}</p>
                @endif
                @if(!empty($contacts['ogrn']))
                    <p><strong>ОГРН:</strong> {{ $contacts['ogrn'] }}</p>
                @endif
            @else
                <p>Реквизиты не найдены</p>
            @endif
        </div>

        <div class="order-info">
            <div class="order-details">
                <h3>Информация о заказе:</h3>
                <p><strong>Дата заказа:</strong> {{ \Carbon\Carbon::parse($order->created_at)->locale('ru')->isoFormat('D MMMM YYYY, HH:mm') }}</p>
                <p><strong>Статус:</strong> {{ $order->status->display_name ?? $order->status->name ?? 'Неизвестно' }}</p>
                <p><strong>Способ доставки:</strong> {{ $order->shipping_method ?? 'Не указано' }}</p>
                <p><strong>Способ оплаты:</strong> {{ $order->payment_method ?? 'Не указано' }}</p>
                @if($order->tracking_number)
                    <p><strong>Трек-номер:</strong> {{ $order->tracking_number }}</p>
                @endif
</div>

            <div class="customer-info">
                <h3>Информация о покупателе:</h3>
                <p><strong>Имя:</strong> {{ $order->customer_name ?? 'Не указано' }}</p>
                <p><strong>Email:</strong> {{ $order->customer_email ?? 'Не указано' }}</p>
                <p><strong>Телефон:</strong> {{ $order->customer_phone ?? 'Не указано' }}</p>
                @if($order->shipping_address)
                    <p><strong>Адрес доставки:</strong> {{ $order->shipping_address }}</p>
                @endif
            </div>
        </div>

        <table class="items-table">
            <thead>
                <tr>
                    <th>№</th>
                    <th>Наименование товара</th>
                    <th class="text-center">Кол-во</th>
                    <th class="text-right">Цена</th>
                    <th class="text-right">Сумма</th>
                </tr>
            </thead>
            <tbody>
                @php
                    $orderItems = method_exists($order, 'getItemsWithDetails') ? $order->getItemsWithDetails() : ($order->items ?? []);
                @endphp
                @if($orderItems && count($orderItems) > 0)
                    @foreach($orderItems as $index => $item)
                        @php
                            $quantity = (float) ($item['quantity'] ?? 1);
                            $itemPrice = (float) ($item['final_price'] ?? $item['unit_price'] ?? $item['price'] ?? 0);
                            $itemTotal = (float) ($item['total'] ?? ($itemPrice * $quantity));
                        @endphp
                        <tr>
                            <td class="text-center">{{ $index + 1 }}</td>
                            <td>
                                @php
                                    // Пробуем разные варианты названия товара
                                    $itemName = 'Товар';
                                    if (isset($item['good_name']) && $item['good_name']) {
                                        $itemName = $item['good_name'];
                                    } elseif (isset($item['name']) && $item['name']) {
                                        $itemName = $item['name'];
                                    } elseif (isset($item['title']) && $item['title']) {
                                        $itemName = $item['title'];
                                    } elseif (isset($item['product_name']) && $item['product_name']) {
                                        $itemName = $item['product_name'];
                                    }
                                    
                                    $variationName = '';
                                    if (isset($item['variation_name']) && $item['variation_name'] && $item['variation_name'] !== $itemName) {
                                        $variationName = $item['variation_name'];
                                    }
                                    $sku = $item['variation_sku'] ?? $item['good_sku'] ?? $item['sku'] ?? '';
                                @endphp
                                <div>{{ $itemName }}</div>
                                @if(!empty($variationName))
                                    <div style="font-size: 11px; color: #666; margin-top: 2px;">Вариация: {{ $variationName }}</div>
                                @endif
                                @if(!empty($sku))
                                    <div style="font-size: 11px; color: #666; margin-top: 2px;">Арт: {{ $sku }}</div>
                                @endif
                            </td>
                            <td class="text-center">{{ $quantity }}</td>
                            <td class="text-right">{{ number_format($itemPrice, 0, ',', ' ') }} ₽</td>
                            <td class="text-right">{{ number_format($itemTotal, 0, ',', ' ') }} ₽</td>
                        </tr>
                    @endforeach
                @else
                    <tr>
                        <td colspan="5" class="text-center">Товары не найдены</td>
                    </tr>
                @endif
            </tbody>
        </table>

        <div class="totals">
            <table>
                <tr>
                    <td class="label">Стоимость товаров:</td>
                    <td class="amount">{{ number_format(($order->subtotal ?? 0) + ($order->sale_discount_amount ?? 0), 0, ',', ' ') }} ₽</td>
                </tr>
                @if($order->delivery_cost && $order->delivery_cost > 0)
                    <tr>
                        <td class="label">Доставка:</td>
                        <td class="amount">{{ number_format($order->delivery_cost, 0, ',', ' ') }} ₽</td>
                    </tr>
                @endif
            </table>
        </div>

        @php
            $hasDiscounts = ($order->discount_amount && $order->discount_amount > 0) ||
                           ($order->sale_discount_amount && $order->sale_discount_amount > 0) ||
                           ($order->registered_user_discount_amount && $order->registered_user_discount_amount > 0) ||
                           ($order->promo_code_discount_amount && $order->promo_code_discount_amount > 0) ||
                           ($order->bonus_points_to_use && $order->bonus_points_to_use > 0);
        @endphp

        @if($order->has_certificate && $order->certificate_code)
            <div class="discounts" style="border-left-color: #dc2626;">
                <h4 style="color: #b91c1c;">Сертификат:</h4>
                <table>
                    <tr>
                        <td class="label">Код сертификата:</td>
                        <td class="amount" style="color: #dc2626;">{{ $order->certificate_code }}</td>
                    </tr>
                </table>
                <p style="margin: 12px 0 0; color: #b91c1c; font-weight: 600;">Мы свяжемся с Вами по поводу индивидуальных условий.</p>
            </div>
        @endif

        @if($hasDiscounts)
            <div class="discounts">
                <h4>Применённые скидки:</h4>
                <table>
                    @if($order->sale_discount_amount && $order->sale_discount_amount > 0)
                        <tr>
                            <td class="label">Акционные цены:</td>
                            <td class="amount">-{{ number_format($order->sale_discount_amount, 0, ',', ' ') }} ₽</td>
                        </tr>
                    @endif
                    @if($order->registered_user_discount_amount && $order->registered_user_discount_amount > 0)
                        <tr>
                            <td class="label">Скидка для зарегистрированных:</td>
                            <td class="amount">-{{ number_format($order->registered_user_discount_amount, 0, ',', ' ') }} ₽</td>
                        </tr>
                    @endif
                    @if($order->promo_code_discount_amount && $order->promo_code_discount_amount > 0)
                        <tr>
                            <td class="label">Промокод{{ $order->promo_code ? ' "' . $order->promo_code . '"' : '' }}:</td>
                            <td class="amount">-{{ number_format($order->promo_code_discount_amount, 0, ',', ' ') }} ₽</td>
                        </tr>
                    @endif
                    @if($order->bonus_points_to_use && $order->bonus_points_to_use > 0)
                        <tr>
                            <td class="label">Списано бонусов ({{ $order->bonus_points_to_use }} баллов):</td>
                            <td class="amount">-{{ number_format($order->bonus_points_to_use, 0, ',', ' ') }} ₽</td>
                        </tr>
                    @endif
                </table>
            </div>
        @endif

        <div class="totals">
            <table>
                <tr class="total-row">
                    <td class="label">ИТОГО К ОПЛАТЕ:</td>
                    <td class="amount">{{ number_format($order->total_amount ?? 0, 0, ',', ' ') }} ₽</td>
                </tr>
            </table>
        </div>

        @if($order->order_bonus_points && $order->order_bonus_points > 0)
            <div class="totals">
                <table>
                    <tr>
                        <td class="label">Начислено бонусов:</td>
                        <td class="amount">+{{ $order->order_bonus_points }} баллов</td>
                    </tr>
                </table>
            </div>
        @endif

        @if($order->notes)
            <div style="margin-top: 30px; background-color: #f8f9fa; padding: 15px; border-radius: 5px;">
                <h4>Примечания:</h4>
                <p>{{ $order->notes }}</p>
            </div>
        @endif

        <div style="text-align: center; margin: 30px 0;">
            <a href="{{ $siteInfo['main_site'] ?? config('app.url') }}/profile/orders" class="cta-button">
                Посмотреть заказ в личном кабинете
            </a>
        </div>

        <div class="footer">
            <p>Накладная сформирована {{ \Carbon\Carbon::now()->locale('ru')->isoFormat('D MMMM YYYY') }} в {{ \Carbon\Carbon::now()->locale('ru')->isoFormat('HH:mm') }}</p>
            <p>Спасибо за покупку!</p>
            <p style="margin: 15px 0 0 0; text-align: center;">
                <a href="{{ $siteInfo['main_site'] ?? config('app.url') }}" 
                   style="display: inline-block; background-color: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; font-weight: bold; font-size: 14px;">
                    Перейти в интернет-магазин
                </a>
            </p>
            <p style="margin: 10px 0 0 0; font-size: 12px; color: #666;">
                Если у вас есть вопросы, обратитесь в службу поддержки.
            </p>
        </div>
    </div>
</body>
</html>
