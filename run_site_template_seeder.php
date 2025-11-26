<?php

require_once 'vendor/autoload.php';

use Illuminate\Database\Seeder;
use Database\Seeders\SiteTemplateSeeder;

// Создаем экземпляр сидера и запускаем его
$seeder = new SiteTemplateSeeder();
$seeder->run();

echo "SiteTemplateSeeder выполнен успешно!\n";
