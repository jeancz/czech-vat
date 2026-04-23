<?php

declare(strict_types=1);

namespace JeanCz\CzechVat\Tests;

use JeanCz\CzechVat\Exception\InvalidPeriodException;
use JeanCz\CzechVat\Model\Period\TaxPeriod;
use PHPUnit\Framework\TestCase;

final class TaxPeriodTest extends TestCase
{
    public function testMonthlyPeriod(): void
    {
        $p = TaxPeriod::monthly(2025, 1);

        self::assertTrue($p->isMonthly());
        self::assertFalse($p->isQuarterly());
        self::assertSame(2025, $p->year);
        self::assertSame(1, $p->month);
        self::assertNull($p->quarter);
        self::assertSame('2025-01', (string) $p);
    }

    public function testQuarterlyPeriod(): void
    {
        $p = TaxPeriod::quarterly(2025, 2);

        self::assertFalse($p->isMonthly());
        self::assertTrue($p->isQuarterly());
        self::assertSame(2025, $p->year);
        self::assertSame(2, $p->quarter);
        self::assertNull($p->month);
        self::assertSame('2025-Q2', (string) $p);
    }

    public function testFromDate(): void
    {
        $p = TaxPeriod::fromDate(new \DateTimeImmutable('2025-03-15'));

        self::assertSame(2025, $p->year);
        self::assertSame(3, $p->month);
    }

    public function testThrowsOnInvalidMonth(): void
    {
        $this->expectException(InvalidPeriodException::class);

        TaxPeriod::monthly(2025, 13);
    }

    public function testThrowsOnInvalidQuarter(): void
    {
        $this->expectException(InvalidPeriodException::class);

        TaxPeriod::quarterly(2025, 5);
    }

    public function testStartDate(): void
    {
        $p = TaxPeriod::monthly(2025, 3);
        self::assertSame('2025-03-01', $p->startDate()->format('Y-m-d'));
    }
}
