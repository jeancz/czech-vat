<?php

declare(strict_types=1);

namespace JeanCz\CzechVat\Builder;

use JeanCz\CzechVat\Exception\XmlGenerationException;

/**
 * Shared helpers for building Czech EPO XML documents via DOM.
 *
 * All setAttr* helpers silently skip null or empty-string values so
 * callers do not need to guard every optional attribute.
 */
abstract class AbstractXmlBuilder
{
    protected \DOMDocument $dom;

    protected function __construct(
        protected readonly string $softwareName    = 'jeancz/czech-vat',
        protected readonly string $softwareVersion = '1.0',
    ) {
        $this->dom = new \DOMDocument('1.0', 'UTF-8');
        $this->dom->formatOutput = true;
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Set an attribute only when the value is not null and not an empty string.
     */
    protected function setAttr(\DOMElement $el, string $name, string|int|float|null $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $el->setAttribute($name, (string) $value);
    }

    /**
     * Format a monetary amount for the VAT Return (whole crowns, no decimals).
     */
    protected function formatWholeCrowns(float $amount): string
    {
        return number_format(round($amount), 0, '.', '');
    }

    /**
     * Format a monetary amount for the Control Statement (two decimal places).
     */
    protected function formatDecimal(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /**
     * Format a date as DD.MM.YYYY (Czech EPO date format).
     */
    protected function formatDate(\DateTimeInterface $date): string
    {
        return $date->format('d.m.Y');
    }

    /**
     * Today's date formatted for the d_poddp (filing date) attribute.
     */
    protected function today(): string
    {
        return (new \DateTimeImmutable())->format('d.m.Y');
    }

    /**
     * Strip any alphabetic prefix from a VAT ID and return only digits.
     * "CZ12345678" → "12345678"
     */
    protected function numericVatId(string $vatId): string
    {
        return (string) preg_replace('/^[A-Z]+/i', '', $vatId);
    }

    /**
     * Produce the final XML string.
     *
     * @throws XmlGenerationException
     */
    protected function saveXml(): string
    {
        $xml = $this->dom->saveXML();

        if ($xml === false) {
            throw new XmlGenerationException('DOMDocument::saveXML() returned false.');
        }

        return $xml;
    }
}
