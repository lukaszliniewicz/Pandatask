<?php

namespace Pandatask\Application\Board;

use Pandatask\Infrastructure\Persistence\BoardRepository;

final class BoardService {

    private $repository;

    public function __construct( $repository = null ) {
        $this->repository = $repository ?: new BoardRepository();
    }

    public function getUserWritableBoards( $user_id ) {
        if ( ! $user_id ) {
            return array();
        }

        $transient_key = 'pandat69_writable_boards_v2_' . $user_id;
        $cached        = get_transient( $transient_key );

        if ( false !== $cached ) {
            return $cached;
        }

        $boards = $this->repository->findWritableBoardsForUser( $user_id );
        set_transient( $transient_key, $boards, HOUR_IN_SECONDS );

        return $boards;
    }

    public function getAllBoardNames() {
        $transient_key = 'pandat69_all_board_names';
        $cached        = get_transient( $transient_key );

        if ( false !== $cached ) {
            return $cached;
        }

        $boards = $this->repository->findAllBoardNames();
        set_transient( $transient_key, $boards, HOUR_IN_SECONDS );

        return $boards;
    }

    public function getBoardDisplayName( $board_name, $group_id = 0 ) {
        if ( preg_match( '/^group_(\d+)$/', $board_name, $matches ) ) {
            $group_id = $group_id > 0 ? $group_id : intval( $matches[1] );

            return $this->getGroupBoardDisplayName( $group_id, $board_name );
        }

        if ( preg_match( '/^user_(\d+)$/', $board_name, $matches ) ) {
            return $this->getUserBoardDisplayName( intval( $matches[1] ) );
        }

        return ucwords( str_replace( '_', ' ', $board_name ) );
    }

    public function clearGroupBoardNameCache( $group_id ) {
        if ( $group_id > 0 ) {
            delete_transient( 'pandat69_board_display_name_group_' . $group_id );
        }
    }

    private function getGroupBoardDisplayName( $group_id, $fallback_board_name ) {
        if ( $group_id <= 0 ) {
            return ucwords( str_replace( '_', ' ', $fallback_board_name ) );
        }

        $transient_key = 'pandat69_board_display_name_group_' . $group_id;
        $cached        = get_transient( $transient_key );

        if ( false !== $cached ) {
            return $cached;
        }

        $group_name = $this->repository->findGroupName( $group_id );

        if ( '' !== $group_name ) {
            $display_name = esc_html( $group_name );
            set_transient( $transient_key, $display_name, DAY_IN_SECONDS );

            return $display_name;
        }

        return ucwords( str_replace( '_', ' ', $fallback_board_name ) );
    }

    private function getUserBoardDisplayName( $user_id ) {
        if ( is_user_logged_in() && $user_id === get_current_user_id() ) {
            return __( 'My Tasks', 'pandatask' );
        }

        $display_name = $this->repository->findUserDisplayName( $user_id );

        if ( '' !== $display_name ) {
            // translators: %s is the user's display name. e.g. "John Doe's Tasks"
            return sprintf( __( '%s\'s Tasks', 'pandatask' ), $display_name );
        }

        return __( 'User Tasks', 'pandatask' );
    }
}
