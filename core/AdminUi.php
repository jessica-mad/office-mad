<?php
namespace MAD_Oficina\Core;

if ( ! defined('ABSPATH') ) exit;

/**
 * Small helpers shared by admin views.
 */
final class AdminUi {

    /** Flash message set by a handler before redirecting. */
    public static function flash($text, $type = 'success'){
        set_transient('madof_notice_' . get_current_user_id(), ['type' => $type, 'text' => $text], 60);
    }

    public static function render_flash(){
        $key = 'madof_notice_' . get_current_user_id();
        $notice = get_transient($key);
        if ( ! $notice ) return;
        delete_transient($key);
        $type = $notice['type'] === 'error' ? 'error' : 'success';
        printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($type), esc_html($notice['text']));
    }

    /** Opens a POST form to admin-post.php for "madof_{$action}" with its nonce. */
    /** «Modelo e indicaciones: en Personajes y agentes», pointing at that agent's card. */
    public static function agents_link($key){
        return '<p class="description">' . esc_html__('El modelo y las indicaciones de este agente se eligen en', 'mad-oficina') . ' <a href="' . esc_url(admin_url('admin.php?page=mad-oficina-agentes#agente-' . $key)) . '">' . esc_html__('MAD Oficina → Personajes y agentes', 'mad-oficina') . '</a>.</p>';
    }

    public static function form_open($action, $class = 'madof-form'){
        $html  = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="' . esc_attr($class) . '">';
        $html .= '<input type="hidden" name="action" value="madof_' . esc_attr($action) . '">';
        $html .= wp_nonce_field('madof_' . $action, '_wpnonce', true, false);
        return $html;
    }

    public static function header($kicker, $title, $intro = ''){
        echo '<header class="madof-hero"><span class="madof-kicker">' . esc_html($kicker) . '</span>';
        echo '<h1>' . esc_html($title) . '</h1>';
        if ( $intro ) echo '<p>' . esc_html($intro) . '</p>';
        echo '</header>';
        echo '<hr class="wp-header-end">'; // WordPress moves admin notices here, not into the hero
        self::render_flash();
    }

    /**
     * 12×12 pixel icons. Each row is a 12-char string: '#' = filled pixel.
     */
    const ICONS = [
        'alert' => [
            '.....##.....',
            '....####....',
            '....####....',
            '...##..##...',
            '...##..##...',
            '..###..###..',
            '..###..###..',
            '.##########.',
            '.####..####.',
            '############',
            '############',
            '............',
        ],
        'check' => [
            '............',
            '..........##',
            '.........##.',
            '........##..',
            '.......##...',
            '##....##....',
            '.##..##.....',
            '..####......',
            '...##.......',
            '............',
            '............',
            '............',
        ],
        'cross' => [
            '............',
            '.##......##.',
            '..##....##..',
            '...##..##...',
            '....####....',
            '.....##.....',
            '....####....',
            '...##..##...',
            '..##....##..',
            '.##......##.',
            '............',
            '............',
        ],
        'plus' => [
            '............',
            '.....##.....',
            '.....##.....',
            '.....##.....',
            '.....##.....',
            '.##########.',
            '.##########.',
            '.....##.....',
            '.....##.....',
            '.....##.....',
            '.....##.....',
            '............',
        ],
        'user' => [
            '....####....',
            '...######...',
            '...######...',
            '...######...',
            '....####....',
            '............',
            '..########..',
            '.##########.',
            '.##########.',
            '.##########.',
            '............',
            '............',
        ],
        'eye' => [
            '............',
            '............',
            '....####....',
            '..##....##..',
            '.#...##...#.',
            '#...####...#',
            '#...####...#',
            '.#...##...#.',
            '..##....##..',
            '....####....',
            '............',
            '............',
        ],
        'key' => [
            '............',
            '.####.......',
            '##..##......',
            '##..########',
            '##..##..#.##',
            '.####...#.##',
            '............',
            '............',
            '............',
            '............',
            '............',
            '............',
        ],
    ];

    public static function icon($name, $label = ''){
        $rows = self::ICONS[$name] ?? null;
        if ( ! $rows ) return '';
        $rects = '';
        foreach ( $rows as $y => $row ){
            for ( $x = 0; $x < 12; $x++ ){
                if ( $row[$x] === '#' ) $rects .= '<rect x="' . $x . '" y="' . $y . '" width="1" height="1"/>';
            }
        }
        $a11y = $label ? 'role="img" aria-label="' . esc_attr($label) . '"' : 'aria-hidden="true"';
        return '<svg class="madof-icon" viewBox="0 0 12 12" width="12" height="12" shape-rendering="crispEdges" fill="currentColor" ' . $a11y . '>' . $rects . '</svg>';
    }
}
