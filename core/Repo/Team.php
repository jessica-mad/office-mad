<?php
namespace MAD_Oficina\Core\Repo;

use MAD_Oficina\Core\Schema;
use MAD_Oficina\Core\Time;

if ( ! defined('ABSPATH') ) exit;

/**
 * Team members (Liz and whoever joins later). Real people, not AI characters.
 */
final class Team {

    public static function all($active_only = false){
        global $wpdb;
        $where = $active_only ? 'WHERE active = 1' : '';
        return array_map([self::class, 'hydrate'], $wpdb->get_results('SELECT * FROM ' . Schema::table('team_members') . " {$where} ORDER BY name ASC", ARRAY_A));
    }

    public static function get($id){
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::table('team_members') . ' WHERE id = %d', $id), ARRAY_A);
        return $row ? self::hydrate($row) : null;
    }

    public static function find_by_slug($slug){
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::table('team_members') . ' WHERE slug = %s', sanitize_key($slug)), ARRAY_A);
        return $row ? self::hydrate($row) : null;
    }

    public static function find_by_user($user_id){
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::table('team_members') . ' WHERE wp_user_id = %d', $user_id), ARRAY_A);
        return $row ? self::hydrate($row) : null;
    }

    /**
     * @param array $data name, slug, wp_user_id, workspace_email, chat_dm_space, skills (array|string), active, is_demo
     * @return int|\WP_Error
     */
    public static function save($data, $id = 0){
        global $wpdb;
        $name = sanitize_text_field($data['name'] ?? '');
        if ( $name === '' ) return new \WP_Error('madof_team_name', __('El nombre es obligatorio.', 'mad-oficina'));

        $slug = sanitize_key($data['slug'] ?? '') ?: sanitize_key(remove_accents($name));
        $existing = self::find_by_slug($slug);
        if ( $existing && (int) $existing['id'] !== (int) $id ) {
            return new \WP_Error('madof_team_slug', sprintf(__('El identificador %s ya existe.', 'mad-oficina'), $slug));
        }

        $email = sanitize_email($data['workspace_email'] ?? '');
        $skills = $data['skills'] ?? [];
        if ( is_string($skills) ) $skills = preg_split('/\s*,\s*/', $skills);

        $row = [
            'name'            => $name,
            'slug'            => $slug,
            'wp_user_id'      => ! empty($data['wp_user_id']) ? (int) $data['wp_user_id'] : null,
            'workspace_email' => $email ?: null,
            'chat_dm_space'   => sanitize_text_field($data['chat_dm_space'] ?? '') ?: null,
            'skills'          => wp_json_encode(array_values(array_filter(array_map('sanitize_text_field', (array) $skills)))),
            'active'          => isset($data['active']) ? ( $data['active'] ? 1 : 0 ) : 1,
            'updated_at'      => Time::now(),
        ];

        if ( $id ) {
            $wpdb->update(Schema::table('team_members'), $row, ['id' => $id]);
            return (int) $id;
        }
        $row['is_demo'] = empty($data['is_demo']) ? 0 : 1;
        $row['created_at'] = Time::now();
        $wpdb->insert(Schema::table('team_members'), $row);
        return (int) $wpdb->insert_id;
    }

    public static function delete($id){
        global $wpdb;
        $wpdb->delete(Schema::table('team_members'), ['id' => (int) $id]);
    }

    private static function hydrate($row){
        $row['id'] = (int) $row['id'];
        $row['wp_user_id'] = $row['wp_user_id'] ? (int) $row['wp_user_id'] : null;
        $row['skills'] = $row['skills'] ? (json_decode($row['skills'], true) ?: []) : [];
        return $row;
    }
}
