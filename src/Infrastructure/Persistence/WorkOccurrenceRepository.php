<?php

namespace Pandatask\Infrastructure\Persistence;

final class WorkOccurrenceRepository {

    public function createForTask( $task, $sequence_number = 1, $state = 'open' ) {
        global $wpdb;

        $table = DatabaseContext::getDbPrefix() . 'task_work_occurrences';
        $task_id = (int) $task->id;
        $sequence_number = max( 1, (int) $sequence_number );
        $now = gmdate( 'Y-m-d H:i:s' );
        $result = $wpdb->insert(
            $table,
            array(
                'task_id'                  => $task_id,
                'creator_id_snapshot'      => ! empty( $task->creator_id ) ? (int) $task->creator_id : null,
                'sequence_number'          => $sequence_number,
                'occurrence_key'           => 'task-' . $task_id . '-' . $sequence_number,
                'state'                    => $state,
                'board_name_snapshot'      => $task->board_name,
                'task_name_snapshot'       => $task->name,
                'project_id_snapshot'      => ! empty( $task->project_id ) ? (int) $task->project_id : null,
                'project_name_snapshot'    => $task->project_name ?? null,
                'category_id_snapshot'     => ! empty( $task->category_id ) ? (int) $task->category_id : null,
                'category_name_snapshot'   => $task->category_name ?? null,
                'start_date_snapshot'      => $task->start_date ?? null,
                'deadline_snapshot'        => $task->deadline ?? null,
                'estimated_effort_seconds' => isset( $task->estimated_effort_seconds ) ? (int) $task->estimated_effort_seconds : null,
                'opened_at'                => $now,
                'completed_at'             => 'completed' === $state ? $now : null,
                'skipped_at'               => 'skipped' === $state ? $now : null,
            )
        );

        return false === $result ? false : (int) $wpdb->insert_id;
    }

    public function findForTask( $task_id ) {
        global $wpdb;
        $table = DatabaseContext::getDbPrefix() . 'task_work_occurrences';
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE task_id = %d ORDER BY sequence_number ASC, id ASC",
                (int) $task_id
            )
        );
    }

    public function findById( $occurrence_id ) {
        global $wpdb;
        $table = DatabaseContext::getDbPrefix() . 'task_work_occurrences';
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $occurrence_id ) );
    }

    public function findCurrentForTask( $task_id ) {
        global $wpdb;
        $prefix = DatabaseContext::getDbPrefix();
        $tasks = $prefix . 'tasks';
        $occurrences = $prefix . 'task_work_occurrences';
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT occurrence.*
                 FROM {$tasks} task
                 INNER JOIN {$occurrences} occurrence ON occurrence.id = task.current_work_occurrence_id
                 WHERE task.id = %d",
                (int) $task_id
            )
        );
    }

    public function nextSequence( $task_id ) {
        global $wpdb;
        $table = DatabaseContext::getDbPrefix() . 'task_work_occurrences';
        return 1 + (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(sequence_number), 0) FROM {$table} WHERE task_id = %d", (int) $task_id ) );
    }

    public function setState( $occurrence_id, $state ) {
        global $wpdb;
        $table = DatabaseContext::getDbPrefix() . 'task_work_occurrences';
        $current = $this->findById( (int) $occurrence_id );
        if ( ! $current ) {
            return false;
        }
        $now = gmdate( 'Y-m-d H:i:s' );
        $data = array( 'state' => $state, 'updated_at' => $now );

        foreach ( array( 'completed', 'skipped', 'cancelled' ) as $terminal ) {
            $field = $terminal . '_at';
            $data[ $field ] = $terminal === $state
                ? ( $current->$field ?: $now )
                : null;
        }

        return false !== $wpdb->update( $table, $data, array( 'id' => (int) $occurrence_id ) );
    }

    public function tombstoneTaskOccurrences( $task_id ) {
        global $wpdb;
        $table = DatabaseContext::getDbPrefix() . 'task_work_occurrences';
        $now = gmdate( 'Y-m-d H:i:s' );
        return false !== $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table}
                 SET tombstoned_at = COALESCE(tombstoned_at, %s),
                     state = CASE WHEN state = 'open' THEN 'cancelled' ELSE state END,
                     cancelled_at = CASE WHEN state = 'open' THEN COALESCE(cancelled_at, %s) ELSE cancelled_at END,
                     updated_at = %s
                 WHERE task_id = %d",
                $now,
                $now,
                $now,
                (int) $task_id
            )
        );
    }

    /** Refresh mutable classification/schedule snapshots while an occurrence is still open. */
    public function refreshOpenSnapshot( $occurrence_id, $task ) {
        global $wpdb;
        $table = DatabaseContext::getDbPrefix() . 'task_work_occurrences';
        $data = array(
            'creator_id_snapshot'      => ! empty( $task->creator_id ) ? (int) $task->creator_id : null,
            'board_name_snapshot'      => $task->board_name,
            'task_name_snapshot'       => $task->name,
            'project_id_snapshot'      => ! empty( $task->project_id ) ? (int) $task->project_id : null,
            'project_name_snapshot'    => $task->project_name ?? null,
            'category_id_snapshot'     => ! empty( $task->category_id ) ? (int) $task->category_id : null,
            'category_name_snapshot'   => $task->category_name ?? null,
            'start_date_snapshot'      => $task->start_date ?? null,
            'deadline_snapshot'        => $task->deadline ?? null,
            'estimated_effort_seconds' => isset( $task->estimated_effort_seconds ) ? (int) $task->estimated_effort_seconds : null,
            'updated_at'               => gmdate( 'Y-m-d H:i:s' ),
        );

        return false !== $wpdb->update(
            $table,
            $data,
            array( 'id' => (int) $occurrence_id, 'state' => 'open' )
        );
    }

    public function setCurrentOccurrence( $task_id, $occurrence_id ) {
        global $wpdb;
        $tasks = DatabaseContext::getDbPrefix() . 'tasks';
        return false !== $wpdb->update(
            $tasks,
            array( 'current_work_occurrence_id' => (int) $occurrence_id ),
            array( 'id' => (int) $task_id ),
            array( '%d' ),
            array( '%d' )
        );
    }
}
