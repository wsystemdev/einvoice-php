<?php

declare(strict_types=1);

namespace WSystem\EInvoice;

use WSystem\EInvoice\Validator\ValidatorInterface;

/**
 * Resolves the validator used by create() and the CLI. When the validator stream's
 * WSystem\EInvoice\Validator\Validator class is autoloadable, it is used by default.
 * Callers can still inject a different ValidatorInterface.
 */
final class Validators
{
    public static function resolve(?ValidatorInterface $given = null): ?ValidatorInterface
    {
        if ($given !== null) {
            return $given;
        }
        if (class_exists(Validator\Validator::class)) {
            return new Validator\Validator();
        }
        return null;
    }
}
