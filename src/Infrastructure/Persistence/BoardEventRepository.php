<?php

namespace Pandatask\Infrastructure\Persistence;

final class BoardEventRepository {

    public function addEvent( $board_name, $task, $actor_id, $event_type, $promote = false, array $event_data = array(), $created_at = null, $source_activity_id = null ) {
        global $wpdb;

        $board_name = sanitize_key( (string) $board_name );
        $task_id = (int) ( is_object( $task ) ? ( $task->id ?? 0 ) : ( $task['id'] ?? 0 ) );
        $task_name = (string) ( is_object( $task ) ? ( $task->name ?? '' ) : ( $task['name'] ?? '' ) );
        $task_status = (string) ( is_object( $task ) ? ( $task->status ?? '' ) : ( $task['status'] ?? '' ) );
        $event_type = sanitize_key( (string) $event_type );
        $created_at = $created_at ?: gmdate( 'Y-m-d H:i:s' );
        $source_activity_id = $source_activity_id ? (int) $source_activity_id : null;

        if ( '' === $board_name || $task_id < 1 || '' === $task_name || '' === $event_type ) {
            return false;
        }

        $table = DatabaseContext::getDbPrefix() . 'board_events';
        $inserted = $wpdb->insert(
            $table,
            array(
                'board_name'         => $board_name,
                'task_id'            => $task_id,
                'actor_id'           => max( 0, (int) $actor_id ),
                'event_type'         => $event_type,
                'task_name'          => $task_name,
                'task_status'        => $task_status ?: null,
                'event_data'         => empty( $event_data ) ? null : wp_json_encode( $event_data ),
                'promote'            => $promote ? 1 : 0,
                'source_activity_id' => $source_activity_id,
                'created_at'         => $created_at,
            ),
            array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
        );

        if ( false === $inserted ) {
            return false;
        }

        return (int) $wpdb->insert_id;
    }

    public function getBoardEvents( $board_name, $limit = 20 ) {
        global $wpdb;

        $limit = max( 1, min( 100, (int) $limit ) );
        $table = DatabaseContext::getDbPrefix() . 'board_events';
        $tasks_table = DatabaseContext::getDbPrefix() . 'tasks';
        $users_table = $wpdb->users;

        $events = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT e.*,
                        COALESCE(u.display_name, 'System or deleted user') AS actor_name,
                        t.board_name AS current_board_name,
                        CASE WHEN t.id IS NULL THEN 0 ELSE 1 END AS task_exists
                 FROM {$table} e
                 LEFT JOIN {$users_table} u ON u.ID = e.actor_id
                 LEFT JOIN {$tasks_table} t ON t.id = e.task_id
                 WHERE e.board_name = %s
                 ORDER BY e.created_at DESC, e.id DESC
                 LIMIT %d",
                $board_name,
                $limit
            )
        );

        foreach ( (array) $events as $event ) {
            $decoded = array();
            if ( ! empty( $event->event_data ) ) {
                $value = json_decode( (string) $event->event_data, true );
                if ( is_array( $value ) ) {
                    $decoded = $value;
                }
            }
            $event->event_data = $decoded;
            $event->promote = (bool) $event->promote;
            $event->task_exists = (bool) $event->task_exists;
        }

        return $events;
    }

    public function getBoardSummary( $board_name ) {
        global $wpdb;

        $table = DatabaseContext::getDbPrefix() . 'tasks';
        $today = wp_date( 'Y-m-d' );
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                    SUM(CASE WHEN status = 'in-progress' THEN 1 ELSE 0 END) AS in_progress_count,
                    SUM(CASE WHEN status <> 'done' THEN 1 ELSE 0 END) AS open_count,
                    SUM(CASE WHEN status <> 'done' AND deadline = %s THEN 1 ELSE 0 END) AS due_today_count,
                    SUM(CASE WHEN status <> 'done' AND deadline IS NOT NULL AND deadline < %s THEN 1 ELSE 0 END) AS overdue_count
                 FROM {$table}
                 WHERE board_name = %s
                   AND archived = 0",
                $today,
                $today,
                $board_name
            ),
            ARRAY_A
        );

        return array(
            'pending'     => (int) ( $row['pending_count'] ?? 0 ),
            'in_progress' => (int) ( $row['in_progress_count'] ?? 0 ),
            'open'        => (int) ( $row['open_count'] ?? 0 ),
            'due_today'   => (int) ( $row['due_today_count'] ?? 0 ),
            'overdue'     => (int) ( $row['overdue_count'] ?? 0 ),
        );
    }

    public function hasSourceActivity( $activity_id ) {
        global $wpdb;

        $activity_id = (int) $activity_id;
        if ( $activity_id < 1 ) {
            return false;
        }

        $table = DatabaseContext::getDbPrefix() . 'board_events';

        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE source_activity_id = %d LIMIT 1",
                $activity_id
            )
        );
    }
}
