<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ContactSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $contacts = [
            [
                'id' => 3,
                'name' => 'Магазин на Крестовском',
                'short_name' => null,
                'is_main' => 1,
                'created_at' => '2025-09-15 03:48:26',
                'updated_at' => '2025-09-15 07:14:02',
            ],
        ];

        foreach ($contacts as $contact) {
            DB::table('contacts')->updateOrInsert(
                ['id' => $contact['id']],
                $contact
            );
        }
    }
}













