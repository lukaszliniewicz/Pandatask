<?php

namespace Pandatask\Infrastructure\Persistence;

final class WorkReportRepository {

    public function personalSummary( $user_id, $start_date, $end_date ) {
        global $wpdb;
        $entries = DatabaseContext::getDbPrefix() . 'work_entries';
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
        $residual = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(duration_seconds), 0) FROM {$entries}
                 WHERE user_id = %d AND deleted_at IS NULL AND kind = 'residual' AND work_date BETWEEN %s AND %s",
                (int) $user_id,
                $start_date,
                $end_date
            )
        );
        return array(
            'total_seconds'       => $total,
            'allocated_seconds'   => $allocated,
            'unallocated_seconds' => max( 0, $total - $allocated ),
            'residual_seconds'    => $residual,
            'breakdown'           => $rows,
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
        foreach ( $rows as $row ) {
            $total += (int) $row->duration_seconds;
        }
        return array( 'total_seconds' => $total, 'breakdown' => $rows );
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
