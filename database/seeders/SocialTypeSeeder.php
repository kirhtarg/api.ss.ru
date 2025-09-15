<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\SocialType;

class SocialTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $socialTypes = [
            [
                'social' => 'VKontakte',
                'icon' => 'fab fa-vk',
            ],
            [
                'social' => 'Telegram',
                'icon' => 'fab fa-telegram',
            ],
            [
                'social' => 'WhatsApp',
                'icon' => 'fab fa-whatsapp',
            ],
            [
                'social' => 'Instagram',
                'icon' => 'fab fa-instagram',
            ],
            [
                'social' => 'Facebook',
                'icon' => 'fab fa-facebook',
            ],
            [
                'social' => 'Twitter',
                'icon' => 'fab fa-twitter',
            ],
            [
                'social' => 'YouTube',
                'icon' => 'fab fa-youtube',
            ],
            [
                'social' => 'TikTok',
                'icon' => 'fab fa-tiktok',
            ],
            [
                'social' => 'LinkedIn',
                'icon' => 'fab fa-linkedin',
            ],
            [
                'social' => 'Discord',
                'icon' => 'fab fa-discord',
            ],
            [
                'social' => 'Skype',
                'icon' => 'fab fa-skype',
            ],
            [
                'social' => 'Email',
                'icon' => 'fas fa-envelope',
            ],
        ];

        foreach ($socialTypes as $socialType) {
            SocialType::firstOrCreate(
                ['social' => $socialType['social']],
                $socialType
            );
        }
    }
}
