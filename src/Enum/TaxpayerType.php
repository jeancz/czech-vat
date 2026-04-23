<?php

declare(strict_types=1);

namespace JeanCz\CzechVat\Enum;

/**
 * Type of taxpayer (typ_ds in XSD).
 *
 * F – fyzická osoba (natural person)
 * P – právnická osoba (legal entity)
 */
enum TaxpayerType: string
{
    case NaturalPerson = 'F';
    case LegalEntity   = 'P';
}
