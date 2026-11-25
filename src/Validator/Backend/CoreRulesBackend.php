<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Validator\Backend;

use WSystem\EInvoice\Validator\Core\SchematronProgram;
use WSystem\EInvoice\Validator\Core\Tree;
use WSystem\EInvoice\Validator\Document;
use WSystem\EInvoice\Validator\Issues;
use WSystem\EInvoice\Validator\RulePack;
use WSystem\EInvoice\Validator\RuleEvaluationError;
use WSystem\EInvoice\Validator\Core\XPathError;

/**
 * Default engine ("php-core"): no extension needed. Reads the bundled official CEN / KoSIT schematron files and runs
 * their rules with a pure-PHP XPath 2.0 subset engine. It is tested rule by rule against SaxonC on the parity corpus
 * (docs/validator-rules.md lists coverage), but it is NOT the official engine: results are labelled
 * conformance "pre-check" and checked.* = "core-subset". Rules it cannot evaluate are listed in rules_not_evaluated.
 */
final class CoreRulesBackend implements SchematronBackend
{
    public const VERSION = 'W System php-core 1.0 (pure-PHP schematron subset engine)';

    /** @var list<array{rule_id: string, ruleset: string, reason: string}> */
    private array $notEvaluated = [];
    /** @var \WeakMap<\DOMDocument, Tree>|null */
    private ?\WeakMap $trees = null;

    public function name(): string
    {
        return 'php-core';
    }

    public function official(): bool
    {
        return false;
    }

    public function run(Document $doc, string $ruleset, RulePack $rules): array
    {
        $prog = SchematronProgram::load($rules->xsl($doc->syntax, $ruleset));
        $this->trees ??= new \WeakMap();
        $tree = $this->trees[$doc->dom] ??= new Tree($doc->dom);
        try {
            [$fails, $errors] = $prog->run($tree);
        } catch (XPathError $e) {
            throw new RuleEvaluationError($e->getMessage(), 'php-core', $ruleset, [], $e);
        }
        foreach ($prog->unsupported as $id => $why) {
            $this->notEvaluated[] = ['rule_id' => $id, 'ruleset' => $ruleset, 'reason' => 'not supported by php-core: ' . $why];
        }
        foreach ($errors as $e) {
            $this->notEvaluated[] = ['rule_id' => $e['id'], 'ruleset' => $ruleset, 'reason' => 'evaluation error: ' . $e['error']];
        }
        return array_map(static fn ($f) => Issues::entry($f['id'], $f['flag'], $ruleset, $f['text'], $f['location']), $fails);
    }

    /** Rules of the last validate() call that php-core could not evaluate (and the reason); cleared by takeNotEvaluated(). */
    public function takeNotEvaluated(): array
    {
        $n = $this->notEvaluated;
        $this->notEvaluated = [];
        return $n;
    }
}
