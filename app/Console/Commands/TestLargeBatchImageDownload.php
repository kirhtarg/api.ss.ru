<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TestLargeBatchImageDownload extends Command
{
    protected $signature = 'test:large-batch-image-download {count=150}';
    protected $description = 'Тестирует загрузку большого количества изображений (по умолчанию 150)';

    public function handle()
    {
        $count = (int)$this->argument('count');
        
        if ($count < 1) {
            $this->error('Количество изображений должно быть больше 0');
            return 1;
        }

        $this->info("Тестирование загрузки {$count} изображений...");

        // Генерируем тестовые URL
        $imageUrls = [];
        for ($i = 1; $i <= $count; $i++) {
            $imageUrls[] = "https://via.placeholder.com/" . (800 + $i % 200) . "x" . (600 + $i % 150) . ".jpg";
        }

        $this->info("Сгенерировано {$count} тестовых URL изображений");

        try {
            $startTime = microtime(true);
            
            $response = Http::timeout(300)->post(config('app.url') . '/api/admin/shop/goods/download-images-batch', [
                'imageUrls' => $imageUrls,
                'storagePath' => '/images/shop/goods',
                'optimize' => true,
                'naming' => 'hash',
                'resize' => 'no_change'
            ]);

            $endTime = microtime(true);
            $executionTime = round(($endTime - $startTime), 2);

            if ($response->successful()) {
                $data = $response->json();
                
                if ($data['success']) {
                    $this->info('✅ Загрузка большого пакета изображений успешна!');
                    $this->info("Время выполнения: {$executionTime} секунд");
                    $this->info('Всего изображений: ' . $data['data']['total']);
                    $this->info('Успешно загружено: ' . $data['data']['successful']);
                    $this->info('Ошибок: ' . $data['data']['failed']);
                    $this->info('Скорость: ' . round($data['data']['successful'] / $executionTime, 2) . ' изображений/сек');
                    
                    if (!empty($data['data']['paths'])) {
                        $this->info('Примеры загруженных файлов:');
                        $paths = array_slice($data['data']['paths'], 0, 5);
                        foreach ($paths as $url => $path) {
                            $this->line("  ✅ {$url} -> {$path}");
                        }
                        if (count($data['data']['paths']) > 5) {
                            $this->line("  ... и еще " . (count($data['data']['paths']) - 5) . " файлов");
                        }
                    }
                    
                    if (!empty($data['data']['errors'])) {
                        $this->warn('Ошибки:');
                        foreach (array_slice($data['data']['errors'], 0, 5) as $error) {
                            $this->line("  ❌ {$error['url']}: {$error['error']}");
                        }
                        if (count($data['data']['errors']) > 5) {
                            $this->line("  ... и еще " . (count($data['data']['errors']) - 5) . " ошибок");
                        }
                    }
                } else {
                    $this->error('❌ Ошибка: ' . $data['message']);
                }
            } else {
                $this->error('❌ HTTP ошибка: ' . $response->status());
                $this->error($response->body());
            }
        } catch (\Exception $e) {
            $this->error('❌ Исключение: ' . $e->getMessage());
        }

        return 0;
    }
}
