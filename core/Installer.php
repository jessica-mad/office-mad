<?php
namespace MAD_Oficina\Core;

if ( ! defined('ABSPATH') ) exit;

/**
 * Creates/updates tables and roles. Idempotent: safe to run on every deploy.
 */
final class Installer {

    /** Bump whenever Schema or Roles change. */
    const DB_VERSION        = 23; // 2: meetings.source/source_key, approvals.object_type/object_id · 3: leads · 4: leads.meeting_start/end · 5: billing_profiles, invoices, invoice_lines · 6: client_users.areas · 7: invoices.due_date/issued_at/issuer, billing_profiles.billing_day/payment_days · 8: billing_items, quotes, quote_lines · 9: invoice_lines.item_id/item_month, invoices.asked_at · 10: suppliers, expenses · 11: expenses.received_at · 12: suppliers.currency · 13: taxes, expenses.paid_eur/payment_* · 14: expenses.payment_for/source_ref · 15: invoices.auto_send · 16: action_items.evidence · 17: bank_movements · 18: ad_accounts, ad_campaigns, ad_metrics_daily, ad_changes · 19: ad_changes.attachments · 20: meetings.internal · 21: reminders · 22: invoices.rectify_reason · 23: invoices.rectify_mode
    const DB_VERSION_OPTION = 'madoficina_db_version';

    public static function activate(){
        self::install();
    }

    public static function maybe_upgrade(){
        if ( (int) get_option(self::DB_VERSION_OPTION, 0) < self::DB_VERSION ) {
            self::install();
        }
    }

    public static function install(){
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        foreach ( Schema::definitions() as $name => $body ){
            dbDelta('CREATE TABLE ' . Schema::table($name) . " ({$body}\n) {$charset};");
        }

        $from = (int) get_option(self::DB_VERSION_OPTION, 0);
        foreach ( self::migrations() as $version => $callback ){
            if ( $version > $from && $version <= self::DB_VERSION ) $callback();
        }

        Roles::install();
        update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);
    }

    /**
     * Data migrations that dbDelta cannot express (renames, backfills…).
     * Keyed by the DB version that introduces them. Must never delete data.
     *
     * @return array<int,callable>
     */
    private static function migrations(){
        return [
            // Suppliers created before they had a currency take the one of their latest invoice.
            12 => function(){
                global $wpdb;
                $s = Schema::table('suppliers'); $e = Schema::table('expenses');
                $wpdb->query("UPDATE {$s} s SET s.currency = COALESCE((SELECT e.currency FROM {$e} e WHERE e.supplier_id = s.id AND e.currency <> '' ORDER BY e.issue_date DESC, e.id DESC LIMIT 1), 'EUR')");
            },
        ];
    }
}
