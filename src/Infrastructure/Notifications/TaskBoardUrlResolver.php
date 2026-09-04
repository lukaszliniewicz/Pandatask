<?php

namespace Pandatask\Infrastructure\Notifications;

final class TaskBoardUrlResolver {

    public static function resolve( $board_name, $task_id = 0 ) {
        $base_url = self::resolveBoardBaseUrl( $board_name );

        if ( ! $base_url ) {
            return false;
        }

        if ( $task_id > 0 ) {
            return add_query_arg( 'open_task', $task_id, $base_url );
        }

        return $base_url;
    }

    /**
     * Resolve the canonical board URL for opening a project.
     *
     * @param mixed $board_name Board identifier.
     * @param mixed $project_id Project ID.
     * @return string|false
     */
    public static function resolveProject( $board_name, $project_id = 0 ) {
        $project_id = filter_var(
            $project_id,
            FILTER_VALIDATE_INT,
            array( 'options' => array( 'min_range' => 1 ) )
        );

        if ( false === $project_id ) {
            return false;
        }

        $base_url = self::resolveBoardBaseUrl( $board_name );

        if ( ! $base_url ) {
            return false;
        }

        return add_query_arg( 'pandatask_project', $project_id, $base_url );
    }

    /**
     * Resolve and cache a board's canonical base URL.
     *
     * @param mixed $board_name Board identifier.
     * @return string|false
     */
    private static function resolveBoardBaseUrl( $board_name ) {
        $transient_key = 'pandat69_board_url_' . sanitize_key( $board_name );
        $base_url      = get_transient( $transient_key );

        if ( false === $base_url ) {
            global $wpdb;

            if ( preg_match( '/^group_(\d+)$/', $board_name, $matches ) ) {
                $group_id = intval( $matches[1] );

                if ( $group_id > 0 && function_exists( 'groups_get_group' ) ) {
                    $group = groups_get_group( $group_id );

                    if ( $group && ! empty( $group->slug ) ) {
                        $group_url = \Pandatask\Integration\BuddyPress\BuddyPressSupport::groupUrl( $group );
                        if ( $group_url ) {
                            $base_url = trailingslashit( $group_url ) . 'tasks';
                        }
                    }
                }
            }

            if ( ! $base_url && preg_match( '/^user_(\d+)$/', $board_name, $matches ) ) {
                $user_id = intval( $matches[1] );

                if ( $user_id > 0 && function_exists( 'bp_core_get_user_domain' ) ) {
                    $user_url = bp_core_get_user_domain( $user_id );

                    if ( $user_url ) {
                        $base_url = trailingslashit( $user_url ) . 'tasks';
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
            return $base_url;
        }

        return false;
    }
}
