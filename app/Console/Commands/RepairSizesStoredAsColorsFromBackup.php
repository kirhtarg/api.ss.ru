<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RepairSizesStoredAsColorsFromBackup extends Command
{
    protected $signature = 'shop:repair-sizes-stored-as-colors
                            {backupTable : Таблица из первой очистки связей вариаций}
                            {--execute : Исправить только однозначно распознанные вариации}';

    protected $description = 'Переносит размер из ошибочной оси «Цвет» и возвращает цвет из резервной таблицы';

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

        $archived = DB::table("{$backupTable} as b")
            ->join('shop_good_variations as v', 'v.id', '=', 'b.variation_id')
            ->join('shop_goods as g', 'g.id', '=', 'v.good_id')
            ->join('shop_variation_attribute_values as av', 'av.id', '=', 'b.attribute_value_id')
            ->get([
                'g.id as good_id', 'g.name as good_name', 'v.id as variation_id', 'v.sku',
                'av.attribute_id', 'av.value as archived_value',
            ])
            ->groupBy('variation_id');

        $variationIds = $archived->keys()->values();
        $live = DB::table('shop_variation_attributes_values as vav')
            ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
            ->whereIn('vav.variation_id', $variationIds)
            ->get(['vav.variation_id', 'av.attribute_id', 'av.value as live_value'])
            ->groupBy('variation_id');

        $candidates = collect();
        foreach ($archived as $variationId => $archiveLinks) {
            $liveLinks = $live->get($variationId, collect());
            $liveByAttribute = $liveLinks->groupBy('attribute_id');
            $liveColorLinks = $liveByAttribute->get($colorAttributeId, collect());
            $liveSizeLinks = $liveByAttribute->get($sizeAttributeId, collect());

            if (
                $archiveLinks->count() !== 1
                || (int) $archiveLinks->first()->attribute_id !== $colorAttributeId
                || ! $this->looksLikeColor($archiveLinks->first()->archived_value)
                || $liveColorLinks->count() !== 1
                || ! $this->looksLikeSize($liveColorLinks->first()->live_value)
            ) {
                continue;
            }

            $size = null;
            if ($liveLinks->count() === 1) {
                // The size exists only in the wrong "Color" axis.
                $size = $liveColorLinks->first()->live_value;
            } elseif (
                $liveLinks->count() === 2
                && $liveSizeLinks->count() === 1
                && $this->looksLikeSize($liveSizeLinks->first()->live_value)
            ) {
                // A valid size already exists; remove only the extra size from Color.
                $size = $liveSizeLinks->first()->live_value;
            }

            if ($size === null) {
                continue;
            }

            $candidates->push([
                'good_id' => $archiveLinks->first()->good_id,
                'good_name' => $archiveLinks->first()->good_name,
                'variation_id' => $variationId,
                'sku' => $archiveLinks->first()->sku,
                'color' => $archiveLinks->first()->archived_value,
                'size' => $size,
                'live_links_count' => $liveLinks->count(),
            ]);
        }

        $this->table(
            ['Товар', 'SKU', 'Цвет из резерва', 'Размер из текущей записи'],
            $candidates->take(25)->map(fn (array $item) => [
                $item['good_id'], $item['sku'], $item['color'], $item['size'],
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

        $candidateVariationIds = $candidates->pluck('variation_id')->all();
        $repairBackupTable = 'shop_variation_size_color_repair_'.now()->format('Ymd_His');

        DB::statement(sprintf(
            'CREATE TABLE `%s` AS SELECT * FROM shop_variation_attributes_values WHERE variation_id IN (%s)',
            $repairBackupTable,
            implode(',', array_map('intval', $candidateVariationIds)),
        ));

        $expectedBackupRows = (int) $candidates->sum('live_links_count');
        $backupRows = (int) DB::table($repairBackupTable)->count();
        if ($backupRows !== $expectedBackupRows) {
            throw new \RuntimeException("Резервная таблица {$repairBackupTable} содержит {$backupRows} строк вместо ожидаемых {$expectedBackupRows}. Исправление отменено.");
        }

        DB::transaction(function () use ($candidates, $candidateVariationIds, $colorAttributeId, $sizeAttributeId): void {
            $now = now();

            DB::table('shop_variation_attributes_values')
                ->whereIn('variation_id', $candidateVariationIds)
                ->delete();

            foreach ($candidates as $candidate) {
                $colorValueId = DB::table('shop_variation_attribute_values')
                    ->where('attribute_id', $colorAttributeId)
                    ->where('value', $candidate['color'])
                    ->value('id');

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
                    [
                        'variation_id' => $candidate['variation_id'],
                        'attribute_value_id' => $colorValueId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                    [
                        'variation_id' => $candidate['variation_id'],
                        'attribute_value_id' => $sizeValueId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                ]);
            }
        });

        $this->info("Исправлено вариаций: {$candidates->count()}. Резервная таблица: {$repairBackupTable}");

        return self::SUCCESS;
    }

    private function looksLikeSize(string $value): bool
    {
        $sizeToken = '(?:XXS|XS|S|M|L|XL|XXL|2XL|3XL|4XL|SM|MD|LG)';

        return (bool) preg_match('/^(?:\d+(?:\s*-\s*\d+)?\s*\([^)]+\)|'.$sizeToken.'(?:\s*\/\s*'.$sizeToken.')*)$/iu', trim($value));
    }

    private function looksLikeColor(string $value): bool
    {
        return ! $this->looksLikeSize($value);
    }
}
