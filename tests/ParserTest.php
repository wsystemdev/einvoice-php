<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WSystem\EInvoice\InputError;
use WSystem\EInvoice\Parser;

/** Parser::parse() gives exactly the W System engine's parse output (tools/validator/build_parse_golden.py). */
final class ParserTest extends TestCase
{
    private const DIR = __DIR__ . '/fixtures/validator';

    public static function files(): iterable
    {
        foreach (json_decode((string) file_get_contents(self::DIR . '/parse_golden.json'), true, 512, JSON_THROW_ON_ERROR) as $f => $want) {
            yield $f => [$f, $want];
        }
    }

    #[DataProvider('files')]
    public function testSameAsPythonEngine(string $file, array $want): void
    {
        $data = (string) file_get_contents(self::DIR . '/' . $file);
        if (isset($want['input_error'])) {
            try {
                Parser::parse($data);
                $this->fail('expected InputError');
            } catch (InputError $e) {
                // same message; the PHP one says {} where Python prints {None} for an element without a namespace
                $this->assertSame(str_replace('{None}', '{}', explode(' (<string>', $want['input_error'])[0]),
                    substr($e->getMessage(), 0, strlen(str_replace('{None}', '{}', explode(' (<string>', $want['input_error'])[0]))));
            }
            return;
        }
        if (str_ends_with($file, '04.05a-INVOICE_uncefact.xml')) {
            // Known engine bug, not copied: Python's _cii_date_any iterates lxml comments too and returns the XML
            // comment "Tag der Leistungserbringung" as the delivery date; the PHP parser returns the date (20200102).
            $this->assertSame('Tag der Leistungserbringung', $want['invoice']['delivery']['date']);
            $want['invoice']['delivery']['date'] = '2020-01-02';
        }
        $got = Parser::parse($data);
        $this->assertSame(json_encode($want, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), json_encode($got, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}
