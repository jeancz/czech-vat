<?php

declare(strict_types=1);

namespace JeanCz\CzechVat;

use JeanCz\CzechVat\Builder\ControlStatementBuilder;
use JeanCz\CzechVat\Builder\VatReturnBuilder;
use JeanCz\CzechVat\Enum\ControlStatementFilingType;
use JeanCz\CzechVat\Enum\VatReturnFilingType;
use JeanCz\CzechVat\Generator\XsdValidator;
use JeanCz\CzechVat\Model\Invoice\InvoiceCollection;
use JeanCz\CzechVat\Model\Period\TaxPeriod;
use JeanCz\CzechVat\Model\Taxpayer\Taxpayer;

/**
 * Façade for generating Czech VAT XML filings.
 *
 * This is the primary entry point for library consumers.
 *
 * Quick example
 * ─────────────
 * ```php
 * use JeanCz\CzechVat\VatFilingGenerator;
 * use JeanCz\CzechVat\Model\Taxpayer\Taxpayer;
 * use JeanCz\CzechVat\Model\Period\TaxPeriod;
 * use JeanCz\CzechVat\Model\Invoice\{Invoice, InvoiceLine, InvoiceCollection};
 *
 * $taxpayer = Taxpayer::legalEntity(
 *     vatId:        'CZ12345678',
 *     taxOfficeCode: '452',
 *     companyName:  'My Company s.r.o.',
 *     street:       'Hlavní',
 *     houseNumber:  '1',
 *     city:         'Praha',
 *     postalCode:   '11000',
 *     email:        'accounting@mycompany.cz',
 * );
 *
 * $period = TaxPeriod::monthly(2025, 1);
 *
 * $invoices = (new InvoiceCollection())
 *     ->addIssued(
 *         new Invoice(
 *             lines:         [new InvoiceLine(taxBase: 100_000, vat: 21_000, vatRate: 21)],
 *             partnerVatId:  'CZ87654321',
 *             documentNumber:'FAK-2025-001',
 *             taxPointDate:  new \DateTimeImmutable('2025-01-15'),
 *         )
 *     );
 *
 * $generator = new VatFilingGenerator($taxpayer, $period, $invoices);
 *
 * file_put_contents('kontrolni_hlaseni.xml',   $generator->generateControlStatement());
 * file_put_contents('danove_priznani_dph.xml',  $generator->generateVatReturn());
 * ```
 */
final class VatFilingGenerator
{
    public function __construct(
        private readonly Taxpayer $taxpayer,
        private readonly TaxPeriod $period,
        private readonly InvoiceCollection $invoices,
        private readonly string $softwareName    = 'jeancz/czech-vat',
        private readonly string $softwareVersion = '1.0',
    ) {
    }

    /**
     * Generate the VAT Control Statement XML (DPHKH1).
     *
     * The Control Statement is always filed for a monthly period, even for
     * quarterly VAT Return filers (they submit three monthly statements per quarter).
     */
    public function generateControlStatement(
        ControlStatementFilingType $filingType = ControlStatementFilingType::Regular,
        ?string $authorityRequestNumber = null,
    ): string {
        return (new ControlStatementBuilder(
            taxpayer:               $this->taxpayer,
            period:                 $this->period,
            invoices:               $this->invoices,
            filingType:             $filingType,
            authorityRequestNumber: $authorityRequestNumber,
            softwareName:           $this->softwareName,
            softwareVersion:        $this->softwareVersion,
        ))->generate();
    }

    /**
     * Generate the VAT Return XML (DPHDP3).
     *
     * Quarterly filers pass a quarterly TaxPeriod; monthly filers pass a monthly one.
     */
    public function generateVatReturn(
        VatReturnFilingType $filingType = VatReturnFilingType::Regular,
    ): string {
        return (new VatReturnBuilder(
            taxpayer:       $this->taxpayer,
            period:         $this->period,
            invoices:       $this->invoices,
            filingType:     $filingType,
            softwareName:   $this->softwareName,
            softwareVersion: $this->softwareVersion,
        ))->generate();
    }

    /**
     * Validate the Control Statement XML against its XSD schema.
     *
     * @throws \JeanCz\CzechVat\Exception\XmlValidationException
     */
    public function validateControlStatement(string $xml): void
    {
        XsdValidator::forControlStatement()->validate($xml);
    }

    /**
     * Validate the VAT Return XML against its XSD schema.
     *
     * @throws \JeanCz\CzechVat\Exception\XmlValidationException
     */
    public function validateVatReturn(string $xml): void
    {
        XsdValidator::forVatReturn()->validate($xml);
    }
}
