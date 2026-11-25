<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Validator\Core;

/** @internal A dynamic XPath error (e.g. FORG0001 invalid cast), as Saxon would raise it. */
final class XPathError extends \RuntimeException
{
    public function __construct(public readonly string $xpathCode, string $message)
    {
        parent::__construct("{$xpathCode}: {$message}");
    }
}
