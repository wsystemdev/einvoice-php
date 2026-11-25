# Code layout and conventions (for everyone working on this package)

Repo: github.com/wsystemdev/einvoice-php · Composer package: `wsystem/einvoice` · Licence: Apache-2.0 · PHP >= 8.1 · root namespace `WSystem\EInvoice\` (PSR-4 `src/`).

| Namespace / path | Owner stream | Contents |
|---|---|---|
| `WSystem\EInvoice\EInvoice` (`src/EInvoice.php`) | generator | Facade: `create(array)`, `validate(string)` (delegates to an injected `ValidatorInterface`) |
| `WSystem\EInvoice\Generator\` | generator | `Generator::xml()/create()`, `UblGenerator`, `CiiGenerator` (ports of vatcheck einvoice.build_ubl / einvoice_cii) |
| `WSystem\EInvoice\Pdf\` | generator | `HybridPdf` (ZUGFeRD/Factur-X PDF/A-3b writer), `AttachmentReader` (reads embedded XML from any PDF: **use it in the validator** for PDF input) |
| `WSystem\EInvoice\Model\` | shared | `InvoiceShape` (input array shape = API JSON), `Codes` (namespaces, CustomizationIDs, VAT categories, exemption defaults), `Decimal` (exact decimal, Python-Decimal semantics), `MoneyModel` (totals) |
| `WSystem\EInvoice\Validator\` | **validator** | `ValidatorInterface` (contract, result shape = API /einvoice/validate) + implementations: XSD, SaxonC, CoreRules, Http, Command |
| `WSystem\EInvoice\Support\Py` | shared, @internal | Python-semantics helpers for parity |
| `WSystem\EInvoice\InputError` | shared | Bad input (API's HTTP 400). Throw it for unreadable invoices too |
| `resources/kosit/xrechnung-3.0.2-2026-08-31/` | shared | Official KoSIT release, **unmodified**. XSD: `resources/{ubl/2.1/xsd/maindoc/UBL-Invoice-2.1.xsd, ubl/2.1/xsd/maindoc/UBL-CreditNote-2.1.xsd, cii/16b/xsd/CrossIndustryInvoice_100pD16B.xsd}`; XSLT: `resources/{ubl/2.1,cii/16b}/xsl/EN16931-*-validation.xsl`, `resources/xrechnung/3.0.2/xsl/XRechnung-{UBL,CII}-validation.xsl` (XSLT 2.0) |
| `tests/` | all | PHPUnit 11, one `*Test.php` per class; namespace `WSystem\EInvoice\Tests\` |
| `tests/fixtures/inputs.json` + `expected.json` | generator (read-only for others) | 391 invoice inputs; expected = the Python engine's XML, totals and **official validation verdict** (`valid`, `error_ids`, `warning_ids` from Saxon + KoSIT/CEN XSLT). The validator stream can assert its verdicts against `expected.json` |
| `tests/fixtures/validator/` | validator | Validator-only fixtures (add yours here) |
| `tools/parity/` | generator | Python reference generation (`gen_fixtures.py`, `py_ref.py`, needs /workspace/vatcheck venv) and `compare.php` |
| `.github/workflows/ci.yml` | generator (packaging) | Matrix PHP 8.1–8.4 phpunit. Add jobs, don't remove gates |

Rules: no new runtime dependency without an Apache/MIT/BSD licence (no LGPL/GPL); no network in default code paths; never bundle OpenPeppol BIS schematron; public text says "W System"; release tags are cut from `main` only by the packaging owner, after green CI.
Branches: work on `gen/*` (generator) or `val/*` (validator) branches and merge to main by PR or fast-forward once CI is green; don't push red commits to main.
