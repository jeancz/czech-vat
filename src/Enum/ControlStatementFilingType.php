<?php

declare(strict_types=1);

namespace JeanCz\CzechVat\Enum;

/**
 * Type of VAT Control Statement filing (khdph_forma in XSD).
 *
 * B – řádné (regular)
 * O – řádné/opravné (regular corrective)
 * N – následné (subsequent)
 * E – následné/opravné (subsequent corrective)
 */
enum ControlStatementFilingType: string
{
    case Regular              = 'B';
    case RegularCorrective    = 'O';
    case Subsequent           = 'N';
    case SubsequentCorrective = 'E';
}
