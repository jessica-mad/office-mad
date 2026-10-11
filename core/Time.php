<?php
namespace MAD_Oficina\Core;

if ( ! defined('ABSPATH') ) exit;

final class Time {
    /** Current time in UTC, MySQL format. All datetimes are stored in UTC. */
    public static function now(){
        return gmdate('Y-m-d H:i:s');
    }
}
