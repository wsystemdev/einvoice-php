<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Validator;

use WSystem\EInvoice\InputError;
use WSystem\EInvoice\Model\Codes;
use WSystem\EInvoice\Validator\Backend\CoreRulesBackend;
use WSystem\EInvoice\Validator\Backend\SaxonCBackend;
use WSystem\EInvoice\Validator\Backend\SchematronBackend;
use WSystem\EInvoice\Validator\National\Norway;
use WSystem\EInvoice\Validator\National\Slovakia;

/**
 * Local, offline e-invoice validation: XML Schema + EN 16931 (+ XRechnung 3.0.2) business rules, for UBL 2.1, UN/CEFACT
 * CII D16B and ZUGFeRD / Factur-X / XRechnung PDF/A-3 hybrids. Same result shape and rule ids as W System's
 * POST /einvoice/validate (port of vatcheck einvoice.validate).
 *
 * Business rules run through a backend, and every result names it ("engine"):
 *  - SaxonCBackend, engine "saxonc" (default when the SaxonC-HE extension is loaded): the official KoSIT/CEN schematron,
 *    conformance "official";
 *  - CoreRulesBackend, engine "php-core" (default otherwise): a pure-PHP pre-check, conformance "pre-check",
 *    checked.* = "core-subset".
 * HttpValidator (a separate ValidatorInterface, engine "remote") sends the file to a W System validator or api.wsystem.dev.
 */
final class Validator implements ValidatorInterface
{
    public const DISCLAIMER = Codes::DISCLAIMER;
    private const CORE_DISCLAIMER = ' Business rules were pre-checked by the pure-PHP engine (php-core), not by the official schematron engine: '
        . 'a valid result here is a pre-check, not a conformance check. For the complete official rule sets, install the SaxonC-HE extension '
        . 'or use the W System validator or API.';

    private SchematronBackend $backend;
    private RulePack $rules;

    public function __construct(?SchematronBackend $backend = null, ?RulePack $rules = null, private int $maxBytes = Document::DEFAULT_MAX_BYTES)
    {
        $this->backend = $backend ?? self::defaultBackend();
        $this->rules = $rules ?? new RulePack();
    }

    public static function defaultBackend(): SchematronBackend
    {
        return SaxonCBackend::available() ? new SaxonCBackend() : new CoreRulesBackend();
    }

    public function backend(): SchematronBackend
    {
        return $this->backend;
    }

    public function validate(string $data, string $rules = 'auto'): array
    {
        $t0 = microtime(true);
        $doc = Document::load($data, $this->maxBytes);
        $rules = strtolower($rules === '' ? 'auto' : $rules);
        $sets = ['EN16931'];
        if ($rules === 'xrechnung' || ($rules === 'auto' && in_array($doc->profile, ['XRechnung', 'XRechnung Extension'], true))) {
            $sets[] = 'XRechnung';
        } elseif (!in_array($rules, ['auto', 'en16931'], true)) {
            throw new InputError('rules must be auto, en16931 or xrechnung (peppol is not available yet)');
        }
        if ($this->backend instanceof CoreRulesBackend) {
            $this->backend->takeNotEvaluated();
        }
        $issues = XsdCheck::errors($doc, $this->rules);
        foreach ($sets as $rs) {
            try {
                array_push($issues, ...$this->backend->run($doc, $rs, $this->rules));
            } catch (RuleEvaluationError $e) {
                $e->xsdErrors = $issues;
                throw $e;
            }
        }
        if ($doc->syntax === 'cii' && str_contains(strtolower($doc->customizationId), 'extended')) {
            $issues = array_map([Issues::class, 'extendedRelax'], $issues);
        }
        if ($doc->profile === 'XRechnung Extension' && in_array('XRechnung', $sets, true)) {
            $issues = array_map(static fn ($i) => Issues::xrechnungExtLevel($i, $doc->syntax), $issues);
        }
        [$skIssues, $sk] = Slovakia::check($doc);
        [$noIssues, $no] = Norway::check($doc);
        array_push($issues, ...$skIssues, ...$noIssues);
        $errors = array_values(array_filter($issues, static fn ($i) => $i['severity'] === 'error'));
        $warnings = array_values(array_filter($issues, static fn ($i) => $i['severity'] === 'warning'));
        $fmt = 0;
        foreach ($errors as &$e) {
            $e['error_class'] = $e['rule_id'] === 'XSD' ? 'format' : 'business_rule';
            $fmt += $e['rule_id'] === 'XSD' ? 1 : 0;
        }
        unset($e);
        $official = $this->backend->official();
        $mark = $official ? true : 'core-subset';
        $notes = $doc->notes;
        foreach ([$sk, $no] as $blk) {
            if (isset($blk['note'])) {
                $notes[] = $blk['note'];
            }
        }
        $ruleVersions = RulePack::ruleVersions() + ($official ? [] : ['core_rules' => CoreRulesBackend::VERSION]);
        if ($sk['applies']) {
            $ruleVersions['SK_national'] = Slovakia::TRANSPOSITION;
        }
        if ($no['applies']) {
            $ruleVersions['NO_national'] = Norway::VERSION;
        }
        $out = [
            'valid' => !$errors,
            'error_count' => count($errors),
            'warning_count' => count($warnings),
            ...$doc->meta,
            ...$doc->detection(),
            'notes' => $notes,
            'checked' => ['xsd' => true, 'EN16931' => $mark, 'XRechnung' => in_array('XRechnung', $sets, true) ? $mark : false,
                'PeppolBIS3' => false, 'SK_national' => $sk['applies'], 'NO_national' => $no['applies']],
            'errors' => $errors,
            'warnings' => $warnings,
            'engine' => $this->backend->name(),
            'backend' => $this->backend->name(),
            'conformance' => $official ? 'official' : 'pre-check',
            'rule_set' => $this->ruleSetLabel($sets, $official),
            'rule_versions' => $ruleVersions,
            'sources' => RulePack::SOURCES,
            'disclaimer' => self::DISCLAIMER . ($official ? '' : self::CORE_DISCLAIMER),
            'format_error_count' => $fmt,
            'business_rule_error_count' => count($errors) - $fmt,
            'ms' => (int) ((microtime(true) - $t0) * 1000),
        ];
        if ($this->backend instanceof CoreRulesBackend) {
            $out['rules_not_evaluated'] = $this->backend->takeNotEvaluated();
        }
        if ($sk['applies']) {
            $out['slovakia'] = $sk;
        }
        if ($no['applies']) {
            $out['norway'] = $no;
        }
        return $out;
    }

    /** @param list<string> $sets */
    private function ruleSetLabel(array $sets, bool $official): string
    {
        $names = implode(' + ', array_map(static fn ($s) => $s === 'XRechnung' ? 'XRechnung 3.0.2' : 'EN 16931', $sets));
        return $official
            ? "{$names}: official KoSIT/CEN schematron (KoSIT config " . RulePack::VERSION . ')'
            : "{$names}: pre-check by the W System pure-PHP engine over the bundled KoSIT/CEN rule files (" . CoreRulesBackend::VERSION
                . '; coverage in docs/validator-rules.md), not the official engine';
    }
}
