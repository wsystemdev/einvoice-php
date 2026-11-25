<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Model;

use WSystem\EInvoice\Support\Py;

/**
 * Small immutable arbitrary-precision decimal (no ext-bcmath / ext-gmp needed).
 * Semantics follow Python's decimal.Decimal for the operations the builders use:
 * exact add / sub / mul, exact division by powers of ten, quantize(0.01, ROUND_HALF_UP), normalize().
 */
final class Decimal
{
    /** @var string digits without sign or leading zeros ("0" for zero) */
    private string $digits;
    private int $exp;
    private bool $neg;

    private function __construct(string $digits, int $exp, bool $neg)
    {
        $digits = ltrim($digits, '0');
        if ($digits === '') {
            $digits = '0';
        }
        $this->digits = $digits;
        $this->exp = $exp;
        $this->neg = $neg;
    }

    public static function zero(): self
    {
        return new self('0', 0, false);
    }

    /** Parse like Python Decimal(str(v)). Throws \InvalidArgumentException on junk. */
    public static function of(mixed $v): self
    {
        if ($v instanceof self) {
            return $v;
        }
        if (is_bool($v) || $v === null || is_array($v) || is_object($v)) {
            throw new \InvalidArgumentException('not a number');
        }
        $s = is_float($v) ? Py::str($v) : trim((string) $v);
        if (!preg_match('/^([+-])?(\d*)(?:\.(\d*))?(?:[eE]([+-]?\d+))?$/', $s, $m) || ($m[2] === '' && ($m[3] ?? '') === '')) {
            throw new \InvalidArgumentException('not a number');
        }
        $int = $m[2];
        $frac = $m[3] ?? '';
        $e = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : 0;
        return new self($int . $frac, $e - strlen($frac), ($m[1] ?? '') === '-');
    }

    public function isZero(): bool
    {
        return $this->digits === '0';
    }

    public function isNegative(): bool
    {
        return $this->neg && !$this->isZero();
    }

    private static function align(self $a, self $b): array
    {
        $e = min($a->exp, $b->exp);
        $da = $a->digits === '0' ? '0' : $a->digits . str_repeat('0', $a->exp - $e);
        $db = $b->digits === '0' ? '0' : $b->digits . str_repeat('0', $b->exp - $e);
        return [$da, $db, $e];
    }

    private static function cmpDigits(string $a, string $b): int
    {
        $a = ltrim($a, '0');
        $b = ltrim($b, '0');
        if (strlen($a) !== strlen($b)) {
            return strlen($a) <=> strlen($b);
        }
        return strcmp($a, $b) <=> 0;
    }

    private static function addDigits(string $a, string $b): string
    {
        $r = '';
        $carry = 0;
        $i = strlen($a) - 1;
        $j = strlen($b) - 1;
        while ($i >= 0 || $j >= 0 || $carry) {
            $s = $carry + ($i >= 0 ? (int) $a[$i] : 0) + ($j >= 0 ? (int) $b[$j] : 0);
            $r = ($s % 10) . $r;
            $carry = intdiv($s, 10);
            $i--;
            $j--;
        }
        return $r === '' ? '0' : $r;
    }

    /** a - b with a >= b */
    private static function subDigits(string $a, string $b): string
    {
        $r = '';
        $borrow = 0;
        $i = strlen($a) - 1;
        $j = strlen($b) - 1;
        while ($i >= 0) {
            $s = (int) $a[$i] - $borrow - ($j >= 0 ? (int) $b[$j] : 0);
            $borrow = 0;
            if ($s < 0) {
                $s += 10;
                $borrow = 1;
            }
            $r = $s . $r;
            $i--;
            $j--;
        }
        return $r;
    }

    public function add(self|int|string $o): self
    {
        $o = self::of($o);
        [$da, $db, $e] = self::align($this, $o);
        $na = $this->isNegative();
        $nb = $o->isNegative();
        if ($na === $nb) {
            // Python: 0 + -0 -> 0 ; -0 + -0 -> -0 (only matters for zero results)
            return new self(self::addDigits($da, $db), $e, $na || ($this->neg && $o->neg));
        }
        $c = self::cmpDigits($da, $db);
        if ($c === 0) {
            return new self('0', $e, false);
        }
        return $c > 0 ? new self(self::subDigits($da, $db), $e, $na) : new self(self::subDigits($db, $da), $e, $nb);
    }

    public function neg(): self
    {
        return new self($this->digits, $this->exp, !$this->neg);
    }

    public function sub(self|int|string $o): self
    {
        return $this->add(self::of($o)->neg());
    }

    public function mul(self|int|string $o): self
    {
        $o = self::of($o);
        $a = $this->digits;
        $b = $o->digits;
        $res = array_fill(0, strlen($a) + strlen($b), 0);
        for ($i = strlen($a) - 1; $i >= 0; $i--) {
            for ($j = strlen($b) - 1; $j >= 0; $j--) {
                $p = (int) $a[$i] * (int) $b[$j] + $res[$i + $j + 1];
                $res[$i + $j + 1] = $p % 10;
                $res[$i + $j] += intdiv($p, 10);
            }
        }
        return new self(implode('', $res), $this->exp + $o->exp, $this->neg !== $o->neg);
    }

    /** exact division by 10^n */
    public function shift(int $n): self
    {
        return new self($this->digits, $this->exp - $n, $this->neg);
    }

    public function cmp(self|int|string $o): int
    {
        $d = $this->sub($o);
        return $d->isZero() ? 0 : ($d->neg ? -1 : 1);
    }

    /** quantize to $places decimals, ROUND_HALF_UP (away from zero on .5), like Python Decimal.quantize */
    public function round(int $places = 2): self
    {
        $target = -$places;
        if ($this->exp >= $target) {
            return new self($this->digits === '0' ? '0' : $this->digits . str_repeat('0', $this->exp - $target), $target, $this->neg);
        }
        $cut = $target - $this->exp; // digits to drop
        $d = str_pad($this->digits, $cut + 1, '0', STR_PAD_LEFT);
        $keep = substr($d, 0, strlen($d) - $cut);
        $first = (int) $d[strlen($d) - $cut];
        if ($first >= 5) {
            $keep = self::addDigits($keep, '1');
        }
        return new self($keep, $target, $this->neg);
    }

    /** Python format(v.normalize(), "f") with "-0" -> "0" (the engine's _num) */
    public function num(): string
    {
        if ($this->isZero()) {
            return '0';
        }
        $d = $this->digits;
        $e = $this->exp;
        while ($e < 0 && substr($d, -1) === '0') {
            $d = substr($d, 0, -1);
            $e++;
        }
        // normalize also strips trailing zeros of integers, but "f" format prints them back
        return ($this->neg ? '-' : '') . self::plain($d, $e);
    }

    private static function plain(string $d, int $e): string
    {
        if ($e >= 0) {
            return ($d === '0' ? '0' : $d . str_repeat('0', $e));
        }
        $n = -$e;
        if (strlen($d) <= $n) {
            return '0.' . str_repeat('0', $n - strlen($d)) . $d;
        }
        return substr($d, 0, strlen($d) - $n) . '.' . substr($d, strlen($d) - $n);
    }

    /** str() of the value as held (Python str(Decimal) for non-exponent cases) */
    public function __toString(): string
    {
        return ($this->neg ? '-' : '') . self::plain($this->digits, $this->exp);
    }

    /** the engine's _a(): quantize(0.01, HALF_UP) then str(). Keeps Python's "-0.00". */
    public function a(): string
    {
        return (string) $this->round(2);
    }

    /** canonical key for grouping (Decimal equality) */
    public function key(): string
    {
        return $this->isZero() ? '0' : $this->num();
    }

    public function toFloat(): float
    {
        return (float) (string) $this;
    }
}
