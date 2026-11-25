<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Validator\Core;

/** @internal xs:untypedAtomic (the typed value of a node in a non-schema-aware run). */
final class Untyped
{
    public function __construct(public readonly string $v)
    {
    }
}
