<?php

namespace Pandatask\Infrastructure\Persistence;

use RuntimeException;

/**
 * Persistence for recurring task series and their task occurrences.
 *
 * Transactions and lock ordering are owned by the caller.  In particular,
 * callers that need both rows lock the task before locking its series.
 */
final class TaskRecurrenceRepository {

    /**
     * @return object|null
     */
    public function findById( $series_id ) {
        global $wpdb;

        $table = DatabaseContext::getDbPrefix() . 'task_recurrence_series';

        return $this->readRow(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d",
                (int) $series_id
            )
        );
    }

    /**
     * @return object|null
     */
    public function findForTask( $task_id ) {
        global $wpdb;

        $prefix = DatabaseContext::getDbPrefix();
        $tasks  = $prefix . 'tasks';
        $series = $prefix . 'task_recurrence_series';

        return $this->readRow(
            $wpdb->prepare(
                "SELECT series.*
                 FROM {$tasks} task
                 INNER JOIN {$series} series ON series.id = task.recurrence_series_id
                 WHERE task.id = %d
                 LIMIT 1",
                (int) $task_id
            )
        );
    }

    /**
     * Lock a series row for the caller's current transaction.
     *
     * @return object|null
     */
    public function lockSeries( $series_id ) {
        global $wpdb;

        $table = DatabaseContext::getDbPrefix() . 'task_recurrence_series';

        return $this->readRow(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d FOR UPDATE",
                (int) $series_id
            )
        );
    }

    /**
     * Lock a task row for the caller's current transaction.
     *
     * @return object|null
     */
    public function lockTask( $task_id ) {
        global $wpdb;

        $table = DatabaseContext::getDbPrefix() . 'tasks';

        return $this->readRow(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d FOR UPDATE",
                (int) $task_id
            )
        );
    }

    /**
     * @return int|false
     */
    public function insertSeries( array $data ) {
        global $wpdb;

        $table  = DatabaseContext::getDbPrefix() . 'task_recurrence_series';
        $result = $wpdb->insert( $table, $data );

        return false === $result ? false : (int) $wpdb->insert_id;
    }

    /**
     * A zero-row update is successful: the requested values may already be
     * present, while a database error is represented by false.
     */
    public function updateSeries( $series_id, array $data ) {
        global $wpdb;

        if ( empty( $data ) ) {
            return true;
        }

        $table = DatabaseContext::getDbPrefix() . 'task_recurrence_series';

        return false !== $wpdb->update(
            $table,
            $data,
            array( 'id' => (int) $series_id )
        );
    }

    /**
     * Update only the three recurrence linkage fields on a task.
     */
    public function linkTask( $task_id, $series_id, $sequence, $scheduled_start ) {
        global $wpdb;

        $table = DatabaseContext::getDbPrefix() . 'tasks';

        return false !== $wpdb->update(
            $table,
            array(
                'recurrence_series_id'        => null === $series_id ? null : (int) $series_id,
                'recurrence_sequence'         => null === $sequence ? null : (int) $sequence,
                'recurrence_scheduled_start'  => $scheduled_start,
            ),
            array( 'id' => (int) $task_id ),
            array( '%d', '%d', '%s' ),
            array( '%d' )
        );
    }

    /**
     * @return object[] Rows contain only user_id and role.
     */
    public function findAssignments( $task_id ) {
        global $wpdb;

        $table = DatabaseContext::getDbPrefix() . 'assignments';
        return $this->readResults(
            $wpdb->prepare(
                "SELECT user_id, role
                 FROM {$table}
                 WHERE task_id = %d
                 ORDER BY id ASC",
                (int) $task_id
            )
        );
    }

    /**
     * @return int[]
     */
    public function findPredecessorIds( $task_id ) {
        global $wpdb;

        $table = DatabaseContext::getDbPrefix() . 'task_relationships';

        return array_map(
            'intval',
            $this->readColumn(
                $wpdb->prepare(
                    "SELECT predecessor_id
                     FROM {$table}
                     WHERE task_id = %d
                     ORDER BY id ASC",
                    (int) $task_id
                )
            )
        );
    }

    /**
     * @return int[]
     */
    public function findLegacyTaskIds( $limit = 100 ) {
        global $wpdb;

        $limit = $this->boundedLimit( $limit );
        $table = DatabaseContext::getDbPrefix() . 'tasks';

        return array_map(
            'intval',
            $this->readColumn(
                $wpdb->prepare(
                    "SELECT id
                     FROM {$table}
                     WHERE is_recurring = 1
                       AND recurrence_series_id IS NULL
                     ORDER BY id ASC
                     LIMIT %d",
                    $limit
                )
            )
        );
    }

    /**
     * @return object[]
     */
    public function findReadySeries( $today, $limit = 100 ) {
        global $wpdb;

        $limit = $this->boundedLimit( $limit );
        $prefix = DatabaseContext::getDbPrefix();
        $series = $prefix . 'task_recurrence_series';
        $tasks  = $prefix . 'tasks';

        return $this->readResults(
            $wpdb->prepare(
                "SELECT series.*
                 FROM {$series} series
                 INNER JOIN {$tasks} current_task ON current_task.id = series.current_task_id
                 WHERE series.active = 1
                   AND series.next_start_date IS NOT NULL
                   AND (series.next_start_date <= %s OR current_task.status = 'done')
                 ORDER BY series.next_start_date ASC, series.id ASC
                 LIMIT %d",
                (string) $today,
                $limit
            )
        );
    }

    /**
     * @return object[]
     */
    public function listOccurrenceTasks( $series_id, $limit = 100, $before_sequence = null ) {
        global $wpdb;

        $limit = $this->boundedLimit( $limit );
        $table = DatabaseContext::getDbPrefix() . 'tasks';
        $query = "SELECT task.*
                  FROM {$table} task
                  WHERE task.recurrence_series_id = %d";
        $args  = array( (int) $series_id );

        if ( null !== $before_sequence ) {
            $query .= ' AND task.recurrence_sequence < %d';
            $args[] = (int) $before_sequence;
        }

        $query .= ' ORDER BY task.recurrence_sequence DESC, task.id DESC LIMIT %d';
        $args[] = $limit;

        return $this->readResults( $wpdb->prepare( $query, ...$args ) );
    }

    /**
     * The caller must hold the corresponding series lock before calling.
     */
    public function findMaxSequence( $series_id ) {
        global $wpdb;

        $table = DatabaseContext::getDbPrefix() . 'tasks';
        $value = $this->readValue(
            $wpdb->prepare(
                "SELECT COALESCE(MAX(recurrence_sequence), 0)
                 FROM {$table}
                 WHERE recurrence_series_id = %d",
                (int) $series_id
            )
        );

        return (int) $value;
    }

    /**
     * @return object|null
     */
    private function readRow( $query ) {
        global $wpdb;

        $row = $wpdb->get_row( $query );
        $this->throwOnReadError( $row );

        return $row;
    }

    /**
     * @return object[]
     */
    private function readResults( $query ) {
        global $wpdb;

        $rows = $wpdb->get_results( $query );
        $this->throwOnReadError( $rows );

        return (array) $rows;
    }

    /**
     * @return array<int,mixed>
     */
    private function readColumn( $query ) {
        global $wpdb;

        $values = $wpdb->get_col( $query );
        $this->throwOnReadError( $values );

        return (array) $values;
    }

    private function readValue( $query ) {
        global $wpdb;

        $value = $wpdb->get_var( $query );
        $this->throwOnReadError( $value );

        return $value;
    }

    private function throwOnReadError( $result ) {
        global $wpdb;

        if ( false === $result || ! empty( $wpdb->last_error ) ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Database errors are exception messages, not rendered output.
            throw new RuntimeException( ! empty( $wpdb->last_error ) ? (string) $wpdb->last_error : 'Database read failed.' );
        }
    }

    private function boundedLimit( $limit ) {
        return max( 1, min( 100, (int) $limit ) );
    }
}
