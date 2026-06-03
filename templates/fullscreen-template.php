<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="profile" href="https://gmpg.org/xfn/11">
    <title><?php esc_html_e( 'Task Board - Fullscreen', 'pandatask' ); ?></title>
    <?php wp_head(); ?>
</head>
<body <?php body_class('pandat69-fullscreen-body'); ?>>
    <?php
    if ( ! is_user_logged_in() ) {
        // Must be logged in to view any board
        echo '<div class="pandat69-permission-error"><h1>' . esc_html__('Access Denied', 'pandatask') . '</h1><p>' . esc_html__('You must be logged in to view this task board.', 'pandatask') . '</p></div>';
    } elseif ( isset( $_GET['board_name'] ) ) {
        $board_name = sanitize_key( $_GET['board_name'] );
        $group_id = isset( $_GET['group_id'] ) ? absint( $_GET['group_id'] ) : 0;
        $user_id = get_current_user_id();
        $can_view = true; // Assume can view unless checks fail

        // If it's a group board, check for membership
        if ( $group_id > 0 && function_exists('groups_is_user_member') ) {
            if ( ! groups_is_user_member( $user_id, $group_id ) && ! user_can( $user_id, 'bp_moderate' ) ) {
                $can_view = false;
            }
        } elseif ( preg_match('/^group_(\d+)$/', $board_name, $matches) ) {
            // Also handle case where group_id is not in URL but board_name is group_x
            $detected_group_id = intval($matches[1]);
            if ( $detected_group_id > 0 && function_exists('groups_is_user_member') ) {
                if ( ! groups_is_user_member( $user_id, $detected_group_id ) && ! user_can( $user_id, 'bp_moderate' ) ) {
                    $can_view = false;
                }
            }
        }
        // Add other permission checks here for non-group boards if needed, e.g., based on role.
        // For now, any logged-in user can see non-group boards.

        if ( $can_view ) {
            $shortcode_atts = 'board_name="' . esc_attr( $board_name ) . '"';
            if ($group_id > 0) {
                $shortcode_atts .= ' group_id="' . esc_attr( $group_id ) . '"';
            }
            
            echo do_shortcode( '[task_board ' . $shortcode_atts . ']' );
        } else {
            echo '<div class="pandat69-permission-error"><h1>' . esc_html__('Access Denied', 'pandatask') . '</h1><p>' . esc_html__('You do not have permission to view this task board.', 'pandatask') . '</p></div>';
        }
    } else {
        echo '<div class="pandat69-permission-error"><h1>' . esc_html__('Error', 'pandatask') . '</h1><p>' . esc_html__('No task board specified.', 'pandatask') . '</p></div>';
    }
    ?>
    <?php wp_footer(); ?>
</body>
</html>
