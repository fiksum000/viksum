<?php

namespace App\Support;

final class WhatsappNumber
{
    public static function normalize(?string $number): ?string
    {
        if ($number === null || trim($number) === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $number) ?? '';
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '0')) {
            return '62'.substr($digits, 1);
        }

        if (str_starts_with($digits, '8')) {
            return '62'.$digits;
        }

        return $digits;
    }

    public static function isValid(?string $number): bool
    {
        return $number === null || preg_match('/^628[0-9]{7,12}$/', $number) === 1;
    }
}

