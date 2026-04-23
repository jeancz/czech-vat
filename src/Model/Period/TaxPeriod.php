<?php

declare(strict_types=1);

namespace JeanCz\CzechVat\Model\Period;

use JeanCz\CzechVat\Exception\InvalidPeriodException;

/**
 * Represents a Czech VAT filing period.
 *
 * Control Statements are always monthly.
 * VAT Returns can be monthly or quarterly.
 */
final readonly class TaxPeriod
{
    private function __construct(
        public readonly int $year,
        public readonly ?int $month,
        public readonly ?int $quarter,
    ) {
    }

    /**
     * Create a monthly period (used for Control Statement and monthly VAT Return filers).
     *
     * @throws InvalidPeriodException
     */
    public static function monthly(int $year, int $month): self
    {
        if ($month < 1 || $month > 12) {
            throw new InvalidPeriodException(sprintf('Month must be between 1 and 12, %d given.', $month));
        }

        if ($year < 1990 || $year > 9999) {
            throw new InvalidPeriodException(sprintf('Year %d is out of the allowed range.', $year));
        }

        return new self(year: $year, month: $month, quarter: null);
    }

    /**
     * Create a quarterly period (used for quarterly VAT Return filers).
     *
     * @throws InvalidPeriodException
     */
    public static function quarterly(int $year, int $quarter): self
    {
        if ($quarter < 1 || $quarter > 4) {
            throw new InvalidPeriodException(sprintf('Quarter must be between 1 and 4, %d given.', $quarter));
        }

        if ($year < 1990 || $year > 9999) {
            throw new InvalidPeriodException(sprintf('Year %d is out of the allowed range.', $year));
        }

        return new self(year: $year, month: null, quarter: $quarter);
    }

    /**
     * Derive a monthly period from a DateTimeInterface (day is ignored).
     */
    public static function fromDate(\DateTimeInterface $date): self
    {
        return self::monthly((int) $date->format('Y'), (int) $date->format('n'));
    }

    public function isMonthly(): bool
    {
        return $this->month !== null;
    }

    public function isQuarterly(): bool
    {
        return $this->quarter !== null;
    }

    /**
     * Returns the first day of this period.
     */
    public function startDate(): \DateTimeImmutable
    {
        if ($this->isMonthly()) {
            return new \DateTimeImmutable(sprintf('%04d-%02d-01', $this->year, $this->month));
        }

        $firstMonth = ($this->quarter - 1) * 3 + 1;

        return new \DateTimeImmutable(sprintf('%04d-%02d-01', $this->year, $firstMonth));
    }

    /**
     * Returns the last day of this period.
     */
    public function endDate(): \DateTimeImmutable
    {
        return $this->startDate()->modify('last day of this month +' . ($this->isQuarterly() ? '2 months' : '0 months'));
    }

    public function __toString(): string
    {
        if ($this->isMonthly()) {
            return sprintf('%04d-%02d', $this->year, $this->month);
        }

        return sprintf('%04d-Q%d', $this->year, $this->quarter);
    }
}
