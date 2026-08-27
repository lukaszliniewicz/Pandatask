<?php

namespace Pandatask\Infrastructure\Persistence;

final class WorkReportRepository {

    /**
     * Fetch the roster total for many users in one aggregate query.
     */
    public function personalTotalsForUsers( array $user_ids, $start_date, $end_date ) {
        global $wpdb;

        $user_ids = array_values( array_unique( array_filter( array_map( 'absint', $user_ids ) ) ) );
        if ( empty( $user_ids ) ) {
            return array();
        }

        $entries      = DatabaseContext::getDbPrefix() . 'work_entries';
        $placeholders = implode( ', ', array_fill( 0, count( $user_ids ), '%d' ) );
        $params       = array_merge( $user_ids, array( $start_date, $end_date ) );
        $rows         = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT user_id, COALESCE(SUM(duration_seconds), 0) AS total_seconds
                 FROM {$entries}
                 WHERE user_id IN ({$placeholders})
                   AND deleted_at IS NULL
                   AND work_date BETWEEN %s AND %s
                 GROUP BY user_id",
                ...$params
            )
        );
        $totals       = array_fill_keys( $user_ids, 0 );

        foreach ( $rows as $row ) {
            $totals[ (int) $row->user_id ] = (int) $row->total_seconds;
        }

        return $totals;
    }

    public function personalSummary( $user_id, $start_date, $end_date ) {
        global $wpdb;
        $prefix = DatabaseContext::getDbPrefix();
        $entries = $prefix . 'work_entries';
        $allocations = $prefix . 'work_allocations';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT activity_type, kind, capacity, COUNT(*) AS entry_count, COALESCE(SUM(duration_seconds), 0) AS duration_seconds
                 FROM {$entries}
                 WHERE user_id = %d AND deleted_at IS NULL AND work_date BETWEEN %s AND %s
                 GROUP BY activity_type, kind, capacity
                 ORDER BY duration_seconds DESC",
                (int) $user_id,
                $start_date,
                $end_date
            )
        );
        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(duration_seconds), 0) FROM {$entries}
                 WHERE user_id = %d AND deleted_at IS NULL AND work_date BETWEEN %s AND %s",
                (int) $user_id,
                $start_date,
                $end_date
            )
        );
        $allocated = $this->personalAllocatedSeconds( $user_id, $start_date, $end_date );
        $task_linked = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(allocation.seconds), 0)
                 FROM {$allocations} allocation
                 INNER JOIN {$entries} entry ON entry.id = allocation.work_entry_id
                 WHERE entry.user_id = %d AND entry.deleted_at IS NULL
                   AND allocation.task_id_snapshot IS NOT NULL
                   AND entry.work_date BETWEEN %s AND %s",
                (int) $user_id,
                $start_date,
                $end_date
            )
        );
        $board_only = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(allocation.seconds), 0)
                 FROM {$allocations} allocation
                 INNER JOIN {$entries} entry ON entry.id = allocation.work_entry_id
                 WHERE entry.user_id = %d AND entry.deleted_at IS NULL
                   AND allocation.task_id_snapshot IS NULL
                   AND allocation.board_name_snapshot IS NOT NULL
                   AND allocation.board_name_snapshot <> ''
                   AND entry.work_date BETWEEN %s AND %s",
                (int) $user_id,
                $start_date,
                $end_date
            )
        );
        $residual = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(duration_seconds), 0) FROM {$entries}
                 WHERE user_id = %d AND deleted_at IS NULL AND kind = 'residual' AND work_date BETWEEN %s AND %s",
                (int) $user_id,
                $start_date,
                $end_date
            )
        );
        $post_completion = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(allocation.seconds), 0)
                 FROM {$allocations} allocation
                 INNER JOIN {$entries} entry ON entry.id = allocation.work_entry_id
                 WHERE entry.user_id = %d AND entry.deleted_at IS NULL
                   AND allocation.allocation_context = 'post_completion'
                   AND entry.work_date BETWEEN %s AND %s",
                (int) $user_id,
                $start_date,
                $end_date
            )
        );
        $task_breakdown = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT allocation.task_id_snapshot AS id, allocation.task_name_snapshot AS name,
                        COALESCE(SUM(allocation.seconds), 0) AS duration_seconds
                 FROM {$allocations} allocation
                 INNER JOIN {$entries} entry ON entry.id = allocation.work_entry_id
                 WHERE entry.user_id = %d AND entry.deleted_at IS NULL
                   AND allocation.task_id_snapshot IS NOT NULL
                   AND entry.work_date BETWEEN %s AND %s
                 GROUP BY allocation.task_id_snapshot, allocation.task_name_snapshot
                 ORDER BY duration_seconds DESC",
                (int) $user_id, $start_date, $end_date
            )
        );
        $board_breakdown = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT allocation.board_name_snapshot AS id, allocation.board_name_snapshot AS name,
                        COALESCE(SUM(allocation.seconds), 0) AS duration_seconds
                 FROM {$allocations} allocation
                 INNER JOIN {$entries} entry ON entry.id = allocation.work_entry_id
                 WHERE entry.user_id = %d AND entry.deleted_at IS NULL
                   AND allocation.board_name_snapshot IS NOT NULL
                   AND allocation.board_name_snapshot <> ''
                   AND entry.work_date BETWEEN %s AND %s
                 GROUP BY allocation.board_name_snapshot
                 ORDER BY duration_seconds DESC",
                (int) $user_id, $start_date, $end_date
            )
        );
        $project_breakdown = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT allocation.project_id_snapshot AS id, allocation.project_name_snapshot AS name,
                        COALESCE(SUM(allocation.seconds), 0) AS duration_seconds
                 FROM {$allocations} allocation
                 INNER JOIN {$entries} entry ON entry.id = allocation.work_entry_id
                 WHERE entry.user_id = %d AND entry.deleted_at IS NULL
                   AND allocation.project_id_snapshot IS NOT NULL
                   AND entry.work_date BETWEEN %s AND %s
                 GROUP BY allocation.project_id_snapshot, allocation.project_name_snapshot
                 ORDER BY duration_seconds DESC",
                (int) $user_id, $start_date, $end_date
            )
        );
        $category_breakdown = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT allocation.category_id_snapshot AS id, allocation.category_name_snapshot AS name,
                        COALESCE(SUM(allocation.seconds), 0) AS duration_seconds
                 FROM {$allocations} allocation
                 INNER JOIN {$entries} entry ON entry.id = allocation.work_entry_id
                 WHERE entry.user_id = %d AND entry.deleted_at IS NULL
                   AND allocation.category_id_snapshot IS NOT NULL
                   AND entry.work_date BETWEEN %s AND %s
                 GROUP BY allocation.category_id_snapshot, allocation.category_name_snapshot
                 ORDER BY duration_seconds DESC",
                (int) $user_id, $start_date, $end_date
            )
        );
        $capacity_breakdown = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT capacity AS name, kind, COALESCE(SUM(duration_seconds), 0) AS duration_seconds
                 FROM {$entries}
                 WHERE user_id = %d AND deleted_at IS NULL AND work_date BETWEEN %s AND %s
                 GROUP BY capacity, kind
                 ORDER BY duration_seconds DESC",
                (int) $user_id, $start_date, $end_date
            )
        );
        return array(
            'total_seconds'          => $total,
            'allocated_seconds'      => $allocated,
            'task_linked_seconds'    => $task_linked,
            'task_detailed_seconds'  => max( 0, $task_linked - $residual ),
            'board_only_seconds'     => $board_only,
            'unallocated_seconds'    => max( 0, $total - $allocated ),
            'residual_seconds'       => $residual,
            'post_completion_seconds' => $post_completion,
            'breakdown'              => $rows,
            'activity_breakdown'     => $rows,
            'task_breakdown'         => $task_breakdown,
            'board_breakdown'        => $board_breakdown,
            'project_breakdown'      => $project_breakdown,
            'category_breakdown'     => $category_breakdown,
            'capacity_breakdown'     => $capacity_breakdown,
        );
    }

    public function boardSummary( $board_name, $start_date, $end_date ) {
        global $wpdb;
        $prefix = DatabaseContext::getDbPrefix();
        $entries = $prefix . 'work_entries';
        $allocations = $prefix . 'work_allocations';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT entry.activity_type, entry.kind, entry.capacity,
                        COALESCE(SUM(allocation.seconds), 0) AS duration_seconds,
                        COUNT(DISTINCT entry.id) AS entry_count
                 FROM {$allocations} allocation
                 INNER JOIN {$entries} entry ON entry.id = allocation.work_entry_id
                 WHERE allocation.board_name_snapshot = %s
                   AND entry.deleted_at IS NULL
                   AND entry.work_date BETWEEN %s AND %s
                 GROUP BY entry.activity_type, entry.kind, entry.capacity
                 ORDER BY duration_seconds DESC",
                $board_name,
                $start_date,
                $end_date
            )
        );
        $total = 0;
        $residual = 0;
        foreach ( $rows as $row ) {
            $seconds = (int) $row->duration_seconds;
            $total += $seconds;
            if ( 'residual' === (string) $row->kind ) {
                $residual += $seconds;
            }
        }
        $task_linked = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(allocation.seconds), 0)
                 FROM {$allocations} allocation
                 INNER JOIN {$entries} entry ON entry.id = allocation.work_entry_id
                 WHERE allocation.board_name_snapshot = %s
                   AND allocation.task_id_snapshot IS NOT NULL
                   AND entry.deleted_at IS NULL
                   AND entry.work_date BETWEEN %s AND %s",
                $board_name, $start_date, $end_date
            )
        );
        $board_only = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(allocation.seconds), 0)
                 FROM {$allocations} allocation
                 INNER JOIN {$entries} entry ON entry.id = allocation.work_entry_id
                 WHERE allocation.board_name_snapshot = %s
                   AND allocation.task_id_snapshot IS NULL
                   AND entry.deleted_at IS NULL
                   AND entry.work_date BETWEEN %s AND %s",
                $board_name, $start_date, $end_date
            )
        );
        $post_completion = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(allocation.seconds), 0)
                 FROM {$allocations} allocation
                 INNER JOIN {$entries} entry ON entry.id = allocation.work_entry_id
                 WHERE allocation.board_name_snapshot = %s
                   AND allocation.allocation_context = 'post_completion'
                   AND entry.deleted_at IS NULL
                   AND entry.work_date BETWEEN %s AND %s",
                $board_name, $start_date, $end_date
            )
        );
        $task_breakdown = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT allocation.task_id_snapshot AS id, allocation.task_name_snapshot AS name,
                        COALESCE(SUM(allocation.seconds), 0) AS duration_seconds
                 FROM {$allocations} allocation
                 INNER JOIN {$entries} entry ON entry.id = allocation.work_entry_id
                 WHERE allocation.board_name_snapshot = %s
                   AND allocation.task_id_snapshot IS NOT NULL
                   AND entry.deleted_at IS NULL
                   AND entry.work_date BETWEEN %s AND %s
                 GROUP BY allocation.task_id_snapshot, allocation.task_name_snapshot
                 ORDER BY duration_seconds DESC",
                $board_name, $start_date, $end_date
            )
        );
        $project_breakdown = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT allocation.project_id_snapshot AS id, allocation.project_name_snapshot AS name,
                        COALESCE(SUM(allocation.seconds), 0) AS duration_seconds
                 FROM {$allocations} allocation
                 INNER JOIN {$entries} entry ON entry.id = allocation.work_entry_id
                 WHERE allocation.board_name_snapshot = %s
                   AND allocation.project_id_snapshot IS NOT NULL
                   AND entry.deleted_at IS NULL
                   AND entry.work_date BETWEEN %s AND %s
                 GROUP BY allocation.project_id_snapshot, allocation.project_name_snapshot
                 ORDER BY duration_seconds DESC",
                $board_name, $start_date, $end_date
            )
        );
        $category_breakdown = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT allocation.category_id_snapshot AS id, allocation.category_name_snapshot AS name,
                        COALESCE(SUM(allocation.seconds), 0) AS duration_seconds
                 FROM {$allocations} allocation
                 INNER JOIN {$entries} entry ON entry.id = allocation.work_entry_id
                 WHERE allocation.board_name_snapshot = %s
                   AND allocation.category_id_snapshot IS NOT NULL
                   AND entry.deleted_at IS NULL
                   AND entry.work_date BETWEEN %s AND %s
                 GROUP BY allocation.category_id_snapshot, allocation.category_name_snapshot
                 ORDER BY duration_seconds DESC",
                $board_name, $start_date, $end_date
            )
        );
        $capacity_breakdown = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT entry.capacity AS name, entry.kind, COALESCE(SUM(allocation.seconds), 0) AS duration_seconds
                 FROM {$allocations} allocation
                 INNER JOIN {$entries} entry ON entry.id = allocation.work_entry_id
                 WHERE allocation.board_name_snapshot = %s
                   AND entry.deleted_at IS NULL
                   AND entry.work_date BETWEEN %s AND %s
                 GROUP BY entry.capacity, entry.kind
                 ORDER BY duration_seconds DESC",
                $board_name, $start_date, $end_date
            )
        );
        return array(
            'total_seconds'          => $total,
            'allocated_seconds'      => $total,
            'task_linked_seconds'    => $task_linked,
            'task_detailed_seconds'  => max( 0, $task_linked - $residual ),
            'board_only_seconds'     => $board_only,
            'unallocated_seconds'    => 0,
            'residual_seconds'       => $residual,
            'post_completion_seconds' => $post_completion,
            'breakdown'              => $rows,
            'activity_breakdown'     => $rows,
            'task_breakdown'         => $task_breakdown,
            'board_breakdown'        => array(),
            'project_breakdown'      => $project_breakdown,
            'category_breakdown'     => $category_breakdown,
            'capacity_breakdown'     => $capacity_breakdown,
        );
    }

    public function unresolvedOccurrencesForUser( $user_id, $limit = 50 ) {
        global $wpdb;
        $prefix = DatabaseContext::getDbPrefix();
        $occurrences = $prefix . 'task_work_occurrences';
        $resolutions = $prefix . 'task_time_resolutions';
        $limit = max( 1, min( 100, (int) $limit ) );
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT occurrence.id AS occurrence_id,
                        occurrence.task_id,
                        occurrence.task_name_snapshot,
                        occurrence.board_name_snapshot,
                        occurrence.completed_at,
                        resolution.specific_seconds
                 FROM {$resolutions} resolution
                 INNER JOIN {$occurrences} occurrence ON occurrence.id = resolution.occurrence_id
                 WHERE occurrence.state = 'completed'
                   AND resolution.user_id = %d
                   AND resolution.state = 'unresolved'
                   AND resolution.id = (
                       SELECT latest.id
                       FROM {$resolutions} latest
                       WHERE latest.occurrence_id = resolution.occurrence_id
                         AND latest.user_id = resolution.user_id
                       ORDER BY latest.revision DESC, latest.id DESC
                       LIMIT 1
                   )
                 ORDER BY occurrence.completed_at DESC, occurrence.id DESC
                 LIMIT %d",
                (int) $user_id,
                $limit
            )
        );
    }

    public function unresolvedOccurrenceCountForUser( $user_id ) {
        global $wpdb;
        $prefix = DatabaseContext::getDbPrefix();
        $occurrences = $prefix . 'task_work_occurrences';
        $resolutions = $prefix . 'task_time_resolutions';
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$resolutions} resolution
                 INNER JOIN {$occurrences} occurrence ON occurrence.id = resolution.occurrence_id
                 WHERE occurrence.state = 'completed'
                   AND resolution.user_id = %d
                   AND resolution.state = 'unresolved'
                   AND resolution.id = (
                       SELECT latest.id
                       FROM {$resolutions} latest
                       WHERE latest.occurrence_id = resolution.occurrence_id
                         AND latest.user_id = resolution.user_id
                       ORDER BY latest.revision DESC, latest.id DESC
                       LIMIT 1
                   )",
                (int) $user_id
            )
        );
    }

    public function unresolvedOccurrenceCount( $board_name ) {
        global $wpdb;
        $prefix = DatabaseContext::getDbPrefix();
        $occurrences = $prefix . 'task_work_occurrences';
        $resolutions = $prefix . 'task_time_resolutions';
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$resolutions} resolution
                 INNER JOIN {$occurrences} occurrence ON occurrence.id = resolution.occurrence_id
                 WHERE occurrence.board_name_snapshot = %s
                   AND occurrence.state = 'completed'
                   AND resolution.state = 'unresolved'
                   AND resolution.id = (
                       SELECT latest.id
                       FROM {$resolutions} latest
                       WHERE latest.occurrence_id = resolution.occurrence_id
                         AND latest.user_id = resolution.user_id
                       ORDER BY latest.revision DESC, latest.id DESC
                       LIMIT 1
                   )",
                $board_name
            )
        );
    }

    private function personalAllocatedSeconds( $user_id, $start_date, $end_date ) {
        global $wpdb;
        $prefix = DatabaseContext::getDbPrefix();
        $entries = $prefix . 'work_entries';
        $allocations = $prefix . 'work_allocations';
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(allocation.seconds), 0)
                 FROM {$allocations} allocation
                 INNER JOIN {$entries} entry ON entry.id = allocation.work_entry_id
                 WHERE entry.user_id = %d AND entry.deleted_at IS NULL AND entry.work_date BETWEEN %s AND %s",
                (int) $user_id,
                $start_date,
                $end_date
            )
        );
    }
}
