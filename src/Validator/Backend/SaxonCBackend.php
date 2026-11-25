<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Validator\Backend;

use WSystem\EInvoice\Validator\Document;
use WSystem\EInvoice\Validator\Issues;
use WSystem\EInvoice\Validator\RulePack;
use WSystem\EInvoice\Validator\RuleEvaluationError;

/**
 * Official rules in-process: the KoSIT/CEN schematron XSLT 2.0 files run by the SaxonC-HE PHP extension (MPL-2.0,
 * https://www.saxonica.com/saxon-c/). Same engine family and same files as api.wsystem.dev and the KoSIT validator.
 * Compiled stylesheets are cached per process (first use ~0.4 s per rule set, then a few ms per invoice).
 */
final class SaxonCBackend implements SchematronBackend
{
    /** @var array<string, object> */
    private static array $compiled = [];
    private static ?object $processor = null;

    public static function available(): bool
    {
        return class_exists('Saxon\\SaxonProcessor', false);
    }

    public function __construct()
    {
        if (!self::available()) {
            throw new \RuntimeException('the SaxonC-HE PHP extension (saxon) is not loaded; see docs/validator.md');
        }
    }

    public function name(): string
    {
        return 'saxonc';
    }

    public function official(): bool
    {
        return true;
    }

    public function run(Document $doc, string $ruleset, RulePack $rules): array
    {
        $proc = self::processor();
        $file = $rules->xsl($doc->syntax, $ruleset);
        if (!isset(self::$compiled[$file])) {
            $x = $proc->newXslt30Processor();
            $exe = $x->compileFromFile($file);
            if ($exe === null) {
                throw new \RuntimeException("SaxonC could not compile {$file}");
            }
            self::$compiled[$file] = $exe;
        }
        $node = $proc->parseXmlFromString($doc->dom->saveXML($doc->dom->documentElement));
        $exe = self::$compiled[$file];
        // Not transformToString(): in SaxonC 13.1 an XsltExecutable that threw from transformToString() crashes the
        // PHP process when it is freed. setInitialMatchSelection() + applyTemplatesReturningString() is equivalent
        // (same SVRL; covered by the parity tests) and stays usable after a dynamic error.
        $exe->setGlobalContextItem($node);
        $exe->setInitialMatchSelection($node);
        try {
            $out = $exe->applyTemplatesReturningString();
        } catch (\Throwable $e) {
            throw new RuleEvaluationError(trim($e->getMessage()), 'saxonc', $ruleset, [], $e);
        }
        if (!is_string($out) || $out === '') {
            throw new \RuntimeException('SaxonC returned no SVRL report for ' . basename($file));
        }
        return Issues::fromSvrl($out, $ruleset);
    }

    private static function processor(): object
    {
        if (self::$processor === null) {
            $cls = 'Saxon\\SaxonProcessor';
            self::$processor = new $cls(false);
        }
        return self::$processor;
    }
}
