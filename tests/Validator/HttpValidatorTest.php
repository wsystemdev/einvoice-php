<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Tests\Validator;

use PHPUnit\Framework\TestCase;
use WSystem\EInvoice\InputError;
use WSystem\EInvoice\Validator\HttpValidator;

final class HttpValidatorTest extends TestCase
{
    public function testSendsTheFileAndLabelsTheResultRemote(): void
    {
        $seen = [];
        $v = new HttpValidator('https://api.example.test/', 'k-123', function (string $url, array $h, string $body, int $t) use (&$seen) {
            $seen = [$url, $h, $body];
            return [200, json_encode(['valid' => true, 'error_count' => 0, 'checked' => ['xsd' => true, 'EN16931' => true], 'errors' => []])];
        });
        $r = $v->validate('%PDF-1.7 ...', 'xrechnung');
        $this->assertSame('https://api.example.test/einvoice/validate?rules=xrechnung', $seen[0]);
        $this->assertSame('application/pdf', $seen[1]['Content-Type']);
        $this->assertSame('k-123', $seen[1]['X-API-Key']);
        $this->assertSame('%PDF-1.7 ...', $seen[2]);
        $this->assertSame('remote', $r['engine']);
        $this->assertSame('official', $r['conformance']);
        $this->assertTrue($r['valid']);
    }

    public function testKeylessXmlAndApiInputError(): void
    {
        $v = new HttpValidator(transport: function (string $url, array $h) {
            $this->assertArrayNotHasKey('X-API-Key', $h);
            $this->assertSame('application/xml', $h['Content-Type']);
            return [400, json_encode(['error' => 'bad_input', 'message' => 'not well-formed XML'])];
        });
        $this->expectException(InputError::class);
        $this->expectExceptionMessage('not well-formed XML');
        $v->validate('<x');
    }

    public function testServerErrorIsNotAVerdict(): void
    {
        $v = new HttpValidator(transport: fn () => [503, '{"error":"rules_unavailable","message":"Retry later."}']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 503');
        $v->validate('<Invoice/>');
    }

    public function testRefusesUnknownRules(): void
    {
        $this->expectException(InputError::class);
        (new HttpValidator(transport: fn () => [200, '{}']))->validate('<x/>', 'peppol');
    }
}
