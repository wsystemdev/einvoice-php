<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Validator\Core;

/** @internal Immutable, indexed copy of a DOM document for fast XPath evaluation (document order = Node::$id). */
final class Tree
{
    public Node $root;
    /** @var list<Node> all nodes in document order (attributes right after their element) */
    public array $all = [];

    public function __construct(\DOMDocument $dom)
    {
        $this->root = new Node($this, 0, Node::DOC);
        $this->all[] = $this->root;
        foreach ($dom->childNodes as $c) {
            $this->add($this->root, $c);
        }
    }

    private function add(Node $parent, \DOMNode $d): void
    {
        $n = match (true) {
            $d instanceof \DOMElement => new Node($this, count($this->all), Node::ELEM, (string) $d->namespaceURI, (string) $d->localName, $d->nodeName),
            $d instanceof \DOMText => new Node($this, count($this->all), Node::TEXT, value: $d->data), // includes CDATA
            $d instanceof \DOMComment => new Node($this, count($this->all), Node::COMMENT, value: $d->data),
            $d instanceof \DOMProcessingInstruction => new Node($this, count($this->all), Node::PI, local: $d->target, qname: $d->target, value: $d->data),
            default => null,
        };
        if ($n === null) {
            return;
        }
        if ($n->kind === Node::TEXT && $parent->children && end($parent->children)->kind === Node::TEXT) {
            return; // adjacent text (DOM keeps CDATA separate); XPath sees one text node - merged below
        }
        $n->parent = $parent;
        $n->sibIndex = count($parent->children);
        $parent->children[] = $n;
        $this->all[] = $n;
        if ($d instanceof \DOMElement) {
            foreach ($d->attributes as $a) {
                $an = new Node($this, count($this->all), Node::ATTR, (string) $a->namespaceURI, (string) $a->localName, $a->nodeName, $a->value);
                $an->parent = $n;
                $n->attrs[] = $an;
                $this->all[] = $an;
            }
            $buf = null;
            foreach ($d->childNodes as $c) {
                if ($c instanceof \DOMText) {
                    $buf = ($buf ?? '') . $c->data;
                    continue;
                }
                if ($buf !== null) {
                    $this->add($n, new \DOMText($buf));
                    $buf = null;
                }
                $this->add($n, $c);
            }
            if ($buf !== null) {
                $this->add($n, new \DOMText($buf));
            }
        }
    }
}
