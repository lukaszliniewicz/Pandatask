<?php

namespace Pandatask\Infrastructure\Persistence;

final class DatabaseContext {

    public static function getDbPrefix() {
        global $wpdb;

        return $wpdb->prefix . 'pandat69_';
    }

    public static function getBoardCacheVersion( $board_name, $type = 'tasks' ) {
        $key     = "pandat69_v_{$type}_{$board_name}";
        $version = get_transient( $key );

        return false === $version ? 1 : (int) $version;
    }

    public static function invalidateBoardCache( $board_name ) {
        self::incrementBoardCacheVersion( $board_name, 'tasks' );
        self::incrementBoardCacheVersion( $board_name, 'categories' );
        self::incrementBoardCacheVersion( $board_name, 'projects' );
        self::incrementBoardCacheVersion( $board_name, 'parent_tasks' );
        self::incrementBoardCacheVersion( $board_name, 'reports' );
    }

    public static function invalidateUserCache( $user_id ) {
        if ( $user_id > 0 ) {
            self::incrementUserCacheVersion( $user_id );
        }
    }

    private static function incrementBoardCacheVersion( $board_name, $type = 'tasks' ) {
        $key     = "pandat69_v_{$type}_{$board_name}";
        $version = self::getBoardCacheVersion( $board_name, $type );

        set_transient( $key, $version + 1, YEAR_IN_SECONDS );
    }

    private static function getUserCacheVersion( $user_id ) {
        $key     = "pandat69_v_user_{$user_id}";
        $version = get_transient( $key );

        return false === $version ? 1 : (int) $version;
    }

    private static function incrementUserCacheVersion( $user_id ) {
        $key     = "pandat69_v_user_{$user_id}";
        $version = self::getUserCacheVersion( $user_id );

        set_transient( $key, $version + 1, YEAR_IN_SECONDS );
    }
}
