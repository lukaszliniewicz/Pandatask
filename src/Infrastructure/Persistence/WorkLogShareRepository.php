<?php

namespace Pandatask\Infrastructure\Persistence;

final class WorkLogShareRepository {

    public function sharedGroupIdsForUser( $user_id ) {
        global $wpdb;

        $table = DatabaseContext::getDbPrefix() . 'work_log_group_shares';
        return array_map(
            'intval',
            $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT group_id FROM {$table} WHERE user_id = %d ORDER BY group_id ASC",
                    (int) $user_id
                )
            )
        );
    }

    public function userIdsForGroup( $group_id ) {
        global $wpdb;

        $table = DatabaseContext::getDbPrefix() . 'work_log_group_shares';
        return array_map(
            'intval',
            $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT user_id FROM {$table} WHERE group_id = %d ORDER BY user_id ASC",
                    (int) $group_id
                )
            )
        );
    }

    public function hasGrant( $user_id, $group_id ) {
        global $wpdb;

        $table = DatabaseContext::getDbPrefix() . 'work_log_group_shares';
        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE user_id = %d AND group_id = %d LIMIT 1",
                (int) $user_id,
                (int) $group_id
            )
        );
    }

    public function replaceForUser( $user_id, array $group_ids ) {
        global $wpdb;

        $table = DatabaseContext::getDbPrefix() . 'work_log_group_shares';
        if ( ! DatabaseContext::beginTransaction() ) {
            return false;
        }

        if ( false === $wpdb->delete( $table, array( 'user_id' => (int) $user_id ), array( '%d' ) ) ) {
            DatabaseContext::rollback();
            return false;
        }

        foreach ( $group_ids as $group_id ) {
            if ( false === $wpdb->insert(
                $table,
                array(
                    'user_id'    => (int) $user_id,
                    'group_id'   => (int) $group_id,
                    'created_at' => gmdate( 'Y-m-d H:i:s' ),
                    'updated_at' => gmdate( 'Y-m-d H:i:s' ),
                ),
                array( '%d', '%d', '%s', '%s' )
            ) ) {
                DatabaseContext::rollback();
                return false;
            }
        }

        if ( ! DatabaseContext::commit() ) {
            DatabaseContext::rollback();
            return false;
        }

        return true;
    }

    /**
     * Remove one owner's grant when membership cleanup runs.
     */
    public static function deleteForUserGroup( $user_id, $group_id ) {
        global $wpdb;

        $table = DatabaseContext::getDbPrefix() . 'work_log_group_shares';
        return false !== $wpdb->delete(
            $table,
            array(
                'user_id'  => (int) $user_id,
                'group_id' => (int) $group_id,
            ),
            array( '%d', '%d' )
        );
    }

    /**
     * Remove every grant for a deleted group.
     */
    public static function deleteForGroup( $group_id ) {
        global $wpdb;

        $table = DatabaseContext::getDbPrefix() . 'work_log_group_shares';
        return false !== $wpdb->delete( $table, array( 'group_id' => (int) $group_id ), array( '%d' ) );
    }
}
