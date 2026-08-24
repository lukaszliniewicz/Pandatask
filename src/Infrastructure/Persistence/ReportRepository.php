<?php

namespace Pandatask\Infrastructure\Persistence;

final class ReportRepository {

    public function findReportData( $board_name, $start_date, $end_date ) {
        global $wpdb;

        $prefix            = DatabaseContext::getDbPrefix();
        $tasks_table       = $prefix . 'tasks';
        $occurrences_table = $prefix . 'task_work_occurrences';
        $assignments_table = $prefix . 'assignments';
        $resolutions_table = $prefix . 'task_time_resolutions';
        $users_table       = $wpdb->users;
        $today             = current_time( 'Y-m-d' );
        $site_timezone     = wp_timezone();
        $utc_timezone      = new \DateTimeZone( 'UTC' );
        $range_start_utc   = ( new \DateTimeImmutable( $start_date . ' 00:00:00', $site_timezone ) )->setTimezone( $utc_timezone )->format( 'Y-m-d H:i:s' );
        $range_end_utc     = ( new \DateTimeImmutable( $end_date . ' 00:00:00', $site_timezone ) )->modify( '+1 day' )->setTimezone( $utc_timezone )->format( 'Y-m-d H:i:s' );

        $tasks_added = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT t.id, t.name, t.created_at
                 FROM {$tasks_table} t
                 WHERE t.board_name = %s AND t.created_at >= %s AND t.created_at < %s
                 ORDER BY t.created_at DESC, t.id DESC",
                $board_name,
                $range_start_utc,
                $range_end_utc
            )
        );

        $tasks_completed = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT occurrence.task_id AS id,
                        occurrence.id AS occurrence_id,
                        occurrence.task_name_snapshot AS name,
                        occurrence.completed_at
                 FROM {$occurrences_table} occurrence
                 WHERE occurrence.board_name_snapshot = %s
                   AND occurrence.state = 'completed'
                   AND occurrence.completed_at IS NOT NULL
                   AND occurrence.completed_at >= %s
                   AND occurrence.completed_at < %s
                 ORDER BY occurrence.completed_at DESC, occurrence.id DESC",
                $board_name,
                $range_start_utc,
                $range_end_utc
            )
        );

        $missed_deadlines = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT t.id, t.name, t.deadline, DATEDIFF(%s, t.deadline) as days_overdue
                 FROM {$tasks_table} t
                 WHERE t.board_name = %s AND t.deadline IS NOT NULL AND t.deadline < %s AND t.status != 'done' AND archived = 0 AND t.deadline BETWEEN %s AND %s
                 ORDER BY t.deadline ASC, t.id ASC",
                $today,
                $board_name,
                $today,
                $start_date,
                $end_date
            )
        );

        $tasks_per_person = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT u.display_name, COUNT(t.id) as task_count
                 FROM {$assignments_table} a
                 JOIN {$users_table} u ON a.user_id = u.ID
                 JOIN {$tasks_table} t ON a.task_id = t.id
                 WHERE t.board_name = %s AND t.status != 'done' AND t.archived = 0 AND a.role = 'assignee'
                 GROUP BY u.ID, u.display_name
                 ORDER BY task_count DESC, u.display_name ASC",
                $board_name
            )
        );

        $this->hydrateAssignedUserNames(
            array_merge( (array) $tasks_added, (array) $tasks_completed, (array) $missed_deadlines )
        );

        return array(
            'tasks_added'      => $tasks_added,
            'tasks_completed'  => $tasks_completed,
            'missed_deadlines' => $missed_deadlines,
            'tasks_per_person' => $tasks_per_person,
        );
    }

    public function findUserReportData( $user_id, $start_date, $end_date ) {
        global $wpdb;

        $prefix            = DatabaseContext::getDbPrefix();
        $tasks_table       = $prefix . 'tasks';
        $occurrences_table = $prefix . 'task_work_occurrences';
        $assignments_table = $prefix . 'assignments';
        $resolutions_table = $prefix . 'task_time_resolutions';
        $users_table       = $wpdb->users;
        $today             = current_time( 'Y-m-d' );
        $site_timezone     = wp_timezone();
        $utc_timezone      = new \DateTimeZone( 'UTC' );
        $range_start_utc   = ( new \DateTimeImmutable( $start_date . ' 00:00:00', $site_timezone ) )->setTimezone( $utc_timezone )->format( 'Y-m-d H:i:s' );
        $range_end_utc     = ( new \DateTimeImmutable( $end_date . ' 00:00:00', $site_timezone ) )->modify( '+1 day' )->setTimezone( $utc_timezone )->format( 'Y-m-d H:i:s' );
        $scope = "(t.creator_id = %d OR EXISTS (SELECT 1 FROM {$assignments_table} mine WHERE mine.task_id = t.id AND mine.user_id = %d))";

        $tasks_added = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT t.id, t.name, t.created_at FROM {$tasks_table} t
                 WHERE {$scope} AND t.created_at >= %s AND t.created_at < %s
                 ORDER BY t.created_at DESC, t.id DESC",
                (int) $user_id, (int) $user_id, $range_start_utc, $range_end_utc
            )
        );
        $tasks_completed = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT occurrence.task_id AS id,
                        occurrence.id AS occurrence_id,
                        occurrence.task_name_snapshot AS name,
                        occurrence.completed_at
                 FROM {$occurrences_table} occurrence
                 WHERE (
                    occurrence.creator_id_snapshot = %d
                    OR EXISTS (
                        SELECT 1 FROM {$assignments_table} mine
                        WHERE mine.task_id = occurrence.task_id AND mine.user_id = %d
                    )
                    OR EXISTS (
                        SELECT 1 FROM {$resolutions_table} historical_resolution
                        WHERE historical_resolution.occurrence_id = occurrence.id
                          AND historical_resolution.user_id = %d
                    )
                 )
                   AND occurrence.state = 'completed'
                   AND occurrence.completed_at IS NOT NULL
                   AND occurrence.completed_at >= %s
                   AND occurrence.completed_at < %s
                 ORDER BY occurrence.completed_at DESC, occurrence.id DESC",
                (int) $user_id,
                (int) $user_id,
                (int) $user_id,
                $range_start_utc,
                $range_end_utc
            )
        );
        $missed_deadlines = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT t.id, t.name, t.deadline, DATEDIFF(%s, t.deadline) as days_overdue FROM {$tasks_table} t
                 WHERE {$scope} AND t.deadline IS NOT NULL AND t.deadline < %s AND t.status != 'done' AND t.archived = 0 AND t.deadline BETWEEN %s AND %s
                 ORDER BY t.deadline ASC, t.id ASC",
                $today, (int) $user_id, (int) $user_id, $today, $start_date, $end_date
            )
        );
        $open_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT t.id) FROM {$tasks_table} t
                 WHERE {$scope} AND t.status != 'done' AND t.archived = 0",
                (int) $user_id, (int) $user_id
            )
        );
        $display_name = (string) $wpdb->get_var( $wpdb->prepare( "SELECT display_name FROM {$users_table} WHERE ID = %d", (int) $user_id ) );
        $tasks_per_person = array( (object) array( 'display_name' => $display_name, 'task_count' => $open_count ) );

        $this->hydrateAssignedUserNames( array_merge( (array) $tasks_added, (array) $tasks_completed, (array) $missed_deadlines ) );

        return array(
            'tasks_added'      => $tasks_added,
            'tasks_completed'  => $tasks_completed,
            'missed_deadlines' => $missed_deadlines,
            'tasks_per_person' => $tasks_per_person,
        );
    }

    private function hydrateAssignedUserNames( array $tasks ) {
        global $wpdb;

        $task_ids = array_values( array_unique( array_filter( array_map( 'absint', wp_list_pluck( $tasks, 'id' ) ) ) ) );

        if ( empty( $task_ids ) ) {
            return;
        }

        $assignments = DatabaseContext::getDbPrefix() . 'assignments';
        $users = $wpdb->users;
        $task_ids_sql = implode( ',', $task_ids );
        $rows = $wpdb->get_results(
            "SELECT assignment.task_id, user_record.display_name
             FROM {$assignments} assignment
             INNER JOIN {$users} user_record ON user_record.ID = assignment.user_id
             WHERE assignment.task_id IN ({$task_ids_sql})
               AND assignment.role = 'assignee'
             ORDER BY assignment.task_id ASC, user_record.display_name ASC, assignment.user_id ASC"
        );
        $names = array();

        foreach ( $rows as $row ) {
            $names[ (int) $row->task_id ][] = $row->display_name;
        }

        foreach ( $tasks as $task ) {
            $task->assigned_user_names = implode( ', ', array_values( array_unique( $names[ (int) $task->id ] ?? array() ) ) );
        }
    }
}
