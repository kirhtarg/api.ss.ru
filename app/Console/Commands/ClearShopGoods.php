<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ClearShopGoods extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shop:clear-goods {--force : Принудительная очистка без подтверждения} {--with-brands : Также очистить таблицу shop_brands} {--with-categories : Также очистить таблицу shop_categories}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Очистка таблицы shop_goods и всех связанных таблиц';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $withBrands = $this->option('with-brands');
        $withCategories = $this->option('with-categories');

        $message = 'ВНИМАНИЕ: Это удалит ВСЕ данные из таблицы shop_goods и всех связанных таблиц!';
        if ($withBrands) {
            $message .= ' Также будет очищена таблица shop_brands.';
        }
        if ($withCategories) {
            $message .= ' Также будет очищена таблица shop_categories.';
        }
        $message .= ' Продолжить?';

        if (! $this->option('force')) {
            if (! $this->confirm($message)) {
                $this->info('Операция отменена.');

                return;
            }
        }

        $this->info('Начинаем очистку таблицы shop_goods и связанных таблиц...');

        try {
            // Начинаем транзакцию
            DB::beginTransaction();

            // Отключаем проверку внешних ключей
            DB::statement('SET FOREIGN_KEY_CHECKS = 0');

            $tables = [
                'shop_stock_reservations' => 'резервации товаров',
                'shop_low_stock_notifications' => 'уведомления о низких остатках',
                'shop_good_audits' => 'аудит товаров',
                'shop_good_prices' => 'цены товаров',
                'shop_stocks' => 'остатки товаров',
                'shop_good_images' => 'изображения товаров',
                'shop_good_videos' => 'видео товаров',
                'shop_good_variations' => 'вариации товаров',
                'shop_variation_attributes_values' => 'значения атрибутов вариаций',
                'shop_good_properties' => 'связи товаров со свойствами',
                'shop_good_tags' => 'связи товаров с тегами',
                'shop_good_categories' => 'связи товаров с категориями',
                'shop_good_brands' => 'связи товаров с брендами',
                'shop_goods' => 'товары',
            ];

            // Добавляем дополнительные таблицы по запросу
            if ($withBrands) {
                $tables['shop_brands'] = 'бренды';
            }
            if ($withCategories) {
                $tables['shop_categories'] = 'категории';
            }

            $totalDeleted = 0;
            $progressBar = $this->output->createProgressBar(count($tables));
            $progressBar->start();

            foreach ($tables as $table => $description) {
                $count = DB::table($table)->count();
                if ($count > 0) {
                    DB::table($table)->truncate();
                    $totalDeleted += $count;
                }
                $progressBar->advance();
            }

            $progressBar->finish();
            $this->newLine();

            // Включаем обратно проверку внешних ключей
            DB::statement('SET FOREIGN_KEY_CHECKS = 1');

            // Подтверждаем транзакцию
            DB::commit();

            // Сбрасываем автоинкремент для основных таблиц (вне транзакции)
            $autoIncrementTables = [
                'shop_goods',
                'shop_good_images',
                'shop_good_videos',
                'shop_good_variations',
                'shop_good_prices',
                'shop_stocks',
                'shop_stock_reservations',
                'shop_good_audits',
                'shop_low_stock_notifications',
            ];

            // Добавляем дополнительные таблицы по запросу
            if ($withBrands) {
                $autoIncrementTables[] = 'shop_brands';
            }
            if ($withCategories) {
                $autoIncrementTables[] = 'shop_categories';
            }

            $this->info('Сбрасываем автоинкремент...');
            foreach ($autoIncrementTables as $table) {
                try {
                    // Проверяем существование таблицы перед сбросом автоинкремента
                    $exists = DB::select("SHOW TABLES LIKE '{$table}'");
                    if (! empty($exists)) {
                        DB::statement("ALTER TABLE {$table} AUTO_INCREMENT = 1");
                    }
                } catch (\Exception $e) {
                    $this->warn("Не удалось сбросить автоинкремент для таблицы {$table}: ".$e->getMessage());
                }
            }

            $this->newLine();
            $this->info('✅ Очистка завершена успешно!');
            $this->info("Всего удалено записей: {$totalDeleted}");
            $this->info('Автоинкремент сброшен для всех таблиц.');

        } catch (\Exception $e) {
            // Откатываем транзакцию в случае ошибки
            DB::rollBack();

            $this->error('❌ Ошибка при очистке: '.$e->getMessage());
            $this->error('Все изменения отменены.');

            return 1;
        }

        return 0;
    }
}
