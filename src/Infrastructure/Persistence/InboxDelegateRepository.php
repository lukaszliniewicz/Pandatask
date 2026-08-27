<?php

namespace Pandatask\Infrastructure\Persistence;

final class InboxDelegateRepository {

    public function roleFor( $owner_user_id, $user_id ) {
        global $wpdb;

        $table = DatabaseContext::getDbPrefix() . 'inbox_delegates';
        $role = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT role FROM {$table} WHERE owner_user_id = %d AND user_id = %d LIMIT 1",
                (int) $owner_user_id,
                (int) $user_id
            )
        );

        return is_string( $role ) ? $role : null;
    }

    public function listForOwner( $owner_user_id ) {
        global $wpdb;

        $table = DatabaseContext::getDbPrefix() . 'inbox_delegates';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT owner_user_id, user_id, role, created_at, updated_at
                 FROM {$table}
                 WHERE owner_user_id = %d
                 ORDER BY role DESC, user_id ASC",
                (int) $owner_user_id
            )
        );

        foreach ( $rows as $row ) {
            $row->owner_user_id = (int) $row->owner_user_id;
            $row->user_id = (int) $row->user_id;
        }

        return $rows;
    }

    public function ownersForUser( $user_id ) {
        global $wpdb;

        $table = DatabaseContext::getDbPrefix() . 'inbox_delegates';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT owner_user_id, user_id, role, created_at, updated_at
                 FROM {$table}
                 WHERE user_id = %d
                 ORDER BY owner_user_id ASC",
                (int) $user_id
            )
        );

        foreach ( $rows as $row ) {
            $row->owner_user_id = (int) $row->owner_user_id;
            $row->user_id = (int) $row->user_id;
        }

        return $rows;
    }

    public function replaceForOwner( $owner_user_id, array $delegates ) {
        global $wpdb;

        $table = DatabaseContext::getDbPrefix() . 'inbox_delegates';
        if ( ! DatabaseContext::beginTransaction() ) {
            return false;
        }

        if ( false === $wpdb->delete( $table, array( 'owner_user_id' => (int) $owner_user_id ), array( '%d' ) ) ) {
            DatabaseContext::rollback();
            return false;
        }

        $now = gmdate( 'Y-m-d H:i:s' );
        foreach ( $delegates as $delegate ) {
            if ( false === $wpdb->insert(
                $table,
                array(
                    'owner_user_id' => (int) $owner_user_id,
                    'user_id'       => (int) $delegate['user_id'],
                    'role'          => (string) $delegate['role'],
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ),
                array( '%d', '%d', '%s', '%s', '%s' )
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
}
