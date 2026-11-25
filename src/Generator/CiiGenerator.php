<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Generator;

use WSystem\EInvoice\InputError;
use WSystem\EInvoice\Model\Codes;
use WSystem\EInvoice\Model\Decimal;
use WSystem\EInvoice\Model\MoneyModel;
use WSystem\EInvoice\Support\Py;

/** UN/CEFACT CII D16B builder (formats cii, xrechnung-cii and the XML of zugferd / factur-x / zugferd-xrechnung). Port of vatcheck/einvoice_cii. */
final class CiiGenerator
{
    public const CII_NS = [
        'rsm' => 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100',
        'ram' => 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100',
        'qdt' => 'urn:un:unece:uncefact:data:standard:QualifiedDataType:100',
        'udt' => 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100',
    ];
    /** format => [guideline id, PDF container?, embedded file name, Factur-X XMP ConformanceLevel] */
    public const FORMATS = [
        'cii' => [Codes::EN16931_ID, false, null, null],
        'xrechnung-cii' => [Codes::XRECHNUNG_ID, false, null, null],
        'zugferd' => [Codes::EN16931_ID, true, 'factur-x.xml', 'EN 16931'],
        'factur-x' => [Codes::EN16931_ID, true, 'factur-x.xml', 'EN 16931'],
        'zugferd-xrechnung' => [Codes::XRECHNUNG_ID, true, 'xrechnung.xml', 'XRECHNUNG'],
    ];
    public const ALIASES = ['facturx' => 'factur-x', 'zugferd-en16931' => 'zugferd', 'factur-x-en16931' => 'factur-x', 'cii-xrechnung' => 'xrechnung-cii',
        'xrechnung_cii' => 'xrechnung-cii', 'en16931-cii' => 'cii', 'zugferd-comfort' => 'zugferd'];

    public static function normFormat(mixed $fmt): string
    {
        $f = Py::lower(trim(Py::str(Py::t($fmt) ? $fmt : '')));
        return self::ALIASES[$f] ?? $f;
    }

    private static function date102(mixed $d, string $field): string
    {
        if (!Codes::isDate($d)) {
            throw new InputError("$field must be YYYY-MM-DD");
        }
        return str_replace('-', '', Py::str($d));
    }

    private static function ac(array $ac, bool $doc): string
    {
        $s = '<ram:SpecifiedTradeAllowanceCharge><ram:ChargeIndicator><udt:Indicator>' . ($ac['charge'] ? 'true' : 'false') . '</udt:Indicator></ram:ChargeIndicator>';
        if ($ac['percent'] !== null) {
            $s .= '<ram:CalculationPercent>' . $ac['percent']->num() . '</ram:CalculationPercent>';
        }
        if ($ac['base'] !== null) {
            $s .= '<ram:BasisAmount>' . $ac['base']->a() . '</ram:BasisAmount>';
        }
        $s .= '<ram:ActualAmount>' . $ac['amount']->a() . '</ram:ActualAmount>';
        if (Py::t($ac['reason_code'])) {
            $s .= '<ram:ReasonCode>' . Py::x($ac['reason_code']) . '</ram:ReasonCode>';
        }
        if (Py::t($ac['reason'])) {
            $s .= '<ram:Reason>' . Py::x($ac['reason']) . '</ram:Reason>';
        }
        if ($doc) {
            $s .= '<ram:CategoryTradeTax><ram:TypeCode>VAT</ram:TypeCode><ram:CategoryCode>' . Py::x($ac['cat']) . '</ram:CategoryCode>';
            if ($ac['cat'] !== 'O') {
                $s .= '<ram:RateApplicablePercent>' . $ac['rate']->num() . '</ram:RateApplicablePercent>';
            }
            $s .= '</ram:CategoryTradeTax>';
        }
        return $s . '</ram:SpecifiedTradeAllowanceCharge>';
    }

    private static function party(array $p, string $role, bool $xr): string
    {
        foreach (['name', 'country'] as $f) {
            if (!Py::t($p[$f] ?? null)) {
                throw new InputError("$role.$f is required");
            }
        }
        $cc = Py::upper($p['country']);
        $tag = $role === 'seller' ? 'SellerTradeParty' : 'BuyerTradeParty';
        $s = "<ram:$tag>";
        $s .= '<ram:Name>' . Py::x($p['name']) . '</ram:Name>';
        if (Py::t($p['legal_form'] ?? null) && $role === 'seller') {
            $s .= '<ram:Description>' . Py::x($p['legal_form']) . '</ram:Description>';
        }
        if (Py::t($p['company_id'] ?? null) || Py::t($p['trading_name'] ?? null)) {
            $s .= '<ram:SpecifiedLegalOrganization>';
            if (Py::t($p['company_id'] ?? null)) {
                $sch = Py::t($p['company_id_scheme'] ?? null) ? ' schemeID="' . Py::x($p['company_id_scheme']) . '"' : '';
                $s .= "<ram:ID$sch>" . Py::x($p['company_id']) . '</ram:ID>';
            }
            if (Py::t($p['trading_name'] ?? null)) {
                $s .= '<ram:TradingBusinessName>' . Py::x($p['trading_name']) . '</ram:TradingBusinessName>';
            }
            $s .= '</ram:SpecifiedLegalOrganization>';
        }
        if (Py::t($p['contact_name'] ?? null) || Py::t($p['phone'] ?? null) || Py::t($p['email'] ?? null)) {
            $s .= '<ram:DefinedTradeContact>';
            if (Py::t($p['contact_name'] ?? null)) {
                $s .= '<ram:PersonName>' . Py::x($p['contact_name']) . '</ram:PersonName>';
            }
            if (Py::t($p['phone'] ?? null)) {
                $s .= '<ram:TelephoneUniversalCommunication><ram:CompleteNumber>' . Py::x($p['phone']) . '</ram:CompleteNumber></ram:TelephoneUniversalCommunication>';
            }
            if (Py::t($p['email'] ?? null)) {
                $s .= '<ram:EmailURIUniversalCommunication><ram:URIID>' . Py::x($p['email']) . '</ram:URIID></ram:EmailURIUniversalCommunication>';
            }
            $s .= '</ram:DefinedTradeContact>';
        }
        $s .= '<ram:PostalTradeAddress>';
        if (Py::t($p['postal_code'] ?? null)) {
            $s .= '<ram:PostcodeCode>' . Py::x($p['postal_code']) . '</ram:PostcodeCode>';
        }
        if (Py::t($p['street'] ?? null)) {
            $s .= '<ram:LineOne>' . Py::x($p['street']) . '</ram:LineOne>';
        }
        if (Py::t($p['city'] ?? null)) {
            $s .= '<ram:CityName>' . Py::x($p['city']) . '</ram:CityName>';
        }
        $s .= '<ram:CountryID>' . Py::x($cc) . '</ram:CountryID></ram:PostalTradeAddress>';
        [$eid, $esch] = Codes::endpoint($p, $xr ? 'xrechnung' : 'en16931', $role);
        if (Py::t($eid)) {
            $s .= '<ram:URIUniversalCommunication><ram:URIID schemeID="' . Py::x($esch) . '">' . Py::x($eid) . '</ram:URIID></ram:URIUniversalCommunication>';
        }
        if (Py::t($p['vat_id'] ?? null)) {
            $s .= '<ram:SpecifiedTaxRegistration><ram:ID schemeID="VA">' . Py::x(Py::upper(str_replace(' ', '', Py::str($p['vat_id'])))) . '</ram:ID></ram:SpecifiedTaxRegistration>';
        }
        if (Py::t($p['tax_number'] ?? null)) {
            $s .= '<ram:SpecifiedTaxRegistration><ram:ID schemeID="FC">' . Py::x($p['tax_number']) . '</ram:ID></ram:SpecifiedTaxRegistration>';
        }
        return $s . "</ram:$tag>";
    }

    /** @return array{0: string, 1: array, 2: array} [xml, totals, model]; $fmt is a key of FORMATS */
    public static function build(array $j, string $fmt): array
    {
        if (!isset(self::FORMATS[$fmt])) {
            throw new InputError('format must be one of ' . implode(', ', array_keys(self::FORMATS)));
        }
        $gid = self::FORMATS[$fmt][0];
        $xr = $gid === Codes::XRECHNUNG_ID;
        $m = MoneyModel::compute($j);
        $cur = $m['cur'];
        $isCn = $m['is_cn'];
        $tc = Py::str(Py::t($j['type_code'] ?? null) ? $j['type_code'] : ($isCn ? '381' : '380'));
        $ns = [];
        foreach (self::CII_NS as $k => $v) {
            $ns[] = "xmlns:$k=\"$v\"";
        }
        $x = ["<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<rsm:CrossIndustryInvoice " . implode(' ', $ns) . '>', '<rsm:ExchangedDocumentContext>'];
        if ($xr) {
            $x[] = '<ram:BusinessProcessSpecifiedDocumentContextParameter><ram:ID>' . Codes::PEPPOL_PROFILE . '</ram:ID></ram:BusinessProcessSpecifiedDocumentContextParameter>';
        }
        $x[] = "<ram:GuidelineSpecifiedDocumentContextParameter><ram:ID>$gid</ram:ID></ram:GuidelineSpecifiedDocumentContextParameter></rsm:ExchangedDocumentContext>";
        $x[] = '<rsm:ExchangedDocument><ram:ID>' . Py::x($j['invoice_number']) . '</ram:ID><ram:TypeCode>' . Py::x($tc) . '</ram:TypeCode>'
            . '<ram:IssueDateTime><udt:DateTimeString format="102">' . self::date102($j['issue_date'], 'issue_date') . '</udt:DateTimeString></ram:IssueDateTime>';
        if (Py::t($j['note'] ?? null)) {
            $x[] = '<ram:IncludedNote><ram:Content>' . Py::x($j['note']) . '</ram:Content></ram:IncludedNote>';
        }
        $x[] = '</rsm:ExchangedDocument><rsm:SupplyChainTradeTransaction>';
        foreach ($m['lines'] as $ln) {
            $s = "<ram:IncludedSupplyChainTradeLineItem><ram:AssociatedDocumentLineDocument><ram:LineID>{$ln['id']}</ram:LineID>"
                . '</ram:AssociatedDocumentLineDocument><ram:SpecifiedTradeProduct>';
            if (Py::t($ln['sid'])) {
                $s .= '<ram:SellerAssignedID>' . Py::x($ln['sid']) . '</ram:SellerAssignedID>';
            }
            $s .= '<ram:Name>' . Py::x($ln['name']) . '</ram:Name>';
            if (Py::t($ln['desc'])) {
                $s .= '<ram:Description>' . Py::x($ln['desc']) . '</ram:Description>';
            }
            $s .= '</ram:SpecifiedTradeProduct><ram:SpecifiedLineTradeAgreement><ram:NetPriceProductTradePrice>'
                . '<ram:ChargeAmount>' . $ln['price']->num() . '</ram:ChargeAmount></ram:NetPriceProductTradePrice></ram:SpecifiedLineTradeAgreement>'
                . '<ram:SpecifiedLineTradeDelivery><ram:BilledQuantity unitCode="' . Py::x($ln['unit']) . '">' . $ln['q']->num() . '</ram:BilledQuantity>'
                . '</ram:SpecifiedLineTradeDelivery><ram:SpecifiedLineTradeSettlement><ram:ApplicableTradeTax><ram:TypeCode>VAT</ram:TypeCode>'
                . '<ram:CategoryCode>' . Py::x($ln['cat']) . '</ram:CategoryCode>';
            if ($ln['cat'] !== 'O') {
                $s .= '<ram:RateApplicablePercent>' . $ln['rate']->num() . '</ram:RateApplicablePercent>';
            }
            $s .= '</ram:ApplicableTradeTax>' . implode('', array_map(fn ($a) => self::ac($a, false), $ln['acs']));
            $s .= '<ram:SpecifiedTradeSettlementLineMonetarySummation><ram:LineTotalAmount>' . $ln['net']->a() . '</ram:LineTotalAmount>'
                . '</ram:SpecifiedTradeSettlementLineMonetarySummation></ram:SpecifiedLineTradeSettlement></ram:IncludedSupplyChainTradeLineItem>';
            $x[] = $s;
        }
        $x[] = '<ram:ApplicableHeaderTradeAgreement>';
        if (Py::t($j['buyer_reference'] ?? null)) {
            $x[] = '<ram:BuyerReference>' . Py::x($j['buyer_reference']) . '</ram:BuyerReference>';
        }
        $x[] = self::party($j['seller'], 'seller', $xr) . self::party($j['buyer'], 'buyer', $xr);
        if (Py::t($j['order_reference'] ?? null)) {
            $x[] = '<ram:BuyerOrderReferencedDocument><ram:IssuerAssignedID>' . Py::x($j['order_reference']) . '</ram:IssuerAssignedID></ram:BuyerOrderReferencedDocument>';
        }
        $x[] = '</ram:ApplicableHeaderTradeAgreement><ram:ApplicableHeaderTradeDelivery>';
        if (Py::t($j['delivery_country'] ?? null)) {
            $x[] = '<ram:ShipToTradeParty><ram:PostalTradeAddress><ram:CountryID>' . Py::x(Py::upper($j['delivery_country'])) . '</ram:CountryID>'
                . '</ram:PostalTradeAddress></ram:ShipToTradeParty>';
        }
        if (Py::t($j['delivery_date'] ?? null)) {
            $x[] = '<ram:ActualDeliverySupplyChainEvent><ram:OccurrenceDateTime><udt:DateTimeString format="102">'
                . self::date102($j['delivery_date'], 'delivery_date') . '</udt:DateTimeString></ram:OccurrenceDateTime></ram:ActualDeliverySupplyChainEvent>';
        }
        $x[] = '</ram:ApplicableHeaderTradeDelivery><ram:ApplicableHeaderTradeSettlement>';
        $pay = Py::t($j['payment'] ?? null) ? $j['payment'] : [];
        if (!Py::isDict($pay)) {
            throw new InputError('payment must be an object');
        }
        if (Py::t($pay['reference'] ?? null)) {
            $x[] = '<ram:PaymentReference>' . Py::x($pay['reference']) . '</ram:PaymentReference>';
        }
        $x[] = "<ram:InvoiceCurrencyCode>$cur</ram:InvoiceCurrencyCode>";
        if (Py::t($pay['iban'] ?? null) || Py::t($pay['means_code'] ?? null)) {
            $code = array_key_exists('means_code', $pay) ? $pay['means_code'] : (Py::t($pay['iban'] ?? null) ? '58' : '1');
            $pm = '<ram:SpecifiedTradeSettlementPaymentMeans><ram:TypeCode>' . Py::x($code) . '</ram:TypeCode>';
            if (Py::t($pay['iban'] ?? null)) {
                $pm .= '<ram:PayeePartyCreditorFinancialAccount><ram:IBANID>' . Py::x(str_replace(' ', '', Py::str($pay['iban']))) . '</ram:IBANID>';
                if (Py::t($pay['account_name'] ?? null)) {
                    $pm .= '<ram:AccountName>' . Py::x($pay['account_name']) . '</ram:AccountName>';
                }
                $pm .= '</ram:PayeePartyCreditorFinancialAccount>';
                if (Py::t($pay['bic'] ?? null)) {
                    $pm .= '<ram:PayeeSpecifiedCreditorFinancialInstitution><ram:BICID>' . Py::x($pay['bic']) . '</ram:BICID></ram:PayeeSpecifiedCreditorFinancialInstitution>';
                }
            }
            $x[] = $pm . '</ram:SpecifiedTradeSettlementPaymentMeans>';
        }
        $tpd = Py::t($j['tax_point_date'] ?? null) ? self::date102($j['tax_point_date'], 'tax_point_date') : null;
        foreach ($m['vat'] as $gi => $g) {
            $s = '<ram:ApplicableTradeTax><ram:CalculatedAmount>' . $g['tax']->a() . '</ram:CalculatedAmount><ram:TypeCode>VAT</ram:TypeCode>';
            if (Py::t($g['ex_text'])) {
                $s .= '<ram:ExemptionReason>' . Py::x($g['ex_text']) . '</ram:ExemptionReason>';
            }
            $s .= '<ram:BasisAmount>' . $g['base']->a() . '</ram:BasisAmount><ram:CategoryCode>' . Py::x($g['cat']) . '</ram:CategoryCode>';
            if (Py::t($g['ex_code'])) {
                $s .= '<ram:ExemptionReasonCode>' . Py::x($g['ex_code']) . '</ram:ExemptionReasonCode>';
            }
            if ($tpd && $gi === 0) {
                $s .= '<ram:TaxPointDate><udt:DateString format="102">' . $tpd . '</udt:DateString></ram:TaxPointDate>';
            }
            if ($g['cat'] !== 'O') {
                $s .= '<ram:RateApplicablePercent>' . $g['rate']->num() . '</ram:RateApplicablePercent>';
            }
            $x[] = $s . '</ram:ApplicableTradeTax>';
        }
        foreach ($m['doc_acs'] as $a) {
            $x[] = self::ac($a, true);
        }
        $due = !$isCn ? ($j['due_date'] ?? null) : null;
        if (Py::t($pay['terms'] ?? null) || Py::t($due)) {
            $s = '<ram:SpecifiedTradePaymentTerms>';
            if (Py::t($pay['terms'] ?? null)) {
                $s .= '<ram:Description>' . Py::x($pay['terms']) . '</ram:Description>';
            }
            if (Py::t($due)) {
                $s .= '<ram:DueDateDateTime><udt:DateTimeString format="102">' . self::date102($due, 'due_date') . '</udt:DateTimeString></ram:DueDateDateTime>';
            }
            $x[] = $s . '</ram:SpecifiedTradePaymentTerms>';
        }
        $s = '<ram:SpecifiedTradeSettlementHeaderMonetarySummation><ram:LineTotalAmount>' . $m['line_total']->a() . '</ram:LineTotalAmount>';
        if ($m['doc_acs']) {
            $s .= '<ram:ChargeTotalAmount>' . $m['charge_total']->a() . '</ram:ChargeTotalAmount><ram:AllowanceTotalAmount>' . $m['allow_total']->a() . '</ram:AllowanceTotalAmount>';
        }
        $s .= '<ram:TaxBasisTotalAmount>' . $m['basis']->a() . '</ram:TaxBasisTotalAmount>'
            . "<ram:TaxTotalAmount currencyID=\"$cur\">" . $m['vat_total']->a() . '</ram:TaxTotalAmount>'
            . '<ram:GrandTotalAmount>' . $m['incl']->a() . '</ram:GrandTotalAmount>';
        if (!$m['prepaid']->isZero()) {
            $s .= '<ram:TotalPrepaidAmount>' . $m['prepaid']->a() . '</ram:TotalPrepaidAmount>';
        }
        $s .= '<ram:DuePayableAmount>' . $m['payable']->a() . '</ram:DuePayableAmount></ram:SpecifiedTradeSettlementHeaderMonetarySummation>';
        $x[] = $s;
        if (Py::t($j['preceding_invoice'] ?? null)) {
            $s = '<ram:InvoiceReferencedDocument><ram:IssuerAssignedID>' . Py::x($j['preceding_invoice']) . '</ram:IssuerAssignedID>';
            if (Py::t($j['preceding_invoice_date'] ?? null)) {
                $s .= '<ram:FormattedIssueDateTime><qdt:DateTimeString format="102">' . self::date102($j['preceding_invoice_date'], 'preceding_invoice_date')
                    . '</qdt:DateTimeString></ram:FormattedIssueDateTime>';
            }
            $x[] = $s . '</ram:InvoiceReferencedDocument>';
        }
        $x[] = '</ram:ApplicableHeaderTradeSettlement></rsm:SupplyChainTradeTransaction></rsm:CrossIndustryInvoice>';
        return [implode('', $x), MoneyModel::totals($m), $m];
    }
}
