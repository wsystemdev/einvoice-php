<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Tests\Validator;

/**
 * The shared validator corpus: tests/fixtures/validator/files/* with golden results from the W System Python engine
 * (tools/validator/build_validator_corpus.py). Rule ids, severities, rule sets, messages and locations must match.
 */
final class Golden
{
    public const DIR = __DIR__ . '/../fixtures/validator';

    /** @return array<string, array<string, mixed>> */
    public static function cases(): array
    {
        static $c = null;
        return $c ??= json_decode((string) file_get_contents(self::DIR . '/golden.json'), true, 512, JSON_THROW_ON_ERROR)['cases'];
    }

    public static function bytes(array $case): string
    {
        return (string) file_get_contents(self::DIR . '/files/' . $case['file']);
    }

    /** @return list<string> comparable signature of result entries */
    public static function sig(array $entries, bool $full = true): array
    {
        return array_map(static fn ($i) => implode(' | ', [$i['rule_id'], $i['severity'], $i['ruleset']]
            + ($full ? [3 => $i['rule_id'] === 'XSD' ? '' : (string) $i['location'], 4 => (string) $i['message']] : [])), array_values($entries));
    }

    /** Assert a PHP result equals the golden Python-engine result for one corpus case (verdict, entries, detection). */
    public static function assertSameAsGolden(\PHPUnit\Framework\TestCase $t, array $c, array $r): void
    {
        $t->assertNull($c['input_error'], 'reference raised: ' . (string) $c['input_error']);
        $t->assertSame($c['valid'], $r['valid']);
        $t->assertSame(self::sig($c['errors']), self::sig($r['errors']));
        $t->assertSame(self::sig($c['warnings']), self::sig($r['warnings']));
        foreach (['syntax', 'document_type', 'customization_id', 'profile', 'container', 'notes'] as $k) {
            $t->assertSame($c[$k], $r[$k], $k);
        }
        $t->assertSame($c['embedded_file'] ?? null, $r['embedded_file'] ?? null);
        foreach ($c['errors'] as $i => $e) {
            $t->assertSame($e['business_terms'], $r['errors'][$i]['business_terms'], $e['rule_id']);
            if ($e['rule_id'] === 'XSD') {
                $t->assertSame($e['message'], $r['errors'][$i]['message']);
            }
        }
    }

    public static function saxonOrSkip(\PHPUnit\Framework\TestCase $t): void
    {
        if (!\WSystem\EInvoice\Validator\Backend\SaxonCBackend::available()) {
            if (getenv('WSYSTEM_REQUIRE_SAXONC') === '1') {
                $t->fail('WSYSTEM_REQUIRE_SAXONC=1 but the SaxonC-HE extension is not loaded');
            }
            $t->markTestSkipped('SaxonC-HE extension not loaded (the CI "saxonc" job runs these)');
        }
    }
}
