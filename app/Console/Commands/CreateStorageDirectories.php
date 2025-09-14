<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class CreateStorageDirectories extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'storage:create-directories';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create necessary storage directories for the application';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $directories = [
            'storage/app/public/images/shop/goods',
            'storage/app/public/images/shop/categories',
            'storage/app/public/images/shop/brands',
            'storage/app/public/images/good_texts',
            'storage/app/public/import-logs',
            'storage/logs',
        ];

        $this->info('Creating storage directories...');

        foreach ($directories as $directory) {
            $fullPath = base_path($directory);
            
            if (!File::exists($fullPath)) {
                if (File::makeDirectory($fullPath, 0755, true)) {
                    $this->info("✓ Created directory: {$directory}");
                } else {
                    $this->error("✗ Failed to create directory: {$directory}");
                }
            } else {
                $this->line("→ Directory already exists: {$directory}");
            }
        }

        // Set proper permissions
        $this->info('Setting permissions...');
        
        foreach ($directories as $directory) {
            $fullPath = base_path($directory);
            if (File::exists($fullPath)) {
                chmod($fullPath, 0755);
                $this->info("✓ Set permissions for: {$directory}");
            }
        }

        $this->info('Storage directories setup completed!');
        
        return Command::SUCCESS;
    }
}
