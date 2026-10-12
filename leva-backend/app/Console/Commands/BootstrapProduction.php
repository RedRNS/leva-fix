<?php

namespace App\Console\Commands;

use App\Models\ScrapedTool;
use Database\Seeders\ScrapedToolsSeeder;
use Illuminate\Console\Command;

class BootstrapProduction extends Command
{
    protected $signature = 'leva:bootstrap';

    protected $description = 'Isi katalog tools awal jika masih kosong (aman dijalankan berulang)';

    public function handle(): int
    {
        if (ScrapedTool::query()->exists()) {
            $this->info('Katalog tools sudah terisi, dilewati.');

            return self::SUCCESS;
        }

        $this->call('db:seed', ['--class' => ScrapedToolsSeeder::class, '--force' => true]);

        return self::SUCCESS;
    }
}
