<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Generator;

use WSystem\EInvoice\InputError;
use WSystem\EInvoice\Model\Codes;
use WSystem\EInvoice\Model\Decimal;
use WSystem\EInvoice\Model\MoneyModel;
use WSystem\EInvoice\Support\Py;

/** UBL 2.1 Invoice / CreditNote builder (formats en16931, xrechnung, peppol-bis3). Port of vatcheck/einvoice.build_ubl. */
final class UblGenerator
{
    public const FORMATS = ['en16931', 'xrechnung', 'peppol-bis3'];

    private static function taxcat(string $cat, Decimal $rate, string $tag = 'cac:ClassifiedTaxCategory'): string
    {
        $s = "<$tag><cbc:ID>" . Py::x($cat) . '</cbc:ID>';
        if ($cat !== 'O') {
            $s .= '<cbc:Percent>' . $rate->num() . '</cbc:Percent>';
        }
        return $s . "<cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme></$tag>";
    }

    private static function ac(array $a, string $cur, bool $doc): string
    {
        $s = '<cac:AllowanceCharge><cbc:ChargeIndicator>' . ($a['charge'] ? 'true' : 'false') . '</cbc:ChargeIndicator>';
        if (Py::t($a['reason_code'])) {
            $s .= '<cbc:AllowanceChargeReasonCode>' . Py::x($a['reason_code']) . '</cbc:AllowanceChargeReasonCode>';
        }
        if (Py::t($a['reason'])) {
            $s .= '<cbc:AllowanceChargeReason>' . Py::x($a['reason']) . '</cbc:AllowanceChargeReason>';
        }
        if ($a['percent'] !== null) {
            $s .= '<cbc:MultiplierFactorNumeric>' . $a['percent']->num() . '</cbc:MultiplierFactorNumeric>';
        }
        $s .= "<cbc:Amount currencyID=\"$cur\">" . $a['amount']->a() . '</cbc:Amount>';
        if ($a['base'] !== null) {
            $s .= "<cbc:BaseAmount currencyID=\"$cur\">" . $a['base']->a() . '</cbc:BaseAmount>';
        }
        if ($doc) {
            $s .= self::taxcat($a['cat'], $a['rate'], 'cac:TaxCategory');
        }
        return $s . '</cac:AllowanceCharge>';
    }

    private static function party(mixed $p, string $role, string $fmt): string
    {
        $p = is_array($p) ? $p : [];
        foreach (['name', 'country'] as $f) {
            if (!Py::t($p[$f] ?? null)) {
                throw new InputError("$role.$f is required");
            }
        }
        $cc = Py::upper($p['country']);
        [$eid, $esch] = Codes::endpoint($p, $fmt, $role);
        $parts = [];
        if (Py::t($eid)) {
            $parts[] = '<cbc:EndpointID schemeID="' . Py::x($esch) . '">' . Py::x($eid) . '</cbc:EndpointID>';
        }
        if (Py::t($p['trading_name'] ?? null)) {
            $parts[] = '<cac:PartyName><cbc:Name>' . Py::x($p['trading_name']) . '</cbc:Name></cac:PartyName>';
        }
        $addr = '<cac:PostalAddress>';
        if (Py::t($p['street'] ?? null)) {
            $addr .= '<cbc:StreetName>' . Py::x($p['street']) . '</cbc:StreetName>';
        }
        if (Py::t($p['city'] ?? null)) {
            $addr .= '<cbc:CityName>' . Py::x($p['city']) . '</cbc:CityName>';
        }
        if (Py::t($p['postal_code'] ?? null)) {
            $addr .= '<cbc:PostalZone>' . Py::x($p['postal_code']) . '</cbc:PostalZone>';
        }
        $addr .= '<cac:Country><cbc:IdentificationCode>' . Py::x($cc) . '</cbc:IdentificationCode></cac:Country></cac:PostalAddress>';
        $parts[] = $addr;
        $vat = Py::t($p['vat_id'] ?? null) ? Py::upper(str_replace(' ', '', Py::str($p['vat_id']))) : null;
        if ($vat !== null) {
            $parts[] = '<cac:PartyTaxScheme><cbc:CompanyID>' . Py::x($vat) . '</cbc:CompanyID><cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme></cac:PartyTaxScheme>';
        }
        if ($fmt === 'peppol-bis3' && $role === 'seller' && $cc === 'NO' && !Py::t($p['tax_number'] ?? null)
            && in_array(Py::upper(Py::t($p['legal_form'] ?? null) ? $p['legal_form'] : ''), ['AS', 'ASA', 'NUF'], true)) {
            // NO-R-002: AS / ASA / NUF sellers show "Foretaksregisteret"
            $parts[] = '<cac:PartyTaxScheme><cbc:CompanyID>Foretaksregisteret</cbc:CompanyID><cac:TaxScheme><cbc:ID>TAX</cbc:ID></cac:TaxScheme></cac:PartyTaxScheme>';
        }
        if (Py::t($p['tax_number'] ?? null)) {
            $parts[] = '<cac:PartyTaxScheme><cbc:CompanyID>' . Py::x($p['tax_number']) . '</cbc:CompanyID><cac:TaxScheme><cbc:ID>FC</cbc:ID></cac:TaxScheme></cac:PartyTaxScheme>';
        }
        $le = '<cac:PartyLegalEntity><cbc:RegistrationName>' . Py::x($p['name']) . '</cbc:RegistrationName>';
        if (!Py::t($p['company_id'] ?? null) && $fmt === 'peppol-bis3' && $cc === 'NO' && $vat !== null && preg_match('/^NO\d{9}(MVA)?$/D', $vat)) {
            $p['company_id'] = substr($vat, 2, 9);
            $p['company_id_scheme'] = '0192';
        }
        if (Py::t($p['company_id'] ?? null)) {
            $sch = Py::t($p['company_id_scheme'] ?? null) ? ' schemeID="' . Py::x($p['company_id_scheme']) . '"' : '';
            $le .= "<cbc:CompanyID$sch>" . Py::x($p['company_id']) . '</cbc:CompanyID>';
        }
        if (Py::t($p['legal_form'] ?? null) && $role === 'seller') {
            $le .= '<cbc:CompanyLegalForm>' . Py::x($p['legal_form']) . '</cbc:CompanyLegalForm>';
        }
        $parts[] = $le . '</cac:PartyLegalEntity>';
        if (Py::t($p['contact_name'] ?? null) || Py::t($p['phone'] ?? null) || Py::t($p['email'] ?? null)) {
            $c = '<cac:Contact>';
            if (Py::t($p['contact_name'] ?? null)) {
                $c .= '<cbc:Name>' . Py::x($p['contact_name']) . '</cbc:Name>';
            }
            if (Py::t($p['phone'] ?? null)) {
                $c .= '<cbc:Telephone>' . Py::x($p['phone']) . '</cbc:Telephone>';
            }
            if (Py::t($p['email'] ?? null)) {
                $c .= '<cbc:ElectronicMail>' . Py::x($p['email']) . '</cbc:ElectronicMail>';
            }
            $parts[] = $c . '</cac:Contact>';
        }
        return '<cac:Party>' . implode('', $parts) . '</cac:Party>';
    }

    /** @return array{0: string, 1: array} [xml, totals] */
    public static function build(array $j): array
    {
        $fmt = Py::lower(Py::t($j['format'] ?? null) ? $j['format'] : 'en16931');
        if (!in_array($fmt, self::FORMATS, true)) {
            throw new InputError('format must be en16931, xrechnung, peppol-bis3 (UBL), cii, xrechnung-cii (CII) or zugferd / factur-x / zugferd-xrechnung (PDF)');
        }
        $m = MoneyModel::compute($j, false);
        $cur = $m['cur'];
        $isCn = $m['is_cn'];
        $linesXml = [];
        $qtag = $isCn ? 'cbc:CreditedQuantity' : 'cbc:InvoicedQuantity';
        $ltag = $isCn ? 'cac:CreditNoteLine' : 'cac:InvoiceLine';
        foreach ($m['lines'] as $ln) {
            $lineAc = implode('', array_map(fn ($a) => self::ac($a, $cur, false), $ln['acs']));
            $item = '<cac:Item>';
            if (Py::t($ln['desc'])) {
                $item .= '<cbc:Description>' . Py::x($ln['desc']) . '</cbc:Description>';
            }
            $item .= '<cbc:Name>' . Py::x($ln['name']) . '</cbc:Name>';
            if (Py::t($ln['sid'])) {
                $item .= '<cac:SellersItemIdentification><cbc:ID>' . Py::x($ln['sid']) . '</cbc:ID></cac:SellersItemIdentification>';
            }
            $item .= self::taxcat($ln['cat'], $ln['rate']) . '</cac:Item>';
            $linesXml[] = "<$ltag><cbc:ID>{$ln['id']}</cbc:ID><$qtag unitCode=\"" . Py::x($ln['unit']) . '">' . $ln['q']->num() . "</$qtag>"
                . "<cbc:LineExtensionAmount currencyID=\"$cur\">" . $ln['net']->a() . "</cbc:LineExtensionAmount>$lineAc$item"
                . "<cac:Price><cbc:PriceAmount currencyID=\"$cur\">" . $ln['price']->num() . "</cbc:PriceAmount></cac:Price></$ltag>";
        }
        $subt = [];
        foreach ($m['vat'] as $g) {
            $s = "<cac:TaxSubtotal><cbc:TaxableAmount currencyID=\"$cur\">" . $g['base']->a() . "</cbc:TaxableAmount><cbc:TaxAmount currencyID=\"$cur\">"
                . $g['tax']->a() . '</cbc:TaxAmount><cac:TaxCategory><cbc:ID>' . $g['cat'] . '</cbc:ID>';
            if ($g['cat'] !== 'O') {
                $s .= '<cbc:Percent>' . $g['rate']->num() . '</cbc:Percent>';
            }
            if ($g['ex']) {
                if (Py::t($g['ex_code'])) {
                    $s .= '<cbc:TaxExemptionReasonCode>' . Py::x($g['ex_code']) . '</cbc:TaxExemptionReasonCode>';
                }
                $s .= '<cbc:TaxExemptionReason>' . Py::x($g['ex_text']) . '</cbc:TaxExemptionReason>';
            }
            $s .= '<cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme></cac:TaxCategory></cac:TaxSubtotal>';
            $subt[] = $s;
        }
        $cid = ['en16931' => Codes::EN16931_ID, 'xrechnung' => Codes::XRECHNUNG_ID, 'peppol-bis3' => Codes::PEPPOL_ID][$fmt];
        [$rootTag, $rootNs] = $isCn ? ['CreditNote', Codes::NS['ubl_cn']] : ['Invoice', Codes::NS['ubl_inv']];
        $x = ["<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<$rootTag xmlns=\"$rootNs\" xmlns:cac=\"" . Codes::NS['cac'] . '" xmlns:cbc="' . Codes::NS['cbc'] . '">',
            "<cbc:CustomizationID>$cid</cbc:CustomizationID>"];
        if ($fmt === 'peppol-bis3' || $fmt === 'xrechnung') {
            $x[] = '<cbc:ProfileID>' . Codes::PEPPOL_PROFILE . '</cbc:ProfileID>';
        }
        $x[] = '<cbc:ID>' . Py::x($j['invoice_number']) . '</cbc:ID><cbc:IssueDate>' . Py::x($j['issue_date']) . '</cbc:IssueDate>';
        if (Py::t($j['due_date'] ?? null) && !$isCn) {
            $x[] = '<cbc:DueDate>' . Py::x($j['due_date']) . '</cbc:DueDate>';
        }
        $tt = $isCn ? 'CreditNoteTypeCode' : 'InvoiceTypeCode';
        $x[] = "<cbc:$tt>" . Py::x(array_key_exists('type_code', $j) ? $j['type_code'] : ($isCn ? '381' : '380')) . "</cbc:$tt>";
        if (Py::t($j['note'] ?? null)) {
            $x[] = '<cbc:Note>' . Py::x($j['note']) . '</cbc:Note>';
        }
        if (Py::t($j['tax_point_date'] ?? null)) {
            if (!Codes::isDate($j['tax_point_date'])) {
                throw new InputError('tax_point_date must be YYYY-MM-DD');
            }
            $x[] = '<cbc:TaxPointDate>' . Py::x($j['tax_point_date']) . '</cbc:TaxPointDate>';
        }
        $x[] = "<cbc:DocumentCurrencyCode>$cur</cbc:DocumentCurrencyCode>";
        $bref = Py::v($j, 'buyer_reference');
        if ($bref === null && !(Py::t($j['order_reference'] ?? null) || $fmt === 'en16931') && $fmt === 'peppol-bis3') {
            $bref = $j['invoice_number'];
        }
        if (Py::t($bref)) {
            $x[] = '<cbc:BuyerReference>' . Py::x($bref) . '</cbc:BuyerReference>';
        }
        if (Py::t($j['order_reference'] ?? null)) {
            $x[] = '<cac:OrderReference><cbc:ID>' . Py::x($j['order_reference']) . '</cbc:ID></cac:OrderReference>';
        }
        if (Py::t($j['preceding_invoice'] ?? null)) {
            $x[] = '<cac:BillingReference><cac:InvoiceDocumentReference><cbc:ID>' . Py::x($j['preceding_invoice']) . '</cbc:ID></cac:InvoiceDocumentReference></cac:BillingReference>';
        }
        $x[] = '<cac:AccountingSupplierParty>' . self::party($j['seller'], 'seller', $fmt) . '</cac:AccountingSupplierParty>';
        $x[] = '<cac:AccountingCustomerParty>' . self::party($j['buyer'], 'buyer', $fmt) . '</cac:AccountingCustomerParty>';
        if (Py::t($j['delivery_date'] ?? null) || Py::t($j['delivery_country'] ?? null)) {
            $dl = Py::t($j['delivery_date'] ?? null) ? '<cbc:ActualDeliveryDate>' . Py::x($j['delivery_date']) . '</cbc:ActualDeliveryDate>' : '';
            if (Py::t($j['delivery_country'] ?? null)) {
                $dl .= '<cac:DeliveryLocation><cac:Address><cac:Country><cbc:IdentificationCode>' . Py::x(Py::upper($j['delivery_country']))
                    . '</cbc:IdentificationCode></cac:Country></cac:Address></cac:DeliveryLocation>';
            }
            $x[] = "<cac:Delivery>$dl</cac:Delivery>";
        }
        $pay = Py::t($j['payment'] ?? null) && is_array($j['payment']) ? $j['payment'] : [];
        if (Py::t($pay['iban'] ?? null) || Py::t($pay['means_code'] ?? null)) {
            $code = array_key_exists('means_code', $pay) ? $pay['means_code'] : (Py::t($pay['iban'] ?? null) ? '58' : '1');
            $pm = '<cac:PaymentMeans><cbc:PaymentMeansCode>' . Py::x($code) . '</cbc:PaymentMeansCode>';
            if (Py::t($pay['reference'] ?? null)) {
                $pm .= '<cbc:PaymentID>' . Py::x($pay['reference']) . '</cbc:PaymentID>';
            }
            if (Py::t($pay['iban'] ?? null)) {
                $pm .= '<cac:PayeeFinancialAccount><cbc:ID>' . Py::x(str_replace(' ', '', Py::str($pay['iban']))) . '</cbc:ID>';
                if (Py::t($pay['account_name'] ?? null)) {
                    $pm .= '<cbc:Name>' . Py::x($pay['account_name']) . '</cbc:Name>';
                }
                if (Py::t($pay['bic'] ?? null)) {
                    $pm .= '<cac:FinancialInstitutionBranch><cbc:ID>' . Py::x($pay['bic']) . '</cbc:ID></cac:FinancialInstitutionBranch>';
                }
                $pm .= '</cac:PayeeFinancialAccount>';
            }
            $x[] = $pm . '</cac:PaymentMeans>';
        }
        if (Py::t($pay['terms'] ?? null)) {
            $x[] = '<cac:PaymentTerms><cbc:Note>' . Py::x($pay['terms']) . '</cbc:Note></cac:PaymentTerms>';
        }
        foreach ($m['doc_acs'] as $a) {
            $x[] = self::ac($a, $cur, true);
        }
        $x[] = "<cac:TaxTotal><cbc:TaxAmount currencyID=\"$cur\">" . $m['vat_total']->a() . '</cbc:TaxAmount>' . implode('', $subt) . '</cac:TaxTotal>';
        $lmt = "<cac:LegalMonetaryTotal><cbc:LineExtensionAmount currencyID=\"$cur\">" . $m['line_total']->a() . '</cbc:LineExtensionAmount>'
            . "<cbc:TaxExclusiveAmount currencyID=\"$cur\">" . $m['basis']->a() . '</cbc:TaxExclusiveAmount>'
            . "<cbc:TaxInclusiveAmount currencyID=\"$cur\">" . $m['incl']->a() . '</cbc:TaxInclusiveAmount>';
        if ($m['doc_acs']) {
            $lmt .= "<cbc:AllowanceTotalAmount currencyID=\"$cur\">" . $m['allow_total']->a() . '</cbc:AllowanceTotalAmount>'
                . "<cbc:ChargeTotalAmount currencyID=\"$cur\">" . $m['charge_total']->a() . '</cbc:ChargeTotalAmount>';
        }
        if (!$m['prepaid']->isZero()) {
            $lmt .= "<cbc:PrepaidAmount currencyID=\"$cur\">" . $m['prepaid']->a() . '</cbc:PrepaidAmount>';
        }
        $x[] = $lmt . "<cbc:PayableAmount currencyID=\"$cur\">" . $m['payable']->a() . '</cbc:PayableAmount></cac:LegalMonetaryTotal>';
        array_push($x, ...$linesXml);
        $x[] = "</$rootTag>";
        return [implode('', $x), MoneyModel::totals($m), $m];
    }
}
