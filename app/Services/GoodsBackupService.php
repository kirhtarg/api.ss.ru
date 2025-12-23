<?php

namespace App\Services;

use App\Models\GoodsImportBackup;
use App\Models\ShopGood;
use App\Models\ShopCategory;
use App\Models\ShopBrand;
use App\Models\ShopGoodImage;
use App\Models\ShopPropertyValue;
use App\Models\ShopGoodProperty;
use App\Models\ShopGoodVariation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class GoodsBackupService
{
    /**
     * Таблицы, которые нужно включить в резервную копию
     */
    private const TABLES_TO_BACKUP = [
        'shop_goods',
        'shop_categories',
        'shop_brands',
        'shop_good_images',
        'shop_property_values',
        'shop_good_properties',
        'shop_good_variations',
        'shop_tags',
        'shop_labels'
    ];

    /**
     * Создать резервную копию товаров
     */
    public function createBackup(string $name, int $userId, ?int $shopId = null): GoodsImportBackup
    {
        Log::info('Начало создания резервной копии товаров', [
            'name' => $name,
            'user_id' => $userId,
            'shop_id' => $shopId
        ]);

        // Создаем уникальное имя файла
        $filename = 'backup_' . date('Y_m_d_H_i_s') . '_' . Str::random(8) . '.json';

        // Собираем данные из всех таблиц
        $backupData = $this->collectBackupData($shopId);
        $jsonData = json_encode($backupData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        // Сохраняем файл
        $backupPath = 'backups/goods_import/' . $filename;
        Storage::put($backupPath, $jsonData);

        // Получаем размер файла
        $fileSize = Storage::size($backupPath);

        // Подсчитываем общее количество записей
        $totalRecords = 0;
        foreach ($backupData as $tableData) {
            $totalRecords += count($tableData);
        }

        // Создаем запись в базе данных
        $backup = GoodsImportBackup::create([
            'name' => $name,
            'filename' => $filename,
            'shop_id' => $shopId,
            'user_id' => $userId,
            'size' => $fileSize,
            'records_count' => $totalRecords,
            'tables_backed_up' => self::TABLES_TO_BACKUP,
            'status' => 'completed'
        ]);

        Log::info('Резервная копия товаров успешно создана', [
            'backup_id' => $backup->id,
            'filename' => $filename,
            'size' => $fileSize,
            'records_count' => $totalRecords
        ]);

        return $backup;
    }

    /**
     * Восстановить данные из резервной копии
     */
    public function restoreBackup(GoodsImportBackup $backup): void
    {
        Log::info('Начало восстановления резервной копии', [
            'backup_id' => $backup->id,
            'filename' => $backup->filename
        ]);

        // Проверяем, существует ли файл
        if (!$backup->fileExists()) {
            throw new \Exception('Файл резервной копии не найден');
        }

        // Читаем данные из файла
        $backupData = json_decode(Storage::get('backups/goods_import/' . $backup->filename), true);

        if (!$backupData) {
            throw new \Exception('Неверный формат файла резервной копии');
        }

        // Очищаем таблицы перед восстановлением
        $this->clearTables();

        // Восстанавливаем данные
        $this->restoreData($backupData);

        Log::info('Резервная копия успешно восстановлена', [
            'backup_id' => $backup->id
        ]);
    }

    /**
     * Собрать данные для резервной копии
     */
    private function collectBackupData(?int $shopId = null): array
    {
        $backupData = [];

        foreach (self::TABLES_TO_BACKUP as $table) {
            try {
                $query = DB::table($table);

                // Если указан shop_id, добавляем фильтр (если таблица поддерживает)
                if ($shopId && $this->tableHasShopIdColumn($table)) {
                    $query->where('shop_id', $shopId);
                }

                $backupData[$table] = $query->get()->toArray();

                Log::debug("Собраны данные таблицы {$table}", [
                    'records_count' => count($backupData[$table])
                ]);

            } catch (\Exception $e) {
                Log::warning("Ошибка при сборе данных таблицы {$table}", [
                    'error' => $e->getMessage()
                ]);
                $backupData[$table] = [];
            }
        }

        return $backupData;
    }

    /**
     * Проверить, имеет ли таблица колонку shop_id
     */
    private function tableHasShopIdColumn(string $table): bool
    {
        $tablesWithShopId = [
            'shop_goods',
            'shop_categories',
            'shop_brands',
            'shop_good_images',
            'shop_good_variations'
        ];

        return in_array($table, $tablesWithShopId);
    }

    /**
     * Очистить таблицы перед восстановлением
     */
    private function clearTables(): void
    {
        Log::info('Очистка таблиц перед восстановлением');

        // Очищаем в правильном порядке с учетом внешних ключей
        $clearOrder = [
            'shop_good_variations',
            'shop_good_properties',
            'shop_property_values',
            'shop_good_images',
            'shop_goods',
            'shop_categories',
            'shop_brands',
            'shop_tags',
            'shop_labels'
        ];

        foreach ($clearOrder as $table) {
            try {
                DB::table($table)->delete();
                Log::debug("Таблица {$table} очищена");
            } catch (\Exception $e) {
                Log::warning("Ошибка при очистке таблицы {$table}", [
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Восстановить данные из резервной копии
     */
    private function restoreData(array $backupData): void
    {
        Log::info('Восстановление данных из резервной копии');

        // Восстанавливаем в правильном порядке с учетом внешних ключей
        $restoreOrder = [
            'shop_brands',
            'shop_categories',
            'shop_goods',
            'shop_good_images',
            'shop_property_values',
            'shop_good_properties',
            'shop_good_variations',
            'shop_tags',
            'shop_labels'
        ];

        foreach ($restoreOrder as $table) {
            if (!isset($backupData[$table]) || empty($backupData[$table])) {
                Log::debug("Пропускаем таблицу {$table} - нет данных");
                continue;
            }

            try {
                $records = $backupData[$table];
                $chunkSize = 100; // Размер чанка для вставки

                // Разбиваем на чанки для больших таблиц
                $chunks = array_chunk($records, $chunkSize);

                foreach ($chunks as $chunk) {
                    DB::table($table)->insert($chunk);
                }

                Log::debug("Таблица {$table} восстановлена", [
                    'records_count' => count($records)
                ]);

            } catch (\Exception $e) {
                Log::error("Ошибка при восстановлении таблицы {$table}", [
                    'error' => $e->getMessage(),
                    'records_count' => count($backupData[$table] ?? [])
                ]);

                // Продолжаем с другими таблицами, но логируем ошибку
            }
        }
    }
}






