<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Tests\Validator;

use PHPUnit\Framework\TestCase;
use WSystem\EInvoice\InputError;
use WSystem\EInvoice\Validator\Document;
use WSystem\EInvoice\Validator\Validator;
use WSystem\EInvoice\Validator\Backend\CoreRulesBackend;

/** Input handling and detection do not need a schematron engine: they must match the Python engine everywhere. */
final class DocumentTest extends TestCase
{
    public function testInputErrorsAndDetectionMatchReference(): void
    {
        $n = 0;
        foreach (Golden::cases() as $k => $c) {
            try {
                $d = Document::load(Golden::bytes($c));
            } catch (InputError $e) {
                $this->assertNotNull($c['input_error'], "$k: unexpected " . $e->getMessage());
                if (str_starts_with($c['input_error'], 'not well-formed XML:')) {
                    // lxml appends "(<string>, line N)"; the libxml message itself is the same
                    $this->assertStringStartsWith($e->getMessage(), $c['input_error'], $k);
                } else {
                    $this->assertSame($c['input_error'], $e->getMessage(), $k);
                }
                $n++;
                continue;
            }
            $this->assertNull($c['input_error'], $k);
            $this->assertSame([$c['syntax'], $c['document_type'], $c['customization_id'], $c['profile'], $c['container'], $c['embedded_file'] ?? null],
                [$d->syntax, $d->documentType, $d->customizationId, $d->profile, $d->meta['container'], $d->meta['embedded_file'] ?? null], $k);
        }
        $this->assertSame(6, $n);
    }

    public function testLimitsAndRules(): void
    {
        try {
            Document::load(str_repeat(' ', 11), 10);
            $this->fail('no size limit');
        } catch (InputError $e) {
            $this->assertStringContainsString('file too large', $e->getMessage());
        }
        $this->expectException(InputError::class);
        $this->expectExceptionMessage('rules must be auto, en16931 or xrechnung');
        (new Validator(new CoreRulesBackend()))->validate(Golden::bytes(Golden::cases()['cen/ubl/ubl-tc434-example1.xml']), 'peppol');
    }

    public function testNoEntityExpansionOrNetwork(): void
    {
        $this->expectException(InputError::class);
        Document::load('<?xml version="1.0"?><!DOCTYPE Invoice SYSTEM "http://example.invalid/x.dtd"><Invoice/>');
    }
}
