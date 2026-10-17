<?php
use MAD_Oficina\Ads\Accounts;
use MAD_Oficina\Ads\Changes;
use MAD_Oficina\Ads\GoogleAds;
use MAD_Oficina\Ads\Reasons;
use MAD_Oficina\Ads\Sync;
use MAD_Oficina\Core\Crypto;
use MAD_Oficina\Core\Schema;

/**
 * Campañas (read-only): Google Ads and Meta are faked; every account, campaign and
 * change here is invented.
 */
class AdsTest extends MADOF_TestCase {

    private $client;
    private $g = ['campaigns' => [], 'metrics' => [], 'changes' => [], 'accounts' => []];
    private $m = ['campaigns' => [], 'insights' => [], 'activities' => [], 'accounts' => []];
    private $requests = [];

    public function set_up(){
        parent::set_up();
        update_option('timezone_string', 'Europe/Madrid');
        $this->client = $this->make_client('LCS', 'Luz Clara');
        update_option(MAD_Oficina_Core::option_key('ads'), ['google_mcc' => '111-222-3333', 'google_dev_token' => Crypto::encrypt('dev-token-inventado'), 'meta_token' => Crypto::encrypt('meta-token-inventado')]);
        $google = MAD_Oficina_Core::instance()->get_module('google')->oauth();
        update_option(MAD_Oficina_Core::option_key('google'), ['refresh_token' => Crypto::encrypt('refresh'), 'access_token' => Crypto::encrypt('access'),
            'expires_at' => time() + 3600, 'scopes' => $google->required_scopes()]);
        delete_option(Reasons::OPTION);
        add_filter('pre_http_request', [$this, 'fake'], 10, 3);
    }

    public function tear_down(){
        remove_filter('pre_http_request', [$this, 'fake']);
        parent::tear_down();
    }

    public function fake($pre, $args, $url){
        $ok = fn($body) => ['response' => ['code' => 200], 'body' => wp_json_encode($body)];
        if ( strpos($url, 'https://googleads.googleapis.com/') === 0 ) {
            $this->requests[] = ['url' => $url, 'method' => $args['method'] ?? 'POST', 'headers' => $args['headers']];
            $q = json_decode($args['body'], true)['query'];
            if ( str_contains($q, 'FROM customer_client') ) return $ok(['results' => $this->g['accounts']]);
            if ( str_contains($q, 'FROM change_event') ) return $ok(['results' => $this->g['changes']]);
            if ( str_contains($q, 'segments.date') ) return $ok(['results' => $this->g['metrics']]);
            if ( str_contains($q, 'FROM campaign') ) return $ok(['results' => $this->g['campaigns']]);
        }
        if ( strpos($url, 'https://graph.facebook.com/') === 0 ) {
            $this->requests[] = ['url' => $url, 'method' => $args['method'] ?? 'GET', 'headers' => $args['headers']];
            $path = wp_parse_url($url, PHP_URL_PATH);
            foreach ( ['adaccounts' => 'accounts', 'campaigns' => 'campaigns', 'insights' => 'insights', 'activities' => 'activities'] as $edge => $k ) {
                if ( str_ends_with($path, '/' . $edge) ) return $ok(['data' => $this->m[$k]]);
            }
        }
        return $pre;
    }

    private function sync(){ return new Sync(get_option(MAD_Oficina_Core::option_key('ads'))); }

    private function gcampaign($id, $name, $budget, $status = 'ENABLED'){
        return ['campaign' => ['id' => $id, 'name' => $name, 'status' => $status, 'advertisingChannelType' => 'SEARCH', 'biddingStrategyType' => 'MAXIMIZE_CONVERSIONS',
            'campaignBudget' => 'customers/5550001111/campaignBudgets/9' . $id], 'campaignBudget' => ['amountMicros' => (string) ($budget * 1000000)]];
    }

    private function gmetric($id, $date, $spend, $conv){
        return ['campaign' => ['id' => $id], 'segments' => ['date' => $date], 'metrics' => ['impressions' => '1000', 'clicks' => '50', 'costMicros' => (string) ($spend * 1000000), 'conversions' => $conv, 'conversionsValue' => 0]];
    }

    public function test_the_ads_permission_is_asked_only_with_a_developer_token(){
        $oauth = MAD_Oficina_Core::instance()->get_module('google')->oauth();
        $this->assertContains(GoogleAds::SCOPE, $oauth->required_scopes());
        update_option(MAD_Oficina_Core::option_key('ads'), []);
        $this->assertNotContains(GoogleAds::SCOPE, $oauth->required_scopes(), 'no token yet: Google is not asked to reconnect');
    }

    public function test_google_accounts_are_found_and_linked_by_their_name(){
        $this->g['accounts'] = [
            ['customerClient' => ['id' => '5550001111', 'descriptiveName' => 'LCS - Luz Clara Search', 'currencyCode' => 'EUR', 'status' => 'ENABLED', 'manager' => false]],
            ['customerClient' => ['id' => '5550002222', 'descriptiveName' => 'Cuenta de pruebas', 'currencyCode' => 'EUR', 'status' => 'ENABLED', 'manager' => false]],
        ];
        $r = $this->sync()->discover();
        $this->assertSame(2, $r['found']);
        $linked = Accounts::all(['linked' => true]);
        $this->assertCount(1, $linked);
        $this->assertSame($this->client, (int) $linked[0]['client_id']);
        $req = $this->requests[0];
        $this->assertSame('1112223333', $req['headers']['login-customer-id'], 'through the MCC');
        $this->assertStringContainsString('/customers/1112223333/googleAds:search', $req['url']);
    }

    public function test_google_changes_with_their_values_who_and_what_is_automatic(){
        $acc = Accounts::get(Accounts::upsert('google', ['external_id' => '5550001111', 'name' => 'Luz Clara', 'currency' => 'EUR']));
        Accounts::link($acc['id'], $this->client);
        $acc = Accounts::get($acc['id']);
        $this->g['campaigns'] = [$this->gcampaign('101', 'Rebajas', 35), $this->gcampaign('102', 'Marca', 10)];
        foreach ( range(1, 9) as $d ) $this->g['metrics'][] = $this->gmetric('101', sprintf('2026-10-%02d', $d), 20, 2);
        $this->g['changes'] = [
            ['changeEvent' => ['resourceName' => 'customers/5550001111/changeEvents/1', 'changeDateTime' => '2026-10-10 09:31:02.123', 'changeResourceType' => 'CAMPAIGN_BUDGET',
                'changeResourceName' => 'customers/5550001111/campaignBudgets/9101', 'clientType' => 'GOOGLE_ADS_WEB_CLIENT', 'userEmail' => 'jessica@mad.example',
                'resourceChangeOperation' => 'UPDATE', 'changedFields' => 'amountMicros', 'oldResource' => ['campaignBudget' => ['amountMicros' => '20000000']], 'newResource' => ['campaignBudget' => ['amountMicros' => '35000000']]]],
            ['changeEvent' => ['resourceName' => 'customers/5550001111/changeEvents/2', 'changeDateTime' => '2026-10-10 09:32:00', 'changeResourceType' => 'CAMPAIGN',
                'changeResourceName' => 'customers/5550001111/campaigns/101', 'campaign' => 'customers/5550001111/campaigns/101', 'clientType' => 'GOOGLE_ADS_WEB_CLIENT', 'userEmail' => 'jessica@mad.example',
                'resourceChangeOperation' => 'UPDATE', 'changedFields' => 'status,name', 'oldResource' => ['campaign' => ['status' => 'PAUSED', 'name' => 'Rebajas v1']], 'newResource' => ['campaign' => ['status' => 'ENABLED', 'name' => 'Rebajas']]]],
            ['changeEvent' => ['resourceName' => 'customers/5550001111/changeEvents/3', 'changeDateTime' => '2026-10-11 03:00:00', 'changeResourceType' => 'AD_GROUP_CRITERION',
                'changeResourceName' => 'customers/5550001111/adGroupCriteria/1~2', 'campaign' => 'customers/5550001111/campaigns/102', 'clientType' => 'GOOGLE_ADS_RECOMMENDATIONS', 'userEmail' => '',
                'resourceChangeOperation' => 'CREATE', 'changedFields' => '', 'newResource' => ['adGroupCriterion' => ['keyword' => ['text' => 'luz clara']]]]],
        ];
        $r = $this->sync()->account($acc, '2026-10-17');
        $this->assertSame(['campaigns' => 2, 'days' => 9, 'changes' => 4], $r);
        foreach ( $this->requests as $q ) $this->assertStringEndsWith('googleAds:search', $q['url'], 'read-only: only searches');

        $log = Changes::find(['client_id' => $this->client], 50);
        $budget = array_values(array_filter($log, fn($c) => $c['object_type'] === 'campaign_budget'))[0];
        $this->assertSame('Rebajas', $budget['campaign_name'], 'a budget change is found through the campaign that uses it');
        $this->assertSame(['20', '35'], [$budget['old_value'], $budget['new_value']]);
        $this->assertStringContainsString('presupuesto diario 20,00 € → 35,00 €', $budget['summary']);
        $this->assertSame('jessica@mad.example', $budget['actor']);
        $this->assertSame(7, $budget['metrics_before']['days'], 'the week before is kept');
        $status = array_values(array_filter($log, fn($c) => $c['field'] === 'status'))[0];
        $this->assertStringContainsString('estado en pausa → activa', $status['summary']);
        $auto = array_values(array_filter($log, fn($c) => $c['automatic']));
        $this->assertCount(1, $auto, 'Google\'s recommendations are never asked about');
        $this->assertSame(3, Changes::counts()['pending']);

        // The same history read again the next day: nothing duplicated.
        $acc = Accounts::get($acc['id']);
        $this->assertSame(0, $this->sync()->account($acc, '2026-10-18')['changes']);
    }

    public function test_a_difference_the_history_does_not_explain_is_logged_too(){
        $acc = Accounts::get(Accounts::upsert('google', ['external_id' => '5550001111', 'name' => 'Luz Clara', 'currency' => 'EUR']));
        Accounts::link($acc['id'], $this->client);
        $this->g['campaigns'] = [$this->gcampaign('101', 'Rebajas', 20)];
        $this->sync()->account(Accounts::get($acc['id']), '2026-10-16');
        $this->g['campaigns'] = [$this->gcampaign('101', 'Rebajas', 25, 'PAUSED')];
        $this->sync()->account(Accounts::get($acc['id']), '2026-10-17');
        $log = Changes::find(['client_id' => $this->client]);
        $fields = array_column($log, 'field'); sort($fields);
        $this->assertSame(['budget', 'status'], $fields);
        $this->assertSame('sync', $log[0]['source']);
    }

    public function test_meta_campaigns_insights_and_activity(){
        $this->m['accounts'] = [['id' => 'act_778899', 'account_id' => '778899', 'name' => 'Luz Clara', 'currency' => 'EUR', 'account_status' => 1, 'business_name' => 'Luz Clara SL']];
        $this->sync()->discover();
        $acc = Accounts::all(['platform' => 'meta'])[0];
        $this->assertSame($this->client, (int) $acc['client_id']);
        $this->m['campaigns'] = [['id' => '2385', 'name' => 'Leads otoño', 'status' => 'ACTIVE', 'effective_status' => 'ACTIVE', 'objective' => 'OUTCOME_LEADS', 'daily_budget' => '1500', 'start_time' => '2026-09-01T00:00:00+0200']];
        $this->m['insights'] = [['campaign_id' => '2385', 'date_start' => '2026-10-15', 'impressions' => '3000', 'clicks' => '90', 'spend' => '14.80',
            'actions' => [['action_type' => 'link_click', 'value' => '90'], ['action_type' => 'lead', 'value' => '4'], ['action_type' => 'onsite_conversion.lead_grouped', 'value' => '4']]]];
        $this->m['activities'] = [
            ['event_time' => '2026-10-15T10:00:00+0000', 'event_type' => 'update_campaign_budget', 'translated_event_type' => 'Presupuesto de campaña actualizado', 'actor_name' => 'Jessica Prueba',
                'object_id' => '2385', 'object_name' => 'Leads otoño', 'object_type' => 'CAMPAIGN', 'extra_data' => wp_json_encode(['old_value' => 1000, 'new_value' => 1500, 'type' => 'payment_amount_budget'])],
            ['event_time' => '2026-10-15T11:00:00+0000', 'event_type' => 'ad_review_approved', 'translated_event_type' => 'Anuncio aprobado', 'actor_name' => '',
                'object_id' => '99', 'object_name' => 'Anuncio 1', 'object_type' => 'AD', 'extra_data' => ''],
        ];
        $r = $this->sync()->account($acc, '2026-10-17');
        $this->assertSame(1, $r['days']);
        $c = Accounts::campaigns_of($this->client, '2026-10-17')[0];
        $this->assertSame(['15.00', 'day', '4.00', 'active'], [$c['budget'], $c['budget_period'], $c['conversions'], $c['status']], 'cents → euros; a lead counted once');
        $log = Changes::find(['client_id' => $this->client]);
        $budget = array_values(array_filter($log, fn($x) => ! $x['automatic']))[0];
        $this->assertSame(['10', '15'], [$budget['old_value'], $budget['new_value']]);
        $this->assertSame('Jessica Prueba', $budget['actor']);
        $this->assertCount(1, array_filter($log, fn($x) => $x['automatic']), 'Meta\'s reviews are automatic');
        foreach ( $this->requests as $q ) if ( str_contains($q['url'], 'graph.facebook.com') ) $this->assertSame('GET', $q['method'], 'read-only');
    }

    public function test_a_reason_said_beforehand_waits_for_its_change(){
        $acc = Accounts::get(Accounts::upsert('google', ['external_id' => '5550001111', 'name' => 'Luz Clara', 'currency' => 'EUR']));
        Accounts::link($acc['id'], $this->client);
        $this->g['campaigns'] = [$this->gcampaign('101', 'Rebajas otoño', 20), $this->gcampaign('102', 'Marca', 10)];
        $this->sync()->account(Accounts::get($acc['id']), '2026-10-15');
        Reasons::add($this->client, 'rebajas', 'Convierte a 6 € y sobra presupuesto', 'chat', 0, strtotime('2026-10-15 18:00:00'));
        $this->g['campaigns'] = [$this->gcampaign('101', 'Rebajas otoño', 35), $this->gcampaign('102', 'Marca', 12)];
        $this->sync()->account(Accounts::get($acc['id']), '2026-10-16');
        $log = Changes::find(['client_id' => $this->client]);
        $by = array_column($log, 'reason', 'object_name');
        $this->assertSame('Convierte a 6 € y sobra presupuesto', $by['Rebajas otoño']);
        $this->assertNull($by['Marca'], 'only the campaign she talked about');
        $this->assertSame(1, Changes::counts()['pending']);
    }

    public function test_the_why_is_saved_from_the_log_only_by_a_manager_and_the_week_after_is_measured(){
        $acc = Accounts::get(Accounts::upsert('google', ['external_id' => '5550001111', 'name' => 'Luz Clara', 'currency' => 'EUR']));
        Accounts::link($acc['id'], $this->client);
        $this->g['campaigns'] = [$this->gcampaign('101', 'Rebajas', 20)];
        foreach ( range(1, 16) as $d ) $this->g['metrics'][] = $this->gmetric('101', sprintf('2026-10-%02d', $d), $d < 9 ? 20 : 35, $d < 9 ? 2 : 5);
        $this->sync()->account(Accounts::get($acc['id']), '2026-10-08');
        $this->g['campaigns'] = [$this->gcampaign('101', 'Rebajas', 35)];
        $this->sync()->account(Accounts::get($acc['id']), '2026-10-09');
        $this->sync()->account(Accounts::get($acc['id']), '2026-10-17');
        $this->assertSame(1, Changes::fill_after('2026-10-17'));
        $c = Changes::find(['client_id' => $this->client])[0];
        $this->assertSame('gasto/día 20,00 € → 35,00 € · conv. 14 → 35 · CPA 10,00 € → 7,00 € · CTR 5% → 5%', Changes::effect(Changes::get($c['id'])));

        $call = function($user) use ($c){
            wp_set_current_user($user);
            $_POST = $_REQUEST = ['ids' => (string) $c['id'], 'reason' => 'Prueba de 2 semanas', 'visibility' => 'internal', '_ajax_nonce' => wp_create_nonce('madof_ads_reason')];
            add_filter('wp_doing_ajax', '__return_true');
            $die = fn() => function(){ throw new \RuntimeException('die'); };
            add_filter('wp_die_ajax_handler', $die);
            ob_start();
            try { MAD_Oficina_Core::instance()->get_module('ads')->ajax_reason(); } catch ( \RuntimeException $e ) {}
            $out = json_decode(ob_get_clean(), true);
            remove_filter('wp_die_ajax_handler', $die); remove_filter('wp_doing_ajax', '__return_true');
            $_POST = $_REQUEST = [];
            return $out;
        };
        $this->assertFalse($call($this->make_client_user($this->client))['success'], 'a client never writes here');
        $this->assertNull(Changes::get($c['id'])['reason']);
        $this->assertTrue($call($this->make_admin())['success']);
        $this->assertSame(['Prueba de 2 semanas', 'manual', 'internal'], [Changes::get($c['id'])['reason'], Changes::get($c['id'])['reason_source'], Changes::get($c['id'])['visibility']]);
    }

    public function test_only_linked_accounts_are_read_each_morning(){
        Accounts::upsert('google', ['external_id' => '5550002222', 'name' => 'Sin cliente', 'currency' => 'EUR']);
        $linked = Accounts::upsert('google', ['external_id' => '5550001111', 'name' => 'Luz Clara', 'currency' => 'EUR']);
        Accounts::link($linked, $this->client);
        $this->assertSame(0, $this->sync()->run(strtotime('2026-10-17 05:00:00 Europe/Madrid')), 'not before 6:00');
        $this->assertSame(1, $this->sync()->run(strtotime('2026-10-17 09:00:00 Europe/Madrid')));
        $this->assertSame(0, $this->sync()->run(strtotime('2026-10-17 09:10:00 Europe/Madrid')), 'once a day');
    }

    private function report($csv){
        $f = get_temp_dir() . 'meta-' . wp_generate_password(6, false) . '.csv';
        file_put_contents($f, $csv);
        return $f;
    }

    public function test_meta_reports_from_ads_manager_fill_campaigns_metrics_and_the_log(){
        $head = '"Inicio del informe";"Fin del informe";"Nombre de la cuenta";"Identificador de la cuenta";"Nombre de la campaña";"Identificador de la campaña";"Día";"Entrega de la campaña";"Presupuesto de la campaña";"Resultados";"Indicador de resultado";"Importe gastado (EUR)";"Impresiones";"Clics en el enlace"' . "\n";
        $row = fn($day, $budget, $spend, $res, $status = 'Activa') => "\"{$day}\";\"{$day}\";\"Luz Clara\";\"778899\";\"Leads otoño\";\"2385\";\"{$day}\";\"{$status}\";\"{$budget}\";\"{$res}\";\"Clientes potenciales\";\"{$spend}\";\"1.200\";\"35\"\n";
        $csv = $head . $row('2026-10-14', '15,00', '14,80', '3') . $row('2026-10-15', '15,00', '15,20', '4') . '"";"";"";"";"Resultados de 2 campañas";"";"";"";"";"7";"";"30,00";"2.400";"70"' . "\n";
        $r = \MAD_Oficina\Ads\MetaImport::import($this->report($csv), 0, '2026-10-16');
        $this->assertNotWPError($r);
        $this->assertSame(['campaigns' => 1, 'days' => 2, 'changes' => 0, 'from' => '2026-10-14', 'to' => '2026-10-15'], array_diff_key($r, ['accounts' => 1]));
        $c = Accounts::campaigns_of($this->client, '2026-10-16')[0];
        $this->assertSame(['Leads otoño', '15.00', 'active', '30.00', '7.00', '2400', '70'], [$c['name'], $c['budget'], $c['status'], $c['spend'], $c['conversions'], (string) $c['impressions'], (string) $c['clicks']], 'linked by the account name; totals row skipped; 1.200 = 1200');

        // The same days again (overlap) + a new day with another budget: nothing duplicated, the change is logged.
        $csv = $head . $row('2026-10-15', '15,00', '15,20', '4') . $row('2026-10-16', '25,00', '22,00', '6');
        $r = \MAD_Oficina\Ads\MetaImport::import($this->report($csv), 0, '2026-10-17');
        $this->assertSame(1, $r['changes']);
        $this->assertSame('52.00', Accounts::campaigns_of($this->client, '2026-10-17')[0]['spend']);
        $log = Changes::find(['client_id' => $this->client]);
        $this->assertSame(['budget', '15.00', '25', 'import'], [$log[0]['field'], $log[0]['old_value'], $log[0]['new_value'], $log[0]['source']]);
        $this->assertStringContainsString('15,00 € → 25,00 €', $log[0]['summary']);
    }

    public function test_meta_reports_in_english_without_account_id_need_the_client_and_the_day(){
        $csv = "Campaign name,Reporting starts,Reporting ends,Amount spent (EUR),Impressions,Link clicks,Results\nSpring sale,2026-10-01,2026-10-07,70.50,5000,120,9\n";
        $r = \MAD_Oficina\Ads\MetaImport::import($this->report($csv), $this->client);
        $this->assertWPError($r, 'one row for a whole week: she is told to break it down by day');
        $this->assertStringContainsString('Desglose → Por tiempo → Día', $r->get_error_message());

        $csv = "Campaign name,Day,Amount spent (EUR),Impressions,Link clicks,Results\nSpring sale,2026-10-01,10.50,700,20,1\n";
        $this->assertWPError(\MAD_Oficina\Ads\MetaImport::import($this->report($csv), 0), 'no account in the file: she must say whose it is');
        $r = \MAD_Oficina\Ads\MetaImport::import($this->report($csv), $this->client);
        $this->assertNotWPError($r);
        $acc = Accounts::all(['client_id' => $this->client])[0];
        $this->assertSame('import-' . $this->client, $acc['external_id']);
        $this->assertSame(0, $this->sync()->run(strtotime('2026-10-17 09:00:00 Europe/Madrid')), 'imported accounts are never read through the API');
    }
}
