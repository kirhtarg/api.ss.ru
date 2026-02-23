<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Setting;

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
                'type' => 'text'
            ]
        );
    }
}
