<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Validator\Core;

/** @internal A node of Tree. Identity = object identity; $id = document order. */
final class Node
{
    public const DOC = 0;
    public const ELEM = 1;
    public const ATTR = 2;
    public const TEXT = 3;
    public const COMMENT = 4;
    public const PI = 5;

    public ?Node $parent = null;
    /** @var list<Node> */
    public array $children = [];
    /** @var list<Node> */
    public array $attrs = [];
    public int $sibIndex = 0;
    private ?string $sv = null;

    public function __construct(public readonly Tree $tree, public readonly int $id, public readonly int $kind,
        public readonly string $ns = '', public readonly string $local = '', public readonly string $qname = '', public readonly string $value = '')
    {
    }

    public function stringValue(): string
    {
        if ($this->sv !== null) {
            return $this->sv;
        }
        if ($this->kind === self::ELEM || $this->kind === self::DOC) {
            $s = '';
            foreach ($this->children as $c) {
                if ($c->kind === self::TEXT) {
                    $s .= $c->value;
                } elseif ($c->kind === self::ELEM) {
                    $s .= $c->stringValue();
                }
            }
            return $this->sv = $s;
        }
        return $this->sv = $this->value;
    }
}
