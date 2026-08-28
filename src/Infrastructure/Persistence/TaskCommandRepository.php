<?php

namespace Pandatask\Infrastructure\Persistence;

final class TaskCommandRepository {

    public function insertTask( $task_data, $format ) {
        global $wpdb;

        $tasks_table = DatabaseContext::getDbPrefix() . 'tasks';
        $result      = $wpdb->insert( $tasks_table, $task_data, $format );

        if ( false === $result ) {
            return false;
        }

        return (int) $wpdb->insert_id;
    }

    public function insertTaskRelationship( $task_id, $predecessor_id ) {
        global $wpdb;

        $rel_table = DatabaseContext::getDbPrefix() . 'task_relationships';

        return false !== $wpdb->insert(
            $rel_table,
            array(
                'task_id'        => $task_id,
                'predecessor_id' => $predecessor_id,
            )
        );
    }

    public function getTaskPredecessorIds( $task_id ) {
        global $wpdb;

        $rel_table = DatabaseContext::getDbPrefix() . 'task_relationships';
        $results   = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT predecessor_id FROM {$rel_table} WHERE task_id = %d",
                $task_id
            )
        );

        return array_map( 'intval', (array) $results );
    }

    public function deleteTaskRelationship( $task_id, $predecessor_id ) {
        global $wpdb;

        $rel_table = DatabaseContext::getDbPrefix() . 'task_relationships';

        return false !== $wpdb->delete(
            $rel_table,
            array(
                'task_id'        => $task_id,
                'predecessor_id' => $predecessor_id,
            )
        );
    }

    public function updateTask( $task_id, $update_data, $format ) {
        global $wpdb;

        $tasks_table = DatabaseContext::getDbPrefix() . 'tasks';

        return $wpdb->update( $tasks_table, $update_data, array( 'id' => $task_id ), $format, array( '%d' ) );
    }

    /**
     * Lock a task row for the current transaction and return its status.
     *
     * @return string|null Status, or null when the task no longer exists.
     */
    public function lockTaskStatusForUpdate( $task_id ) {
        global $wpdb;

        $tasks_table = DatabaseContext::getDbPrefix() . 'tasks';

        return $wpdb->get_var(
            $wpdb->prepare(
                "SELECT status FROM {$tasks_table} WHERE id = %d FOR UPDATE",
                $task_id
            )
        );
    }

    public function updateProjectForTasks( $task_ids, $project_id ) {
        global $wpdb;

        $task_ids = array_values( array_filter( array_map( 'absint', (array) $task_ids ) ) );

        if ( empty( $task_ids ) ) {
            return true;
        }

        $tasks_table = DatabaseContext::getDbPrefix() . 'tasks';
        $task_ids_sql = implode( ',', $task_ids );
        $project_sql = $project_id ? (string) absint( $project_id ) : 'NULL';

        return false !== $wpdb->query(
            "UPDATE {$tasks_table}
             SET project_id = {$project_sql}, updated_at = UTC_TIMESTAMP()
             WHERE id IN ({$task_ids_sql})"
        );
    }

    public function findParticipantUserIdsForTasks( $task_ids ) {
        global $wpdb;

        $task_ids = array_values( array_filter( array_map( 'absint', (array) $task_ids ) ) );

        if ( empty( $task_ids ) ) {
            return array();
        }

        $assignments_table = DatabaseContext::getDbPrefix() . 'assignments';
        $tasks_table = DatabaseContext::getDbPrefix() . 'tasks';
        $task_ids_sql = implode( ',', $task_ids );
        $user_ids = $wpdb->get_col(
            "SELECT user_id
             FROM {$assignments_table}
             WHERE task_id IN ({$task_ids_sql})"
            . " UNION
                SELECT creator_id
                FROM {$tasks_table}
                WHERE id IN ({$task_ids_sql})
                AND creator_id IS NOT NULL"
        );

        return array_values( array_filter( array_map( 'absint', (array) $user_ids ) ) );
    }

    public function deleteTaskAssignments( $task_id ) {
        global $wpdb;

        $assignments_table = DatabaseContext::getDbPrefix() . 'assignments';

        return false !== $wpdb->delete( $assignments_table, array( 'task_id' => $task_id ), array( '%d' ) );
    }

    public function deleteTaskComments( $task_id ) {
        global $wpdb;

        $comments_table = DatabaseContext::getDbPrefix() . 'comments';

        return false !== $wpdb->delete( $comments_table, array( 'task_id' => $task_id ), array( '%d' ) );
    }

    public function deleteTaskHistory( $task_id ) {
        global $wpdb;

        $history_table = DatabaseContext::getDbPrefix() . 'task_history';

        return false !== $wpdb->delete( $history_table, array( 'task_id' => $task_id ), array( '%d' ) );
    }

    public function deleteTaskChangeBuffers( $task_id ) {
        global $wpdb;

        $buffers_table = DatabaseContext::getDbPrefix() . 'task_change_buffers';

        return false !== $wpdb->delete( $buffers_table, array( 'task_id' => $task_id ), array( '%d' ) );
    }

    public function deleteTaskRelationships( $task_id ) {
        global $wpdb;

        $relationships_table = DatabaseContext::getDbPrefix() . 'task_relationships';

        return false !== $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$relationships_table} WHERE task_id = %d OR predecessor_id = %d",
                $task_id,
                $task_id
            )
        );
    }

    public function deleteTask( $task_id ) {
        global $wpdb;

        $tasks_table = DatabaseContext::getDbPrefix() . 'tasks';

        return $wpdb->delete( $tasks_table, array( 'id' => $task_id ), array( '%d' ) );
    }

    public function unlinkChildTasks( $task_id ) {
        global $wpdb;

        $tasks_table = DatabaseContext::getDbPrefix() . 'tasks';

        return $wpdb->update(
            $tasks_table,
            array( 'parent_task_id' => null ),
            array( 'parent_task_id' => $task_id ),
            array( '%s' ),
            array( '%d' )
        );
    }

    public function findSuccessorIds( $completed_task_id ) {
        global $wpdb;

        $rel_table = DatabaseContext::getDbPrefix() . 'task_relationships';
        $results   = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT task_id FROM {$rel_table} WHERE predecessor_id = %d",
                $completed_task_id
            )
        );

        return array_map( 'intval', (array) $results );
    }

    public function findRoleAssignmentUserIds( $task_id, $role ) {
        global $wpdb;

        $assignments_table = DatabaseContext::getDbPrefix() . 'assignments';
        $results           = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT user_id FROM {$assignments_table} WHERE task_id = %d AND role = %s",
                $task_id,
                $role
            )
        );

        return array_map( 'intval', (array) $results );
    }

    public function deleteRoleAssignments( $task_id, $role, $user_ids ) {
        global $wpdb;

        $user_ids = array_filter( array_map( 'absint', (array) $user_ids ) );

        if ( empty( $user_ids ) ) {
            return true;
        }

        $assignments_table   = DatabaseContext::getDbPrefix() . 'assignments';
        $user_ids_safe_string = implode( ',', $user_ids );
        $query               = $wpdb->prepare(
            "DELETE FROM {$assignments_table} WHERE task_id = %d AND role = %s AND user_id IN ({$user_ids_safe_string})",
            $task_id,
            $role
        );

        return false !== $wpdb->query( $query );
    }

    public function insertRoleAssignment( $task_id, $user_id, $role ) {
        global $wpdb;

        $assignments_table = DatabaseContext::getDbPrefix() . 'assignments';

        return false !== $wpdb->insert(
            $assignments_table,
            array(
                'task_id' => $task_id,
                'user_id' => $user_id,
                'role'    => $role,
            ),
            array( '%d', '%d', '%s' )
        );
    }

    public function findPendingTasksToStart( $today ) {
        global $wpdb;

        $tasks_table = DatabaseContext::getDbPrefix() . 'tasks';

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$tasks_table}
                 WHERE status = 'pending'
                 AND start_date <= %s
                 AND archived = 0",
                $today
            )
        );
    }

    public function findRecurringTasksToRollOver( $today ) {
        global $wpdb;

        $tasks_table = DatabaseContext::getDbPrefix() . 'tasks';

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$tasks_table}
                 WHERE is_recurring = 1
                 AND archived = 0
                 AND deadline IS NOT NULL
                 AND (status = 'done' OR deadline < %s)
                 AND (recurrence_ends_on IS NULL OR recurrence_ends_on >= %s)",
                $today,
                $today
            )
        );
    }

    public function setTaskRecurringState( $task_id, $is_recurring ) {
        global $wpdb;

        $tasks_table = DatabaseContext::getDbPrefix() . 'tasks';

        return $wpdb->update(
            $tasks_table,
            array( 'is_recurring' => absint( $is_recurring ) ),
            array( 'id' => $task_id ),
            array( '%d' ),
            array( '%d' )
        );
    }
}
