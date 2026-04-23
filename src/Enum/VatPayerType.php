<?php

declare(strict_types=1);

namespace JeanCz\CzechVat\Enum;

/**
 * Type of VAT payer for the return (typ_platce in XSD).
 *
 * P – Plátce daně (§ 6 – § 6fa)
 * I – Identifikovaná osoba (§ 6g – § 6l)
 * S – Skupina (§ 5a)
 * N – Neplátce dle § 108
 * R – Neplátce – pořízení NDP § 19c
 * D – Neplátce – dodání NDP § 19b
 */
enum VatPayerType: string
{
    case VatPayer            = 'P';
    case IdentifiedPerson    = 'I';
    case Group               = 'S';
    case NonPayer108         = 'N';
    case NonPayerAcquisition = 'R';
    case NonPayerDelivery    = 'D';
}
