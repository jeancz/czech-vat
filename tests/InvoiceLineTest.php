<?php

declare(strict_types=1);

namespace JeanCz\CzechVat\Tests;

use JeanCz\CzechVat\Enum\VatRateType;
use JeanCz\CzechVat\Exception\InvalidInvoiceException;
use JeanCz\CzechVat\Model\Invoice\InvoiceLine;
use JeanCz\CzechVat\Model\VatRates\VatRates;
use PHPUnit\Framework\TestCase;

final class InvoiceLineTest extends TestCase
{
    private VatRates $rates;

    protected function setUp(): void
    {
        $this->rates = VatRates::current();
    }

    public function testCreatesLineWithCorrectValues(): void
    {
        $line = new InvoiceLine(VatRateType::Standard, taxBase: 1000.0, vat: 210.0, rates: $this->rates);

        self::assertSame(1000.0, $line->taxBase);
        self::assertSame(210.0, $line->vat);
        self::assertSame(VatRateType::Standard, $line->vatRateType);
        self::assertSame(21, $line->vatPercentage);
        self::assertSame(1210.0, $line->totalIncludingVat());
    }

    public function testFactoryComputesVatFromTaxBase(): void
    {
        $line = InvoiceLine::fromTaxBase(VatRateType::Standard, taxBase: 1000.0, rates: $this->rates);

        self::assertSame(1000.0, $line->taxBase);
        self::assertSame(210.0, $line->vat);
    }

    public function testReducedRateResolvesCorrectPercentage(): void
    {
        $line = InvoiceLine::fromTaxBase(VatRateType::Reduced, taxBase: 1000.0, rates: $this->rates);

        self::assertSame(12, $line->vatPercentage);
        self::assertSame(120.0, $line->vat);
    }

    public function testCustomRateSchedule(): void
    {
        $customRates = VatRates::custom([
            VatRateType::Standard->value => 20,
            VatRateType::Reduced->value  => 10,
            VatRateType::Zero->value   => 0,
        ]);

        $line = InvoiceLine::fromTaxBase(VatRateType::Standard, taxBase: 1000.0, rates: $customRates);

        self::assertSame(20, $line->vatPercentage);
        self::assertSame(200.0, $line->vat);
    }

    public function testCreditNoteWithNegativeBase(): void
    {
        $line = new InvoiceLine(VatRateType::Standard, taxBase: -1000.0, vat: -210.0, rates: $this->rates);

        self::assertSame(-1210.0, $line->totalIncludingVat());
    }

    public function testThrowsOnNegativeBaseWithPositiveVat(): void
    {
        $this->expectException(InvalidInvoiceException::class);

        new InvoiceLine(VatRateType::Standard, taxBase: -1000.0, vat: 210.0, rates: $this->rates);
    }

    public function testThrowsWhenRateTypeNotInSchedule(): void
    {
        $this->expectException(\JeanCz\CzechVat\Exception\InvalidVatRateException::class);

        // Current schedule does not have SecondReduced
        new InvoiceLine(VatRateType::SecondReduced, taxBase: 1000.0, vat: 100.0, rates: $this->rates);
    }
}
