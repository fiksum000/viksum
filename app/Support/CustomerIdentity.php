<?php

namespace App\Support;

use Illuminate\Support\Str;

final class CustomerIdentity
{
    public static function newCustomerCode(): string
    {
        return (string) random_int(100000000, 999999999);
    }

    public static function newPortalPassword(): string
    {
        return Str::random(8);
    }
}
