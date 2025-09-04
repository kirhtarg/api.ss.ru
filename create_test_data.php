<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// Создаем тестовое меню
$menu = \App\Models\SiteMenu::create([
    'name' => 'Основное меню',
    'description' => 'Главное меню сайта',
    'is_active' => true
]);

// Создаем пункты меню
\App\Models\SiteMenuItem::create([
    'site_menu_id' => $menu->id,
    'title' => 'Главная',
    'url' => '/',
    'sort_order' => 1,
    'is_active' => true
]);

\App\Models\SiteMenuItem::create([
    'site_menu_id' => $menu->id,
    'title' => 'Каталог',
    'url' => '/catalog',
    'sort_order' => 2,
    'is_active' => true
]);

\App\Models\SiteMenuItem::create([
    'site_menu_id' => $menu->id,
    'title' => 'О нас',
    'url' => '/about',
    'sort_order' => 3,
    'is_active' => true
]);

\App\Models\SiteMenuItem::create([
    'site_menu_id' => $menu->id,
    'title' => 'Контакты',
    'url' => '/contacts',
    'sort_order' => 4,
    'is_active' => true
]);

// Создаем тестовый шаблон
\App\Models\SiteTemplate::create([
    'name' => 'Default Template',
    'description' => 'Дефолтный шаблон сайта',
    'folder_name' => 'default',
    'menu_id' => $menu->id,
    'is_active' => true
]);

echo "Тестовые данные созданы успешно!\n";
echo "Меню ID: " . $menu->id . "\n";
echo "Шаблон создан с menu_id: " . $menu->id . "\n";
