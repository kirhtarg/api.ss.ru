<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SocialTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $socialTypes = [
            [
                'id' => 1,
                'social' => 'VKontakte',
                'icon' => 'fab fa-vk',
                'created_at' => '2025-09-15 03:43:47',
                'updated_at' => '2025-09-15 03:43:47',
            ],
            [
                'id' => 2,
                'social' => 'Telegram',
                'icon' => 'fab fa-telegram',
                'created_at' => '2025-09-15 03:43:47',
                'updated_at' => '2025-09-15 03:43:47',
            ],
            [
                'id' => 3,
                'social' => 'WhatsApp',
                'icon' => 'fab fa-whatsapp',
                'created_at' => '2025-09-15 03:43:47',
                'updated_at' => '2025-09-15 03:43:47',
            ],
            [
                'id' => 4,
                'social' => 'Instagram',
                'icon' => 'fab fa-instagram',
                'created_at' => '2025-09-15 03:43:47',
                'updated_at' => '2025-09-15 03:43:47',
            ],
            [
                'id' => 5,
                'social' => 'Facebook',
                'icon' => 'fab fa-facebook',
                'created_at' => '2025-09-15 03:43:47',
                'updated_at' => '2025-09-15 03:43:47',
            ],
            [
                'id' => 6,
                'social' => 'Twitter',
                'icon' => 'fab fa-twitter',
                'created_at' => '2025-09-15 03:43:47',
                'updated_at' => '2025-09-15 03:43:47',
            ],
            [
                'id' => 7,
                'social' => 'YouTube',
                'icon' => 'fab fa-youtube',
                'created_at' => '2025-09-15 03:43:47',
                'updated_at' => '2025-09-15 03:43:47',
            ],
            [
                'id' => 8,
                'social' => 'TikTok',
                'icon' => 'fab fa-tiktok',
                'created_at' => '2025-09-15 03:43:47',
                'updated_at' => '2025-09-15 03:43:47',
            ],
            [
                'id' => 9,
                'social' => 'LinkedIn',
                'icon' => 'fab fa-linkedin',
                'created_at' => '2025-09-15 03:43:47',
                'updated_at' => '2025-09-15 03:43:47',
            ],
            [
                'id' => 10,
                'social' => 'Discord',
                'icon' => 'fab fa-discord',
                'created_at' => '2025-09-15 03:43:47',
                'updated_at' => '2025-09-15 03:43:47',
            ],
            [
                'id' => 11,
                'social' => 'Skype',
                'icon' => 'fab fa-skype',
                'created_at' => '2025-09-15 03:43:47',
                'updated_at' => '2025-09-15 03:43:47',
            ],
            [
                'id' => 12,
                'social' => 'Email',
                'icon' => 'fas fa-envelope',
                'created_at' => '2025-09-15 03:43:47',
                'updated_at' => '2025-09-15 03:43:47',
            ],
        ];

        foreach ($socialTypes as $socialType) {
            DB::table('social_types')->updateOrInsert(
                ['id' => $socialType['id']],
                $socialType
            );
        }
    }
}





