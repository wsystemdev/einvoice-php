# Changelog

## Unreleased
- Generator: UBL 2.1 (EN 16931, XRechnung 3.0, Peppol BIS 3 structure), UN/CEFACT CII D16B (EN 16931, XRechnung 3.0), byte-identical to the W System API (391 parity fixtures + 4,700 fuzzed invoices).
- Generator: ZUGFeRD 2.3 / Factur-X 1.07 (EN 16931 and XRECHNUNG profiles) as PDF/A-3b, built on setasign/fpdf (MIT) with embedded fonts, XMP and sRGB output intent. Visual text uses Windows-1252; other characters are simplified in the visual PDF only, with a note in the result.
- CI: every generated fixture checked with the KoSIT validator, veraPDF and Mustang.
- Package skeleton: namespaces `Generator`, `Validator`, `Model`, `Pdf`; KoSIT XRechnung 3.0.2 rules (2026-08-31) bundled unmodified.
