<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InspectVariationAxisHistory extends Command
{
    protected $signature = 'shop:inspect-variation-axis-history
                            {variationId : ID вариации}';

    protected $description = 'Показывает все значения осей вариации из основной и резервных таблиц без изменения данных';

    public function handle(): int
    {
        $variationId = (int) $this->argument('variationId');
        $variation = DB::table('shop_good_variations as v')
            ->join('shop_goods as g', 'g.id', '=', 'v.good_id')
            ->where('v.id', $variationId)
            ->first(['v.id', 'v.sku', 'v.name as variation_name', 'g.id as good_id', 'g.name as good_name']);

        if (! $variation) {
            $this->error('Вариация не найдена.');

            return self::FAILURE;
        }

        $this->line("Товар #{$variation->good_id}: {$variation->good_name}");
        $this->line("Вариация #{$variation->id}; SKU: ".($variation->sku ?: 'не указан'));

        $database = DB::connection()->getDatabaseName();
        $tables = collect(DB::select(
            'SELECT TABLE_NAME
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ?
               AND TABLE_NAME LIKE "shop_variation%"
               AND COLUMN_NAME IN ("variation_id", "attribute_value_id")
             GROUP BY TABLE_NAME
             HAVING COUNT(DISTINCT COLUMN_NAME) = 2
             ORDER BY CASE WHEN TABLE_NAME = "shop_variation_attributes_values" THEN 0 ELSE 1 END, TABLE_NAME',
            [$database]
        ))->pluck('TABLE_NAME');

        $rows = [];
        foreach ($tables as $table) {
            if (! preg_match('/^[A-Za-z0-9_]+$/', $table) || ! Schema::hasTable($table)) {
                continue;
            }

            $columns = Schema::getColumnListing($table);
            $select = ['link.variation_id', 'av.attribute_id', 'a.name as attribute_name', 'av.value'];
            if (in_array('created_at', $columns, true)) {
                $select[] = 'link.created_at';
            }
            if (in_array('updated_at', $columns, true)) {
                $select[] = 'link.updated_at';
            }

            foreach (DB::table("{$table} as link")
                ->join('shop_variation_attribute_values as av', 'av.id', '=', 'link.attribute_value_id')
                ->leftJoin('shop_variation_attributes as a', 'a.id', '=', 'av.attribute_id')
                ->where('link.variation_id', $variationId)
                ->get($select) as $row) {
                $rows[] = [
                    $table,
                    $row->attribute_name ?: '#'.$row->attribute_id,
                    $row->value,
                    $row->created_at ?? '-',
                    $row->updated_at ?? '-',
                ];
            }
        }

        $this->table(['Таблица', 'Ось', 'Значение', 'Создано', 'Обновлено'], $rows ?: [['-', '-', 'Связей не найдено', '-', '-']]);

        return self::SUCCESS;
    }
}
