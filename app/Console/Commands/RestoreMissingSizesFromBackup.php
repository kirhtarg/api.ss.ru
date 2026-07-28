<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RestoreMissingSizesFromBackup extends Command
{
    protected $signature = 'shop:restore-missing-sizes-from-backup
                            {backupTable : Таблица из первой очистки связей вариаций}
                            {--execute : Добавить только однозначно определённые размеры}';

    protected $description = 'Восстанавливает отсутствующий размер из резервной таблицы, сохраняя текущий цвет вариации';

    public function handle(): int
    {
        $backupTable = (string) $this->argument('backupTable');
        if (! preg_match('/^[A-Za-z0-9_]+$/', $backupTable) || ! Schema::hasTable($backupTable)) {
            $this->error('Указанная резервная таблица не найдена.');

            return self::FAILURE;
        }

        $attributeIds = DB::table('shop_variation_attributes')
            ->whereIn('name', ['Цвет', 'Размер'])
            ->pluck('id', 'name');

        if (! isset($attributeIds['Цвет'], $attributeIds['Размер'])) {
            $this->error('В справочнике должны существовать характеристики «Цвет» и «Размер».');

            return self::FAILURE;
        }

        $colorAttributeId = (int) $attributeIds['Цвет'];
        $sizeAttributeId = (int) $attributeIds['Размер'];
        $archive = DB::table("{$backupTable} as b")
            ->join('shop_good_variations as v', 'v.id', '=', 'b.variation_id')
            ->join('shop_goods as g', 'g.id', '=', 'v.good_id')
            ->join('shop_variation_attribute_values as av', 'av.id', '=', 'b.attribute_value_id')
            ->get([
                'g.id as good_id', 'g.name as good_name', 'v.id as variation_id', 'v.sku',
                'av.attribute_id', 'av.value as archived_value',
            ])
            ->groupBy('variation_id');

        $live = DB::table('shop_variation_attributes_values as vav')
            ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
            ->whereIn('vav.variation_id', $archive->keys()->all())
            ->get(['vav.variation_id', 'av.attribute_id', 'av.value as live_value'])
            ->groupBy('variation_id');

        $candidates = collect();
        foreach ($archive as $variationId => $archiveRows) {
            $liveRows = $live->get($variationId, collect());
            $archiveSizes = $archiveRows->filter(fn ($row) => (int) $row->attribute_id === $colorAttributeId && $this->looksLikeSize($row->archived_value));
            $archiveColors = $archiveRows->filter(fn ($row) => (int) $row->attribute_id === $colorAttributeId && ! $this->looksLikeSize($row->archived_value));

            if (
                $archiveRows->count() !== 2
                || $archiveSizes->count() !== 1
                || $archiveColors->count() !== 1
                || $liveRows->count() !== 1
                || (int) $liveRows->first()->attribute_id !== $colorAttributeId
                || $this->looksLikeSize($liveRows->first()->live_value)
            ) {
                continue;
            }

            $candidates->push([
                'good_id' => $archiveRows->first()->good_id,
                'sku' => $archiveRows->first()->sku,
                'variation_id' => $variationId,
                'color' => $liveRows->first()->live_value,
                'size' => $archiveSizes->first()->archived_value,
            ]);
        }

        $this->table(
            ['Товар', 'SKU', 'Текущий цвет', 'Восстанавливаемый размер'],
            $candidates->take(25)->map(fn (array $candidate) => [
                $candidate['good_id'], $candidate['sku'] ?: '-', $candidate['color'], $candidate['size'],
            ])->all()
        );
        $this->line('Однозначно распознано вариаций: '.$candidates->count());
        if ($candidates->count() > 25) {
            $this->line('В таблице показаны первые 25 вариаций.');
        }

        if (! $this->option('execute') || $candidates->isEmpty()) {
            $this->comment('Режим проверки. Для выполнения добавьте --execute.');

            return self::SUCCESS;
        }

        $variationIds = $candidates->pluck('variation_id')->all();
        $backupTableName = 'shop_variation_missing_size_repair_'.now()->format('Ymd_His');
        DB::statement(sprintf(
            'CREATE TABLE `%s` AS SELECT * FROM shop_variation_attributes_values WHERE variation_id IN (%s)',
            $backupTableName,
            implode(',', array_map('intval', $variationIds)),
        ));

        if ((int) DB::table($backupTableName)->count() !== $candidates->count()) {
            throw new \RuntimeException("Резервная таблица {$backupTableName} содержит неполный набор связей. Исправление отменено.");
        }

        DB::transaction(function () use ($candidates, $sizeAttributeId): void {
            $now = now();
            foreach ($candidates as $candidate) {
                $sizeValueId = DB::table('shop_variation_attribute_values')
                    ->where('attribute_id', $sizeAttributeId)
                    ->where('value', $candidate['size'])
                    ->value('id');

                if (! $sizeValueId) {
                    $sizeValueId = DB::table('shop_variation_attribute_values')->insertGetId([
                        'attribute_id' => $sizeAttributeId,
                        'value' => $candidate['size'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                DB::table('shop_variation_attributes_values')->insert([
                    'variation_id' => $candidate['variation_id'],
                    'attribute_value_id' => $sizeValueId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });

        $this->info("Восстановлено размеров: {$candidates->count()}. Резервная таблица: {$backupTableName}");

        return self::SUCCESS;
    }

    private function looksLikeSize(string $value): bool
    {
        $sizeToken = '(?:XXS|XS|S|M|М|L|XL|XXL|2XL|3XL|4XL|SM|MD|LG|OS|NS|UNI|ONE\s+SIZE)';

        return (bool) preg_match('/^(?:ДЕТ\s+.+|\d+\s*\'\s*\d+\s*"|\d+(?:[.,]\d+)?\s*-\s*\d+(?:[.,]\d+)?|\d+(?:\s*-\s*\d+)?\s*\([^)]+\)|\d+(?:[.,]\d+)?(?:\s*-\s*\d+(?:[.,]\d+)?)?\s*(?:ML|L|KGS?|G|MM|CM|M|IN)(?:\s*\/\s*\d+(?:[.,]\d+)?(?:\s*-\s*\d+(?:[.,]\d+)?)?\s*(?:ML|L|KGS?|G|MM|CM|M|IN))*|'.$sizeToken.'(?:\s*\/\s*'.$sizeToken.')*|OS\s*-\s*(?:LEFT|RIGHT)|(?:SHORT|REGULAR|LONG|TALL)\s*-\s*\d+)$/iu', trim($value));
    }
}
