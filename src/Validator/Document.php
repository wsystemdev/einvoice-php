<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Validator;

use WSystem\EInvoice\InputError;
use WSystem\EInvoice\Model\Codes;
use WSystem\EInvoice\Pdf\AttachmentReader;

/**
 * A parsed e-invoice: the XML (taken from the PDF for ZUGFeRD / Factur-X hybrids), its DOM and what it claims to be.
 * Port of vatcheck einvoice.extract_xml / _parse / detect.
 */
final class Document
{
    public const PDF_XML_NAMES = ['factur-x.xml', 'zugferd-invoice.xml', 'xrechnung.xml', 'zugferd_invoice.xml', 'order-x.xml'];
    public const DEFAULT_MAX_BYTES = 5 * 1024 * 1024;

    /** @param array<string, string> $meta container / embedded_file */
    private function __construct(
        public readonly string $xml,
        public readonly \DOMDocument $dom,
        public readonly array $meta,
        public readonly string $syntax,
        public readonly string $documentType,
        public readonly string $customizationId,
        public readonly string $profile,
        /** @var list<string> */
        public readonly array $notes,
    ) {
    }

    public static function load(string $data, int $maxBytes = self::DEFAULT_MAX_BYTES): self
    {
        [$xml, $meta] = self::extractXml($data, $maxBytes);
        $dom = self::parse($xml);
        return self::detect($xml, $dom, $meta);
    }

    /** @return array{0: string, 1: array<string, string>} */
    public static function extractXml(string $data, int $maxBytes = self::DEFAULT_MAX_BYTES): array
    {
        if ($data === '') {
            throw new InputError('empty body: send the invoice XML or PDF as the request body');
        }
        if (strlen($data) > $maxBytes) {
            throw new InputError('file too large (max ' . intdiv($maxBytes, 1024 * 1024) . ' MB)');
        }
        if (substr(ltrim($data, " \t\n\r\x0B\x0C"), 0, 5) !== '%PDF-') {
            return [$data, ['container' => 'xml']];
        }
        try {
            $files = AttachmentReader::read($data);
        } catch (InputError $e) {
            throw str_starts_with($e->getMessage(), 'could not read PDF') ? $e : new InputError('could not read PDF: ' . $e->getMessage());
        } catch (\Throwable $e) {
            throw new InputError('could not read PDF: ' . $e->getMessage());
        }
        if (!$files) {
            throw new InputError('PDF has no embedded files: this is not a ZUGFeRD/Factur-X/XRechnung hybrid invoice');
        }
        $pick = null;
        foreach (array_keys($files) as $n) {
            if (in_array(strtolower((string) $n), self::PDF_XML_NAMES, true)) {
                $pick = (string) $n;
                break;
            }
        }
        if ($pick === null) {
            foreach (array_keys($files) as $n) {
                if (str_ends_with(strtolower((string) $n), '.xml')) {
                    $pick = (string) $n;
                    break;
                }
            }
        }
        if ($pick === null) {
            $names = array_map('strval', array_keys($files));
            sort($names, SORT_STRING);
            throw new InputError('PDF has embedded files but none is XML: [' . implode(', ', array_map(static fn ($n) => "'" . $n . "'", $names)) . ']');
        }
        return [$files[$pick], ['container' => 'pdf', 'embedded_file' => $pick]];
    }

    public static function parse(string $xml): \DOMDocument
    {
        if (str_contains(substr($xml, 0, 2048), '<!DOCTYPE') || str_contains(substr($xml, 0, 4096), '<!ENTITY')) {
            throw new InputError('DOCTYPE/ENTITY declarations are not allowed in e-invoices');
        }
        $prev = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $dom = new \DOMDocument();
            // no LIBXML_NOENT / DTDLOAD: entities are never expanded and nothing is fetched
            $ok = $xml !== '' && $dom->loadXML($xml, LIBXML_NONET);
            if (!$ok || $dom->documentElement === null) {
                $err = libxml_get_errors()[0] ?? libxml_get_last_error();
                $msg = $err ? trim($err->message) . ', line ' . $err->line . ', column ' . $err->column : 'no root element';
                throw new InputError('not well-formed XML: ' . $msg);
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
        return $dom;
    }

    /** @param array<string, string> $meta */
    private static function detect(string $xml, \DOMDocument $dom, array $meta): self
    {
        $root = $dom->documentElement;
        $ns = (string) $root->namespaceURI;
        $local = $root->localName;
        if ($ns === Codes::NS['ubl_inv'] && $local === 'Invoice') {
            [$syntax, $doctype] = ['ubl', 'ubl_inv'];
        } elseif ($ns === Codes::NS['ubl_cn'] && $local === 'CreditNote') {
            [$syntax, $doctype] = ['ubl', 'ubl_cn'];
        } elseif ($ns === Codes::NS['cii'] && $local === 'CrossIndustryInvoice') {
            [$syntax, $doctype] = ['cii', 'cii'];
        } else {
            throw new InputError("unsupported root element {{$ns}}{$local}: expected UBL Invoice/CreditNote or UN/CEFACT CII");
        }
        $xp = self::xpath($dom);
        $q = $syntax === 'ubl' ? 'cbc:CustomizationID'
            : 'rsm:ExchangedDocumentContext/ram:GuidelineSpecifiedDocumentContextParameter/ram:ID';
        $n = $xp->query($q, $root)->item(0);
        $cid = self::pyStrip($n ? $n->textContent : '');
        $c = mb_strtolower($cid);
        if ($cid === Codes::XRECHNUNG_EXT_ID) {
            $profile = 'XRechnung Extension';
        } elseif (str_contains($c, 'xrechnung')) {
            $profile = 'XRechnung';
        } elseif (str_contains($c, 'peppol.eu:2017:poacc:billing')) {
            $profile = 'Peppol BIS Billing 3.0';
        } elseif (str_contains($c, 'factur-x.eu') || str_contains($c, 'zugferd')) {
            $parts = explode(':', $c);
            $profile = 'Factur-X/ZUGFeRD ' . (str_contains($c, ':') ? mb_strtoupper(end($parts)) : '');
        } elseif (str_starts_with($c, 'urn:cen.eu:en16931:2017')) {
            $profile = !str_contains($c, '#') ? 'EN 16931' : 'EN 16931 CIUS/extension';
        } else {
            $profile = 'unknown';
        }
        $notes = [];
        if (str_contains($c, 'minimum') || str_contains($c, 'basicwl')) {
            $notes[] = 'Factur-X MINIMUM and BASIC WL are not EN 16931 compliant invoices; in DE/FR B2B they are not valid e-invoices on their own.';
        }
        if ($profile === 'XRechnung Extension') {
            $notes[] = "XRechnung 3.0 Extension: checked with the EN 16931 and XRechnung rules using the severity levels of KoSIT's "
                . 'official Extension scenario (e.g. DIGA scheme IDs such as XR01/XR03 under BR-CL-10/11/21/25/26 are allowed and '
                . 'reported as warnings). The receiver must accept the Extension.';
        }
        if (str_contains($c, 'extended')) {
            $notes[] = 'Factur-X EXTENDED goes beyond EN 16931: the EN 16931 CII syntax restrictions (CII-SR-*, CII-DT-*, e.g. '
                . "'... should not be present') are reported as warnings, not errors, because EXTENDED allows those elements. "
                . 'EN 16931 business rules (BR-*) still apply as errors. The Factur-X EXTENDED schematron itself is not run.';
        }
        return new self($xml, $dom, $meta, $syntax, $doctype, $cid, $profile, $notes);
    }

    public static function xpath(\DOMDocument $dom): \DOMXPath
    {
        $xp = new \DOMXPath($dom);
        foreach (['cbc', 'cac', 'rsm', 'ram', 'udt', 'qdt'] as $p) {
            $xp->registerNamespace($p, Codes::NS[$p]);
        }
        $xp->registerNamespace('ubl', $dom->documentElement->namespaceURI ?? Codes::NS['ubl_inv']);
        return $xp;
    }

    /** Python str.strip() (Unicode whitespace) */
    public static function pyStrip(string $s): string
    {
        return preg_replace('/^[\s\x{1C}-\x{1F}\x{85}\x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]+|[\s\x{1C}-\x{1F}\x{85}\x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]+$/u', '', $s) ?? trim($s);
    }

    /** @return array<string, mixed> the detection block of the result */
    public function detection(): array
    {
        return ['syntax' => $this->syntax, 'document_type' => $this->documentType, 'customization_id' => $this->customizationId, 'profile' => $this->profile];
    }
}
