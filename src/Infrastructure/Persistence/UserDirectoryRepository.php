<?php

namespace Pandatask\Infrastructure\Persistence;

final class UserDirectoryRepository {

    public function findBuddyPressUsers( $search = '', $group_id = 0, $include = array() ) {
        if ( $group_id <= 0 || ! function_exists( 'bp_has_members' ) || ! function_exists( 'groups_get_group_members' ) ) {
            return array();
        }

        $members              = array();
        $group_members_result = groups_get_group_members(
            array(
                'group_id'     => $group_id,
                'per_page'     => 50,
                'search_terms' => $search ? sanitize_text_field( $search ) : false,
            )
        );

        if ( ! empty( $group_members_result['members'] ) ) {
            foreach ( $group_members_result['members'] as $member ) {
                $members[ $member->ID ] = array(
                    'id'   => $member->ID,
                    'name' => $member->display_name,
                );
            }
        }

        $admins = get_users(
            array(
                'role'           => 'administrator',
                'search'         => $search ? '*' . esc_attr( $search ) . '*' : '',
                'search_columns' => array( 'user_login', 'user_email', 'user_nicename', 'display_name' ),
                'fields'         => array( 'ID', 'display_name' ),
                'number'         => 50,
            )
        );

        foreach ( $admins as $admin ) {
            if ( isset( $members[ $admin->ID ] ) ) {
                continue;
            }

            $members[ $admin->ID ] = array(
                'id'   => $admin->ID,
                'name' => $admin->display_name,
            );
        }

        if ( $include ) {
            $included_users = get_users(
                array(
                    'include' => array_map( 'absint', $include ),
                    'fields'  => array( 'ID', 'display_name' ),
                )
            );

            foreach ( $included_users as $user ) {
                if ( ! groups_is_user_member( $user->ID, $group_id ) && ! user_can( $user->ID, 'manage_options' ) ) {
                    continue;
                }

                $members[ $user->ID ] = array(
                    'id'   => $user->ID,
                    'name' => $user->display_name,
                );
            }
        }

        uasort(
            $members,
            static function ( $left, $right ) {
                return strcasecmp( $left['name'], $right['name'] );
            }
        );

        return array_values( $members );
    }

    public function findWordPressUsers( $search = '', $include = array() ) {
        $args = array(
            'orderby' => 'display_name',
            'order'   => 'ASC',
            'number'  => 50,
            'fields'  => array( 'ID', 'display_name' ),
        );

        if ( ! empty( $search ) ) {
            $args['search']         = '*' . esc_attr( $search ) . '*';
            $args['search_columns'] = array( 'user_login', 'user_nicename', 'user_email', 'display_name' );
        }

        $users           = get_users( $args );
        $formatted_users = array();

        foreach ( $users as $user ) {
            $formatted_users[ $user->ID ] = array(
                'id'   => $user->ID,
                'name' => $user->display_name,
            );
        }

        if ( $include ) {
            $included_users = get_users(
                array(
                    'include' => array_map( 'absint', $include ),
                    'fields'  => array( 'ID', 'display_name' ),
                )
            );

            foreach ( $included_users as $user ) {
                $formatted_users[ $user->ID ] = array(
                    'id'   => $user->ID,
                    'name' => $user->display_name,
                );
            }
        }

        uasort(
            $formatted_users,
            static function ( $left, $right ) {
                return strcasecmp( $left['name'], $right['name'] );
            }
        );

        return array_values( $formatted_users );
    }
}
