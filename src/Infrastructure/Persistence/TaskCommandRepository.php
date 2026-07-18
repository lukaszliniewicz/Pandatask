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
