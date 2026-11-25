# Validator parity corpus: sources and licences

`files/` holds public sample invoices used only as test data. They are redistributed unmodified (or with one
deliberate defect, under `invalid/` and `national/`), with their licences:

| path | source | licence |
|---|---|---|
| `cen/` | CEN/TC 434 eInvoicing-EN16931, release validation-1.3.17, `examples/` (https://github.com/ConnectingEurope/eInvoicing-EN16931) | EUPL-1.2 |
| `kosit/` | KoSIT xrechnung-testsuite v2026-08-31, `instances/` (https://github.com/itplr-kosit/xrechnung-testsuite) | Apache-2.0 |
| `mustang/` | Mustang project ZUGFeRD/Factur-X samples (https://github.com/ZUGFeRD/mustangproject) | Apache-2.0 |
| `wsystem/`, `national/` | output of the W System engine for the package's own generator inputs | Apache-2.0 (this package) |
| `invalid/` | single-defect mutations of the files above (see `note` in golden.json) | licence of the original |

`golden.json` is the W System Python engine's result (official KoSIT/CEN schematron via Saxon-HE, XRechnung config
2026-08-31) for every file, produced by `tools/validator/build_validator_corpus.py`.
