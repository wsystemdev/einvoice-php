<?php

declare(strict_types=1);

namespace WSystem\EInvoice;

/** The invoice data is incomplete or invalid (the API answers these with HTTP 400). */
class InputError extends \InvalidArgumentException
{
}
