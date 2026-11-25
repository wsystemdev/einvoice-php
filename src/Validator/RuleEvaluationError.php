<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Validator;

/**
 * The business rules could not be evaluated on this document: an official rule hit a dynamic XPath error, almost
 * always because a value has the wrong type (e.g. "XX" where the schema expects a number), which the XML Schema check
 * also reports. The official engine (Saxon) aborts the whole rule run in that case, and so does every backend here, so
 * no partial result can look valid. It is an InputError (HTTP 400 at the API; "input error" in the CLI). $xsdErrors holds the XML Schema findings, which usually name the field to fix.
 */
final class RuleEvaluationError extends \WSystem\EInvoice\InputError
{
    public readonly string $detail;

    /** Same error with the XML Schema findings attached (and named in the message). */
    public function withXsdErrors(array $xsdErrors): self
    {
        return new self($this->detail, $this->engine, $this->ruleset, $xsdErrors, $this->getPrevious());
    }

    /**
     * @param list<array<string, mixed>> $xsdErrors
     */
    public function __construct(
        string $detail,
        public readonly string $engine,
        public readonly string $ruleset,
        public readonly array $xsdErrors = [],
        ?\Throwable $previous = null,
    ) {
        $xsd = $xsdErrors ? ' XML Schema: ' . $xsdErrors[0]['message'] . (count($xsdErrors) > 1 ? ' (+' . (count($xsdErrors) - 1) . ' more)' : '') : '';
        parent::__construct("The {$ruleset} rules could not be evaluated on this document ({$engine}): {$detail}. "
            . 'This usually means a value has the wrong type; fix the XML Schema errors first.' . $xsd, 0, $previous);
        $this->detail = $detail;
    }
}
