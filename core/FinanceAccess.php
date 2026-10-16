<?php
namespace MAD_Oficina\Core;

if ( ! defined('ABSPATH') ) exit;

/**
 * Who sees Finanzas (billing, expenses, taxes, the accountant's requests).
 *
 * Until someone reserves it, every manager of the office sees it (as before). Once
 * reserved, only the listed users do — even if other people are administrators too.
 * It hides Finanzas inside WordPress; it cannot stop an administrator who edits code,
 * the database or other users' capabilities (only removing the administrator role can).
 */
final class FinanceAccess {

    const OPTION = 'madoficina_finance_users';
    /** Requests that carry money matters (the accountant's). */
    const APPROVAL_TYPES = ['invoice_issue', 'supplier_new', 'expense_review', 'missing_invoice'];

    /** @return int[] */
    public static function users(){
        $ids = get_option(self::OPTION, []);
        return is_array($ids) ? array_values(array_filter(array_map('intval', $ids))) : [];
    }

    public static function reserved(){ return (bool) self::users(); }

    public static function can($user_id = null){
        $user_id = $user_id === null ? get_current_user_id() : (int) $user_id;
        if ( ! $user_id || ! user_can($user_id, Roles::CAP_MANAGE) ) return false;
        return ! self::reserved() || in_array($user_id, self::users(), true);
    }

    /** Only someone who already sees Finanzas can change who does; they always keep it. @return true|\WP_Error */
    public static function set(array $ids){
        $me = get_current_user_id();
        if ( ! self::can($me) ) return new \WP_Error('madof_finance', __('Solo quien ve Finanzas puede cambiar quién la ve.', 'mad-oficina'));
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($id) => user_can($id, Roles::CAP_MANAGE))));
        if ( ! in_array($me, $ids, true) ) $ids[] = $me;
        update_option(self::OPTION, $ids, false);
        return true;
    }

    public static function types(){
        return (array) apply_filters('madoficina_finance_approval_types', self::APPROVAL_TYPES);
    }

    /** A request this person must not see (a money matter, and Finanzas is not theirs). */
    public static function hides(array $approval, $user_id = null){
        return in_array($approval['action_type'] ?? '', self::types(), true) && ! self::can($user_id);
    }

    /** @param array[] $approvals @return array[] the ones this person may see */
    public static function visible(array $approvals, $user_id = null){
        if ( self::can($user_id) ) return $approvals;
        return array_values(array_filter($approvals, fn($a) => ! self::hides($a, $user_id)));
    }

    /** Pending requests this person may see (for counters). */
    public static function pending_count($user_id = null){
        $n = 0;
        foreach ( Approvals::count_by_type() as $type => $c ) if ( self::can($user_id) || ! in_array($type, self::types(), true) ) $n += $c;
        return $n;
    }
}
