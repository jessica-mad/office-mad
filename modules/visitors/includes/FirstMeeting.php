<?php
namespace MAD_Oficina\Visitors;

use MAD_Oficina\Core\Approvals;
use MAD_Oficina\Core\Time;

if ( ! defined('ABSPATH') ) exit;

/**
 * First meeting for visitors: real free slots (Agenda module) → the visitor
 * confirms the email → request in the inbox (always asks, never autonomous)
 * → on approval, live re-check and Calendar event with Meet.
 */
final class FirstMeeting {

    const ACTION   = 'first_meeting';
    const AGENT    = 'scheduler';
    const DURATION = 30;
    const OFFER    = 6;
    const PER_DAY  = 2;

    /** @return \MAD_Oficina\Scheduling\Requests|null */
    public static function requests(){
        $m = \MAD_Oficina_Core::instance()->get_module('scheduling');
        return $m ? $m->requests() : null;
    }

    public static function available(){
        return self::requests() && class_exists('MAD_Oficina\\Scheduling\\Calendar') && \MAD_Oficina\Scheduling\Calendar::is_ready();
    }

    /**
     * Slots offered to visitors. Kept for one minute so a busy public page does
     * not hit Google on every visit; the slot is checked live again on submit
     * and on approval.
     * @return array|\WP_Error
     */
    public static function offer(){
        if ( ! self::available() ) return new \WP_Error('madof_fm_off', __('Ahora mismo no puedo enseñarte la agenda. Déjanos un brief y te proponemos fecha.', 'mad-oficina'), ['status' => 503]);
        $cached = get_transient('madof_public_slots');
        if ( is_array($cached) ) return $cached;
        $req = self::requests();
        $slots = $req->free_slots(self::DURATION);
        if ( is_wp_error($slots) ) return new \WP_Error('madof_fm_cal', __('Ahora mismo no puedo consultar la agenda. Inténtalo en un rato o déjanos un brief.', 'mad-oficina'), ['status' => 503]);
        $out = $req->present(\MAD_Oficina\Scheduling\Slots::offer($slots, self::OFFER, wp_timezone()));
        set_transient('madof_public_slots', $out, MINUTE_IN_SECONDS);
        return $out;
    }

    /**
     * Visitor's request: stored as an unconfirmed lead until the email is confirmed.
     * @return array|\WP_Error ['lead' => id, 'token' => plain token, 'label' => slot label]
     */
    public static function submit(array $p){
        $name = mb_substr(sanitize_text_field($p['name'] ?? ''), 0, 120);
        $email = sanitize_email($p['email'] ?? '');
        if ( $name === '' ) return new \WP_Error('madof_fm', __('Dinos tu nombre.', 'mad-oficina'), ['status' => 400]);
        if ( ! is_email($email) ) return new \WP_Error('madof_fm', __('Revisa el email: ahí te llegará la invitación.', 'mad-oficina'), ['status' => 400]);
        if ( ! self::available() ) return new \WP_Error('madof_fm_off', __('Ahora mismo no puedo reservar. Déjanos un brief y te proponemos fecha.', 'mad-oficina'), ['status' => 503]);

        $start = (int) ($p['start'] ?? 0);
        $slots = self::requests()->free_slots(self::DURATION); // live, not the cached offer
        if ( is_wp_error($slots) ) return new \WP_Error('madof_fm_cal', __('Ahora mismo no puedo consultar la agenda. Inténtalo en un rato.', 'mad-oficina'), ['status' => 503]);
        $slot = null;
        foreach ( $slots as $s ) if ( (int) $s['start'] === $start ) $slot = $s;
        if ( ! $slot ) {
            delete_transient('madof_public_slots');
            return new \WP_Error('madof_fm_taken', __('Ese hueco acaba de ocuparse. Elige otro, por favor.', 'mad-oficina'), ['status' => 409]);
        }
        if ( ! Guard::allow('first-meeting', self::PER_DAY) || ! Guard::allow('first-meeting-email', self::PER_DAY, DAY_IN_SECONDS, strtolower($email)) ) {
            return new \WP_Error('madof_limit', __('Ya tenemos tu petición. Revisa tu email para confirmarla.', 'mad-oficina'), ['status' => 429]);
        }

        $token = Leads::new_token();
        $id = Leads::create([
            'name' => $name, 'email' => $email,
            'company' => mb_substr(sanitize_text_field($p['company'] ?? ''), 0, 160),
            'message' => mb_substr(sanitize_textarea_field($p['topic'] ?? ''), 0, 1000),
            'source' => 'meeting', 'meeting_start' => $slot['start'], 'meeting_end' => $slot['end'],
            'verify_hash' => hash('sha256', $token), 'consent_at' => Time::now(), 'ip_hash' => Guard::ip_hash(),
        ]);
        return ['lead' => $id, 'token' => $token, 'label' => self::label($slot['start'])];
    }

    public static function label($ts){
        return \MAD_Oficina\Scheduling\Slots::label((int) $ts, wp_timezone());
    }

    /** Email confirmed → request in Jessica's inbox (and her Chat). Never runs on its own. */
    public static function on_verified(array $lead){
        if ( $lead['source'] !== 'meeting' || ! $lead['meeting_start'] || $lead['approval_id'] ) return;
        $who = $lead['name'] . ($lead['company'] ? ' (' . $lead['company'] . ')' : '');
        $id = Approvals::create(self::AGENT, self::ACTION,
            sprintf(__('Primera reunión: %1$s · %2$s', 'mad-oficina'), $who, self::label($lead['meeting_start'])),
            ['lead_id' => $lead['id'], 'name' => $lead['name'], 'email' => $lead['email'], 'company' => $lead['company'],
             'start' => $lead['meeting_start'], 'end' => $lead['meeting_end'], 'topic' => (string) $lead['message']],
            ['summary' => $lead['message'] ?: __('Sin tema indicado.', 'mad-oficina'), 'object_type' => 'lead', 'object_id' => $lead['id']]
        );
        Leads::update($lead['id'], ['approval_id' => $id]);
    }

    /** @return array|\WP_Error */
    public static function approve(array $approval, array $input){
        $p = $approval['payload'];
        $req = self::requests();
        if ( ! $req ) return new \WP_Error('madof_fm_off', __('Activa el módulo Agenda para crear la reunión.', 'mad-oficina'));
        $tz = wp_timezone();
        $start = (int) $p['start'];
        if ( ! empty($input['start']) ) {
            try { $start = (new \DateTimeImmutable($input['start'], $tz))->getTimestamp(); } catch ( \Exception $e ) {}
        }
        $duration = max(15, min(240, (int) ($input['duration'] ?? round(((int) $p['end'] - (int) $p['start']) / 60))));
        $end = $start + $duration * 60;
        $free = $req->check_free($start, $end);
        if ( is_wp_error($free) ) return $free;

        $attendees = [];
        foreach ( preg_split('/[\s,;]+/', (string) ($input['attendees'] ?? $p['email']), -1, PREG_SPLIT_NO_EMPTY) as $e ) {
            if ( is_email($e) ) $attendees[] = strtolower($e);
        }
        $topic = sanitize_textarea_field($input['topic'] ?? $p['topic']);
        $event = \MAD_Oficina\Scheduling\Calendar::create_meeting(
            sprintf('%s x MAD · %s', $p['company'] ?: $p['name'], __('Primera reunión', 'mad-oficina')),
            trim($topic . "\n\n" . __('Primera reunión pedida desde la web de MAD.', 'mad-oficina')),
            $start, $end, $attendees
        );
        if ( is_wp_error($event) ) return $event;
        $lead = Leads::get((int) $p['lead_id']);
        if ( $lead ) {
            Leads::update($lead['id'], ['status' => $lead['status'] === 'new' ? 'contacted' : $lead['status'],
                'notes' => trim((string) $lead['notes'] . "\n" . sprintf(__('Primera reunión creada: %s', 'mad-oficina'), self::label($start)))]);
        }
        return [
            'message'  => sprintf(__('Primera reunión creada para %1$s con %2$s. Google ha enviado la invitación.', 'mad-oficina'), self::label($start), $p['name']),
            'event_id' => $event['id'] ?? null,
            'meet'     => $event['hangoutLink'] ?? null,
            'start'    => $start,
        ];
    }

    public static function render_form($html, array $approval){
        $p = $approval['payload'];
        $lead_url = admin_url('admin.php?page=mad-oficina-leads&lead=' . (int) $p['lead_id']);
        ob_start(); ?>
        <p class="description"><?php printf(esc_html__('Aún no es cliente: ha confirmado su email (%s).', 'mad-oficina'), esc_html($p['email'])); ?> <a href="<?php echo esc_url($lead_url); ?>"><?php esc_html_e('Ver lead', 'mad-oficina'); ?></a></p>
        <div class="madof-cols">
            <label><?php esc_html_e('Fecha y hora', 'mad-oficina'); ?>
                <input type="datetime-local" name="input[start]" value="<?php echo esc_attr(wp_date('Y-m-d\TH:i', (int) $p['start'], wp_timezone())); ?>"></label>
            <label><?php esc_html_e('Duración (min)', 'mad-oficina'); ?>
                <input type="number" min="15" max="240" name="input[duration]" value="<?php echo esc_attr((int) round(((int) $p['end'] - (int) $p['start']) / 60)); ?>"></label>
            <label><?php esc_html_e('Invitados', 'mad-oficina'); ?>
                <input type="text" name="input[attendees]" value="<?php echo esc_attr($p['email']); ?>"></label>
        </div>
        <label><?php esc_html_e('Tema', 'mad-oficina'); ?><textarea name="input[topic]" rows="2"><?php echo esc_textarea((string) $p['topic']); ?></textarea></label>
        <p class="description"><?php esc_html_e('Al aprobar compruebo otra vez tu calendario, creo el evento con Meet y Google le envía la invitación. Si descartas, no le llega nada: escríbele tú desde el lead.', 'mad-oficina'); ?></p>
        <?php
        return ob_get_clean();
    }
}
