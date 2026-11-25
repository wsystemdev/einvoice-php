<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Validator\Backend;

use WSystem\EInvoice\Validator\Document;
use WSystem\EInvoice\Validator\RulePack;

final class CoreRulesBackend implements SchematronBackend
{
    public const VERSION = 'W System core rules 0.1';

    public function name(): string
    {
        return 'php-core';
    }

    public function official(): bool
    {
        return false;
    }

    public function run(Document $doc, string $ruleset, RulePack $rules): array
    {
        return [];
    }
}
