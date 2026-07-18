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

    public function canWriteBoard( $board_name, $user_id = null ) {
        return $this->canReadBoard( $board_name, $user_id );
    }

    public function canManageBoard( $board_name, $user_id = null ) {
        $user_id = null === $user_id ? get_current_user_id() : (int) $user_id;

        if ( ! $user_id ) {
            return new WP_Error( 'rest_forbidden', 'Not logged in.', array( 'status' => 401 ) );
        }

        if ( user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'bp_moderate' ) ) {
            return true;
        }

        if ( preg_match( '/^group_(\d+)$/', $board_name, $matches ) ) {
            $group_id = (int) $matches[1];

            if (
                function_exists( 'groups_is_user_admin' )
                && ( groups_is_user_admin( $user_id, $group_id ) || groups_is_user_mod( $user_id, $group_id ) )
            ) {
                return true;
            }

            return new WP_Error( 'rest_forbidden', 'Group manager access required.', array( 'status' => 403 ) );
        }

        if ( preg_match( '/^user_(\d+)$/', $board_name, $matches ) ) {
            return $user_id === (int) $matches[1]
                ? true
                : new WP_Error( 'rest_forbidden', 'Private board.', array( 'status' => 403 ) );
        }

        return user_can( $user_id, 'edit_others_posts' )
            ? true
            : new WP_Error( 'rest_forbidden', 'Board manager access required.', array( 'status' => 403 ) );
    }

    public function isUserAllowedOnBoard( $board_name, $candidate_user_id ) {
        $candidate_user_id = (int) $candidate_user_id;

        if ( $candidate_user_id <= 0 || ! get_userdata( $candidate_user_id ) ) {
            return false;
        }

        if ( ! preg_match( '/^group_(\d+)$/', $board_name, $matches ) ) {
            return true;
        }

        $group_id = (int) $matches[1];

        return user_can( $candidate_user_id, 'manage_options' )
            || user_can( $candidate_user_id, 'bp_moderate' )
            || ( function_exists( 'groups_is_user_member' ) && groups_is_user_member( $candidate_user_id, $group_id ) );
    }
}
