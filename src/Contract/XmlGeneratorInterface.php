<?php

declare(strict_types=1);

namespace JeanCz\CzechVat\Contract;

use JeanCz\CzechVat\Exception\XmlGenerationException;

/**
 * Any class that can produce an XML string.
 */
interface XmlGeneratorInterface
{
    /**
     * @throws XmlGenerationException
     */
    public function generate(): string;
}
