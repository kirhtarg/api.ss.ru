<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SuffSupportSettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        Setting::updateOrCreate(
            ['key' => 'suff_support'],
            [
                'value' => '-detail',
                'group' => 'shop',
                'type' => 'text',
            ]
        );
    }
}
