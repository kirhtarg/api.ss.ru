<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\ShopCategory;

echo "Добавляем подкатегории к главным категориям:\n";

// Находим главные категории
$mainCategories = ShopCategory::where('is_main', true)->get();

foreach($mainCategories as $mainCat) {
    echo "Главная категория: {$mainCat->name}\n";
    
    // Создаем 2-3 подкатегории для каждой главной
    $subcategories = [];
    switch($mainCat->name) {
        case 'Выносы':
            $subcategories = ['Выносы руля', 'Выносы подседельные', 'Выносы амортизаторов'];
            break;
        case 'Защита':
            $subcategories = ['Шлемы', 'Наколенники', 'Налокотники', 'Защита спины'];
            break;
        case 'Велозапчасти':
            $subcategories = ['Тормоза', 'Переключатели', 'Цепи', 'Кассеты'];
            break;
    }
    
    foreach($subcategories as $index => $subName) {
        $subcategory = new ShopCategory();
        $subcategory->name = $subName;
        $subcategory->slug = \Illuminate\Support\Str::slug($subName);
        $subcategory->is_active = true;
        $subcategory->is_main = false;
        $subcategory->parent_id = $mainCat->id;
        $subcategory->sort_order = $index + 1;
        $subcategory->save();
        
        echo "  - Добавлена подкатегория: {$subName}\n";
    }
}

echo "\nПроверяем результат:\n";
$mainCategoriesWithChildren = ShopCategory::where('is_main', true)
    ->with('children')
    ->get();

foreach($mainCategoriesWithChildren as $mainCat) {
    echo "Главная категория: {$mainCat->name} (подкатегорий: {$mainCat->children->count()})\n";
    foreach($mainCat->children as $child) {
        echo "  - {$child->name}\n";
    }
}
