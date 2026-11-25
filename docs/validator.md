# Validating e-invoices

```php
use WSystem\EInvoice\Validator\Validator;

$result = (new Validator())->validate(file_get_contents('invoice.xml'));   // UBL, CII, or a ZUGFeRD/Factur-X/XRechnung PDF
$result['valid'];        // bool
$result['errors'];       // [{rule_id, severity, ruleset, message, location, business_terms, fix?}, ...]
$result['engine'];       // which engine ran the business rules: "php-core" | "saxonc" | "remote"
$result['conformance'];  // "official" (SaxonC, W System validator/API) or "pre-check" (php-core)
```

CLI: `vendor/bin/wsystem-einvoice validate [--rules auto|en16931|xrechnung] [--json] invoice.xml|invoice.pdf|-`

## What runs, and what a "valid" means

Out of the box, `validate()` runs XML Schema validation and a pure-PHP pre-check of the core EN 16931 and XRechnung
rules. For the complete official rule sets, install the optional SaxonC extension or point the validator at the
W System validator or API.

| Engine | How you get it | XML Schema | EN 16931 + XRechnung 3.0.2 business rules | Labels in every result |
|---|---|---|---|---|
| `php-core` (default) | nothing to install | yes (UBL 2.1 / CII D16B, bundled) | pre-check: a pure-PHP engine evaluates the bundled official rule files. It is tested against Saxon (see [validator-rules.md](validator-rules.md)), but it is **not** the official engine | `engine: "php-core"`, `conformance: "pre-check"`, `checked.EN16931: "core-subset"`, `rules_not_evaluated: [...]` |
| `saxonc` | the SaxonC-HE PHP extension ([saxonc.md](saxonc.md)), auto-detected | yes | the official KoSIT/CEN schematron (bundled, unmodified) | `engine: "saxonc"`, `conformance: "official"`, `checked.EN16931: true` |
| `remote` | `new HttpValidator(apiKey: …)`: sends the file to api.wsystem.dev (or the URL you give). **The invoice leaves your machine**; it's opt-in only and never a silent fallback | yes | official | `engine: "remote"`, `conformance: "official"`, `remote_url` |

The self-hosted W System validator (Docker, `wsv validate`) runs the same official rules offline. A PHP backend for it
is planned after v1.0. Until then, run it next to your app or in CI.

Peppol BIS 3 rules are not included (`checked.PeppolBIS3: false`). The only rules of Peppol origin are those that KoSIT
ships inside its XRechnung configuration.

Every result also carries `rule_set` (a readable description of what ran), `rule_versions`, `sources` and a
`disclaimer`. For php-core, the disclaimer says the result is a pre-check, not a conformance check.

Inputs and errors:

- PDF input: the embedded XML is read from the PDF/A-3 file (`factur-x.xml`, `zugferd-invoice.xml`, `xrechnung.xml`,
  then any `.xml` attachment). XML with DOCTYPE/ENTITY is refused, and files over 5 MB are refused.
- `InputError` is thrown for unreadable input: not XML, a PDF without XML, not an invoice.
- `RuleEvaluationError` (a subclass of `InputError`) is thrown when an official rule cannot be evaluated because a
  value has the wrong type. The official engine aborts on those, so no partial result is returned.
  `$e->xsdErrors` names the field.

Same shape as the API: the result matches `POST https://api.wsystem.dev/einvoice/validate` field for field, with the
same rule ids, messages and locations. With SaxonC, results are identical to the API on the parity corpus.

## Rule packs

The KoSIT XRechnung 3.0.2 configuration (2026-08-31, sha256 `2530cd10…`) is bundled. `wsystem-einvoice rules:update`
checks the published manifest. When a newer pack is listed, it downloads that official zip, verifies its SHA-256
(and refuses any mismatch) and unpacks it under `~/.cache/wsystem-einvoice/rules/`. The validator uses a downloaded
pack only when you opt in with `WSYSTEM_EINVOICE_RULES=<dir>`, and results then name that directory in `rule_versions`.
