<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Validator\Core;

/** @internal dynamic context */
final class Ctx
{
    /** @param array<string, list<mixed>> $vars */
    public function __construct(public readonly mixed $item, public readonly int $pos, public readonly int $size, public readonly array $vars)
    {
    }
}
