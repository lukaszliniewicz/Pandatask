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

    public static function invalidateBoardCache( $board_name, $types = null ) {
        $allowed_types = array( 'tasks', 'categories', 'projects', 'parent_tasks', 'reports' );
        $types         = null === $types ? $allowed_types : array_intersect( (array) $types, $allowed_types );

        foreach ( $types as $type ) {
            self::incrementBoardCacheVersion( $board_name, $type );
        }
    }

    public static function invalidateUserCache( $user_id ) {
        if ( $user_id > 0 ) {
            self::incrementUserCacheVersion( $user_id );
        }
    }

    public static function getTaskCacheKey( $task_id ) {
        return 'pandat69_task_v2_' . (int) $task_id;
    }

    public static function invalidateTaskCache( $task_id ) {
        delete_transient( self::getTaskCacheKey( $task_id ) );
        delete_transient( 'pandat69_task_' . (int) $task_id );
    }

    public static function beginTransaction() {
        global $wpdb;

        return false !== $wpdb->query( 'START TRANSACTION' );
    }

    public static function commit() {
        global $wpdb;

        return false !== $wpdb->query( 'COMMIT' );
    }

    public static function rollback() {
        global $wpdb;

        return false !== $wpdb->query( 'ROLLBACK' );
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
