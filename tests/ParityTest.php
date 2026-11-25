<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WSystem\EInvoice\Generator\Generator;
use WSystem\EInvoice\InputError;

/**
 * Byte-for-byte parity with the W System reference engine (Python, api.wsystem.dev):
 * tests/fixtures/expected.json was produced by tools/parity/py_ref.py from the same inputs.
 */
final class ParityTest extends TestCase
{
    public static function fixtures(): iterable
    {
        $in = json_decode(file_get_contents(__DIR__ . '/fixtures/inputs.json'), true, 512, JSON_THROW_ON_ERROR);
        $exp = json_decode(file_get_contents(__DIR__ . '/fixtures/expected.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($in as $f) {
            yield $f['name'] => [$f['input'], $exp[$f['name']]];
        }
    }

    #[DataProvider('fixtures')]
    public function testSameOutputAsReferenceEngine(array $input, array $expected): void
    {
        try {
            $r = Generator::xml($input);
        } catch (InputError $e) {
            $this->assertArrayHasKey('error', $expected, 'PHP raised: ' . $e->getMessage());
            // KSeF FA(3) is an API-only format (Poland's national system), so the SDK's message does not offer it.
            $this->assertSame(str_replace(', or ksef-fa3', '', $expected['error']), $e->getMessage());
            return;
        }
        $this->assertArrayNotHasKey('error', $expected, 'reference raised: ' . ($expected['error'] ?? ''));
        $this->assertSame($expected['xml'], $r['xml']);
        $this->assertSame($expected['totals'], $r['totals']);
    }
}
