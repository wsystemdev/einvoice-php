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
        Golden::assertSameAsGolden($this, $c, $r);
        $got = array_intersect_key($r['checked'], $c['checked']);
        $want = $c['checked'];
        ksort($got);
        ksort($want);
        $this->assertSame($want, $got);
        $this->assertSame('saxonc', $r['engine']);
        $this->assertSame('official', $r['conformance']);
    }
}
