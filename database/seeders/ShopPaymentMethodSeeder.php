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
                'name' => 'При получении',
                'type' => 'cash',
                'is_active' => true,
                'description' => 'Оплата наличными при получении заказа',
                'settings' => [
                    'available_for' => ['pickup', 'courier'],
                    'description' => 'Оплата наличными при получении'
                ],
                'sort_order' => 1,
                'is_default' => true,
                'can_disable_default' => true
            ],
            [
                'name' => 'Банковской картой',
                'type' => 'card',
                'is_active' => true,
                'description' => 'Оплата банковской картой онлайн',
                'settings' => [
                    'provider' => 'test_bank',
                    'api_key' => '',
                    'api_url' => '',
                    'description' => 'Безопасная оплата картой Visa, MasterCard, МИР'
                ],
                'sort_order' => 2,
                'is_default' => false,
                'can_disable_default' => true
            ],
            [
                'name' => 'Тест-Банк',
                'type' => 'test_bank',
                'is_active' => true,
                'description' => 'Тестовая оплата через Тест-Банк',
                'settings' => [
                    'api_key' => 'test_key_12345',
                    'api_url' => 'https://test-bank.example.com/api',
                    'merchant_id' => 'test_merchant',
                    'description' => 'Тестовая оплата для проверки функционала'
                ],
                'sort_order' => 3,
                'is_default' => false,
                'can_disable_default' => true
            ],
            [
                'name' => 'Банковский перевод',
                'type' => 'transfer',
                'is_active' => true,
                'description' => 'Оплата по банковским реквизитам',
                'settings' => [
                    'bank_name' => 'Сбербанк',
                    'account_number' => '12345678901234567890',
                    'bik' => '044525225',
                    'inn' => '1234567890',
                    'kpp' => '123456789',
                    'description' => 'Оплата по реквизитам для юридических лиц'
                ],
                'sort_order' => 4,
                'is_default' => false,
                'can_disable_default' => true
            ]
        ];

        foreach ($paymentMethods as $method) {
            ShopPaymentMethod::create($method);
        }
    }
}
