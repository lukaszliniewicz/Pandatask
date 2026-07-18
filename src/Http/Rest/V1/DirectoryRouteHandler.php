<?php

namespace Pandatask\Http\Rest\V1;

use Pandatask\Application\Board\BoardService;
use Pandatask\Application\User\UserDirectoryService;
use WP_REST_Response;

final class DirectoryRouteHandler {

    private $board_service;

    private $user_directory_service;

    public function __construct( $board_service = null, $user_directory_service = null ) {
        $this->board_service          = $board_service ?: new BoardService();
        $this->user_directory_service = $user_directory_service ?: new UserDirectoryService();
    }

    public function get_user_writable_boards( $request ) {
        $boards = $this->board_service->getUserWritableBoards( get_current_user_id() );

        return new WP_REST_Response( array( 'boards' => $boards ), 200 );
    }

    public function get_boards( $request ) {
        $search = $request['search'];
        $boards = $this->board_service->getAllBoardNames();

        if ( $search ) {
            $boards = array_filter(
                $boards,
                static function ( $board ) use ( $search ) {
                    return false !== stripos( $board->name, $search );
                }
            );
            $boards = array_values( $boards );
        }

        return new WP_REST_Response( $boards, 200 );
    }

    public function get_users( $request ) {
        $search     = $request['search'] ?? '';
        $board_name = $request['board_name'] ?? '';
        $include    = array_slice( array_values( array_unique( array_filter( array_map( 'absint', (array) ( $request['include'] ?? array() ) ) ) ) ), 0, 50 );

        $users = $this->user_directory_service->getUsersForBoard( $board_name, $search, $include );

        return new WP_REST_Response( array( 'users' => $users ), 200 );
    }
}
