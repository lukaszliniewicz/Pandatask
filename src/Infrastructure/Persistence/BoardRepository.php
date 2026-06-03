<?php

namespace Pandatask\Infrastructure\Persistence;

final class BoardRepository {

    public function findWritableBoardsForUser( $user_id ) {
        $boards   = array();
        $boards[] = (object) array(
            'id'   => 'user_' . $user_id,
            'name' => __( 'My Private Tasks', 'pandatask' ),
        );

        if ( function_exists( 'groups_get_groups' ) ) {
            $bp_groups = groups_get_groups(
                array(
                    'user_id'     => $user_id,
                    'per_page'    => 999,
                    'show_hidden' => true,
                )
            );

            if ( ! empty( $bp_groups['groups'] ) ) {
                foreach ( $bp_groups['groups'] as $group ) {
                    $tasks_enabled = function_exists( 'groups_get_groupmeta' ) ? groups_get_groupmeta( $group->id, 'pandat69_tasks_enabled', true ) : '';

                    if ( '0' === (string) $tasks_enabled ) {
                        continue;
                    }

                    $board_id = 'group_' . $group->id;
                    $boards[] = (object) array(
                        'id'   => $board_id,
                        'name' => 'Group: ' . $group->name,
                    );
                }
            }
        }

        return $boards;
    }

    public function findAllBoardNames() {
        global $wpdb;

        $tasks_table     = DatabaseContext::getDbPrefix() . 'tasks';
        $boards          = array();
        $standard_boards = $wpdb->get_results(
            "SELECT DISTINCT board_name as id, board_name as name
             FROM {$tasks_table}
             WHERE board_name NOT LIKE 'group_%'"
        );

        foreach ( $standard_boards as $board ) {
            $board->name          = ucwords( str_replace( '_', ' ', $board->name ) );
            $boards[ $board->id ] = $board;
        }

        if ( function_exists( 'groups_get_groups' ) ) {
            $bp_groups = groups_get_groups( array( 'per_page' => 999 ) );

            if ( ! empty( $bp_groups['groups'] ) ) {
                foreach ( $bp_groups['groups'] as $group ) {
                    $board_id            = 'group_' . $group->id;
                    $boards[ $board_id ] = (object) array(
                        'id'   => $board_id,
                        'name' => 'Group: ' . $group->name,
                    );
                }
            }
        }

        uasort(
            $boards,
            static function ( $left, $right ) {
                return strcasecmp( $left->name, $right->name );
            }
        );

        return array_values( $boards );
    }

    public function findGroupName( $group_id ) {
        if ( $group_id <= 0 || ! function_exists( 'groups_get_group' ) ) {
            return '';
        }

        $group = groups_get_group( $group_id );

        if ( ! $group || empty( $group->name ) ) {
            return '';
        }

        return $group->name;
    }

    public function findUserDisplayName( $user_id ) {
        $user = get_userdata( $user_id );

        return $user ? $user->display_name : '';
    }
}
