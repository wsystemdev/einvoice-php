<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Validator\National;

use WSystem\EInvoice\Validator\Document;

/**
 * Norwegian national field checks (port of vatcheck einvoice_no). W System's own checks from Norwegian law and the
 * Brønnøysund number rules, NOT the official EHF / Peppol BIS (NO-R) schematron. Warnings only (ruleset NO-LAW).
 */
final class Norway
{
    public const VERSION = 'W System NO field checks 2026-10-21';
    public const SOURCE = 'https://wsystem.dev/norway-e-invoicing-2027-software';
    private const RATES = ['25', '15', '12'];
    private const WEIGHTS = [3, 2, 7, 6, 5, 4, 3, 2];

    public static function orgnrOk(string $n): bool
    {
        if (!preg_match('/^\d{9}$/D', $n)) {
            return false;
        }
        $s = 0;
        foreach (self::WEIGHTS as $i => $w) {
            $s += (int) $n[$i] * $w;
        }
        $r = 11 - $s % 11;
        $r = $r === 11 ? 0 : $r;
        return $r !== 10 && $r === (int) $n[8];
    }

    private static function w(string $rid, string $msg, array $bts, string $fix): array
    {
        return Xml::w('NO-LAW', $rid, $msg, $bts, $fix);
    }

    /** @return array{0: string, 1: list<string>} */
    private static function taxIds(Xml $x, ?\DOMElement $party): array
    {
        $vat = '';
        $other = [];
        foreach ($x->findAll($party, 'cac:PartyTaxScheme') as $pts) {
            $cid = $x->t($pts, 'cbc:CompanyID');
            if (Xml::up($x->t($pts, 'cac:TaxScheme/cbc:ID')) === 'VAT') {
                $vat = Xml::up(str_replace(' ', '', $cid));
            } else {
                $other[] = $cid;
            }
        }
        return [$vat, $other];
    }

    /** @return array{0: list<array<string, mixed>>, 1: array<string, mixed>} */
    public static function check(Document $doc): array
    {
        $x = new Xml(Document::xpath($doc->dom));
        $root = $doc->dom->documentElement;
        if ($doc->syntax !== 'ubl') {
            $cc = Xml::up($x->t($root, './/ram:SellerTradeParty/ram:PostalTradeAddress/ram:CountryID'));
            if ($cc === 'NO') {
                return [[], ['applies' => false, 'note' => 'Norwegian field checks run on UBL only (Norwegian e-invoices over Peppol use EHF / Peppol BIS Billing 3.0 in UBL 2.1); this CII file was checked against EN 16931 only.']];
            }
            return [[], ['applies' => false]];
        }
        $sup = $x->find($root, 'cac:AccountingSupplierParty/cac:Party');
        $cus = $x->find($root, 'cac:AccountingCustomerParty/cac:Party');
        if (Xml::up($x->t($sup, 'cac:PostalAddress/cac:Country/cbc:IdentificationCode')) !== 'NO') {
            return [[], ['applies' => false]];
        }
        $buyerCc = Xml::up($x->t($cus, 'cac:PostalAddress/cac:Country/cbc:IdentificationCode'));
        $out = [];
        if (!str_contains($x->t($root, 'cbc:CustomizationID'), 'peppol.eu:2017:poacc:billing:3.0')) {
            $out[] = self::w('NO-LAW-01', "Norwegian e-invoices are exchanged over Peppol as EHF / Peppol BIS Billing 3.0 (UBL 2.1); this file's CustomizationID (BT-24) is not the Peppol BIS 3 identifier.",
                ['BT-24'], 'Use CustomizationID urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0 (create with format=peppol-bis3).');
        }
        [$sVat, $sOther] = self::taxIds($x, $sup);
        $sLegal = str_replace(' ', '', $x->t($sup, 'cac:PartyLegalEntity/cbc:CompanyID'));
        $sOrg = $sLegal !== '' ? $sLegal : (preg_match('/^NO\d{9}(MVA)?$/D', $sVat) ? substr($sVat, 2, 9) : '');
        if ($sLegal === '') {
            $out[] = self::w('NO-LAW-02', "Seller legal registration identifier (BT-30) is empty. A Norwegian sales document states the seller's organisation number.",
                ['BT-30', 'BT-30-1'], 'Add the 9-digit organisation number in cac:PartyLegalEntity/cbc:CompanyID schemeID="0192" (create: seller.company_id).');
        }
        if ($sOrg !== '' && !self::orgnrOk($sOrg)) {
            $out[] = self::w('NO-LAW-03', "Seller organisation number {$sOrg} fails the Brønnøysund modulus-11 check digit, so it can't be a real organisation number.",
                ['BT-30', 'BT-31'], 'Check the number at https://data.brreg.no/enhetsregisteret/oppslag/enheter or GET /company.');
        }
        if ($sVat !== '' && str_starts_with($sVat, 'NO') && !preg_match('/^NO\d{9}MVA$/D', $sVat)) {
            $out[] = self::w('NO-LAW-04', "Seller VAT identifier {$sVat}: a seller registered in the Norwegian VAT register shows the organisation number followed by 'MVA' (format NO + 9 digits + MVA).",
                ['BT-31'], 'Use e.g. NO923609016MVA in the VAT PartyTaxScheme.');
        }
        $legalForm = $x->t($sup, 'cac:PartyLegalEntity/cbc:CompanyLegalForm');
        $name = $x->t($sup, 'cac:PartyLegalEntity/cbc:RegistrationName');
        if ($name === '') {
            $name = $x->t($sup, 'cac:PartyName/cbc:Name');
        }
        $looksAs = preg_match('/\b(ASA|AS|NUF)\s*$/u', $name) || preg_match('/\b(ASA|AS|NUF)\b/u', $legalForm);
        $hasFr = false;
        foreach ([...$sOther, $legalForm] as $s) {
            $hasFr = $hasFr || str_contains(mb_strtolower($s, 'UTF-8'), 'foretaksregisteret');
        }
        if ($looksAs && !$hasFr) {
            $out[] = self::w('NO-LAW-05', "Seller '{$name}' looks like an AS, ASA or NUF, but the word 'Foretaksregisteret' is not on the invoice (foretaksregisterloven § 10-2).",
                ['BT-32', 'BT-33'], "Add a PartyTaxScheme with CompanyID 'Foretaksregisteret' and TaxScheme ID 'TAX' (create does this when seller.legal_form is AS/ASA/NUF).");
        }
        $ep = $x->find($sup, 'cbc:EndpointID');
        if ($ep !== null && Document::pyStrip($ep->getAttribute('schemeID')) === '0192') {
            $eid = Document::pyStrip($ep->textContent);
            if (!self::orgnrOk($eid)) {
                $out[] = self::w('NO-LAW-06', "Seller electronic address 0192:{$eid} is not a valid 9-digit organisation number; Norwegian Peppol participants are 0192:<org. no.>.",
                    ['BT-34', 'BT-34-1'], 'Use the organisation number only (no NO prefix, no MVA suffix).');
            }
        }
        if ($buyerCc === 'NO') {
            $bLegal = str_replace(' ', '', $x->t($cus, 'cac:PartyLegalEntity/cbc:CompanyID'));
            if ($bLegal === '') {
                $out[] = self::w('NO-LAW-07', "Buyer organisation number (BT-47) is empty. From 2028 Norwegian sales documents to business buyers must state the buyer's organisation number, and it is how the buyer is found in Peppol (0192).",
                    ['BT-47', 'BT-47-1'], "Add the buyer's organisation number in cac:PartyLegalEntity/cbc:CompanyID schemeID=\"0192\" (create: buyer.company_id).");
            } elseif (!self::orgnrOk($bLegal)) {
                $out[] = self::w('NO-LAW-08', "Buyer organisation number {$bLegal} fails the modulus-11 check digit.", ['BT-47'], "Check the buyer's number in the Brønnøysund register.");
            }
            $bep = $x->find($cus, 'cbc:EndpointID');
            if ($bep !== null && Document::pyStrip($bep->getAttribute('schemeID')) === '0192' && !self::orgnrOk(Document::pyStrip($bep->textContent))) {
                $out[] = self::w('NO-LAW-09', 'Buyer electronic address 0192:' . Document::pyStrip($bep->textContent) . ' is not a valid organisation number.',
                    ['BT-49', 'BT-49-1'], 'Look the buyer up with GET /einvoice/route before sending.');
            }
            foreach ($x->findAll($root, 'cac:TaxTotal/cac:TaxSubtotal') as $st) {
                $cat = Xml::up($x->t($st, 'cac:TaxCategory/cbc:ID'));
                $p = $x->t($st, 'cac:TaxCategory/cbc:Percent');
                $rate = Xml::num($p !== '' ? $p : '0');
                if ($cat === 'S' && !in_array($rate, self::RATES, true)) {
                    $out[] = self::w('NO-LAW-10', "Standard-rated (S) VAT breakdown uses {$rate} %; Norwegian rates are 25 %, 15 % (food) and 12 %.",
                        ['BT-119', 'BT-118'], 'Check the VAT rate for this domestic supply.');
                }
            }
        }
        $ok = $sOrg !== '' ? self::orgnrOk($sOrg) : null;
        return [$out, ['applies' => true, 'version' => self::VERSION, 'source' => self::SOURCE,
            'checks' => 'W System field checks from Norwegian law and the Brønnøysund number rules; not the official EHF / Peppol BIS (NO-R) schematron.',
            'seller_org_number' => $sOrg !== '' ? $sOrg : null, 'seller_org_number_valid' => $ok,
            'participant_id' => $sOrg !== '' && $ok ? "0192:{$sOrg}" : null]];
    }
}
