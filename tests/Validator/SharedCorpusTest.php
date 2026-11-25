<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Tests\Validator;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WSystem\EInvoice\InputError;
use WSystem\EInvoice\Validator\Backend\CoreRulesBackend;
use WSystem\EInvoice\Validator\Backend\SaxonCBackend;
use WSystem\EInvoice\Validator\Validator;

/**
 * Verdicts on the public corpus shared with the W System validator (Docker): wsystemdev/einvoice-validator
 * fixtures/expected.json, copied to tests/fixtures/validator/shared/ by tools/validator/sync_shared_fixtures.sh.
 * Expected: same verdict, profile and syntax; every listed rule id must appear; input_error files are rejected.
 * Checked separately for the default php-core engine and for SaxonC (QA TK2-06).
 */
final class SharedCorpusTest extends TestCase
{
    private const DIR = __DIR__ . '/../fixtures/validator/shared';

    public static function cases(): iterable
    {
        $exp = json_decode((string) file_get_contents(self::DIR . '/expected.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach (['php-core', 'saxonc'] as $engine) {
            foreach ($exp['files'] as $file => $e) {
                yield "$engine $file" => [$engine, $file, $e];
            }
        }
    }

    #[DataProvider('cases')]
    public function testVerdict(string $engine, string $file, array $e): void
    {
        if ($engine === 'saxonc') {
            Golden::saxonOrSkip($this);
        }
        $v = new Validator($engine === 'saxonc' ? new SaxonCBackend() : new CoreRulesBackend());
        $bytes = (string) file_get_contents(self::DIR . '/' . $file);
        if (!empty($e['input_error'])) {
            $this->expectException(InputError::class);
            $v->validate($bytes);
            return;
        }
        $r = $v->validate($bytes);
        $ids = array_values(array_unique(array_column($r['errors'], 'rule_id')));
        $this->assertSame($e['valid'], $r['valid'], 'verdict; errors: ' . implode(', ', $ids));
        $this->assertSame($e['profile'], $r['profile']);
        $this->assertSame($e['syntax'], $r['syntax']);
        $this->assertSame([], array_values(array_diff($e['errors'], $ids)), 'expected rule ids missing; got ' . implode(', ', $ids));
        $this->assertSame($engine, $r['engine']);
        $this->assertSame($engine === 'saxonc' ? 'official' : 'pre-check', $r['conformance']);
    }

    public function testCorpusIsTheSharedOne(): void
    {
        $exp = json_decode((string) file_get_contents(self::DIR . '/expected.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(17, $exp['files']);
        $this->assertStringStartsWith('wsystemdev/einvoice-validator ', (string) file_get_contents(self::DIR . '/SOURCE'));
        foreach (array_keys($exp['files']) as $f) {
            $this->assertFileExists(self::DIR . '/' . $f);
        }
    }
}
