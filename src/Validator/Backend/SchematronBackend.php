<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Validator\Backend;

use WSystem\EInvoice\Validator\Document;
use WSystem\EInvoice\Validator\RulePack;

/** Runs one business-rule set (EN16931 or XRechnung) on a parsed invoice. */
interface SchematronBackend
{
    /** "saxonc", "command", "core-rules", ... (reported as result.backend) */
    public function name(): string;

    /** true when the official KoSIT/CEN schematron runs (checked.* = true); false for a subset (checked.* = "core-subset"). */
    public function official(): bool;

    /**
     * @param string $ruleset "EN16931" or "XRechnung"
     * @return list<array<string, mixed>> result entries (see Issues::entry)
     */
    public function run(Document $doc, string $ruleset, RulePack $rules): array;
}
