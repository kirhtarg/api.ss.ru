<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TestLargeBatchImageCreate extends Command
{
    protected $signature = 'test:large-batch-image-create {goodId} {count=150}';
    protected $description = 'Тестирует создание большого количества записей изображений (по умолчанию 150)';

    public function handle()
    {
        $goodId = $this->argument('goodId');
        $count = (int)$this->argument('count');
        
        if ($count < 1) {
            $this->error('Количество изображений должно быть больше 0');
            return 1;
        }

        $this->info("Тестирование создания {$count} записей изображений для товара {$goodId}...");

        // Генерируем тестовые данные
        $images = [];
        for ($i = 1; $i <= $count; $i++) {
            $images[] = [
                'good_id' => (int)$goodId,
                'file_path' => "/images/shop/goods/test_image_{$i}.jpg",
                'alt_text' => "Тестовое изображение {$i}",
                'is_main' => $i === 1, // Первое изображение - главное
                'sort_order' => $i - 1,
                'image_action' => 'add'
            ];
        }

        $this->info("Сгенерировано {$count} тестовых записей изображений");

        try {
            $startTime = microtime(true);
            
            $response = Http::timeout(300)->post(config('app.url') . '/api/admin/shop/goods/images/import-batch', [
                'images' => $images
            ]);

            $endTime = microtime(true);
            $executionTime = round(($endTime - $startTime), 2);

            if ($response->successful()) {
                $data = $response->json();
                
                if ($data['success']) {
                    $this->info('✅ Создание большого пакета записей изображений успешно!');
                    $this->info("Время выполнения: {$executionTime} секунд");
                    $this->info('Всего записей: ' . $data['data']['total']);
                    $this->info('Успешно создано: ' . $data['data']['successful']);
                    $this->info('Ошибок: ' . $data['data']['failed']);
                    $this->info('Скорость: ' . round($data['data']['successful'] / $executionTime, 2) . ' записей/сек');
                    
                    if (!empty($data['data']['created'])) {
                        $this->info('Примеры созданных записей:');
                        $created = array_slice($data['data']['created'], 0, 5);
                        foreach ($created as $image) {
                            $this->line("  ✅ Товар {$image['good_id']}: {$image['file_path']} (ID: {$image['image_id']})");
                        }
                        if (count($data['data']['created']) > 5) {
                            $this->line("  ... и еще " . (count($data['data']['created']) - 5) . " записей");
                        }
                    }
                    
                    if (!empty($data['data']['errors'])) {
                        $this->warn('Ошибки:');
                        foreach (array_slice($data['data']['errors'], 0, 5) as $error) {
                            $this->line("  ❌ Товар {$error['good_id']}: {$error['file_path']} - {$error['error']}");
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
