<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AnalyzeVariationRepairBackup extends Command
{
    protected $signature = 'shop:analyze-variation-repair-backup
                            {backupTable : Таблица из первой очистки связей вариаций}
                            {--limit=25 : Количество групп для вывода}';

    protected $description = 'Группирует неоднозначные архивные связи вариаций по структуре осей без изменения данных';

    public function handle(): int
    {
        $backupTable = (string) $this->argument('backupTable');
        if (! preg_match('/^[A-Za-z0-9_]+$/', $backupTable) || ! Schema::hasTable($backupTable)) {
            $this->error('Указанная резервная таблица не найдена.');

            return self::FAILURE;
        }

        $attributes = DB::table('shop_variation_attributes')->pluck('name', 'id');
        $archive = DB::table("{$backupTable} as b")
            ->join('shop_good_variations as v', 'v.id', '=', 'b.variation_id')
            ->join('shop_goods as g', 'g.id', '=', 'v.good_id')
            ->join('shop_variation_attribute_values as av', 'av.id', '=', 'b.attribute_value_id')
            ->get([
                'g.id as good_id', 'g.name as good_name', 'v.id as variation_id', 'v.sku',
                'av.attribute_id', 'av.value',
            ])
            ->groupBy('variation_id');

        $live = DB::table('shop_variation_attributes_values as vav')
            ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
            ->whereIn('vav.variation_id', $archive->keys()->all())
            ->get(['vav.variation_id', 'av.attribute_id', 'av.value'])
            ->groupBy('variation_id');

        $groups = [];
        foreach ($archive as $variationId => $archiveRows) {
            $liveRows = $live->get($variationId, collect());
            if ($this->hasClearlyValidCurrentAxes($liveRows, $attributes)) {
                $kind = 'Текущие оси корректны, архивные связи устарели';
            } else {
                $kind = 'Требуется сверка';
            }

            $signature = $kind.' | архив: '.$this->axisSignature($archiveRows, $attributes)
                .' | сейчас: '.$this->axisSignature($liveRows, $attributes);

            if (! isset($groups[$signature])) {
                $groups[$signature] = [
                    'kind' => $kind,
                    'archive' => $this->axisSignature($archiveRows, $attributes),
                    'current' => $this->axisSignature($liveRows, $attributes),
                    'count' => 0,
                    'samples' => [],
                ];
            }

            $groups[$signature]['count']++;
            if (count($groups[$signature]['samples']) < 3) {
                $groups[$signature]['samples'][] = (string) ($archiveRows->first()->sku ?: '#'.$variationId);
            }
        }

        $rows = collect($groups)
            ->sortByDesc('count')
            ->take(max(1, (int) $this->option('limit')))
            ->map(fn (array $group) => [
                $group['kind'],
                $group['archive'],
                $group['current'],
                $group['count'],
                implode(', ', $group['samples']),
            ])
            ->values()
            ->all();

        $this->table(['Статус', 'Оси из резерва', 'Текущие оси', 'Вариаций', 'Примеры SKU'], $rows);
        $this->line('Всего структур: '.count($groups));

        return self::SUCCESS;
    }

    private function axisSignature($rows, $attributes): string
    {
        if ($rows->isEmpty()) {
            return 'нет осей';
        }

        return $rows
            ->groupBy('attribute_id')
            ->map(function ($values, $attributeId) use ($attributes) {
                $name = $attributes[$attributeId] ?? 'Характеристика #'.$attributeId;
                $types = $values->map(fn ($row) => $this->looksLikeSize($row->value) ? 'размер' : 'значение')
                    ->countBy()
                    ->map(fn ($count, $type) => $type.' x'.$count)
                    ->implode(', ');

                return $name.' ['.$types.']';
            })
            ->implode(' | ');
    }

    private function hasClearlyValidCurrentAxes($rows, $attributes): bool
    {
        $byName = $rows->groupBy(fn ($row) => $attributes[$row->attribute_id] ?? (string) $row->attribute_id);
        $colors = $byName->get('Цвет', collect());
        $sizes = $byName->get('Размер', collect());

        return $colors->count() === 1
            && $sizes->count() === 1
            && ! $this->looksLikeSize($colors->first()->value)
            && $this->looksLikeSize($sizes->first()->value);
    }

    private function looksLikeSize(string $value): bool
    {
        $sizeToken = '(?:XXS|XS|S|M|М|L|XL|XXL|2XL|3XL|4XL|SM|MD|LG|OS|NS|UNI|ONE\s+SIZE)';

        return (bool) preg_match('/^(?:ДЕТ\s+.+|\d+(?:[.,]\d+)?\s*-\s*\d+(?:[.,]\d+)?|\d+(?:\s*-\s*\d+)?\s*\([^)]+\)|\d+(?:[.,]\d+)?(?:\s*-\s*\d+(?:[.,]\d+)?)?\s*(?:ML|L|KGS?|G|MM|CM|M|IN)(?:\s*\/\s*\d+(?:[.,]\d+)?(?:\s*-\s*\d+(?:[.,]\d+)?)?\s*(?:ML|L|KGS?|G|MM|CM|M|IN))*|'.$sizeToken.'(?:\s*\/\s*'.$sizeToken.')*|OS\s*-\s*(?:LEFT|RIGHT)|(?:SHORT|REGULAR|LONG|TALL)\s*-\s*\d+)$/iu', trim($value));
    }
}
