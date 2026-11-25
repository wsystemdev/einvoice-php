<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Validator\Core;

/** @internal An expression uses XPath syntax or a function the pure-PHP engine does not implement. */
final class XPathUnsupported extends \RuntimeException
{
}
