<?php

namespace App\Console\Commands;

use App\Services\FupService;
use Illuminate\Console\Command;

class ResetMonthlyFup extends Command
{
    protected $signature = 'billing:fup-reset';
    protected $description = 'Pulihkan profile normal dan reset pemakaian FUP bulan sebelumnya';

    public function handle(FupService $fup): int
    {
        $count = $fup->resetMonthly();
        $this->info("{$count} status FUP direset; profile normal dipulihkan jika perlu.");
        return self::SUCCESS;
    }
}
