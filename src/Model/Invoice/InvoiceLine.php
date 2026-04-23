<?php

declare(strict_types=1);

namespace JeanCz\CzechVat\Model\Invoice;

use JeanCz\CzechVat\Enum\VatRateType;
use JeanCz\CzechVat\Exception\InvalidInvoiceException;
use JeanCz\CzechVat\Model\VatRates\VatRates;

/**
 * Represents a single line on an invoice with a specific VAT rate category.
 *
 * The concrete percentage is not stored here — it is resolved at construction
 * time from the supplied {@see VatRates} schedule and stored alongside the
 * semantic type. This means:
 *
 *   - The model never has hardcoded "21", "12", etc.
 *   - Builders aggregate by {@see VatRateType}, not by percentage.
 *   - If rates change in the future, only the VatRates factory changes.
 *
 * Construction options:
 *
 *   // Explicit amounts, semantic type
 *   new InvoiceLine(VatRateType::Standard, taxBase: 1000, vat: 210, rates: VatRates::current());
 *
 *   // Compute VAT automatically from tax base
 *   InvoiceLine::fromTaxBase(VatRateType::Standard, taxBase: 1000, rates: VatRates::current());
 */
final readonly class InvoiceLine
{
    /** Resolved concrete percentage, e.g. 21 */
    public readonly int $vatPercentage;

    /**
     * @throws InvalidInvoiceException
     */
    public function __construct(
        public readonly VatRateType $vatRateType,
        public readonly float $taxBase,
        public readonly float $vat,
        VatRates $rates,
    ) {
        $this->vatPercentage = $rates->percentage($vatRateType);

        if ($taxBase < 0 && $vat > 0) {
            throw new InvalidInvoiceException(
                'A credit note (negative tax base) cannot have a positive VAT amount.'
            );
        }
    }

    /**
     * Compute VAT from tax base using the rate from the supplied schedule.
     * Rounds to 2 decimal places.
     *
     * @throws InvalidInvoiceException
     */
    public static function fromTaxBase(
        VatRateType $vatRateType,
        float $taxBase,
        VatRates $rates,
    ): self {
        $percentage = $rates->percentage($vatRateType);
        $vat        = round($taxBase * ($percentage / 100), 2);

        return new self(
            vatRateType: $vatRateType,
            taxBase:     $taxBase,
            vat:         $vat,
            rates:       $rates,
        );
    }

    public function totalIncludingVat(): float
    {
        return $this->taxBase + $this->vat;
    }
}
