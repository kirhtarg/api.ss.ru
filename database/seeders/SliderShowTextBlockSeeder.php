<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SliderShowTextBlockSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Обновляем существующие слайдеры
        // Для слайдеров 1, 2, 3 (верхний блок и Сервис) - показываем текстовый блок
        DB::table('sliders')
            ->whereIn('id', [1, 2, 3])
            ->update(['show_text_block' => true]);

        // Для слайдера 4 (контакты) - не показываем текстовый блок
        DB::table('sliders')
            ->where('id', 4)
            ->update(['show_text_block' => false]);

        // Для слайдера 5 (мобильный) - показываем текстовый блок
        DB::table('sliders')
            ->where('id', 5)
            ->update(['show_text_block' => true]);
    }
}
