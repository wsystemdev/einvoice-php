<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Validator;

/** XML Schema check (UBL 2.1 / CII D16B) with libxml, like the API's lxml check. Port of einvoice._xsd_errors. */
final class XsdCheck
{
    public const RULESET = 'XML Schema (UBL 2.1 / CII D16B)';
    public const FIX = 'The XML structure is wrong (unknown element, wrong order or bad value). Fix this first; business rules may be unreliable until it passes.';

    /** @return list<array<string, mixed>> */
    public static function errors(Document $doc, RulePack $rules): array
    {
        $prev = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $ok = $doc->dom->schemaValidate($rules->xsd($doc->documentType));
            $errs = $ok ? [] : libxml_get_errors();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
        $out = [];
        foreach (array_slice($errs, 0, 50) as $e) {
            if ($e->level === LIBXML_ERR_WARNING) {
                continue;
            }
            $msg = preg_replace('/\{[^}]+\}/', '', rtrim($e->message, "\n"));
            $out[] = ['rule_id' => 'XSD', 'severity' => 'error', 'ruleset' => self::RULESET,
                'message' => "Line {$e->line}: {$msg}", 'location' => null, 'business_terms' => [], 'fix' => self::FIX];
        }
        return $out;
    }
}
