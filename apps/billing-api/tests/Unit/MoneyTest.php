<?php

namespace Tests\Unit;

use App\Services\Money;
use App\Subscriptions\StateMachine;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public static function decimals(): array
    {
        return [['0.00', 0], ['0.01', 1], ['199.99', 19999], ['1234567890.12', 123456789012], ['9.1', 910]];
    }

    #[DataProvider('decimals')]
    public function test_money_roundtrip(string $decimal, int $minor): void
    {
        $this->assertSame($minor, Money::minor($decimal));
        $this->assertSame($minor, Money::minor(Money::decimal($minor)));
    }

    public function test_discount_and_tax_rounding(): void
    {
        $this->assertSame(17999, Money::discount(19999, 10));
        $this->assertSame(2700, Money::tax(17999, 1500));
        $this->assertSame('206.99', Money::decimal(17999 + 2700));
    }

    public function test_invalid_money_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::minor('1e3');
    }

    public function test_negative_money_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::minor('-1.00');
    }

    public function test_over_discount_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::discount(100, 101);
    }

    public function test_valid_state_transitions(): void
    {
        StateMachine::assert('pending', 'active');
        StateMachine::assert('active', 'past_due');
        StateMachine::assert('past_due', 'grace_period');
        StateMachine::assert('grace_period', 'suspended');
        StateMachine::assert('suspended', 'active');
        $this->assertTrue(true);
    }

    public function test_terminal_state_does_not_reactivate(): void
    {
        $this->expectException(\DomainException::class);
        StateMachine::assert('cancelled', 'active');
    }

    public function test_month_end_does_not_overflow(): void
    {
        $this->assertSame('2027-02-28', CarbonImmutable::parse('2027-01-31')->addMonthNoOverflow()->format('Y-m-d'));
        $this->assertSame('2029-02-28', CarbonImmutable::parse('2028-02-29')->addYearNoOverflow()->format('Y-m-d'));
    }
}
