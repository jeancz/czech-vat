<?php

declare(strict_types=1);

namespace JeanCz\CzechVat\Enum;

/**
 * Semantic category of a VAT rate.
 *
 * Deliberately contains no percentage values — those belong to
 * {@see \JeanCz\CzechVat\Model\VatRates\VatRates} which is configured per filing period.
 *
 * EPO XML schema maps categories to attribute suffixes:
 *   Standard      → zakl_dane1 / dan1  (historically named '23')
 *   Reduced        → zakl_dane2 / dan2  (historically named '5')
 *   SecondReduced  → zakl_dane3 / dan3  (second reduced, valid to 31.12.2023)
 *   Zero           → not reported in standard VAT lines
 */
enum VatRateType: string
{
    case Standard     = 'standard';
    case Reduced      = 'reduced';
    case SecondReduced = 'second_reduced';
    case Zero         = 'zero';

    /**
     * EPO XML attribute index used for zakl_dane{N} / dan{N} attributes.
     * Returns null for Zero because zero-rated supplies are not reported
     * in the standard taxable-supply rows.
     */
    public function epoAttributeIndex(): ?int
    {
        return match ($this) {
            self::Standard      => 1,
            self::Reduced       => 2,
            self::SecondReduced => 3,
            self::Zero          => null,
        };
    }

    /**
     * Whether this type maps to the EPO '23' suffix (standard rate slot).
     * Used for VetaC / Veta1 obrat23 vs obrat5 grouping.
     */
    public function isStandardSlot(): bool
    {
        return $this === self::Standard;
    }

    /**
     * Whether this type maps to the EPO '5' suffix (reduced rate slot).
     */
    public function isReducedSlot(): bool
    {
        return match ($this) {
            self::Reduced, self::SecondReduced => true,
            default                            => false,
        };
    }
}
