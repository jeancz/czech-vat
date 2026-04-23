<?php

declare(strict_types=1);

namespace JeanCz\CzechVat\Model\Invoice;

use JeanCz\CzechVat\Enum\VatRateType;
use JeanCz\CzechVat\Exception\InvalidInvoiceException;

/**
 * Represents a complete invoice (or credit note) with one or more lines.
 *
 * The threshold for individual VAT Control Statement reporting is
 * 10 000 CZK including VAT (§ 100a ZDPH). Invoices at or below this
 * amount are reported as aggregate totals (A.5 / B.3).
 */
final readonly class Invoice
{
    public const float CONTROL_STATEMENT_THRESHOLD = 10_000.0;

    /** @var InvoiceLine[] */
    private array $lines;

    /**
     * @param InvoiceLine[]           $lines
     * @param string|null             $partnerVatId            VAT ID of partner (required above threshold)
     * @param string|null             $documentNumber          Our document number (required above threshold)
     * @param string|null             $supplierDocumentNumber  Supplier's document number (for received invoices)
     * @param \DateTimeInterface|null $taxPointDate            DUZP / DPPD (required above threshold)
     * @param bool                    $isReverseCharge         Přenesení daňové povinnosti
     *
     * @throws InvalidInvoiceException
     */
    public function __construct(
        array $lines,
        public readonly ?string $partnerVatId = null,
        public readonly ?string $documentNumber = null,
        public readonly ?string $supplierDocumentNumber = null,
        public readonly ?\DateTimeInterface $taxPointDate = null,
        public readonly bool $isReverseCharge = false,
    ) {
        if ($lines === []) {
            throw new InvalidInvoiceException('An invoice must have at least one line.');
        }

        foreach ($lines as $line) {
            // No-op loop to keep verification logic if needed,
            // but the type is already enforced by the array hint and PHPDoc.
        }

        $this->lines = array_values($lines);

        if ($this->requiresDetailedControlStatementReporting()) {
            $this->assertDetailedReportingFields();
        }
    }

    /** @return InvoiceLine[] */
    public function lines(): array
    {
        return $this->lines;
    }

    public function totalIncludingVat(): float
    {
        return array_sum(array_map(
            static fn(InvoiceLine $l): float => $l->totalIncludingVat(),
            $this->lines,
        ));
    }

    public function totalTaxBase(): float
    {
        return array_sum(array_map(
            static fn(InvoiceLine $l): float => $l->taxBase,
            $this->lines,
        ));
    }

    public function totalVat(): float
    {
        return array_sum(array_map(
            static fn(InvoiceLine $l): float => $l->vat,
            $this->lines,
        ));
    }

    /**
     * Invoices above the threshold must be reported line-by-line
     * in the Control Statement (sections A.4 / B.2).
     */
    public function requiresDetailedControlStatementReporting(): bool
    {
        return abs($this->totalIncludingVat()) > self::CONTROL_STATEMENT_THRESHOLD;
    }

    /**
     * Returns lines grouped by VatRateType.
     *
     * @return array<string, array{taxBase: float, vat: float}>  keyed by VatRateType::value
     */
    public function groupByVatRateType(): array
    {
        $grouped = [];

        foreach ($this->lines as $line) {
            $key = $line->vatRateType->value;

            if (!isset($grouped[$key])) {
                $grouped[$key] = ['taxBase' => 0.0, 'vat' => 0.0];
            }

            $grouped[$key]['taxBase'] += $line->taxBase;
            $grouped[$key]['vat']     += $line->vat;
        }

        return $grouped;
    }

    /**
     * Returns the partner VAT ID without any country prefix, or null.
     */
    public function partnerVatIdNumeric(): ?string
    {
        if ($this->partnerVatId === null) {
            return null;
        }

        return preg_replace('/^[A-Z]+/i', '', $this->partnerVatId);
    }

    /**
     * @throws InvalidInvoiceException
     */
    private function assertDetailedReportingFields(): void
    {
        if (in_array($this->partnerVatId, [null, '', '0'], true)) {
            throw new InvalidInvoiceException(
                sprintf('Invoices over %.0f CZK require a partner VAT ID.', self::CONTROL_STATEMENT_THRESHOLD)
            );
        }

        if (in_array($this->documentNumber, [null, '', '0'], true)) {
            throw new InvalidInvoiceException(
                sprintf('Invoices over %.0f CZK require a document number.', self::CONTROL_STATEMENT_THRESHOLD)
            );
        }

        if (!$this->taxPointDate instanceof \DateTimeInterface) {
            throw new InvalidInvoiceException(
                sprintf('Invoices over %.0f CZK require a tax point date.', self::CONTROL_STATEMENT_THRESHOLD)
            );
        }
    }
}
