<?php

namespace App\Console\Commands;

use App\Services\HotspotVoucherLifecycleService;
use Illuminate\Console\Command;

class MaintainHotspotVouchers extends Command
{
    protected $signature = 'billing:hotspot-maintain';
    protected $description = 'Terapkan masa berlaku, pemakaian, dan FUP voucher Hotspot';

    public function handle(HotspotVoucherLifecycleService $lifecycle): int
    {
        $count = $lifecycle->maintain();
        $this->info("{$count} voucher Hotspot diperiksa.");
        return self::SUCCESS;
    }
}
