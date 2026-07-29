<?php

namespace App\Console\Commands;

use App\Models\ShopGoodVariation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupOrphanGoodVariations extends Command
{
    protected $signature = 'shop:cleanup-orphan-variations
                            {--execute : Создать резервную таблицу и удалить осиротевшие вариации}';

    protected $description = 'Проверяет и безопасно удаляет вариации без существующего родительского товара';

    public function handle(): int
    {
        $orphans = DB::table('shop_good_variations as v')
            ->leftJoin('shop_goods as g', 'g.id', '=', 'v.good_id')
            ->whereNull('g.id')
            ->orderBy('v.id')
            ->get(['v.id', 'v.good_id', 'v.sku', 'v.supplier']);

        $this->table(['ID вариации', 'ID товара', 'SKU', 'Поставщик'], $orphans->map(fn ($row) => [
            $row->id, $row->good_id, $row->sku, $row->supplier,
        ]));
        $this->newLine();
        $this->line('Осиротевших вариаций: '.$orphans->count());

        if ($orphans->isEmpty()) {
            $this->info('Очистка не требуется. Теперь можно применить миграцию внешнего ключа.');

            return self::SUCCESS;
        }

        if (! $this->option('execute')) {
            $this->warn('Режим проверки. Для резервного удаления используйте --execute.');

            return self::SUCCESS;
        }

        $backupTable = 'shop_orphan_variations_'.now()->format('Ymd_His');
        $variationIds = $orphans->pluck('id')->map(fn ($id) => (int) $id)->all();

        // MySQL commits DDL implicitly, so create the archive before the
        // transactional delete instead of pretending both operations are atomic.
        DB::statement("CREATE TABLE `{$backupTable}` AS SELECT * FROM `shop_good_variations` WHERE `id` IN (".implode(',', $variationIds).')');

        DB::transaction(function () use ($variationIds): void {
            ShopGoodVariation::query()
                ->whereIn('id', $variationIds)
                ->get()
                ->each
                ->delete();
        });

        $remaining = DB::table('shop_good_variations as v')
            ->leftJoin('shop_goods as g', 'g.id', '=', 'v.good_id')
            ->whereNull('g.id')
            ->count();

        if ($remaining > 0) {
            $this->error("После очистки осталось осиротевших вариаций: {$remaining}.");

            return self::FAILURE;
        }

        $this->info("Удалено вариаций: ".count($variationIds).". Резервная таблица: {$backupTable}");
        $this->line('Теперь примените только защитную миграцию внешнего ключа.');

        return self::SUCCESS;
    }
}
