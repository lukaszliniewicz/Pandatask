<?php

namespace Pandatask\Infrastructure\Persistence;

final class TaskChecklistRepository {

    /**
     * Lock and return the complete task row for an atomic checklist mutation.
     *
     * @param int $task_id Task identifier.
     * @return object|null
     */
    public function lockTask( $task_id ) {
        global $wpdb;

        $tasks_table = DatabaseContext::getDbPrefix() . 'tasks';

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$tasks_table} WHERE id = %d FOR UPDATE",
                (int) $task_id
            )
        );
    }

    /**
     * Update only checklist columns and the task's modification timestamp.
     *
     * @param int    $task_id Task identifier.
     * @param string $json Stored checklist JSON.
     * @param int    $version New checklist version.
     * @return bool
     */
    public function write( $task_id, $json, $version ) {
        global $wpdb;

        $tasks_table = DatabaseContext::getDbPrefix() . 'tasks';
        $result = $wpdb->update(
            $tasks_table,
            array(
                'checklist_json'    => $json,
                'checklist_version' => (int) $version,
                'updated_at'        => gmdate( 'Y-m-d H:i:s' ),
            ),
            array( 'id' => (int) $task_id ),
            array( '%s', '%d', '%s' ),
            array( '%d' )
        );

        return false !== $result;
    }

    /**
     * Return users whose cached task workspaces include this task.
     *
     * @param int $task_id Task identifier.
     * @return array<int,int>
     */
    public function findParticipantUserIdsForTask( $task_id ) {
        global $wpdb;

        $prefix            = DatabaseContext::getDbPrefix();
        $tasks_table       = $prefix . 'tasks';
        $assignments_table = $prefix . 'assignments';

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT user_id
                 FROM {$assignments_table}
                 WHERE task_id = %d
                 UNION
                 SELECT creator_id
                 FROM {$tasks_table}
                 WHERE id = %d AND creator_id IS NOT NULL",
                (int) $task_id,
                (int) $task_id
            )
        );

        return array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
    }
}
