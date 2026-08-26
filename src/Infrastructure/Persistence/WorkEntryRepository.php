<?php

namespace Pandatask\Infrastructure\Persistence;

final class WorkEntryRepository {

    public function insert( array $data ) {
        global $wpdb;
        $table = DatabaseContext::getDbPrefix() . 'work_entries';
        $result = $wpdb->insert( $table, $data );
        return false === $result ? false : (int) $wpdb->insert_id;
    }

    public function update( $entry_id, array $data ) {
        global $wpdb;
        $table = DatabaseContext::getDbPrefix() . 'work_entries';
        return false !== $wpdb->update( $table, $data, array( 'id' => (int) $entry_id ) );
    }

    public function findById( $entry_id ) {
        global $wpdb;
        $prefix = DatabaseContext::getDbPrefix();
        $entries = $prefix . 'work_entries';
        $allocations = $prefix . 'work_allocations';
        $entry = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$entries} WHERE id = %d AND deleted_at IS NULL",
                (int) $entry_id
            )
        );
        if ( ! $entry ) {
            return null;
        }
        $entry->allocations = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$allocations} WHERE work_entry_id = %d ORDER BY id ASC",
                (int) $entry_id
            )
        );
        return $entry;
    }

    public function findBySourceKey( $source_key ) {
        global $wpdb;
        $table = DatabaseContext::getDbPrefix() . 'work_entries';
        $entry_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE source_key = %s AND deleted_at IS NULL LIMIT 1",
                sanitize_text_field( (string) $source_key )
            )
        );
        return $entry_id > 0 ? $this->findById( $entry_id ) : null;
    }

    public function softDelete( $entry_id ) {
        return $this->update(
            $entry_id,
            array(
                'deleted_at' => gmdate( 'Y-m-d H:i:s' ),
                'updated_at' => gmdate( 'Y-m-d H:i:s' ),
            )
        );
    }

    public function replaceAllocations( $entry_id, array $allocations ) {
        global $wpdb;
        $table = DatabaseContext::getDbPrefix() . 'work_allocations';
        if ( false === $wpdb->delete( $table, array( 'work_entry_id' => (int) $entry_id ), array( '%d' ) ) ) {
            return false;
        }
        foreach ( $allocations as $allocation ) {
            $allocation['work_entry_id'] = (int) $entry_id;
            if ( false === $wpdb->insert( $table, $allocation ) ) {
                return false;
            }
        }
        return true;
    }

    public function findForUser( $user_id, $start_date = '', $end_date = '', $limit = 200, $offset = 0 ) {
        global $wpdb;
        $prefix = DatabaseContext::getDbPrefix();
        $entries = $prefix . 'work_entries';
        $sql = "SELECT * FROM {$entries} WHERE user_id = %d AND deleted_at IS NULL";
        $params = array( (int) $user_id );
        if ( $start_date ) {
            $sql .= ' AND work_date >= %s';
            $params[] = $start_date;
        }
        if ( $end_date ) {
            $sql .= ' AND work_date <= %s';
            $params[] = $end_date;
        }
        $sql .= ' ORDER BY work_date DESC, id DESC LIMIT %d OFFSET %d';
        $params[] = max( 1, min( 501, (int) $limit ) );
        $params[] = max( 0, (int) $offset );
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );
        $this->hydrateAllocations( $rows );
        return $rows;
    }

    public function findForTask( $task_id, $user_id = 0 ) {
        global $wpdb;
        $prefix = DatabaseContext::getDbPrefix();
        $entries = $prefix . 'work_entries';
        $allocations = $prefix . 'work_allocations';
        $sql = "SELECT DISTINCT entry.*
                FROM {$entries} entry
                INNER JOIN {$allocations} allocation ON allocation.work_entry_id = entry.id
                WHERE allocation.task_id_snapshot = %d AND entry.deleted_at IS NULL";
        $params = array( (int) $task_id );
        if ( $user_id > 0 ) {
            $sql .= ' AND entry.user_id = %d';
            $params[] = (int) $user_id;
        }
        $sql .= ' ORDER BY entry.work_date DESC, entry.id DESC';
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );
        $this->hydrateAllocations( $rows );
        return $rows;
    }

    public function specificSecondsForOccurrenceUser( $occurrence_id, $user_id, $exclude_residual = true ) {
        global $wpdb;
        $prefix = DatabaseContext::getDbPrefix();
        $entries = $prefix . 'work_entries';
        $allocations = $prefix . 'work_allocations';
        $kind_clause = $exclude_residual ? " AND entry.kind <> 'residual'" : '';
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(allocation.seconds), 0)
                 FROM {$allocations} allocation
                 INNER JOIN {$entries} entry ON entry.id = allocation.work_entry_id
                 WHERE allocation.occurrence_id = %d
                   AND entry.user_id = %d
                   AND entry.deleted_at IS NULL{$kind_clause}",
                (int) $occurrence_id,
                (int) $user_id
            )
        );
    }

    public function allocatedSecondsForTaskIds( array $task_ids ) {
        global $wpdb;
        $task_ids = array_values( array_unique( array_filter( array_map( 'absint', $task_ids ) ) ) );
        if ( empty( $task_ids ) ) {
            return 0;
        }
        $prefix = DatabaseContext::getDbPrefix();
        $entries = $prefix . 'work_entries';
        $allocations = $prefix . 'work_allocations';
        $ids_sql = implode( ',', $task_ids );
        return (int) $wpdb->get_var(
            "SELECT COALESCE(SUM(allocation.seconds), 0)
             FROM {$allocations} allocation
             INNER JOIN {$entries} entry ON entry.id = allocation.work_entry_id
             WHERE allocation.task_id_snapshot IN ({$ids_sql})
               AND entry.deleted_at IS NULL"
        );
    }

    public function allocationSecondsForEntry( $entry_id ) {
        global $wpdb;
        $table = DatabaseContext::getDbPrefix() . 'work_allocations';
        return (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COALESCE(SUM(seconds), 0) FROM {$table} WHERE work_entry_id = %d", (int) $entry_id )
        );
    }

    private function hydrateAllocations( array $rows ) {
        global $wpdb;
        if ( empty( $rows ) ) {
            return;
        }
        $ids = array_map( 'absint', wp_list_pluck( $rows, 'id' ) );
        $sql_ids = implode( ',', array_filter( $ids ) );
        if ( '' === $sql_ids ) {
            return;
        }
        $table = DatabaseContext::getDbPrefix() . 'work_allocations';
        $allocations = $wpdb->get_results( "SELECT * FROM {$table} WHERE work_entry_id IN ({$sql_ids}) ORDER BY id ASC" );
        $by_entry = array();
        foreach ( $allocations as $allocation ) {
            $by_entry[ (int) $allocation->work_entry_id ][] = $allocation;
        }
        foreach ( $rows as $row ) {
            $row->allocations = $by_entry[ (int) $row->id ] ?? array();
        }
    }
}
