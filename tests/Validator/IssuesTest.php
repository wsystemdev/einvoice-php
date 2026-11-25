<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Tests\Validator;

use PHPUnit\Framework\TestCase;
use WSystem\EInvoice\Validator\Issues;

final class IssuesTest extends TestCase
{
    public function testEntryAndHints(): void
    {
        $e = Issues::entry('BR-CL-14', 'fatal', 'EN16931', "[BR-CL-14]-Country codes\n  in an invoice MUST be coded", '/Invoice/cac:AccountingCustomerParty[1]/x');
        $this->assertSame('error', $e['severity']);
        $this->assertSame('Country codes in an invoice MUST be coded', $e['message']);
        $this->assertSame(['BT-55'], $e['business_terms']);
        $this->assertStringContainsString('buyer', $e['fix']);
        $w = Issues::entry('UBL-CR-001', 'warning', 'EN16931', '[UBL-CR-001]-A UBL invoice should not include extensions', null);
        $this->assertSame('warning', $w['severity']);
        $this->assertSame(['BT-1', 'BT-24'], Issues::entry('X', 'fatal', 'EN16931', 'see BT-24 and BT-1 and BT-24', null)['business_terms']);
    }

    public function testProfileSeverities(): void
    {
        $i = ['rule_id' => 'CII-DT-027', 'severity' => 'error', 'ruleset' => 'EN16931'];
        $this->assertSame('warning', Issues::extendedRelax($i)['severity']);
        $this->assertSame('error', Issues::extendedRelax(['rule_id' => 'BR-16'] + $i)['severity']);
        $x = Issues::xrechnungExtLevel(['rule_id' => 'BR-CL-10', 'severity' => 'error', 'ruleset' => 'EN16931'], 'cii');
        $this->assertSame('warning', $x['severity']);
        $this->assertStringContainsString('information', $x['profile_note']);
        $this->assertSame('error', Issues::xrechnungExtLevel(['rule_id' => 'CII-SR-452', 'severity' => 'warning', 'ruleset' => 'EN16931'], 'cii')['severity']);
    }
}
