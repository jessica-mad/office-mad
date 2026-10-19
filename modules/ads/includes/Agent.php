<?php
namespace MAD_Oficina\Ads;

use MAD_Oficina\Core\Schema;

if ( ! defined('ABSPATH') ) exit;

/**
 * Campañas (the Analista) as one of the assistant's areas: the change log and its whys,
 * campaigns and their numbers. She writes the why and notes changes made by hand; she
 * never touches a campaign (everything here is read-only towards Google and Meta).
 */
final class Agent extends \MAD_Oficina\Chat\Agents\Specialist {

    public function slug(){ return 'campanas'; }
    public function character(){ return 'analista'; }
    public function area(){ return 'Campañas'; }
    public function scope(){ return 'campañas de Google Ads y Meta de cada cliente (estado, presupuesto, gasto, clics, conversiones, CPA), la bitácora de cambios con quién, cuándo y por qué, el antes y después de cada cambio; apunta motivos y cambios hechos a mano'; }
    public function routing(){
        return 'Todo lo de CAMPAÑAS DE ANUNCIOS (Google Ads, Meta): qué se cambió y por qué, apuntar el motivo de un cambio, apuntar un cambio en la bitácora, cómo va una campaña. '
            . 'Ejemplos: «¿por qué bajamos la puja de LCS en septiembre?», «en LCS subí el presupuesto de Rebajas a 35 € porque convertía bien», «apunta en la bitácora de MOM que pausé la campaña de marca», «la A porque…, la B no lo sé» (respuesta al mensaje diario de Nuria), «¿cómo van las campañas de Lanas este mes?».';
    }

    protected function tool_label($tool){
        return ['find_changes' => 'está mirando la bitácora', 'daily_list' => 'está mirando su lista de hoy', 'set_reason' => 'está apuntando el porqué', 'log_change' => 'está apuntando el cambio en la bitácora',
            'campaigns' => 'está mirando las campañas'][$tool] ?? '';
    }

    protected function instructions(){
        return "- Puedes ESCRIBIR en la bitácora: el porqué de cambios que ya están (set_reason) y cambios que Jessica te cuenta que hizo ella (log_change). No tocas campañas: todo es solo lectura hacia Google y Meta.\n"
            . "- Si responde a tu mensaje diario con letras («la A porque…»), usa daily_list para saber qué cambios son cada letra y guarda cada motivo en los suyos. Si de una dice que no lo sabe, no escribas nada en esa.\n"
            . "- Si cuenta un cambio («subí el presupuesto de Rebajas en LCS porque…»): busca primero en la bitácora (find_changes, ese cliente, últimos días). Si ya está, ponle el motivo; si no está, apúntalo con log_change (queda también esperando a que aparezca el cambio real para unirle el motivo).\n"
            . "- El motivo se guarda con sus palabras, resumido si es largo, sin inventar. Solo marca client_visible si lo pide.\n"
            . "- Para «por qué hicimos X»: find_changes y responde con fecha, qué cambió, quién y el motivo apuntado (o «sin motivo apuntado»). El antes/después es una comparación de periodos, no una prueba de causa: dilo así si lo das.\n"
            . "- Di exactamente qué apuntaste y dónde (cliente, campaña, fecha).\n";
    }

    protected function tools(){
        return [
            ['name' => 'find_changes', 'description' => 'Bitácora de cambios de campañas: id, fecha, cliente, plataforma, campaña, qué cambió, quién, motivo apuntado y antes/después. Más recientes primero.',
             'input_schema' => self::obj(['client' => ['type' => 'string', 'description' => 'Código o nombre del cliente'], 'campaign' => ['type' => 'string', 'description' => 'Palabras del nombre de la campaña o del cambio'],
                'from' => ['type' => 'string', 'description' => 'AAAA-MM-DD'], 'to' => ['type' => 'string'], 'without_reason' => ['type' => 'boolean'], 'limit' => ['type' => 'integer']]),
             'handler' => [$this, 'find_changes']],
            ['name' => 'daily_list', 'description' => 'Tu último mensaje diario a Jessica: cada letra (A, B…) con los cambios que agrupa y sus ids.',
             'input_schema' => self::obj([]), 'handler' => [$this, 'daily_list']],
            ['name' => 'set_reason', 'description' => 'Guarda el porqué de uno o varios cambios de la bitácora (por ids, o por letra del mensaje diario).',
             'input_schema' => self::obj(['ids' => ['type' => 'array', 'items' => ['type' => 'integer']], 'letters' => ['type' => 'array', 'items' => ['type' => 'string']],
                'reason' => ['type' => 'string'], 'client_visible' => ['type' => 'boolean', 'description' => 'Solo si lo pide: puede salir en el informe del cliente']], ['reason']),
             'handler' => [$this, 'set_reason']],
            ['name' => 'log_change', 'description' => 'Apunta en la bitácora un cambio que hizo Jessica (con su porqué) y deja el motivo esperando al cambio real cuando se lea de Google o Meta.',
             'input_schema' => self::obj(['client' => ['type' => 'string'], 'platform' => ['type' => 'string', 'enum' => ['google', 'meta', 'other']], 'campaign' => ['type' => 'string', 'description' => 'Nombre (o parte) de la campaña'],
                'what' => ['type' => 'string', 'description' => 'Qué cambió, breve: «presupuesto 20 → 35 €/día»'], 'reason' => ['type' => 'string'], 'date' => ['type' => 'string', 'description' => 'AAAA-MM-DD; hoy si no se dice']],
                ['client', 'what', 'reason']),
             'handler' => [$this, 'log_change']],
            ['name' => 'campaigns', 'description' => 'Campañas de un cliente con estado, presupuesto y los últimos 30 días (gasto, clics, conversiones, CPA) y tus notas de cómo se creó.',
             'input_schema' => self::obj(['client' => ['type' => 'string']], ['client']), 'handler' => [$this, 'campaigns']],
        ];
    }

    private function one_client($q){
        $ids = self::clients_matching($q);
        if ( ! $ids ) return new \WP_Error('madof_ads', sprintf('No encuentro ningún cliente «%s».', $q));
        if ( count($ids) > 1 ) return new \WP_Error('madof_ads', 'Hay varios clientes posibles: ' . implode(', ', array_map([self::class, 'client_label'], $ids)) . '. Di cuál.');
        return $ids[0];
    }

    private static function row(array $c){
        return ['id' => $c['id'], 'date' => substr($c['changed_at'], 0, 16), 'client' => $c['client_code'], 'platform' => Accounts::PLATFORMS[$c['platform']] ?? $c['platform'],
            'campaign' => $c['campaign_name'] ?: $c['object_name'], 'change' => $c['summary'], 'who' => $c['actor'] ?: null, 'automatic' => (bool) $c['automatic'],
            'source' => $c['source'], 'reason' => $c['reason'], 'before_after' => Changes::effect($c) ?: null];
    }

    public function find_changes(array $in){
        $f = ['linked' => true, 'automatic' => false];
        if ( ! empty($in['client']) ) { $c = $this->one_client($in['client']); if ( is_wp_error($c) ) return $c; $f['client_id'] = $c; }
        if ( $d = self::date_arg($in['from'] ?? '') ) $f['from'] = $d;
        if ( $d = self::date_arg($in['to'] ?? '') ) $f['to'] = $d;
        if ( ! empty($in['without_reason']) ) $f['pending'] = true;
        $rows = Changes::find($f, 300);
        if ( ! empty($in['campaign']) ) {
            $words = array_filter(explode(' ', self::norm($in['campaign'])), fn($w) => strlen($w) >= 3);
            $rows = array_values(array_filter($rows, function($r) use ($words){
                $t = self::norm($r['campaign_name'] . ' ' . $r['object_name'] . ' ' . $r['summary'] . ' ' . $r['reason']);
                foreach ( $words as $w ) if ( strpos($t, $w) === false ) return false;
                return true;
            }));
        }
        return ['changes' => array_map([self::class, 'row'], array_slice($rows, 0, self::limit($in['limit'] ?? 0, 15, 40)))];
    }

    public function daily_list(array $in){
        $l = Ask::last();
        if ( ! $l ) return ['list' => [], 'note' => 'No hay mensaje diario reciente.'];
        $out = [];
        foreach ( $l['items'] as $letter => $ids ) $out[] = ['letter' => $letter, 'changes' => array_map(fn($id) => ($c = Changes::get($id)) ? ['id' => $id, 'change' => $c['summary'], 'reason' => $c['reason']] : null, $ids)];
        return ['sent' => $l['date'], 'list' => $out];
    }

    public function set_reason(array $in){
        $ids = array_map('intval', (array) ($in['ids'] ?? []));
        if ( ! empty($in['letters']) ) {
            $l = Ask::last();
            foreach ( (array) $in['letters'] as $letter ) $ids = array_merge($ids, $l['items'][strtoupper(trim((string) $letter))] ?? []);
        }
        $ids = array_values(array_unique(array_filter($ids, fn($id) => ($c = Changes::get($id)) && $c['client_id'])));
        if ( ! $ids ) return new \WP_Error('madof_ads', 'No encuentro esos cambios en la bitácora.');
        if ( class_exists('MAD_Oficina\\Billing\\FinanceUndo') ) $this->undo[] = \MAD_Oficina\Billing\FinanceUndo::snapshot(['ad_changes' => $ids]);
        Changes::set_reason($ids, (string) $in['reason'], 'chat', (int) ($this->ctx['user_id'] ?? 0), ! empty($in['client_visible']) ? 'client' : null);
        return ['saved' => array_map(function($id){ $c = Changes::get($id); return ['id' => $id, 'date' => substr($c['changed_at'], 0, 10), 'change' => $c['summary'], 'reason' => $c['reason'], 'client_visible' => $c['visibility'] === 'client']; }, $ids)];
    }

    public function log_change(array $in){
        $client = $this->one_client((string) $in['client']);
        if ( is_wp_error($client) ) return $client;
        $date = self::date_arg($in['date'] ?? '') ?: current_time('Y-m-d');
        $id = Changes::add_manual($client, (string) ($in['platform'] ?? 'other'), (string) ($in['campaign'] ?? ''), (string) $in['what'], (string) $in['reason'], $date, (int) ($this->ctx['user_id'] ?? 0));
        if ( is_wp_error($id) ) return $id;
        if ( class_exists('MAD_Oficina\\Billing\\FinanceUndo') ) $this->undo[] = \MAD_Oficina\Billing\FinanceUndo::snapshot([], ['ad_changes' => [$id]]);
        // The real change, when Google or Meta show it, takes the same why.
        Reasons::add($client, (string) ($in['campaign'] ?? ''), (string) $in['reason'], 'chat', (int) ($this->ctx['user_id'] ?? 0));
        $c = Changes::get($id);
        return ['logged' => ['id' => $id, 'client' => self::client_label($client), 'date' => $date, 'campaign' => $c['object_name'], 'change' => $c['summary'], 'reason' => $c['reason']],
            'link' => admin_url('admin.php?page=mad-oficina-campanas&tab=log&client=' . $client)];
    }

    public function campaigns(array $in){
        $client = $this->one_client((string) $in['client']);
        if ( is_wp_error($client) ) return $client;
        $rows = Accounts::campaigns_of($client);
        if ( ! $rows ) return ['campaigns' => [], 'note' => 'Este cliente no tiene campañas leídas (sin cuentas vinculadas ni informes de Meta subidos).'];
        return ['client' => self::client_label($client), 'period' => 'últimos 30 días', 'campaigns' => array_map(fn($c) => [
            'platform' => Accounts::PLATFORMS[$c['platform']] ?? $c['platform'], 'name' => $c['name'], 'status' => $c['status'], 'budget' => $c['budget'] !== null ? (float) $c['budget'] . ' €/' . ($c['budget_period'] === 'day' ? 'día' : 'total') : null,
            'spend' => round((float) $c['spend'], 2), 'clicks' => (int) $c['clicks'], 'conversions' => round((float) $c['conversions'], 2),
            'cpa' => (float) $c['conversions'] > 0 ? round((float) $c['spend'] / (float) $c['conversions'], 2) : null, 'notes' => $c['notes'],
        ], $rows)];
    }

    private static function norm($s){ return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]+/', ' ', strtolower(remove_accents((string) $s))))); }
}
