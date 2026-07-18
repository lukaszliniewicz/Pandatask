<?php

namespace Pandatask\Integration\BuddyPress;

final class BuddyPressSupport {

    public static function isLoaded() {
        return function_exists( 'buddypress' );
    }

    public static function isGroupsActive() {
        return self::isLoaded() && function_exists( 'bp_is_active' ) && bp_is_active( 'groups' );
    }

    public static function groupFeatureEnabled( $group_id, $meta_key ) {
        if ( ! $group_id || ! function_exists( 'groups_get_groupmeta' ) ) {
            return false;
        }

        return '0' !== (string) groups_get_groupmeta( $group_id, $meta_key, true );
    }

    public static function sanitizeGroupAssignee( $group_id, $user_id ) {
        $user_id = absint( $user_id );

        if ( 0 === $user_id ) {
            return 0;
        }

        return function_exists( 'groups_is_user_member' ) && groups_is_user_member( $user_id, $group_id ) ? $user_id : 0;
    }

    public static function renderGroupMembersDropdown( $group_id, $name, $selected = 0 ) {
        if ( ! $group_id || ! function_exists( 'groups_get_group_members' ) ) {
            echo '<p>' . esc_html__( 'No members found or group not yet created.', 'pandatask' ) . '</p>';
            return;
        }

        $members = groups_get_group_members(
            array(
                'group_id' => $group_id,
                'per_page' => 0,
            )
        );

        echo '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $name ) . '">';
        echo '<option value="0">' . esc_html__( '-- Select a Member --', 'pandatask' ) . '</option>';

        if ( ! empty( $members['members'] ) ) {
            foreach ( $members['members'] as $member ) {
                echo '<option value="' . esc_attr( $member->ID ) . '"' . selected( $selected, $member->ID, false ) . '>' . esc_html( $member->display_name ) . '</option>';
            }
        }

        echo '</select>';
    }
}
