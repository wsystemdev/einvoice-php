<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Validator\National;

use WSystem\EInvoice\Validator\Document;

/** @internal ElementTree-style helpers (findtext / find / findall on relative paths) for the national checks. */
final class Xml
{
    public function __construct(public readonly \DOMXPath $xp)
    {
    }

    public function find(?\DOMNode $n, string $path): ?\DOMElement
    {
        if ($n === null) {
            return null;
        }
        $r = $this->xp->query($path, $n)->item(0);
        return $r instanceof \DOMElement ? $r : null;
    }

    /** @return list<\DOMElement> */
    public function findAll(?\DOMNode $n, string $path): array
    {
        if ($n === null) {
            return [];
        }
        $out = [];
        foreach ($this->xp->query($path, $n) as $e) {
            if ($e instanceof \DOMElement) {
                $out[] = $e;
            }
        }
        return $out;
    }

    /** Python _t(): stripped text of the first match, '' when absent */
    public function t(?\DOMNode $n, string $path): string
    {
        $e = $this->find($n, $path);
        return $e === null ? '' : Document::pyStrip($e->textContent);
    }

    /** Python _num(): "19.00" -> "19", "19.5" -> "19.5", non-numbers unchanged */
    public static function num(string $s): string
    {
        $v = trim($s);
        if (!is_numeric($v)) {
            return $s;
        }
        $f = (float) $v;
        if (is_finite($f) && $f == floor($f) && abs($f) < 1e18) {
            return (string) (int) $f;
        }
        $r = var_export($f, true);
        return $r;
    }

    /** @param array<string, mixed>|null $location */
    public static function w(string $ruleset, string $rid, string $msg, array $bts, string $fix, ?string $location = null): array
    {
        return ['rule_id' => $rid, 'severity' => 'warning', 'ruleset' => $ruleset, 'message' => $msg,
            'location' => $location, 'business_terms' => $bts, 'fix' => $fix];
    }

    public static function up(string $s): string
    {
        return mb_strtoupper($s, 'UTF-8');
    }
}
