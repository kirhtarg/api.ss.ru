<?php

namespace App\Console\Commands;

use App\Services\YmlFeedService;
use Illuminate\Console\Command;

class GenerateYmlFeed extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shop:generate-yml';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Генерация YML фида для Яндекс.Маркета';

    /**
     * Execute the console command.
     *
     * @param  YmlFeedService  $ymlService
     * @return int
     */
    public function handle(YmlFeedService $ymlService)
    {
        $this->info('Начало генерации YML фида...');
        
        $result = $ymlService->generate();

        if ($result['success']) {
            $this->info('YML фид успешно сгенерирован!');
            $this->table(
                ['Параметр', 'Значение'],
                [
                    ['Файл', $result['filename']],
                    ['Дата', $result['generated_at']],
                    ['Размер', $result['size']],
                    ['Кол-во товаров', $result['count']],
                ]
            );
            return Command::SUCCESS;
        }

        $this->error('Ошибка при генерации YML фида: ' . $result['message']);
        return Command::FAILURE;
    }
}
