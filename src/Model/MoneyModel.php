<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Model;

use WSystem\EInvoice\InputError;
use WSystem\EInvoice\Support\Py;

/**
 * @internal Shared money model: lines, allowances/charges, VAT groups and totals
 * (port of vatcheck/einvoice_cii.compute, the same rounding as the UBL builder).
 */
final class MoneyModel
{
    public static function dateCheck(array $j): void
    {
        foreach (['invoice_number', 'issue_date', 'seller', 'buyer', 'lines'] as $f) {
            if (!Py::t($j[$f] ?? null)) {
                throw new InputError("$f is required");
            }
        }
        if (!Codes::isDate($j['issue_date'])) {
            throw new InputError('issue_date must be YYYY-MM-DD');
        }
    }

    public static function currency(array $j): string
    {
        $cur = Py::upper(Py::t($j['currency'] ?? null) ? $j['currency'] : 'EUR');
        if (!preg_match('/^[A-Z]{3}$/D', $cur)) {
            throw new InputError('currency must be an ISO 4217 code like EUR');
        }
        return $cur;
    }

    private static function acList(array $j, string $key, string $path): array
    {
        $v = Py::t($j[$key] ?? null) ? $j[$key] : [];
        if (!Py::isList($v) || count($v) > 100) {
            throw new InputError("$path must be a list of up to 100 items");
        }
        return $v;
    }

    /** @return array{0: Decimal, 1: ?Decimal, 2: ?Decimal} */
    private static function acAmount(mixed $o, string $field): array
    {
        if (!Py::isDict($o)) {
            throw new InputError("$field must be an object");
        }
        $pct = ($o['percent'] ?? null) !== null ? Codes::dec($o['percent'], "$field.percent") : null;
        $base = ($o['base_amount'] ?? null) !== null ? Codes::dec($o['base_amount'], "$field.base_amount") : null;
        if (($o['amount'] ?? null) !== null) {
            $amt = Codes::dec($o['amount'], "$field.amount");
        } elseif ($pct !== null && $base !== null) {
            $amt = $base->mul($pct)->shift(2);
        } else {
            throw new InputError("$field.amount is required (or percent + base_amount)");
        }
        $amt = $amt->round(2);
        if ($amt->isNegative()) {
            throw new InputError("$field.amount must not be negative");
        }
        return [$amt, $pct, $base];
    }

    private static function reason(array $o, bool $charge): mixed
    {
        if (Py::t($o['reason'] ?? null)) {
            return $o['reason'];
        }
        return Py::t($o['reason_code'] ?? null) ? null : ($charge ? 'Zuschlag / Charge' : 'Rabatt / Discount');
    }

    public static function lineAcs(array $ln, string $P): array
    {
        $out = [];
        foreach ([[false, 'allowances'], [true, 'charges']] as [$kind, $key]) {
            foreach (self::acList($ln, $key, "$P.$key") as $k => $o) {
                [$amt, $pct, $base] = self::acAmount($o, "$P.{$key}[$k]");
                $out[] = ['charge' => $kind, 'amount' => $amt, 'percent' => $pct, 'base' => $base,
                    'reason' => self::reason($o, $kind), 'reason_code' => $o['reason_code'] ?? null];
            }
        }
        return $out;
    }

    /** @return array{0: string, 1: Decimal} */
    private static function catRate(array $o, string $field, ?array $default): array
    {
        $cat = Py::upper(Py::t($o['vat_category'] ?? null) ? $o['vat_category'] : ($default ? $default[0] : 'S'));
        if (($o['vat_rate'] ?? null) === null && $cat === 'S') {
            if ($default && $default[0] === 'S') {
                return [$cat, $default[1]];
            }
            throw new InputError("$field.vat_rate is required for VAT category S");
        }
        $rate = Codes::dec(Py::t($o['vat_rate'] ?? null) ? $o['vat_rate'] : 0, "$field.vat_rate");
        return [$cat, $cat !== 'O' ? $rate : Decimal::zero()];
    }

    /** @param array<string, array{cat: string, rate: Decimal, base: Decimal}> $groups */
    public static function docAcs(array $j, array $groups): array
    {
        $only = count($groups) === 1 ? [reset($groups)['cat'], reset($groups)['rate']] : null;
        $out = [];
        foreach ([[false, 'allowances'], [true, 'charges']] as [$kind, $key]) {
            foreach (self::acList($j, $key, $key) as $k => $o) {
                $F = "{$key}[$k]";
                [$amt, $pct, $base] = self::acAmount($o, $F);
                if (($o['vat_category'] ?? null) === null && ($o['vat_rate'] ?? null) === null && $only === null) {
                    throw new InputError("$F.vat_category and vat_rate are required when the invoice has more than one VAT rate");
                }
                [$cat, $rate] = self::catRate($o, $F, $only);
                $out[] = ['charge' => $kind, 'amount' => $amt, 'percent' => $pct, 'base' => $base, 'cat' => $cat, 'rate' => $rate,
                    'reason' => self::reason($o, $kind), 'reason_code' => $o['reason_code'] ?? null];
            }
        }
        return $out;
    }

    private static function addGroup(array &$groups, string $cat, Decimal $rate, Decimal $amount): void
    {
        $rate = $cat !== 'O' ? $rate : Decimal::zero();
        $k = $cat . '|' . $rate->key();
        if (!isset($groups[$k])) {
            $groups[$k] = ['cat' => $cat, 'rate' => $rate, 'base' => Decimal::zero()];
        }
        $groups[$k]['base'] = $groups[$k]['base']->add($amount);
    }

    public static function compute(array $j, bool $checkParties = true): array
    {
        self::dateCheck($j);
        $cur = self::currency($j);
        if (!Py::isList($j['lines']) || count($j['lines']) > 500) {
            throw new InputError('lines must be a list of up to 500 items');
        }
        if ($checkParties) {
            foreach (['seller', 'buyer'] as $r) {
                if (!Py::isDict($j[$r])) {
                    throw new InputError("$r must be an object");
                }
            }
        }
        $lines = [];
        $groups = [];
        $total = Decimal::zero();
        foreach ($j['lines'] as $idx => $ln) {
            $i = $idx + 1;
            $P = "lines[$idx]";
            if (!Py::isDict($ln) || !Py::t($ln['description'] ?? null)) {
                throw new InputError("$P.description is required");
            }
            $q = Codes::dec(array_key_exists('quantity', $ln) ? $ln['quantity'] : 1, "$P.quantity");
            $price = Codes::dec($ln['unit_price'] ?? null, "$P.unit_price");
            if ($price->isNegative()) {
                throw new InputError("$P.unit_price must not be negative (use a negative quantity)");
            }
            $cat = Py::upper(Py::t($ln['vat_category'] ?? null) ? $ln['vat_category'] : 'S');
            if ($cat === 'S' && ($ln['vat_rate'] ?? null) === null) {
                throw new InputError("$P.vat_rate is required for VAT category S");
            }
            $rate = Codes::dec(Py::t($ln['vat_rate'] ?? null) ? $ln['vat_rate'] : 0, "$P.vat_rate");
            $net = $q->mul($price)->round(2);
            $acs = self::lineAcs($ln, $P);
            foreach ($acs as $a) {
                $net = $net->add($a['charge'] ? $a['amount'] : $a['amount']->neg());
            }
            $total = $total->add($net);
            self::addGroup($groups, $cat, $rate, $net);
            $lines[] = ['id' => $i, 'q' => $q, 'price' => $price, 'net' => $net, 'cat' => $cat, 'rate' => $rate,
                'unit' => Codes::unit($ln['unit'] ?? null), 'acs' => $acs, 'raw_description' => $ln['description'],
                'name' => Py::cut(Py::str($ln['description']), 200), 'desc' => $ln['item_description'] ?? null,
                'sid' => $ln['seller_item_id'] ?? null];
        }
        $docAcs = self::docAcs($j, $groups);
        $allow = Decimal::zero();
        $charge = Decimal::zero();
        foreach ($docAcs as $a) {
            self::addGroup($groups, $a['cat'], $a['rate'], $a['charge'] ? $a['amount'] : $a['amount']->neg());
            if ($a['charge']) {
                $charge = $charge->add($a['amount']);
            } else {
                $allow = $allow->add($a['amount']);
            }
        }
        $vat = [];
        $vatTotal = Decimal::zero();
        foreach ($groups as $g) {
            $tax = in_array($g['cat'], Codes::NO_TAX, true) ? Decimal::zero() : $g['base']->mul($g['rate'])->shift(2)->round(2);
            $vatTotal = $vatTotal->add($tax);
            $code = $text = null;
            $hasEx = isset(Codes::EXEMPT_DEFAULTS[$g['cat']]);
            if ($hasEx) {
                [$code, $text] = Codes::EXEMPT_DEFAULTS[$g['cat']];
                if (Py::isDict($j['vat_exemption_code'] ?? null)) {
                    $code = array_key_exists($g['cat'], $j['vat_exemption_code']) ? $j['vat_exemption_code'][$g['cat']] : $code;
                }
                if (Py::isDict($j['vat_exemption_reason'] ?? null)) {
                    $text = array_key_exists($g['cat'], $j['vat_exemption_reason']) ? $j['vat_exemption_reason'][$g['cat']] : $text;
                }
            }
            $vat[] = ['cat' => $g['cat'], 'rate' => $g['rate'], 'base' => $g['base'], 'tax' => $tax, 'ex' => $hasEx,
                'ex_code' => $code, 'ex_text' => $text];
        }
        $basis = $total->sub($allow)->add($charge);
        $incl = $basis->add($vatTotal);
        $prepaid = Codes::dec(array_key_exists('prepaid_amount', $j) ? $j['prepaid_amount'] : 0, 'prepaid_amount');
        return ['cur' => $cur, 'lines' => $lines, 'doc_acs' => $docAcs, 'vat' => $vat, 'line_total' => $total, 'allow_total' => $allow,
            'charge_total' => $charge, 'basis' => $basis, 'vat_total' => $vatTotal, 'incl' => $incl, 'prepaid' => $prepaid,
            'payable' => $incl->sub($prepaid), 'is_cn' => in_array(Py::str(array_key_exists('type_code', $j) ? $j['type_code'] : '380'), Codes::CN_CODES, true)];
    }

    public static function totals(array $m): array
    {
        $t = ['currency' => $m['cur'], 'line_total' => $m['line_total']->a(), 'vat_total' => $m['vat_total']->a(),
            'total_with_vat' => $m['incl']->a(), 'payable' => $m['payable']->a()];
        if ($m['doc_acs']) {
            $t += ['allowance_total' => $m['allow_total']->a(), 'charge_total' => $m['charge_total']->a(), 'tax_basis_total' => $m['basis']->a()];
        }
        return $t;
    }
}
