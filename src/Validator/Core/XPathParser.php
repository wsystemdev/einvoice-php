<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Validator\Core;

/**
 * @internal Parser for the XPath 2.0 subset used by the official EN 16931 / XRechnung schematron (SchXslt output).
 * Produces a small array AST evaluated by XPathEvaluator. Unsupported syntax throws XPathUnsupported, so a rule that
 * can't be read is reported as not covered instead of being guessed.
 */
final class XPathParser
{
    private const KIND_TESTS = ['node', 'text', 'element', 'attribute', 'comment', 'processing-instruction', 'document-node'];
    private const AXES = ['child', 'descendant', 'attribute', 'self', 'descendant-or-self', 'following-sibling', 'following',
        'parent', 'ancestor', 'preceding-sibling', 'preceding', 'ancestor-or-self'];

    /** @var list<array{0: string, 1: string}> [type, value] */
    private array $t = [];
    private int $i = 0;

    /** @var array<string, array> */
    private static array $cache = [];

    public static function parse(string $expr): array
    {
        if (isset(self::$cache[$expr])) {
            return self::$cache[$expr];
        }
        $p = new self();
        $p->t = self::lex($expr);
        $p->i = 0;
        $ast = $p->expr();
        if ($p->peek()[0] !== 'eof') {
            throw new XPathUnsupported("unexpected '{$p->peek()[1]}' in: {$expr}");
        }
        return self::$cache[$expr] = $ast;
    }

    /** @return list<array{0: string, 1: string}> */
    private static function lex(string $s): array
    {
        $out = [];
        $n = strlen($s);
        $i = 0;
        while ($i < $n) {
            $c = $s[$i];
            if (ctype_space($c)) {
                $i++;
                continue;
            }
            if ($c === '(' && ($s[$i + 1] ?? '') === ':') { // comment (: ... :)
                $e = strpos($s, ':)', $i + 2);
                $i = $e === false ? $n : $e + 2;
                continue;
            }
            if ($c === '"' || $c === "'") {
                $j = $i + 1;
                $v = '';
                while ($j < $n) {
                    if ($s[$j] === $c) {
                        if (($s[$j + 1] ?? '') === $c) {
                            $v .= $c;
                            $j += 2;
                            continue;
                        }
                        break;
                    }
                    $v .= $s[$j++];
                }
                $out[] = ['str', $v];
                $i = $j + 1;
                continue;
            }
            if (ctype_digit($c) || ($c === '.' && ctype_digit($s[$i + 1] ?? ''))) {
                preg_match('/\G(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?/', $s, $m, 0, $i);
                $out[] = ['num', $m[0]];
                $i += strlen($m[0]);
                continue;
            }
            if (preg_match('/\G[A-Za-z_\x80-\xff][\w.\-\x80-\xff]*(?::(?:[A-Za-z_\x80-\xff][\w.\-\x80-\xff]*|\*))?/', $s, $m, 0, $i)) {
                $w = $m[0];
                // "a:b" but not "axis::"
                if (str_ends_with($w, ':*') === false && str_contains($w, ':') && ($s[$i + strlen($w)] ?? '') === ':') {
                    $w = substr($w, 0, strpos($w, ':'));
                }
                $out[] = ['name', $w];
                $i += strlen($w);
                continue;
            }
            foreach (['//', '::', '!=', '<=', '>=', '..', ':='] as $op) {
                if (substr($s, $i, 2) === $op) {
                    $out[] = ['op', $op];
                    $i += 2;
                    continue 2;
                }
            }
            if (str_contains('/()[]@,|.=<>+-*$?', $c)) {
                if ($c === '*' && ($s[$i + 1] ?? '') === ':') { // *:local
                    preg_match('/\G\*:[A-Za-z_][\w.\-]*/', $s, $m, 0, $i);
                    $out[] = ['name', $m[0]];
                    $i += strlen($m[0]);
                    continue;
                }
                $out[] = ['op', $c];
                $i++;
                continue;
            }
            throw new XPathUnsupported("unexpected character '{$c}'");
        }
        $out[] = ['eof', ''];
        return $out;
    }

    private function peek(int $k = 0): array
    {
        return $this->t[$this->i + $k] ?? ['eof', ''];
    }

    private function next(): array
    {
        return $this->t[$this->i++] ?? ['eof', ''];
    }

    private function isOp(string $v, int $k = 0): bool
    {
        $t = $this->peek($k);
        return $t[0] === 'op' && $t[1] === $v;
    }

    private function isName(string $v, int $k = 0): bool
    {
        $t = $this->peek($k);
        return $t[0] === 'name' && $t[1] === $v;
    }

    private function expect(string $v): void
    {
        $t = $this->next();
        if ($t[1] !== $v) {
            throw new XPathUnsupported("expected '{$v}', got '{$t[1]}'");
        }
    }

    private function expr(): array
    {
        $items = [$this->single()];
        while ($this->isOp(',')) {
            $this->next();
            $items[] = $this->single();
        }
        return count($items) === 1 ? $items[0] : ['seq', $items];
    }

    private function single(): array
    {
        if (($this->isName('for') || $this->isName('let') || $this->isName('some') || $this->isName('every')) && $this->isOp('$', 1)) {
            $kw = $this->next()[1];
            $binds = [];
            do {
                if ($this->isOp(',')) {
                    $this->next();
                }
                $this->expect('$');
                $var = $this->next()[1];
                if ($kw === 'let') {
                    $this->expect(':=');
                } else {
                    $this->expect('in');
                }
                $binds[] = [$var, $this->single()];
            } while ($this->isOp(','));
            if ($kw === 'for' || $kw === 'let') {
                $this->expect('return');
            } else {
                $this->expect('satisfies');
            }
            return [$kw, $binds, $this->single()];
        }
        if ($this->isName('if') && $this->isOp('(', 1)) {
            $this->next();
            $this->expect('(');
            $c = $this->expr();
            $this->expect(')');
            $this->expect('then');
            $a = $this->single();
            $this->expect('else');
            return ['if', $c, $a, $this->single()];
        }
        return $this->orExpr();
    }

    private function orExpr(): array
    {
        $l = $this->andExpr();
        while ($this->isName('or')) {
            $this->next();
            $l = ['or', $l, $this->andExpr()];
        }
        return $l;
    }

    private function andExpr(): array
    {
        $l = $this->cmp();
        while ($this->isName('and')) {
            $this->next();
            $l = ['and', $l, $this->cmp()];
        }
        return $l;
    }

    private function cmp(): array
    {
        $l = $this->range();
        $t = $this->peek();
        if ($t[0] === 'op' && in_array($t[1], ['=', '!=', '<', '<=', '>', '>='], true)) {
            $this->next();
            return ['gcmp', $t[1], $l, $this->range()];
        }
        if ($t[0] === 'name' && in_array($t[1], ['eq', 'ne', 'lt', 'le', 'gt', 'ge'], true)) {
            $this->next();
            return ['vcmp', $t[1], $l, $this->range()];
        }
        if ($t[0] === 'name' && $t[1] === 'is') {
            throw new XPathUnsupported('node comparison "is"');
        }
        return $l;
    }

    private function range(): array
    {
        $l = $this->additive();
        if ($this->isName('to')) {
            $this->next();
            return ['to', $l, $this->additive()];
        }
        return $l;
    }

    private function additive(): array
    {
        $l = $this->mult();
        while ($this->isOp('+') || $this->isOp('-')) {
            $op = $this->next()[1];
            $l = ['arith', $op, $l, $this->mult()];
        }
        return $l;
    }

    private function mult(): array
    {
        $l = $this->union();
        while ($this->isOp('*') || $this->isName('div') || $this->isName('idiv') || $this->isName('mod')) {
            $op = $this->next()[1];
            $l = ['arith', $op, $l, $this->union()];
        }
        return $l;
    }

    private function union(): array
    {
        $l = $this->intersect();
        while ($this->isOp('|') || $this->isName('union')) {
            $this->next();
            $l = ['union', $l, $this->intersect()];
        }
        return $l;
    }

    private function intersect(): array
    {
        $l = $this->castExpr();
        while ($this->isName('intersect') || $this->isName('except')) {
            $op = $this->next()[1];
            $l = [$op, $l, $this->castExpr()];
        }
        return $l;
    }

    private function castExpr(): array
    {
        $l = $this->unary();
        foreach (['instance' => 'of', 'treat' => 'as', 'castable' => 'as', 'cast' => 'as'] as $kw => $kw2) {
            if ($this->isName($kw) && $this->isName($kw2, 1)) {
                $this->next();
                $this->next();
                $type = $this->next()[1];
                if ($this->isOp('(')) { // element() etc.
                    $this->next();
                    $this->expect(')');
                    $type .= '()';
                }
                $occ = '';
                if ($this->isOp('?') || $this->isOp('*') || $this->isOp('+')) {
                    $occ = $this->next()[1];
                }
                return [$kw, $l, $type, $occ];
            }
        }
        return $l;
    }

    private function unary(): array
    {
        $neg = false;
        while ($this->isOp('-') || $this->isOp('+')) {
            if ($this->next()[1] === '-') {
                $neg = !$neg;
            }
        }
        $p = $this->path();
        return $neg ? ['neg', $p] : $p;
    }

    private function path(): array
    {
        if ($this->isOp('/')) {
            $this->next();
            if ($this->startsStep()) {
                return ['path', ['root'], $this->relPath()];
            }
            return ['root'];
        }
        if ($this->isOp('//')) {
            $this->next();
            return ['path', ['root'], array_merge([['axis', 'descendant-or-self', ['kind', 'node'], []]], $this->relPath())];
        }
        $steps = $this->relPath();
        return count($steps) === 1 ? $steps[0] : ['path', null, $steps];
    }

    private function startsStep(): bool
    {
        $t = $this->peek();
        if ($t[0] === 'name') {
            return !in_array($t[1], ['and', 'or', 'div', 'mod', 'idiv', 'eq', 'ne', 'lt', 'le', 'gt', 'ge', 'to', 'union', 'intersect', 'except', 'then', 'else', 'return', 'satisfies', 'in'], true)
                || $this->isOp('(', 1) || $this->isOp('::', 1);
        }
        return $t[0] === 'op' && in_array($t[1], ['@', '.', '..', '*', '(', '$'], true) || $t[0] === 'str' || $t[0] === 'num';
    }

    /** @return list<array> */
    private function relPath(): array
    {
        $steps = [$this->step()];
        while ($this->isOp('/') || $this->isOp('//')) {
            if ($this->next()[1] === '//') {
                $steps[] = ['axis', 'descendant-or-self', ['kind', 'node'], []];
            }
            $steps[] = $this->step();
        }
        return $steps;
    }

    private function step(): array
    {
        $t = $this->peek();
        if ($t[0] === 'op' && $t[1] === '..') {
            $this->next();
            return ['axis', 'parent', ['kind', 'node'], $this->predicates()];
        }
        if ($t[0] === 'op' && $t[1] === '@') {
            $this->next();
            return ['axis', 'attribute', $this->nodeTest(true), $this->predicates()];
        }
        if ($t[0] === 'name' && $this->isOp('::', 1)) {
            if (!in_array($t[1], self::AXES, true)) {
                throw new XPathUnsupported("axis {$t[1]}");
            }
            $this->next();
            $this->next();
            return ['axis', $t[1], $this->nodeTest($t[1] === 'attribute'), $this->predicates()];
        }
        if ($t[0] === 'op' && $t[1] === '*') {
            return ['axis', 'child', $this->nodeTest(false), $this->predicates()];
        }
        if ($t[0] === 'name' && (!$this->isOp('(', 1) || in_array($t[1], self::KIND_TESTS, true))) {
            if (in_array($t[1], self::KIND_TESTS, true) && $this->isOp('(', 1)) {
                return ['axis', $t[1] === 'attribute' ? 'attribute' : 'child', $this->nodeTest(false), $this->predicates()];
            }
            return ['axis', 'child', $this->nodeTest(false), $this->predicates()];
        }
        $prim = $this->primary();
        $preds = $this->predicates();
        return $preds ? ['filter', $prim, $preds] : $prim;
    }

    private function nodeTest(bool $attr): array
    {
        $t = $this->next();
        if ($t[0] === 'op' && $t[1] === '*') {
            return ['any'];
        }
        if ($t[0] !== 'name') {
            throw new XPathUnsupported("node test '{$t[1]}'");
        }
        if (in_array($t[1], self::KIND_TESTS, true) && $this->isOp('(')) {
            $this->next();
            $arg = null;
            if (!$this->isOp(')')) {
                $arg = $this->next()[1];
            }
            $this->expect(')');
            if ($t[1] === 'element' || $t[1] === 'attribute') {
                return $arg === null || $arg === '*' ? ['kind', $t[1]] : throw new XPathUnsupported('element(name)');
            }
            return ['kind', $t[1]];
        }
        if (str_starts_with($t[1], '*:')) {
            return ['local', substr($t[1], 2)];
        }
        if (str_ends_with($t[1], ':*')) {
            return ['nsany', substr($t[1], 0, -2)];
        }
        return ['name', $t[1]];
    }

    /** @return list<array> */
    private function predicates(): array
    {
        $p = [];
        while ($this->isOp('[')) {
            $this->next();
            $p[] = $this->expr();
            $this->expect(']');
        }
        return $p;
    }

    private function primary(): array
    {
        $t = $this->next();
        if ($t[0] === 'str') {
            return ['lit', $t[1]];
        }
        if ($t[0] === 'num') {
            $v = $t[1];
            if (preg_match('/[eE]/', $v)) {
                return ['lit', (float) $v];
            }
            if (str_contains($v, '.')) {
                return ['lit', Dec::of($v)];
            }
            return ['lit', (int) $v];
        }
        if ($t[0] === 'op' && $t[1] === '$') {
            return ['var', $this->next()[1]];
        }
        if ($t[0] === 'op' && $t[1] === '(') {
            if ($this->isOp(')')) {
                $this->next();
                return ['seq', []];
            }
            $e = $this->expr();
            $this->expect(')');
            return ['paren', $e];
        }
        if ($t[0] === 'op' && $t[1] === '.') {
            return ['ctx'];
        }
        if ($t[0] === 'name' && $this->isOp('(')) {
            $this->next();
            $args = [];
            if (!$this->isOp(')')) {
                $args[] = $this->single();
                while ($this->isOp(',')) {
                    $this->next();
                    $args[] = $this->single();
                }
            }
            $this->expect(')');
            return ['call', $t[1], $args];
        }
        throw new XPathUnsupported("unexpected '{$t[1]}'");
    }
}
