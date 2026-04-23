<?php

declare(strict_types=1);

namespace JeanCz\CzechVat\Model\Invoice;

use JeanCz\CzechVat\Enum\VatRateType;

/**
 * Immutable, typed collection of invoices.
 */
final class InvoiceCollection
{
    /** @var Invoice[] */
    private array $issued   = [];

    /** @var Invoice[] */
    private array $received = [];

    public function addIssued(Invoice ...$invoices): self
    {
        $clone         = clone $this;
        $clone->issued = array_merge($this->issued, $invoices);

        return $clone;
    }

    public function addReceived(Invoice ...$invoices): self
    {
        $clone           = clone $this;
        $clone->received = array_merge($this->received, $invoices);

        return $clone;
    }

    /** @return Invoice[] */
    public function issued(): array
    {
        return $this->issued;
    }

    /** @return Invoice[] */
    public function received(): array
    {
        return $this->received;
    }

    /**
     * Aggregate totals per VatRateType across the given invoice list.
     *
     * @param  Invoice[] $invoices
     * @return array<string, array{taxBase: float, vat: float}>  keyed by VatRateType::value
     */
    public static function aggregateByVatRateType(array $invoices): array
    {
        $totals = [];

        foreach ($invoices as $invoice) {
            foreach ($invoice->groupByVatRateType() as $typeValue => $amounts) {
                if (!isset($totals[$typeValue])) {
                    $totals[$typeValue] = ['taxBase' => 0.0, 'vat' => 0.0];
                }

                $totals[$typeValue]['taxBase'] += $amounts['taxBase'];
                $totals[$typeValue]['vat']     += $amounts['vat'];
            }
        }

        return $totals;
    }
}
