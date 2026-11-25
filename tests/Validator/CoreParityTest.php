<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Tests\Validator;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WSystem\EInvoice\InputError;
use WSystem\EInvoice\Validator\Backend\CoreRulesBackend;
use WSystem\EInvoice\Validator\Validator;

/**
 * Default engine (php-core, no extension): on the parity corpus it must give exactly the W System engine's result
 * (rule ids, severities, messages, locations), while labelling itself a pre-check. Runs everywhere, no SaxonC needed.
 */
final class CoreParityTest extends TestCase
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
        $v = new Validator(new CoreRulesBackend());
        try {
            $r = $v->validate(Golden::bytes($c), $c['rules']);
        } catch (InputError $e) {
            $this->assertNotNull($c['input_error'], 'unexpected input error: ' . $e->getMessage());
            return;
        }
        Golden::assertSameAsGolden($this, $c, $r);
        $this->assertSame([], $r['rules_not_evaluated'], 'php-core skipped rules on this case');
        $this->assertSame('php-core', $r['engine']);
        $this->assertSame('pre-check', $r['conformance']);
        $this->assertSame('core-subset', $r['checked']['EN16931']);
    }
}
