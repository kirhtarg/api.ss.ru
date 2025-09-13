<?php

namespace App\Console\Commands;

use App\Models\ShopGood;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GenerateGoodSlugs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'goods:generate-slugs';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate slugs for existing goods that don\'t have them';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Generating slugs for goods...');

        $goodsWithoutSlugs = ShopGood::whereNull('slug')
            ->orWhere('slug', '')
            ->get();

        $this->info("Found {$goodsWithoutSlugs->count()} goods without slugs");

        $bar = $this->output->createProgressBar($goodsWithoutSlugs->count());
        $bar->start();

        $updated = 0;
        foreach ($goodsWithoutSlugs as $good) {
            $baseSlug = Str::slug($good->name);
            $slug = $baseSlug;
            $counter = 1;

            // Проверяем уникальность slug
            while (ShopGood::where('slug', $slug)->where('id', '!=', $good->id)->exists()) {
                $slug = $baseSlug . '-' . $counter;
                $counter++;
            }

            $good->update(['slug' => $slug]);
            $updated++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Updated {$updated} goods with slugs");

        return Command::SUCCESS;
    }
}
