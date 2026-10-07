<?php

namespace Tests\Feature;

use App\Support\WhatsappNumber;
use PHPUnit\Framework\TestCase;

class WhatsappNumberTest extends TestCase
{
    public function test_indonesian_mobile_number_formats_normalize_to_country_code_62(): void
    {
        $this->assertSame('628123456789', WhatsappNumber::normalize('0812 3456 789'));
        $this->assertSame('628123456789', WhatsappNumber::normalize('+62 812-3456-789'));
        $this->assertSame('628123456789', WhatsappNumber::normalize('0081 234 56789'));
    }

    public function test_empty_numbers_are_nullable_and_invalid_numbers_fail_validation(): void
    {
        $this->assertNull(WhatsappNumber::normalize('  '));
        $this->assertTrue(WhatsappNumber::isValid('628123456789'));
        $this->assertFalse(WhatsappNumber::isValid('62812'));
        $this->assertFalse(WhatsappNumber::isValid('08123456789'));
    }
}
