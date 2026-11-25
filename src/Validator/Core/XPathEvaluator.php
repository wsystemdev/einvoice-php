<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Validator\Core;

/**
 * @internal Evaluates XPathParser ASTs over a Tree with XPath 2.0 semantics (sequences, untypedAtomic promotion,
 * exact xs:decimal arithmetic, general/value comparisons) for the functions the official rule files use.
 * Items: Node, string (xs:string), Untyped, int (xs:integer), Dec (xs:decimal), float (xs:double), bool, DateV.
 */
final class XPathEvaluator
{
    /** @var array<string, string> prefix => namespace URI */
    public array $ns = [];
    /** @var array<string, list<mixed>> global variables (already evaluated) */
    public array $globals = [];
    /** @var array<string, \Closure> lazily evaluated globals */
    public array $lazyGlobals = [];
    /** @var array<string, array{params: list<array{0: string, 1: ?string}>, vars: list<array{0: string, 1: array}>, body: array}> */
    public array $functions = [];
    public ?Node $current = null;

    public function __construct(public readonly Tree $tree)
    {
    }

    /**
     * @param array<string, list<mixed>> $vars
     * @return list<mixed>
     */
    public function evaluate(array $ast, mixed $ctx, array $vars = [], int $pos = 1, int $size = 1): array
    {
        return $this->ev($ast, new Ctx($ctx, $pos, $size, $vars));
    }

    public function ebvOf(array $ast, mixed $ctx, array $vars = []): bool
    {
        return $this->ebv($this->ev($ast, new Ctx($ctx, 1, 1, $vars)));
    }

    // ------------------------------------------------------------------ core
    /** @return list<mixed> */
    private function ev(array $a, Ctx $c): array
    {
        switch ($a[0]) {
            case 'lit':
                return [$a[1]];
            case 'seq':
                $out = [];
                foreach ($a[1] as $x) {
                    array_push($out, ...$this->ev($x, $c));
                }
                return $out;
            case 'paren':
                return $this->ev($a[1], $c);
            case 'ctx':
                if ($c->item === null) {
                    throw new XPathError('XPDY0002', 'no context item');
                }
                return [$c->item];
            case 'root':
                return [$this->rootOf($c)];
            case 'var':
                return $this->variable($a[1], $c);
            case 'or':
                return [$this->ebv($this->ev($a[1], $c)) || $this->ebv($this->ev($a[2], $c))];
            case 'and':
                return [$this->ebv($this->ev($a[1], $c)) && $this->ebv($this->ev($a[2], $c))];
            case 'if':
                return $this->ebv($this->ev($a[1], $c)) ? $this->ev($a[2], $c) : $this->ev($a[3], $c);
            case 'for':
            case 'let':
            case 'some':
            case 'every':
                return $this->flwor($a, $c, 0, $c->vars);
            case 'gcmp':
                return [$this->generalCompare($a[1], $this->atomize($this->ev($a[2], $c)), $this->atomize($this->ev($a[3], $c)))];
            case 'vcmp':
                $l = $this->atomize($this->ev($a[2], $c));
                $r = $this->atomize($this->ev($a[3], $c));
                if (!$l || !$r) {
                    return [];
                }
                if (count($l) > 1 || count($r) > 1) {
                    throw new XPathError('XPTY0004', 'value comparison of a sequence');
                }
                $lv = $l[0] instanceof Untyped ? $l[0]->v : $l[0];
                $rv = $r[0] instanceof Untyped ? $r[0]->v : $r[0];
                return [$this->compareAtoms(['eq' => '=', 'ne' => '!=', 'lt' => '<', 'le' => '<=', 'gt' => '>', 'ge' => '>='][$a[1]], $lv, $rv)];
            case 'to':
                $l = $this->atomize($this->ev($a[1], $c));
                $r = $this->atomize($this->ev($a[2], $c));
                if (!$l || !$r) {
                    return [];
                }
                $from = $this->toInt($l[0]);
                $to = $this->toInt($r[0]);
                return $from > $to ? [] : range($from, $to);
            case 'arith':
                return $this->arith($a[1], $this->ev($a[2], $c), $this->ev($a[3], $c));
            case 'neg':
                $v = $this->atomize($this->ev($a[1], $c));
                if (!$v) {
                    return [];
                }
                return $this->arith('-', [0], [$v[0]]);
            case 'union':
                return $this->docOrder(array_merge($this->nodesOnly($this->ev($a[1], $c)), $this->nodesOnly($this->ev($a[2], $c))));
            case 'intersect':
            case 'except':
                $l = $this->nodesOnly($this->ev($a[1], $c));
                $r = [];
                foreach ($this->nodesOnly($this->ev($a[2], $c)) as $n) {
                    $r[$n->id] = true;
                }
                return $this->docOrder(array_values(array_filter($l, fn ($n) => ($a[0] === 'intersect') === isset($r[$n->id]))));
            case 'instance':
                return [$this->instanceOf($this->ev($a[1], $c), $a[2], $a[3])];
            case 'castable':
                try {
                    $v = $this->atomize($this->ev($a[1], $c));
                    if (!$v) {
                        return [$a[3] === '?'];
                    }
                    if (count($v) > 1) {
                        return [false];
                    }
                    $this->cast($v[0], $a[2]);
                    return [true];
                } catch (XPathError) {
                    return [false];
                }
            case 'cast':
                $v = $this->atomize($this->ev($a[1], $c));
                if (!$v) {
                    if ($a[3] === '?') {
                        return [];
                    }
                    throw new XPathError('XPTY0004', 'cast of empty sequence');
                }
                return [$this->cast($v[0], $a[2])];
            case 'treat':
                return $this->ev($a[1], $c);
            case 'call':
                return $this->call($a[1], $a[2], $c);
            case 'filter':
                $seq = $this->ev($a[1], $c);
                foreach ($a[2] as $p) {
                    $seq = $this->applyPredicate($seq, $p, $c);
                }
                return $seq;
            case 'axis':
                if (!$c->item instanceof Node) {
                    throw new XPathError('XPTY0020', 'axis step on a non-node');
                }
                return $this->axisStep($c->item, $a, $c);
            case 'path':
                return $this->path($a, $c);
        }
        throw new XPathUnsupported('expression ' . $a[0]);
    }

    private function rootOf(Ctx $c): Node
    {
        return $this->tree->root;
    }

    /** @return list<mixed> */
    private function variable(string $name, Ctx $c): array
    {
        if (array_key_exists($name, $c->vars)) {
            return $c->vars[$name];
        }
        if (array_key_exists($name, $this->globals)) {
            return $this->globals[$name];
        }
        if (isset($this->lazyGlobals[$name])) {
            $f = $this->lazyGlobals[$name];
            unset($this->lazyGlobals[$name]);
            return $this->globals[$name] = $f();
        }
        throw new XPathUnsupported("unknown variable \${$name}");
    }

    /** @param array<string, list<mixed>> $vars */
    private function flwor(array $a, Ctx $c, int $i, array $vars): array
    {
        [$kw, $binds, $ret] = $a;
        if ($i === count($binds)) {
            $r = $this->ev($ret, new Ctx($c->item, $c->pos, $c->size, $vars));
            return $kw === 'some' || $kw === 'every' ? [$this->ebv($r)] : $r;
        }
        [$name, $expr] = $binds[$i];
        $seq = $this->ev($expr, new Ctx($c->item, $c->pos, $c->size, $vars));
        if ($kw === 'let') {
            return $this->flwor($a, $c, $i + 1, [$name => $seq] + $vars);
        }
        $out = [];
        foreach ($seq as $item) {
            $r = $this->flwor($a, $c, $i + 1, [$name => [$item]] + $vars);
            if ($kw === 'some') {
                if ($r[0]) {
                    return [true];
                }
            } elseif ($kw === 'every') {
                if (!$r[0]) {
                    return [false];
                }
            } else {
                array_push($out, ...$r);
            }
        }
        return $kw === 'some' ? [false] : ($kw === 'every' ? [true] : $out);
    }

    // ------------------------------------------------------------------ paths
    private function path(array $a, Ctx $c): array
    {
        if ($a[1] !== null) {
            $seq = [$this->tree->root];
        } else {
            $first = array_shift($a[2]);
            $seq = $this->ev($first, $c);
        }
        foreach ($a[2] as $step) {
            $out = [];
            $allNodes = true;
            $n = count($seq);
            foreach ($seq as $i => $item) {
                if (!$item instanceof Node) {
                    throw new XPathError('XPTY0019', 'path step on an atomic value');
                }
                $r = $step[0] === 'axis' ? $this->axisStep($item, $step, $c) : $this->ev($step, new Ctx($item, $i + 1, $n, $c->vars));
                foreach ($r as $x) {
                    $out[] = $x;
                    if (!$x instanceof Node) {
                        $allNodes = false;
                    }
                }
            }
            $seq = $allNodes ? $this->docOrder($out) : $out;
        }
        return $seq;
    }

    /** @param list<mixed> $nodes */
    private function docOrder(array $nodes): array
    {
        $by = [];
        foreach ($nodes as $n) {
            $by[$n->id] = $n;
        }
        ksort($by);
        return array_values($by);
    }

    private function nodesOnly(array $s): array
    {
        foreach ($s as $x) {
            if (!$x instanceof Node) {
                throw new XPathError('XPTY0004', 'union of non-nodes');
            }
        }
        return $s;
    }

    /** @return list<Node> */
    private function axisStep(Node $n, array $step, Ctx $c): array
    {
        [, $axis, $test, $preds] = $step;
        $cand = match ($axis) {
            'child' => $n->children,
            'attribute' => $n->attrs,
            'self' => [$n],
            'parent' => $n->parent ? [$n->parent] : [],
            'descendant' => $this->descendants($n, false),
            'descendant-or-self' => $this->descendants($n, true),
            'ancestor' => $this->ancestors($n, false),
            'ancestor-or-self' => $this->ancestors($n, true),
            'following-sibling' => $n->parent && $n->kind !== Node::ATTR ? array_slice($n->parent->children, $n->sibIndex + 1) : [],
            'preceding-sibling' => $n->parent && $n->kind !== Node::ATTR ? array_reverse(array_slice($n->parent->children, 0, $n->sibIndex)) : [],
            'preceding' => $this->preceding($n),
            'following' => $this->following($n),
        };
        $principal = $axis === 'attribute' ? Node::ATTR : Node::ELEM;
        $sel = [];
        foreach ($cand as $x) {
            if ($this->testNode($x, $test, $principal)) {
                $sel[] = $x;
            }
        }
        foreach ($preds as $p) {
            $sel = $this->applyPredicate($sel, $p, $c);
        }
        $reverse = in_array($axis, ['ancestor', 'ancestor-or-self', 'preceding', 'preceding-sibling', 'parent'], true);
        return $reverse ? array_reverse($sel) : $sel; // predicates saw axis order; result in document order
    }

    private function descendants(Node $n, bool $self): array
    {
        $out = $self ? [$n] : [];
        $stack = array_reverse($n->children);
        while ($stack) {
            $x = array_pop($stack);
            $out[] = $x;
            for ($i = count($x->children) - 1; $i >= 0; $i--) {
                $stack[] = $x->children[$i];
            }
        }
        return $out;
    }

    /** nearest first */
    private function ancestors(Node $n, bool $self): array
    {
        $out = $self ? [$n] : [];
        for ($p = $n->parent; $p !== null; $p = $p->parent) {
            $out[] = $p;
        }
        return $out;
    }

    /** reverse document order, excluding ancestors and attributes */
    private function preceding(Node $n): array
    {
        $anc = [];
        foreach ($this->ancestors($n, false) as $a) {
            $anc[$a->id] = true;
        }
        $out = [];
        for ($i = $n->id - 1; $i >= 0; $i--) {
            $x = $this->tree->all[$i];
            if (!isset($anc[$x->id]) && $x->kind !== Node::ATTR && $x->kind !== Node::DOC) {
                $out[] = $x;
            }
        }
        return $out;
    }

    private function following(Node $n): array
    {
        $out = [];
        $start = $n;
        while ($start->kind === Node::ATTR) {
            $start = $start->parent;
        }
        $desc = [];
        foreach ($this->descendants($start, true) as $d) {
            $desc[$d->id] = true;
        }
        foreach ($start->attrs as $at) {
            $desc[$at->id] = true;
        }
        foreach ($this->tree->all as $x) {
            if ($x->id > $start->id && !isset($desc[$x->id]) && $x->kind !== Node::ATTR) {
                $out[] = $x;
            }
        }
        return $out;
    }

    private function testNode(Node $x, array $t, int $principal): bool
    {
        switch ($t[0]) {
            case 'any':
                return $x->kind === $principal;
            case 'name':
                if ($x->kind !== $principal) {
                    return false;
                }
                $qn = $t[1];
                if (($p = strpos($qn, ':')) !== false) {
                    $prefix = substr($qn, 0, $p);
                    $local = substr($qn, $p + 1);
                    if (!isset($this->ns[$prefix])) {
                        throw new XPathUnsupported("unknown prefix {$prefix}");
                    }
                    return $x->local === $local && $x->ns === $this->ns[$prefix];
                }
                return $x->local === $qn && $x->ns === '';
            case 'local':
                return $x->kind === $principal && $x->local === $t[1];
            case 'nsany':
                return $x->kind === $principal && $x->ns === ($this->ns[$t[1]] ?? throw new XPathUnsupported("unknown prefix {$t[1]}"));
            case 'kind':
                return match ($t[1]) {
                    'node' => true,
                    'text' => $x->kind === Node::TEXT,
                    'element' => $x->kind === Node::ELEM,
                    'attribute' => $x->kind === Node::ATTR,
                    'comment' => $x->kind === Node::COMMENT,
                    'processing-instruction' => $x->kind === Node::PI,
                    'document-node' => $x->kind === Node::DOC,
                };
        }
        return false;
    }

    private function applyPredicate(array $seq, array $p, Ctx $c): array
    {
        // fast path: [1], [last()] style numeric literal
        if ($p[0] === 'lit' && is_int($p[1])) {
            return isset($seq[$p[1] - 1]) ? [$seq[$p[1] - 1]] : [];
        }
        $out = [];
        $n = count($seq);
        foreach ($seq as $i => $item) {
            $r = $this->ev($p, new Ctx($item, $i + 1, $n, $c->vars));
            if (count($r) === 1 && (is_int($r[0]) || is_float($r[0]) || $r[0] instanceof Dec)) {
                $num = $r[0] instanceof Dec ? $r[0]->toFloat() : (float) $r[0];
                if ($num == $i + 1) {
                    $out[] = $item;
                }
            } elseif ($this->ebv($r)) {
                $out[] = $item;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ values
    /** @return list<mixed> */
    public function atomize(array $seq): array
    {
        $out = [];
        foreach ($seq as $x) {
            $out[] = $x instanceof Node ? new Untyped($x->stringValue()) : $x;
        }
        return $out;
    }

    public function ebv(array $seq): bool
    {
        if (!$seq) {
            return false;
        }
        $f = $seq[0];
        if ($f instanceof Node) {
            return true;
        }
        if (count($seq) > 1) {
            throw new XPathError('FORG0006', 'effective boolean value of a sequence of atomic values');
        }
        return match (true) {
            is_bool($f) => $f,
            is_string($f) => $f !== '',
            $f instanceof Untyped => $f->v !== '',
            is_int($f) => $f !== 0,
            is_float($f) => $f != 0.0 && !is_nan($f),
            $f instanceof Dec => !$f->isZero(),
            default => throw new XPathError('FORG0006', 'effective boolean value of a date'),
        };
    }

    public function str(mixed $x): string
    {
        return match (true) {
            $x instanceof Node => $x->stringValue(),
            $x instanceof Untyped => $x->v,
            is_string($x) => $x,
            is_bool($x) => $x ? 'true' : 'false',
            is_int($x) => (string) $x,
            $x instanceof Dec => (string) $x,
            is_float($x) => self::doubleToString($x),
            $x instanceof DateV => $x->lexical,
            default => '',
        };
    }

    public static function doubleToString(float $f): string
    {
        if (is_nan($f)) {
            return 'NaN';
        }
        if (is_infinite($f)) {
            return $f > 0 ? 'INF' : '-INF';
        }
        if ($f == 0.0) {
            return fdiv(1, $f) < 0 ? '-0' : '0';
        }
        $a = abs($f);
        if ($a >= 1e-6 && $a < 1e6) {
            $d = Dec::fromFloat($f);
            return $d === null ? (string) $f : (string) $d;
        }
        // XPath 2.0 canonical double: mantissa with exactly one digit before the point, "E" exponent
        $s = sprintf('%.17E', $f);
        for ($p = 0; $p <= 16; $p++) {
            $t = sprintf('%.' . $p . 'E', $f);
            if ((float) $t === $f) {
                $s = $t;
                break;
            }
        }
        [$m, $e] = explode('E', $s);
        if (!str_contains($m, '.')) {
            $m .= '.0';
        }
        return $m . 'E' . (int) $e;
    }

    private function toDouble(mixed $x): float
    {
        return match (true) {
            is_float($x) => $x,
            is_int($x) => (float) $x,
            $x instanceof Dec => $x->toFloat(),
            is_bool($x) => $x ? 1.0 : 0.0,
            default => self::parseDouble($this->str($x)),
        };
    }

    public static function parseDouble(string $s): float
    {
        $t = trim($s, " \t\n\r");
        if (preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?$/D', $t)) {
            return (float) $t;
        }
        return match ($t) {
            'INF', '+INF' => INF,
            '-INF' => -INF,
            default => NAN,
        };
    }

    private function toInt(mixed $x): int
    {
        if (is_int($x)) {
            return $x;
        }
        if ($x instanceof Untyped || is_string($x)) {
            $t = trim($this->str($x));
            if (!preg_match('/^[+-]?\d+$/D', $t)) {
                throw new XPathError('FORG0001', "not an integer '{$t}'");
            }
            return (int) $t;
        }
        if ($x instanceof Dec) {
            return (int) (string) $x->floor();
        }
        if (is_float($x)) {
            return (int) $x;
        }
        throw new XPathError('XPTY0004', 'not an integer');
    }

    private static function isNumeric(mixed $x): bool
    {
        return is_int($x) || is_float($x) || $x instanceof Dec;
    }

    /** @param list<mixed> $l @param list<mixed> $r */
    private function generalCompare(string $op, array $l, array $r): bool
    {
        foreach ($l as $a) {
            foreach ($r as $b) {
                if ($a instanceof Untyped && $b instanceof Untyped) {
                    [$x, $y] = [$a->v, $b->v];
                } elseif ($a instanceof Untyped) {
                    [$x, $y] = [$this->promoteUntyped($a, $b), $b];
                } elseif ($b instanceof Untyped) {
                    [$x, $y] = [$a, $this->promoteUntyped($b, $a)];
                } else {
                    [$x, $y] = [$a, $b];
                }
                if ($this->compareAtoms($op, $x, $y)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function promoteUntyped(Untyped $u, mixed $other): mixed
    {
        if (self::isNumeric($other)) {
            $d = self::parseDouble($u->v);
            if (is_nan($d) && !in_array(trim($u->v), ['NaN'], true)) {
                throw new XPathError('FORG0001', "cannot convert '{$u->v}' to xs:double");
            }
            return $d;
        }
        if (is_bool($other)) {
            return $this->cast($u, 'xs:boolean');
        }
        if ($other instanceof DateV) {
            return DateV::parseDate($u->v);
        }
        return $u->v;
    }

    private function compareAtoms(string $op, mixed $x, mixed $y): bool
    {
        if (self::isNumeric($x) && self::isNumeric($y)) {
            if (is_float($x) || is_float($y)) {
                $fx = $this->toDouble($x);
                $fy = $this->toDouble($y);
                if (is_nan($fx) || is_nan($fy)) {
                    return $op === '!=';
                }
                $c = $fx <=> $fy;
            } else {
                $c = ($x instanceof Dec ? $x : Dec::int($x))->cmp($y instanceof Dec ? $y : Dec::int($y));
            }
        } elseif (is_string($x) && is_string($y)) {
            $c = strcmp($x, $y) <=> 0;
        } elseif (is_bool($x) && is_bool($y)) {
            $c = $x <=> $y;
        } elseif ($x instanceof DateV && $y instanceof DateV) {
            $c = strcmp($x->key, $y->key) <=> 0;
        } else {
            throw new XPathError('XPTY0004', 'cannot compare ' . get_debug_type($x) . ' with ' . get_debug_type($y));
        }
        return match ($op) {
            '=' => $c === 0, '!=' => $c !== 0, '<' => $c < 0, '<=' => $c <= 0, '>' => $c > 0, '>=' => $c >= 0,
        };
    }

    /** @return list<mixed> */
    private function arith(string $op, array $l, array $r): array
    {
        $l = $this->atomize($l);
        $r = $this->atomize($r);
        if (!$l || !$r) {
            return [];
        }
        if (count($l) > 1 || count($r) > 1) {
            throw new XPathError('XPTY0004', 'arithmetic on a sequence');
        }
        $a = $l[0] instanceof Untyped ? $this->toDouble($l[0]) : $l[0];
        $b = $r[0] instanceof Untyped ? $this->toDouble($r[0]) : $r[0];
        if (!self::isNumeric($a) || !self::isNumeric($b)) {
            throw new XPathError('XPTY0004', 'arithmetic on a non-number');
        }
        if (is_float($a) || is_float($b)) {
            $x = $this->toDouble($a);
            $y = $this->toDouble($b);
            return [match ($op) {
                '+' => $x + $y, '-' => $x - $y, '*' => $x * $y,
                'div' => $y == 0.0 ? ($x == 0.0 || is_nan($x) ? NAN : ($x > 0 === (1 / $y > 0) ? INF : -INF)) : $x / $y,
                'mod' => $y == 0.0 ? NAN : fmod($x, $y),
                'idiv' => $y == 0.0 ? throw new XPathError('FOAR0001', 'idiv by zero') : (int) ($x / $y),
            }];
        }
        if (is_int($a) && is_int($b) && $op !== 'div') {
            if ($op === 'idiv' || $op === 'mod') {
                if ($b === 0) {
                    throw new XPathError('FOAR0001', 'division by zero');
                }
                return [$op === 'idiv' ? intdiv($a, $b) : $a % $b];
            }
            $res = match ($op) { '+' => $a + $b, '-' => $a - $b, '*' => $a * $b };
            if (is_int($res)) {
                return [$res];
            }
        }
        $x = $a instanceof Dec ? $a : Dec::int($a);
        $y = $b instanceof Dec ? $b : Dec::int($b);
        $res = match ($op) {
            '+' => $x->add($y), '-' => $x->sub($y), '*' => $x->mul($y), 'div' => $x->div($y),
            'mod' => $x->mod($y), 'idiv' => $x->idiv($y),
        };
        if ($op === 'idiv') {
            return [(int) (string) $res];
        }
        return [is_int($a) && is_int($b) && $op !== 'div' && $res->isInteger() ? (int) (string) $res : $res];
    }

    private function instanceOf(array $seq, string $type, string $occ): bool
    {
        if (!$seq) {
            return $occ === '?' || $occ === '*';
        }
        if (count($seq) > 1 && $occ !== '*' && $occ !== '+') {
            return false;
        }
        foreach ($seq as $x) {
            $ok = match ($type) {
                'element()' => $x instanceof Node && $x->kind === Node::ELEM,
                'attribute()' => $x instanceof Node && $x->kind === Node::ATTR,
                'text()' => $x instanceof Node && $x->kind === Node::TEXT,
                'node()' => $x instanceof Node,
                'xs:string' => is_string($x),
                'xs:decimal' => $x instanceof Dec || is_int($x),
                'xs:integer' => is_int($x),
                'xs:double' => is_float($x),
                'xs:boolean' => is_bool($x),
                'xs:date' => $x instanceof DateV,
                'xs:untypedAtomic' => $x instanceof Untyped,
                default => throw new XPathUnsupported("instance of {$type}"),
            };
            if (!$ok) {
                return false;
            }
        }
        return true;
    }

    public function cast(mixed $x, string $type): mixed
    {
        if ($x instanceof Node) {
            $x = new Untyped($x->stringValue());
        }
        $s = $this->str($x);
        switch ($type) {
            case 'xs:string':
                return $s;
            case 'xs:untypedAtomic':
                return new Untyped($s);
            case 'xs:decimal':
                if ($x instanceof Dec) {
                    return $x;
                }
                if (is_int($x)) {
                    return Dec::int($x);
                }
                if (is_float($x)) {
                    return Dec::fromFloatExact($x) ?? throw new XPathError('FOCA0002', 'cannot cast NaN/INF to xs:decimal');
                }
                if (is_bool($x)) {
                    return Dec::int($x ? 1 : 0);
                }
                return Dec::tryParse($s) ?? throw new XPathError('FORG0001', "cannot cast '{$s}' to xs:decimal");
            case 'xs:integer':
                if (is_int($x)) {
                    return $x;
                }
                if ($x instanceof Dec || is_float($x)) {
                    $d = $x instanceof Dec ? $x : (Dec::fromFloatExact($x) ?? throw new XPathError('FOCA0002', 'NaN to integer'));
                    $t = $d->cmp(Dec::int(0)) < 0 ? $d->ceiling() : $d->floor();
                    return (int) (string) $t;
                }
                if (is_bool($x)) {
                    return $x ? 1 : 0;
                }
                $t = trim($s, " \t\n\r");
                if (!preg_match('/^[+-]?\d+$/D', $t)) {
                    throw new XPathError('FORG0001', "cannot cast '{$s}' to xs:integer");
                }
                if (strlen(ltrim($t, '+-0')) > 18) {
                    return Dec::of($t); // beyond 64 bit: kept exact as an integer-valued decimal (IBAN mod 97 checks)
                }
                return (int) $t;
            case 'xs:double':
            case 'xs:float':
                if (self::isNumeric($x) || is_bool($x)) {
                    return $this->toDouble($x);
                }
                $d = self::parseDouble($s);
                if (is_nan($d) && trim($s) !== 'NaN') {
                    throw new XPathError('FORG0001', "cannot cast '{$s}' to xs:double");
                }
                return $d;
            case 'xs:boolean':
                if (is_bool($x)) {
                    return $x;
                }
                if (self::isNumeric($x)) {
                    return $this->ebv([$x]);
                }
                return match (trim($s)) {
                    'true', '1' => true, 'false', '0' => false,
                    default => throw new XPathError('FORG0001', "cannot cast '{$s}' to xs:boolean"),
                };
            case 'xs:date':
                if ($x instanceof DateV) {
                    return $x;
                }
                return DateV::parseDate($s);
        }
        throw new XPathUnsupported("cast to {$type}");
    }

    // ------------------------------------------------------------------ functions
    /** @param list<array> $args */
    private function call(string $name, array $args, Ctx $c): array
    {
        if (isset($this->functions[$name])) {
            return $this->userFunction($name, array_map(fn ($a) => $this->ev($a, $c), $args));
        }
        if (str_starts_with($name, 'fn:')) {
            $name = substr($name, 3);
        }
        $A = fn (int $i) => $this->ev($args[$i], $c);
        $S = function (int $i) use ($args, $c): string { // string argument (empty sequence -> '')
            if (!isset($args[$i])) {
                return $c->item === null ? '' : $this->str($c->item);
            }
            $v = $this->atomize($this->ev($args[$i], $c));
            if (count($v) > 1) {
                throw new XPathError('XPTY0004', 'string argument is a sequence');
            }
            return $v ? $this->str($v[0]) : '';
        };
        $one = function (int $i) use ($args, $c): mixed {
            $v = $this->atomize($this->ev($args[$i], $c));
            if (count($v) > 1) {
                throw new XPathError('XPTY0004', 'argument is a sequence');
            }
            return $v[0] ?? null;
        };
        switch ($name) {
            case 'true':
                return [true];
            case 'false':
                return [false];
            case 'not':
                return [!$this->ebv($A(0))];
            case 'boolean':
                return [$this->ebv($A(0))];
            case 'exists':
                return [(bool) $A(0)];
            case 'empty':
                return [!$A(0)];
            case 'count':
                return [count($A(0))];
            case 'position':
                return [$c->pos];
            case 'last':
                return [$c->size];
            case 'current':
                return $this->current ? [$this->current] : [];
            case 'root':
                return [$this->tree->root];
            case 'string':
                if (!$args) {
                    return [$c->item === null ? '' : $this->str($c->item)];
                }
                $v = $A(0);
                return [$v ? $this->str($v[0]) : ''];
            case 'data':
                return $this->atomize($A(0));
            case 'normalize-space':
                return [trim((string) preg_replace('/[ \t\r\n]+/', ' ', $S(0)), ' ')];
            case 'upper-case':
                return [mb_strtoupper($S(0), 'UTF-8')];
            case 'lower-case':
                return [mb_strtolower($S(0), 'UTF-8')];
            case 'string-length':
                return [mb_strlen($S(0), 'UTF-8')];
            case 'concat':
                $s = '';
                foreach (array_keys($args) as $i) {
                    $s .= $S($i);
                }
                return [$s];
            case 'contains':
                return [str_contains($S(0), $S(1))];
            case 'starts-with':
                return [str_starts_with($S(0), $S(1))];
            case 'ends-with':
                return [str_ends_with($S(0), $S(1))];
            case 'substring-after':
                $h = $S(0);
                $n = $S(1);
                $p = strpos($h, $n);
                return [$p === false ? '' : substr($h, $p + strlen($n))];
            case 'substring-before':
                $h = $S(0);
                $n = $S(1);
                $p = strpos($h, $n);
                return [$p === false || $n === '' ? '' : substr($h, 0, $p)];
            case 'substring':
                return [$this->substring($S(0), $one(1), isset($args[2]) ? $one(2) : null)];
            case 'translate':
                return [$this->translate($S(0), $S(1), $S(2))];
            case 'string-join':
                return [implode(isset($args[1]) ? $S(1) : '', array_map(fn ($x) => $this->str($x), $this->atomize($A(0))))];
            case 'matches':
                return [(bool) preg_match($this->regex($S(1), isset($args[2]) ? $S(2) : ''), $S(0))];
            case 'replace':
                return [(string) preg_replace($this->regex($S(1), isset($args[3]) ? $S(3) : ''), self::replacement($S(2)), $S(0))];
            case 'tokenize':
                $in = $S(0);
                if ($in === '') {
                    return [];
                }
                return preg_split($this->regex($S(1), isset($args[2]) ? $S(2) : ''), $in) ?: [];
            case 'string-to-codepoints':
                $s = $S(0);
                return $s === '' ? [] : array_map('mb_ord', mb_str_split($s, 1, 'UTF-8'));
            case 'codepoints-to-string':
                return [implode('', array_map(fn ($x) => mb_chr($this->toInt($x), 'UTF-8'), $this->atomize($A(0))))];
            case 'reverse':
                return array_reverse($A(0));
            case 'distinct-values':
                $out = [];
                foreach ($this->atomize($A(0)) as $x) {
                    $x = $x instanceof Untyped ? $x->v : $x;
                    foreach ($out as $y) {
                        try {
                            if ($this->compareAtoms('=', $x, $y)) {
                                continue 2;
                            }
                        } catch (XPathError) {
                        }
                    }
                    $out[] = $x;
                }
                return $out;
            case 'index-of':
                $out = [];
                $needle = $one(1);
                foreach ($this->atomize($A(0)) as $i => $x) {
                    try {
                        if ($this->generalCompare('=', [$x], [$needle])) {
                            $out[] = $i + 1;
                        }
                    } catch (XPathError) {
                    }
                }
                return $out;
            case 'name':
            case 'local-name':
            case 'namespace-uri':
                $n = $args ? ($A(0)[0] ?? null) : $c->item;
                if ($n === null) {
                    return [''];
                }
                if (!$n instanceof Node) {
                    throw new XPathError('XPTY0004', "{$name}() of a non-node");
                }
                return [$name === 'name' ? $n->qname : ($name === 'local-name' ? $n->local : $n->ns)];
            case 'number':
                $v = $args ? $this->atomize($A(0)) : ($c->item === null ? [] : $this->atomize([$c->item]));
                if (!$v) {
                    return [NAN];
                }
                try {
                    return [$v[0] instanceof Untyped || is_string($v[0]) ? self::parseDouble($this->str($v[0])) : $this->toDouble($v[0])];
                } catch (XPathError) {
                    return [NAN];
                }
            case 'sum':
                $v = $this->atomize($A(0));
                if (!$v) {
                    return isset($args[1]) ? $A(1) : [0];
                }
                $acc = array_shift($v);
                if ($acc instanceof Untyped) {
                    $acc = $this->toDouble($acc);
                }
                foreach ($v as $x) {
                    $acc = $this->arith('+', [$acc], [$x instanceof Untyped ? $this->toDouble($x) : $x])[0];
                }
                return [$acc];
            case 'avg':
                $v = $this->atomize($A(0));
                if (!$v) {
                    return [];
                }
                $s = $this->call('sum', [$args[0]], $c);
                return $this->arith('div', $s, [count($v)]);
            case 'max':
            case 'min':
                $v = array_map(fn ($x) => $x instanceof Untyped ? $this->toDouble($x) : $x, $this->atomize($A(0)));
                if (!$v) {
                    return [];
                }
                $best = array_shift($v);
                foreach ($v as $x) {
                    if ($this->compareAtoms($name === 'max' ? '>' : '<', $x, $best)) {
                        $best = $x;
                    }
                }
                return [$best];
            case 'abs':
            case 'floor':
            case 'ceiling':
            case 'round':
            case 'round-half-to-even':
                $x = $one(0);
                if ($x === null) {
                    return [];
                }
                if ($x instanceof Untyped) {
                    $x = $this->toDouble($x);
                }
                $prec = isset($args[1]) ? $this->toInt($one(1)) : 0;
                return [$this->numFn($name, $x, $prec)];
            case 'xs:decimal':
            case 'xs:integer':
            case 'xs:string':
            case 'xs:double':
            case 'xs:boolean':
            case 'xs:date':
                $v = $this->atomize($A(0));
                if (!$v) {
                    return [];
                }
                if (count($v) > 1) {
                    throw new XPathError('XPTY0004', "{$name}() of a sequence");
                }
                return [$this->cast($v[0], $name)];
            case 'current-date':
                return [DateV::parseDate(date('Y-m-d'))];
            case 'current-dateTime':
                return [new DateV(date('c'), date('Y-m-d\TH:i:s'), true)];
            case 'document-uri':
            case 'base-uri':
                return [];
            case 'system-property':
                return [''];
            case 'exactly-one':
                $v = $A(0);
                if (count($v) !== 1) {
                    throw new XPathError('FORG0005', 'exactly-one');
                }
                return $v;
            case 'zero-or-one':
                $v = $A(0);
                if (count($v) > 1) {
                    throw new XPathError('FORG0003', 'zero-or-one');
                }
                return $v;
        }
        throw new XPathUnsupported("function {$name}()");
    }

    private function numFn(string $name, mixed $x, int $prec): mixed
    {
        if (is_float($x)) {
            if (is_nan($x) || is_infinite($x)) {
                return $x;
            }
            return match ($name) {
                'abs' => abs($x),
                'floor' => floor($x),
                'ceiling' => ceil($x),
                'round' => $prec === 0 ? floor($x + 0.5) : (Dec::fromFloat($x)?->round($prec)->toFloat() ?? $x),
                'round-half-to-even' => (Dec::fromFloat($x)?->roundHalfEven($prec)->toFloat() ?? $x),
            };
        }
        if (is_int($x)) {
            return match ($name) {
                'abs' => abs($x),
                'round', 'round-half-to-even' => $prec >= 0 ? $x : (int) (string) Dec::int($x)->{$name === 'round' ? 'round' : 'roundHalfEven'}($prec),
                default => $x,
            };
        }
        if ($x instanceof Dec) {
            return match ($name) {
                'abs' => $x->abs(), 'floor' => $x->floor(), 'ceiling' => $x->ceiling(),
                'round' => $x->round($prec), 'round-half-to-even' => $x->roundHalfEven($prec),
            };
        }
        throw new XPathError('XPTY0004', "{$name}() of a non-number");
    }

    private function substring(string $s, mixed $start, mixed $len): string
    {
        $chars = mb_str_split($s, 1, 'UTF-8');
        $st = $this->roundXp($this->toDouble($start instanceof Untyped ? $this->toDouble($start) : $start));
        $ln = $len === null ? INF : $this->roundXp($this->toDouble($len instanceof Untyped ? $this->toDouble($len) : $len));
        $out = '';
        foreach ($chars as $i => $ch) {
            $p = $i + 1;
            if ($p >= $st && $p < $st + $ln) {
                $out .= $ch;
            }
        }
        return $out;
    }

    private function roundXp(float $f): float
    {
        return is_nan($f) || is_infinite($f) ? $f : floor($f + 0.5);
    }

    private function translate(string $s, string $from, string $to): string
    {
        $f = mb_str_split($from, 1, 'UTF-8');
        $t = mb_str_split($to, 1, 'UTF-8');
        $map = [];
        foreach ($f as $i => $ch) {
            if (!array_key_exists($ch, $map)) {
                $map[$ch] = $t[$i] ?? '';
            }
        }
        $out = '';
        foreach (mb_str_split($s, 1, 'UTF-8') as $ch) {
            $out .= array_key_exists($ch, $map) ? $map[$ch] : $ch;
        }
        return $out;
    }

    /** XSD/XPath regular expression -> PCRE. Unanchored; flags i, m, s, x. */
    private function regex(string $re, string $flags): string
    {
        static $cache = [];
        $k = $flags . "\0" . $re;
        if (isset($cache[$k])) {
            return $cache[$k];
        }
        if (preg_match('/\\\\[icIC]|\[[^\]]*-\[/', $re)) {
            throw new XPathUnsupported("XSD regex feature in {$re}");
        }
        $mods = 'u';
        foreach (str_split($flags) as $f) {
            if (in_array($f, ['i', 'm', 's', 'x'], true)) {
                $mods .= $f;
            }
        }
        $p = '/' . str_replace('/', '\\/', $re) . '/' . $mods;
        if (@preg_match($p, '') === false) {
            throw new XPathUnsupported("regex {$re}");
        }
        return $cache[$k] = $p;
    }

    private static function replacement(string $r): string
    {
        return (string) preg_replace('/\\\\([\\\\$])/', '\\\\$1', $r);
    }

    /** @param list<list<mixed>> $argv */
    private function userFunction(string $name, array $argv): array
    {
        $f = $this->functions[$name];
        $vars = [];
        foreach ($f['params'] as $i => [$pname, $ptype]) {
            $v = $argv[$i] ?? [];
            if ($ptype !== null && str_starts_with($ptype, 'xs:')) {
                $t = rtrim($ptype, '?*+');
                $v = array_map(fn ($x) => $x instanceof Untyped ? $this->cast($x, $t) : (is_int($x) && $t === 'xs:decimal' ? Dec::int($x) : $x), $this->atomize($v));
            }
            $vars[$pname] = $v;
        }
        $c = new Ctx(null, 1, 1, $vars);
        foreach ($f['vars'] as [$vname, $ast]) {
            $c = new Ctx(null, 1, 1, [$vname => $this->ev($ast, $c)] + $c->vars);
        }
        return $this->ev($f['body'], $c);
    }
}
