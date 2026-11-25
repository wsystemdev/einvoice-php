<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Validator;

/**
 * Location and identity of the official rule files: the KoSIT XRechnung validator configuration (Apache-2.0), which
 * ships the CEN EN 16931 validation artefacts (EUPL-1.2) unmodified. Bundled under resources/kosit/; no network.
 */
final class RulePack
{
    public const VERSION = '2026-08-31';
    public const SHA256 = '2530cd107c414511c5d0462ec10f886910395abfca820db82e83d70bf01221a8';

    public const XSL = [
        'ubl' => ['EN16931' => 'resources/ubl/2.1/xsl/EN16931-UBL-validation.xsl', 'XRechnung' => 'resources/xrechnung/3.0.2/xsl/XRechnung-UBL-validation.xsl'],
        'cii' => ['EN16931' => 'resources/cii/16b/xsl/EN16931-CII-validation.xsl', 'XRechnung' => 'resources/xrechnung/3.0.2/xsl/XRechnung-CII-validation.xsl'],
    ];
    public const XSD = [
        'ubl_inv' => 'resources/ubl/2.1/xsd/maindoc/UBL-Invoice-2.1.xsd',
        'ubl_cn' => 'resources/ubl/2.1/xsd/maindoc/UBL-CreditNote-2.1.xsd',
        'cii' => 'resources/cii/16b/xsd/CrossIndustryInvoice_100pD16B.xsd',
    ];
    public const SOURCES = [
        'EN16931' => 'CEN/TC 434 EN 16931 validation artefacts (EUPL-1.2) https://github.com/ConnectingEurope/eInvoicing-EN16931',
        'XRechnung' => 'KoSIT XRechnung validator configuration (Apache-2.0) https://github.com/itplr-kosit/validator-configuration-xrechnung',
    ];

    public function __construct(private ?string $dir = null)
    {
        // WSYSTEM_EINVOICE_RULES: a pack installed by `rules:update` (opt-in); the bundled pack otherwise
        $this->dir ??= (getenv('WSYSTEM_EINVOICE_RULES') ?: null) ?? dirname(__DIR__, 2) . '/resources/kosit/xrechnung-3.0.2-' . self::VERSION;
    }

    public function isBundled(): bool
    {
        return realpath($this->dir) === realpath(dirname(__DIR__, 2) . '/resources/kosit/xrechnung-3.0.2-' . self::VERSION);
    }

    public function dir(): string
    {
        return $this->dir;
    }

    public function xsl(string $syntax, string $ruleset): string
    {
        return $this->file(self::XSL[$syntax][$ruleset]);
    }

    public function xsd(string $documentType): string
    {
        return $this->file(self::XSD[$documentType]);
    }

    private function file(string $rel): string
    {
        $p = $this->dir . '/' . $rel;
        if (!is_file($p)) {
            throw new \RuntimeException("rule file missing: {$p} (the package's resources/kosit directory is incomplete)");
        }
        return $p;
    }

    /** @return array<string, string> */
    public static function ruleVersions(): array
    {
        return ['EN16931' => '1.3.15+ via KoSIT config', 'XRechnung' => '3.0.2 config ' . self::VERSION];
    }
}
