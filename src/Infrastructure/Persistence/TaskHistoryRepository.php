<?php

namespace Pandatask\Infrastructure\Persistence;

final class TaskHistoryRepository {

    public function addEntry( $task_id, $user_id, $field_changed, $old_value = '', $new_value = '', $change_comment = '' ) {
        global $wpdb;

        $history_table = DatabaseContext::getDbPrefix() . 'task_history';

        return $wpdb->insert(
            $history_table,
            array(
                'task_id'         => $task_id,
                'user_id'         => $user_id,
                'field_changed'   => $field_changed,
                'old_value'       => $old_value,
                'new_value'       => $new_value,
                'change_comment'  => $change_comment,
                'changed_at'      => gmdate( 'Y-m-d H:i:s' ),
            ),
            array( '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
        );
    }

    public function getTaskHistory( $task_id ) {
        global $wpdb;

        $history_table = DatabaseContext::getDbPrefix() . 'task_history';
        $users_table   = $wpdb->users;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT h.*, u.display_name as user_name
                 FROM {$history_table} h
                 LEFT JOIN {$users_table} u ON h.user_id = u.ID
                 WHERE h.task_id = %d
                 ORDER BY h.changed_at DESC",
                $task_id
            )
        );
    }
}
