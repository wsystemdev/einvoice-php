<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Support;

use WSystem\EInvoice\Model\Decimal;

/**
 * @internal Helpers that reproduce the Python reference engine's semantics exactly
 * (truthiness, str(), XML escaping), so PHP and API output are byte-identical.
 */
final class Py
{
    /** Python truthiness: None, False, 0, 0.0, "", [] are false; "0" is true. */
    public static function t(mixed $v): bool
    {
        if ($v === null || $v === false || $v === '' || $v === [] || $v === 0 || $v === 0.0) {
            return false;
        }
        if ($v instanceof Decimal) {
            return !$v->isZero();
        }
        return true;
    }

    /** $a.get($k) */
    public static function g(array $a, string $k, mixed $default = null): mixed
    {
        return array_key_exists($k, $a) ? $a[$k] : $default;
    }

    /** truthy value or null (Python `a.get(k)` used in a boolean context) */
    public static function v(array $a, string $k): mixed
    {
        $v = $a[$k] ?? null;
        return self::t($v) ? $v : null;
    }

    /** Python str() */
    public static function str(mixed $v): string
    {
        if (is_bool($v)) {
            return $v ? 'True' : 'False';
        }
        if ($v === null) {
            return 'None';
        }
        if (is_float($v)) {
            if (is_nan($v)) {
                return 'nan';
            }
            if (is_infinite($v)) {
                return $v > 0 ? 'inf' : '-inf';
            }
            $s = json_encode($v, JSON_PRESERVE_ZERO_FRACTION);
            if (stripos($s, 'e') !== false) {
                // Python repr: 1e-05, 1e+16
                [$m, $e] = preg_split('/e/i', $s);
                $m = preg_replace('/\.0$/', '', $m);
                $e = (int) $e;
                return $m . 'e' . ($e < 0 ? '-' : '+') . str_pad((string) abs($e), 2, '0', STR_PAD_LEFT);
            }
            return $s;
        }
        if ($v instanceof Decimal) {
            return (string) $v;
        }
        if (is_array($v)) {
            return json_encode($v);
        }
        return (string) $v;
    }

    /** xml.sax.saxutils.escape(str(v)): & < > only */
    public static function x(mixed $v): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], self::str($v));
    }

    public static function upper(mixed $v): string
    {
        return mb_strtoupper(self::str($v), 'UTF-8');
    }

    public static function lower(mixed $v): string
    {
        return mb_strtolower(self::str($v), 'UTF-8');
    }

    /** Python str[:n] on code points */
    public static function cut(string $s, int $n): string
    {
        return mb_substr($s, 0, $n, 'UTF-8');
    }

    public static function isDict(mixed $v): bool
    {
        return is_array($v) && ($v === [] || !array_is_list($v));
    }

    public static function isList(mixed $v): bool
    {
        return is_array($v) && array_is_list($v);
    }
}
