<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Tests;

use PHPUnit\Framework\TestCase;
use WSystem\EInvoice\Builder;
use WSystem\EInvoice\EInvoice;
use WSystem\EInvoice\Validator\ValidatorInterface;

final class EInvoiceTest extends TestCase
{
    private static function sample(array $over = []): array
    {
        $in = json_decode(file_get_contents(__DIR__ . '/../examples/invoice.json'), true);
        return array_replace($in, $over);
    }

    public function testFacadeAndBuilderMatch(): void
    {
        $inv = self::sample(['format' => 'en16931']);
        $a = (new EInvoice())->create($inv);
        $b = Builder::create($inv);
        $this->assertSame($a['xml'], $b['xml']);
        $this->assertSame('955.00', $a['totals']['line_total']);
        $this->assertSame('UBL 2.1', $a['syntax']);
        if (!class_exists(\WSystem\EInvoice\Validator\Validator::class)) {
            $this->assertNull($a['valid']);
            $this->assertSame('none', $a['validator']);
        }
    }

    public function testInjectedValidatorIsUsedAndBlocksABadPdf(): void
    {
        $stub = new class implements ValidatorInterface {
            public function validate(string $data, string $rules = 'auto'): array
            {
                return [
                    'valid' => false,
                    'errors' => [['rule_id' => 'BR-01', 'severity' => 'error', 'message' => 'stub error', 'ruleset' => 'EN16931', 'location' => '', 'business_terms' => []]],
                    'warnings' => [],
                    'checked' => ['xsd' => true, 'EN16931' => 'core-subset', 'XRechnung' => false, 'PeppolBIS3' => false],
                    'backend' => 'stub',
                ];
            }
        };
        $r = (new EInvoice($stub))->create(self::sample(['format' => 'zugferd']));
        $this->assertFalse($r['valid']);
        $this->assertSame('stub', $r['validator']);
        $this->assertNull($r['pdf']);
        $this->assertStringContainsString('No PDF was built because the embedded XML has errors', implode(' ', $r['notes']));
        $this->assertStringContainsString('CrossIndustryInvoice', $r['xml']);
        $this->assertSame('BR-01', $r['errors'][0]['rule_id']);
    }

    public function testValidateWithoutAModuleThrowsAClearError(): void
    {
        if (class_exists(\WSystem\EInvoice\Validator\Validator::class)) {
            $this->markTestSkipped('validator module is present');
        }
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('no validator configured');
        (new EInvoice())->validate('<Invoice/>');
    }
}
