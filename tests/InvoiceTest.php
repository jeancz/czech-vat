<?php

declare(strict_types=1);

namespace JeanCz\CzechVat\Tests;

use JeanCz\CzechVat\Enum\VatRateType;
use JeanCz\CzechVat\Exception\InvalidInvoiceException;
use JeanCz\CzechVat\Model\Invoice\Invoice;
use JeanCz\CzechVat\Model\Invoice\InvoiceLine;
use JeanCz\CzechVat\Model\VatRates\VatRates;
use PHPUnit\Framework\TestCase;

final class InvoiceTest extends TestCase
{
    private VatRates $rates;

    protected function setUp(): void
    {
        $this->rates = VatRates::current();
    }

    private function line(VatRateType $type, float $base, float $vat): InvoiceLine
    {
        return new InvoiceLine($type, taxBase: $base, vat: $vat, rates: $this->rates);
    }

    public function testSmallInvoiceDoesNotRequireDetailedReporting(): void
    {
        $invoice = new Invoice([$this->line(VatRateType::Standard, 5000, 1050)]);

        self::assertFalse($invoice->requiresDetailedControlStatementReporting());
    }

    public function testLargeInvoiceRequiresDetailedReporting(): void
    {
        $invoice = new Invoice(
            lines:         [$this->line(VatRateType::Standard, 100_000, 21_000)],
            partnerVatId:  'CZ99999999',
            documentNumber:'FAK-001',
            taxPointDate:  new \DateTimeImmutable('2025-01-15'),
        );

        self::assertTrue($invoice->requiresDetailedControlStatementReporting());
    }

    public function testThrowsOnEmptyLines(): void
    {
        $this->expectException(InvalidInvoiceException::class);

        new Invoice([]);
    }

    public function testTotals(): void
    {
        $invoice = new Invoice(
            lines:         [
                $this->line(VatRateType::Standard, 1000, 210),
                $this->line(VatRateType::Reduced, 500, 60),
            ],
            partnerVatId:  'CZ00000000',
            documentNumber:'FAK-XXL',
            taxPointDate:  new \DateTimeImmutable('2025-01-01'),
        );

        self::assertSame(1500.0, $invoice->totalTaxBase());
        self::assertSame(270.0, $invoice->totalVat());
        self::assertSame(1770.0, $invoice->totalIncludingVat());
    }

    public function testGroupByVatRateTypeUsesSemanticKeys(): void
    {
        $invoice = new Invoice(
            lines:         [
                $this->line(VatRateType::Standard, 1000, 210),
                $this->line(VatRateType::Standard, 2000, 420),
                $this->line(VatRateType::Reduced, 500, 60),
            ],
            partnerVatId:  'CZ00000000',
            documentNumber:'FAK-GRP',
            taxPointDate:  new \DateTimeImmutable('2025-01-01'),
        );

        $groups = $invoice->groupByVatRateType();

        // Keys are semantic type strings, not percentages
        self::assertArrayHasKey(VatRateType::Standard->value, $groups);
        self::assertArrayHasKey(VatRateType::Reduced->value, $groups);
        self::assertArrayNotHasKey('21', $groups);
        self::assertArrayNotHasKey('12', $groups);

        self::assertSame(3000.0, $groups[VatRateType::Standard->value]['taxBase']);
        self::assertSame(630.0,  $groups[VatRateType::Standard->value]['vat']);
        self::assertSame(500.0,  $groups[VatRateType::Reduced->value]['taxBase']);
    }
}
