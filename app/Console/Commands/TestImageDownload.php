<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Http\Controllers\Admin\ShopGoodsController;
use Illuminate\Http\Request;

class TestImageDownload extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:image-download {url} {--path=/shop/goods} {--optimize=true}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test image download functionality';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $url = $this->argument('url');
        $path = $this->option('path');
        $optimize = $this->option('optimize') === 'true';

        $this->info("Testing image download...");
        $this->info("URL: {$url}");
        $this->info("Path: {$path}");
        $this->info("Optimize: " . ($optimize ? 'Yes' : 'No'));

        // Создаем mock request
        $request = new Request();
        $request->merge([
            'imageUrl' => $url,
            'storagePath' => $path,
            'optimize' => $optimize
        ]);

        // Создаем экземпляр контроллера
        $controller = new ShopGoodsController();

        try {
            $response = $controller->downloadImage($request);
            $data = $response->getData(true);

            if ($data['success']) {
                $this->info("✅ Success!");
                $this->info("Path: " . $data['data']['path']);
                $this->info("Size: " . number_format($data['data']['size']) . " bytes");
                $this->info("Optimized: " . ($data['data']['optimized'] ? 'Yes' : 'No'));
            } else {
                $this->error("❌ Failed: " . $data['message']);
            }
        } catch (\Exception $e) {
            $this->error("❌ Exception: " . $e->getMessage());
        }
    }
}
