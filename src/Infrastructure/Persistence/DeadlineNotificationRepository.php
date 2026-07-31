<?php

namespace Pandatask\Infrastructure\Persistence;

final class DeadlineNotificationRepository {

    /**
     * Includes catch-up days so a delayed WP-Cron run does not permanently miss
     * a configured reminder.
     *
     * @return array<object>
     */
    public function findApproaching( $today, $limit = 500 ): array {
        global $wpdb;

        $tasks = DatabaseContext::getDbPrefix() . 'tasks';
        $limit = max( 1, min( 1000, (int) $limit ) );

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT *
                 FROM {$tasks}
                 WHERE notify_deadline = 1
                   AND archived = 0
                   AND status <> 'done'
                   AND deadline IS NOT NULL
                   AND deadline > %s
                   AND deadline <= DATE_ADD(%s, INTERVAL notify_days_before DAY)
                   AND (deadline_reminder_sent_for IS NULL OR deadline_reminder_sent_for <> deadline)
                 ORDER BY deadline ASC, id ASC
                 LIMIT %d",
                $today,
                $today,
                $limit
            )
        );
    }

    /**
     * @return array<object>
     */
    public function findMissed( $today, $limit = 500 ): array {
        global $wpdb;

        $tasks = DatabaseContext::getDbPrefix() . 'tasks';
        $limit = max( 1, min( 1000, (int) $limit ) );

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT *
                 FROM {$tasks}
                 WHERE missed_deadline_notified = 0
                   AND archived = 0
                   AND status <> 'done'
                   AND deadline IS NOT NULL
                   AND deadline < %s
                 ORDER BY deadline ASC, id ASC
                 LIMIT %d",
                $today,
                $limit
            )
        );
    }

    /**
     * @param array<int>    $task_ids Task identifiers.
     * @param array<string> $roles Assignment roles.
     * @return array<int,array<int>>
     */
    public function findRecipientMap( array $task_ids, array $roles ): array {
        global $wpdb;

        $task_ids = array_values( array_filter( array_map( 'absint', $task_ids ) ) );
        $roles = array_values( array_intersect( $roles, array( 'assignee', 'supervisor' ) ) );

        if ( empty( $task_ids ) || empty( $roles ) ) {
            return array();
        }

        $assignments = DatabaseContext::getDbPrefix() . 'assignments';
        $task_ids_sql = implode( ',', $task_ids );
        $role_placeholders = implode( ',', array_fill( 0, count( $roles ), '%s' ) );
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT task_id, user_id
                 FROM {$assignments}
                 WHERE task_id IN ({$task_ids_sql})
                   AND role IN ({$role_placeholders})
                 ORDER BY task_id ASC, user_id ASC",
                ...$roles
            )
        );
        $map = array();
        $all_user_ids = array();

        foreach ( $rows as $row ) {
            $task_id = (int) $row->task_id;
            $user_id = (int) $row->user_id;
            $map[ $task_id ][ $user_id ] = $user_id;
            $all_user_ids[ $user_id ] = $user_id;
        }

        if ( ! empty( $all_user_ids ) && function_exists( 'cache_users' ) ) {
            cache_users( array_values( $all_user_ids ) );
        }

        return array_map( 'array_values', $map );
    }

    public function markApproachingSent( $task_id, $deadline ): bool {
        global $wpdb;

        $tasks = DatabaseContext::getDbPrefix() . 'tasks';

        return false !== $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$tasks}
                 SET deadline_reminder_sent_for = deadline,
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = %d AND deadline = %s",
                $task_id,
                $deadline
            )
        );
    }

    public function markMissedSent( $task_id, $deadline ): bool {
        global $wpdb;

        $tasks = DatabaseContext::getDbPrefix() . 'tasks';

        return false !== $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$tasks}
                 SET missed_deadline_notified = 1,
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = %d AND deadline = %s",
                $task_id,
                $deadline
            )
        );
    }
}
