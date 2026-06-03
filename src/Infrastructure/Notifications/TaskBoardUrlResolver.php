<?php

namespace Pandatask\Infrastructure\Notifications;

final class TaskBoardUrlResolver {

    public static function resolve( $board_name, $task_id = 0 ) {
        $transient_key = 'pandat69_board_url_' . sanitize_key( $board_name );
        $base_url      = get_transient( $transient_key );

        if ( false === $base_url ) {
            global $wpdb;

            if ( preg_match( '/^group_(\d+)$/', $board_name, $matches ) ) {
                $group_id = intval( $matches[1] );

                if ( $group_id > 0 && function_exists( 'bp_get_group_permalink' ) && function_exists( 'groups_get_group' ) ) {
                    $group = groups_get_group( $group_id );

                    if ( $group && ! empty( $group->slug ) ) {
                        $group_url = bp_get_group_permalink( $group );
                        $base_url  = trailingslashit( $group_url ) . 'tasks';
                    }
                }
            }

            if ( ! $base_url ) {
                $shortcode_pattern     = '[task_board board_name="' . $board_name . '"';
                $alt_shortcode_pattern = "[task_board board_name='" . $board_name . "'";

                $post_id = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT ID FROM $wpdb->posts
                        WHERE (post_content LIKE %s OR post_content LIKE %s)
                        AND post_status = 'publish' AND post_type IN ('page', 'post')
                        ORDER BY post_date DESC
                        LIMIT 1",
                        '%' . $wpdb->esc_like( $shortcode_pattern ) . '%',
                        '%' . $wpdb->esc_like( $alt_shortcode_pattern ) . '%'
                    )
                );

                if ( $post_id ) {
                    $base_url = get_permalink( $post_id );
                }
            }

            if ( $base_url ) {
                set_transient( $transient_key, $base_url, DAY_IN_SECONDS );
            } else {
                set_transient( $transient_key, 'not_found', DAY_IN_SECONDS );
            }
        }

        if ( $base_url && 'not_found' !== $base_url ) {
            if ( $task_id > 0 ) {
                return add_query_arg( 'open_task', $task_id, $base_url );
            }

            return $base_url;
        }

        return false;
    }
}
