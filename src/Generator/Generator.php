<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Generator;

use WSystem\EInvoice\Model\Codes;
use WSystem\EInvoice\Validator\ValidatorInterface;
use WSystem\EInvoice\Support\Py;

/**
 * Build an e-invoice from the same array shape as W System's POST /einvoice/create.
 * xml() returns UBL or CII; the PDF formats (zugferd, factur-x, zugferd-xrechnung) are built by Generator::create().
 */
final class Generator
{
    public const UBL_FORMATS = UblGenerator::FORMATS;
    public const CII_FORMATS = ['cii', 'xrechnung-cii', 'zugferd', 'factur-x', 'zugferd-xrechnung'];

    /** @return array{format: string, syntax: string, xml: string, totals: array, model: array, xrechnung: bool, pdf: ?array} */
    public static function xml(array $invoice): array
    {
        $fmt = CiiGenerator::normFormat($invoice['format'] ?? null);
        if (isset(CiiGenerator::FORMATS[$fmt])) {
            [$xml, $totals, $m] = CiiGenerator::build($invoice, $fmt);
            [$gid, $isPdf, $fname, $level] = CiiGenerator::FORMATS[$fmt];
            return ['format' => $fmt, 'syntax' => 'UN/CEFACT CII D16B', 'xml' => $xml, 'totals' => $totals, 'model' => $m,
                'xrechnung' => $gid === Codes::XRECHNUNG_ID, 'pdf' => $isPdf ? ['file_name' => $fname, 'level' => $level] : null];
        }
        [$xml, $totals, $m] = UblGenerator::build($invoice);
        $fmt = Py::lower(Py::t($invoice['format'] ?? null) ? $invoice['format'] : 'en16931');
        return ['format' => $fmt, 'syntax' => 'UBL 2.1', 'xml' => $xml, 'totals' => $totals, 'model' => $m,
            'xrechnung' => $fmt === 'xrechnung', 'pdf' => null];
    }

    /**
     * Same result shape as POST /einvoice/create: valid, format, syntax, totals, xml, errors, warnings, checked, notes
     * (+ pdf, pdf_filename, embedded_file, zugferd_profile for the PDF formats). Without a validator, "valid" is null.
     */
    public static function create(array $invoice, ?ValidatorInterface $validator = null): array
    {
        $validator = \WSystem\EInvoice\Validators::resolve($validator);
        $b = self::xml($invoice);
        $notes = [];
        foreach (($invoice['lines'] ?? []) as $ln) {
            if (is_array($ln) && isset(Codes::RETIRED_UNITS[Py::upper(trim(Py::str(Py::t($ln['unit'] ?? null) ? $ln['unit'] : '')))])) {
                $notes[] = 'Unit code PK (pack) was retired from UN/ECE Recommendation 20 and is rejected by BR-CL-23; written as XPK (package).';
                break;
            }
        }
        if ($b['format'] === 'peppol-bis3') {
            $notes[] = 'Built to the Peppol BIS Billing 3.0 structure and checked against EN 16931 only; Peppol-specific rules are not run.';
        }
        $v = $validator?->validate($b['xml'], $b['xrechnung'] ? 'xrechnung' : 'en16931');
        $engine = $v['engine'] ?? $v['backend'] ?? 'none';
        $r = ['valid' => $v['valid'] ?? null, 'format' => $b['format'], 'syntax' => $b['syntax'], 'totals' => $b['totals'], 'xml' => $b['xml'],
            'errors' => $v['errors'] ?? [], 'warnings' => $v['warnings'] ?? [], 'checked' => $v['checked'] ?? null,
            'validator' => $engine, 'engine' => $engine, 'conformance' => $v['conformance'] ?? null,
            'notes' => $notes, 'disclaimer' => $v['disclaimer'] ?? Codes::DISCLAIMER];
        if ($b['pdf'] !== null) {
            $r['embedded_file'] = $b['pdf']['file_name'];
            $r['zugferd_profile'] = $b['pdf']['level'] === 'EN 16931' ? 'EN 16931 (COMFORT)' : 'XRECHNUNG';
            if ($v !== null && !$v['valid']) {
                $r['pdf'] = null;
                $r['notes'][] = 'No PDF was built because the embedded XML has errors: fix them and call again.';
            } else {
                $pdf = \WSystem\EInvoice\Pdf\HybridPdf::build($invoice, $b['model'], $b['xml'], $b['format'], $b['pdf']['file_name'], $b['pdf']['level']);
                $safe = substr(preg_replace('/[^A-Za-z0-9._-]+/', '_', Py::str($invoice['invoice_number'])), 0, 80) ?: 'invoice';
                $r['pdf'] = $pdf;
                if (\WSystem\EInvoice\Pdf\PdfA3::$replaced) {
                    $r['notes'][] = 'Some characters are outside the PDF fonts (Windows-1252) and were simplified or shown as "?" in the visual PDF only; the embedded XML keeps them unchanged.';
                    $r['pdf_info_replaced_characters'] = true;
                }
                $r['pdf_filename'] = $safe . '.pdf';
                $r['pdf_info'] = ['standard' => 'PDF/A-3b', 'hybrid' => $b['pdf']['level'] === 'EN 16931' ? 'ZUGFeRD 2.3 / Factur-X 1.07 EN 16931' : 'ZUGFeRD 2.3 XRECHNUNG',
                    'af_relationship' => 'Alternative', 'bytes' => strlen($pdf), 'sha256' => hash('sha256', $pdf)];
            }
        }
        if ($b['xrechnung'] && $b['syntax'] !== 'UBL 2.1') {
            $r['notes'][] = 'XRechnung 3.0 in CII syntax: checked against EN 16931 and the KoSIT XRechnung 3.0.2 rules.';
        }
        return $r;
    }
}
