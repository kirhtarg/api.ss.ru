<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ContactPhoneSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $phones = [
            [
                'id' => 25,
                'id_contact' => 3,
                'phone_name' => 'В СПб',
                'phone_number' => '+78126480019',
                'is_main' => 1,
                'created_at' => '2025-09-15 07:50:59',
                'updated_at' => '2025-09-15 07:50:59',
            ],
            [
                'id' => 26,
                'id_contact' => 3,
                'phone_name' => 'В Москве',
                'phone_number' => '+74952152455',
                'is_main' => 0,
                'created_at' => '2025-09-15 07:50:59',
                'updated_at' => '2025-09-15 07:50:59',
            ],
            [
                'id' => 27,
                'id_contact' => 3,
                'phone_name' => 'по России',
                'phone_number' => '+78129822291',
                'is_main' => 0,
                'created_at' => '2025-09-15 07:50:59',
                'updated_at' => '2025-09-15 07:50:59',
            ],
        ];

        foreach ($phones as $phone) {
            DB::table('contact_phones')->updateOrInsert(
                ['id' => $phone['id']],
                $phone
            );
        }
    }
}




