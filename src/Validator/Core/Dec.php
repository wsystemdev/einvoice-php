<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Validator\Core;

/**
 * @internal Exact xs:decimal for the pure-PHP rule engine (no bcmath/gmp needed). Value = (-1)^neg * int(digits) * 10^-scale.
 */
final class Dec
{
    private const DIV_SCALE = 18;

    private function __construct(public readonly bool $neg, public readonly string $digits, public readonly int $scale)
    {
    }

    private static function make(bool $neg, string $digits, int $scale): self
    {
        $digits = ltrim($digits, '0');
        if ($digits === '') {
            return new self(false, '0', 0);
        }
        while ($scale > 0 && $digits[-1] === '0') {
            $digits = substr($digits, 0, -1);
            $scale--;
        }
        if ($scale < 0) {
            $digits .= str_repeat('0', -$scale);
            $scale = 0;
        }
        return new self($neg, $digits, $scale);
    }

    /** Lexical xs:decimal ("12", "-1.50", ".5", "+3."); null when not castable. Exponent forms are for doubles only. */
    public static function tryParse(string $s): ?self
    {
        $s = trim($s, " \t\n\r");
        if (!preg_match('/^([+-])?(\d*)(?:\.(\d*))?$/D', $s, $m) || ($m[2] === '' && ($m[3] ?? '') === '')) {
            return null;
        }
        $frac = $m[3] ?? '';
        return self::make(($m[1] ?? '') === '-', $m[2] . $frac, strlen($frac));
    }

    public static function of(string $s): self
    {
        return self::tryParse($s) ?? throw new \InvalidArgumentException("not a decimal: {$s}");
    }

    public static function int(int $i): self
    {
        return self::make($i < 0, (string) abs($i), 0);
    }

    public static function fromFloat(float $f): ?self
    {
        if (!is_finite($f)) {
            return null;
        }
        $s = rtrim(rtrim(sprintf('%.17F', $f), '0'), '.');
        // shortest round-trip representation
        for ($p = 1; $p <= 17; $p++) {
            $t = sprintf('%.' . $p . 'G', $f);
            if ((float) $t === $f) {
                $s = $t;
                break;
            }
        }
        if (stripos($s, 'E') !== false) {
            $s = number_format($f, 20, '.', '');
        }
        return self::tryParse($s);
    }

    /** Exact decimal value of a binary double (Saxon's xs:decimal(xs:double) is exact: 0.1e0 -> 0.1000000000000000055511...). */
    public static function fromFloatExact(float $f): ?self
    {
        if (!is_finite($f)) {
            return null;
        }
        if ($f == 0.0) {
            return self::int(0);
        }
        $bits = unpack('J', pack('E', $f))[1];
        $neg = $bits < 0;
        $exp = ($bits >> 52) & 0x7FF;
        $man = $bits & 0xFFFFFFFFFFFFF;
        if ($exp === 0) {
            $exp = 1;
        } else {
            $man |= 1 << 52;
        }
        $e = $exp - 1075; // value = man * 2^e
        $digits = (string) $man;
        if ($e >= 0) {
            for ($i = 0; $i < $e; $i++) {
                $digits = self::umul($digits, '2');
            }
            return self::make($neg, $digits, 0);
        }
        // man * 2^e = man * 5^-e / 10^-e
        for ($i = 0; $i < -$e; $i++) {
            $digits = self::umul($digits, '5');
        }
        return self::make($neg, $digits, -$e);
    }

    public function isZero(): bool
    {
        return $this->digits === '0';
    }

    public function toFloat(): float
    {
        return (float) (string) $this;
    }

    public function __toString(): string
    {
        $d = $this->digits;
        if ($this->scale > 0) {
            $d = str_pad($d, $this->scale + 1, '0', STR_PAD_LEFT);
            $d = substr($d, 0, -$this->scale) . '.' . substr($d, -$this->scale);
        }
        return ($this->neg ? '-' : '') . $d;
    }

    /** @return array{0: string, 1: string, 2: int} aligned digit strings and common scale */
    private static function align(self $a, self $b): array
    {
        $s = max($a->scale, $b->scale);
        return [$a->digits . str_repeat('0', $s - $a->scale), $b->digits . str_repeat('0', $s - $b->scale), $s];
    }

    private static function ucmp(string $a, string $b): int
    {
        $a = ltrim($a, '0');
        $b = ltrim($b, '0');
        return strlen($a) !== strlen($b) ? strlen($a) <=> strlen($b) : strcmp($a, $b) <=> 0;
    }

    private static function uadd(string $a, string $b): string
    {
        $r = '';
        $c = 0;
        for ($i = strlen($a) - 1, $j = strlen($b) - 1; $i >= 0 || $j >= 0 || $c; $i--, $j--) {
            $s = $c + ($i >= 0 ? ord($a[$i]) - 48 : 0) + ($j >= 0 ? ord($b[$j]) - 48 : 0);
            $r .= chr(48 + $s % 10);
            $c = intdiv($s, 10);
        }
        return strrev($r);
    }

    /** a - b, a >= b */
    private static function usub(string $a, string $b): string
    {
        $r = '';
        $br = 0;
        for ($i = strlen($a) - 1, $j = strlen($b) - 1; $i >= 0; $i--, $j--) {
            $s = ord($a[$i]) - 48 - $br - ($j >= 0 ? ord($b[$j]) - 48 : 0);
            $br = $s < 0 ? 1 : 0;
            $r .= chr(48 + $s + 10 * $br);
        }
        $r = ltrim(strrev($r), '0');
        return $r === '' ? '0' : $r;
    }

    private static function umul(string $a, string $b): string
    {
        if (strlen($a) + strlen($b) < 18) {
            return (string) ((int) $a * (int) $b);
        }
        $res = array_fill(0, strlen($a) + strlen($b), 0);
        for ($i = strlen($a) - 1; $i >= 0; $i--) {
            for ($j = strlen($b) - 1; $j >= 0; $j--) {
                $p = (ord($a[$i]) - 48) * (ord($b[$j]) - 48) + $res[$i + $j + 1];
                $res[$i + $j + 1] = $p % 10;
                $res[$i + $j] += intdiv($p, 10);
            }
        }
        $r = ltrim(implode('', $res), '0');
        return $r === '' ? '0' : $r;
    }

    /** @return array{0: string, 1: string} integer quotient and remainder of a / b (b > 0) */
    private static function udivmod(string $a, string $b): array
    {
        $q = '';
        $r = '0';
        $n = strlen($a);
        for ($i = 0; $i < $n; $i++) {
            $r = ltrim($r . $a[$i], '0');
            if ($r === '') {
                $r = '0';
            }
            $d = 0;
            while (self::ucmp($r, $b) >= 0) {
                $r = self::usub($r, $b);
                $d++;
            }
            $q .= $d;
        }
        $q = ltrim($q, '0');
        return [$q === '' ? '0' : $q, $r];
    }

    public function add(self $o): self
    {
        [$a, $b, $s] = self::align($this, $o);
        if ($this->neg === $o->neg) {
            return self::make($this->neg, self::uadd($a, $b), $s);
        }
        $c = self::ucmp($a, $b);
        if ($c === 0) {
            return self::make(false, '0', 0);
        }
        return $c > 0 ? self::make($this->neg, self::usub($a, $b), $s) : self::make($o->neg, self::usub($b, $a), $s);
    }

    public function negate(): self
    {
        return $this->isZero() ? $this : new self(!$this->neg, $this->digits, $this->scale);
    }

    public function sub(self $o): self
    {
        return $this->add($o->negate());
    }

    public function mul(self $o): self
    {
        return self::make($this->neg !== $o->neg, self::umul($this->digits, $o->digits), $this->scale + $o->scale);
    }

    /**
     * Saxon's decimal division: scale max(18, scale(a) - scale(b) + 18), rounding HALF_DOWN, trailing zeros dropped
     * (1 div 3 = 0.333333333333333333; 1.5 div 7 = 0.2142857142857142857; 1 div 524288 = 0.000001907348632812).
     */
    public function div(self $o): self
    {
        if ($o->isZero()) {
            throw new XPathError('FOAR0001', 'division by zero');
        }
        $scale = max(self::DIV_SCALE, $this->scale - $o->scale + self::DIV_SCALE);
        // q = A * 10^(scale + sb - sa) / B
        [$q, $r] = self::udivmod($this->digits . str_repeat('0', $scale + $o->scale - $this->scale), $o->digits);
        if (self::ucmp(self::umul($r, '2'), $o->digits) > 0) {
            $q = self::uadd($q, '1');
        }
        return self::make($this->neg !== $o->neg, $q, $scale);
    }

    /** integer division truncating toward zero */
    public function idiv(self $o): self
    {
        if ($o->isZero()) {
            throw new XPathError('FOAR0001', 'division by zero');
        }
        [$a, $b] = self::align($this, $o);
        [$q] = self::udivmod($a, $b);
        return self::make($this->neg !== $o->neg, $q, 0);
    }

    /** XPath mod: sign of the dividend */
    public function mod(self $o): self
    {
        return $this->sub($this->idiv($o)->mul($o));
    }

    public function cmp(self $o): int
    {
        $d = $this->sub($o);
        return $d->isZero() ? 0 : ($d->neg ? -1 : 1);
    }

    public function abs(): self
    {
        return $this->neg ? $this->negate() : $this;
    }

    public function floor(): self
    {
        if ($this->scale === 0) {
            return $this;
        }
        $int = substr(str_pad($this->digits, $this->scale + 1, '0', STR_PAD_LEFT), 0, -$this->scale);
        $t = self::make($this->neg, $int, 0);
        return $this->neg ? $t->sub(self::int(1)) : $t;
    }

    public function ceiling(): self
    {
        return $this->negate()->floor()->negate();
    }

    /** fn:round: half toward positive infinity */
    public function round(int $precision = 0): self
    {
        $f = self::make(false, '1' . str_repeat('0', max(0, $precision)), 0);
        return $this->mul($f)->add(self::of('0.5'))->floor()->div($f);
    }

    public function roundHalfEven(int $precision = 0): self
    {
        $f = self::make(false, '1' . str_repeat('0', max(0, $precision)), 0);
        $x = $this->mul($f);
        $fl = $x->floor();
        $frac = $x->sub($fl);
        $c = $frac->cmp(self::of('0.5'));
        if ($c > 0 || ($c === 0 && $fl->mod(self::int(2))->abs()->cmp(self::int(1)) === 0)) {
            $fl = $fl->add(self::int(1));
        }
        return $fl->div($f);
    }

    public function isInteger(): bool
    {
        return $this->scale === 0;
    }
}
