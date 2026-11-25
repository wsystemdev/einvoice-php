# wsystem/einvoice: EU e-invoices in PHP, offline

Create and check XRechnung 3.0 (UBL and CII), EN 16931 UBL/CII and ZUGFeRD / Factur-X PDF/A-3 invoices inside your own PHP application. No API key, no network.

> **Status: pre-release, under active development.** The API may change before v1.0. Packagist registration is pending; until then install from the GitHub repository (see below).

## Install

PHP 8.1 or newer. Once the package is on Packagist:

```bash
composer require wsystem/einvoice
```

Until then:

```bash
composer config repositories.wsystem-einvoice vcs https://github.com/wsystemdev/einvoice-php
composer require wsystem/einvoice:dev-main
```

```php
use WSystem\EInvoice\EInvoice;

$r = (new EInvoice())->create([
    'format' => 'xrechnung',            // en16931 | xrechnung | peppol-bis3 | cii | xrechnung-cii | zugferd | factur-x | zugferd-xrechnung
    'invoice_number' => 'INV-2026-001', 'issue_date' => '2026-10-18', 'due_date' => '2026-11-17',
    'buyer_reference' => '04011000-12345-34',
    'seller' => ['name' => 'Muster GmbH', 'vat_id' => 'DE123456789', 'street' => 'Hauptstr. 1', 'city' => 'Berlin',
                 'postal_code' => '10115', 'country' => 'DE', 'email' => 'billing@muster.de', 'phone' => '+49 30 1234', 'contact_name' => 'Anna Muster'],
    'buyer'  => ['name' => 'Kunde AG', 'vat_id' => 'DE811193231', 'street' => 'Ring 2', 'city' => 'Muenchen', 'postal_code' => '80331', 'country' => 'DE', 'email' => 'ap@kunde.de'],
    'lines'  => [['description' => 'Consulting', 'quantity' => 10, 'unit' => 'HUR', 'unit_price' => '95.50', 'vat_rate' => 19]],
    'payment' => ['iban' => 'DE89370400440532013000', 'terms' => '30 days net'],
]);
file_put_contents('invoice.xml', $r['xml']);
```

For `zugferd`, `factur-x` and `zugferd-xrechnung`, `$r['pdf']` holds a PDF/A-3b file with the CII XML embedded (`factur-x.xml` / `xrechnung.xml`). Credit notes (`type_code` `381`, plus a preceding invoice) and allowances or charges on the lines or the document are included in both the XML and the visual PDF.

`$r['engine']` is `php-core` (default), `saxonc`, or `remote`. `$r['conformance']` is `pre-check` or `official`.

## Command line

```bash
vendor/bin/wsystem-einvoice --version
vendor/bin/wsystem-einvoice create examples/invoice.json invoice.xml
vendor/bin/wsystem-einvoice create --format zugferd examples/invoice.json invoice.pdf
vendor/bin/wsystem-einvoice validate invoice.xml
vendor/bin/wsystem-einvoice parse invoice.xml
```

On the default install, `validate` prints a line like:

```text
valid [php-core] EN 16931 + XRechnung 3.0.2: pre-check by the W System pure-PHP engine over the bundled KoSIT/CEN rule files (...), not the official engine
```

With the SaxonC-HE extension loaded, that becomes `valid [saxonc] … official KoSIT/CEN schematron …`. `--json` prints the same fields as the PHP API (`engine`, `conformance`, `checked`, `errors`).

## Which rules run where

| Engine | How it's enabled | XML Schema | EN 16931 / XRechnung 3.0.2 business rules | Labels in every result |
|---|---|---|---|---|
| `php-core` (default) | nothing to install | yes | pre-check only: a pure-PHP engine runs the bundled official rule files; it is not the official engine | `engine: "php-core"`, `conformance: "pre-check"`, `checked.EN16931: "core-subset"` |
| `saxonc` | optional SaxonC-HE PHP extension (`ext-saxonc`), auto-detected | yes | official KoSIT/CEN schematron (bundled, unmodified) | `engine: "saxonc"`, `conformance: "official"` |
| `remote` | `HttpValidator` (opt-in; the file is sent to the W System validator or API) | yes | official | `engine: "remote"`, `conformance: "official"` |

Out of the box, validation runs XML Schema validation and a pure-PHP pre-check (`php-core`) of the EN 16931 and XRechnung rules. A valid result in this mode is a pre-check, not a conformance check. For the complete official rule sets, install the optional SaxonC-HE extension (`ext-saxonc`) or point the validator at the W System validator or API (`remote`). Peppol BIS 3 rules are not included.

Every file the generator produces is tested in CI with the official KoSIT validator, veraPDF (PDF/A-3b) and the Mustang ZUGFeRD validator.

The invoice array is the same JSON object as the W System API's `POST /einvoice/create`, and the output is byte-identical to it.

Licence: Apache-2.0. Bundled rule files keep their own licences (CEN EN 16931 artefacts: EUPL-1.2); see THIRD-PARTY-NOTICES.md. A technically valid file is not tax or legal advice.
