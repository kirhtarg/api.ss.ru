<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class AuditVariationRepairBackup extends Command
{
    protected $signature = 'shop:audit-variation-repair-backup
                            {backupTable : Таблица из первой очистки связей вариаций}';

    protected $description = 'Создаёт CSV-аудит текущих и архивных осей вариаций без изменения данных';

    public function handle(): int
    {
        $backupTable = (string) $this->argument('backupTable');
        if (! preg_match('/^[A-Za-z0-9_]+$/', $backupTable) || ! Schema::hasTable($backupTable)) {
            $this->error('Указанная резервная таблица не найдена.');

            return self::FAILURE;
        }

        $attributes = DB::table('shop_variation_attributes')->pluck('name', 'id');
        $colorAttributeId = $attributes->search('Цвет', true);
        $sizeAttributeId = $attributes->search('Размер', true);

        $archivedRows = DB::table("{$backupTable} as b")
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

        $variationIds = $archivedRows->pluck('variation_id')->unique()->values();
        $liveRows = DB::table('shop_variation_attributes_values as vav')
            ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
            ->whereIn('vav.variation_id', $variationIds)
            ->orderBy('vav.variation_id')
            ->get(['vav.variation_id', 'av.attribute_id', 'av.value'])
            ->groupBy('variation_id');

        $archivedByVariation = $archivedRows->groupBy('variation_id');
        $summary = [];
        $csvRows = [];

        foreach ($archivedByVariation as $variationId => $rows) {
            $live = $liveRows->get($variationId, collect());
            $classification = $this->classify($live, $rows, $colorAttributeId, $sizeAttributeId);
            $summary[$classification['code']] = ($summary[$classification['code']] ?? 0) + 1;

            $csvRows[] = [
                $rows->first()->good_id,
                $rows->first()->good_name,
                $variationId,
                $rows->first()->sku,
                $this->formatAxes($live, $attributes),
                $this->formatAxes($rows, $attributes),
                $classification['label'],
                $classification['recommendation'],
            ];
        }

        $relativePath = 'variation-repair-audit/variation_repair_audit_'.now()->format('Ymd_His').'.csv';
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['ID товара', 'Товар', 'ID вариации', 'SKU', 'Текущие оси', 'Оси из резерва', 'Статус', 'Рекомендация'], ';');
        foreach ($csvRows as $csvRow) {
            fputcsv($handle, $csvRow, ';');
        }
        rewind($handle);
        Storage::disk('public')->put($relativePath, stream_get_contents($handle));
        fclose($handle);

        $this->table(
            ['Статус', 'Вариаций'],
            collect($summary)->map(fn (int $count, string $code) => [$this->statusLabel($code), $count])->values()->all()
        );
        $this->newLine();
        $this->info('CSV-аудит: storage/app/public/'.$relativePath);
        $this->line('URL: '.Storage::disk('public')->url($relativePath));

        return self::SUCCESS;
    }

    private function classify($live, $archived, $colorAttributeId, $sizeAttributeId): array
    {
        if ($live->isEmpty()) {
            return ['code' => 'no_current_axes', 'label' => 'Нет текущих осей', 'recommendation' => 'Восстановить по исходному файлу импорта.'];
        }

        $byAttribute = $live->groupBy('attribute_id');
        if ($byAttribute->contains(fn ($values) => $values->count() > 1)) {
            return ['code' => 'duplicate_axis', 'label' => 'Повтор значения одной оси', 'recommendation' => 'Требуется проверка: не выбирать значение автоматически.'];
        }

        // The initial cleanup archive has the original color. If it is present in
        // the current color axis and the current size axis has a valid value, the
        // repair is complete and must not be reported as an unresolved difference.
        if ($colorAttributeId !== false && $sizeAttributeId !== false && $archived->count() === 1) {
            $archivedColor = $archived->first();
            $currentColors = $byAttribute->get($colorAttributeId, collect())->pluck('value');
            $currentSizes = $byAttribute->get($sizeAttributeId, collect())->pluck('value');

            if (
                (int) $archivedColor->attribute_id === (int) $colorAttributeId
                && $currentColors->contains($archivedColor->value)
                && $currentSizes->contains(fn (string $value) => $this->looksLikeSize($value))
            ) {
                return ['code' => 'repaired', 'label' => 'Исправлено корректно', 'recommendation' => 'Действия не требуются.'];
            }
        }

        if ($colorAttributeId !== false) {
            $colorValues = $byAttribute->get($colorAttributeId, collect())->pluck('value');
            if ($colorValues->contains(fn (string $value) => $this->looksLikeSize($value))) {
                return ['code' => 'size_in_color', 'label' => 'Размер записан как цвет', 'recommendation' => 'Можно сопоставить цвет из резерва и перенести размер в ось «Размер».'];
            }
        }

        if ($sizeAttributeId !== false) {
            $sizeValues = $byAttribute->get($sizeAttributeId, collect())->pluck('value');
            if ($sizeValues->contains(fn (string $value) => ! $this->looksLikeSize($value))) {
                return ['code' => 'unrecognized_size', 'label' => 'Необычное значение размера', 'recommendation' => 'Сверить с исходным файлом импорта.'];
            }
        }

        return ['code' => 'review', 'label' => 'Нужна сверка', 'recommendation' => 'Текущие и архивные данные различаются, но безопасного правила нет.'];
    }

    private function formatAxes($rows, $attributes): string
    {
        return $rows->map(fn ($row) => ($attributes[$row->attribute_id] ?? 'Характеристика #'.$row->attribute_id).': '.$row->value)
            ->implode(' | ');
    }

    private function looksLikeSize(string $value): bool
    {
        return (bool) preg_match('/^(?:\d+(?:\s*-\s*\d+)?\s*\([^)]+\)|(?:XXS|XS|S|M|L|XL|XXL|2XL|3XL|4XL))$/iu', trim($value));
    }

    private function statusLabel(string $code): string
    {
        return match ($code) {
            'repaired' => 'Исправлено корректно',
            'no_current_axes' => 'Нет текущих осей',
            'duplicate_axis' => 'Повтор значения одной оси',
            'size_in_color' => 'Размер записан как цвет',
            'unrecognized_size' => 'Необычное значение размера',
            default => 'Нужна сверка',
        };
    }
}
