<?php

declare(strict_types=1);

namespace JeanCz\CzechVat\Contract;

use JeanCz\CzechVat\Exception\XmlValidationException;

/**
 * Validates an XML string against a schema.
 */
interface XmlValidatorInterface
{
    /**
     * @throws XmlValidationException when validation fails.
     */
    public function validate(string $xml): void;
}
