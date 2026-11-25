<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Validator\National;

use WSystem\EInvoice\Validator\Document;

/**
 * Slovak national field checks (eFaktúra, from 2027-01-01). Port of vatcheck einvoice_sk. W System's own checks from
 * public Financial Administration (FR SR) documents, NOT the official Peppol BIS / Slovak schematron. Warnings only (SK-FS).
 */
final class Slovakia
{
    public const TRANSPOSITION = 'FR SR Peppol BIS transposition v1.13 (2026-10-29)';
    public const SOURCE = 'https://www.financnasprava.sk/sk/podnikatelia/dane/dan-z-pridanej-hodnoty/e-faktura';
    public const UUID_NAMESPACE = '3fd00464-9f15-52ce-8cdc-6dd374d5ad42';
    private const RATES = ['23', '19', '5'];
    private const EXEMPT_CATS = ['E', 'AE', 'K', 'G', 'O'];

    /** UUIDv5 of 'BT-34@schemeID BT-34 BT-3 BT-1 BT-2' (single spaces, UTF-8) in the fixed FR SR namespace. */
    public static function invoiceUuid(string $scheme, string $endpoint, string $typeCode, string $number, string $issueDate): string
    {
        $name = implode(' ', array_map([Document::class, 'pyStrip'], [$scheme, $endpoint, $typeCode, $number, $issueDate]));
        $h = sha1(hex2bin(str_replace('-', '', self::UUID_NAMESPACE)) . $name);
        $h[12] = '5';
        $h[16] = dechex((hexdec($h[16]) & 0x3) | 0x8);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
    }

    private static function w(string $rid, string $msg, array $bts, string $fix): array
    {
        return Xml::w('SK-FS', $rid, $msg, $bts, $fix);
    }

    /** @return array{0: list<array<string, mixed>>, 1: array<string, mixed>} */
    public static function check(Document $doc): array
    {
        $x = new Xml(Document::xpath($doc->dom));
        $root = $doc->dom->documentElement;
        if ($doc->syntax !== 'ubl') {
            if (Xml::up($x->t($root, './/ram:SellerTradeParty/ram:PostalTradeAddress/ram:CountryID')) === 'SK') {
                return [[], ['applies' => false, 'note' => 'Slovak national field checks run on UBL only (Slovak eFaktúra over Peppol uses UBL 2.1 / Peppol BIS Billing 3.0); this CII file was checked against EN 16931 only.']];
            }
            return [[], ['applies' => false]];
        }
        $sup = $x->find($root, 'cac:AccountingSupplierParty/cac:Party');
        $cus = $x->find($root, 'cac:AccountingCustomerParty/cac:Party');
        if (Xml::up($x->t($sup, 'cac:PostalAddress/cac:Country/cbc:IdentificationCode')) !== 'SK') {
            return [[], ['applies' => false]];
        }
        $buyerCc = Xml::up($x->t($cus, 'cac:PostalAddress/cac:Country/cbc:IdentificationCode'));
        $isCn = $root->localName === 'CreditNote';
        $out = [];
        if (!str_contains($x->t($root, 'cbc:CustomizationID'), 'peppol.eu:2017:poacc:billing:3.0')) {
            $out[] = self::w('SK-FS-01', "Slovak eFaktúra is exchanged over Peppol as Peppol BIS Billing 3.0 (UBL 2.1); this file's CustomizationID (BT-24) is not the Peppol BIS 3 identifier.",
                ['BT-24'], 'Use CustomizationID urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0 and ProfileID urn:fdc:peppol.eu:2017:poacc:billing:01:1.0 (create with format=peppol-bis3).');
        }
        $vat = '';
        foreach ($x->findAll($sup, 'cac:PartyTaxScheme') as $pts) {
            if (Xml::up($x->t($pts, 'cac:TaxScheme/cbc:ID')) === 'VAT') {
                $vat = Xml::up(str_replace(' ', '', $x->t($pts, 'cbc:CompanyID')));
            }
        }
        $dic = preg_match('/^SK\d{10}$/D', $vat) ? substr($vat, 2) : '';
        $ep = $x->find($sup, 'cbc:EndpointID');
        [$epId, $epScheme] = $ep !== null ? [Document::pyStrip($ep->textContent), Document::pyStrip($ep->getAttribute('schemeID'))] : ['', ''];
        if ($epId === '') {
            $out[] = self::w('SK-FS-02', 'Seller electronic address (BT-34) is mandatory in the Slovak transposition (1..1); it also feeds the invoice UUID.',
                ['BT-34', 'BT-34-1'], "Add cbc:EndpointID schemeID=\"0245\" with the seller's 10-digit DIČ (the digits after 'SK' in the IČ DPH).");
        } elseif ($epScheme !== '0245' || !preg_match('/^\d{10}$/D', $epId)) {
            $out[] = self::w('SK-FS-03', 'Seller electronic address is ' . ($epScheme !== '' ? $epScheme : '?') . ":{$epId}; Slovak participants are registered as 0245:<10-digit DIČ>.",
                ['BT-34', 'BT-34-1'], 'Use schemeID 0245 and the 10-digit DIČ (not 9950 + IČ DPH).');
        } elseif ($dic !== '' && $epId !== $dic) {
            $out[] = self::w('SK-FS-04', "Seller endpoint 0245:{$epId} does not match the DIČ in the seller VAT number {$vat}.",
                ['BT-34', 'BT-31'], "The Slovak IČ DPH is 'SK' + DIČ; the EndpointID should normally be the same 10 digits.");
        }
        $bep = $x->find($cus, 'cbc:EndpointID');
        [$bId, $bScheme] = $bep !== null ? [Document::pyStrip($bep->textContent), Document::pyStrip($bep->getAttribute('schemeID'))] : ['', ''];
        if ($bId === '') {
            $out[] = self::w('SK-FS-05', 'Buyer electronic address (BT-49) is mandatory in the Slovak transposition (1..1).',
                ['BT-49', 'BT-49-1'], "Add the buyer's cbc:EndpointID; for a Slovak buyer schemeID=\"0245\" and the 10-digit DIČ. Check it on Peppol (GET /einvoice/route).");
        } elseif ($buyerCc === 'SK' && ($bScheme !== '0245' || !preg_match('/^\d{10}$/D', $bId))) {
            $out[] = self::w('SK-FS-06', 'Buyer electronic address is ' . ($bScheme !== '' ? $bScheme : '?') . ":{$bId}; Slovak buyers are addressed as 0245:<10-digit DIČ>.",
                ['BT-49', 'BT-49-1'], "Use schemeID 0245 and the buyer's 10-digit DIČ.");
        }
        $paths = ['cac:PostalAddress/cbc:StreetName', 'cac:PostalAddress/cbc:CityName', 'cac:PostalAddress/cbc:PostalZone'];
        foreach ([[$sup, 'Seller', ['BT-35', 'BT-37', 'BT-38']], [$cus, 'Buyer', ['BT-50', 'BT-52', 'BT-53']]] as [$party, $role, $bts]) {
            $missing = [];
            foreach ($bts as $i => $bt) {
                if ($x->t($party, $paths[$i]) === '') {
                    $missing[] = $bt;
                }
            }
            if ($missing) {
                $out[] = self::w($role === 'Seller' ? 'SK-FS-07' : 'SK-FS-08',
                    "{$role} address is incomplete (" . implode(', ', $missing) . ' missing). EN 16931 makes these optional, but the Slovak VAT Act (s. 74(1)(' . ($role === 'Seller' ? 'a' : 'b') . ') requires the address on the invoice.',
                    $missing, 'Fill ' . strtolower($role) . ' street, city and postal code.');
            }
        }
        if ($x->t($sup, 'cac:PartyLegalEntity/cbc:CompanyID') === '') {
            $out[] = self::w('SK-FS-09', 'Seller legal registration identifier (BT-30, the IČO) is conditionally required: give it if an IČO has been assigned.',
                ['BT-30', 'BT-30-1'], 'Add the seller IČO in cac:PartyLegalEntity/cbc:CompanyID (with schemeID from the ICD list).');
        }
        if ($x->t($sup, 'cac:PartyLegalEntity/cbc:CompanyLegalForm') === '') {
            $out[] = self::w('SK-FS-10', 'Seller additional legal information (BT-33) is conditionally required: legal form and the register entry (court, section, insert number) for registered businesses (Commercial Code s. 3a).',
                ['BT-33'], "Example: 'Spoločnosť s ručením obmedzeným, zapísaná v Obchodnom registri Okresného súdu Žilina, oddiel Sro, vložka č. 12345/L' in cbc:CompanyLegalForm.");
        }
        if ($buyerCc === 'SK' && $x->t($cus, 'cac:PartyLegalEntity/cbc:CompanyID') === '') {
            $out[] = self::w('SK-FS-11', 'Buyer legal registration identifier (BT-47, the IČO) is conditionally required if an IČO has been assigned.',
                ['BT-47', 'BT-47-1'], 'Add the buyer IČO in cac:PartyLegalEntity/cbc:CompanyID if it has one.');
        }
        $issue = $x->t($root, 'cbc:IssueDate');
        $taxPoint = $x->t($root, 'cbc:TaxPointDate');
        if ($x->find($root, 'cac:InvoicePeriod/cbc:DescriptionCode') !== null) {
            $out[] = self::w('SK-FS-12', 'The Slovak transposition does not use BT-8 (VAT point date code); the tax point date goes in BT-7.',
                ['BT-8', 'BT-7'], 'Remove cac:InvoicePeriod/cbc:DescriptionCode and use cbc:TaxPointDate.');
        }
        $delivery = $x->t($root, 'cac:Delivery/cbc:ActualDeliveryDate');
        if ($taxPoint === '' && $delivery !== '' && $delivery !== $issue) {
            $out[] = self::w('SK-FS-13', "Delivery date {$delivery} differs from the issue date {$issue} but BT-7 (tax point date, DUZP) is empty. In Slovakia BT-7 is required when it is known and differs from the issue date (VAT Act s. 74(1)(d)).",
                ['BT-7', 'BT-72'], 'Add cbc:TaxPointDate (create: tax_point_date).');
        }
        foreach ($x->findAll($root, 'cac:TaxTotal/cac:TaxSubtotal') as $st) {
            $cat = Xml::up($x->t($st, 'cac:TaxCategory/cbc:ID'));
            $p = $x->t($st, 'cac:TaxCategory/cbc:Percent');
            $rate = Xml::num($p !== '' ? $p : '0');
            if ($cat === 'S' && !in_array($rate, self::RATES, true) && $buyerCc === 'SK') {
                $out[] = self::w('SK-FS-14', "Standard-rated (S) VAT breakdown uses {$rate} %; Slovak rates are 23 %, 19 % and 5 %.",
                    ['BT-119', 'BT-118'], 'Check the VAT rate for this domestic supply.');
            }
            if (in_array($cat, self::EXEMPT_CATS, true)) {
                if ($x->t($st, 'cac:TaxCategory/cbc:TaxExemptionReasonCode') === '') {
                    $out[] = self::w('SK-FS-15', "VAT category {$cat} has no VATEX exemption reason code (BT-121). FR SR recommends using BT-121 primarily, with BT-120 text as the explanation (FAQ part III mapping).",
                        ['BT-121', 'BT-120'], 'E.g. VATEX-EU-IC for s. 43 intra-EU supply (K), VATEX-EU-G for exports (G), VATEX-EU-AE for reverse charge (AE), VATEX-EU-O for not subject (O), VATEX-EU-132-1x / VATEX-EU-135-1 for s. 28-41 exemptions (E).');
                }
                if ($x->t($st, 'cac:TaxCategory/cbc:TaxExemptionReason') === '') {
                    $out[] = self::w('SK-FS-16', "VAT category {$cat} has no exemption reason text (BT-120). It is conditionally required where the VAT Act asks for a specific wording (s. 74(1)(h), (k)-(n), e.g. 'prenesenie daňovej povinnosti' for reverse charge).",
                        ['BT-120'], 'Add cbc:TaxExemptionReason with the legal wording / reference.');
                }
            }
        }
        $typeCode = $x->t($root, $isCn ? 'cbc:CreditNoteTypeCode' : 'cbc:InvoiceTypeCode');
        if ($typeCode === '384') {
            $out[] = self::w('SK-FS-17', 'Type code 384 (corrected invoice): FR SR currently recommends a credit note plus a new invoice for corrections; 384 is planned for a later Peppol BIS release.',
                ['BT-3'], 'Issue a credit note (381) and a new invoice (380) instead.');
        }
        $block = ['applies' => true, 'transposition' => self::TRANSPOSITION, 'source' => self::SOURCE,
            'checks' => 'W System field checks from the FR SR transposition; not the official Slovak/Peppol schematron.',
            'seller_dic' => $dic !== '' ? $dic : null, 'participant_id' => $dic !== '' ? "0245:{$dic}" : null];
        $number = $x->t($root, 'cbc:ID');
        if ($epId !== '' && $epScheme !== '' && $typeCode !== '' && $number !== '' && $issue !== '') {
            $block['invoice_uuid'] = self::invoiceUuid($epScheme, $epId, $typeCode, $number, $issue);
            $block['invoice_uuid_input'] = implode(' ', [$epScheme, $epId, $typeCode, $number, $issue]);
            $block['invoice_uuid_note'] = "UUIDv5 over 'BT-34 schemeID, BT-34, BT-3, BT-1, BT-2' joined by single spaces, namespace 3fd00464-9f15-52ce-8cdc-6dd374d5ad42, per the BT-34 note in the "
                . 'transposition. The access points (C2/C3) compute the official value; the published example in the document is inconsistent, so treat this as a cross-check.';
        }
        return [$out, $block];
    }
}
