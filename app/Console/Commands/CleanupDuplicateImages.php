<?php

namespace App\Console\Commands;

use App\Models\ShopGoodImage;
use Illuminate\Console\Command;

class CleanupDuplicateImages extends Command
{
    protected $signature = 'images:cleanup-duplicates {--force : Выполнить реальное удаление}';
    protected $description = 'Поиск и удаление дубликатов изображений';

    public function handle()
    {
        $force = $this->option('force');
        $this->info($force ? 'ЗАПУСК ОЧИСТКИ...' : 'РЕЖИМ ПРОВЕРКИ');

        $imagesBaseDir = base_path('..' . DIRECTORY_SEPARATOR . 'admin.skateandsnow.ru' . DIRECTORY_SEPARATOR . 'public');
        
        $totalChecked = 0;
        $duplicatesFound = 0;
        $notFound = 0;
        $bytesSaved = 0;

        $groups = ShopGoodImage::select('good_id', 'variation_id')
            ->groupBy('good_id', 'variation_id')
            ->get();

        $bar = $this->output->createProgressBar(count($groups));
        $bar->start();

        foreach ($groups as $group) {
            $images = ShopGoodImage::where('good_id', $group->good_id)
                ->where('variation_id', $group->variation_id)
                ->with(['variation']) // Подгружаем вариацию для получения good_id
                ->orderBy('id', 'asc')
                ->get();

            $hashes = [];

            foreach ($images as $image) {
                $totalChecked++;
                
                $fileName = ltrim($image->file_path, '/\\');
                $fullPath = $imagesBaseDir . DIRECTORY_SEPARATOR . $fileName;

                if (!file_exists($fullPath) || is_dir($fullPath)) {
                    $notFound++;
                    continue;
                }

                $hash = md5_file($fullPath);

                if (isset($hashes[$hash])) {
                    $duplicatesFound++;
                    $bytesSaved += filesize($fullPath);

                    $targetId = $hashes[$hash];
                    
                    // Если good_id пустой, берем его из вариации
                    $goodId = $image->good_id;
                    if (!$goodId && $image->variation) {
                        $goodId = $image->variation->good_id;
                    }
                    
                    $varId = $image->variation_id ?: 'Нет';

                    if ($force) {
                        @unlink($fullPath);
                        $image->delete();
                    } else {
                        $this->line("\n[!] Дубль (Img ID: {$image->id})");
                        $this->line("    Файл: {$image->file_path}");
                        $this->line("    Товар ID: " . ($goodId ?: 'Н/Д') . " | Вариация ID: {$varId} | Идентичен Img ID: {$targetId}");
                    }
                } else {
                    $hashes[$hash] = $image->id;
                }
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        
        $this->table(
            ['Показатель', 'Значение'],
            [
                ['Всего в базе', ShopGoodImage::count() + ($force ? 0 : $duplicatesFound)],
                ['Файлы найдены', $totalChecked - $notFound],
                ['Файлы НЕ найдены', $notFound],
                ['Найдено дублей', $duplicatesFound],
                ['Экономия', round($bytesSaved / 1024 / 1024, 2) . ' MB'],
            ]
        );

        return 0;
    }
}
