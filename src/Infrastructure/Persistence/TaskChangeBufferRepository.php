<?php

namespace Pandatask\Infrastructure\Persistence;

final class TaskChangeBufferRepository {

    /**
     * Add one immutable buffer record inside the caller's task transaction.
     *
     * @param int                 $task_id Task identifier.
     * @param int                 $actor_id Actor identifier.
     * @param array<array<mixed>> $changes Normalized change records.
     * @param string              $comment Optional user comment.
     */
    public function append( $task_id, $actor_id, array $changes, $comment, $deliver_after ): bool {
        global $wpdb;

        return false !== $wpdb->insert(
            DatabaseContext::getDbPrefix() . 'task_change_buffers',
            array(
                'task_id'       => (int) $task_id,
                'actor_id'      => (int) $actor_id,
                'changes'       => wp_json_encode( $changes ),
                'change_comment' => (string) $comment,
                'deliver_after' => (string) $deliver_after,
                'created_at'    => gmdate( 'Y-m-d H:i:s' ),
            ),
            array( '%d', '%d', '%s', '%s', '%s', '%s' )
        );
    }

    /**
     * Caller must hold a transaction while using the returned rows.
     */
    public function findGroupForUpdate( $task_id, $actor_id ): array {
        global $wpdb;

        $table = DatabaseContext::getDbPrefix() . 'task_change_buffers';

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT *
                 FROM {$table}
                 WHERE task_id = %d AND actor_id = %d
                 ORDER BY id ASC
                 FOR UPDATE",
                $task_id,
                $actor_id
            )
        );
    }

    /**
     * @return array<object>
     */
    public function findDueGroups( $limit = 100 ): array {
        global $wpdb;

        $table = DatabaseContext::getDbPrefix() . 'task_change_buffers';
        $limit = max( 1, min( 500, (int) $limit ) );

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT task_id, actor_id, MAX(deliver_after) AS deliver_after
                 FROM {$table}
                 GROUP BY task_id, actor_id
                 HAVING MAX(deliver_after) <= UTC_TIMESTAMP()
                 ORDER BY MAX(deliver_after) ASC
                 LIMIT %d",
                $limit
            )
        );
    }

    public function deleteIds( array $ids ): bool {
        global $wpdb;

        $ids = array_values( array_filter( array_map( 'absint', $ids ) ) );

        if ( empty( $ids ) ) {
            return true;
        }

        $table = DatabaseContext::getDbPrefix() . 'task_change_buffers';
        $ids_sql = implode( ',', $ids );

        return false !== $wpdb->query( "DELETE FROM {$table} WHERE id IN ({$ids_sql})" );
    }

    public function deleteForTask( $task_id ): bool {
        global $wpdb;

        return false !== $wpdb->delete(
            DatabaseContext::getDbPrefix() . 'task_change_buffers',
            array( 'task_id' => (int) $task_id ),
            array( '%d' )
        );
    }
}
