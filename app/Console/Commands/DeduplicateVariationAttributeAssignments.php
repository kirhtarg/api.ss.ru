<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DeduplicateVariationAttributeAssignments extends Command
{
    protected $signature = 'shop:deduplicate-variation-attributes
                            {--execute : Удалить дубли после создания резервной таблицы}';

    protected $description = 'Удаляет повторные значения одной характеристики у одной вариации';

    public function handle(): int
    {
        $duplicateGroups = (int) DB::scalar(<<<'SQL'
            SELECT COUNT(*)
            FROM (
                SELECT vav.variation_id, av.attribute_id
                FROM shop_variation_attributes_values vav
                INNER JOIN shop_variation_attribute_values av ON av.id = vav.attribute_value_id
                GROUP BY vav.variation_id, av.attribute_id
                HAVING COUNT(*) > 1
            ) duplicate_groups
        SQL);

        $duplicateRows = (int) DB::scalar(<<<'SQL'
            SELECT COALESCE(SUM(value_count - 1), 0)
            FROM (
                SELECT COUNT(*) AS value_count
                FROM shop_variation_attributes_values vav
                INNER JOIN shop_variation_attribute_values av ON av.id = vav.attribute_value_id
                GROUP BY vav.variation_id, av.attribute_id
                HAVING COUNT(*) > 1
            ) duplicate_groups
        SQL);

        $this->table(['Показатель', 'Значение'], [
            ['Групп с несколькими значениями', $duplicateGroups],
            ['Строк будет удалено', $duplicateRows],
        ]);

        if (! $this->option('execute') || $duplicateRows === 0) {
            $this->comment($duplicateRows === 0
                ? 'Очистка не требуется.'
                : 'Режим проверки. Для выполнения используйте --execute.');

            return self::SUCCESS;
        }

        $backupTable = 'shop_variation_attribute_duplicates_'.now()->format('Ymd_His');

        DB::transaction(function () use ($backupTable): void {
            DB::statement(<<<SQL
                CREATE TABLE `{$backupTable}` AS
                SELECT ranked.id, ranked.variation_id, ranked.attribute_value_id,
                       ranked.created_at, ranked.updated_at
                FROM (
                    SELECT vav.id, vav.variation_id, vav.attribute_value_id,
                           vav.created_at, vav.updated_at,
                           ROW_NUMBER() OVER (
                               PARTITION BY vav.variation_id, av.attribute_id
                               ORDER BY vav.updated_at DESC, vav.id DESC
                           ) AS row_num
                    FROM shop_variation_attributes_values vav
                    INNER JOIN shop_variation_attribute_values av ON av.id = vav.attribute_value_id
                ) ranked
                WHERE ranked.row_num > 1
            SQL);

            DB::delete(<<<'SQL'
                DELETE vav
                FROM shop_variation_attributes_values vav
                INNER JOIN (
                    SELECT id
                    FROM (
                        SELECT vav.id,
                               ROW_NUMBER() OVER (
                                   PARTITION BY vav.variation_id, av.attribute_id
                                   ORDER BY vav.updated_at DESC, vav.id DESC
                               ) AS row_num
                        FROM shop_variation_attributes_values vav
                        INNER JOIN shop_variation_attribute_values av ON av.id = vav.attribute_value_id
                    ) ranked_rows
                    WHERE row_num > 1
                ) duplicates ON duplicates.id = vav.id
            SQL);
        });

        $this->info("Дубли удалены. Резервная таблица: {$backupTable}");

        return self::SUCCESS;
    }
}
