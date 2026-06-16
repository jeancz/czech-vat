<?php

declare(strict_types=1);

namespace JeanCz\CzechVat\Builder;

use JeanCz\CzechVat\Contract\XmlGeneratorInterface;
use JeanCz\CzechVat\Enum\ControlStatementFilingType;
use JeanCz\CzechVat\Enum\VatRateType;
use JeanCz\CzechVat\Model\Invoice\Invoice;
use JeanCz\CzechVat\Model\Invoice\InvoiceCollection;
use JeanCz\CzechVat\Model\Invoice\InvoiceLine;
use JeanCz\CzechVat\Model\Period\TaxPeriod;
use JeanCz\CzechVat\Model\Taxpayer\Taxpayer;

/**
 * Builds the Czech VAT Control Statement XML (DPHKH1).
 *
 * Structure overview
 * ──────────────────
 *  Pisemnost
 *   └─ DPHKH1
 *       ├─ VetaD   (filing metadata / period)
 *       ├─ VetaP   (taxpayer identification)
 *       ├─ VetaA1* (issued — reverse-charge, line-by-line)
 *       ├─ VetaA4* (issued — taxable over threshold, line-by-line)
 *       ├─ VetaA5  (issued — taxable under threshold, aggregate)
 *       ├─ VetaB1* (received — reverse-charge, line-by-line)
 *       ├─ VetaB2* (received — over threshold, line-by-line)
 *       ├─ VetaB3  (received — under threshold, aggregate)
 *       └─ VetaC   (cross-check totals)
 */
final class ControlStatementBuilder extends AbstractXmlBuilder implements XmlGeneratorInterface
{
    private const string SCHEMA_VERSION = '03.01';

    public function __construct(
        private readonly Taxpayer $taxpayer,
        private readonly TaxPeriod $period,
        private readonly InvoiceCollection $invoices,
        private readonly ControlStatementFilingType $filingType = ControlStatementFilingType::Regular,
        private readonly ?string $authorityRequestNumber = null,
        string $softwareName    = 'jeancz/czech-vat',
        string $softwareVersion = '1.0',
    ) {
        parent::__construct($softwareName, $softwareVersion);
    }

    // ------------------------------------------------------------------ public

    public function generate(): string
    {
        $root = $this->buildRoot();
        $kh   = $this->buildKh1($root);

        $this->buildVetaD($kh);
        $this->buildVetaP($kh);
        $this->buildIssuedSections($kh);
        $this->buildReceivedSections($kh);
        $this->buildVetaC($kh);

        return $this->saveXml();
    }

    // ------------------------------------------------------------------ DOM builders

    private function buildRoot(): \DOMElement
    {
        $root = $this->dom->createElement('Pisemnost');
        $root->setAttribute('nazevSW', $this->softwareName);
        $root->setAttribute('verzeSW', $this->softwareVersion);
        $this->dom->appendChild($root);

        return $root;
    }

    private function buildKh1(\DOMElement $root): \DOMElement
    {
        $kh = $this->dom->createElement('DPHKH1');
        $kh->setAttribute('verzePis', self::SCHEMA_VERSION);
        $root->appendChild($kh);

        return $kh;
    }

    private function buildVetaD(\DOMElement $kh): void
    {
        $el = $this->dom->createElement('VetaD');

        $el->setAttribute('dokument', 'KH1');
        $el->setAttribute('k_uladis', 'DPH');
        $el->setAttribute('khdph_forma', $this->filingType->value);
        $el->setAttribute('rok', (string) $this->period->year);
        $el->setAttribute('d_poddp', $this->today());

        if ($this->period->isMonthly()) {
            $el->setAttribute('mesic', (string) $this->period->month);
        } else {
            $el->setAttribute('ctvrt', (string) $this->period->quarter);
        }

        if ($this->filingType === ControlStatementFilingType::Subsequent
            || $this->filingType === ControlStatementFilingType::SubsequentCorrective) {
            $this->setAttr($el, 'c_jed_vyzvy', $this->authorityRequestNumber);
        }

        $kh->appendChild($el);
    }

    private function buildVetaP(\DOMElement $kh): void
    {
        $t  = $this->taxpayer;
        $el = $this->dom->createElement('VetaP');

        $el->setAttribute('c_ufo', $t->taxOfficeCode);
        $el->setAttribute('dic', $t->dicNumeric);
        $el->setAttribute('typ_ds', $t->taxpayerType->value);

        $this->setAttr($el, 'c_pracufo', $t->taxOfficeWorkplaceCode);

        if ($t->isLegalEntity()) {
            $this->setAttr($el, 'zkrobchjm', $t->companyName);
        } else {
            $this->setAttr($el, 'titul',    $t->title);
            $this->setAttr($el, 'jmeno',    $t->firstName);
            $this->setAttr($el, 'prijmeni', $t->lastName);
        }

        $this->setAttr($el, 'ulice',    $t->street);
        $this->setAttr($el, 'c_orient', $t->orientationNumber);
        $this->setAttr($el, 'c_pop',    $t->houseNumber);
        $this->setAttr($el, 'naz_obce', $t->city);
        $this->setAttr($el, 'psc',      $t->postalCode);
        $this->setAttr($el, 'stat',     $t->country);
        $this->setAttr($el, 'email',    $t->email);
        $this->setAttr($el, 'c_telef',  $t->phone);

        // Authorised person (oprávněná osoba), e.g. statutory representative of a legal entity
        $this->setAttr($el, 'opr_jmeno',     $t->approvedPersonFirstName);
        $this->setAttr($el, 'opr_prijmeni',  $t->approvedPersonLastName);
        $this->setAttr($el, 'opr_postaveni', $t->approvedPersonRole);

        $kh->appendChild($el);
    }

    // ---- issued ------------------------------------------------------------

    private function buildIssuedSections(\DOMElement $kh): void
    {
        $rowIdx = 1;

        [$overThreshold, $underThreshold] = $this->splitByThreshold($this->invoices->issued());

        // A.1 — reverse-charge issued, over threshold
        foreach (array_filter($overThreshold, static fn(Invoice $i): bool => $i->isReverseCharge) as $invoice) {
            $rowIdx = $this->buildVetaA1($kh, $invoice, $rowIdx);
        }

        // A.4 — standard taxable issued, over threshold
        foreach (array_filter($overThreshold, static fn(Invoice $i): bool => !$i->isReverseCharge) as $invoice) {
            $rowIdx = $this->buildVetaA4($kh, $invoice, $rowIdx);
        }

        // A.5 — under threshold (aggregate)
        $this->buildVetaA5($kh, array_filter($underThreshold, static fn(Invoice $i): bool => !$i->isReverseCharge));
    }

    private function buildVetaA1(\DOMElement $kh, Invoice $invoice, int $rowIdx): int
    {
        foreach ($invoice->lines() as $line) {
            $el = $this->dom->createElement('VetaA1');
            $el->setAttribute('c_radku', (string) $rowIdx++);
            $el->setAttribute('dic_odb', $invoice->partnerVatIdNumeric() ?? '');
            $el->setAttribute('c_evid_dd', $invoice->documentNumber ?? '');
            $el->setAttribute('duzp', $invoice->taxPointDate !== null ? $this->formatDate($invoice->taxPointDate) : '');
            $el->setAttribute('zakl_dane1', $this->formatDecimal($line->taxBase));
            $el->setAttribute('kod_pred_pl', '1');
            $kh->appendChild($el);
        }

        return $rowIdx;
    }

    private function buildVetaA4(\DOMElement $kh, Invoice $invoice, int $rowIdx): int
    {
        foreach ($invoice->lines() as $line) {
            $el = $this->dom->createElement('VetaA4');
            $el->setAttribute('c_radku', (string) $rowIdx++);
            $el->setAttribute('dic_odb', $invoice->partnerVatIdNumeric() ?? '');
            $el->setAttribute('c_evid_dd', $invoice->documentNumber ?? '');
            $el->setAttribute('dppd', $invoice->taxPointDate !== null ? $this->formatDate($invoice->taxPointDate) : '');
            $el->setAttribute('kod_rezim_pl', '0');
            $el->setAttribute('zdph_44', 'N');
            $this->setLineVatAttributes($el, $line);
            $kh->appendChild($el);
        }

        return $rowIdx;
    }

    /** @param Invoice[] $invoices */
    private function buildVetaA5(\DOMElement $kh, array $invoices): void
    {
        if ($invoices === []) {
            return;
        }

        $el = $this->dom->createElement('VetaA5');
        $this->setAggregateVatAttributes($el, InvoiceCollection::aggregateByVatRateType(array_values($invoices)));
        $kh->appendChild($el);
    }

    // ---- received ----------------------------------------------------------

    private function buildReceivedSections(\DOMElement $kh): void
    {
        $rowIdx = 1;

        [$overThreshold, $underThreshold] = $this->splitByThreshold($this->invoices->received());

        // B.1 — reverse-charge received, over threshold
        foreach (array_filter($overThreshold, static fn(Invoice $i): bool => $i->isReverseCharge) as $invoice) {
            $rowIdx = $this->buildVetaB1($kh, $invoice, $rowIdx);
        }

        // B.2 — standard received, over threshold
        foreach (array_filter($overThreshold, static fn(Invoice $i): bool => !$i->isReverseCharge) as $invoice) {
            $rowIdx = $this->buildVetaB2($kh, $invoice, $rowIdx);
        }

        // B.3 — under threshold (aggregate)
        $this->buildVetaB3($kh, array_filter($underThreshold, static fn(Invoice $i): bool => !$i->isReverseCharge));
    }

    private function buildVetaB1(\DOMElement $kh, Invoice $invoice, int $rowIdx): int
    {
        foreach ($invoice->lines() as $line) {
            $el = $this->dom->createElement('VetaB1');
            $el->setAttribute('c_radku', (string) $rowIdx++);
            $el->setAttribute('dic_dod', $invoice->partnerVatIdNumeric() ?? '');
            $el->setAttribute('c_evid_dd', $invoice->supplierDocumentNumber ?? $invoice->documentNumber ?? '');
            $el->setAttribute('duzp', $invoice->taxPointDate !== null ? $this->formatDate($invoice->taxPointDate) : '');
            $el->setAttribute('kod_pred_pl', '1');
            $this->setLineVatAttributes($el, $line);
            $kh->appendChild($el);
        }

        return $rowIdx;
    }

    private function buildVetaB2(\DOMElement $kh, Invoice $invoice, int $rowIdx): int
    {
        foreach ($invoice->lines() as $line) {
            $el = $this->dom->createElement('VetaB2');
            $el->setAttribute('c_radku', (string) $rowIdx++);
            $el->setAttribute('dic_dod', $invoice->partnerVatIdNumeric() ?? '');
            $el->setAttribute('c_evid_dd', $invoice->supplierDocumentNumber ?? $invoice->documentNumber ?? '');
            $el->setAttribute('dppd', $invoice->taxPointDate !== null ? $this->formatDate($invoice->taxPointDate) : '');
            $el->setAttribute('pomer', 'N');
            $el->setAttribute('zdph_44', 'N');
            $this->setLineVatAttributes($el, $line);
            $kh->appendChild($el);
        }

        return $rowIdx;
    }

    /** @param Invoice[] $invoices */
    private function buildVetaB3(\DOMElement $kh, array $invoices): void
    {
        if ($invoices === []) {
            return;
        }

        $el = $this->dom->createElement('VetaB3');
        $this->setAggregateVatAttributes($el, InvoiceCollection::aggregateByVatRateType(array_values($invoices)));
        $kh->appendChild($el);
    }

    // ---- cross-check -------------------------------------------------------

    private function buildVetaC(\DOMElement $kh): void
    {
        $issuedTotals   = InvoiceCollection::aggregateByVatRateType($this->invoices->issued());
        $receivedTotals = InvoiceCollection::aggregateByVatRateType($this->invoices->received());

        $el = $this->dom->createElement('VetaC');

        // Standard rate issued tax bases → obrat23
        if (isset($issuedTotals[VatRateType::Standard->value])) {
            $this->setAttr($el, 'obrat23', $this->formatDecimal($issuedTotals[VatRateType::Standard->value]['taxBase']));
        }

        // All reduced rate issued tax bases → obrat5
        $reducedIssuedBase = ($issuedTotals[VatRateType::Reduced->value]['taxBase'] ?? 0.0)
                           + ($issuedTotals[VatRateType::SecondReduced->value]['taxBase'] ?? 0.0);
        if ($reducedIssuedBase !== 0.0) {
            $this->setAttr($el, 'obrat5', $this->formatDecimal($reducedIssuedBase));
        }

        // Standard rate received tax bases → pln23
        if (isset($receivedTotals[VatRateType::Standard->value])) {
            $this->setAttr($el, 'pln23', $this->formatDecimal($receivedTotals[VatRateType::Standard->value]['taxBase']));
        }

        // All reduced rate received tax bases → pln5
        $reducedReceivedBase = ($receivedTotals[VatRateType::Reduced->value]['taxBase'] ?? 0.0)
                             + ($receivedTotals[VatRateType::SecondReduced->value]['taxBase'] ?? 0.0);
        if ($reducedReceivedBase !== 0.0) {
            $this->setAttr($el, 'pln5', $this->formatDecimal($reducedReceivedBase));
        }

        $kh->appendChild($el);
    }

    // ---- attribute helpers -------------------------------------------------

    /**
     * Set zakl_dane{N} / dan{N} attributes on an element based on VatRateType.
     * The index (1/2/3) is derived from the semantic type via epoAttributeIndex().
     */
    private function setLineVatAttributes(\DOMElement $el, InvoiceLine $line): void
    {
        $idx = $line->vatRateType->epoAttributeIndex();

        if ($idx === null) {
            // Zero-rated lines are not reported in taxable-supply rows
            return;
        }

        $el->setAttribute("zakl_dane{$idx}", $this->formatDecimal($line->taxBase));
        $el->setAttribute("dan{$idx}",       $this->formatDecimal($line->vat));
    }

    /**
     * Set aggregate zakl_dane{N}/dan{N} attributes from grouped totals.
     *
     * @param array<string, array{taxBase: float, vat: float}> $totals  keyed by VatRateType::value
     */
    private function setAggregateVatAttributes(\DOMElement $el, array $totals): void
    {
        foreach (VatRateType::cases() as $type) {
            $idx = $type->epoAttributeIndex();

            if ($idx === null || !isset($totals[$type->value])) {
                continue;
            }

            $el->setAttribute("zakl_dane{$idx}", $this->formatDecimal($totals[$type->value]['taxBase']));
            $el->setAttribute("dan{$idx}",       $this->formatDecimal($totals[$type->value]['vat']));
        }
    }

    // ---- utility -----------------------------------------------------------

    /**
     * Split invoices into [overThreshold[], underThreshold[]].
     *
     * @param  Invoice[] $invoices
     * @return array{Invoice[], Invoice[]}
     */
    private function splitByThreshold(array $invoices): array
    {
        $over  = [];
        $under = [];

        foreach ($invoices as $invoice) {
            if ($invoice->requiresDetailedControlStatementReporting()) {
                $over[] = $invoice;
            } else {
                $under[] = $invoice;
            }
        }

        return [$over, $under];
    }
}
