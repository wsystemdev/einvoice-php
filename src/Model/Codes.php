<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Model;

use WSystem\EInvoice\InputError;
use WSystem\EInvoice\Support\Py;

/** @internal constants and helpers shared by the UBL and CII builders (port of vatcheck/einvoice.py). */
final class Codes
{
    public const EN16931_ID = 'urn:cen.eu:en16931:2017';
    public const XRECHNUNG_ID = 'urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0';
    public const XRECHNUNG_EXT_ID = self::XRECHNUNG_ID . '#conformant#urn:xeinkauf.de:kosit:extension:xrechnung_3.0';
    public const XRECHNUNG_CVD_ID = self::XRECHNUNG_ID . '#compliant#urn:xeinkauf.de:kosit:xrechnung:cvd_0.9';
    public const PEPPOL_ID = 'urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0';
    public const PEPPOL_PROFILE = 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0';

    public const NS = [
        'ubl_inv' => 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2',
        'ubl_cn' => 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2',
        'cii' => 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100',
        'cbc' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
        'cac' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
        'rsm' => 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100',
        'ram' => 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100',
        'udt' => 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100',
        'qdt' => 'urn:un:unece:uncefact:data:standard:QualifiedDataType:100',
        'svrl' => 'http://purl.oclc.org/dsdl/svrl',
    ];

    /** category => [exemption reason code, default text] */
    public const EXEMPT_DEFAULTS = [
        'AE' => ['VATEX-EU-AE', 'Reverse charge'],
        'K' => ['VATEX-EU-IC', 'Intra-Community supply'],
        'G' => ['VATEX-EU-G', 'Export outside the EU'],
        'O' => ['VATEX-EU-O', 'Not subject to VAT'],
        'E' => [null, 'Exempt from VAT'],
    ];
    public const NO_TAX = ['O', 'E', 'AE', 'K', 'G', 'Z'];
    public const CN_CODES = ['381', '396', '81', '83', '532'];

    /** Peppol participant scheme by country, used when deriving an EndpointID from a VAT ID. */
    public const VAT_SCHEME = [
        'AT' => '9915', 'BE' => '0208', 'DE' => '9930', 'DK' => '0184', 'ES' => '9920', 'FR' => '9957', 'GR' => '9933',
        'HU' => '9910', 'IE' => '9935', 'IT' => '0211', 'LU' => '9938', 'NL' => '9944', 'PL' => '9945', 'PT' => '9946',
        'RO' => '9947', 'SE' => '0007', 'SI' => '9949', 'SK' => '9950', 'CZ' => '9929', 'EE' => '9931', 'FI' => '0216',
        'HR' => '9934', 'LT' => '9937', 'LV' => '9939', 'MT' => '9943', 'BG' => '9926', 'CY' => '9928', 'NO' => '0192',
    ];
    /** UN/ECE Rec 20 retired PK (pack); Rec 21 XPK is the valid code (BR-CL-23) */
    public const RETIRED_UNITS = ['PK' => 'XPK'];

    public const DISCLAIMER = 'Technical check against the official EN 16931 / XRechnung rule sets. A technically valid file is not tax or legal advice. '
        . 'Peppol BIS 3 specific rules (PEPPOL-EN16931-*) are not checked yet.';

    public static function dec(mixed $v, string $field): Decimal
    {
        try {
            return Decimal::of($v);
        } catch (\InvalidArgumentException) {
            throw new InputError("$field must be a number");
        }
    }

    public static function isDate(mixed $v): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/D', Py::str($v));
    }

    /** 'iso6523-actorid-upis::0208:0685660237' or '0208:0685660237' -> [value, scheme] */
    public static function splitParticipantId(mixed $s): ?array
    {
        $s = trim(Py::str(Py::t($s) ? $s : ''));
        if (preg_match('/^(?:iso6523-actorid-upis::)?(\d{4}):([^\s:@]+)$/iD', $s, $m)) {
            return [$m[2], $m[1]];
        }
        return null;
    }

    /** @return array{0: ?string, 1: ?string} [endpoint id, scheme] */
    public static function endpoint(array $p, string $fmt, string $role): array
    {
        if (Py::t($p['endpoint_id'] ?? null)) {
            $sp = self::splitParticipantId($p['endpoint_id']);
            if ($sp) {
                if (Py::t($p['endpoint_scheme'] ?? null) && trim(Py::str($p['endpoint_scheme'])) !== $sp[1]) {
                    throw new InputError("$role.endpoint_id carries scheme {$sp[1]} but $role.endpoint_scheme is " . Py::str($p['endpoint_scheme'])
                        . '; send the Peppol id as endpoint_scheme + endpoint_id, or the full id alone');
                }
                return $sp;
            }
            $eid = $p['endpoint_id'];
            $sch = Py::t($p['endpoint_scheme'] ?? null) ? $p['endpoint_scheme'] : (str_contains(Py::str($eid), '@') ? 'EM' : '0088');
            return [$eid, $sch];
        }
        $vat = Py::upper(str_replace(' ', '', Py::str(Py::t($p['vat_id'] ?? null) ? $p['vat_id'] : '')));
        if ($fmt === 'peppol-bis3') {
            $cc = substr($vat, 0, 2);
            $num = substr($vat, 2);
            if ($cc === 'BE' && $num !== '' && ctype_digit($num)) {
                return [$num, '0208'];
            }
            if ($cc === 'SK' && preg_match('/^\d{10}$/D', $num)) {
                return [$num, '0245'];
            }
            if ($cc === 'NO' && preg_match('/^\d{9}(MVA)?$/D', $num)) {
                return [substr($num, 0, 9), '0192'];
            }
            if ($cc === 'DK' && preg_match('/^\d{8}$/D', $num)) {
                return [$num, '0184'];
            }
            if ($cc === 'SE' && preg_match('/^\d{12}$/D', $num)) {
                return [substr($num, 0, 10), '0007'];
            }
            if ($cc === 'FI' && preg_match('/^\d{8}$/D', $num)) {
                return ['0037' . $num, '0216'];
            }
            if (isset(self::VAT_SCHEME[$cc]) && $vat !== '') {
                return [$vat, self::VAT_SCHEME[$cc]];
            }
            throw new InputError("$role.endpoint_id and endpoint_scheme are required for Peppol (could not derive from VAT ID)");
        }
        if (Py::t($p['email'] ?? null)) {
            return [$p['email'], 'EM'];
        }
        return [null, null];
    }

    public static function unit(mixed $u): mixed
    {
        $u = Py::t($u) ? $u : 'C62';
        return self::RETIRED_UNITS[Py::upper(trim(Py::str($u)))] ?? $u;
    }
}
