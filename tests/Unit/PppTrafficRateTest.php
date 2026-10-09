<?php

namespace Tests\Unit;

use App\Support\PppTrafficRate;
use PHPUnit\Framework\TestCase;

class PppTrafficRateTest extends TestCase
{
    public function test_it_converts_byte_counter_deltas_to_bits_per_second(): void
    {
        $rate = PppTrafficRate::fromSamples(
            ['sampled_at' => 100, 'download_bytes' => 1000, 'upload_bytes' => 500],
            ['sampled_at' => 130, 'download_bytes' => 3_751_000, 'upload_bytes' => 1_875_500],
        );

        self::assertSame(['download_bps' => 1_000_000, 'upload_bps' => 500_000], $rate);
    }

    public function test_it_discards_short_long_and_reset_counter_samples(): void
    {
        $previous = ['sampled_at' => 100, 'download_bytes' => 1000, 'upload_bytes' => 500];
        self::assertNull(PppTrafficRate::fromSamples($previous, ['sampled_at' => 104, 'download_bytes' => 2000, 'upload_bytes' => 1000]));
        self::assertNull(PppTrafficRate::fromSamples($previous, ['sampled_at' => 221, 'download_bytes' => 2000, 'upload_bytes' => 1000]));
        self::assertNull(PppTrafficRate::fromSamples($previous, ['sampled_at' => 130, 'download_bytes' => 900, 'upload_bytes' => 1000]));
    }
}

