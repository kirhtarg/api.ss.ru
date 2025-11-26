<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ShopPaymentMethodSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $paymentMethods = [
            [
                'id' => 1,
                'name' => 'При получении',
                'type' => 'cash',
                'is_active' => true,
                'description' => 'Оплата наличными при получении заказа',
                'settings' => json_encode([
                    'available_for' => ['pickup', 'courier'],
                    'description' => 'Оплата наличными при получении'
                ]),
                'sort_order' => 1,
                'is_default' => true,
                'can_disable_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'name' => 'Банковской картой',
                'type' => 'card',
                'is_active' => true,
                'description' => 'Оплата банковской картой онлайн',
                'settings' => json_encode([
                    'provider' => 'test_bank',
                    'api_key' => '',
                    'api_url' => '',
                    'description' => 'Безопасная оплата картой Visa, MasterCard, МИР'
                ]),
                'sort_order' => 2,
                'is_default' => false,
                'can_disable_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 3,
                'name' => 'Тест-Банк',
                'type' => 'test_bank',
                'is_active' => true,
                'description' => 'Тестовая оплата через Тест-Банк',
                'settings' => json_encode([
                    'api_key' => 'test_key_12345',
                    'api_url' => 'https://test-bank.example.com/api',
                    'merchant_id' => 'test_merchant',
                    'description' => 'Тестовая оплата для проверки функционала'
                ]),
                'sort_order' => 3,
                'is_default' => false,
                'can_disable_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 4,
                'name' => 'Банковский перевод',
                'type' => 'transfer',
                'is_active' => true,
                'description' => 'Оплата по банковским реквизитам',
                'settings' => json_encode([
                    'bank_name' => 'Сбербанк',
                    'account_number' => '12345678901234567890',
                    'bik' => '044525225',
                    'inn' => '1234567890',
                    'kpp' => '123456789',
                    'description' => 'Оплата по реквизитам для юридических лиц'
                ]),
                'sort_order' => 4,
                'is_default' => false,
                'can_disable_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 5,
                'name' => 'Ю-Касса',
                'type' => 'yookassa',
                'is_active' => true,
                'description' => 'Оплата через Ю-Касса (карты, ЮMoney, СБП)',
                'settings' => json_encode([
                    'shop_id' => '123456', // Тестовый ID магазина
                    'secret_key' => 'test_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', // Тестовый секретный ключ
                    'mode' => 'test',
                    'currency' => 'RUB',
                    'return_url' => 'http://localhost:3000/checkout?payment=return',
                    'additional_settings' => '{"auto_capture": true}',
                    'description' => 'Безопасная оплата через Ю-Касса'
                ]),
                'sort_order' => 5,
                'is_default' => false,
                'can_disable_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 6,
                'name' => 'Яндекс Пэй',
                'type' => 'yandex_pay',
                'is_active' => false,
                'description' => 'Оплата через Яндекс Пэй (карты, Яндекс.Деньги, СБП)',
                'settings' => json_encode([
                    'merchant_id' => '12345678', // Тестовый Merchant ID
                    'secret_key' => 'test_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', // Тестовый секретный ключ
                    'mode' => 'test',
                    'currency' => 'RUB',
                    'return_url' => 'https://your-site.com/checkout?payment=return',
                    'webhook_url' => 'https://your-site.com/api/webhooks/yandex-pay',
                    'additional_settings' => '{"auto_capture": true, "save_payment_method": false, "split_enabled": false}',
                    'description' => 'Безопасная оплата через Яндекс Пэй'
                ]),
                'sort_order' => 6,
                'is_default' => false,
                'can_disable_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 7,
                'name' => 'Яндекс Сплит',
                'type' => 'yandex_split',
                'is_active' => false,
                'description' => 'Рассрочка через Яндекс Сплит',
                'settings' => json_encode([
                    'merchant_id' => '12345678', // Тестовый Merchant ID
                    'secret_key' => 'test_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', // Тестовый секретный ключ
                    'mode' => 'test',
                    'currency' => 'RUB',
                    'return_url' => 'https://your-site.com/checkout?payment=return',
                    'webhook_url' => 'https://your-site.com/api/webhooks/yandex-split',
                    'split_min_amount' => '1000.00',
                    'split_max_amount' => '50000.00',
                    'split_settings' => '{"enabled": true, "installments_count": 4, "first_payment_percent": 25}',
                    'description' => 'Рассрочка без переплат через Яндекс Сплит'
                ]),
                'sort_order' => 7,
                'is_default' => false,
                'can_disable_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ];

        foreach ($paymentMethods as $method) {
            DB::table('shop_payment_methods')->updateOrInsert(
                ['id' => $method['id']],
                $method
            );
        }
    }
}
