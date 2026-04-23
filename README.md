# jeancz/czech-vat

PHP knihovna pro generování XML souborů daňového přiznání k DPH (**DPHDP3**) a kontrolního hlášení DPH (**DPHKH1**) ve formátu požadovaném portálem EPO Finanční správy ČR.

## Obsah

- [Požadavky](#požadavky)
- [Instalace](#instalace)
- [Rychlý start](#rychlý-start)
- [Sazby DPH](#sazby-dph)
- [Faktury a řádky](#faktury-a-řádky)
- [Zdaňovací období](#zdaňovací-období)
- [Plátce](#plátce)
- [Generování XML](#generování-xml)
    - [Kontrolní hlášení](#kontrolní-hlášení)
    - [Daňové přiznání k DPH](#daňové-přiznání-k-dph)
    - [Typy podání](#typy-podání)
- [XSD validace](#xsd-validace)
- [Výjimky](#výjimky)
- [Architektura](#architektura)
- [Rozšíření](#rozšíření)

---

## Požadavky

- PHP 8.4+
- Rozšíření `ext-dom` a `ext-libxml` (součást standardní instalace PHP)
- Žádné další závislosti

## Instalace

```bash
composer require jean/czech-vat
```

---

## Rychlý start

```php
use JeanCz\CzechVat\VatFilingGenerator;
use JeanCz\CzechVat\Enum\VatRateType;
use JeanCz\CzechVat\Model\Invoice\Invoice;
use JeanCz\CzechVat\Model\Invoice\InvoiceLine;
use JeanCz\CzechVat\Model\Invoice\InvoiceCollection;
use JeanCz\CzechVat\Model\Period\TaxPeriod;
use JeanCz\CzechVat\Model\Taxpayer\Taxpayer;
use JeanCz\CzechVat\Model\VatRates\VatRates;

// 1. Sazebník platný pro dané období
$rates = VatRates::current(); // od 1.1.2024: základní 21 %, snížená 12 %

// 2. Plátce
$taxpayer = Taxpayer::legalEntity(
    vatId:        'CZ12345678',
    taxOfficeCode: '452',
    companyName:  'Moje Firma s.r.o.',
    street:       'Hlavní',
    houseNumber:  '1',
    city:         'Praha',
    postalCode:   '11000',
    email:        'ucetni@mojefirma.cz',
);

// 3. Zdaňovací období
$period = TaxPeriod::monthly(2025, 1);

// 4. Faktury
$invoices = (new InvoiceCollection())
    ->addIssued(
        // Vydaná faktura nad 10 000 Kč — vykazuje se jednotlivě v KH
        new Invoice(
            lines: [
                new InvoiceLine(VatRateType::Standard, taxBase: 100_000, vat: 21_000, rates: $rates),
            ],
            partnerVatId:  'CZ87654321',
            documentNumber: 'FAK-2025-001',
            taxPointDate:  new DateTimeImmutable('2025-01-15'),
        ),
        // Vydaná faktura do 10 000 Kč — souhrnně v sekci A.5 KH
        new Invoice([
            new InvoiceLine(VatRateType::Standard, taxBase: 5_000, vat: 1_050, rates: $rates),
        ]),
    )
    ->addReceived(
        // Přijatá faktura nad 10 000 Kč — sekce B.2 KH
        new Invoice(
            lines: [
                new InvoiceLine(VatRateType::Standard, taxBase: 50_000, vat: 10_500, rates: $rates),
            ],
            partnerVatId:           'CZ11111111',
            documentNumber:         'MUJ-REF-001',
            supplierDocumentNumber: 'DOD-INV-999',
            taxPointDate:           new DateTimeImmutable('2025-01-10'),
        ),
    );

// 5. Generování
$generator = new VatFilingGenerator($taxpayer, $period, $invoices);

file_put_contents('kontrolni_hlaseni.xml', $generator->generateControlStatement());
file_put_contents('danove_priznani.xml',   $generator->generateVatReturn());
```

---

## Sazby DPH

Sazby nejsou součástí enumu, ale konfigurovatelného objektu `VatRates`. Oddělení sémantiky od konkrétních procent zajišťuje, že při legislativní změně stačí aktualizovat sazebník — ne upravovat enum a s ním veškerý kód, který na něj závisí.

### Předdefinované sazebníky

```php
use JeanCz\CzechVat\Model\VatRates\VatRates;

// Aktuální sazby (od 1. 1. 2024)
// Základní: 21 %, Snížená: 12 %, Nulová: 0 %
$rates = VatRates::current();

// Stejné, explicitnější název
$rates = VatRates::validFrom20240101();

// Sazby platné do 31. 12. 2023
// Základní: 21 %, Snížená: 15 %, Druhá snížená: 10 %, Nulová: 0 %
$rates = VatRates::validUntil20231231();
```

### Vlastní sazebník

```php
use JeanCz\CzechVat\Enum\VatRateType;

$rates = VatRates::custom([
    VatRateType::Standard->value => 21,
    VatRateType::Reduced->value  => 12,
    VatRateType::Zero->value     => 0,
]);
```

### Práce se sazebníkem

```php
// Zjistit procento pro daný typ
$rates->percentage(VatRateType::Standard); // → 21

// Přeložit konkrétní procento na sémantický typ
$rates->resolve(12); // → VatRateType::Reduced

// Ověřit, zda je sazba registrována
$rates->has(12);                          // → true
$rates->hasType(VatRateType::SecondReduced); // → false (v aktuálním sazebníku)
```

### Typy sazeb (`VatRateType`)

| Case | Popis | EPO XML atribut |
|------|-------|-----------------|
| `VatRateType::Standard` | Základní sazba | `zakl_dane1` / `dan1` |
| `VatRateType::Reduced` | Snížená sazba | `zakl_dane2` / `dan2` |
| `VatRateType::SecondReduced` | Druhá snížená (do 31. 12. 2023) | `zakl_dane3` / `dan3` |
| `VatRateType::Zero` | Nulová sazba | nevykazuje se v řádcích DPH |

---

## Faktury a řádky

### `InvoiceLine` — jeden řádek faktury

```php
use JeanCz\CzechVat\Model\Invoice\InvoiceLine;
use JeanCz\CzechVat\Enum\VatRateType;

$rates = VatRates::current();

// Explicitní základ daně a DPH
$line = new InvoiceLine(
    vatRateType: VatRateType::Standard,
    taxBase:     10_000.0,
    vat:         2_100.0,
    rates:       $rates,
);

// Automatický výpočet DPH ze základu
$line = InvoiceLine::fromTaxBase(
    vatRateType: VatRateType::Reduced,
    taxBase:     5_000.0,
    rates:       $rates,
);
// → vat = 600.00 (12 % ze 5 000)
```

Dobropis se vyjádří záporným základem daně i DPH:

```php
$creditNote = new InvoiceLine(VatRateType::Standard, taxBase: -10_000.0, vat: -2_100.0, rates: $rates);
```

### `Invoice` — celá faktura

Faktura přijímá kolekci řádků s (potenciálně různými) sazbami. Pokud celková částka včetně DPH překračuje **10 000 Kč**, jsou pole `partnerVatId`, `documentNumber` a `taxPointDate` povinná — jejich absence způsobí výjimku při konstrukci.

```php
use JeanCz\CzechVat\Model\Invoice\Invoice;

// Faktura nad prahem — vykazuje se jednotlivě v kontrolním hlášení
$invoice = new Invoice(
    lines: [
        new InvoiceLine(VatRateType::Standard, taxBase: 80_000, vat: 16_800, rates: $rates),
        new InvoiceLine(VatRateType::Reduced,  taxBase: 20_000, vat:  2_400, rates: $rates),
    ],
    partnerVatId:           'CZ87654321',   // povinné nad 10 000 Kč
    documentNumber:         'FAK-2025-042', // povinné nad 10 000 Kč
    supplierDocumentNumber: 'DOD-789',      // číslo dodavatele (pro přijaté faktury)
    taxPointDate:           new DateTimeImmutable('2025-01-20'), // povinné nad 10 000 Kč
    isReverseCharge:        false,          // přenesení daňové povinnosti
);

// Faktura pod prahem — nevyžaduje DIČ partnera ani datum
$smallInvoice = new Invoice([
    new InvoiceLine(VatRateType::Standard, taxBase: 4_000, vat: 840, rates: $rates),
]);
```

### `InvoiceCollection` — kolekce faktur

```php
use JeanCz\CzechVat\Model\Invoice\InvoiceCollection;

$invoices = (new InvoiceCollection())
    ->addIssued($invoice1, $invoice2, $invoice3)
    ->addReceived($received1, $received2);
```

Metody `addIssued()` a `addReceived()` jsou immutable — vracejí novou instanci.

---

## Zdaňovací období

```php
use JeanCz\CzechVat\Model\Period\TaxPeriod;

// Měsíční plátce (nebo kontrolní hlášení — vždy měsíční)
$period = TaxPeriod::monthly(2025, 1);

// Čtvrtletní plátce (daňové přiznání)
$period = TaxPeriod::quarterly(2025, 1); // Q1

// Odvození z DateTimeInterface
$period = TaxPeriod::fromDate(new DateTimeImmutable('2025-03-15'));
// → monthly(2025, 3)

// Dotazování
$period->isMonthly();   // true / false
$period->isQuarterly(); // true / false
$period->startDate();   // DateTimeImmutable — první den období
$period->endDate();     // DateTimeImmutable — poslední den období
(string) $period;       // '2025-01' nebo '2025-Q1'
```

> **Poznámka:** Kontrolní hlášení se podává vždy za kalendářní měsíc, i pro čtvrtletní plátce DPH. Čtvrtletní plátce podá tři měsíční kontrolní hlášení a jedno čtvrtletní daňové přiznání.

---

## Plátce

### Právnická osoba

```php
use JeanCz\CzechVat\Model\Taxpayer\Taxpayer;
use JeanCz\CzechVat\Enum\VatPayerType;

$taxpayer = Taxpayer::legalEntity(
    vatId:                 'CZ12345678',
    taxOfficeCode:         '452',        // kód FÚ z číselníku UFO
    companyName:           'Moje Firma s.r.o.',
    street:                'Obchodní',
    houseNumber:           '15',
    city:                  'Brno',
    postalCode:            '60200',
    country:               'ČESKÁ REPUBLIKA', // výchozí hodnota
    vatPayerType:          VatPayerType::VatPayer, // výchozí hodnota
    email:                 'dph@mojefirma.cz',
    taxOfficeWorkplaceCode: '2401',      // územní pracoviště (volitelné)
);
```

### Fyzická osoba

```php
$taxpayer = Taxpayer::naturalPerson(
    vatId:        'CZ7001011234',
    taxOfficeCode:'451',
    firstName:    'Jan',
    lastName:     'Novák',
    street:       'Lipová',
    houseNumber:  '3',
    city:         'Praha',
    postalCode:   '13000',
);
```

### Typy plátce (`VatPayerType`)

| Case | §ZDPH | Popis |
|------|-------|-------|
| `VatPayer` | § 6–6fa | Plátce DPH (výchozí) |
| `IdentifiedPerson` | § 6g–6l | Identifikovaná osoba |
| `Group` | § 5a | Skupina |
| `NonPayer108` | § 108 | Neplátce s povinností přiznat daň |
| `NonPayerAcquisition` | § 19c | Neplátce — pořízení NDP |
| `NonPayerDelivery` | § 19b | Neplátce — dodání NDP |

---

## Generování XML

Vstupním bodem je fasáda `VatFilingGenerator`:

```php
use JeanCz\CzechVat\VatFilingGenerator;

$generator = new VatFilingGenerator(
    taxpayer:        $taxpayer,
    period:          $period,
    invoices:        $invoices,
    softwareName:    'Moje Aplikace',   // volitelné, výchozí 'jeancz/czech-vat'
    softwareVersion: '1.0',             // volitelné
);
```

### Kontrolní hlášení

```php
$xml = $generator->generateControlStatement();

file_put_contents('kh_2025_01.xml', $xml);
```

Sekce KH, které builder plní automaticky:

| Sekce | Obsah |
|-------|-------|
| `VetaA4` | Vydaná zdanitelná plnění nad 10 000 Kč (jednotlivě) |
| `VetaA5` | Vydaná zdanitelná plnění do 10 000 Kč (souhrnně) |
| `VetaA1` | Vydaná plnění v režimu přenesení daňové povinnosti nad prahem |
| `VetaB2` | Přijatá zdanitelná plnění nad 10 000 Kč (jednotlivě) |
| `VetaB3` | Přijatá zdanitelná plnění do 10 000 Kč (souhrnně) |
| `VetaB1` | Přijatá plnění v režimu přenesení daňové povinnosti nad prahem |
| `VetaC` | Kontrolní součty (křížová kontrola s DP) |

### Daňové přiznání k DPH

```php
$xml = $generator->generateVatReturn();

file_put_contents('dp_dph_2025_01.xml', $xml);
```

Sekce DP, které builder plní automaticky:

| Sekce | Řádky DP | Obsah |
|-------|----------|-------|
| `Veta1` | 1, 2 | Základ daně a daň na výstupu |
| `Veta4` | 40, 41, 46, 47 | Odpočet daně na vstupu |
| `Veta6` | 62–65 | Výsledná daňová povinnost / nadměrný odpočet |

### Typy podání

Výchozí typ je vždy **řádné** podání. Ostatní typy se předají jako argument:

```php
use JeanCz\CzechVat\Enum\VatReturnFilingType;
use JeanCz\CzechVat\Enum\ControlStatementFilingType;

// Opravné daňové přiznání (před uplynutím lhůty)
$xml = $generator->generateVatReturn(VatReturnFilingType::Corrective);

// Dodatečné daňové přiznání
$xml = $generator->generateVatReturn(VatReturnFilingType::Supplementary);

// Následné kontrolní hlášení (po zjištění chyby)
$xml = $generator->generateControlStatement(ControlStatementFilingType::Subsequent);

// Následné KH jako odpověď na výzvu správce daně
$xml = $generator->generateControlStatement(
    filingType:             ControlStatementFilingType::Subsequent,
    authorityRequestNumber: '12345678/25/4501-00000-123456',
);
```

#### Přehled typů podání

**Daňové přiznání (`VatReturnFilingType`)**

| Case | Kód | Popis |
|------|-----|-------|
| `Regular` | B | Řádné |
| `Corrective` | O | Opravné (nahrazuje podané řádné) |
| `Supplementary` | D | Dodatečné |
| `SupplementaryCorrective` | E | Dodatečné/opravné |

**Kontrolní hlášení (`ControlStatementFilingType`)**

| Case | Kód | Popis |
|------|-----|-------|
| `Regular` | B | Řádné |
| `RegularCorrective` | O | Řádné/opravné (před uplynutím lhůty) |
| `Subsequent` | N | Následné (po uplynutí lhůty) |
| `SubsequentCorrective` | E | Následné/opravné |

---

## XSD validace

Balíček obsahuje přiložená XSD schémata z EPO portálu. Validaci lze volat samostatně kdykoli — například v testech nebo před odesláním.

```php
use JeanCz\CzechVat\Generator\XsdValidator;
use JeanCz\CzechVat\Exception\XmlValidationException;

try {
    XsdValidator::forControlStatement()->validate($ksXml);
    XsdValidator::forVatReturn()->validate($dpXml);
} catch (XmlValidationException $e) {
    echo $e->getMessage();

    // Detailní seznam chyb z libxml
    foreach ($e->errors as $error) {
        echo $error . PHP_EOL;
    }
}
```

Validátor lze použít i s vlastním XSD souborem:

```php
$validator = new XsdValidator('/cesta/k/memu/schematu.xsd');
$validator->validate($xml);
```

---

## Výjimky

Všechny výjimky rozšiřují `JeanCz\CzechVat\Exception\CzechVatException` (která rozšiřuje `\RuntimeException`), takže je lze chytat hromadně nebo jednotlivě.

| Výjimka | Kdy se vyhodí |
|---------|---------------|
| `InvalidInvoiceException` | Prázdný seznam řádků; záporný základ s kladným DPH; faktura nad 10 000 Kč bez povinných polí |
| `InvalidVatRateException` | Dotaz na typ sazby, který není v sazebníku; záporné procento v `VatRates::custom()` |
| `InvalidTaxpayerException` | Chybný formát DIČ; chybějící jméno / název firmy |
| `InvalidPeriodException` | Měsíc mimo 1–12; čtvrtletí mimo 1–4 |
| `XmlGenerationException` | Interní chyba DOM při sestavování XML |
| `XmlValidationException` | XML neodpovídá XSD schématu; obsahuje seznam chyb v `$e->errors` |

---

## Architektura

```
src/
├── VatFilingGenerator.php          ← fasáda, hlavní vstupní bod
│
├── Builder/
│   ├── AbstractXmlBuilder.php      ← sdílené DOM helpery, formátování
│   ├── ControlStatementBuilder.php ← sestavuje DPHKH1
│   └── VatReturnBuilder.php        ← sestavuje DPHDP3
│
├── Contract/
│   ├── XmlGeneratorInterface.php   ← generate(): string
│   └── XmlValidatorInterface.php   ← validate(string): void
│
├── Enum/
│   ├── VatRateType.php             ← Standard | Reduced | SecondReduced | Zero
│   ├── VatReturnFilingType.php     ← Regular | Corrective | Supplementary | …
│   ├── ControlStatementFilingType.php
│   ├── TaxpayerType.php            ← NaturalPerson | LegalEntity
│   └── VatPayerType.php            ← VatPayer | IdentifiedPerson | …
│
├── Exception/                      ← typovaná hierarchie výjimek
│
├── Generator/
│   └── XsdValidator.php            ← volitelná XSD validace přes libxml
│
└── Model/
    ├── Invoice/
    │   ├── InvoiceLine.php         ← jeden řádek: VatRateType + základ + DPH
    │   ├── Invoice.php             ← sada řádků + metadata + validace prahu
    │   └── InvoiceCollection.php   ← vydané + přijaté faktury, agregace
    ├── Period/
    │   └── TaxPeriod.php           ← měsíční nebo čtvrtletní období
    ├── Taxpayer/
    │   └── Taxpayer.php            ← plátce (FO / PO), named constructors
    └── VatRates/
        └── VatRates.php            ← konfigurovatelný sazebník DPH
```

**Klíčové principy:**

- **Žádné závislosti** — pouze standardní PHP s `ext-dom` a `ext-libxml`.
- **Immutable value objects** — `Invoice`, `InvoiceLine`, `TaxPeriod`, `Taxpayer` a `InvoiceCollection` jsou neměnné po vytvoření.
- **Validace v modelu** — `Invoice` vynucuje pravidla (práh 10 000 Kč, povinná pole) při konstrukci, ne až při generování XML.
- **Sazby oddělené od sémantiky** — `VatRateType` popisuje kategorii sazby, `VatRates` nese konkrétní procenta pro dané období.
- **EPO mapování inkapsulovano** — historické pojmenování atributů XSD (`zakl_dane1`, `obrat23`, `dan5`, …) je skryto uvnitř builderů a enumu `VatRateType::epoAttributeIndex()`.

---

## Rozšíření

### Vlastní builder

Implementujte `XmlGeneratorInterface` a předejte výsledné XML přímo do `XsdValidator`:

```php
use JeanCz\CzechVat\Contract\XmlGeneratorInterface;

final class MyCustomVatReturnBuilder implements XmlGeneratorInterface
{
    public function generate(): string
    {
        // vlastní implementace
    }
}

$xml = (new MyCustomVatReturnBuilder(...))->generate();
XsdValidator::forVatReturn()->validate($xml);
```

### Vlastní validátor

Implementujte `XmlValidatorInterface` pro vlastní validační logiku (např. odesílání na testovací endpoint EPO):

```php
use JeanCz\CzechVat\Contract\XmlValidatorInterface;

final class EpoSandboxValidator implements XmlValidatorInterface
{
    public function validate(string $xml): void
    {
        // HTTP požadavek na testovací EPO endpoint
    }
}
```

---

## Testování

```bash
composer install
./vendor/bin/phpunit
```

Testovací sada pokrývá unit testy modelů (`InvoiceLine`, `Invoice`, `TaxPeriod`, `VatRates`) i integrační testy s XSD validací obou výstupních formátů.