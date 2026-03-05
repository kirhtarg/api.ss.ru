<?php

require_once 'vendor/autoload.php';

use Database\Seeders\SiteTemplateSeeder;

// Создаем экземпляр сидера и запускаем его
$seeder = new SiteTemplateSeeder;
$seeder->run();

echo "SiteTemplateSeeder выполнен успешно!\n";
