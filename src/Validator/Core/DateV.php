<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Validator\Core;

/** @internal xs:date / xs:dateTime value, compared on a normalised key. */
final class DateV
{
    public function __construct(public readonly string $lexical, public readonly string $key, public readonly bool $dateTime = false)
    {
    }

    public static function parseDate(string $s): DateV
    {
        $s = trim($s);
        if (!preg_match('/^(-?\d{4,})-(\d{2})-(\d{2})(Z|[+-]\d{2}:\d{2})?$/D', $s, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new XPathError('FORG0001', "invalid xs:date '{$s}'");
        }
        return new DateV($s, sprintf('%s-%s-%s', $m[1], $m[2], $m[3]));
    }
}
