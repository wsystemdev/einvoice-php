# wsystem/einvoice: EU e-invoices in PHP, offline

Create and check XRechnung 3.0 (UBL and CII), EN 16931 UBL/CII and ZUGFeRD / Factur-X PDF/A-3 invoices inside your own PHP application. No API key, no network.

> **Status: pre-release, under active development.** The API may change before v1.0.

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

For `zugferd`, `factur-x` and `zugferd-xrechnung`, `$r['pdf']` holds a PDF/A-3b file with the CII XML embedded (`factur-x.xml` / `xrechnung.xml`).

## Which rules run where

| Mode | XML Schema | EN 16931 rules | XRechnung 3.0.2 rules |
|---|---|---|---|
| Default (pure PHP) | yes | core subset (pre-check) | core subset (pre-check) |
| With the optional SaxonC extension | yes | complete official CEN rule set | complete official KoSIT rule set |
| With the W System validator (self-hosted) or the W System API | yes | complete | complete |

Out of the box, validation runs XML Schema validation and a pure-PHP pre-check of the core EN 16931 and XRechnung rules. For the complete official rule sets, install the optional SaxonC extension or point the validator at the W System validator or API. Peppol BIS 3 rules are not included.

Every file the generator produces is tested in CI with the official KoSIT validator, veraPDF (PDF/A-3b) and the Mustang ZUGFeRD validator.

The invoice array is the same JSON object as the W System API's `POST /einvoice/create`, and the output is byte-identical to it.

Licence: Apache-2.0. Bundled rule files keep their own licences (CEN EN 16931 artefacts: EUPL-1.2); see THIRD-PARTY-NOTICES.md. A technically valid file is not tax or legal advice.
