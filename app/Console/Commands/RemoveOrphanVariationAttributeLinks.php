<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RemoveOrphanVariationAttributeLinks extends Command
{
    protected $signature = 'shop:remove-orphan-variation-attribute-links
                            {--execute : Удалить только битые связи после создания резервной таблицы}';

    protected $description = 'Удаляет связи вариаций, для которых уже нет вариации или значения характеристики';

    public function handle(): int
    {
        $orphanRows = (int) DB::scalar(<<<'SQL'
            SELECT COUNT(*)
            FROM shop_variation_attributes_values vav
            LEFT JOIN shop_good_variations v ON v.id = vav.variation_id
            LEFT JOIN shop_variation_attribute_values av ON av.id = vav.attribute_value_id
            WHERE v.id IS NULL OR av.id IS NULL
        SQL);

        $this->table(['Показатель', 'Значение'], [
            ['Битых связей', $orphanRows],
        ]);

        if (! $this->option('execute') || $orphanRows === 0) {
            $this->comment($orphanRows === 0
                ? 'Очистка не требуется.'
                : 'Режим проверки. Для выполнения используйте --execute.');

            return self::SUCCESS;
        }

        $backupTable = 'shop_orphan_variation_attribute_links_'.now()->format('Ymd_His');

        // CREATE TABLE has an implicit MySQL commit, so validate the backup
        // count before starting the separate DELETE statement.
        DB::statement(<<<SQL
            CREATE TABLE `{$backupTable}` AS
            SELECT vav.*
            FROM shop_variation_attributes_values vav
            LEFT JOIN shop_good_variations v ON v.id = vav.variation_id
            LEFT JOIN shop_variation_attribute_values av ON av.id = vav.attribute_value_id
            WHERE v.id IS NULL OR av.id IS NULL
        SQL);

        $backupRows = (int) DB::table($backupTable)->count();
        if ($backupRows !== $orphanRows) {
            throw new \RuntimeException(
                "Резервная таблица {$backupTable} содержит {$backupRows} строк вместо ожидаемых {$orphanRows}. Удаление отменено."
            );
        }

        $deletedRows = DB::delete(<<<'SQL'
            DELETE vav
            FROM shop_variation_attributes_values vav
            LEFT JOIN shop_good_variations v ON v.id = vav.variation_id
            LEFT JOIN shop_variation_attribute_values av ON av.id = vav.attribute_value_id
            WHERE v.id IS NULL OR av.id IS NULL
        SQL);

        $this->info("Удалено битых связей: {$deletedRows}. Резервная таблица: {$backupTable}");

        return self::SUCCESS;
    }
}
