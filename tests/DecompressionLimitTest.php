<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Tests;

use PHPUnit\Framework\TestCase;
use WSystem\EInvoice\InputError;
use WSystem\EInvoice\Parser;
use WSystem\EInvoice\Pdf\AttachmentReader;
use WSystem\EInvoice\Validator\Validator;

/** Decompression bombs in untrusted PDFs are refused before they use the memory (fixtures/bomb/make_bomb.py). */
final class DecompressionLimitTest extends TestCase
{
    private const LIMIT = 'decompressed data exceeds the limit of 75 MB';

    public function testOneGiBBombIsRefusedWithoutInflatingIt(): void
    {
        // make_bomb.py bomb-1gib.pdf 1024: 1 MB PDF whose factur-x.xml inflates to 1 GiB
        $data = (string) file_get_contents(__DIR__ . '/fixtures/bomb/bomb-1gib.pdf');
        $this->assertLessThan(1_100_000, strlen($data));
        // the reader holds up to 75 MB of decompressed data before the limit trips (about 100 MB peak on its own);
        // PHPUnit's own memory comes on top of PHP's 128M default, so give the test some room
        $old = ini_set('memory_limit', '512M');
        $before = memory_get_usage(true);
        foreach ([fn () => Parser::parse($data), fn () => (new Validator())->validate($data)] as $call) {
            try {
                $call();
                $this->fail('no InputError');
            } catch (InputError $e) {
                $this->assertStringContainsString(self::LIMIT, $e->getMessage());
            }
        }
        $this->assertLessThan(300 * 1024 * 1024, memory_get_peak_usage(true) - $before);
        ini_set('memory_limit', (string) $old);
    }

    /** Several object streams, each under the limit, together over it. */
    public function testLimitCountsAllStreamsOfOnePdf(): void
    {
        // up to 75 MB of real decompressed data is held before the limit trips, more than PHP's 128M default allows
        $old = ini_set('memory_limit', '512M');
        $z = gzcompress(str_repeat("\0", 30_000_000), 9);
        $pdf = "%PDF-1.7\n";
        $offs = [];
        foreach (['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [] /Count 0 >>'] as $i => $o) {
            $offs[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n$o\nendobj\n";
        }
        for ($k = 0; $k < 3; $k++) {
            $offs[] = strlen($pdf);
            $pdf .= (3 + $k) . " 0 obj\n<< /Type /ObjStm /N 1 /First 4 /Filter /FlateDecode /Length " . strlen($z) . " >>\nstream\n$z\nendstream\nendobj\n";
        }
        $x = strlen($pdf);
        $pdf .= "xref\n0 " . (count($offs) + 1) . "\n0000000000 65535 f \n";
        foreach ($offs as $o) {
            $pdf .= sprintf("%010d 00000 n \n", $o);
        }
        $pdf .= "trailer\n<< /Size " . (count($offs) + 1) . " /Root 1 0 R >>\nstartxref\n$x\n%%EOF\n";
        unset($z);
        try {
            Parser::parse($pdf);
            $this->fail('no InputError');
        } catch (InputError $e) {
            $this->assertStringContainsString(self::LIMIT, $e->getMessage());
        } finally {
            ini_set('memory_limit', (string) $old);
        }
    }

    public function testNormalStreamsStillInflate(): void
    {
        // zlib and raw deflate, small and just under 1 MiB, the same bytes as gzuncompress / gzinflate give
        foreach ([str_repeat('<a>x</a>', 10), bin2hex(random_bytes(150_000)) . str_repeat('z', 700_000)] as $body) {
            $xml = '<?xml version="1.0"?><r>' . $body . '</r>';
            foreach ([gzcompress($xml), "\x78\x9c" . gzdeflate($xml)] as $stream) {
                $pdf = "%PDF-1.7\n1 0 obj\n<< /Type /Catalog /Names << /EmbeddedFiles << /Names [(a.xml) 2 0 R] >> >> >>\nendobj\n"
                    . "2 0 obj\n<< /Type /Filespec /F (a.xml) /EF << /F 3 0 R >> >>\nendobj\n"
                    . "3 0 obj\n<< /Type /EmbeddedFile /Filter /FlateDecode /Length " . strlen($stream) . " >>\nstream\n$stream\nendstream\nendobj\n%%EOF\n";
                $this->assertSame(['a.xml' => $xml], AttachmentReader::read($pdf));
            }
        }
        $this->assertSame(75_000_000, AttachmentReader::MAX_INFLATED_BYTES);
    }
}
