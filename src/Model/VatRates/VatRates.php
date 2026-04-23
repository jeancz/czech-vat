<?php

declare(strict_types=1);

namespace JeanCz\CzechVat\Model\VatRates;

use JeanCz\CzechVat\Enum\VatRateType;
use JeanCz\CzechVat\Exception\InvalidVatRateException;

/**
 * Configurable set of VAT rates valid for a specific filing period.
 *
 * Rather than hardcoding percentages into an enum, the consumer
 * supplies what rates are currently in force. The library ships
 * factory methods for known Czech rate schedules, but nothing
 * prevents registering custom rates (e.g. for a transitional period
 * or a foreign jurisdiction with a compatible EPO structure).
 *
 * Usage — standard factory:
 *   $rates = VatRates::current();           // valid from 2024-01-01
 *   $rates = VatRates::validFrom20240101(); // same, explicit name
 *   $rates = VatRates::validUntil20231231(); // pre-2024 schedule
 *
 * Usage — custom:
 *   $rates = VatRates::custom(standard: 23, reduced: 9, zero: 0);
 *
 * Resolving a concrete percentage to its semantic type:
 *   $type = $rates->resolve(21);  // → VatRateType::Standard
 *   $pct  = $rates->percentage(VatRateType::Standard); // → 21
 */
final readonly class VatRates
{
    /** @param array<string, int> $rates  VatRateType::value → percentage */
    private function __construct(private array $rates)
    {
    }

    // ------------------------------------------------------------------ factories

    /**
     * Czech VAT rates valid from 1 January 2024.
     *   Standard:     21 %
     *   Reduced:      12 %
     *   Zero:          0 %
     *
     * The second reduced rate (10 %) was abolished on 1 January 2024.
     * It is still available via {@see self::validUntil20231231()} for
     * corrections and supplementary returns covering earlier periods.
     */
    public static function validFrom20240101(): self
    {
        return new self([
            VatRateType::Standard->value => 21,
            VatRateType::Reduced->value  => 12,
            VatRateType::Zero->value     => 0,
        ]);
    }

    /**
     * Alias for {@see self::validFrom20240101()} — the current Czech schedule.
     */
    public static function current(): self
    {
        return self::validFrom20240101();
    }

    /**
     * Czech VAT rates valid until 31 December 2023.
     *   Standard:       21 %
     *   Reduced:        15 %
     *   Second reduced: 10 %
     *   Zero:            0 %
     */
    public static function validUntil20231231(): self
    {
        return new self([
            VatRateType::Standard->value      => 21,
            VatRateType::Reduced->value       => 15,
            VatRateType::SecondReduced->value => 10,
            VatRateType::Zero->value          => 0,
        ]);
    }

    /**
     * Build a fully custom rate schedule.
     * Only the types you supply will be considered valid.
     *
     * @param array<string|int, int> $rates keyed by VatRateType value or name
     *
     * @throws InvalidVatRateException when a percentage is negative
     */
    public static function custom(array $rates): self
    {
        $normalized = [];

        foreach ($rates as $type => $percentage) {
            $typeInstance = VatRateType::tryFrom((string) $type);

            if ($typeInstance === null) {
                throw new InvalidVatRateException(
                    sprintf('Keys must be valid %s values, %s given.', VatRateType::class, get_debug_type($type))
                );
            }

            if ($percentage < 0) {
                throw new InvalidVatRateException(
                    sprintf('VAT percentage cannot be negative, %d given for %s.', $percentage, $typeInstance->value)
                );
            }

            $normalized[$typeInstance->value] = $percentage;
        }

        return new self($normalized);
    }

    // ------------------------------------------------------------------ queries

    /**
     * Return the percentage for a given semantic type.
     *
     * @throws InvalidVatRateException when the type is not registered in this schedule
     */
    public function percentage(VatRateType $type): int
    {
        if (!array_key_exists($type->value, $this->rates)) {
            throw new InvalidVatRateException(
                sprintf(
                    'Rate type "%s" is not defined in this VAT rate schedule. Registered types: %s.',
                    $type->value,
                    implode(', ', array_keys($this->rates)),
                )
            );
        }

        return $this->rates[$type->value];
    }

    /**
     * Resolve a concrete percentage to its semantic {@see VatRateType}.
     *
     * @throws InvalidVatRateException when the percentage is not part of this schedule
     */
    public function resolve(int $percentage): VatRateType
    {
        $flipped = array_flip($this->rates);

        if (!array_key_exists($percentage, $flipped)) {
            throw new InvalidVatRateException(
                sprintf(
                    '%d %% is not a valid rate in this VAT schedule. Valid rates: %s.',
                    $percentage,
                    implode(', ', array_values($this->rates)),
                )
            );
        }

        return VatRateType::from($flipped[$percentage]);
    }

    /**
     * Whether the given percentage is registered in this schedule.
     */
    public function has(int $percentage): bool
    {
        return in_array($percentage, $this->rates, true);
    }

    /**
     * Whether a given semantic type is registered in this schedule.
     */
    public function hasType(VatRateType $type): bool
    {
        return array_key_exists($type->value, $this->rates);
    }

    /**
     * All registered types in this schedule.
     *
     * @return VatRateType[]
     */
    public function types(): array
    {
        return array_map(
            static fn(string $value): VatRateType => VatRateType::from($value),
            array_keys($this->rates),
        );
    }

    /**
     * All registered percentages in this schedule, keyed by type value.
     *
     * @return array<string, int>
     */
    public function all(): array
    {
        return $this->rates;
    }
}
