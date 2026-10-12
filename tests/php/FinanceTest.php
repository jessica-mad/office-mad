<?php
use MAD_Oficina\Billing\Finance;
use MAD_Oficina\Billing\Invoices;
use MAD_Oficina\Billing\Taxes;
use MAD_Oficina\Suppliers\Expenses;

/**
 * Finanzas: taxes noted by hand or monthly, and income vs expenses per month. Invented data.
 */
class FinanceTest extends MADOF_TestCase {

    private function invoice($series, $date, $base, $vat = 0, $irpf = 0, $status = 'sent'){
        return Invoices::insert(['series' => $series, 'number' => 'T-' . wp_generate_password(6, false), 'year' => (int) substr($date, 0, 4), 'status' => $status, 'issue_date' => $date, 'client_name' => 'Cliente Prueba', 'base' => $base, 'vat' => $vat, 'irpf' => $irpf, 'total' => $base + $vat - $irpf], [['description' => 'Fee', 'amount' => $base]]);
    }

    public function test_tax_save_validates_and_keeps_one_per_period(){
        $this->assertWPError(Taxes::save(['kind' => 'nope', 'period' => '2026-T1', 'amount' => '10']));
        $this->assertWPError(Taxes::save(['kind' => 'vat_303', 'period' => '2026-T5', 'amount' => '10']));
        $this->assertWPError(Taxes::save(['kind' => 'vat_303', 'period' => '2026-T1', 'amount' => '']));

        $a = Taxes::save(['kind' => 'vat_303', 'period' => '2026-t1', 'amount' => '1.234,50', 'paid_on' => '2026-04-20']);
        $b = Taxes::save(['kind' => 'vat_303', 'period' => '2026-T1', 'amount' => '1.300,00', 'paid_on' => '2026-04-20']);
        $this->assertSame($a, $b, 'same tax and quarter: updated, not duplicated');
        $this->assertSame(1300.0, Taxes::get($a)['amount']);
        $this->assertSame('2026-T1', Taxes::get($a)['period']);

        $o1 = Taxes::save(['kind' => 'other', 'period' => '2026', 'amount' => '50']);
        $o2 = Taxes::save(['kind' => 'other', 'period' => '2026', 'amount' => '70']);
        $this->assertNotSame($o1, $o2, '«other» can repeat');
        $this->assertSame('pending', Taxes::get($o1)['status']);
    }

    public function test_autonomos_are_noted_monthly_once(){
        $s = ['autonomos_amount' => 294.0, 'autonomos_from' => '2026-07', 'autonomos_day' => 31];
        $this->assertSame(3, Taxes::apply_monthly($s, '2026-09-30'));
        $this->assertSame('2026-09-30', Taxes::find('autonomos', '2026-09')['paid_on'], 'day capped to the month');
        $this->assertSame(0, Taxes::apply_monthly($s, '2026-09-30'), 'idempotent');
        $this->assertNull(Taxes::find('autonomos', '2026-10'));

        // A new amount from November leaves the old months as they were.
        $s = ['autonomos_amount' => 310.0, 'autonomos_from' => '2026-11', 'autonomos_day' => 28];
        $this->assertSame(1, Taxes::apply_monthly($s, '2026-11-28'));
        $this->assertSame(310.0, Taxes::find('autonomos', '2026-11')['amount']);
        $this->assertSame(294.0, Taxes::find('autonomos', '2026-09')['amount']);
        $this->assertSame(0, Taxes::apply_monthly(['autonomos_amount' => 0, 'autonomos_from' => '2026-01'], '2026-11-28'));
    }

    public function test_year_summary_and_vat_estimate(){
        $this->invoice('F', '2026-02-10', 1000, 210, 150);
        $this->invoice('AR', '2026-02-12', 400);
        $this->invoice('F', '2026-03-01', 500, 105, 0, 'draft'); // drafts don't count

        Expenses::insert(['status' => 'registered', 'issue_date' => '2026-02-05', 'currency' => 'EUR', 'base' => 100, 'vat' => 21, 'total' => 121, 'category' => 'software']);
        Expenses::insert(['status' => 'registered', 'issue_date' => '2026-02-15', 'currency' => 'USD', 'base' => 300, 'vat' => 0, 'total' => 300, 'category' => 'team']);
        Expenses::insert(['status' => 'registered', 'issue_date' => '2026-02-20', 'currency' => 'USD', 'base' => 200, 'vat' => 0, 'total' => 200, 'paid_eur' => 185, 'category' => 'team']);
        Expenses::insert(['status' => 'review', 'issue_date' => '2026-02-21', 'currency' => 'EUR', 'base' => 999, 'vat' => 0, 'total' => 999]);

        Taxes::save(['kind' => 'autonomos', 'period' => '2026-02', 'amount' => '294', 'paid_on' => '2026-02-28']);
        Taxes::save(['kind' => 'vat_303', 'period' => '2025-T4', 'amount' => '400', 'paid_on' => '2026-01-20']);

        $f = Finance::year(2026);
        $feb = $f['months'][2];
        $this->assertSame(1000.0, $feb['income']);
        $this->assertSame(400.0, $feb['income_ar'], 'Argentina apart');
        $this->assertSame(210.0, $feb['vat_charged']);
        $this->assertSame(150.0, $feb['irpf']);
        $this->assertSame(285.0, $feb['expenses'], 'EUR base + paid in euros; foreign without paid_eur left out');
        $this->assertSame(21.0, $feb['vat_paid']);
        $this->assertSame(294.0, $feb['tax_costs']);
        $this->assertSame(821.0, $feb['result']);
        $this->assertSame(1, $f['foreign_missing']);
        $this->assertSame(400.0, $f['months'][1]['vat_settled'], 'VAT paid is not a cost');
        $this->assertSame(0.0, $f['months'][1]['result']);
        $this->assertSame(1000.0, $f['quarters'][1]['income']);
        $this->assertSame(821.0, $f['totals']['result']);
        $this->assertSame(['team' => 185.0, 'software' => 100.0], $f['categories']);

        $this->assertSame(['charged' => 210.0, 'paid' => 21.0, 'result' => 189.0], Taxes::vat_estimate(2026, 1));
        $this->assertSame('T1 2026', Taxes::period_label('2026-T1'));
    }
}
