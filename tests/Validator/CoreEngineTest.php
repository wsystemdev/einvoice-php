<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Tests\Validator;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WSystem\EInvoice\InputError;
use WSystem\EInvoice\Validator\Backend\CoreRulesBackend;
use WSystem\EInvoice\Validator\Core\Tree;
use WSystem\EInvoice\Validator\Core\XPathError;
use WSystem\EInvoice\Validator\Core\XPathEvaluator;
use WSystem\EInvoice\Validator\Core\XPathParser;
use WSystem\EInvoice\Validator\Core\XPathUnsupported;
use WSystem\EInvoice\Validator\RuleEvaluationError;
use WSystem\EInvoice\Validator\Validator;

/** The pure-PHP XPath engine against answers recorded from SaxonC (tools/validator/xpath_expectations.php). */
final class CoreEngineTest extends TestCase
{
    private const DIR = __DIR__ . '/../fixtures/validator';

    public static function expressions(): iterable
    {
        foreach (json_decode((string) file_get_contents(self::DIR . '/xpath_expected.json'), true, 512, JSON_THROW_ON_ERROR) as $e => $want) {
            yield $e => [$e, $want];
        }
    }

    #[DataProvider('expressions')]
    public function testSameAnswerAsSaxon(string $expr, ?string $want): void
    {
        static $ev = null;
        if ($ev === null) {
            $dom = new \DOMDocument();
            $dom->load(self::DIR . '/xpath_doc.xml');
            $ev = new XPathEvaluator(new Tree($dom));
            $ev->ns = ['a' => 'urn:a', 'xs' => 'http://www.w3.org/2001/XMLSchema'];
        }
        try {
            $got = $ev->evaluate(XPathParser::parse("string-join(for \$i in ($expr) return string(\$i), '|')"), $ev->tree->root);
            $got = $ev->str($got[0]);
        } catch (XPathError) {
            $got = 'ERROR';
        }
        $this->assertSame($want, $got, $expr);
    }

    public function testUnsupportedSyntaxIsRefusedNotGuessed(): void
    {
        $this->expectException(XPathUnsupported::class);
        XPathParser::parse('function($x) { $x }');
    }

    /** A dynamic error in an official rule aborts the run (as Saxon does), with the XML Schema finding named. */
    public function testDynamicErrorAbortsWithXsdHint(): void
    {
        $xml = (string) file_get_contents(self::DIR . '/files/kosit/standard/01.21a-INVOICE_uncefact.xml');
        $bad = (string) preg_replace('#<ram:RateApplicablePercent>[^<]*#', '<ram:RateApplicablePercent>XX', $xml, 1);
        try {
            (new Validator(new CoreRulesBackend()))->validate($bad);
            $this->fail('expected RuleEvaluationError');
        } catch (RuleEvaluationError $e) {
            $this->assertInstanceOf(InputError::class, $e);
            $this->assertSame('php-core', $e->engine);
            $this->assertSame('EN16931', $e->ruleset);
            $this->assertNotEmpty($e->xsdErrors);
            $this->assertStringContainsString("'XX' is not a valid value", $e->getMessage());
        }
    }

    public function testResultNamesTheEngine(): void
    {
        $r = (new Validator(new CoreRulesBackend()))->validate((string) file_get_contents(self::DIR . '/shared/valid/KoSIT_01.01a-INVOICE_ubl.xml'));
        $this->assertTrue($r['valid']);
        $this->assertSame('php-core', $r['engine']);
        $this->assertSame('pre-check', $r['conformance']);
        $this->assertSame(['xsd' => true, 'EN16931' => 'core-subset', 'XRechnung' => 'core-subset'], array_intersect_key($r['checked'], ['xsd' => 1, 'EN16931' => 1, 'XRechnung' => 1]));
        $this->assertStringContainsString('not the official engine', $r['rule_set']);
        $this->assertStringContainsString('pre-check, not a conformance check', $r['disclaimer']);
        $this->assertSame([], $r['rules_not_evaluated']);
    }
}
