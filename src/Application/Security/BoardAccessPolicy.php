<?php

namespace Pandatask\Application\Security;

use WP_Error;

final class BoardAccessPolicy {

    public function canReadBoard( $board_name, $user_id = null ) {
        $user_id = null === $user_id ? get_current_user_id() : (int) $user_id;

        if ( ! $user_id ) {
            return new WP_Error( 'rest_forbidden', 'Not logged in.', array( 'status' => 401 ) );
        }

        if ( user_can( $user_id, 'manage_options' ) ) {
            return true;
        }

        if ( preg_match( '/^group_(\d+)$/', $board_name, $matches ) ) {
            $group_id = intval( $matches[1] );

            if ( function_exists( 'groups_is_user_member' ) ) {
                if ( groups_is_user_member( $user_id, $group_id ) || user_can( $user_id, 'bp_moderate' ) ) {
                    return true;
                }
            }

            return new WP_Error( 'rest_forbidden', 'Not a group member.', array( 'status' => 403 ) );
        }

        if ( preg_match( '/^user_(\d+)$/', $board_name, $matches ) ) {
            $owner_id = intval( $matches[1] );

            if ( $user_id === $owner_id ) {
                return true;
            }

            return new WP_Error( 'rest_forbidden', 'Private board.', array( 'status' => 403 ) );
        }

        if ( user_can( $user_id, 'edit_posts' ) ) {
            return true;
        }

        return new WP_Error( 'rest_forbidden', 'Access denied.', array( 'status' => 403 ) );
    }
}
