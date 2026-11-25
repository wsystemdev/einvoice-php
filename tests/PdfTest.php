<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Tests;

use PHPUnit\Framework\TestCase;
use WSystem\EInvoice\Builder;
use WSystem\EInvoice\Generator\Generator;
use WSystem\EInvoice\InputError;
use WSystem\EInvoice\Pdf\AttachmentReader;

final class PdfTest extends TestCase
{
    private static function sample(array $over = []): array
    {
        $in = json_decode(file_get_contents(__DIR__ . '/fixtures/inputs.json'), true);
        foreach ($in as $f) {
            if ($f['name'] === 'sample-zugferd') {
                return array_replace($f['input'], $over);
            }
        }
        self::fail('fixture missing');
    }

    public function testZugferdPdfStructureAndLosslessXml(): void
    {
        foreach (['zugferd' => 'factur-x.xml', 'factur-x' => 'factur-x.xml', 'zugferd-xrechnung' => 'xrechnung.xml'] as $fmt => $name) {
            $r = Builder::create(self::sample(['format' => $fmt]));
            $pdf = $r['pdf'];
            $this->assertStringStartsWith("%PDF-1.7\n%\xE2\xE3\xCF\xD3", $pdf);
            $this->assertSame($name, $r['embedded_file']);
            $this->assertSame(['standard' => 'PDF/A-3b'], array_intersect_key($r['pdf_info'], ['standard' => 1]));
            $this->assertSame([$name => $r['xml']], AttachmentReader::read($pdf), 'embedded XML must be byte-identical');
            foreach (['/AFRelationship /Alternative', '/Subtype /text#2Fxml', '/OutputIntents', '/GTS_PDFA1', '<pdfaid:part>3</pdfaid:part>',
                '<pdfaid:conformance>B</pdfaid:conformance>', '/ID [<', '/FontFile2', '/AF ['] as $needle) {
                $this->assertStringContainsString($needle, $pdf, $needle);
            }
            $this->assertStringContainsString('<fx:ConformanceLevel>' . ($fmt === 'zugferd-xrechnung' ? 'XRECHNUNG' : 'EN 16931') . '</fx:ConformanceLevel>', $pdf);
            $this->assertStringNotContainsString('/Helvetica', $pdf, 'only embedded fonts');
        }
    }

    public function testCharactersOutsideTheFontsAreNotedXmlKeepsThem(): void
    {
        $r = Builder::create(self::sample(['lines' => [['description' => 'Ελληνικά 漢字 łódź €', 'quantity' => 1, 'unit_price' => 10, 'vat_rate' => 19]]]));
        $this->assertStringContainsString('Ελληνικά 漢字 łódź €', $r['xml']);
        $this->assertTrue($r['pdf_info_replaced_characters'] ?? false);
        $this->assertStringContainsString('embedded XML keeps them unchanged', implode(' ', $r['notes']));
        $plain = Builder::create(self::sample());
        $this->assertArrayNotHasKey('pdf_info_replaced_characters', $plain);
    }

    public function testMultiPageInvoice(): void
    {
        $lines = [];
        for ($i = 0; $i < 80; $i++) {
            $lines[] = ['description' => "Position $i with a longer description that wraps over two lines in the table", 'quantity' => 1, 'unit_price' => '1.5', 'vat_rate' => 19];
        }
        $r = Builder::create(self::sample(['lines' => $lines]));
        $this->assertGreaterThanOrEqual(3, preg_match_all('#/Type /Page\b#', $r['pdf']));
        $this->assertSame('142.80', $r['totals']['total_with_vat']);
    }

    public function testInputErrorsAreTyped(): void
    {
        $this->expectException(InputError::class);
        $this->expectExceptionMessage('issue_date must be YYYY-MM-DD');
        Generator::create(self::sample(['issue_date' => '1.1.2026']));
    }

    public function testAttachmentReaderRefusesNonPdfAttachments(): void
    {
        $this->assertSame([], AttachmentReader::read("%PDF-1.4\n1 0 obj <</Type /Catalog>> endobj\n%%EOF"));
    }

    public function testCreditNoteAndAllowancesAppearInTheVisualPdf(): void
    {
        $r = Builder::create($this->named('sample-cn-ac-zugferd'));
        $text = self::pdfText($r['pdf']);
        $this->assertStringContainsString('Gutschrift / Credit note', $text);
        $this->assertStringContainsString('Bezug auf Rechnung', $text);
        $this->assertStringContainsString('INV-2026-000', $text);
        $this->assertStringContainsString('01.10.2026', $text);
        $this->assertStringNotContainsString('Due date', $text, 'credit notes have no due date on the visual invoice');
        $this->assertStringContainsString('Nachlass / Allowance: Treuerabatt', $text);
        $this->assertStringContainsString('Zuschlag / Charge: Versand', $text);
        $this->assertStringContainsString('Rabatt / Discount 10', $text);
        $this->assertStringContainsString('Setup: 2', $text);
        $this->assertSame(['factur-x.xml' => $r['xml']], AttachmentReader::read($r['pdf']));
        $this->assertSame('1252.98', $r['totals']['line_total']);
        $this->assertSame('10.00', $r['totals']['allowance_total']);
        $this->assertSame('4.90', $r['totals']['charge_total']);
    }

    public function testDefaultChargeReasonIsNotRepeatedOnThePdf(): void
    {
        $r = Builder::create($this->named('pct-allowance-doc-multi-cii'));
        $text = self::pdfText($r['pdf']);
        $this->assertStringContainsString('Rechnung / Invoice', $text);
        $this->assertStringContainsString('Nachlass / Allowance: Skonto', $text);
        $this->assertStringContainsString('Zuschlag / Charge [7 %]', $text);
        $this->assertStringNotContainsString('Zuschlag / Charge: Zuschlag / Charge', $text);
        $this->assertSame('28.65', $r['totals']['allowance_total']);
    }

    /** @return array<string, mixed> */
    private function named(string $name): array
    {
        $in = json_decode(file_get_contents(__DIR__ . '/fixtures/inputs.json'), true);
        foreach ($in as $f) {
            if ($f['name'] === $name) {
                return $f['input'];
            }
        }
        self::fail("fixture $name missing");
    }

    /** Inflate content streams so tests can see the visual text without pdftotext. */
    private static function pdfText(string $pdf): string
    {
        $out = '';
        if (!preg_match_all('#stream\r?\n(.*?)endstream#s', $pdf, $m)) {
            return '';
        }
        foreach ($m[1] as $s) {
            $try = @gzuncompress($s);
            if ($try === false) {
                $try = @gzuncompress(rtrim($s, "\n"));
            }
            if (!is_string($try) || !str_contains($try, ' Tj')) {
                continue;
            }
            $try = str_replace(['\\(', '\\)'], ['(', ')'], $try);
            $out .= mb_convert_encoding($try, 'UTF-8', 'Windows-1252') . "\n";
        }
        return $out;
    }
}
