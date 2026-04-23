<?php

declare(strict_types=1);

namespace JeanCz\CzechVat\Model\Taxpayer;

use JeanCz\CzechVat\Enum\TaxpayerType;
use JeanCz\CzechVat\Enum\VatPayerType;
use JeanCz\CzechVat\Exception\InvalidTaxpayerException;

/**
 * Represents the VAT-registered entity submitting the filing.
 *
 * Natural persons and legal entities share this model; the
 * constructor enforces that required fields are present for
 * each taxpayer type.
 */
final readonly class Taxpayer
{
    /**
     * The numeric part of the Czech VAT ID (without the "CZ" prefix).
     * E.g. "CZ12345678" → "12345678".
     */
    public readonly string $dicNumeric;

    private function __construct(
        /** Full VAT ID including country prefix, e.g. "CZ12345678" */
        public readonly string $vatId,
        /** Territorial tax office code (číselník UFO) */
        public readonly string $taxOfficeCode,
        public readonly TaxpayerType $taxpayerType,
        public readonly VatPayerType $vatPayerType,
        public readonly string $street,
        public readonly string $houseNumber,
        public readonly string $city,
        public readonly string $postalCode,
        public readonly string $country,
        /** Legal entity name (required for legal entities) */
        public readonly ?string $companyName,
        /** First name (required for natural persons) */
        public readonly ?string $firstName,
        /** Last name (required for natural persons) */
        public readonly ?string $lastName,
        public readonly ?string $email,
        /** Territorial workplace code (optional, číselník PRACUFO) */
        public readonly ?string $taxOfficeWorkplaceCode,
    ) {
        $this->dicNumeric = (string) preg_replace('/^[A-Z]+/', '', strtoupper($vatId));
    }

    /**
     * Factory for a legal entity (právnická osoba).
     *
     * @throws InvalidTaxpayerException
     */
    public static function legalEntity(
        string $vatId,
        string $taxOfficeCode,
        string $companyName,
        string $street,
        string $houseNumber,
        string $city,
        string $postalCode,
        string $country = 'ČESKÁ REPUBLIKA',
        VatPayerType $vatPayerType = VatPayerType::VatPayer,
        ?string $email = null,
        ?string $taxOfficeWorkplaceCode = null,
    ): self {
        self::assertVatId($vatId);

        if (trim($companyName) === '') {
            throw new InvalidTaxpayerException('Company name is required for legal entities.');
        }

        return new self(
            vatId: $vatId,
            taxOfficeCode: $taxOfficeCode,
            taxpayerType: TaxpayerType::LegalEntity,
            vatPayerType: $vatPayerType,
            street: $street,
            houseNumber: $houseNumber,
            city: $city,
            postalCode: (string) preg_replace('/\s+/', '', $postalCode),
            country: $country,
            companyName: $companyName,
            firstName: null,
            lastName: null,
            email: $email,
            taxOfficeWorkplaceCode: $taxOfficeWorkplaceCode,
        );
    }

    /**
     * Factory for a natural person (fyzická osoba).
     *
     * @throws InvalidTaxpayerException
     */
    public static function naturalPerson(
        string $vatId,
        string $taxOfficeCode,
        string $firstName,
        string $lastName,
        string $street,
        string $houseNumber,
        string $city,
        string $postalCode,
        string $country = 'ČESKÁ REPUBLIKA',
        VatPayerType $vatPayerType = VatPayerType::VatPayer,
        ?string $email = null,
        ?string $taxOfficeWorkplaceCode = null,
    ): self {
        self::assertVatId($vatId);

        if (trim($firstName) === '') {
            throw new InvalidTaxpayerException('First name is required for natural persons.');
        }

        if (trim($lastName) === '') {
            throw new InvalidTaxpayerException('Last name is required for natural persons.');
        }

        return new self(
            vatId: $vatId,
            taxOfficeCode: $taxOfficeCode,
            taxpayerType: TaxpayerType::NaturalPerson,
            vatPayerType: $vatPayerType,
            street: $street,
            houseNumber: $houseNumber,
            city: $city,
            postalCode: (string) preg_replace('/\s+/', '', $postalCode),
            country: $country,
            companyName: null,
            firstName: $firstName,
            lastName: $lastName,
            email: $email,
            taxOfficeWorkplaceCode: $taxOfficeWorkplaceCode,
        );
    }

    /**
     * @throws InvalidTaxpayerException
     */
    private static function assertVatId(string $vatId): void
    {
        if (preg_match('/^[A-Z]{2}[0-9]{1,10}$/', strtoupper($vatId)) !== 1) {
            throw new InvalidTaxpayerException(
                sprintf('VAT ID "%s" has an invalid format. Expected e.g. "CZ12345678".', $vatId)
            );
        }
    }

    public function isLegalEntity(): bool
    {
        return $this->taxpayerType === TaxpayerType::LegalEntity;
    }
}
