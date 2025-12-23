<?php

require_once 'vendor/autoload.php';

use App\Models\ShopGood;
use App\Models\ShopGoodVariation;
use Illuminate\Support\Facades\DB;

function testResetSupplierStocks($supplierName) {
    echo "Тестирование обнуления остатков для поставщика: {$supplierName}\n";

    // Проверяем количество товаров до обнуления
    $goodsCount = ShopGood::where('supplier', $supplierName)->count();
    echo "Найдено товаров: {$goodsCount}\n";

    // Проверяем количество вариаций до обнуления
    $variationsCount = ShopGoodVariation::whereHas('good', function($query) use ($supplierName) {
        $query->where('supplier', $supplierName);
    })->count();
    echo "Найдено вариаций: {$variationsCount}\n";

    // Обнуляем остатки товаров
    $goodsUpdated = ShopGood::where('supplier', $supplierName)
        ->update([
            'stock_quantity' => 0,
            'remote_stock_quantity' => null,
            'fast_remote_stock_quantity' => null
        ]);

    echo "Обновлено товаров: {$goodsUpdated}\n";

    // Обнуляем остатки вариаций
    $variationsUpdated = ShopGoodVariation::whereHas('good', function($query) use ($supplierName) {
        $query->where('supplier', $supplierName);
    })->update([
        'stock_quantity' => 0,
        'remote_stock_quantity' => null,
        'fast_remote_stock_quantity' => null
    ]);

    echo "Обновлено вариаций: {$variationsUpdated}\n";

    echo "Тест завершен\n";
}

// Получаем список всех уникальных поставщиков
$suppliers = ShopGood::whereNotNull('supplier')->where('supplier', '!=', '')->distinct()->pluck('supplier')->toArray();
echo "Найденные поставщики в базе:\n";
print_r($suppliers);

// Тестируем с первым найденным поставщиком
if (!empty($suppliers)) {
    testResetSupplierStocks($suppliers[0]);
} else {
    echo "Не найдено поставщиков в базе данных\n";
}
