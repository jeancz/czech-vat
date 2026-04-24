<?php

declare(strict_types=1);

namespace JeanCz\CzechVat\Builder;

use JeanCz\CzechVat\Contract\XmlGeneratorInterface;
use JeanCz\CzechVat\Enum\VatRateType;
use JeanCz\CzechVat\Enum\VatReturnFilingType;
use JeanCz\CzechVat\Model\Invoice\InvoiceCollection;
use JeanCz\CzechVat\Model\Period\TaxPeriod;
use JeanCz\CzechVat\Model\Taxpayer\Taxpayer;

/**
 * Builds the Czech VAT Return XML (DPHDP3).
 *
 * Structure overview
 * ──────────────────
 *  Pisemnost
 *   └─ DPHDP3
 *       ├─ VetaD   (filing metadata / period)
 *       ├─ VetaP   (taxpayer identification)
 *       ├─ Veta1   (taxable supplies on output — rows 1/2)
 *       ├─ Veta4   (deductible input VAT — rows 40/41/46/47)
 *       └─ Veta6   (final summary — rows 62/63/64/65)
 */
final class VatReturnBuilder extends AbstractXmlBuilder implements XmlGeneratorInterface
{
    private const string SCHEMA_VERSION = '03.01';

    public function __construct(
        private readonly Taxpayer $taxpayer,
        private readonly TaxPeriod $period,
        private readonly InvoiceCollection $invoices,
        private readonly VatReturnFilingType $filingType = VatReturnFilingType::Regular,
        string $softwareName    = 'jeancz/czech-vat',
        string $softwareVersion = '1.0',
    ) {
        parent::__construct($softwareName, $softwareVersion);
    }

    // ------------------------------------------------------------------ public

    public function generate(): string
    {
        $root = $this->buildRoot();
        $dp3  = $this->buildDp3($root);

        $this->buildVetaD($dp3);
        $this->buildVetaP($dp3);
        $this->buildVeta1($dp3);
        $this->buildVeta4($dp3);
        $this->buildVeta6($dp3);

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

    private function buildDp3(\DOMElement $root): \DOMElement
    {
        $dp3 = $this->dom->createElement('DPHDP3');
        $dp3->setAttribute('verzePis', self::SCHEMA_VERSION);
        $root->appendChild($dp3);

        return $dp3;
    }

    private function buildVetaD(\DOMElement $dp3): void
    {
        $el = $this->dom->createElement('VetaD');

        $el->setAttribute('dokument', 'DP3');
        $el->setAttribute('k_uladis', 'DPH');
        $el->setAttribute('dapdph_forma', $this->filingType->value);
        $el->setAttribute('rok', (string) $this->period->year);
        $el->setAttribute('d_poddp', $this->today());
        $el->setAttribute('typ_platce', $this->taxpayer->vatPayerType->value);

        if ($this->period->isMonthly()) {
            $el->setAttribute('mesic', (string) $this->period->month);
        } else {
            $el->setAttribute('ctvrt', (string) $this->period->quarter);
        }

        $hasLiability = $this->totalOutputVat() !== 0.0 || $this->totalInputVat() !== 0.0;
        $el->setAttribute('trans', $hasLiability ? 'A' : 'N');

        if (!$this->taxpayer->isLegalEntity()) {
            $this->setAttr($el, 'c_okec', $this->taxpayer->mainEconomicActivity);
        }

        $dp3->appendChild($el);
    }

    private function buildVetaP(\DOMElement $dp3): void
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

        $dp3->appendChild($el);
    }

    /**
     * Veta1 — output VAT on taxable supplies (rows 1 and 2).
     *
     * EPO attribute naming:
     *   obrat23 / dan23  → Standard  (základní sazba)
     *   obrat5  / dan5   → Reduced + SecondReduced merged (snížená sazba)
     */
    private function buildVeta1(\DOMElement $dp3): void
    {
        $totals = InvoiceCollection::aggregateByVatRateType($this->invoices->issued());

        if ($totals === []) {
            return;
        }

        $el = $this->dom->createElement('Veta1');

        // Standard rate slot
        if (isset($totals[VatRateType::Standard->value])) {
            $this->setAttr($el, 'obrat23', $this->formatWholeCrowns($totals[VatRateType::Standard->value]['taxBase']));
            $this->setAttr($el, 'dan23',   $this->formatWholeCrowns($totals[VatRateType::Standard->value]['vat']));
        }

        // Reduced rate slot — merges Reduced and SecondReduced
        $reducedBase = ($totals[VatRateType::Reduced->value]['taxBase']       ?? 0.0)
                     + ($totals[VatRateType::SecondReduced->value]['taxBase'] ?? 0.0);
        $reducedVat  = ($totals[VatRateType::Reduced->value]['vat']           ?? 0.0)
                     + ($totals[VatRateType::SecondReduced->value]['vat']     ?? 0.0);

        if ($reducedBase !== 0.0 || $reducedVat !== 0.0) {
            $this->setAttr($el, 'obrat5', $this->formatWholeCrowns($reducedBase));
            $this->setAttr($el, 'dan5',   $this->formatWholeCrowns($reducedVat));
        }

        $dp3->appendChild($el);
    }

    /**
     * Veta4 — deductible input VAT (rows 40, 41, 46, 47).
     *
     * EPO attribute naming:
     *   pln23 / odp_tuz23_nar → Standard
     *   pln5  / odp_tuz5_nar  → Reduced + SecondReduced merged
     *   odp_sum_nar           → total claimable input VAT
     */
    private function buildVeta4(\DOMElement $dp3): void
    {
        $totals = InvoiceCollection::aggregateByVatRateType($this->invoices->received());

        if ($totals === []) {
            return;
        }

        $el = $this->dom->createElement('Veta4');

        $vat23  = $totals[VatRateType::Standard->value]['vat']     ?? 0.0;
        $base23 = $totals[VatRateType::Standard->value]['taxBase'] ?? 0.0;

        if ($base23 !== 0.0 || $vat23 !== 0.0) {
            $this->setAttr($el, 'pln23',         $this->formatWholeCrowns($base23));
            $this->setAttr($el, 'odp_tuz23_nar', $this->formatWholeCrowns($vat23));
        }

        $vat5  = ($totals[VatRateType::Reduced->value]['vat']       ?? 0.0)
               + ($totals[VatRateType::SecondReduced->value]['vat'] ?? 0.0);
        $base5 = ($totals[VatRateType::Reduced->value]['taxBase']       ?? 0.0)
               + ($totals[VatRateType::SecondReduced->value]['taxBase'] ?? 0.0);

        if ($base5 !== 0.0 || $vat5 !== 0.0) {
            $this->setAttr($el, 'pln5',         $this->formatWholeCrowns($base5));
            $this->setAttr($el, 'odp_tuz5_nar', $this->formatWholeCrowns($vat5));
        }

        $totalInputVat = $vat23 + $vat5;
        $this->setAttr($el, 'odp_sum_nar', $this->formatWholeCrowns($totalInputVat));

        $dp3->appendChild($el);
    }

    /**
     * Veta6 — final summary (rows 62–66).
     */
    private function buildVeta6(\DOMElement $dp3): void
    {
        $outputVat = $this->totalOutputVat();
        $inputVat  = $this->totalInputVat();
        $result    = round($outputVat - $inputVat);

        $el = $this->dom->createElement('Veta6');

        $this->setAttr($el, 'dan_zocelk', $this->formatWholeCrowns($outputVat));
        $this->setAttr($el, 'odp_zocelk', $this->formatWholeCrowns($inputVat));

        if ($this->filingType === VatReturnFilingType::Supplementary
            || $this->filingType === VatReturnFilingType::SupplementaryCorrective) {
            $this->setAttr($el, 'dano', '0');
        } elseif ($result >= 0) {
            $this->setAttr($el, 'dano_da', $this->formatWholeCrowns($result));
        } else {
            $this->setAttr($el, 'dano_no', $this->formatWholeCrowns(abs($result)));
        }

        $dp3->appendChild($el);
    }

    // ------------------------------------------------------------------ helpers

    private function totalOutputVat(): float
    {
        return array_sum(array_column(
            InvoiceCollection::aggregateByVatRateType($this->invoices->issued()),
            'vat',
        ));
    }

    private function totalInputVat(): float
    {
        return array_sum(array_column(
            InvoiceCollection::aggregateByVatRateType($this->invoices->received()),
            'vat',
        ));
    }
}
