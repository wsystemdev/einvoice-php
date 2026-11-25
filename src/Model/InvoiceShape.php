<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Model;

/**
 * The invoice input shape shared by every module: the same JSON object as W System's POST /einvoice/create
 * (https://api.wsystem.dev/docs). Generators take it; the parser (validator stream) returns this shape where it can.
 *
 * @phpstan-type Party array{name: string, country: string, vat_id?: string, tax_number?: string, company_id?: string,
 *   company_id_scheme?: string, legal_form?: string, trading_name?: string, street?: string, city?: string, postal_code?: string,
 *   email?: string, phone?: string, contact_name?: string, endpoint_id?: string, endpoint_scheme?: string}
 * @phpstan-type AllowanceCharge array{amount?: int|float|string, percent?: int|float|string, base_amount?: int|float|string,
 *   reason?: string, reason_code?: string, vat_category?: string, vat_rate?: int|float|string}
 * @phpstan-type Line array{description: string, quantity?: int|float|string, unit?: string, unit_price: int|float|string,
 *   vat_category?: string, vat_rate?: int|float|string, seller_item_id?: string, item_description?: string,
 *   allowances?: list<AllowanceCharge>, charges?: list<AllowanceCharge>}
 * @phpstan-type Invoice array{format?: string, invoice_number: string, issue_date: string, type_code?: string,
 *   due_date?: string, delivery_date?: string, delivery_country?: string, tax_point_date?: string, currency?: string,
 *   buyer_reference?: string, order_reference?: string, preceding_invoice?: string, preceding_invoice_date?: string, note?: string,
 *   prepaid_amount?: int|float|string, seller: Party, buyer: Party, lines: list<Line>,
 *   allowances?: list<AllowanceCharge>, charges?: list<AllowanceCharge>,
 *   payment?: array{iban?: string, bic?: string, account_name?: string, means_code?: string, reference?: string, terms?: string},
 *   vat_exemption_code?: array<string, string>, vat_exemption_reason?: array<string, string>}
 */
final class InvoiceShape
{
    public const FORMATS = ['en16931', 'xrechnung', 'peppol-bis3', 'cii', 'xrechnung-cii', 'zugferd', 'factur-x', 'zugferd-xrechnung'];
    public const VAT_CATEGORIES = ['S', 'Z', 'E', 'AE', 'K', 'G', 'O', 'L', 'M'];
}
