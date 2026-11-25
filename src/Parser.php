<?php

declare(strict_types=1);

namespace WSystem\EInvoice;

use WSystem\EInvoice\Validator\Document;

/**
 * Reads an incoming EU e-invoice (UBL Invoice/CreditNote, UN/CEFACT CII, or the XML inside a ZUGFeRD / Factur-X /
 * XRechnung PDF) into one flat array keyed like W System's POST /einvoice/parse (port of vatcheck einvoice_parse,
 * identical output on the parity corpus). It reads; it does not judge validity: use the Validator for the rules.
 */
final class Parser
{
    public const NOTE = 'Values are read as written in the XML (strings, no rounding or recomputation). Reading does not check validity: '
        . 'use POST /einvoice/validate for the EN 16931 / XRechnung rules. Not tax advice.';

    private \DOMXPath $xp;

    private function __construct(\DOMDocument $dom)
    {
        $this->xp = Document::xpath($dom);
    }

    /** @return array<string, mixed> */
    public static function parse(string $data): array
    {
        $d = Document::load($data);
        $p = new self($d->dom);
        $root = $d->dom->documentElement;
        $inv = $d->syntax === 'cii' ? $p->cii($root) : $p->ubl($root, $d->documentType === 'ubl_cn');
        $out = [
            'syntax' => $d->syntax,
            'document_type' => ['ubl_inv' => 'invoice', 'ubl_cn' => 'credit_note', 'cii' => 'invoice'][$d->documentType],
            'profile' => $d->profile,
            'customization_id' => $d->customizationId,
            'container' => $d->meta,
            'invoice' => self::clean($inv),
            'line_count' => count($inv['lines']),
            'note' => self::NOTE,
        ];
        if ($d->syntax === 'cii' && in_array($inv['type_code'] ?? null, ['381', '396', '532'], true)) {
            $out['document_type'] = 'credit_note';
        }
        return $out;
    }

    // ------------------------------------------------------------------ helpers (ElementTree semantics)
    private function find(?\DOMElement $el, string $path): ?\DOMElement
    {
        if ($el === null) {
            return null;
        }
        $n = $this->xp->query($path, $el)->item(0);
        return $n instanceof \DOMElement ? $n : null;
    }

    /** @return list<\DOMElement> */
    private function findAll(?\DOMElement $el, string $path): array
    {
        if ($el === null) {
            return [];
        }
        $out = [];
        foreach ($this->xp->query($path, $el) as $n) {
            if ($n instanceof \DOMElement) {
                $out[] = $n;
            }
        }
        return $out;
    }

    /** ElementTree .text: the character data before the first child node that isn't text */
    private static function text(\DOMElement $e): ?string
    {
        $s = null;
        foreach ($e->childNodes as $c) {
            if ($c instanceof \DOMText) { // includes CDATA
                $s = ($s ?? '') . $c->data;
            } else {
                break;
            }
        }
        return $s;
    }

    private function t(?\DOMElement $el, string $path): ?string
    {
        $x = $this->find($el, $path);
        if ($x === null) {
            return null;
        }
        $v = Document::pyStrip(self::text($x) ?? '');
        return $v === '' ? null : $v;
    }

    private function attr(?\DOMElement $el, string $path, string $attr): ?string
    {
        $x = $this->find($el, $path);
        return $x !== null && $x->hasAttribute($attr) ? $x->getAttribute($attr) : null;
    }

    private static function clean(mixed $d): mixed
    {
        if (is_array($d)) {
            $isList = array_is_list($d) && $d !== [];
            $out = [];
            foreach ($d as $k => $v) {
                $v = self::clean($v);
                if ($v === null || $v === '' || $v === []) {
                    continue;
                }
                $isList ? $out[] = $v : $out[$k] = $v;
            }
            return $out;
        }
        return $d;
    }

    // ------------------------------------------------------------------ UBL
    private function ublParty(?\DOMElement $p): ?array
    {
        if ($p === null) {
            return null;
        }
        $vat = null;
        $taxReg = null;
        foreach ($this->findAll($p, 'cac:PartyTaxScheme') as $pts) {
            if (strtoupper($this->t($pts, 'cac:TaxScheme/cbc:ID') ?? '') === 'VAT') {
                $vat = $this->t($pts, 'cbc:CompanyID');
            } elseif ($taxReg === null) {
                $taxReg = ['v' => $this->t($pts, 'cbc:CompanyID')];
            }
        }
        $a = $this->find($p, 'cac:PostalAddress');
        return [
            'name' => $this->t($p, 'cac:PartyLegalEntity/cbc:RegistrationName') ?? $this->t($p, 'cac:PartyName/cbc:Name'),
            'trading_name' => $this->t($p, 'cac:PartyName/cbc:Name'),
            'vat_id' => $vat, 'tax_registration_id' => $taxReg['v'] ?? null,
            'legal_registration_id' => $this->t($p, 'cac:PartyLegalEntity/cbc:CompanyID'),
            'legal_registration_scheme' => $this->attr($p, 'cac:PartyLegalEntity/cbc:CompanyID', 'schemeID'),
            'electronic_address' => $this->t($p, 'cbc:EndpointID'),
            'electronic_address_scheme' => $this->attr($p, 'cbc:EndpointID', 'schemeID'),
            'address' => ['street' => $this->t($a, 'cbc:StreetName'), 'additional' => $this->t($a, 'cbc:AdditionalStreetName'),
                'city' => $this->t($a, 'cbc:CityName'), 'postal_code' => $this->t($a, 'cbc:PostalZone'),
                'subdivision' => $this->t($a, 'cbc:CountrySubentity'), 'country' => $this->t($a, 'cac:Country/cbc:IdentificationCode')],
            'contact' => ['name' => $this->t($p, 'cac:Contact/cbc:Name'), 'phone' => $this->t($p, 'cac:Contact/cbc:Telephone'),
                'email' => $this->t($p, 'cac:Contact/cbc:ElectronicMail')],
        ];
    }

    private function ubl(\DOMElement $root, bool $cn): array
    {
        $lt = $cn ? 'cac:CreditNoteLine' : 'cac:InvoiceLine';
        $qt = $cn ? 'cbc:CreditedQuantity' : 'cbc:InvoicedQuantity';
        $tot = $this->find($root, 'cac:LegalMonetaryTotal');
        $pay = [];
        foreach ($this->findAll($root, 'cac:PaymentMeans') as $pm) {
            $pay[] = ['means_code' => $this->t($pm, 'cbc:PaymentMeansCode'), 'remittance_information' => $this->t($pm, 'cbc:PaymentID'),
                'iban' => $this->t($pm, 'cac:PayeeFinancialAccount/cbc:ID'), 'account_name' => $this->t($pm, 'cac:PayeeFinancialAccount/cbc:Name'),
                'bic' => $this->t($pm, 'cac:PayeeFinancialAccount/cac:FinancialInstitutionBranch/cbc:ID'),
                'mandate_reference' => $this->t($pm, 'cac:PaymentMandate/cbc:ID'),
                'debited_account' => $this->t($pm, 'cac:PaymentMandate/cac:PayerFinancialAccount/cbc:ID')];
        }
        $vat = [];
        foreach ($this->findAll($root, 'cac:TaxTotal/cac:TaxSubtotal') as $st) {
            $vat[] = ['category' => $this->t($st, 'cac:TaxCategory/cbc:ID'), 'rate' => $this->t($st, 'cac:TaxCategory/cbc:Percent'),
                'taxable_amount' => $this->t($st, 'cbc:TaxableAmount'), 'tax_amount' => $this->t($st, 'cbc:TaxAmount'),
                'exemption_reason' => $this->t($st, 'cac:TaxCategory/cbc:TaxExemptionReason'),
                'exemption_reason_code' => $this->t($st, 'cac:TaxCategory/cbc:TaxExemptionReasonCode')];
        }
        $taxTotal = null;
        $cur = $this->t($root, 'cbc:DocumentCurrencyCode');
        foreach ($this->findAll($root, 'cac:TaxTotal') as $tt) {
            $c = $this->attr($tt, 'cbc:TaxAmount', 'currencyID');
            if ($c === null || $c === $cur) {
                $taxTotal ??= $this->t($tt, 'cbc:TaxAmount');
            }
        }
        $lines = [];
        foreach ($this->findAll($root, $lt) as $ln) {
            $lines[] = ['id' => $this->t($ln, 'cbc:ID'), 'name' => $this->t($ln, 'cac:Item/cbc:Name'), 'description' => $this->t($ln, 'cac:Item/cbc:Description'),
                'quantity' => $this->t($ln, $qt), 'unit' => $this->attr($ln, $qt, 'unitCode'),
                'unit_price' => $this->t($ln, 'cac:Price/cbc:PriceAmount'), 'net_amount' => $this->t($ln, 'cbc:LineExtensionAmount'),
                'vat_category' => $this->t($ln, 'cac:Item/cac:ClassifiedTaxCategory/cbc:ID'),
                'vat_rate' => $this->t($ln, 'cac:Item/cac:ClassifiedTaxCategory/cbc:Percent'),
                'seller_item_id' => $this->t($ln, 'cac:Item/cac:SellersItemIdentification/cbc:ID'),
                'standard_item_id' => $this->t($ln, 'cac:Item/cac:StandardItemIdentification/cbc:ID'),
                'note' => $this->t($ln, 'cbc:Note')];
        }
        $notes = [];
        foreach ($this->findAll($root, 'cbc:Note') as $n) {
            $s = self::text($n);
            if ($s !== null && Document::pyStrip($s) !== '') {
                $notes[] = Document::pyStrip($s);
            }
        }
        $att = [];
        foreach ($this->findAll($root, 'cac:AdditionalDocumentReference') as $d) {
            $att[] = ['id' => $this->t($d, 'cbc:ID'), 'description' => $this->t($d, 'cbc:DocumentDescription'),
                'filename' => $this->attr($d, 'cac:Attachment/cbc:EmbeddedDocumentBinaryObject', 'filename'),
                'mime_code' => $this->attr($d, 'cac:Attachment/cbc:EmbeddedDocumentBinaryObject', 'mimeCode'),
                'embedded' => $this->find($d, 'cac:Attachment/cbc:EmbeddedDocumentBinaryObject') !== null];
        }
        return [
            'invoice_number' => $this->t($root, 'cbc:ID'), 'issue_date' => $this->t($root, 'cbc:IssueDate'),
            'type_code' => $this->t($root, $cn ? 'cbc:CreditNoteTypeCode' : 'cbc:InvoiceTypeCode'),
            'currency' => $cur, 'due_date' => $this->t($root, 'cbc:DueDate') ?? $this->t($root, 'cac:PaymentMeans/cbc:PaymentDueDate'),
            'tax_point_date' => $this->t($root, 'cbc:TaxPointDate'), 'buyer_reference' => $this->t($root, 'cbc:BuyerReference'),
            'order_reference' => $this->t($root, 'cac:OrderReference/cbc:ID'), 'contract_reference' => $this->t($root, 'cac:ContractDocumentReference/cbc:ID'),
            'preceding_invoices' => array_map(fn ($b) => $this->t($b, 'cac:InvoiceDocumentReference/cbc:ID'), $this->findAll($root, 'cac:BillingReference')),
            'notes' => $notes,
            'payment_terms' => $this->t($root, 'cac:PaymentTerms/cbc:Note'),
            'seller' => $this->ublParty($this->find($root, 'cac:AccountingSupplierParty/cac:Party')),
            'buyer' => $this->ublParty($this->find($root, 'cac:AccountingCustomerParty/cac:Party')),
            'delivery' => ['date' => $this->t($root, 'cac:Delivery/cbc:ActualDeliveryDate'), 'party_name' => $this->t($root, 'cac:Delivery/cac:DeliveryParty/cac:PartyName/cbc:Name'),
                'country' => $this->t($root, 'cac:Delivery/cac:DeliveryLocation/cac:Address/cac:Country/cbc:IdentificationCode')],
            'totals' => ['line_total' => $this->t($tot, 'cbc:LineExtensionAmount'), 'allowance_total' => $this->t($tot, 'cbc:AllowanceTotalAmount'),
                'charge_total' => $this->t($tot, 'cbc:ChargeTotalAmount'), 'total_without_vat' => $this->t($tot, 'cbc:TaxExclusiveAmount'),
                'vat_total' => $taxTotal, 'total_with_vat' => $this->t($tot, 'cbc:TaxInclusiveAmount'),
                'prepaid' => $this->t($tot, 'cbc:PrepaidAmount'), 'rounding' => $this->t($tot, 'cbc:PayableRoundingAmount'),
                'amount_due' => $this->t($tot, 'cbc:PayableAmount')],
            'vat_breakdown' => $vat, 'payment' => $pay, 'lines' => $lines, 'attachments' => $att,
        ];
    }

    // ------------------------------------------------------------------ CII
    private function ciiParty(?\DOMElement $p): ?array
    {
        if ($p === null) {
            return null;
        }
        $vat = $taxReg = null;
        foreach ($this->findAll($p, 'ram:SpecifiedTaxRegistration') as $r) {
            $sid = $this->attr($r, 'ram:ID', 'schemeID');
            if ($sid === 'VA') {
                $vat = $this->t($r, 'ram:ID');
            } elseif ($sid === 'FC') {
                $taxReg = $this->t($r, 'ram:ID');
            }
        }
        $a = $this->find($p, 'ram:PostalTradeAddress');
        $ct = $this->find($p, 'ram:DefinedTradeContact');
        return [
            'name' => $this->t($p, 'ram:Name'), 'trading_name' => $this->t($p, 'ram:SpecifiedLegalOrganization/ram:TradingBusinessName'),
            'vat_id' => $vat, 'tax_registration_id' => $taxReg,
            'legal_registration_id' => $this->t($p, 'ram:SpecifiedLegalOrganization/ram:ID'),
            'legal_registration_scheme' => $this->attr($p, 'ram:SpecifiedLegalOrganization/ram:ID', 'schemeID'),
            'electronic_address' => $this->t($p, 'ram:URIUniversalCommunication/ram:URIID'),
            'electronic_address_scheme' => $this->attr($p, 'ram:URIUniversalCommunication/ram:URIID', 'schemeID'),
            'address' => ['street' => $this->t($a, 'ram:LineOne'), 'additional' => $this->t($a, 'ram:LineTwo'), 'city' => $this->t($a, 'ram:CityName'),
                'postal_code' => $this->t($a, 'ram:PostcodeCode'), 'subdivision' => $this->t($a, 'ram:CountrySubDivisionName'),
                'country' => $this->t($a, 'ram:CountryID')],
            'contact' => ['name' => $this->t($ct, 'ram:PersonName') ?? $this->t($ct, 'ram:DepartmentName'),
                'phone' => $this->t($ct, 'ram:TelephoneUniversalCommunication/ram:CompleteNumber'),
                'email' => $this->t($ct, 'ram:EmailURIUniversalCommunication/ram:URIID')],
        ];
    }

    private function ciiDateAny(?\DOMElement $el, string $path): ?string
    {
        $x = $this->find($el, $path);
        if ($x === null) {
            return null;
        }
        $s = null;
        foreach ($this->xp->query('.//*', $x) as $c) {
            $tx = self::text($c);
            if ($tx !== null && Document::pyStrip($tx) !== '') {
                $s = Document::pyStrip($tx);
                break;
            }
        }
        if ($s !== null && strlen($s) === 8 && ctype_digit($s)) {
            return substr($s, 0, 4) . '-' . substr($s, 4, 2) . '-' . substr($s, 6);
        }
        return $s;
    }

    private function cii(\DOMElement $root): array
    {
        $tx = $this->find($root, 'rsm:SupplyChainTradeTransaction');
        $ag = $this->find($tx, 'ram:ApplicableHeaderTradeAgreement');
        $de = $this->find($tx, 'ram:ApplicableHeaderTradeDelivery');
        $st = $this->find($tx, 'ram:ApplicableHeaderTradeSettlement');
        $sm = $this->find($st, 'ram:SpecifiedTradeSettlementHeaderMonetarySummation');
        $cur = $this->t($st, 'ram:InvoiceCurrencyCode');
        $vatTotal = null;
        foreach ($this->findAll($sm, 'ram:TaxTotalAmount') as $e) {
            $c = $e->hasAttribute('currencyID') ? $e->getAttribute('currencyID') : null;
            $tx2 = self::text($e);
            if (($c === null || $c === $cur) && $tx2 !== null && $tx2 !== '') {
                $vatTotal = ($vatTotal === null || $vatTotal === '') ? Document::pyStrip($tx2) : $vatTotal;
            }
        }
        $pay = [];
        foreach ($this->findAll($st, 'ram:SpecifiedTradeSettlementPaymentMeans') as $pm) {
            $pay[] = ['means_code' => $this->t($pm, 'ram:TypeCode'), 'remittance_information' => $this->t($st, 'ram:PaymentReference'),
                'iban' => $this->t($pm, 'ram:PayeePartyCreditorFinancialAccount/ram:IBANID') ?? $this->t($pm, 'ram:PayeePartyCreditorFinancialAccount/ram:ProprietaryID'),
                'account_name' => $this->t($pm, 'ram:PayeePartyCreditorFinancialAccount/ram:AccountName'),
                'bic' => $this->t($pm, 'ram:PayeeSpecifiedCreditorFinancialInstitution/ram:BICID'),
                'mandate_reference' => $this->t($st, 'ram:SpecifiedTradePaymentTerms/ram:DirectDebitMandateID'),
                'debited_account' => $this->t($pm, 'ram:PayerPartyDebtorFinancialAccount/ram:IBANID')];
        }
        $vat = [];
        foreach ($this->findAll($st, 'ram:ApplicableTradeTax') as $t) {
            $vat[] = ['category' => $this->t($t, 'ram:CategoryCode'), 'rate' => $this->t($t, 'ram:RateApplicablePercent'),
                'taxable_amount' => $this->t($t, 'ram:BasisAmount'), 'tax_amount' => $this->t($t, 'ram:CalculatedAmount'),
                'exemption_reason' => $this->t($t, 'ram:ExemptionReason'), 'exemption_reason_code' => $this->t($t, 'ram:ExemptionReasonCode')];
        }
        $lines = [];
        $q = 'ram:SpecifiedLineTradeDelivery/ram:BilledQuantity';
        foreach ($this->findAll($tx, 'ram:IncludedSupplyChainTradeLineItem') as $ln) {
            $lines[] = ['id' => $this->t($ln, 'ram:AssociatedDocumentLineDocument/ram:LineID'), 'name' => $this->t($ln, 'ram:SpecifiedTradeProduct/ram:Name'),
                'description' => $this->t($ln, 'ram:SpecifiedTradeProduct/ram:Description'), 'quantity' => $this->t($ln, $q), 'unit' => $this->attr($ln, $q, 'unitCode'),
                'unit_price' => $this->t($ln, 'ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:ChargeAmount'),
                'net_amount' => $this->t($ln, 'ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount'),
                'vat_category' => $this->t($ln, 'ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:CategoryCode'),
                'vat_rate' => $this->t($ln, 'ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:RateApplicablePercent'),
                'seller_item_id' => $this->t($ln, 'ram:SpecifiedTradeProduct/ram:SellerAssignedID'),
                'standard_item_id' => $this->t($ln, 'ram:SpecifiedTradeProduct/ram:GlobalID'),
                'note' => $this->t($ln, 'ram:AssociatedDocumentLineDocument/ram:IncludedNote/ram:Content')];
        }
        $doc = $this->find($root, 'rsm:ExchangedDocument');
        $att = [];
        foreach ($this->findAll($ag, 'ram:AdditionalReferencedDocument') as $d) {
            $att[] = ['id' => $this->t($d, 'ram:IssuerAssignedID'), 'description' => $this->t($d, 'ram:Name'),
                'filename' => $this->attr($d, 'ram:AttachmentBinaryObject', 'filename'), 'mime_code' => $this->attr($d, 'ram:AttachmentBinaryObject', 'mimeCode'),
                'embedded' => $this->find($d, 'ram:AttachmentBinaryObject') !== null];
        }
        return [
            'invoice_number' => $this->t($doc, 'ram:ID'), 'issue_date' => $this->ciiDateAny($doc, 'ram:IssueDateTime'),
            'type_code' => $this->t($doc, 'ram:TypeCode'), 'currency' => $cur,
            'due_date' => $this->ciiDateAny($st, 'ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime'),
            'tax_point_date' => $this->ciiDateAny($st, 'ram:ApplicableTradeTax/ram:TaxPointDate'),
            'buyer_reference' => $this->t($ag, 'ram:BuyerReference'), 'order_reference' => $this->t($ag, 'ram:BuyerOrderReferencedDocument/ram:IssuerAssignedID'),
            'contract_reference' => $this->t($ag, 'ram:ContractReferencedDocument/ram:IssuerAssignedID'),
            'preceding_invoices' => array_map(fn ($b) => $this->t($b, 'ram:IssuerAssignedID'), $this->findAll($st, 'ram:InvoiceReferencedDocument')),
            'notes' => array_map(fn ($n) => $this->t($n, 'ram:Content'), $this->findAll($doc, 'ram:IncludedNote')),
            'payment_terms' => $this->t($st, 'ram:SpecifiedTradePaymentTerms/ram:Description'),
            'seller' => $this->ciiParty($this->find($ag, 'ram:SellerTradeParty')),
            'buyer' => $this->ciiParty($this->find($ag, 'ram:BuyerTradeParty')),
            'delivery' => ['date' => $this->ciiDateAny($de, 'ram:ActualDeliverySupplyChainEvent/ram:OccurrenceDateTime'),
                'party_name' => $this->t($de, 'ram:ShipToTradeParty/ram:Name'), 'country' => $this->t($de, 'ram:ShipToTradeParty/ram:PostalTradeAddress/ram:CountryID')],
            'totals' => ['line_total' => $this->t($sm, 'ram:LineTotalAmount'), 'allowance_total' => $this->t($sm, 'ram:AllowanceTotalAmount'),
                'charge_total' => $this->t($sm, 'ram:ChargeTotalAmount'), 'total_without_vat' => $this->t($sm, 'ram:TaxBasisTotalAmount'),
                'vat_total' => $vatTotal, 'total_with_vat' => $this->t($sm, 'ram:GrandTotalAmount'), 'prepaid' => $this->t($sm, 'ram:TotalPrepaidAmount'),
                'rounding' => $this->t($sm, 'ram:RoundingAmount'), 'amount_due' => $this->t($sm, 'ram:DuePayableAmount')],
            'vat_breakdown' => $vat, 'payment' => $pay, 'lines' => $lines, 'attachments' => $att,
        ];
    }
}
