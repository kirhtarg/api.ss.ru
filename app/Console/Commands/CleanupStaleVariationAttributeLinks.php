<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupStaleVariationAttributeLinks extends Command
{
    protected $signature = 'shop:cleanup-stale-variation-attribute-links
                            {--execute : Удалить подтверждённые устаревшие связи}';

    protected $description = 'Удаляет связи атрибутов, созданные раньше самой вариации';

    public function handle(): int
    {
        $staleRows = (int) DB::table('shop_variation_attributes_values as vav')
            ->join('shop_good_variations as v', 'v.id', '=', 'vav.variation_id')
            ->whereColumn('vav.created_at', '<', 'v.created_at')
            ->count();

        $this->table(['Показатель', 'Значение'], [
            ['Связей старше самой вариации', $staleRows],
        ]);

        if (! $this->option('execute') || $staleRows === 0) {
            $this->comment($staleRows === 0
                ? 'Очистка не требуется.'
                : 'Режим проверки. Для выполнения используйте --execute.');

            return self::SUCCESS;
        }

        $backupTable = 'shop_stale_variation_attribute_links_'.now()->format('Ymd_His');

        // CREATE TABLE causes an implicit MySQL commit. Verify the backup before deletion.
        DB::statement(<<<SQL
            CREATE TABLE `{$backupTable}` AS
            SELECT vav.*
            FROM shop_variation_attributes_values vav
            INNER JOIN shop_good_variations v ON v.id = vav.variation_id
            WHERE vav.created_at < v.created_at
        SQL);

        $backupRows = (int) DB::table($backupTable)->count();
        if ($backupRows !== $staleRows) {
            throw new \RuntimeException(
                "Резервная таблица {$backupTable} содержит {$backupRows} строк вместо ожидаемых {$staleRows}. Удаление отменено."
            );
        }

        $deletedRows = DB::delete(<<<'SQL'
            DELETE vav
            FROM shop_variation_attributes_values vav
            INNER JOIN shop_good_variations v ON v.id = vav.variation_id
            WHERE vav.created_at < v.created_at
        SQL);

        $this->info("Удалено устаревших связей: {$deletedRows}. Резервная таблица: {$backupTable}");

        return self::SUCCESS;
    }
}
