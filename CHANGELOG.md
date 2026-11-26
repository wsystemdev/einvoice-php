# Changelog

## 0.9.0-beta.2 (security fix, not released yet)

### Security

- **Decompression bomb in PDF reading** (`Parser::parse()`, `validate()`, `Validator`): `/FlateDecode` streams were
  inflated with no output limit, so a 1 MB PDF could inflate to 1 GiB (about 2 GB of memory with `memory_limit=-1`, or a
  PHP fatal "Allowed memory size exhausted" error otherwise). Streams are now inflated incrementally with a byte counter:
  all decompressed streams of one PDF together may be at most 75,000,000 bytes (`AttachmentReader::MAX_INFLATED_BYTES`,
  the same limit as the W System API), and past it an `InputError` "decompressed data exceeds the limit of 75 MB" is
  thrown. The embedded XML must also fit the normal input limit (5 MB by default). Regression tests use a 1 GiB bomb and
  several object streams that only pass the limit together.
- Reading a PDF that really decompresses to the full 75 MB needs about 100 MB of PHP memory.

## 0.9.0-beta.1

First public beta of `wsystem/einvoice`.

### Validator

- Offline `validate()` for UBL, CII and ZUGFeRD / Factur-X / XRechnung PDF/A-3: XML Schema plus EN 16931 and XRechnung 3.0.2 business rules.
- Default engine is `php-core` (`conformance: "pre-check"`, `checked.EN16931: "core-subset"`): a pure-PHP pre-check over the bundled official rule files, not the official engine.
- Optional SaxonC-HE extension (`ext-saxonc`) is auto-detected and reported as `engine: "saxonc"`, `conformance: "official"`.
- Optional `HttpValidator` (`engine: "remote"`) sends the file to the W System validator or API. Opt-in only; the invoice leaves the machine.
- `Parser::parse()` for UBL, CII and hybrid PDFs. `wsystem-einvoice rules:update` checks the published manifest and verifies SHA-256 before unpacking a newer official pack.

### Generator

- UBL 2.1 (EN 16931, XRechnung 3.0, Peppol BIS 3 structure) and UN/CEFACT CII D16B, byte-identical to the W System API (439 parity fixtures plus fuzzed invoices).
- ZUGFeRD 2.3 / Factur-X 1.07 (EN 16931 and XRECHNUNG profiles) as PDF/A-3b, built on setasign/fpdf (MIT). Credit notes and allowances/charges are in the XML and on the visual invoice.
- `EInvoice`, `Builder` and `Generator::create()` auto-use the validator when present; a PDF is only built when the XML is valid. Results include `engine` and `conformance`.

### CLI and packaging

- `vendor/bin/wsystem-einvoice` supports `create`, `validate`, `parse`, `rules:update`, `--version`, `--help`, `--format` and `--json`. Validate output uses the same engine and conformance labels as the PHP API.
- Composer `suggest` names `ext-saxonc`. `composer.lock` is committed and resolved for PHP 8.1 (PHPUnit 10.5) so the 8.1–8.4 CI matrix can install it. `tests/` (and the large fixtures), `.github/` and `phpunit.xml.dist` are `export-ignore`.
- README and the Packagist description state that the default install is a php-core pre-check; official EN 16931 / XRechnung rules need SaxonC or the W System validator/API. Peppol BIS 3 rules are not included.

CI checks generated files with the official KoSIT validator, veraPDF (PDF/A-3b) and Mustang.
