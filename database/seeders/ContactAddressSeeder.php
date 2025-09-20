<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ContactAddressSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $addresses = [
            [
                'id' => 9,
                'id_contact' => 3,
                'address' => 'ст.м.Крестовский остров.<div>г.Санкт-Петербург, Константиновский пр.11</div><div>Часы работы: с 11 до 22! Каждый день!</div>',
                'address_short' => 'г.СПб, Константиновский пр.11',
                'longitude' => 59.9726270,
                'latitude' => 30.2716530,
                'howtogo' => 'Магазин находится в 5 минутах от метро "Крестовский остров".<div>На автомобиле перед магазином удобная парковка. Приходите, ждем Вас в гости!</div>',
                'is_main' => 1,
                'created_at' => '2025-09-15 07:50:59',
                'updated_at' => '2025-09-15 07:50:59',
            ],
        ];

        foreach ($addresses as $address) {
            DB::table('contact_addresses')->updateOrInsert(
                ['id' => $address['id']],
                $address
            );
        }
    }
}












