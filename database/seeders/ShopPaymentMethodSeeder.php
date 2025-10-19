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
