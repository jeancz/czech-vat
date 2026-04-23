<?php

declare(strict_types=1);

namespace JeanCz\CzechVat\Exception;

final class XmlValidationException extends CzechVatException
{
    /** @param list<string> $errors */
    public function __construct(string $message, public readonly array $errors = [])
    {
        parent::__construct($message);
    }
}
