<?php

namespace App\Services;

use InvalidArgumentException;

final class Money
{
    public static function minor(string $amount): int
    {
        if (! preg_match('/^\d{1,10}(\.\d{1,2})?$/', $amount)) {
            throw new InvalidArgumentException('Invalid money amount');
        }$p = explode('.', $amount);

        return ((int) $p[0]) * 100 + (int) str_pad($p[1] ?? '', 2, '0');
    }

    public static function decimal(int $minor): string
    {
        if ($minor < 0) {
            throw new InvalidArgumentException('Negative money');
        }

return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function discount(int $minor, int $percent): int
    {
        if ($percent < 0 || $percent > 100) {
            throw new InvalidArgumentException('Invalid discount');
        }

return $minor - intdiv($minor * $percent + 50, 100);
    }

    public static function tax(int $minor, int $basisPoints): int
    {
        return intdiv($minor * $basisPoints + 5000, 10000);
    }
}
