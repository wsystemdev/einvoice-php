<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Validator;

/**
 * Contract between the generator and the validator stream (WSystem\EInvoice\Validator\*).
 *
 * validate() takes the raw invoice bytes (UBL/CII XML, or a ZUGFeRD/Factur-X/XRechnung PDF/A-3 hybrid) and returns
 * the same shape as W System's POST /einvoice/validate, so results compare 1:1 with api.wsystem.dev:
 *
 *   valid: bool, error_count: int, warning_count: int,
 *   container: "xml"|"pdf", embedded_file?: string,
 *   syntax: "ubl"|"cii", document_type: "ubl_inv"|"ubl_cn"|"cii", customization_id: string, profile: string, notes: string[],
 *   checked: {xsd: bool, EN16931: bool|"core-subset", XRechnung: bool|"core-subset", PeppolBIS3: false},
 *   errors / warnings: list<{rule_id, severity: "error"|"warning", ruleset, message, location, business_terms: string[], fix?}>,
 *   engine: "php-core"|"saxonc"|"remote" (which engine ran the business rules; "backend" is the same value),
 *   conformance: "official"|"pre-check" (only SaxonC or the W System validator/API run the official rule sets),
 *   rule_set: string (human-readable: which rule sets ran, and how), rules_not_evaluated?: list (php-core only),
 *   rule_versions: array, sources: array, disclaimer: string
 * Throws RuleEvaluationError when an official rule hits a dynamic error (e.g. "XX" where a number is required); the
 * official engine aborts on those, so no backend returns a partial result.
 *
 * $rules: "auto" (XRechnung rules for XRechnung files, EN 16931 otherwise), "en16931" or "xrechnung".
 * Must throw \WSystem\EInvoice\InputError for unreadable input (not XML, PDF without XML, DOCTYPE/ENTITY, too large).
 */
interface ValidatorInterface
{
    /** @return array<string, mixed> */
    public function validate(string $data, string $rules = 'auto'): array;
}
