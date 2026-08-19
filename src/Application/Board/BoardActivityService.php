<?php

namespace Pandatask\Application\Board;

use Pandatask\Infrastructure\Persistence\BoardEventRepository;

final class BoardActivityService {

    private $repository;

    public function __construct( $repository = null ) {
        $this->repository = $repository ?: new BoardEventRepository();
    }

    public function getBoardActivity( $board_name, $limit = 20 ) {
        $board_name = sanitize_key( (string) $board_name );
        $limit = max( 1, min( 100, (int) $limit ) );

        return array(
            'board_name' => $board_name,
            'summary'    => $this->repository->getBoardSummary( $board_name ),
            'events'     => $this->repository->getBoardEvents( $board_name, $limit ),
            'settings'   => $this->getBoardSettings( $board_name ),
        );
    }

    public function getBoardSettings( $board_name ) {
        $group_id = $this->groupIdFromBoard( $board_name );
        if ( $group_id < 1 || ! function_exists( 'groups_get_groupmeta' ) ) {
            return array(
                'feed_widget_enabled' => false,
                'preview_count'       => 3,
            );
        }

        $preview_count = (int) groups_get_groupmeta( $group_id, 'pandat69_task_activity_preview_count', true );
        if ( ! in_array( $preview_count, array( 3, 5, 8 ), true ) ) {
            $preview_count = 3;
        }

        return array(
            'feed_widget_enabled' => '0' !== (string) groups_get_groupmeta( $group_id, 'pandat69_task_activity_enabled', true ),
            'preview_count'       => $preview_count,
        );
    }

    private function groupIdFromBoard( $board_name ) {
        if ( preg_match( '/^group_(\d+)$/', (string) $board_name, $matches ) ) {
            return (int) $matches[1];
        }
        return 0;
    }
}
