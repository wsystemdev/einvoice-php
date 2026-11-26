<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Tests\Validator;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WSystem\EInvoice\Model\Codes;
use WSystem\EInvoice\Validator\Backend\CoreRulesBackend;
use WSystem\EInvoice\Validator\Backend\SaxonCBackend;
use WSystem\EInvoice\Validator\Issues;
use WSystem\EInvoice\Validator\Validator;

/**
 * KoSIT scenario severity levels (scenarios.xml customLevel) for standard XRechnung and XRechnung CVD, as in the W System
 * engine 0.3.157.1: the official CVD samples (BR-CL-13) and unlisted unit codes (BR-CL-23) are warnings; the overrides are
 * specific to their scenario; "error" levels escalate Schematron warnings.
 */
final class KositLevelsTest extends TestCase
{
    private const SHARED = __DIR__ . '/../fixtures/validator/shared/valid/';

    public static function engines(): iterable
    {
        yield 'php-core' => ['php-core'];
        yield 'saxonc' => ['saxonc'];
    }

    private function validator(TestCase $t, string $engine): Validator
    {
        if ($engine === 'saxonc') {
            Golden::saxonOrSkip($t);
        }
        return new Validator($engine === 'saxonc' ? new SaxonCBackend() : new CoreRulesBackend());
    }

    /** @return list<string> */
    private static function ids(array $entries): array
    {
        return array_values(array_unique(array_column($entries, 'rule_id')));
    }

    #[DataProvider('engines')]
    public function testOfficialCvdSamplesAreValidWithBrCl13Warning(string $engine): void
    {
        $v = $this->validator($this, $engine);
        foreach (['KoSIT_02.01a-cvd_INVOICE_ubl.xml', 'KoSIT_02.01a-cvd_INVOICE_uncefact.xml'] as $f) {
            $r = $v->validate((string) file_get_contents(self::SHARED . $f));
            $this->assertTrue($r['valid'], "$f: " . implode(', ', self::ids($r['errors'])));
            $this->assertSame(Codes::XRECHNUNG_CVD_ID, $r['customization_id']);
            $this->assertContains('BR-CL-13', self::ids($r['warnings']), $f);
            // the override belongs to the CVD scenario only
            $std = $v->validate(str_replace(Codes::XRECHNUNG_CVD_ID, Codes::XRECHNUNG_ID, (string) file_get_contents(self::SHARED . $f)));
            $this->assertFalse($std['valid']);
            $this->assertContains('BR-CL-13', self::ids($std['errors']), $f);
        }
    }

    #[DataProvider('engines')]
    public function testBrCl23IsAWarningInStandardXrechnungOnly(string $engine): void
    {
        $v = $this->validator($this, $engine);
        foreach (['ubl-xrechnung-BR-CL-23-unit-code-warning.xml', 'cii-xrechnung-BR-CL-23-unit-code-warning.xml'] as $f) {
            $xml = (string) file_get_contents(self::SHARED . $f);
            $r = $v->validate($xml);
            $this->assertTrue($r['valid'], "$f: " . implode(', ', self::ids($r['errors'])));
            $this->assertContains('BR-CL-23', self::ids($r['warnings']), $f);
            $g = $v->validate(str_replace(Codes::XRECHNUNG_ID, Codes::EN16931_ID, $xml));
            $this->assertFalse($g['valid']);
            $this->assertContains('BR-CL-23', self::ids($g['errors']), $f);
        }
    }

    public function testScenarioSelectionIsExact(): void
    {
        $this->assertSame('XRechnung', Issues::kositLevels('XRechnung', 'ubl', 'ubl_inv', Codes::XRECHNUNG_ID)[0]);
        $this->assertSame('XRechnung CVD', Issues::kositLevels('XRechnung', 'cii', 'cii', Codes::XRECHNUNG_CVD_ID)[0]);
        $this->assertSame('Extension', Issues::kositLevels('XRechnung Extension', 'cii', 'cii', Codes::XRECHNUNG_EXT_ID)[0]);
        $this->assertNull(Issues::kositLevels('XRechnung', 'ubl', 'ubl_inv', Codes::XRECHNUNG_ID . '#other'));
        $this->assertNull(Issues::kositLevels('EN 16931', 'ubl', 'ubl_inv', Codes::EN16931_ID));
    }

    public function testErrorLevelEscalatesAndNotesNameTheScenario(): void
    {
        $i = Issues::kositLevel(['rule_id' => 'CII-SR-465', 'severity' => 'warning', 'ruleset' => 'EN16931'], Issues::XRECHNUNG_STD_LEVELS['cii'], 'XRechnung');
        $this->assertSame('error', $i['severity']);
        $this->assertStringContainsString('customLevel: error', $i['profile_note']);
        $w = Issues::kositLevel(['rule_id' => 'BR-CL-23', 'severity' => 'error', 'ruleset' => 'EN16931'], Issues::XRECHNUNG_STD_LEVELS['ubl_inv'], 'XRechnung');
        $this->assertSame('warning', $w['severity']);
        $this->assertStringContainsString('KoSIT XRechnung scenario', $w['profile_note']);
        $same = ['rule_id' => 'BR-CO-10', 'severity' => 'error', 'ruleset' => 'EN16931'];
        $this->assertSame($same, Issues::kositLevel($same, Issues::XRECHNUNG_STD_LEVELS['cii'], 'XRechnung'));
    }

    public function testTablesMatchTheBundledKositScenarios(): void
    {
        $t = (string) file_get_contents(__DIR__ . '/../../resources/kosit/xrechnung-3.0.2-2026-08-31/scenarios.xml');
        preg_match_all('#<scenario>(.*?)</scenario>#s', $t, $m);
        $std = $cvd = [];
        foreach ($m[1] as $sc) {
            preg_match('#<name>(.*?)</name>#', $sc, $n);
            if (!str_contains($n[1], 'XRechnung') || str_contains($n[1], 'Extension')) {
                continue;
            }
            $dt = str_contains($n[1], '(CII)') ? 'cii' : (str_contains($n[1], 'CreditNote') ? 'ubl_cn' : 'ubl_inv');
            preg_match_all('#<customLevel level="(\w+)">([\w-]+)</customLevel>#', $sc, $l, PREG_SET_ORDER);
            $lv = [];
            foreach ($l as $x) {
                $lv[$x[2]] = $x[1];
            }
            if (str_contains($n[1], 'CVD')) {
                $cvd[$dt] = $lv;
            } else {
                $std[$dt] = $lv;
            }
        }
        ksort($std); ksort($cvd);
        $s = Issues::XRECHNUNG_STD_LEVELS; $c = Issues::XRECHNUNG_CVD_LEVELS;
        ksort($s); ksort($c);
        foreach ([[$std, $s], [$cvd, $c]] as [$a, $b]) {
            $this->assertSame(array_keys($a), array_keys($b));
            foreach ($a as $k => $v) {
                ksort($v); $w = $b[$k]; ksort($w);
                $this->assertSame($v, $w, $k);
            }
        }
    }
}
