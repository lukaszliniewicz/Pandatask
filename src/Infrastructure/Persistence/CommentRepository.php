<?php

namespace Pandatask\Infrastructure\Persistence;

final class CommentRepository {

    public function findForTask( $task_id, $task = null ) {
        global $wpdb;

        $prefix         = DatabaseContext::getDbPrefix();
        $comments_table = $prefix . 'comments';
        $users_table    = $wpdb->users;
        $comments       = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT c.*, u.display_name as user_name
                 FROM {$comments_table} c
                 JOIN {$users_table} u ON c.user_id = u.ID
                 WHERE c.task_id = %d
                 ORDER BY c.created_at ASC",
                $task_id
            )
        );

        if ( $comments ) {
            foreach ( $comments as $comment ) {
                $this->hydrateExistingComment( $comment, $task );
            }
        }

        return $comments;
    }

    public function create( $task_id, $user_id, $comment_text ) {
        global $wpdb;

        $prefix         = DatabaseContext::getDbPrefix();
        $comments_table = $prefix . 'comments';
        $users_table    = $wpdb->users;
        $data           = array(
            'task_id'      => absint( $task_id ),
            'user_id'      => absint( $user_id ),
            'comment_text' => wp_kses_post( $comment_text ),
            'created_at'   => gmdate( 'Y-m-d H:i:s' ),
        );
        $result         = $wpdb->insert( $comments_table, $data, array( '%d', '%d', '%s', '%s' ) );

        if ( ! $result ) {
            return false;
        }

        $comment_id = $wpdb->insert_id;
        $comment    = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT c.*, u.display_name as user_name
                 FROM {$comments_table} c
                 JOIN {$users_table} u ON c.user_id = u.ID
                 WHERE c.id = %d",
                $comment_id
            )
        );

        if ( $comment ) {
            $comment->user_avatar_url      = get_avatar_url( $comment->user_id, array( 'size' => 48, 'default' => 'mystery' ) );
            $comment->can_manage           = true;
            $created_timestamp             = strtotime( $comment->created_at );
            $comment->created_at_formatted = __( 'just now', 'pandatask' );
            $comment->created_at_tooltip   = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $created_timestamp );
            $comment->is_edited            = false;
        }

        return $comment;
    }

    public function canUserManageComment( $comment, $task = null ) {
        $current_user_id = get_current_user_id();

        if ( ! $current_user_id ) {
            return false;
        }

        if ( current_user_can( 'manage_options' ) ) {
            return true;
        }

        if ( $comment->user_id == $current_user_id ) {
            return true;
        }

        if ( ! $task ) {
            global $wpdb;

            $tasks_table      = DatabaseContext::getDbPrefix() . 'tasks';
            $task_board_name  = $wpdb->get_var( $wpdb->prepare( "SELECT board_name FROM {$tasks_table} WHERE id = %d", $comment->task_id ) );
        } else {
            $task_board_name = $task->board_name;
        }

        if ( $task_board_name && preg_match( '/^group_(\d+)$/', $task_board_name, $matches ) ) {
            $group_id = intval( $matches[1] );

            if ( function_exists( 'groups_is_user_admin' ) && ( groups_is_user_admin( $current_user_id, $group_id ) || groups_is_user_mod( $current_user_id, $group_id ) ) ) {
                return true;
            }
        }

        return false;
    }

    public function findById( $comment_id ) {
        global $wpdb;

        $comments_table = DatabaseContext::getDbPrefix() . 'comments';

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$comments_table} WHERE id = %d",
                $comment_id
            )
        );
    }

    public function update( $comment_id, $comment_text ) {
        global $wpdb;

        $comments_table = DatabaseContext::getDbPrefix() . 'comments';
        $result         = $wpdb->update(
            $comments_table,
            array( 'comment_text' => wp_kses_post( $comment_text ) ),
            array( 'id' => $comment_id ),
            array( '%s' ),
            array( '%d' )
        );

        return false !== $result;
    }

    public function delete( $comment_id ) {
        global $wpdb;

        $comments_table = DatabaseContext::getDbPrefix() . 'comments';
        $result         = $wpdb->delete( $comments_table, array( 'id' => $comment_id ), array( '%d' ) );

        return (bool) $result;
    }

    private function hydrateExistingComment( $comment, $task = null ) {
        $comment->user_avatar_url = get_avatar_url( $comment->user_id, array( 'size' => 48, 'default' => 'mystery' ) );
        $comment->can_manage      = $this->canUserManageComment( $comment, $task );

        $created_timestamp             = strtotime( $comment->created_at );
        $updated_timestamp             = strtotime( $comment->updated_at );
        $comment->created_at_formatted = sprintf(
            /* translators: %s: Human-readable time difference. */
            __( '%s ago', 'pandatask' ),
            human_time_diff( $created_timestamp, time() )
        );
        $comment->created_at_tooltip = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $created_timestamp );
        $comment->is_edited          = ( $updated_timestamp - $created_timestamp > 60 );

        if ( $comment->is_edited ) {
            $comment->updated_at_tooltip = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $updated_timestamp );
        }
    }
}
