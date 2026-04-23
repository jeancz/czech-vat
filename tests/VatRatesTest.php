<?php

declare(strict_types=1);

namespace JeanCz\CzechVat\Tests;

use JeanCz\CzechVat\Enum\VatRateType;
use JeanCz\CzechVat\Exception\InvalidVatRateException;
use JeanCz\CzechVat\Model\VatRates\VatRates;
use PHPUnit\Framework\TestCase;

final class VatRatesTest extends TestCase
{
    public function testCurrentScheduleHasCorrectRates(): void
    {
        $rates = VatRates::current();

        self::assertSame(21, $rates->percentage(VatRateType::Standard));
        self::assertSame(12, $rates->percentage(VatRateType::Reduced));
        self::assertSame(0,  $rates->percentage(VatRateType::Zero));
    }

    public function testCurrentScheduleHasNoSecondReduced(): void
    {
        $this->expectException(InvalidVatRateException::class);

        VatRates::current()->percentage(VatRateType::SecondReduced);
    }

    public function testPreReformScheduleHasSecondReduced(): void
    {
        $rates = VatRates::validUntil20231231();

        self::assertSame(21, $rates->percentage(VatRateType::Standard));
        self::assertSame(15, $rates->percentage(VatRateType::Reduced));
        self::assertSame(10, $rates->percentage(VatRateType::SecondReduced));
        self::assertSame(0,  $rates->percentage(VatRateType::Zero));
    }

    public function testResolvePercentageToType(): void
    {
        $rates = VatRates::current();

        self::assertSame(VatRateType::Standard, $rates->resolve(21));
        self::assertSame(VatRateType::Reduced,  $rates->resolve(12));
        self::assertSame(VatRateType::Zero,     $rates->resolve(0));
    }

    public function testResolveThrowsForUnknownPercentage(): void
    {
        $this->expectException(InvalidVatRateException::class);
        $this->expectExceptionMessageMatches('/10.*valid rates/i');

        VatRates::current()->resolve(10);
    }

    public function testCustomSchedule(): void
    {
        $rates = VatRates::custom([
            VatRateType::Standard->value => 23,
            VatRateType::Reduced->value  => 9,
            VatRateType::Zero->value     => 0,
        ]);

        self::assertSame(23, $rates->percentage(VatRateType::Standard));
        self::assertSame(9,  $rates->percentage(VatRateType::Reduced));
        self::assertTrue($rates->has(23));
        self::assertFalse($rates->has(21));
    }

    public function testCustomScheduleThrowsOnNegativeRate(): void
    {
        $this->expectException(InvalidVatRateException::class);

        VatRates::custom([VatRateType::Standard->value => -1]);
    }

    public function testHasType(): void
    {
        $rates = VatRates::current();

        self::assertTrue($rates->hasType(VatRateType::Standard));
        self::assertFalse($rates->hasType(VatRateType::SecondReduced));
    }
}
