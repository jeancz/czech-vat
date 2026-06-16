<?php

declare(strict_types=1);

namespace JeanCz\CzechVat\Tests;

use JeanCz\CzechVat\Enum\VatRateType;
use JeanCz\CzechVat\Exception\XmlValidationException;
use JeanCz\CzechVat\Generator\XsdValidator;
use JeanCz\CzechVat\Model\Invoice\Invoice;
use JeanCz\CzechVat\Model\Invoice\InvoiceCollection;
use JeanCz\CzechVat\Model\Invoice\InvoiceLine;
use JeanCz\CzechVat\Model\Period\TaxPeriod;
use JeanCz\CzechVat\Model\Taxpayer\Taxpayer;
use JeanCz\CzechVat\Model\VatRates\VatRates;
use JeanCz\CzechVat\VatFilingGenerator;
use PHPUnit\Framework\TestCase;

final class VatFilingGeneratorTest extends TestCase
{
    private Taxpayer $taxpayer;
    private TaxPeriod $period;
    private VatRates $rates;

    protected function setUp(): void
    {
        $this->taxpayer = Taxpayer::legalEntity(
            vatId: 'CZ12345678',
            taxOfficeCode: '452',
            companyName: 'Test s.r.o.',
            street: 'Testovací',
            houseNumber: '1',
            city: 'Praha',
            postalCode: '11000',
            email: 'test@test.cz',
        );

        $this->period = TaxPeriod::monthly(2025, 1);
        $this->rates = VatRates::current();
    }

    // ------------------------------------------------------------------ helpers

    private function line(VatRateType $type, float $base, float $vat): InvoiceLine
    {
        return new InvoiceLine($type, taxBase: $base, vat: $vat, rates: $this->rates);
    }

    private function buildCollection(): InvoiceCollection
    {
        return (new InvoiceCollection())
            ->addIssued(
                new Invoice(
                    lines: [$this->line(VatRateType::Standard, 100_000, 21_000)],
                    partnerVatId: 'CZ87654321',
                    documentNumber: 'FAK-2025-001',
                    taxPointDate: new \DateTimeImmutable('2025-01-15'),
                ),
                new Invoice([$this->line(VatRateType::Standard, 5_000, 1_050)]),
            )
            ->addReceived(
                new Invoice(
                    lines: [$this->line(VatRateType::Standard, 50_000, 10_500)],
                    partnerVatId: 'CZ11111111',
                    documentNumber: 'MY-REF-001',
                    supplierDocumentNumber: 'SUP-INV-999',
                    taxPointDate: new \DateTimeImmutable('2025-01-10'),
                )
            );
    }

    // ------------------------------------------------------------------ Control Statement

    public function testControlStatementContainsPeriodAndTaxpayer(): void
    {
        $xml = (new VatFilingGenerator($this->taxpayer, $this->period, $this->buildCollection()))
            ->generateControlStatement();

        self::assertStringContainsString('rok="2025"', $xml);
        self::assertStringContainsString('mesic="1"', $xml);
        self::assertStringContainsString('dic="12345678"', $xml);
        self::assertStringContainsString('c_ufo="452"', $xml);
    }

    public function testControlStatementA4ForLargeIssuedInvoice(): void
    {
        $xml = (new VatFilingGenerator($this->taxpayer, $this->period, $this->buildCollection()))
            ->generateControlStatement();

        self::assertStringContainsString('VetaA4', $xml);
        self::assertStringContainsString('dic_odb="87654321"', $xml);
        // Standard rate → zakl_dane1
        self::assertStringContainsString('zakl_dane1="100000.00"', $xml);
        self::assertStringContainsString('dan1="21000.00"', $xml);
    }

    public function testControlStatementA5ForSmallIssuedInvoice(): void
    {
        $xml = (new VatFilingGenerator($this->taxpayer, $this->period, $this->buildCollection()))
            ->generateControlStatement();

        self::assertStringContainsString('VetaA5', $xml);
    }

    public function testControlStatementB2ForLargeReceivedInvoice(): void
    {
        $xml = (new VatFilingGenerator($this->taxpayer, $this->period, $this->buildCollection()))
            ->generateControlStatement();

        self::assertStringContainsString('VetaB2', $xml);
        self::assertStringContainsString('SUP-INV-999', $xml);
    }

    public function testReducedRateUsesZaklDane2(): void
    {
        $invoices = (new InvoiceCollection())
            ->addIssued(new Invoice([$this->line(VatRateType::Reduced, 5_000, 600)]));

        $xml = (new VatFilingGenerator($this->taxpayer, $this->period, $invoices))
            ->generateControlStatement();

        // Reduced → zakl_dane2 / dan2
        self::assertStringContainsString('VetaA5', $xml);
        self::assertStringContainsString('zakl_dane2="5000.00"', $xml);
        self::assertStringContainsString('dan2="600.00"', $xml);
        self::assertStringNotContainsString('zakl_dane1', $xml);
    }

    // ------------------------------------------------------------------ VAT Return

    public function testVatReturnContainsTaxLiability(): void
    {
        $xml = (new VatFilingGenerator($this->taxpayer, $this->period, $this->buildCollection()))
            ->generateVatReturn();

        // 22 050 output − 10 500 input = 11 550 payable
        self::assertStringContainsString('dano_da="11550"', $xml);
    }

    public function testVatReturnStandardRateMapsToObrat23(): void
    {
        $invoices = (new InvoiceCollection())
            ->addIssued(
                new Invoice(
                    [$this->line(VatRateType::Standard, 10_000, 2_100)],
                    partnerVatId: 'CZ87654321',
                    documentNumber: 'SUP-INV-999',
                    taxPointDate: new \DateTimeImmutable('2025-01-10'),
                )
            );

        $xml = (new VatFilingGenerator($this->taxpayer, $this->period, $invoices))
            ->generateVatReturn();

        self::assertStringContainsString('obrat23="10000"', $xml);
        self::assertStringContainsString('dan23="2100"', $xml);
        self::assertStringNotContainsString('obrat5', $xml);
    }

    public function testVatReturnReducedRateMapsToObrat5(): void
    {
        $invoices = (new InvoiceCollection())
            ->addIssued(new Invoice([$this->line(VatRateType::Reduced, 5_000, 600)]));

        $xml = (new VatFilingGenerator($this->taxpayer, $this->period, $invoices))
            ->generateVatReturn();

        self::assertStringContainsString('obrat5="5000"', $xml);
        self::assertStringContainsString('dan5="600"', $xml);
        self::assertStringNotContainsString('obrat23', $xml);
    }

    public function testPreReformScheduleSecondReducedMergedIntoObrat5(): void
    {
        $oldRates = VatRates::validUntil20231231();

        $invoices = (new InvoiceCollection())
            ->addIssued(
                new Invoice([
                    new InvoiceLine(VatRateType::Reduced, taxBase: 3_000, vat: 450, rates: $oldRates),
                    new InvoiceLine(VatRateType::SecondReduced, taxBase: 2_000, vat: 200, rates: $oldRates),
                ])
            );

        $xml = (new VatFilingGenerator($this->taxpayer, $this->period, $invoices))
            ->generateVatReturn();

        // Both reduced types merged → obrat5 = 5000, dan5 = 650
        self::assertStringContainsString('obrat5="5000"', $xml);
        self::assertStringContainsString('dan5="650"', $xml);
    }

    public function testQuarterlyPeriod(): void
    {
        $period = TaxPeriod::quarterly(2025, 1);
        $xml = (new VatFilingGenerator($this->taxpayer, $period, $this->buildCollection()))
            ->generateVatReturn();

        self::assertStringContainsString('ctvrt="1"', $xml);
        self::assertStringNotContainsString('mesic=', $xml);
    }

    // ------------------------------------------------------------------ Natural person fields

    private function naturalPersonTaxpayer(): Taxpayer
    {
        return Taxpayer::naturalPerson(
            vatId: 'CZ7001011234',
            taxOfficeCode: '451',
            firstName: 'Jan',
            lastName: 'Novák',
            street: 'Lipová',
            houseNumber: '3',
            city: 'Praha',
            postalCode: '13000',
            title: 'Ing.',
            phone: '123456789',
            orientationNumber: '5a',
            mainEconomicActivity: 6201,
        );
    }

    public function testNaturalPersonFieldsAppearsInControlStatement(): void
    {
        $xml = (new VatFilingGenerator($this->naturalPersonTaxpayer(), $this->period, $this->buildCollection()))
            ->generateControlStatement();

        self::assertStringContainsString('titul="Ing."', $xml);
        self::assertStringContainsString('c_telef="123456789"', $xml);
        self::assertStringContainsString('c_orient="5a"', $xml);
        // c_okec is not part of KH1 — confirm it is absent
        self::assertStringNotContainsString('c_okec', $xml);
    }

    public function testNaturalPersonFieldsAppearsInVatReturn(): void
    {
        $xml = (new VatFilingGenerator($this->naturalPersonTaxpayer(), $this->period, $this->buildCollection()))
            ->generateVatReturn();

        self::assertStringContainsString('titul="Ing."', $xml);
        self::assertStringContainsString('c_telef="123456789"', $xml);
        self::assertStringContainsString('c_orient="5a"', $xml);
        self::assertStringContainsString('c_okec="6201"', $xml);
    }

    public function testNaturalPersonOptionalFieldsOmittedWhenNull(): void
    {
        $taxpayer = Taxpayer::naturalPerson(
            vatId: 'CZ7001011234',
            taxOfficeCode: '451',
            firstName: 'Jan',
            lastName: 'Novák',
            street: 'Lipová',
            houseNumber: '3',
            city: 'Praha',
            postalCode: '13000',
        );

        $xml = (new VatFilingGenerator($taxpayer, $this->period, $this->buildCollection()))
            ->generateVatReturn();

        self::assertStringNotContainsString('titul=', $xml);
        self::assertStringNotContainsString('c_telef=', $xml);
        self::assertStringNotContainsString('c_orient=', $xml);
        self::assertStringNotContainsString('c_okec=', $xml);
    }

    public function testApprovedPersonAppearsInControlStatement(): void
    {
        $taxpayer = Taxpayer::legalEntity(
            vatId: 'CZ12345678',
            taxOfficeCode: '452',
            companyName: 'Rome s.r.o.',
            street: 'Testovací',
            houseNumber: '1',
            city: 'Praha',
            postalCode: '11000',
            email: 'test@test.cz',
            approvedPersonFirstName: 'Pilát',
            approvedPersonLastName: 'Pontský',
            approvedPersonRole: 'PREFEKT',
        );

        $xml = (new VatFilingGenerator($taxpayer, $this->period, $this->buildCollection()))
            ->generateControlStatement();

        self::assertStringContainsString('opr_jmeno="Pilát"', $xml);
        self::assertStringContainsString('opr_prijmeni="Pontský"', $xml);
        self::assertStringContainsString('opr_postaveni="PREFEKT"', $xml);

        XsdValidator::forControlStatement()->validate($xml);
    }

    public function testApprovedPersonOmittedWhenNull(): void
    {
        $xml = (new VatFilingGenerator($this->taxpayer, $this->period, $this->buildCollection()))
            ->generateControlStatement();

        self::assertStringNotContainsString('opr_jmeno', $xml);
        self::assertStringNotContainsString('opr_prijmeni', $xml);
        self::assertStringNotContainsString('opr_postaveni', $xml);
    }

    public function testNaturalPersonControlStatementPassesXsdValidation(): void
    {
        $xml = (new VatFilingGenerator($this->naturalPersonTaxpayer(), $this->period, $this->buildCollection()))
            ->generateControlStatement();

        XsdValidator::forControlStatement()->validate($xml);
        $this->addToAssertionCount(1);
    }

    public function testNaturalPersonVatReturnPassesXsdValidation(): void
    {
        $xml = (new VatFilingGenerator($this->naturalPersonTaxpayer(), $this->period, $this->buildCollection()))
            ->generateVatReturn();

        XsdValidator::forVatReturn()->validate($xml);
        $this->addToAssertionCount(1);
    }

    // ------------------------------------------------------------------ XSD validation

    public function testControlStatementPassesXsdValidation(): void
    {
        $xml = (new VatFilingGenerator($this->taxpayer, $this->period, $this->buildCollection()))
            ->generateControlStatement();

        XsdValidator::forControlStatement()->validate($xml);
        $this->addToAssertionCount(1);
    }

    public function testVatReturnPassesXsdValidation(): void
    {
        $xml = (new VatFilingGenerator($this->taxpayer, $this->period, $this->buildCollection()))
            ->generateVatReturn();

        XsdValidator::forVatReturn()->validate($xml);
        $this->addToAssertionCount(1);
    }

    public function testValidatorThrowsOnInvalidXml(): void
    {
        $this->expectException(XmlValidationException::class);

        XsdValidator::forVatReturn()->validate('<invalid>not a vat return</invalid>');
    }
}
