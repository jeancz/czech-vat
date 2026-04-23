<?php

declare(strict_types=1);

namespace JeanCz\CzechVat\Generator;

use JeanCz\CzechVat\Contract\XmlValidatorInterface;
use JeanCz\CzechVat\Exception\XmlValidationException;

/**
 * Validates a generated XML string against a bundled or provided XSD schema.
 *
 * The validator is intentionally decoupled from the builders so it can be
 * used in tests, CI pipelines, or skipped entirely in production if desired.
 *
 * Usage:
 *   $validator = XsdValidator::forControlStatement();
 *   $validator->validate($xml);
 *
 *   // or with a custom schema path:
 *   $validator = new XsdValidator('/path/to/my.xsd');
 *   $validator->validate($xml);
 */
final class XsdValidator implements XmlValidatorInterface
{
    private const string  SCHEMA_DIR = __DIR__ . '/../../schema/';

    public function __construct(private readonly string $xsdPath)
    {
    }

    public static function forControlStatement(): self
    {
        return new self(self::SCHEMA_DIR . 'dphkh1_epo2.xsd');
    }

    public static function forVatReturn(): self
    {
        return new self(self::SCHEMA_DIR . 'dphdp3_epo2.xsd');
    }

    /**
     * @throws XmlValidationException
     */
    public function validate(string $xml): void
    {
        if (!file_exists($this->xsdPath)) {
            throw new XmlValidationException(
                sprintf('XSD schema file not found: %s', $this->xsdPath)
            );
        }

        $dom = new \DOMDocument();

        // Suppress loadXML warnings; we'll throw our own exception.
        if (!@$dom->loadXML($xml)) {
            throw new XmlValidationException('The provided string is not valid XML.');
        }

        $previousState = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $valid = $dom->schemaValidate($this->xsdPath);

        $libxmlErrors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previousState);

        if (!$valid) {
            $messages = array_map(
                static fn(\LibXMLError $e): string => sprintf(
                    '[Line %d, Col %d] %s',
                    $e->line,
                    $e->column,
                    trim($e->message),
                ),
                $libxmlErrors,
            );

            throw new XmlValidationException(
                sprintf("XML validation failed against schema %s:\n%s", basename($this->xsdPath), implode("\n", $messages)),
                $messages,
            );
        }
    }
}
