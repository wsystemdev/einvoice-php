<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Pdf;

use WSystem\EInvoice\Model\Decimal;
use WSystem\EInvoice\Support\Py;

/**
 * ZUGFeRD 2.3 / Factur-X 1.07 hybrid invoice: a readable A4 invoice (bilingual German / English labels, the same
 * content as the W System API's PDF) as PDF/A-3b with the CII XML embedded as factur-x.xml (xrechnung.xml for the
 * XRECHNUNG profile). Port of vatcheck/einvoice_pdf.py.
 */
final class HybridPdf
{
    public const UNIT = ['C62' => 'Stk / pcs', 'HUR' => 'Std / h', 'DAY' => 'Tag / day', 'H87' => 'Stk / pcs', 'XPK' => 'Pck / pack', 'KGM' => 'kg', 'MTR' => 'm',
        'LTR' => 'l', 'MON' => 'Monat / month', 'ANN' => 'Jahr / year', 'MIN' => 'min', 'KMT' => 'km', 'MTK' => 'm²', 'LS' => 'pauschal / lump sum',
        'WEE' => 'Woche / week', 'SET' => 'Set'];
    public const TITLE = ['380' => 'Rechnung / Invoice', '381' => 'Gutschrift / Credit note', '384' => 'Rechnungskorrektur / Corrected invoice',
        '389' => 'Gutschrift (Selbstfakturierung) / Self-billed invoice', '326' => 'Teilrechnung / Partial invoice',
        '875' => 'Abschlagsrechnung / Partial construction invoice', '877' => 'Schlussrechnung / Final construction invoice',
        '386' => 'Vorauszahlungsrechnung / Prepayment invoice'];
    public const AC_REASON = ['95' => 'Rabatt / Discount', '100' => 'Sondervereinbarung / Special agreement', '102' => 'Langfristige Vereinbarung / Fixed long term',
        '103' => 'Befristet / Temporary', '104' => 'Standard', '41' => 'Bonus', '42' => 'Bonus', '60' => 'Herstellerrabatt / Manufacturer discount',
        '64' => 'Sondervereinbarung / Special agreement', '65' => 'Rabatt Produktionsfehler / Production error discount',
        '66' => 'Neueröffnungsrabatt / New outlet discount', '67' => 'Musterrabatt / Sample discount', '68' => 'Auslaufrabatt / End-of-range discount',
        '88' => 'Materialzu-/abschlag / Material surcharge', 'FC' => 'Fracht / Freight', 'ABL' => 'Verpackung / Packaging',
        'AEO' => 'Sammlung und Recycling / Collection and recycling', 'ADR' => 'Sonstige Leistungen / Other services',
        'ZZZ' => 'Vereinbart / Mutually defined', 'SH' => 'Sonderbehandlung / Special handling', 'DL' => 'Lieferung / Delivery'];
    public const CAT = ['S' => '', 'Z' => 'Nullsatz / zero rated', 'E' => 'steuerfrei / exempt', 'AE' => 'Steuerschuldnerschaft des Leistungsempfängers / reverse charge',
        'K' => 'innergemeinschaftliche Lieferung / intra-Community supply', 'G' => 'Ausfuhr / export', 'O' => 'nicht steuerbar / not subject to VAT',
        'L' => 'IGIC', 'M' => 'IPSI'];

    private const L = 18.0;   // left margin mm
    private const W = 174.0;  // text width mm (A4 210 - 2 x 18)
    private const BOTTOM = 277.0;

    private PdfA3 $pdf;
    private bool $de;

    /**
     * @param array $j  the invoice input; $m = MoneyModel::compute($j); $xml = the CII the receiver reads
     * @param ?int $time Unix time written into the PDF dates (null = now); fix it for reproducible files
     */
    public static function build(array $j, array $m, string $xml, string $fmt, string $fileName, string $level, ?int $time = null): string
    {
        $label = $level !== 'XRECHNUNG' ? 'ZUGFeRD 2.3 / Factur-X 1.07, Profil ' . $level : 'ZUGFeRD 2.3, Profil XRECHNUNG';
        $h = new self();
        PdfA3::$replaced = false;
        return $h->render($j, $m, $label, $fileName, $xml, $level, $time);
    }

    private function money(Decimal $v, int $places = 2): string
    {
        $s = number_format(0, 0); // keep locale-independent
        $neg = $v->isNegative();
        $r = (string) $v->round($places);
        $r = ltrim($r, '-');
        [$int, $frac] = array_pad(explode('.', $r, 2), 2, '');
        $int = strrev(implode($this->de ? '.' : ',', str_split(strrev($int), 3)));
        $out = $int . ($places > 0 ? ($this->de ? ',' : '.') . $frac : '');
        return ($neg ? '-' : '') . $out;
    }

    private function qty(Decimal $v): string
    {
        $s = $v->num();
        return $this->de ? str_replace('.', ',', $s) : $s;
    }

    private function d(mixed $s): string
    {
        if (!Py::t($s)) {
            return '';
        }
        $p = explode('-', Py::str($s));
        if (count($p) !== 3) {
            return Py::str($s);
        }
        return $this->de ? "{$p[2]}.{$p[1]}.{$p[0]}" : "{$p[0]}-{$p[1]}-{$p[2]}";
    }

    private static function s(mixed $v): string
    {
        return $v === null ? '' : Py::str($v);
    }

    // ------------------------------------------------------------------ drawing helpers
    private function font(bool $bold, float $size): void
    {
        $this->pdf->SetFont('WS', $bold ? 'B' : '', $size);
    }

    /** @return list<string> lines (1252-encoded) wrapped to $w mm in the current font */
    private function wrap(string $text, float $w): array
    {
        $out = [];
        foreach (explode("\n", PdfA3::enc($text)) as $para) {
            $line = '';
            foreach (preg_split('/ +/', $para) as $word) {
                $try = $line === '' ? $word : "$line $word";
                if ($this->pdf->GetStringWidth($try) <= $w) {
                    $line = $try;
                    continue;
                }
                if ($line !== '') {
                    $out[] = $line;
                }
                while ($this->pdf->GetStringWidth($word) > $w && strlen($word) > 1) {
                    $k = strlen($word);
                    while ($k > 1 && $this->pdf->GetStringWidth(substr($word, 0, $k)) > $w) {
                        $k--;
                    }
                    $out[] = substr($word, 0, $k);
                    $word = substr($word, $k);
                }
                $line = $word;
            }
            $out[] = $line;
        }
        return $out;
    }

    /**
     * Draw a text block at (x, y) with width w. $parts: list of [text, bold, size, grey].
     * @return float the height used
     */
    private function block(float $x, float $y, float $w, array $parts, string $align = 'L', bool $dry = false): float
    {
        $h = 0.0;
        foreach ($parts as [$text, $bold, $size, $grey]) {
            $this->font($bold, $size);
            $lh = $size * 0.3528 * 1.3;
            foreach ($this->wrap($text, $w) as $ln) {
                if (!$dry) {
                    $this->pdf->SetTextColor($grey ? 68 : 0);
                    $this->pdf->SetXY($x, $y + $h);
                    $this->pdf->Cell($w, $lh, $ln, 0, 0, $align);
                }
                $h += $lh;
            }
        }
        $this->pdf->SetTextColor(0);
        return $h;
    }

    private function ensure(float $h, ?callable $onNewPage = null): void
    {
        if ($this->pdf->GetY() + $h > self::BOTTOM) {
            $this->pdf->AddPage();
            $this->pdf->SetY(16);
            if ($onNewPage) {
                $onNewPage();
            }
        }
    }

    /** one table row; $cells = list of [width, parts, align]; returns height */
    private function row(array $cells, ?callable $onNewPage = null, float $pad = 1.0): float
    {
        $h = 0.0;
        foreach ($cells as [$w, $parts, $align]) {
            $h = max($h, $this->block(0, 0, $w - 2 * $pad, $parts, $align, true));
        }
        $h += 2 * $pad;
        $this->ensure($h, $onNewPage);
        $y = $this->pdf->GetY();
        $x = self::L;
        foreach ($cells as [$w, $parts, $align]) {
            $this->block($x + $pad, $y + $pad, $w - 2 * $pad, $parts, $align);
            $x += $w;
        }
        $this->pdf->SetY($y + $h);
        return $h;
    }

    private function rule(float $width, int $grey, float $x = self::L, float $w = self::W): void
    {
        $this->pdf->SetDrawColor($grey);
        $this->pdf->SetLineWidth($width);
        $y = $this->pdf->GetY();
        $this->pdf->Line($x, $y, $x + $w, $y);
    }

    // ------------------------------------------------------------------ the invoice
    private function render(array $j, array $m, string $label, string $fileName, string $xml, string $level, ?int $time): string
    {
        $seller = is_array($j['seller'] ?? null) ? $j['seller'] : [];
        $buyer = is_array($j['buyer'] ?? null) ? $j['buyer'] : [];
        $pay = Py::t($j['payment'] ?? null) && is_array($j['payment']) ? $j['payment'] : [];
        $this->de = in_array(Py::upper(self::s($seller['country'] ?? '')), ['DE', 'AT', 'CH', 'LU', 'LI'], true);
        $cur = $m['cur'];
        $tc = Py::str(Py::t($j['type_code'] ?? null) ? $j['type_code'] : ($m['is_cn'] ? '381' : '380'));
        $title = self::TITLE[$tc] ?? 'Rechnung / Invoice';
        $tparts = explode(' / ', $title);
        $num = Py::str($j['invoice_number']);

        $this->pdf = $pdf = new PdfA3($time);
        $pdf->SetTitle(end($tparts) . ' ' . $num, true);
        $pdf->SetAuthor(self::s($seller['name'] ?? ''), true);
        $pdf->SetSubject("$label e-invoice $num", true);
        $pdf->SetMargins(self::L, 16, self::L);
        $pdf->SetAutoPageBreak(false);
        $pdf->attach($fileName, $xml, 'Factur-X / ZUGFeRD e-invoice (UN/CEFACT CII)', $level);
        $footerText = mb_substr(self::s($seller['name'] ?? '') . ' · ' . $tparts[0] . ' ' . $num, 0, 120);
        $pdf->footer = function (PdfA3 $p) use ($footerText): void {
            $p->SetFont('WS', '', 7);
            $p->SetTextColor(102);
            $p->SetXY(self::L, 287 - 3);
            $p->Cell(self::W / 2, 4, PdfA3::enc($footerText), 0, 0, 'L');
            $p->SetXY(self::L + self::W / 2, 287 - 3);
            $p->Cell(self::W / 2, 4, PdfA3::enc('Seite / Page ' . $p->PageNo()), 0, 0, 'R');
            $p->SetTextColor(0);
        };
        $pdf->AddPage();
        $pdf->SetY(16);

        $addr = function (array $p): array {
            $out = [[self::s($p['name'] ?? null), true, 8.5, false]];
            if (Py::t($p['trading_name'] ?? null)) {
                $out[] = [Py::str($p['trading_name']), false, 8.5, false];
            }
            if (Py::t($p['street'] ?? null)) {
                $out[] = [Py::str($p['street']), false, 8.5, false];
            }
            $pc = implode(' ', array_map([Py::class, 'str'], array_filter([$p['postal_code'] ?? null, $p['city'] ?? null], [Py::class, 't'])));
            if ($pc !== '') {
                $out[] = [$pc, false, 8.5, false];
            }
            $cc = Py::upper(self::s($p['country'] ?? ''));
            if ($cc !== '') {
                $out[] = [$cc, false, 8.5, false];
            }
            return $out;
        };
        $ids = function (array $p): array {
            $o = [];
            if (Py::t($p['vat_id'] ?? null)) {
                $o[] = ['USt-IdNr. / VAT ID: ' . Py::str($p['vat_id']), false, 7.2, true];
            }
            if (Py::t($p['tax_number'] ?? null)) {
                $o[] = ['Steuernummer / Tax no.: ' . Py::str($p['tax_number']), false, 7.2, true];
            }
            if (Py::t($p['company_id'] ?? null)) {
                $o[] = ['Register / Reg. no.: ' . Py::str($p['company_id']), false, 7.2, true];
            }
            return $o;
        };

        // header: seller | title
        $sellerParts = array_merge($addr($seller), $ids($seller));
        $contact = array_map([Py::class, 'str'], array_filter([$seller['contact_name'] ?? null, $seller['phone'] ?? null, $seller['email'] ?? null], [Py::class, 't']));
        if ($contact) {
            $sellerParts[] = [implode(' · ', $contact), false, 7.2, true];
        }
        $y0 = $pdf->GetY();
        $h1 = $this->block(self::L, $y0, self::W * 0.55, $sellerParts);
        $h2 = $this->block(self::L + self::W * 0.55, $y0, self::W * 0.45, [[$title, true, 15, false]], 'R');
        $pdf->SetY($y0 + max($h1, $h2) + 8);

        // buyer | meta
        $meta = [['Nummer / Number', $num], ['Datum / Issue date', $this->d($j['issue_date'])]];
        if (Py::t($j['due_date'] ?? null) && !$m['is_cn']) {
            $meta[] = ['Fällig am / Due date', $this->d($j['due_date'])];
        }
        if (Py::t($j['delivery_date'] ?? null)) {
            $meta[] = ['Leistungsdatum / Delivery date', $this->d($j['delivery_date'])];
        }
        if (Py::t($j['tax_point_date'] ?? null)) {
            $meta[] = ['Steuerdatum / Tax point date', $this->d($j['tax_point_date'])];
        }
        if (Py::t($j['buyer_reference'] ?? null)) {
            $meta[] = ['Käuferreferenz (Leitweg-ID) / Buyer ref.', Py::str($j['buyer_reference'])];
        }
        if (Py::t($j['order_reference'] ?? null)) {
            $meta[] = ['Bestellung / Purchase order', Py::str($j['order_reference'])];
        }
        if (Py::t($j['preceding_invoice'] ?? null)) {
            $meta[] = ['Bezug auf Rechnung / Corrects invoice', Py::str($j['preceding_invoice'])
                . (Py::t($j['preceding_invoice_date'] ?? null) ? ' (' . $this->d($j['preceding_invoice_date']) . ')' : '')];
        }
        $y0 = $pdf->GetY();
        $h1 = $this->block(self::L, $y0, self::W * 0.55, array_merge([['Rechnungsempfänger / Bill to', false, 7.2, true]], $addr($buyer), $ids($buyer)));
        $yy = $y0;
        foreach ($meta as [$k, $v]) {
            $a = $this->block(self::L + self::W * 0.55, $yy, self::W * 0.25, [[$k, false, 7.2, true]]);
            $b = $this->block(self::L + self::W * 0.80, $yy, self::W * 0.20, [[$v, false, 8.5, false]], 'R');
            $yy += max($a, $b) + 0.7;
        }
        $pdf->SetY(max($y0 + $h1, $yy) + 8);
        if (Py::t($j['note'] ?? null)) {
            $pdf->SetY($pdf->GetY() + $this->block(self::L, $pdf->GetY(), self::W, [[Py::str($j['note']), false, 8.5, false]]) + 4);
        }

        // lines
        $cw = [0.06, 0.38, 0.09, 0.11, 0.12, 0.09, 0.15];
        $cw = array_map(fn ($f) => $f * self::W, $cw);
        $head = function () use ($cw, $cur): void {
            $hd = [['Pos.', 'L'], ['Beschreibung / Description', 'L'], ['Menge / Qty', 'R'], ['Einheit / Unit', 'L'], ['Preis / Unit price', 'R'],
                ['USt / VAT', 'R'], ["Betrag / Amount ($cur)", 'R']];
            $cells = [];
            foreach ($hd as $i => [$t, $a]) {
                $cells[] = [$cw[$i], [[$t, true, 7.2, false]], $a];
            }
            $this->row($cells);
            $this->rule(0.28, 34);
        };
        $head();
        foreach ($m['lines'] as $ln) {
            $dsc = [[$ln['name'], true, 8.5, false]];
            if (Py::t($ln['desc'])) {
                $dsc[] = [Py::str($ln['desc']), false, 8.5, false];
            }
            if (Py::t($ln['sid'])) {
                $dsc[] = ['Art.-Nr. / Item: ' . Py::str($ln['sid']), false, 7, false];
            }
            foreach ($ln['acs'] as $a) {
                $lbl = $this->acReason($a);
                $dsc[] = [($a['charge'] ? '+' : '-') . ' ' . $lbl . ($a['percent'] !== null ? ' ' . $this->qty($a['percent']) . ' %' : '') . ': ' . $this->money($a['amount']), false, 7, false];
            }
            $rate = $ln['cat'] !== 'O' ? $this->qty($ln['rate']) . ' %' : '–';
            $pplaces = max(2, $this->decimals($ln['price']));
            $unit = Py::str($ln['unit']);
            $this->row([
                [$cw[0], [[(string) $ln['id'], false, 8.5, false]], 'L'],
                [$cw[1], $dsc, 'L'],
                [$cw[2], [[$this->qty($ln['q']), false, 8.5, false]], 'R'],
                [$cw[3], [[self::UNIT[$unit] ?? $unit, false, 8.5, false]], 'L'],
                [$cw[4], [[$this->money($ln['price'], $pplaces), false, 8.5, false]], 'R'],
                [$cw[5], [[$rate . ($ln['cat'] !== 'S' ? ' ' . $ln['cat'] : ''), false, 8.5, false]], 'R'],
                [$cw[6], [[$this->money($ln['net']), false, 8.5, false]], 'R'],
            ], $head);
            $this->rule(0.09, 187);
        }
        $pdf->SetY($pdf->GetY() + 4);

        // totals
        $tot = [['Summe Positionen / Line total', $this->money($m['line_total']), false]];
        foreach ($m['doc_acs'] as $a) {
            $tot[] = [$this->docAcLabel($a), ($a['charge'] ? '' : '-') . $this->money($a['amount']), false];
        }
        if ($m['doc_acs']) {
            $tot[] = ['Nettobetrag / Net amount', $this->money($m['basis']), false];
        }
        foreach ($m['vat'] as $g) {
            $lbl = ($g['cat'] !== 'O' ? 'USt / VAT ' . $this->qty($g['rate']) . ' %' : 'USt / VAT') . ' auf / on ' . $this->money($g['base']);
            if ($g['cat'] !== 'S') {
                $lbl .= " ({$g['cat']}: " . (self::CAT[$g['cat']] ?? '') . ')';
            }
            $tot[] = [$lbl, $this->money($g['tax']), false];
        }
        $grandIdx = count($tot);
        $tot[] = ["Gesamtbetrag / Total ($cur)", $this->money($m['incl']), true];
        if (!$m['prepaid']->isZero()) {
            $tot[] = ['Bereits gezahlt / Prepaid', '-' . $this->money($m['prepaid']), false];
            $tot[] = ["Zahlbetrag / Amount due ($cur)", $this->money($m['payable']), true];
        }
        $tx = self::L + self::W * 0.25;
        foreach ($tot as $i => [$k, $v, $bold]) {
            if ($i === $grandIdx) {
                $this->rule(0.28, 34, $tx, self::W * 0.75);
            }
            $this->ensure(6);
            $y = $pdf->GetY();
            $a = $this->block($tx, $y + 0.5, self::W * 0.56 - 2, [[$k, $bold, 8.5, false]], 'R');
            $b = $this->block($tx + self::W * 0.56, $y + 0.5, self::W * 0.19 - 1, [[$v, $bold, 8.5, false]], 'R');
            $pdf->SetY($y + max($a, $b) + 1);
        }
        $pdf->SetY($pdf->GetY() + 6);
        foreach ($m['vat'] as $g) {
            if (Py::t($g['ex_text'])) {
                $this->ensure(5);
                $pdf->SetY($pdf->GetY() + $this->block(self::L, $pdf->GetY(), self::W,
                    [["{$g['cat']}: " . Py::str($g['ex_text']) . (Py::t($g['ex_code']) ? ' (' . Py::str($g['ex_code']) . ')' : ''), false, 7.2, true]]));
            }
        }
        $pl = [];
        if (Py::t($pay['iban'] ?? null)) {
            $pl[] = 'IBAN: ' . Py::str($pay['iban']) . (Py::t($pay['bic'] ?? null) ? ' · BIC: ' . Py::str($pay['bic']) : '')
                . (Py::t($pay['account_name'] ?? null) ? ' · Kontoinhaber / Account holder: ' . Py::str($pay['account_name']) : '');
        }
        if (Py::t($pay['reference'] ?? null)) {
            $pl[] = 'Verwendungszweck / Payment reference: ' . Py::str($pay['reference']);
        }
        if (Py::t($pay['terms'] ?? null)) {
            $pl[] = 'Zahlungsbedingungen / Terms: ' . Py::str($pay['terms']);
        }
        if ($pl) {
            $parts = [['Zahlung / Payment', true, 8.5, false]];
            foreach ($pl as $x) {
                $parts[] = [$x, false, 8.5, false];
            }
            $pdf->SetY($pdf->GetY() + 2);
            $this->ensure($this->block(self::L, 0, self::W, $parts, 'L', true));
            $pdf->SetY($pdf->GetY() + $this->block(self::L, $pdf->GetY(), self::W, $parts));
        }
        $note = "Diese PDF-Datei ist eine hybride E-Rechnung ($label). Die eingebettete Datei $fileName enthält die strukturierten "
            . "Rechnungsdaten (UN/CEFACT CII). / This PDF is a hybrid e-invoice ($label); the embedded $fileName carries the structured invoice data (UN/CEFACT CII).";
        $pdf->SetY($pdf->GetY() + 6);
        $this->ensure($this->block(self::L, 0, self::W, [[$note, false, 7.2, true]], 'L', true));
        $this->block(self::L, $pdf->GetY(), self::W, [[$note, false, 7.2, true]]);
        return $pdf->Output('S');
    }

    /** Reason text for an allowance or charge; empty if only a blank code was given. */
    private function acReason(array $a): string
    {
        if (Py::t($a['reason'] ?? null)) {
            return Py::str($a['reason']);
        }
        $code = Py::str($a['reason_code'] ?? '');
        return self::AC_REASON[$code] ?? $code;
    }

    /**
     * Document-level row label. The money model fills a default reason of "Zuschlag / Charge" or
     * "Rabatt / Discount" when neither reason nor reason_code is set; don't repeat the kind prefix then.
     */
    private function docAcLabel(array $a): string
    {
        $kind = $a['charge'] ? 'Zuschlag / Charge' : 'Nachlass / Allowance';
        $reason = $this->acReason($a);
        $lbl = ($reason !== '' && $reason !== $kind) ? "$kind: $reason" : $kind;
        if ($a['percent'] !== null) {
            $lbl .= ' (' . $this->qty($a['percent']) . ' %)';
        }
        if ($a['cat'] !== 'O') {
            $lbl .= ' [' . $this->qty($a['rate']) . ' %]';
        }
        return $lbl;
    }

    private function decimals(Decimal $v): int
    {
        $s = $v->num();
        $p = strpos($s, '.');
        return $p === false ? 0 : strlen($s) - $p - 1;
    }
}
