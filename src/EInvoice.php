<?php

declare(strict_types=1);

namespace WSystem\EInvoice;

use WSystem\EInvoice\Generator\Generator;
use WSystem\EInvoice\Validator\ValidatorInterface;

/**
 * Entry point. create() builds the invoice locally (same output as api.wsystem.dev POST /einvoice/create) and, when a
 * validator is given, checks it before returning. For the PDF formats a PDF is built only when the XML is valid.
 */
final class EInvoice
{
    public function __construct(private ?ValidatorInterface $validator = null)
    {
    }

    public function create(array $invoice): array
    {
        return Generator::create($invoice, $this->validator);
    }

    public function validate(string $data, string $rules = 'auto'): array
    {
        if ($this->validator === null) {
            throw new \LogicException('no validator configured');
        }
        return $this->validator->validate($data, $rules);
    }
}
