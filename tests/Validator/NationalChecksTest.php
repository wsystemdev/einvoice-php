<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Tests\Validator;

use PHPUnit\Framework\TestCase;
use WSystem\EInvoice\InputError;
use WSystem\EInvoice\Validator\Backend\CoreRulesBackend;
use WSystem\EInvoice\Validator\National\Norway;
use WSystem\EInvoice\Validator\National\Slovakia;
use WSystem\EInvoice\Validator\Validator;

/** Slovak (SK-FS) and Norwegian (NO-LAW) field checks are pure PHP: identical to the Python engine on every corpus file. */
final class NationalChecksTest extends TestCase
{
    public function testSameAsReferenceOnCorpus(): void
    {
        $v = new Validator(new CoreRulesBackend());
        $hits = 0;
        foreach (Golden::cases() as $k => $c) {
            if ($c['input_error']) {
                continue;
            }
            $r = $v->validate(Golden::bytes($c), $c['rules']);
            $nat = static fn (array $l) => array_values(array_filter($l, static fn ($i) => in_array($i['ruleset'], ['SK-FS', 'NO-LAW'], true)));
            $this->assertSame(Golden::sig($nat($c['warnings'])), Golden::sig($nat($r['warnings'])), $k);
            $this->assertSame(Golden::sig($nat($c['errors'])), Golden::sig($nat($r['errors'])), $k);
            $this->assertSame([$c['checked']['xsd']], [$r['checked']['xsd']]);
            $hits += count($nat($r['warnings']));
        }
        $this->assertGreaterThan(30, $hits);
    }

    public function testHelpers(): void
    {
        $this->assertTrue(Norway::orgnrOk('923609016'));
        $this->assertFalse(Norway::orgnrOk('923609017'));
        $this->assertFalse(Norway::orgnrOk('92360901'));
        // uuid.uuid5(UUID('3fd00464-9f15-52ce-8cdc-6dd374d5ad42'), '0245 2020123456 380 F1 2026-01-01')
        $this->assertSame('7de64304-bfc9-5df5-89b7-338cb4a76bff', Slovakia::invoiceUuid('0245', '2020123456', '380', 'F1', '2026-01-01'));
    }
}
