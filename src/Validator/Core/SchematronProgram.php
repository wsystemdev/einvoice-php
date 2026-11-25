<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Validator\Core;

/**
 * @internal Reads an official SchXslt-compiled schematron XSLT (the bundled CEN / KoSIT files, unmodified) and runs
 * its rules with the pure-PHP XPath engine, reproducing SchXslt's semantics: per pattern, the first matching rule
 * (highest priority) fires for a node and later ones are suppressed; nodes are visited in document order, attributes
 * before children; the report lists patterns in declaration order.
 */
final class SchematronProgram
{
    private const XSL = 'http://www.w3.org/1999/XSL/Transform';
    private const SVRL = 'http://purl.oclc.org/dsdl/svrl';
    private const SCHXSLT = 'https://doi.org/10.5281/zenodo.1495494';

    /** @var array<string, self> */
    private static array $loaded = [];

    /** @var array<string, string> */
    public array $ns = [];
    /** @var list<array{0: string, 1: ?string, 2: ?string}> name, select, text content */
    private array $globals = [];
    /** @var array<string, array> */
    private array $functionDefs = [];
    /** @var list<string> pattern ids in report order */
    public array $patterns = [];
    /** @var list<array{match: string, priority: float, pattern: string, asserts: list<array>}> by mode order then priority desc */
    public array $rules = [];
    /** @var array<string, string> rule id => why it can't run in pure PHP */
    public array $unsupported = [];

    public static function load(string $file): self
    {
        return self::$loaded[$file] ??= new self($file);
    }

    private function __construct(public readonly string $file)
    {
        $dom = new \DOMDocument();
        if (!$dom->load($file, LIBXML_NONET | LIBXML_PARSEHUGE)) {
            throw new \RuntimeException("cannot read rule file {$file}");
        }
        $xp = new \DOMXPath($dom);
        foreach ($xp->query('namespace::*', $dom->documentElement) as $n) {
            if ($n->prefix !== '' && $n->prefix !== 'xml') {
                $this->ns[$n->localName] = $n->nodeValue;
            }
        }
        $xp->registerNamespace('xsl', self::XSL);
        $xp->registerNamespace('svrl', self::SVRL);
        $xp->registerNamespace('schxslt', self::SCHXSLT);
        foreach ($xp->query('/xsl:transform/xsl:param | /xsl:transform/xsl:variable', $dom) as $v) {
            /** @var \DOMElement $v */
            $this->globals[] = [$v->getAttribute('name'), $v->hasAttribute('select') ? $v->getAttribute('select') : null, $v->textContent];
        }
        foreach ($xp->query('/xsl:transform/xsl:function', $dom) as $f) {
            /** @var \DOMElement $f */
            $name = $f->getAttribute('name');
            if ($name === 'schxslt:location') {
                continue;
            }
            $def = ['params' => [], 'vars' => [], 'body' => null];
            foreach ($f->childNodes as $ch) {
                if (!$ch instanceof \DOMElement || $ch->namespaceURI !== self::XSL) {
                    continue;
                }
                match ($ch->localName) {
                    'param' => $def['params'][] = [$ch->getAttribute('name'), $ch->hasAttribute('as') ? $ch->getAttribute('as') : null],
                    'variable' => $def['vars'][] = [$ch->getAttribute('name'), $ch->hasAttribute('select') ? $ch->getAttribute('select') : "'" . str_replace("'", "''", $ch->textContent) . "'"],
                    'sequence' => $def['body'] = $ch->getAttribute('select'),
                    default => $def['unsupported'] = $ch->localName,
                };
            }
            $this->functionDefs[$name] = $def;
        }
        $modes = [];
        foreach ($xp->query('/xsl:transform/xsl:template[@name][.//schxslt:pattern]', $dom) as $t) {
            foreach ($xp->query('.//schxslt:pattern', $t) as $p) {
                $this->patterns[] = $p->getAttribute('id');
            }
            foreach ($xp->query('.//xsl:apply-templates[@mode]', $t) as $a) {
                $modes[] = $a->getAttribute('mode');
            }
        }
        foreach ($modes as $mode) {
            $list = [];
            foreach ($xp->query("/xsl:transform/xsl:template[@mode='{$mode}'][@match]", $dom) as $t) {
                /** @var \DOMElement $t */
                $rule = $xp->query('xsl:choose/xsl:otherwise/schxslt:rule', $t)->item(0);
                if (!$rule instanceof \DOMElement) {
                    continue;
                }
                $vars = [];
                foreach ($xp->query('xsl:variable', $t) as $v) {
                    /** @var \DOMElement $v */
                    $vars[] = [$v->getAttribute('name'), $v->hasAttribute('select') ? $v->getAttribute('select') : "'" . str_replace("'", "''", $v->textContent) . "'"];
                }
                $asserts = [];
                foreach ($xp->query('xsl:if[svrl:failed-assert or svrl:successful-report]', $rule) as $if) {
                    /** @var \DOMElement $if */
                    $fa = $xp->query('svrl:failed-assert | svrl:successful-report', $if)->item(0);
                    $parts = [];
                    $textEl = $xp->query('svrl:text', $fa)->item(0);
                    if ($textEl) {
                        foreach ($textEl->childNodes as $tn) {
                            if ($tn instanceof \DOMText) {
                                $parts[] = $tn->data;
                            } elseif ($tn instanceof \DOMElement && $tn->namespaceURI === self::XSL && $tn->localName === 'value-of') {
                                $parts[] = ['select' => $tn->getAttribute('select')];
                            }
                        }
                    }
                    $asserts[] = ['id' => $fa->getAttribute('id'), 'flag' => $fa->getAttribute('flag') ?: ($fa->getAttribute('role') ?: 'fatal'),
                        'test' => $if->getAttribute('test'), 'text' => $parts];
                }
                $list[] = ['match' => $t->getAttribute('match'), 'priority' => (float) $t->getAttribute('priority'),
                    'pattern' => $rule->getAttribute('pattern'), 'asserts' => $asserts, 'vars' => $vars, 'ast' => null, 'ok' => null];
            }
            usort($list, static fn ($a, $b) => $b['priority'] <=> $a['priority']);
            array_push($this->rules, ...$list);
        }
        $this->compile();
    }

    /** Parse every expression once; rules (or whole contexts) the engine can't read are recorded as unsupported. */
    private function compile(): void
    {
        foreach ($this->rules as &$r) {
            try {
                $r['ast'] = self::anchor(XPathParser::parse($r['match']));
                foreach ($r['vars'] as &$v) {
                    $v[2] = XPathParser::parse($v[1]);
                }
                unset($v);
            } catch (XPathUnsupported $e) {
                foreach ($r['asserts'] as $a) {
                    $this->unsupported[$a['id']] = 'context: ' . $e->getMessage();
                }
                continue;
            }
            foreach ($r['asserts'] as &$a) {
                try {
                    $a['ast'] = XPathParser::parse($a['test']);
                    foreach ($a['text'] as &$p) {
                        if (is_array($p)) {
                            $p['ast'] = XPathParser::parse($p['select']);
                        }
                    }
                    unset($p);
                } catch (XPathUnsupported $e) {
                    $a['ast'] = null;
                    $this->unsupported[$a['id']] = $e->getMessage();
                }
            }
            unset($a);
        }
        unset($r);
    }

    /** A match pattern as an expression selecting every matching node from the root. */
    private static function anchor(array $p): array
    {
        if (self::absolute($p)) {
            return $p;
        }
        if ($p[0] === 'union') {
            return ['union', self::anchor($p[1]), self::anchor($p[2])];
        }
        if ($p[0] === 'root' || ($p[0] === 'path' && $p[1] !== null)) {
            return $p;
        }
        if ($p[0] === 'call' && $p[1] === 'root') {
            return ['root'];
        }
        $dos = ['axis', 'descendant-or-self', ['kind', 'node'], []];
        if ($p[0] === 'axis') {
            return ['path', ['root'], [$dos, $p]];
        }
        if ($p[0] === 'path' && $p[1] === null && $p[2][0][0] === 'axis') {
            return ['path', ['root'], array_merge([$dos], $p[2])];
        }
        throw new XPathUnsupported('match pattern');
    }

    private static function absolute(array $p): bool
    {
        return match ($p[0]) {
            'root' => true,
            'union' => self::absolute($p[1]) && self::absolute($p[2]),
            'paren' => self::absolute($p[1]),
            'filter' => self::absolute($p[1]),
            'path' => $p[1] !== null ? self::absolute($p[1]) : ($p[2][0][0] !== 'axis' && self::absolute($p[2][0])),
            'call' => $p[1] === 'root',
            default => false,
        };
    }

    /** @return list<string> every rule id in the file */
    public function ruleIds(): array
    {
        $ids = [];
        foreach ($this->rules as $r) {
            foreach ($r['asserts'] as $a) {
                $ids[$a['id']] = true;
            }
        }
        return array_keys($ids);
    }

    /**
     * Run all rules on a document.
     * @return array{0: list<array{id: string, flag: string, text: string, location: string}>, 1: list<array{id: string, error: string}>}
     */
    public function run(Tree $tree): array
    {
        $ev = new XPathEvaluator($tree);
        $ev->ns = $this->ns;
        foreach ($this->globals as [$name, $select, $text]) {
            $ev->lazyGlobals[$name] = $select === null
                ? static fn () => [$text]
                : static fn () => $ev->evaluate(XPathParser::parse($select), $tree->root);
        }
        foreach ($this->functionDefs as $name => $def) {
            if (isset($def['unsupported']) || $def['body'] === null) {
                continue;
            }
            try {
                $ev->functions[$name] = ['params' => $def['params'], 'vars' => array_map(static fn ($v) => [$v[0], XPathParser::parse($v[1])], $def['vars']),
                    'body' => XPathParser::parse($def['body'])];
            } catch (XPathUnsupported) {
            }
        }
        $fired = []; // pattern => node id => true
        $byPattern = array_fill_keys($this->patterns, []);
        $failures = [];
        $matchSets = [];
        foreach ($this->rules as $i => $r) {
            if ($r['ast'] === null) {
                continue;
            }
            try {
                $set = [];
                $ev->patternMode = true;
                foreach ($ev->evaluate($r['ast'], $tree->root) as $n) {
                    $set[$n->id] = true;
                }
                $matchSets[$i] = $set;
            } catch (XPathError $e) {
                throw $e;
            } catch (XPathUnsupported | \ArithmeticError | \TypeError | \ValueError $e) {
                foreach ($r['asserts'] as $a) {
                    $failures[] = ['id' => $a['id'], 'error' => 'context: ' . $e->getMessage()];
                }
            } finally {
                $ev->patternMode = false;
            }
        }
        $errors = [];
        foreach ($tree->all as $node) {
            foreach ($matchSets as $i => $set) {
                if (!isset($set[$node->id])) {
                    continue;
                }
                $r = $this->rules[$i];
                if (isset($fired[$r['pattern']][$node->id])) {
                    continue; // shadowed by a higher-priority rule of the same pattern
                }
                $fired[$r['pattern']][$node->id] = true;
                $ev->current = $node;
                $vars = [];
                try {
                    foreach ($r['vars'] as $v) {
                        $vars[$v[0]] = $ev->evaluate($v[2], $node, $vars);
                    }
                } catch (XPathError $e) {
                    throw $e;
                } catch (XPathUnsupported | \ArithmeticError | \TypeError | \ValueError $e) {
                    foreach ($r['asserts'] as $a) {
                        $errors[] = ['id' => $a['id'], 'error' => 'rule variable: ' . $e->getMessage(), 'location' => self::location($node)];
                    }
                    continue;
                }
                foreach ($r['asserts'] as $a) {
                    if ($a['ast'] === null) {
                        continue;
                    }
                    try {
                        if (!$ev->ebvOf($a['ast'], $node, $vars)) {
                            continue;
                        }
                        $text = '';
                        foreach ($a['text'] as $p) {
                            $text .= is_array($p) ? implode(' ', array_map([$ev, 'str'], $ev->atomize($ev->evaluate($p['ast'], $node, $vars)))) : $p;
                        }
                        $byPattern[$r['pattern']][] = ['id' => $a['id'], 'flag' => $a['flag'], 'text' => $text, 'location' => self::location($node)];
                    } catch (XPathError $e) {
                        throw new XPathError($e->xpathCode, substr($e->getMessage(), strlen($e->xpathCode) + 2) . " (rule {$a['id']} at " . self::location($node) . ')');
                    } catch (XPathUnsupported | \ArithmeticError | \TypeError | \ValueError $e) {
                        $errors[] = ['id' => $a['id'], 'error' => $e->getMessage(), 'location' => self::location($node)];
                    }
                }
            }
        }
        $out = [];
        foreach ($byPattern as $list) {
            array_push($out, ...$list);
        }
        return [$out, array_merge($failures, $errors)];
    }

    /** schxslt:location(): /Q{ns}local[n]/... with @Q{ns}local for attributes */
    public static function location(Node $n): string
    {
        $seg = [];
        for ($x = $n; $x !== null && $x->kind !== Node::DOC; $x = $x->parent) {
            if ($x->kind === Node::ATTR) {
                $seg[] = '@Q{' . $x->ns . '}' . $x->local;
                continue;
            }
            $pos = 1;
            foreach ($x->parent->children as $s) {
                if ($s === $x) {
                    break;
                }
                if ($s->kind === $x->kind && ($x->kind !== Node::ELEM || ($s->local === $x->local && $s->ns === $x->ns))) {
                    $pos++;
                }
            }
            $seg[] = match ($x->kind) {
                Node::ELEM => 'Q{' . $x->ns . '}' . $x->local . '[' . $pos . ']',
                Node::TEXT => 'text()[' . $pos . ']',
                Node::COMMENT => 'comment()[' . $pos . ']',
                default => 'processing-instruction("' . $x->local . '")[' . $pos . ']',
            };
        }
        return '/' . implode('/', array_reverse($seg));
    }
}
