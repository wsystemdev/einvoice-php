<?php

declare(strict_types=1);

namespace WSystem\EInvoice;

use WSystem\EInvoice\Generator\Generator;
use WSystem\EInvoice\Validator\ValidatorInterface;

/** Short alias: Builder::create($invoice) == Generator::create($invoice). */
final class Builder
{
    public static function create(array $invoice, ?ValidatorInterface $validator = null): array
    {
        return Generator::create($invoice, $validator);
    }

    public static function xml(array $invoice): string
    {
        return Generator::xml($invoice)['xml'];
    }
}
