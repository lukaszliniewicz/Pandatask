<?php

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
    define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
    define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
    define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! defined( 'YEAR_IN_SECONDS' ) ) {
    define( 'YEAR_IN_SECONDS', 31536000 );
}

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', '/' );
}

if ( ! defined( 'PANDAT69_PLUGIN_DIR' ) ) {
    define( 'PANDAT69_PLUGIN_DIR', '/' );
}

if ( ! defined( 'PANDAT69_PLUGIN_URL' ) ) {
    define( 'PANDAT69_PLUGIN_URL', 'https://example.test/' );
}

if ( ! defined( 'PANDAT69_VERSION' ) ) {
    define( 'PANDAT69_VERSION', 'test' );
}

if ( ! class_exists( 'WP_CLI' ) ) {
    class WP_CLI {
        public static function add_command( $name, $callable ) {}
        public static function warning( $message ) {}
        public static function line( $message ) {}
    }
}

if ( ! function_exists( 'groups_is_user_member' ) ) {
    function groups_is_user_member( $user_id, $group_id ) {
        return false;
    }
}

if ( ! function_exists( 'groups_is_user_mod' ) ) {
    function groups_is_user_mod( $user_id, $group_id ) {
        return false;
    }
}

if ( ! class_exists( 'BP_Notifications_Notification' ) ) {
    class BP_Notifications_Notification {

        public static function check_access( $user_id, $notification_id ) {
            return true;
        }

        public static function update( $update_args, $where_args ) {
            return true;
        }
    }
}

if ( ! function_exists( 'bp_notifications_add_notification' ) ) {
    function bp_notifications_add_notification( $args ) {
        return 1;
    }
}

if ( ! function_exists( 'bp_core_current_time' ) ) {
    function bp_core_current_time() {
        return gmdate( 'Y-m-d H:i:s' );
    }
}
