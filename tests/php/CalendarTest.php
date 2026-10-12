<?php
use MAD_Oficina\Billing\Agreements;
use MAD_Oficina\Billing\Calendar;
use MAD_Oficina\Billing\Forecast;
use MAD_Oficina\Billing\Invoices;
use MAD_Oficina\Billing\Issuing;
use MAD_Oficina\Billing\Profiles;
use MAD_Oficina\Core\Approvals;

/**
 * Billing calendar: drafts from agreements, asking on the day, postpone / skip. Invented data.
 */
class CalendarTest extends MADOF_TestCase {

    private $profile; private $fee; private $once;

    public function set_up(){
        parent::set_up();
        update_option('timezone_string', 'Europe/Madrid');
        $settings = ['billing_day' => 28, 'payment_days' => 15, 'calendar_enabled' => true, 'calendar_from' => '2026-11', 'calendar_lead_days' => 3, 'calendar_hour' => 9];
        update_option(MAD_Oficina_Core::option_key('billing'), $settings);
        $client = $this->make_client('LCS', 'Luz Clara');
        $this->profile = Profiles::save(['legal_name' => 'Luz Clara SL', 'tax_id' => 'B1', 'email' => 'pagos@lcs.example', 'client_id' => $client]);
        $this->fee = Agreements::create(['profile_id' => $this->profile, 'kind' => 'monthly', 'concept' => 'Mantenimiento web', 'amount' => '550', 'start_month' => '2026-01']);
        $this->once = Agreements::create(['profile_id' => $this->profile, 'kind' => 'once', 'concept' => 'Landing Black Friday', 'amount' => '900', 'start_month' => '2026-11']);
        wp_set_current_user($this->make_admin());
    }

    private function cal(){ return new Calendar(get_option(MAD_Oficina_Core::option_key('billing'))); }
    private function ts($datetime){ return (new DateTimeImmutable($datetime, wp_timezone()))->getTimestamp(); }

    public function test_prepares_three_days_before_and_asks_on_the_day(){
        $this->assertSame(['prepared' => 0, 'asked' => 0], $this->cal()->run($this->ts('2026-11-24 10:00')), 'nothing yet (prepared from the 25th)');
        delete_option(Calendar::RUN_OPTION);
        $this->assertSame([], $this->cal()->run($this->ts('2026-11-25 08:00')), 'before the hour');
        $r = $this->cal()->run($this->ts('2026-11-25 10:00'));
        $this->assertSame(['prepared' => 1, 'asked' => 0], $r);
        $this->assertSame([], $this->cal()->run($this->ts('2026-11-25 18:00')), 'once a day');

        $draft = Invoices::all(['status' => 'draft'])[0];
        $inv = Invoices::get($draft['id']);
        $this->assertSame('2026-11-28', $inv['issue_date']);
        $this->assertSame(['Mantenimiento web · noviembre 2026', 'Landing Black Friday'], array_column($inv['lines'], 'description'));
        $this->assertSame(1450.0, $inv['base']);
        $this->assertSame('calendar', $inv['source']);

        delete_option(Calendar::RUN_OPTION);
        $this->assertSame(['prepared' => 0, 'asked' => 1], $this->cal()->run($this->ts('2026-11-28 09:30')));
        $approval = Approvals::for_object('invoice', $inv['id'])[0];
        $this->assertSame(Approvals::PENDING, $approval['status']);
        $this->assertSame(Issuing::ACTION, $approval['action_type']);
        delete_option(Calendar::RUN_OPTION);
        $this->assertSame(0, $this->cal()->run($this->ts('2026-11-29 09:30'))['asked'], 'asked once');

        // Chat buttons on that card.
        $buttons = Calendar::chat_buttons([], $approval);
        $this->assertSame(['Enviar', 'Editar', 'Posponer 7 días', 'Este mes no'], array_column($buttons, 'text'));
    }

    public function test_never_twice_and_months_before_the_start_are_left_alone(){
        $this->assertSame(0, $this->cal()->prepare('2026-10-27'), 'October is before «empezar en»');
        $this->cal()->prepare('2026-11-26');
        $this->cal()->prepare('2026-11-27');
        $this->assertCount(1, Invoices::all(['status' => 'draft']));

        $id = Invoices::all(['status' => 'draft'])[0]['id'];
        Approvals::resolve((new Issuing(get_option(MAD_Oficina_Core::option_key('billing'))))->request($id), 'approve', ['send' => '', 'issue_date' => '2026-11-28'], get_current_user_id());
        $this->assertNotNull(Invoices::get($id)['number']);
        $this->assertSame(0, $this->cal()->prepare_one(Profiles::get($this->profile), '2026-11'), 'already billed');

        // A new one-off for November after the invoice went out: a second draft only with it.
        Agreements::create(['profile_id' => $this->profile, 'kind' => 'once', 'concept' => 'Extra', 'amount' => '100', 'start_month' => '2026-11']);
        $second = $this->cal()->prepare_one(Profiles::get($this->profile), '2026-11');
        $this->assertSame(['Extra'], array_column(Invoices::get($second)['lines'], 'description'));
        $this->assertStringContainsString('ya hay otra factura', Invoices::get($second)['notes']);
    }

    public function test_editing_a_calendar_draft_keeps_the_link(){
        $id = $this->cal()->prepare_one(Profiles::get($this->profile), '2026-12');
        $inv = Invoices::get($id);
        $lines = array_map(fn($l) => ['description' => $l['description'], 'quantity' => '1', 'unit_price' => '600', 'item_id' => $l['item_id'], 'item_month' => $l['item_month']], $inv['lines']);
        $lines[] = ['description' => 'Algo más', 'quantity' => '1', 'unit_price' => '50'];
        (new Issuing(get_option(MAD_Oficina_Core::option_key('billing'))))->save_draft(['profile_id' => $this->profile, 'issue_date' => $inv['issue_date']], $lines, $id);
        $this->assertSame($id, (int) Calendar::billed_in($this->fee, '2026-12')['invoice_id']);
        $this->assertSame(0, $this->cal()->prepare_one(Profiles::get($this->profile), '2026-12'));
    }

    public function test_postpone_and_skip(){
        $id = $this->cal()->prepare_one(Profiles::get($this->profile), '2026-11');
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->update(Invoices::table(), ['source' => 'calendar'], ['id' => $id]);
        $this->cal()->ask('2026-11-28');
        $r = Calendar::postpone($id, 7);
        $this->assertSame('2026-12-05', $r['date']);
        $inv = Invoices::get($id);
        $this->assertSame('2026-12-05', $inv['issue_date']);
        $this->assertSame('2026-12-20', $inv['due_date'], 'the due date moves with it');
        $this->assertSame(Approvals::REJECTED, Approvals::for_object('invoice', $id)[0]['status']);
        $this->assertSame(0, $this->cal()->ask('2026-12-04'));
        $this->assertSame(1, $this->cal()->ask('2026-12-05'), 'asked again on the new day');

        Calendar::skip_invoice($id);
        $this->assertNull(Invoices::get($id));
        $this->assertTrue(Calendar::skipped($this->fee, '2026-11'));
        $this->assertSame(0, $this->cal()->prepare_one(Profiles::get($this->profile), '2026-11'), 'not prepared again');
        $f = Forecast::compute('2026-11', '2026-12', '2026-11-01');
        $this->assertSame(0.0, $f['totals']['confirmed']['2026-11'], 'nor counted in the forecast');
        $this->assertSame(550.0, $f['totals']['confirmed']['2026-12']);

        $month = $this->cal()->month('2026-11');
        $this->assertSame('skipped', $month[0]['state']);
    }

    public function test_argentina_only_gets_a_reminder(){
        $ar = Profiles::save(['legal_name' => 'Divano', 'series' => 'AR', 'vat_rate' => 0, 'irpf_rate' => 0]);
        Agreements::create(['profile_id' => $ar, 'kind' => 'monthly', 'concept' => 'RRSS', 'amount' => '500', 'start_month' => '2026-11']);
        $notes = [];
        add_action('madoficina_notify_manager', function($t) use (&$notes){ $notes[] = $t; });
        $id = $this->cal()->prepare_one(Profiles::get($ar), '2026-11');
        $GLOBALS['wpdb']->update(Invoices::table(), ['source' => 'calendar'], ['id' => $id]);
        $this->cal()->ask('2026-11-28');
        $this->assertSame('AR', Invoices::get($id)['series']);
        $this->assertSame([], Approvals::for_object('invoice', $id), 'no approval to issue: it is issued in Argentina');
        $this->assertStringContainsString('plataforma argentina', implode(' ', $notes));
    }
}
