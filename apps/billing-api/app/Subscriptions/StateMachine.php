<?php

namespace App\Subscriptions;

use DomainException;

final class StateMachine
{
    private const NEXT = ['pending' => ['active', 'trialing', 'cancelled', 'expired'], 'trialing' => ['active', 'pending', 'expired', 'cancelled'], 'active' => ['past_due', 'cancelled', 'expired', 'suspended'], 'past_due' => ['grace_period', 'active', 'cancelled', 'suspended'], 'grace_period' => ['suspended', 'active', 'cancelled'], 'suspended' => ['active', 'cancelled', 'expired'], 'cancelled' => [], 'expired' => []];

    public static function assert(string $from, string $to): void
    {
        if ($from !== $to && ! in_array($to, self::NEXT[$from] ?? [], true)) {
            throw new DomainException("Cannot transition $from to $to");
        }
    }
}
