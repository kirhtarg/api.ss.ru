<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TestBatchImageCreate extends Command
{
    protected $signature = 'test:batch-image-create {goodId} {filePaths*}';
    protected $description = 'Тестирует пакетное создание изображений товаров';

    public function handle()
    {
        $goodId = $this->argument('goodId');
        $filePaths = $this->argument('filePaths');
        
        if (empty($filePaths)) {
            $this->error('Необходимо указать пути к файлам изображений');
            return 1;
        }

        $this->info('Тестирование пакетного создания изображений...');
        $this->info('ID товара: ' . $goodId);
        $this->info('Пути к файлам: ' . implode(', ', $filePaths));

        try {
            $images = [];
            foreach ($filePaths as $index => $filePath) {
                $images[] = [
                    'good_id' => (int)$goodId,
                    'file_path' => $filePath,
                    'alt_text' => 'Тестовое изображение ' . ($index + 1),
                    'is_main' => $index === 0, // Первое изображение - главное
                    'sort_order' => $index,
                    'image_action' => 'add'
                ];
            }

            $response = Http::post(config('app.url') . '/api/admin/shop/goods/images/import-batch', [
                'images' => $images
            ]);

            if ($response->successful()) {
                $data = $response->json();
                
                if ($data['success']) {
                    $this->info('✅ Пакетное создание изображений успешно!');
                    $this->info('Всего изображений: ' . $data['data']['total']);
                    $this->info('Успешно создано: ' . $data['data']['successful']);
                    $this->info('Ошибок: ' . $data['data']['failed']);
                    
                    if (!empty($data['data']['created'])) {
                        $this->info('Созданные изображения:');
                        foreach ($data['data']['created'] as $image) {
                            $this->line("  ✅ Товар {$image['good_id']}: {$image['file_path']} (ID: {$image['image_id']})");
                        }
                    }
                    
                    if (!empty($data['data']['errors'])) {
                        $this->warn('Ошибки:');
                        foreach ($data['data']['errors'] as $error) {
                            $this->line("  ❌ Товар {$error['good_id']}: {$error['file_path']} - {$error['error']}");
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
