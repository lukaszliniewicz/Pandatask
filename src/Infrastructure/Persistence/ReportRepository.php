<?php

namespace Pandatask\Infrastructure\Persistence;

final class ReportRepository {

    public function findReportData( $board_name, $start_date, $end_date ) {
        global $wpdb;

        $prefix            = DatabaseContext::getDbPrefix();
        $tasks_table       = $prefix . 'tasks';
        $assignments_table = $prefix . 'assignments';
        $users_table       = $wpdb->users;
        $today             = current_time( 'Y-m-d' );

        $tasks_added = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT t.id, t.name, t.created_at, GROUP_CONCAT(DISTINCT u.display_name SEPARATOR ', ') as assigned_user_names
                 FROM {$tasks_table} t
                 LEFT JOIN {$assignments_table} a ON t.id = a.task_id AND a.role = 'assignee'
                 LEFT JOIN {$users_table} u ON a.user_id = u.ID
                 WHERE t.board_name = %s AND DATE(t.created_at) BETWEEN %s AND %s
                 GROUP BY t.id
                 ORDER BY t.created_at DESC",
                $board_name,
                $start_date,
                $end_date
            )
        );

        $tasks_completed = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT t.id, t.name, t.completed_at, GROUP_CONCAT(DISTINCT u.display_name SEPARATOR ', ') as assigned_user_names
                 FROM {$tasks_table} t
                 LEFT JOIN {$assignments_table} a ON t.id = a.task_id AND a.role = 'assignee'
                 LEFT JOIN {$users_table} u ON a.user_id = u.ID
                 WHERE t.board_name = %s AND t.status = 'done' AND t.completed_at IS NOT NULL AND DATE(t.completed_at) BETWEEN %s AND %s
                 GROUP BY t.id
                 ORDER BY t.completed_at DESC",
                $board_name,
                $start_date,
                $end_date
            )
        );

        $missed_deadlines = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT t.id, t.name, t.deadline, DATEDIFF(%s, t.deadline) as days_overdue, GROUP_CONCAT(DISTINCT u.display_name SEPARATOR ', ') as assigned_user_names
                 FROM {$tasks_table} t
                 LEFT JOIN {$assignments_table} a ON t.id = a.task_id AND a.role = 'assignee'
                 LEFT JOIN {$users_table} u ON a.user_id = u.ID
                 WHERE t.board_name = %s AND t.deadline IS NOT NULL AND t.deadline < %s AND t.status != 'done' AND archived = 0 AND t.deadline BETWEEN %s AND %s
                 GROUP BY t.id
                 ORDER BY t.deadline ASC",
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

        return array(
            'tasks_added'      => $tasks_added,
            'tasks_completed'  => $tasks_completed,
            'missed_deadlines' => $missed_deadlines,
            'tasks_per_person' => $tasks_per_person,
        );
    }
}
