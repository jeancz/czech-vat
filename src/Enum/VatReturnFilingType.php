<?php

declare(strict_types=1);

namespace JeanCz\CzechVat\Enum;

/**
 * Type of VAT Return filing (dapdph_forma in XSD).
 *
 * B – řádné (regular)
 * O – opravné (corrective, replaces a previously filed regular return)
 * D – dodatečné (supplementary)
 * E – dodatečné/opravné (supplementary corrective)
 */
enum VatReturnFilingType: string
{
    case Regular                 = 'B';
    case Corrective              = 'O';
    case Supplementary           = 'D';
    case SupplementaryCorrective = 'E';
}
