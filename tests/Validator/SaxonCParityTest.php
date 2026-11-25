<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Tests\Validator;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WSystem\EInvoice\InputError;
use WSystem\EInvoice\Validator\Backend\SaxonCBackend;
use WSystem\EInvoice\Validator\Validator;

/** With SaxonC the PHP validator must give exactly the W System engine's result (official KoSIT/CEN rules). */
final class SaxonCParityTest extends TestCase
{
    public static function corpus(): iterable
    {
        foreach (Golden::cases() as $k => $c) {
            yield $k => [$c];
        }
    }

    #[DataProvider('corpus')]
    public function testSameResultAsPythonEngine(array $c): void
    {
        Golden::saxonOrSkip($this);
        $v = new Validator(new SaxonCBackend());
        try {
            $r = $v->validate(Golden::bytes($c), $c['rules']);
        } catch (InputError $e) {
            $this->assertNotNull($c['input_error'], 'unexpected input error: ' . $e->getMessage());
            return;
        }
        $this->assertNull($c['input_error'], 'reference raised: ' . (string) $c['input_error']);
        $this->assertSame($c['valid'], $r['valid']);
        $this->assertSame(Golden::sig($c['errors']), Golden::sig($r['errors']));
        $this->assertSame(Golden::sig($c['warnings']), Golden::sig($r['warnings']));
        foreach (['syntax', 'document_type', 'customization_id', 'profile', 'container', 'notes'] as $k) {
            $this->assertSame($c[$k], $r[$k], $k);
        }
        $this->assertSame($c['embedded_file'] ?? null, $r['embedded_file'] ?? null);
        $got = array_intersect_key($r['checked'], $c['checked']);
        $want = $c['checked'];
        ksort($got);
        ksort($want);
        $this->assertSame($want, $got);
        foreach ($c['errors'] as $i => $e) {
            $this->assertSame($e['business_terms'], $r['errors'][$i]['business_terms'], $e['rule_id']);
            if ($e['rule_id'] === 'XSD') {
                $this->assertSame($e['message'], $r['errors'][$i]['message']);
            }
        }
        $this->assertSame('saxonc', $r['engine']);
        $this->assertSame('official', $r['conformance']);
    }

    /** The generator's 369 invoices (tests/fixtures/expected.json): same verdict and rule ids as the official check. */
    public function testGeneratorFixturesSameVerdict(): void
    {
        Golden::saxonOrSkip($this);
        $exp = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/expected.json'), true, 512, JSON_THROW_ON_ERROR);
        $in = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/inputs.json'), true, 512, JSON_THROW_ON_ERROR);
        $v = new Validator(new SaxonCBackend());
        $ids = static function (array $l): array {
            $a = array_values(array_unique(array_column($l, 'rule_id')));
            sort($a);
            return $a;
        };
        $n = 0;
        foreach ($in as $f) {
            $e = $exp[$f['name']];
            if (!isset($e['xml'])) {
                continue;
            }
            $fmt = strtolower((string) ($f['input']['format'] ?? 'en16931'));
            $r = $v->validate($e['xml'], in_array($fmt, ['xrechnung', 'xrechnung-cii', 'zugferd-xrechnung'], true) ? 'xrechnung' : 'en16931');
            $this->assertSame([$e['valid'], $e['error_ids'], $e['warning_ids']], [$r['valid'], $ids($r['errors']), $ids($r['warnings'])], $f['name']);
            $n++;
        }
        $this->assertGreaterThan(300, $n);
    }
}
