<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TestBatchImageDownload extends Command
{
    protected $signature = 'test:batch-image-download {urls*}';
    protected $description = 'Тестирует пакетную загрузку изображений';

    public function handle()
    {
        $urls = $this->argument('urls');
        
        if (empty($urls)) {
            $this->error('Необходимо указать URL изображений');
            return 1;
        }

        $this->info('Тестирование пакетной загрузки изображений...');
        $this->info('URL изображений: ' . implode(', ', $urls));

        try {
            $response = Http::post(config('app.url') . '/api/admin/shop/goods/download-images-batch', [
                'imageUrls' => $urls,
                'storagePath' => '/images/shop/goods',
                'optimize' => true,
                'naming' => 'hash',
                'resize' => 'no_change'
            ]);

            if ($response->successful()) {
                $data = $response->json();
                
                if ($data['success']) {
                    $this->info('✅ Пакетная загрузка успешна!');
                    $this->info('Всего изображений: ' . $data['data']['total']);
                    $this->info('Успешно загружено: ' . $data['data']['successful']);
                    $this->info('Ошибок: ' . $data['data']['failed']);
                    
                    if (!empty($data['data']['paths'])) {
                        $this->info('Загруженные файлы:');
                        foreach ($data['data']['paths'] as $url => $path) {
                            $this->line("  {$url} -> {$path}");
                        }
                    }
                    
                    if (!empty($data['data']['errors'])) {
                        $this->warn('Ошибки:');
                        foreach ($data['data']['errors'] as $error) {
                            $this->line("  {$error['url']}: {$error['error']}");
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
