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

    public function test_nuria_asks_for_the_meta_report_once_a_day_when_it_stops_arriving(){
        update_option(MAD_Oficina_Core::option_key('ads'), ['google_mcc' => '111-222-3333', 'google_dev_token' => Crypto::encrypt('dev-token-inventado')]); // no Meta API
        openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048]), $key);
        update_option(MAD_Oficina_Core::option_key('chat'), ['manager_email' => 'jessica@imad.example', 'manager_dm' => 'spaces/JESSDM',
            'service_account' => Crypto::encrypt(wp_json_encode(['type' => 'service_account', 'client_email' => 'bot@proj.iam.gserviceaccount.com', 'private_key' => $key, 'token_uri' => 'https://oauth2.googleapis.com/token']))]);
        delete_option(\MAD_Oficina\Ads\MetaImport::ASKED);
        $sent = [];
        $chat = function($pre, $args, $url) use (&$sent){
            if ( $url === 'https://oauth2.googleapis.com/token' ) return ['response' => ['code' => 200], 'body' => wp_json_encode(['access_token' => 't', 'expires_in' => 3600])];
            if ( strpos($url, 'https://chat.googleapis.com/') === 0 ) { $sent[] = json_decode($args['body'], true)['text'] ?? ''; return ['response' => ['code' => 200], 'body' => '{"name":"spaces/JESSDM/messages/1"}']; }
            return $pre;
        };
        add_filter('pre_http_request', $chat, 5, 3);
        $csv = "Nombre de la cuenta;Identificador de la cuenta;Nombre de la campaña;Día;Importe gastado (EUR)\nLuz Clara;778899;Leads;2026-10-14;10,00\n";
        \MAD_Oficina\Ads\MetaImport::import($this->report($csv), 0);
        $settings = get_option(MAD_Oficina_Core::option_key('ads'));
        $this->assertFalse(\MAD_Oficina\Ads\MetaImport::remind($settings, strtotime('2026-10-15 11:00:00 Europe/Madrid')), 'data up to yesterday: nothing to ask');
        $this->assertFalse(\MAD_Oficina\Ads\MetaImport::remind($settings, strtotime('2026-10-17 08:00:00 Europe/Madrid')), 'not before 10:00');
        $this->assertTrue(\MAD_Oficina\Ads\MetaImport::remind($settings, strtotime('2026-10-17 11:00:00 Europe/Madrid')));
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('Luz Clara (hasta el 14/10)', $sent[0]);
        $this->assertStringContainsString('tab=meta', $sent[0]);
        $this->assertFalse(\MAD_Oficina\Ads\MetaImport::remind($settings, strtotime('2026-10-17 15:00:00 Europe/Madrid')), 'once a day');
        remove_filter('pre_http_request', $chat, 5);
    }

    public function test_searching_accounts_says_what_is_missing_for_each_platform(){
        $google = MAD_Oficina_Core::instance()->get_module('google')->oauth();
        update_option(MAD_Oficina_Core::option_key('google'), ['refresh_token' => Crypto::encrypt('refresh'), 'access_token' => Crypto::encrypt('access'), 'expires_at' => time() + 3600, 'scopes' => ['openid', 'email']]);
        $r = $this->sync()->discover('google');
        $this->assertSame(0, $r['found']);
        $this->assertStringContainsString('Reconectar', $r['errors'][0], 'the Ads permission is missing: she is told to reconnect');
        $this->assertSame([], $this->requests, 'nothing sent without the permission');

        update_option(MAD_Oficina_Core::option_key('google'), ['refresh_token' => Crypto::encrypt('refresh'), 'access_token' => Crypto::encrypt('access'), 'expires_at' => time() + 3600, 'scopes' => $google->required_scopes()]);
        $deny = fn($pre, $args, $url) => strpos($url, 'https://googleads.googleapis.com/') === 0 ? ['response' => ['code' => 403], 'body' => wp_json_encode(['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED',
            'details' => [['errors' => [['errorCode' => ['authorizationError' => 'DEVELOPER_TOKEN_NOT_APPROVED'], 'message' => 'The developer token is only approved for use with test accounts.']]]]]])] : $pre;
        add_filter('pre_http_request', $deny, 20, 3); // after the general fake
        $r = $this->sync()->discover('google');
        remove_filter('pre_http_request', $deny, 20);
        $this->assertStringContainsString('acceso de prueba', $r['errors'][0]);

        $this->g['accounts'] = [];
        $this->assertStringContainsString('no tiene cuentas de cliente', $this->sync()->discover('google')['errors'][0], 'an empty MCC is said, not silent');
    }

    private function chat_fake(array &$sent){
        openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048]), $key);
        update_option(MAD_Oficina_Core::option_key('chat'), ['manager_email' => 'jessica@imad.example', 'manager_dm' => 'spaces/JESSDM',
            'service_account' => Crypto::encrypt(wp_json_encode(['type' => 'service_account', 'client_email' => 'bot@proj.iam.gserviceaccount.com', 'private_key' => $key, 'token_uri' => 'https://oauth2.googleapis.com/token']))]);
        $f = function($pre, $args, $url) use (&$sent){
            if ( $url === 'https://oauth2.googleapis.com/token' ) return ['response' => ['code' => 200], 'body' => wp_json_encode(['access_token' => 't', 'expires_in' => 3600])];
            if ( strpos($url, 'https://chat.googleapis.com/') === 0 ) { $sent[] = json_decode($args['body'], true)['text'] ?? ''; return ['response' => ['code' => 200], 'body' => '{"name":"spaces/JESSDM/messages/1"}']; }
            return $pre;
        };
        add_filter('pre_http_request', $f, 5, 3);
        return $f;
    }

    public function test_nuria_asks_the_why_once_with_letters_and_nora_can_answer_it(){
        // Relative to today: her daily message only counts for a week.
        $d0 = wp_date('Y-m-d', time() - 2 * DAY_IN_SECONDS); $d1 = wp_date('Y-m-d', time() - DAY_IN_SECONDS); $d2 = wp_date('Y-m-d');
        $acc = Accounts::get(Accounts::upsert('google', ['external_id' => '5550001111', 'name' => 'Luz Clara', 'currency' => 'EUR']));
        Accounts::link($acc['id'], $this->client);
        $this->g['campaigns'] = [$this->gcampaign('101', 'Rebajas', 20), $this->gcampaign('102', 'Marca', 10)];
        $this->sync()->account(Accounts::get($acc['id']), $d0);
        $this->g['campaigns'] = [$this->gcampaign('101', 'Rebajas', 35, 'PAUSED'), $this->gcampaign('102', 'Marca', 12)];
        $this->sync()->account(Accounts::get($acc['id']), $d1);
        delete_option(\MAD_Oficina\Ads\Ask::DAY);
        $sent = []; $f = $this->chat_fake($sent);
        $this->assertFalse(\MAD_Oficina\Ads\Ask::run([], strtotime($d1 . ' 08:00:00 Europe/Madrid')), 'not before 9:00');
        $this->assertTrue(\MAD_Oficina\Ads\Ask::run([], strtotime($d1 . ' 09:30:00 Europe/Madrid')));
        $this->assertCount(1, $sent);
        $this->assertMatchesRegularExpression('/A\. (Marca|Rebajas) \(Google Ads, ' . preg_quote(wp_date('j/m', strtotime($d1)), '/') . '\)/', $sent[0]);
        $this->assertStringContainsString('B. ', $sent[0]);
        $this->assertStringContainsString('LCS · Luz Clara', $sent[0]);
        $this->assertStringContainsString('la A porque', \MAD_Oficina\Ads\Ask::context(), 'Nora knows what «la A» is');
        delete_option(\MAD_Oficina\Ads\Ask::DAY);
        $this->assertFalse(\MAD_Oficina\Ads\Ask::run([], strtotime($d2 . ' 09:30:00 Europe/Madrid')), 'each change is asked once');
        remove_filter('pre_http_request', $f, 5);

        // «la A porque…»: Nuria saves it in every change of A, with «Deshacer».
        $agent = new \MAD_Oficina\Ads\Agent(['is_manager' => true, 'user_id' => 0, 'member' => null, 'email' => 'jessica@imad.example']);
        $letterA = \MAD_Oficina\Ads\Ask::last()['items']['A'];
        $r = $agent->set_reason(['letters' => ['a'], 'reason' => 'Convertía a 6 € y sobraba presupuesto']);
        $this->assertCount(count($letterA), $r['saved']);
        foreach ( $letterA as $id ) $this->assertSame(['Convertía a 6 € y sobraba presupuesto', 'chat'], [Changes::get($id)['reason'], Changes::get($id)['reason_source']]);
        $this->assertCount(1, $agent->undo);
        $this->assertSame('Bitácora de campañas', \MAD_Oficina\Billing\FinanceUndo::area($agent->undo[0]), 'the «Deshecho» card names the right area');
        \MAD_Oficina\Billing\FinanceUndo::restore($agent->undo[0]);
        $this->assertNull(Changes::get($letterA[0])['reason'], '«Deshacer» leaves it as it was');
        $this->assertTrue(in_array('campanas', array_map(fn($a) => $a->slug(), (new \MAD_Oficina\Chat\Orchestrator([], ['is_manager' => true, 'user_id' => 0, 'member' => null, 'email' => 'x'], null))->agents()), true));
    }

    public function test_a_change_she_tells_nora_goes_in_the_log_and_waits_for_the_real_one(){
        $acc = Accounts::get(Accounts::upsert('google', ['external_id' => '5550001111', 'name' => 'Luz Clara', 'currency' => 'EUR']));
        Accounts::link($acc['id'], $this->client);
        $this->g['campaigns'] = [$this->gcampaign('101', 'Rebajas otoño', 20)];
        $this->sync()->account(Accounts::get($acc['id']), '2026-10-15');
        $agent = new \MAD_Oficina\Ads\Agent(['is_manager' => true, 'user_id' => 0, 'member' => null, 'email' => 'jessica@imad.example']);
        $r = $agent->log_change(['client' => 'LCS', 'platform' => 'google', 'campaign' => 'rebajas', 'what' => 'presupuesto 20 → 35 €/día', 'reason' => 'Convierte bien', 'date' => current_time('Y-m-d')]);
        $this->assertSame('Rebajas otoño', $r['logged']['campaign'], 'joined to the campaign by its name');
        $manual = Changes::get($r['logged']['id']);
        $this->assertSame(['manual', 'Convierte bien'], [$manual['source'], $manual['reason']]);
        $this->assertStringContainsString('presupuesto 20 → 35 €/día', $manual['summary']);
        $found = $agent->find_changes(['client' => 'LCS', 'campaign' => 'rebajas']);
        $this->assertSame($r['logged']['id'], $found['changes'][0]['id']);

        $this->g['campaigns'] = [$this->gcampaign('101', 'Rebajas otoño', 35)];
        $this->sync()->account(Accounts::get($acc['id']), current_time('Y-m-d'));
        $this->assertCount(1, Changes::find(['client_id' => $this->client]), 'the real change joins the one she told: no duplicate');
        $this->assertSame(['20.00', '35'], [Changes::get($r['logged']['id'])['old_value'], Changes::get($r['logged']['id'])['new_value']], 'with the real values');

        \MAD_Oficina\Billing\FinanceUndo::restore($agent->undo[0]);
        $this->assertNull(Changes::get($r['logged']['id']), '«Deshacer» removes the entry');
    }

    private static function png(){
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    }

    public function test_images_in_the_log_from_the_web_and_from_chat(){
        $acc = Accounts::get(Accounts::upsert('google', ['external_id' => '5550001111', 'name' => 'Luz Clara', 'currency' => 'EUR']));
        Accounts::link($acc['id'], $this->client);
        $this->g['campaigns'] = [$this->gcampaign('101', 'Rebajas', 20)];
        $this->sync()->account(Accounts::get($acc['id']), '2026-10-15');
        $id = Changes::add_manual($this->client, 'google', 'rebajas', 'presupuesto 20 → 35', 'Convierte', current_time('Y-m-d'));

        $this->assertWPError(\MAD_Oficina\Ads\Attachments::add($id, '<?php echo 1; ?>', 'x.png'), 'only real images, whatever the name says');
        $a = \MAD_Oficina\Ads\Attachments::add($id, self::png(), 'captura antes.png');
        $this->assertSame('image/png', $a['type']);
        $this->assertFileExists(\MAD_Oficina\Ads\Attachments::dir() . '/' . $a['f']);
        $this->assertFileExists(\MAD_Oficina\Ads\Attachments::dir() . '/.htaccess', 'the folder is not web-readable');
        $this->assertCount(1, \MAD_Oficina\Ads\Attachments::of($id));

        // Served only to the office.
        wp_set_current_user($this->make_client_user($this->client));
        $_GET = $_REQUEST = ['change' => $id, 'f' => $a['f'], '_wpnonce' => wp_create_nonce('madof_ads_file_' . $id)];
        try { \MAD_Oficina\Ads\Attachments::serve(); $this->fail('a client never sees it'); } catch ( WPDieException $e ) { $this->assertStringContainsString('permisos', $e->getMessage()); }
        $_GET = $_REQUEST = [];

        // From Chat: the image waits until Nuria knows where it goes.
        $token = apply_filters('madoficina_chat_image', null, self::png(), 'ads.png');
        $this->assertIsString($token);
        $agent = new \MAD_Oficina\Ads\Agent(['is_manager' => true, 'user_id' => 0, 'member' => null, 'email' => 'jessica@imad.example', 'images' => [$token]]);
        $r = $agent->attach_images(['id' => $id]);
        $this->assertSame(1, $r['attached']);
        $this->assertCount(2, \MAD_Oficina\Ads\Attachments::of($id));
        $r = $agent->log_change(['client' => 'LCS', 'what' => 'pausé la de marca', 'reason' => 'sin stock', 'attach_images' => true]);
        $this->assertSame(1, $r['images_attached']);
        $this->assertWPError((new \MAD_Oficina\Ads\Agent(['is_manager' => true, 'user_id' => 0, 'member' => null, 'email' => 'x']))->attach_images(['id' => $id]), 'no images in the message: nothing to attach');

        $this->assertTrue(\MAD_Oficina\Ads\Attachments::remove($id, $a['f']));
        $this->assertFileDoesNotExist(\MAD_Oficina\Ads\Attachments::dir() . '/' . $a['f']);
        $this->assertSame([['resource' => 'spaces/X/messages/Y/attachments/Z', 'name' => 'captura.png']],
            \MAD_Oficina\Chat\Transcriber::images_of(['attachment' => [['contentType' => 'image/png', 'contentName' => 'captura.png', 'attachmentDataRef' => ['resourceName' => 'spaces/X/messages/Y/attachments/Z']], ['contentType' => 'audio/mp4', 'attachmentDataRef' => ['resourceName' => 'a']]]]));
    }
}
