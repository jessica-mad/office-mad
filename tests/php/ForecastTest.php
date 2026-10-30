<?php
use MAD_Oficina\Billing\Agreements;
use MAD_Oficina\Billing\Forecast;
use MAD_Oficina\Billing\Invoices;
use MAD_Oficina\Billing\Months;
use MAD_Oficina\Billing\Profiles;
use MAD_Oficina\Billing\Quotes;

/**
 * Agreements, quotes and the month-by-month forecast (base, without VAT). Invented data.
 */
class ForecastTest extends MADOF_TestCase {

    private $lcs; private $rf; private $p_lcs; private $p_rf;

    public function set_up(){
        parent::set_up();
        $this->lcs = $this->make_client('LCS', 'Luz Clara');
        $this->rf = $this->make_client('RFX', 'Ropa Fina');
        $this->p_lcs = Profiles::save(['legal_name' => 'Luz Clara SL', 'tax_id' => 'B1', 'client_id' => $this->lcs]);
        $this->p_rf = Profiles::save(['legal_name' => 'Ropa Fina SL', 'tax_id' => 'B2', 'client_id' => $this->rf]);
    }

    private function invoice($profile, $date, $base, $desc = 'Mantenimiento web · octubre 2026'){
        return Invoices::insert(['series' => 'F', 'number' => 'T-' . wp_generate_password(6, false), 'year' => (int) substr($date, 0, 4), 'status' => 'sent', 'issue_date' => $date, 'client_name' => 'x', 'profile_id' => $profile, 'base' => $base, 'total' => $base], [['description' => $desc, 'amount' => $base]]);
    }

    public function test_months(){
        $this->assertSame('2027-01', Months::add('2026-11', 2));
        $this->assertSame('2026-10', Months::add('2027-01', -3));
        $this->assertSame(3, Months::diff('2026-11', '2027-02'));
        $this->assertSame(['2026-11', '2026-12', '2027-01'], Months::range('2026-11', '2027-01'));
        $this->assertSame('ene 2027', Months::label('2027-01'));
        $this->assertNull(Months::valid('2026-13'));
    }

    public function test_agreements_change_and_end(){
        $fee = Agreements::create(['profile_id' => $this->p_lcs, 'kind' => 'monthly', 'concept' => 'Mantenimiento', 'amount' => '550,00', 'start_month' => '2024-03']);
        $this->assertIsInt($fee);
        $this->assertSame('desde mar 2024', Agreements::period(Agreements::get($fee)));
        $this->assertSame($this->lcs, Agreements::get($fee)['client_id']);

        $new = Agreements::change_amount($fee, '2027-01', '600');
        $this->assertIsInt($new);
        $this->assertSame('2026-12', Agreements::get($fee)['end_month'], 'the old amount ends the month before');
        $this->assertSame(600.0, Agreements::get($new)['amount']);
        $this->assertNull(Agreements::get($new)['end_month']);
        $this->assertSame($fee, Agreements::get($new)['replaces_id']);
        $this->assertWPError(Agreements::change_amount($fee, '2024-03', '1'), 'not from its first month');

        $once = Agreements::create(['profile_id' => $this->p_lcs, 'kind' => 'once', 'concept' => 'Landing', 'amount' => '900', 'start_month' => '2026-11', 'end_month' => '2027-05']);
        $this->assertSame('2026-11', Agreements::get($once)['end_month'], 'a one-off is a single month');
        $this->assertWPError(Agreements::create(['profile_id' => $this->p_lcs, 'concept' => 'x', 'amount' => '1', 'start_month' => '2026-11', 'end_month' => '2026-10']));
        $this->assertTrue(Agreements::cancel($once));
        $this->assertCount(2, Agreements::all(['client_id' => $this->lcs]));
    }

    public function test_quote_accepted_becomes_agreements_and_can_move(){
        $q = Quotes::save(['title' => 'Navidad', 'profile_id' => $this->p_rf, 'probability' => 75, 'sent_on' => '2026-10-01'], [
            ['kind' => 'monthly', 'concept' => 'Campaña Navidad', 'amount' => '1.200,00', 'start_month' => '2026-11', 'months' => 3],
            ['kind' => 'once', 'concept' => 'Setup', 'amount' => '500', 'start_month' => '2026-11'],
        ]);
        $this->assertIsInt($q);
        $quote = Quotes::get($q);
        $this->assertSame('sent', $quote['status']);
        $this->assertMatchesRegularExpression('/^P-\d{2}-001$/', $quote['number']);
        $this->assertSame(1200.0, Quotes::line_in($quote['lines'][0], '2027-01'));
        $this->assertSame(0.0, Quotes::line_in($quote['lines'][0], '2027-02'));

        $ids = Quotes::accept($q, 0, '2026-12')['agreements']; // starts one month later than planned
        $this->assertCount(2, $ids);
        $fee = Agreements::get($ids[0]);
        $this->assertSame(['2026-12', '2027-02'], [$fee['start_month'], $fee['end_month']]);
        $this->assertSame('2026-12', Agreements::get($ids[1])['start_month']);
        $this->assertSame('accepted', Quotes::get($q)['status']);
        $this->assertWPError(Quotes::save(['title' => 'x', 'profile_id' => $this->p_rf], [['kind' => 'once', 'concept' => 'x', 'amount' => '1', 'start_month' => '2026-11']], $q), 'accepted quotes are closed');
        $this->assertWPError(Quotes::accept($q, $this->p_rf));
    }

    public function test_part_of_a_quote_accepted_with_the_payments_agreed_and_the_rest_to_rethink(){
        $q = Quotes::save(['title' => 'Plan 2027', 'profile_id' => $this->p_rf, 'sent_on' => '2026-10-01', 'probability' => 50], [
            ['kind' => 'monthly', 'concept' => 'Redes', 'amount' => '600', 'start_month' => '2026-11', 'months' => 6],
            ['kind' => 'once', 'concept' => 'Web', 'amount' => '3000', 'start_month' => '2026-11'],
            ['kind' => 'once', 'concept' => 'Vídeo', 'amount' => '900', 'start_month' => '2026-12'],
        ]);
        $this->assertWPError(Quotes::accept($q, 0, null, [0 => [], 1 => [], 2 => []]), 'at least one line');
        // Redes at 500 for 3 months; the web paid in 3 monthly instalments of 1.000; the video not now.
        $r = Quotes::accept($q, 0, null, [
            0 => ['on' => 1, 'amount' => '500', 'kind' => 'monthly', 'months' => '3'],
            1 => ['on' => 1, 'amount' => '1000', 'kind' => 'monthly', 'months' => '3', 'start_month' => '2027-01'],
        ], 'new');
        $this->assertCount(2, $r['agreements']);
        $redes = Agreements::get($r['agreements'][0]); $web = Agreements::get($r['agreements'][1]);
        $this->assertSame([500.0, '2026-11', '2027-01'], [(float) $redes['amount'], $redes['start_month'], $redes['end_month']]);
        $this->assertSame(['monthly', 1000.0, '2027-01', '2027-03'], [$web['kind'], (float) $web['amount'], $web['start_month'], $web['end_month']]);
        $this->assertSame(1, $r['left']);
        $rest = Quotes::get($r['new_quote']);
        $this->assertSame(['Vídeo'], array_column($rest['lines'], 'concept'), 'what was not accepted, to rethink');
        $this->assertStringContainsString('Aceptado en parte', Quotes::get($q)['notes']);

        // Its payment plan: one invoice per month, with VAT, and how it goes out.
        $plan = Quotes::payment_plan(Quotes::get($q), new MAD_Oficina\Billing\Issuing(['billing_day' => 28]));
        $this->assertSame(['2026-11', '2026-12', '2027-01', '2027-02', '2027-03'], array_column($plan, 'month'));
        $this->assertSame(['2026-11-28', 500.0, false], [$plan[0]['date'], $plan[0]['base'], $plan[0]['auto']]);
        $this->assertSame(1500.0, $plan[2]['base'], 'January: Redes and the first instalment of the web');
    }

    public function test_links_and_documents_go_with_the_quote(){
        $q = Quotes::save(['title' => 'Web', 'profile_id' => $this->p_rf, 'probability' => 50], [['kind' => 'once', 'concept' => 'Web', 'amount' => '3000', 'start_month' => '2026-12']]);
        $this->assertWPError(MAD_Oficina\Billing\QuoteFiles::add_link($q, 'javascript:alert(1)'));
        $this->assertTrue(MAD_Oficina\Billing\QuoteFiles::add_link($q, 'https://docs.example/propuesta', 'Propuesta'));
        $this->assertSame('pdf', MAD_Oficina\Billing\QuoteFiles::sniff("%PDF-1.7 ..."));
        $this->assertNull(MAD_Oficina\Billing\QuoteFiles::sniff('<?php echo 1;'), 'only PDFs and images, by their bytes');
        $list = MAD_Oficina\Billing\QuoteFiles::of(Quotes::get($q));
        $this->assertSame(['link', 'Propuesta', 'https://docs.example/propuesta'], [$list[0]['type'], $list[0]['name'], $list[0]['url']]);
        $this->assertTrue(MAD_Oficina\Billing\QuoteFiles::remove($q, $list[0]['id']));
        $this->assertSame([], MAD_Oficina\Billing\QuoteFiles::of(Quotes::get($q)));
    }

    public function test_quote_for_someone_who_is_not_a_client_yet(){
        $q = Quotes::save(['title' => 'Web nueva', 'prospect_name' => 'Estudio Norte', 'sent_on' => '2026-10-05', 'probability' => 50], [['kind' => 'once', 'concept' => 'Web', 'amount' => '3000', 'start_month' => '2026-12']]);
        $this->assertWPError(Quotes::accept($q, 0), 'needs fiscal data to be accepted');
        $this->assertWPError(Quotes::save(['title' => 'x'], [['kind' => 'once', 'concept' => 'x', 'amount' => '1', 'start_month' => '2026-11']]), 'needs someone to send it to');
    }

    public function test_forecast_real_confirmed_possible_and_alerts(){
        // LCS: 550/month, invoiced Jul–Sep but not October (now = Nov 2026).
        Agreements::create(['profile_id' => $this->p_lcs, 'kind' => 'monthly', 'concept' => 'Mantenimiento', 'amount' => '550', 'start_month' => '2026-01']);
        foreach ( ['2026-07-28', '2026-08-28', '2026-09-28'] as $d ) $this->invoice($this->p_lcs, $d, 550);
        // RF: Christmas project until January.
        Agreements::create(['profile_id' => $this->p_rf, 'kind' => 'monthly', 'concept' => 'Navidad', 'amount' => '1200', 'start_month' => '2026-11', 'end_month' => '2027-01']);
        // A prospect quote, 50 %.
        Quotes::save(['title' => 'Web', 'prospect_name' => 'Estudio Norte', 'sent_on' => '2026-10-05', 'valid_until' => '2026-10-20', 'probability' => 50], [['kind' => 'once', 'concept' => 'Web', 'amount' => '3000', 'start_month' => '2026-12']]);
        // A draft quote does not count.
        Quotes::save(['title' => 'Borrador', 'prospect_name' => 'Nadie'], [['kind' => 'once', 'concept' => 'x', 'amount' => '999', 'start_month' => '2026-12']]);

        $f = Forecast::compute('2026-08', '2027-03', '2026-11-10');
        $t = $f['totals'];
        $this->assertSame(550.0, $t['expected']['2026-09'], 'past: real');
        $this->assertSame(0.0, $t['expected']['2026-10'], 'past month not invoiced: real is 0');
        $this->assertSame(1750.0, $t['expected']['2026-11']);
        $this->assertSame(1750.0, $t['expected']['2027-01']);
        $this->assertSame(550.0, $t['expected']['2027-02'], 'RF ended');
        $this->assertSame(3000.0, $t['possible']['2026-12']);
        $this->assertSame(1500.0, $t['weighted']['2026-12']);
        $this->assertSame(0.0, $t['possible']['2027-01']);

        $alerts = implode("\n", $f['alerts']);
        $this->assertStringContainsString('«Navidad» termina en ene 2027', $alerts);
        $this->assertStringContainsString('en oct 2026 había 550,00 € acordados y se han facturado 0,00 €', $alerts);
        $this->assertStringContainsString('P-', $alerts, 'expired quote');
        $this->assertStringContainsString('caducó', $alerts);

        $labels = array_column($f['rows'], 'label');
        $this->assertContains('Estudio Norte', $labels);
        $this->assertNotContains('Nadie', $labels);
    }

    public function test_fee_suggestions_from_history(){
        $this->invoice($this->p_lcs, '2026-06-28', 500); // older, lower fee
        foreach ( ['2026-07-28', '2026-08-28', '2026-09-28', '2026-10-28'] as $d ) $this->invoice($this->p_lcs, $d, 550, 'Mantenimiento web y SEM · ' . $d);
        $this->invoice($this->p_rf, '2026-09-15', 2000); // one-off: not a fee
        $s = Agreements::suggestions('2026-11-05');
        $this->assertCount(1, $s);
        $this->assertSame($this->p_lcs, $s[0]['profile']['id']);
        $this->assertSame(550.0, $s[0]['amount']);
        $this->assertSame('2026-07', $s[0]['start_month'], 'when the current amount started, not June at 500');
        $this->assertSame('Mantenimiento web y SEM', $s[0]['concept']);

        Agreements::create(['profile_id' => $this->p_lcs, 'kind' => 'monthly', 'concept' => 'x', 'amount' => '550', 'start_month' => '2026-07']);
        $this->assertSame([], Agreements::suggestions('2026-11-05'), 'not proposed once it has a fee');
        $this->assertSame('Gestión de redes', Agreements::strip_month('Gestión de redes · octubre 2026'));
        $this->assertSame('Fee', Agreements::strip_month('Fee (mes de marzo)'));
    }
}
