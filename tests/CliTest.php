<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Tests;

use PHPUnit\Framework\TestCase;
use WSystem\EInvoice\Cli;
use WSystem\EInvoice\Version;

final class CliTest extends TestCase
{
    public function testVersionAndHelp(): void
    {
        [$code, $out, $err] = self::runCli(['wsystem-einvoice', '--version']);
        $this->assertSame(0, $code);
        $this->assertSame('', $err);
        $this->assertStringStartsWith('wsystem-einvoice ' . Version::VERSION, $out);

        [$code, $out, $err] = self::runCli(['wsystem-einvoice', '--help']);
        $this->assertSame(0, $code);
        $this->assertSame('', $err);
        $this->assertStringContainsString('wsystem-einvoice create', $out);
        $this->assertStringContainsString('php-core', $out);
        $this->assertStringContainsString('pre-check', $out);
        $this->assertStringContainsString('saxonc', $out);
        $this->assertStringContainsString('ext-saxonc', $out);
    }

    public function testUnknownCommandIsUsage(): void
    {
        [$code, $out, $err] = self::runCli(['wsystem-einvoice', 'send']);
        $this->assertSame(2, $code);
        $this->assertSame('', $out);
        $this->assertStringContainsString('unknown command: send', $err);
    }

    public function testCreateWritesXmlAndHonoursFormat(): void
    {
        $in = __DIR__ . '/../examples/invoice.json';
        $outFile = sys_get_temp_dir() . '/ws-cli-' . bin2hex(random_bytes(4)) . '.xml';
        try {
            [$code, $out, $err] = self::runCli(['wsystem-einvoice', 'create', '--format', 'en16931', $in, $outFile]);
            $this->assertSame(0, $code, $err);
            $this->assertSame('', $out);
            $this->assertSame('', $err);
            $xml = file_get_contents($outFile);
            $this->assertStringContainsString('<cbc:CustomizationID>urn:cen.eu:en16931:2017</cbc:CustomizationID>', $xml);
            $this->assertStringContainsString('INV-2026-001', $xml);
        } finally {
            @unlink($outFile);
        }
    }

    public function testCreateJsonMetadataForPdfNeedsOutputPath(): void
    {
        $in = __DIR__ . '/../examples/invoice.json';
        [$code, , $err] = self::runCli(['wsystem-einvoice', 'create', '--format', 'zugferd', '--json', $in]);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('needs an output path', $err);
    }

    public function testCreateJsonWritesPdfAndMetadata(): void
    {
        $in = __DIR__ . '/../examples/invoice.json';
        $outFile = sys_get_temp_dir() . '/ws-cli-' . bin2hex(random_bytes(4)) . '.pdf';
        try {
            [$code, $out, $err] = self::runCli(['wsystem-einvoice', 'create', '--format', 'zugferd', '--json', $in, $outFile]);
            $this->assertSame(0, $code, $err);
            $this->assertSame('', $err);
            $meta = json_decode($out, true);
            $this->assertSame('zugferd', $meta['format']);
            $this->assertSame('955.00', $meta['totals']['line_total']);
            $this->assertContains($meta['engine'], ['php-core', 'saxonc']);
            if ($meta['engine'] === 'php-core') {
                $this->assertSame('pre-check', $meta['conformance']);
                $this->assertTrue($meta['valid']);
            }
            $this->assertArrayNotHasKey('pdf', $meta);
            $this->assertStringStartsWith('%PDF-1.7', file_get_contents($outFile));
        } finally {
            @unlink($outFile);
        }
    }

    public function testCreateRejectsXmlInputWithAClearMessage(): void
    {
        [$code, $out, $err] = self::runCli(['wsystem-einvoice', 'create', '-'], '<Invoice/>');
        $this->assertSame(1, $code);
        $this->assertSame('', $out);
        $this->assertStringContainsString('input error: create expects the invoice as JSON', $err);
        $this->assertStringNotContainsString('Stack trace', $err);
    }

    public function testCreateReportsBadJsonWithoutAStackTrace(): void
    {
        [$code, , $err] = self::runCli(['wsystem-einvoice', 'create', '-'], '{');
        $this->assertSame(1, $code);
        $this->assertStringStartsWith('input error: could not parse JSON:', $err);
        $this->assertStringNotContainsString('Stack trace', $err);
    }

    public function testCreateMissingFile(): void
    {
        [$code, , $err] = self::runCli(['wsystem-einvoice', 'create', '/no/such/invoice.json']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('input error: could not read invoice JSON: file not found', $err);
    }

    public function testCreateTypedInputError(): void
    {
        $json = json_encode([
            'format' => 'en16931',
            'invoice_number' => 'X',
            'issue_date' => '1.1.2026',
            'seller' => ['name' => 'A', 'country' => 'DE', 'city' => 'Berlin', 'street' => 'S', 'postal_code' => '1'],
            'buyer' => ['name' => 'B', 'country' => 'DE', 'city' => 'Berlin', 'street' => 'S', 'postal_code' => '1'],
            'lines' => [['description' => 'L', 'quantity' => 1, 'unit_price' => 1, 'vat_rate' => 19]],
        ]);
        [$code, , $err] = self::runCli(['wsystem-einvoice', 'create', '-'], $json);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('input error: issue_date must be YYYY-MM-DD', $err);
    }

    public function testValidateAndParseAGeneratedInvoice(): void
    {
        $in = __DIR__ . '/../examples/invoice.json';
        $xmlFile = sys_get_temp_dir() . '/ws-cli-' . bin2hex(random_bytes(4)) . '.xml';
        try {
            [$code, , $err] = self::runCli(['wsystem-einvoice', 'create', $in, $xmlFile]);
            $this->assertSame(0, $code, $err);
            $xml = file_get_contents($xmlFile);

            [$code, $out, $err] = self::runCli(['wsystem-einvoice', 'validate', '--json', $xmlFile]);
            $this->assertSame(0, $code, $err);
            $r = json_decode($out, true);
            $this->assertIsArray($r);
            $this->assertTrue($r['valid']);
            $this->assertContains($r['engine'], ['php-core', 'saxonc']);
            if ($r['engine'] === 'php-core') {
                $this->assertSame('pre-check', $r['conformance']);
                $this->assertSame('core-subset', $r['checked']['EN16931']);
            } else {
                $this->assertSame('official', $r['conformance']);
            }

            [$code, $out, $err] = self::runCli(['wsystem-einvoice', 'validate', $xmlFile]);
            $this->assertSame(0, $code, $err);
            $this->assertStringContainsString('valid [' . $r['engine'] . ']', $out);
            if ($r['engine'] === 'php-core') {
                $this->assertStringContainsString('pre-check', $out);
                $this->assertStringContainsString('not the official engine', $out);
            } else {
                $this->assertStringContainsString('official KoSIT/CEN schematron', $out);
            }

            [$code, $out, $err] = self::runCli(['wsystem-einvoice', 'parse', '--json', '-'], $xml);
            $this->assertSame(0, $code, $err);
            $parsed = json_decode($out, true);
            $this->assertSame('ubl', $parsed['syntax']);
            $this->assertSame('INV-2026-001', $parsed['invoice']['invoice_number'] ?? $parsed['invoice']['number'] ?? null);
        } finally {
            @unlink($xmlFile);
        }
    }

    public function testValidateRejectsUnreadableInput(): void
    {
        [$code, $out, $err] = self::runCli(['wsystem-einvoice', 'validate', '-'], 'not an invoice');
        $this->assertSame(1, $code);
        $this->assertSame('', $out);
        $this->assertStringStartsWith('input error:', $err);
        $this->assertStringNotContainsString('Stack trace', $err);
    }

    public function testValidateReportsAnInvalidInvoice(): void
    {
        $path = __DIR__ . '/fixtures/validator/shared/invalid/ubl-xrechnung-BR-DE-15-no-buyer-reference.xml';
        [$code, $out, $err] = self::runCli(['wsystem-einvoice', 'validate', $path]);
        $this->assertSame(1, $code, $out . $err);
        $this->assertMatchesRegularExpression('/^invalid \[(php-core|saxonc)\]/', $out);
        $this->assertStringContainsString('BR-DE-15', $out);
    }

    public function testBinVersion(): void
    {
        $bin = realpath(__DIR__ . '/../bin/wsystem-einvoice');
        $this->assertNotFalse($bin);
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bin) . ' --version';
        exec($cmd, $lines, $code);
        $this->assertSame(0, $code);
        $this->assertStringStartsWith('wsystem-einvoice ' . Version::VERSION, implode("\n", $lines));
    }

    /**
     * @param list<string> $argv
     * @return array{0: int, 1: string, 2: string}
     */
    private static function runCli(array $argv, string $stdin = ''): array
    {
        $in = fopen('php://memory', 'r+');
        fwrite($in, $stdin);
        rewind($in);
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        $code = Cli::run($argv, $in, $out, $err);
        rewind($out);
        rewind($err);
        return [$code, stream_get_contents($out), stream_get_contents($err)];
    }
}
