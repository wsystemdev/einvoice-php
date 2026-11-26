<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Validator;

use WSystem\EInvoice\Model\Codes;

/**
 * Turns schematron output into result entries and applies profile severity rules.
 * Port of vatcheck einvoice._svrl, _clean, FIX_HINTS, _cl_terms, _extended_relax, _xrechnung_ext_level.
 */
final class Issues
{
    public const SVRL_NS = 'http://purl.oclc.org/dsdl/svrl';

    public const FIX_HINTS = [
        'BR-01' => 'Add the specification identifier (UBL cbc:CustomizationID / CII GuidelineSpecifiedDocumentContextParameter).',
        'BR-02' => 'Add an invoice number.',
        'BR-03' => 'Add the invoice issue date (YYYY-MM-DD).',
        'BR-05' => 'Add the invoice currency code (e.g. EUR).',
        'BR-06' => "Add the seller's legal name.",
        'BR-07' => "Add the buyer's name.",
        'BR-08' => "Add the seller's postal address.",
        'BR-09' => "Add the seller's country code (ISO 3166-1 alpha-2).",
        'BR-10' => "Add the buyer's postal address.",
        'BR-11' => "Add the buyer's country code.",
        'BR-16' => 'Add at least one invoice line.',
        'BR-CO-10' => 'Sum of line net amounts must equal the document line total: recompute LineExtensionAmount.',
        'BR-CO-13' => 'Total without VAT must be line total minus allowances plus charges.',
        'BR-CO-15' => 'Total with VAT must be total without VAT plus total VAT.',
        'BR-CO-16' => 'Amount due must be total with VAT minus prepaid amount plus rounding.',
        'BR-CO-17' => 'VAT amount per category must be taxable amount x rate, rounded to 2 decimals.',
        'BR-CO-26' => 'Give the seller a VAT ID, a legal registration ID or another identifier.',
        'BR-S-08' => 'VAT breakdown for standard rate must match the sum of the S-rated lines.',
        'BR-AE-02' => "Reverse charge (AE) needs the seller's VAT ID and the buyer's VAT ID.",
        'BR-DE-1' => 'XRechnung needs payment instructions (payment means).',
        'BR-DE-2' => 'XRechnung needs a seller contact (name, phone and e-mail).',
        'BR-DE-15' => 'XRechnung needs a Buyer reference (Leitweg-ID for German public buyers).',
        'BR-DE-16' => 'XRechnung needs the seller VAT ID, tax number or tax representative.',
        'PEPPOL-EN16931-R020' => 'Add the seller electronic address (EndpointID with schemeID).',
    ];

    /** (location substring, BT, party label); first match wins */
    private const COUNTRY_PARTY_BT = [
        ['AccountingSupplierParty', 'BT-40', 'seller'], ['SellerTradeParty', 'BT-40', 'seller'],
        ['AccountingCustomerParty', 'BT-55', 'buyer'], ['BuyerTradeParty', 'BT-55', 'buyer'],
        ['SellerTaxRepresentativeTradeParty', 'BT-69', 'seller tax representative'],
        ['TaxRepresentativeParty', 'BT-69', 'seller tax representative'],
        ['OriginCountry', 'BT-159', 'item country of origin'],
        ['ShipToTradeParty', 'BT-80', 'deliver-to'], ['Delivery', 'BT-80', 'deliver-to'],
    ];

    /** Severity overrides of KoSIT's "EN16931 XRechnung Extension" scenario (scenarios.xml customLevel, config 3.0.2 / 2026-08-31). */
    public const XRECHNUNG_EXT_LEVELS = [
        'cii' => ['BR-CL-23' => 'warning', 'BR-CL-21' => 'information', 'BR-CL-11' => 'information', 'BR-CL-10' => 'information',
            'BR-CL-24' => 'information', 'BR-CL-25' => 'information', 'BR-CL-26' => 'information',
            'CII-SR-452' => 'error', 'CII-SR-453' => 'error', 'CII-SR-454' => 'error', 'CII-SR-465' => 'error', 'CII-SR-466' => 'error',
            'CII-SR-475' => 'information', 'CII-SR-476' => 'information'],
        'ubl' => ['BR-CL-21' => 'information', 'BR-CL-23' => 'warning', 'BR-CL-24' => 'information', 'BR-CL-10' => 'information',
            'BR-CL-11' => 'information', 'BR-CL-25' => 'information', 'BR-CL-26' => 'information', 'BR-CO-16' => 'information',
            'UBL-CR-470' => 'information', 'UBL-CR-646' => 'information'],
    ];

    private const CII_STD_LEVELS = ['BR-CL-23' => 'warning', 'BR-CL-21' => 'warning',
        'CII-SR-452' => 'error', 'CII-SR-453' => 'error', 'CII-SR-454' => 'error', 'CII-SR-465' => 'error', 'CII-SR-466' => 'error',
        'CII-SR-475' => 'information', 'CII-SR-476' => 'information'];

    /**
     * Severity overrides of KoSIT's standard "EN16931 XRechnung" scenarios (scenarios.xml customLevel, config 3.0.2 / 2026-08-31),
     * per document type. Same table as XRECHNUNG_STD_LEVELS in the W System engine (vatcheck/einvoice.py, 0.3.157.1).
     */
    public const XRECHNUNG_STD_LEVELS = [
        'ubl_inv' => ['BR-CL-23' => 'warning', 'BR-CL-21' => 'warning', 'UBL-CR-646' => 'error'],
        'ubl_cn' => ['BR-CL-23' => 'warning', 'BR-CL-21' => 'warning'],
        'cii' => self::CII_STD_LEVELS,
    ];

    /** Severity overrides of KoSIT's "EN16931 XRechnung CVD" scenarios, per document type. */
    public const XRECHNUNG_CVD_LEVELS = [
        'ubl_inv' => ['BR-CL-13' => 'information', 'BR-CL-23' => 'warning', 'BR-CL-21' => 'warning', 'UBL-CR-646' => 'error'],
        'ubl_cn' => ['BR-CL-13' => 'information', 'BR-CL-23' => 'warning', 'BR-CL-21' => 'warning', 'UBL-CR-646' => 'error'],
        'cii' => ['BR-CL-13' => 'information'] + self::CII_STD_LEVELS,
    ];

    /**
     * Build one result entry. $flag is the schematron flag/role (fatal, error, warning, information).
     * @return array<string, mixed>
     */
    public static function entry(string $ruleId, string $flag, string $ruleset, string $text, ?string $location): array
    {
        $flag = strtolower($flag === '' ? 'fatal' : $flag);
        preg_match_all('/\b(B[TG]-\d+)\b/', $text, $m);
        $bts = array_values(array_unique($m[1]));
        sort($bts, SORT_STRING);
        $e = ['rule_id' => $ruleId, 'severity' => in_array($flag, ['warning', 'information', 'info'], true) ? 'warning' : 'error',
            'ruleset' => $ruleset, 'message' => self::clean($text), 'location' => $location, 'business_terms' => $bts];
        if (isset(self::FIX_HINTS[$ruleId])) {
            $e['fix'] = self::FIX_HINTS[$ruleId];
        }
        if (str_starts_with($ruleId, 'BR-CL-')) {
            [$clBts, $clFix] = self::clTerms($ruleId, (string) $location);
            if ($clBts && !$bts) {
                $e['business_terms'] = $clBts;
            }
            if ($clFix !== null && !isset($e['fix'])) {
                $e['fix'] = $clFix;
            }
        }
        return $e;
    }

    /** @return list<array<string, mixed>> entries from an SVRL report */
    public static function fromSvrl(string $svrl, string $ruleset): array
    {
        $dom = new \DOMDocument();
        if (!@$dom->loadXML($svrl, LIBXML_NONET | LIBXML_PARSEHUGE)) {
            throw new \RuntimeException('schematron backend returned no SVRL report');
        }
        $out = [];
        $walk = static function (\DOMNode $n) use (&$walk, &$out, $ruleset): void {
            foreach ($n->childNodes as $c) {
                if (!$c instanceof \DOMElement) {
                    continue;
                }
                if ($c->namespaceURI === self::SVRL_NS && ($c->localName === 'failed-assert' || $c->localName === 'successful-report')) {
                    $text = '';
                    foreach ($c->childNodes as $t) {
                        if ($t instanceof \DOMElement && $t->namespaceURI === self::SVRL_NS && $t->localName === 'text') {
                            $text = $t->textContent;
                            break;
                        }
                    }
                    $flag = $c->getAttribute('flag') ?: ($c->getAttribute('role') ?: 'fatal');
                    $out[] = self::entry($c->getAttribute('id'), $flag, $ruleset, $text, $c->hasAttribute('location') ? $c->getAttribute('location') : null);
                }
                $walk($c);
            }
        };
        $walk($dom);
        return $out;
    }

    public static function clean(string $text): string
    {
        $t = trim((string) preg_replace('/(*UCP)\s+/u', ' ', $text));
        return (string) preg_replace('/^\[[^\]]+\]\s*-?\s*/u', '', $t);
    }

    /** @return array{0: list<string>, 1: ?string} */
    public static function clTerms(string $rid, string $loc): array
    {
        $loc = (string) preg_replace('/\[[^\]]*\]/', '', $loc);
        if ($rid === 'BR-CL-14') {
            foreach (self::COUNTRY_PARTY_BT as [$key, $bt, $who]) {
                if (str_contains($loc, $key)) {
                    return [[$bt], "The {$who} country code ({$bt}) must be an ISO 3166-1 alpha-2 code such as DE, FR or AT, "
                        . "not a country name like 'Deutschland' or 'Germany'. Map names to codes before writing the XML."];
                }
            }
            return [[], 'Country codes must be ISO 3166-1 alpha-2 (e.g. DE), not country names.'];
        }
        if (in_array($rid, ['BR-CL-03', 'BR-CL-04', 'BR-CL-05'], true)) {
            return [[str_contains($loc, 'TaxCurrencyCode') ? 'BT-6' : 'BT-5'], "Use an ISO 4217 currency code such as EUR, not a symbol or a name like 'Euro'."];
        }
        return match ($rid) {
            'BR-CL-01' => [['BT-3'], 'Use a UNTDID 1001 invoice type code such as 380 (invoice) or 381 (credit note).'],
            'BR-CL-16' => [['BT-81'], 'Use a UNTDID 4461 payment means code such as 58 (SEPA credit transfer) or 30 (credit transfer).'],
            'BR-CL-17', 'BR-CL-18' => [[], 'Use a UNCL 5305 VAT category code: S, Z, E, AE, K, G, O, L or M.'],
            'BR-CL-23' => [['BT-130'], "Use a UN/ECE Recommendation 20/21 unit code such as C62 (piece), HUR (hour) or DAY, not 'Stk' or 'pcs'."],
            default => [[], null],
        };
    }

    /** Factur-X/ZUGFeRD EXTENDED permits CII elements the EN 16931 CII syntax binding restricts (CII-SR-*, CII-DT-*). */
    public static function extendedRelax(array $i): array
    {
        $rid = (string) ($i['rule_id'] ?? '');
        if (($i['ruleset'] ?? '') === 'EN16931' && ($i['severity'] ?? '') === 'error' && (str_starts_with($rid, 'CII-SR-') || str_starts_with($rid, 'CII-DT-'))) {
            $i['severity'] = 'warning';
            $i['profile_note'] = 'Allowed in Factur-X/ZUGFeRD EXTENDED; an error only for EN 16931 (COMFORT) and stricter profiles.';
        }
        return $i;
    }

    /**
     * The KoSIT scenario that matches the exact CustomizationID: [scenario name, severity table], or null.
     * @return array{0: string, 1: array<string, string>}|null
     */
    public static function kositLevels(string $profile, string $syntax, string $documentType, string $customizationId): ?array
    {
        $dt = $syntax === 'cii' ? 'cii' : $documentType;
        if ($profile === 'XRechnung Extension') {
            return ['Extension', self::XRECHNUNG_EXT_LEVELS[$syntax]];
        }
        if ($customizationId === Codes::XRECHNUNG_ID && isset(self::XRECHNUNG_STD_LEVELS[$dt])) {
            return ['XRechnung', self::XRECHNUNG_STD_LEVELS[$dt]];
        }
        if ($customizationId === Codes::XRECHNUNG_CVD_ID && isset(self::XRECHNUNG_CVD_LEVELS[$dt])) {
            return ['XRechnung CVD', self::XRECHNUNG_CVD_LEVELS[$dt]];
        }
        return null;
    }

    /** KoSIT XRechnung Extension scenario severity for one EN16931/XRechnung schematron issue. */
    public static function xrechnungExtLevel(array $i, string $syntax): array
    {
        return self::kositLevel($i, self::XRECHNUNG_EXT_LEVELS[$syntax], 'Extension');
    }

    /** Apply one KoSIT scenario severity (customLevel) to one EN16931/XRechnung schematron issue. */
    public static function kositLevel(array $i, array $levels, string $scenario): array
    {
        $lvl = $levels[(string) ($i['rule_id'] ?? '')] ?? null;
        if ($lvl === null || !in_array($i['ruleset'] ?? null, ['EN16931', 'XRechnung'], true)) {
            return $i;
        }
        $sev = $lvl === 'error' ? 'error' : 'warning';
        if ($sev === ($i['severity'] ?? null)) {
            return $i;
        }
        $i['severity'] = $sev;
        if ($scenario === 'Extension') {
            $i['profile_note'] = $sev === 'warning'
                ? 'Allowed in XRechnung Extension (KoSIT Extension scenario level: ' . $lvl . '); an error for standard XRechnung / EN 16931.'
                : 'An error in XRechnung Extension (KoSIT Extension scenario level: error).';
        } else {
            $i['profile_note'] = $sev === 'warning'
                ? "Reported as $lvl by the official KoSIT $scenario scenario (customLevel), not as an error; the file is not rejected for it."
                : "An error in the official KoSIT $scenario scenario (customLevel: error).";
        }
        return $i;
    }
}
