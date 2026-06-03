<?php

namespace Pandatask\Integration\BuddyPress;

final class BuddyPressSupport {

    public static function isLoaded() {
        return function_exists( 'buddypress' );
    }

    public static function isGroupsActive() {
        return self::isLoaded() && function_exists( 'bp_is_active' ) && bp_is_active( 'groups' );
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
