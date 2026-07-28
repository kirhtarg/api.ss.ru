<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RepairProductVariationAxes extends Command
{
    protected $signature = 'shop:repair-product-variation-axes
                            {goodId : ID товара}
                            {--constant-attribute=Цвет : Характеристика с постоянным значением}
                            {--constant-value= : Постоянное значение, например Black}
                            {--source-attribute=Цвет : Текущая ошибочная характеристика значений}
                            {--target-attribute=Размер : Характеристика, в которую перенести значения}
                            {--execute : Выполнить исправление после предварительной проверки}';

    protected $description = 'Исправляет вариации товара, где значения одной оси ошибочно записаны в другую';

    public function handle(): int
    {
        $goodId = (int) $this->argument('goodId');
        $constantAttributeName = trim((string) $this->option('constant-attribute'));
        $constantValue = trim((string) $this->option('constant-value'));
        $sourceAttributeName = trim((string) $this->option('source-attribute'));
        $targetAttributeName = trim((string) $this->option('target-attribute'));

        if ($constantValue === '') {
            $this->error('Укажите --constant-value, например --constant-value=Black.');

            return self::FAILURE;
        }

        $attributeIds = DB::table('shop_variation_attributes')
            ->whereIn('name', [$constantAttributeName, $sourceAttributeName, $targetAttributeName])
            ->pluck('id', 'name');

        foreach ([$constantAttributeName, $sourceAttributeName, $targetAttributeName] as $attributeName) {
            if (! isset($attributeIds[$attributeName])) {
                $this->error("Характеристика «{$attributeName}» не найдена.");

                return self::FAILURE;
            }
        }

        $constantAttributeId = (int) $attributeIds[$constantAttributeName];
        $sourceAttributeId = (int) $attributeIds[$sourceAttributeName];
        $targetAttributeId = (int) $attributeIds[$targetAttributeName];

        $constantValueId = DB::table('shop_variation_attribute_values')
            ->where('attribute_id', $constantAttributeId)
            ->where('value', $constantValue)
            ->value('id');

        if (! $constantValueId) {
            $this->error("Значение «{$constantValue}» не найдено у характеристики «{$constantAttributeName}».");

            return self::FAILURE;
        }

        $variations = DB::table('shop_good_variations')
            ->where('good_id', $goodId)
            ->orderBy('id')
            ->get(['id', 'sku']);

        if ($variations->isEmpty()) {
            $this->error("У товара {$goodId} нет вариаций.");

            return self::FAILURE;
        }

        $linksByVariation = DB::table('shop_variation_attributes_values as vav')
            ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
            ->whereIn('vav.variation_id', $variations->pluck('id'))
            ->orderBy('vav.variation_id')
            ->get(['vav.id', 'vav.variation_id', 'av.attribute_id', 'av.value'])
            ->groupBy('variation_id');

        $repairRows = new Collection();
        foreach ($variations as $variation) {
            $links = $linksByVariation->get($variation->id, collect());

            if ($links->count() !== 1 || (int) $links->first()->attribute_id !== $sourceAttributeId) {
                $this->error("Вариация {$variation->id} не соответствует безопасному условию ремонта: ожидалось ровно одно значение «{$sourceAttributeName}».");

                return self::FAILURE;
            }

            $repairRows->push([
                'variation_id' => $variation->id,
                'sku' => $variation->sku,
                'value' => $links->first()->value,
            ]);
        }

        $this->table(['Вариация', 'SKU', "Будет перенесено в {$targetAttributeName}"], $repairRows->all());

        if (! $this->option('execute')) {
            $this->comment('Режим проверки. Для выполнения добавьте --execute.');

            return self::SUCCESS;
        }

        $backupTable = 'shop_variation_axis_repair_'.$goodId.'_'.now()->format('Ymd_His');
        $variationIds = $variations->pluck('id')->all();

        DB::statement(sprintf(
            'CREATE TABLE `%s` AS SELECT * FROM shop_variation_attributes_values WHERE variation_id IN (%s)',
            $backupTable,
            implode(',', array_map('intval', $variationIds)),
        ));

        $backupRows = (int) DB::table($backupTable)->count();
        if ($backupRows !== $repairRows->count()) {
            throw new \RuntimeException("Резервная таблица {$backupTable} содержит {$backupRows} строк вместо {$repairRows->count()}. Исправление отменено.");
        }

        DB::transaction(function () use ($variationIds, $repairRows, $constantValueId, $targetAttributeId): void {
            $now = now();

            DB::table('shop_variation_attributes_values')
                ->whereIn('variation_id', $variationIds)
                ->delete();

            foreach ($repairRows as $row) {
                $targetValueId = DB::table('shop_variation_attribute_values')
                    ->where('attribute_id', $targetAttributeId)
                    ->where('value', $row['value'])
                    ->value('id');

                if (! $targetValueId) {
                    $targetValueId = DB::table('shop_variation_attribute_values')->insertGetId([
                        'attribute_id' => $targetAttributeId,
                        'value' => $row['value'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                DB::table('shop_variation_attributes_values')->insert([
                    [
                        'variation_id' => $row['variation_id'],
                        'attribute_value_id' => $constantValueId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                    [
                        'variation_id' => $row['variation_id'],
                        'attribute_value_id' => $targetValueId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                ]);
            }
        });

        $this->info("Исправлено вариаций: {$repairRows->count()}. Резервная таблица: {$backupTable}");

        return self::SUCCESS;
    }
}
