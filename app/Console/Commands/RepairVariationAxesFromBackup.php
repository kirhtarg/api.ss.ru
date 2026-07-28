<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RepairVariationAxesFromBackup extends Command
{
    protected $signature = 'shop:repair-variation-axes-from-backup
                            {backupTable : Таблица из первой очистки связей вариаций}
                            {--execute : Исправить только однозначно распознанные товары}';

    protected $description = 'Восстанавливает цвет и размер из резервной таблицы только для безопасно распознанных товаров';

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
            ->orderBy('g.id')
            ->orderBy('v.id')
            ->get([
                'g.id as good_id',
                'g.name as good_name',
                'v.id as variation_id',
                'v.sku',
                'av.attribute_id',
                'av.value',
            ]);

        if ($archived->isEmpty()) {
            $this->comment('В резервной таблице нет связей с существующими вариациями.');

            return self::SUCCESS;
        }

        $variationIds = $archived->pluck('variation_id')->unique()->values();
        $live = DB::table('shop_variation_attributes_values as vav')
            ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
            ->whereIn('vav.variation_id', $variationIds)
            ->orderBy('vav.variation_id')
            ->get(['vav.variation_id', 'av.attribute_id', 'av.value'])
            ->groupBy('variation_id');

        $variations = DB::table('shop_good_variations')
            ->whereIn('id', $variationIds)
            ->get(['id', 'good_id', 'sku'])
            ->groupBy('good_id');

        $archivedByGood = $archived->groupBy('good_id');
        $candidates = collect();

        foreach ($archivedByGood as $goodId => $archivedRows) {
            $goodVariations = $variations->get($goodId, collect());
            if ($goodVariations->isEmpty()) {
                continue;
            }

            $goodVariationIds = $goodVariations->pluck('id')->sort()->values();
            $archivedByVariation = $archivedRows->groupBy('variation_id');

            if ($archivedByVariation->keys()->sort()->values()->all() !== $goodVariationIds->all()) {
                continue;
            }

            $colors = [];
            $sizes = [];
            $safe = true;

            foreach ($goodVariations as $variation) {
                $archiveLinks = $archivedByVariation->get($variation->id, collect());
                $liveLinks = $live->get($variation->id, collect());

                if (
                    $archiveLinks->count() !== 1
                    || $liveLinks->count() !== 1
                    || (int) $archiveLinks->first()->attribute_id !== $colorAttributeId
                    || (int) $liveLinks->first()->attribute_id !== $colorAttributeId
                ) {
                    $safe = false;
                    break;
                }

                $colors[$variation->id] = trim((string) $archiveLinks->first()->value);
                $sizes[$variation->id] = trim((string) $liveLinks->first()->value);
            }

            if (
                ! $safe
                || collect($colors)->contains(fn (string $value) => ! $this->looksLikeColor($value))
                || collect($sizes)->contains(fn (string $value) => ! $this->looksLikeSize($value))
            ) {
                continue;
            }

            $candidates->push([
                'good_id' => (int) $goodId,
                'good_name' => $archivedRows->first()->good_name,
                'colors' => $colors,
                'variation_ids' => $goodVariationIds->all(),
                'sizes' => $sizes,
            ]);
        }

        $this->table(
            ['Товар', 'Название', 'Цвета', 'Вариаций', 'Размеры'],
            $candidates->map(fn (array $candidate) => [
                $candidate['good_id'],
                $candidate['good_name'],
                implode(', ', array_values(array_unique($candidate['colors']))),
                count($candidate['variation_ids']),
                implode(', ', array_values(array_unique($candidate['sizes']))),
            ])->all()
        );

        $this->line('Однозначно распознано товаров: '.$candidates->count());
        $this->line('Связей из резервной таблицы без безопасного правила: '.($archived->count() - $candidates->sum(fn (array $candidate) => count($candidate['variation_ids']))));

        if (! $this->option('execute') || $candidates->isEmpty()) {
            $this->comment('Режим проверки. Для исправления распознанных товаров используйте --execute.');

            return self::SUCCESS;
        }

        $candidateVariationIds = $candidates->flatMap(fn (array $candidate) => $candidate['variation_ids'])->values()->all();
        $repairBackupTable = 'shop_variation_axis_batch_repair_'.now()->format('Ymd_His');

        DB::statement(sprintf(
            'CREATE TABLE `%s` AS SELECT * FROM shop_variation_attributes_values WHERE variation_id IN (%s)',
            $repairBackupTable,
            implode(',', array_map('intval', $candidateVariationIds)),
        ));

        $backupRows = (int) DB::table($repairBackupTable)->count();
        if ($backupRows !== count($candidateVariationIds)) {
            throw new \RuntimeException("Резервная таблица {$repairBackupTable} содержит {$backupRows} строк вместо ожидаемых ".count($candidateVariationIds).'. Исправление отменено.');
        }

        DB::transaction(function () use ($candidates, $candidateVariationIds, $colorAttributeId, $sizeAttributeId): void {
            $now = now();

            DB::table('shop_variation_attributes_values')
                ->whereIn('variation_id', $candidateVariationIds)
                ->delete();

            foreach ($candidates as $candidate) {
                foreach ($candidate['sizes'] as $variationId => $size) {
                    $colorValueId = DB::table('shop_variation_attribute_values')
                        ->where('attribute_id', $colorAttributeId)
                        ->where('value', $candidate['colors'][$variationId])
                        ->value('id');

                    $sizeValueId = DB::table('shop_variation_attribute_values')
                        ->where('attribute_id', $sizeAttributeId)
                        ->where('value', $size)
                        ->value('id');

                    if (! $sizeValueId) {
                        $sizeValueId = DB::table('shop_variation_attribute_values')->insertGetId([
                            'attribute_id' => $sizeAttributeId,
                            'value' => $size,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }

                    DB::table('shop_variation_attributes_values')->insert([
                        [
                            'variation_id' => $variationId,
                            'attribute_value_id' => $colorValueId,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ],
                        [
                            'variation_id' => $variationId,
                            'attribute_value_id' => $sizeValueId,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ],
                    ]);
                }
            }
        });

        $this->info("Исправлено товаров: {$candidates->count()}. Резервная таблица: {$repairBackupTable}");

        return self::SUCCESS;
    }

    private function looksLikeSize(string $value): bool
    {
        return (bool) preg_match(
            '/^(?:\d+(?:\s*-\s*\d+)?(?:\s*\([^)]+\))?|(?:XXS|XS|S|M|L|XL|XXL|2XL|3XL|4XL))$/iu',
            trim($value)
        );
    }

    private function looksLikeColor(string $value): bool
    {
        return ! $this->looksLikeSize($value);
    }
}
