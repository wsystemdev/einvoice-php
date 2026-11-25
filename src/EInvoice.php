<?php

declare(strict_types=1);

namespace WSystem\EInvoice;

use WSystem\EInvoice\Generator\Generator;
use WSystem\EInvoice\Validator\ValidatorInterface;

/**
 * Entry point. create() builds the invoice locally (same output as api.wsystem.dev POST /einvoice/create) and, when a
 * validator is available, checks it before returning. For the PDF formats a PDF is built only when the XML is valid.
 * If you don't pass a validator, Validator\Validator is used when that class is present (validator module).
 */
final class EInvoice
{
    private ?ValidatorInterface $validator;

    public function __construct(?ValidatorInterface $validator = null)
    {
        $this->validator = Validators::resolve($validator);
    }

    public function create(array $invoice): array
    {
        return Generator::create($invoice, $this->validator);
    }

    public function validate(string $data, string $rules = 'auto'): array
    {
        if ($this->validator === null) {
            throw new \LogicException('no validator configured: the validator module is not in this build yet');
        }
        return $this->validator->validate($data, $rules);
    }
}
