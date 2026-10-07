<?php

namespace Tests\Feature;

use App\Services\FupService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FupBillingPeriodTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_fup_period_changes_on_the_tenth_of_each_month(): void
    {
        config(['billing.timezone' => 'Asia/Jakarta']);
        $fup = app(FupService::class);

        Carbon::setTestNow(Carbon::parse('2026-10-09 23:59:59', 'Asia/Jakarta'));
        $this->assertSame('2026-09', $fup->currentPeriod());

        Carbon::setTestNow(Carbon::parse('2026-10-10 00:00:00', 'Asia/Jakarta'));
        $this->assertSame('2026-10', $fup->currentPeriod());
    }
}
