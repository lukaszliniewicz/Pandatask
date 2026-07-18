<?php

namespace Pandatask\Integration\BuddyPress;

use Pandatask\Application\Board\BoardService;

final class BuddyPressRegistrar {

    private $board_service;

    public function __construct( $board_service = null ) {
        $this->board_service = $board_service ?: new BoardService();
    }

    public function register() {
        add_action( 'plugins_loaded', array( $this, 'loadIntegrations' ), 20 );
        add_action( 'bp_loaded', array( $this, 'registerCacheHooks' ) );
    }

    public function loadIntegrations() {
        if ( ! BuddyPressSupport::isLoaded() ) {
            return;
        }

        BuddyPressBootstrap::get_instance();
        ProfileTasksPage::get_instance();
    }

    public function registerCacheHooks() {
        if ( ! BuddyPressSupport::isGroupsActive() ) {
            return;
        }

        add_action( 'groups_group_details_updated', array( $this, 'clearGroupBoardNameCache' ), 10, 1 );
        add_action( 'groups_group_created', array( $this, 'clearAllBoardNamesCache' ) );
        add_action( 'groups_delete_group', array( $this, 'clearAllBoardNamesCache' ) );
        add_action( 'groups_join_group', array( $this, 'clearWritableBoardsCache' ), 10, 2 );
        add_action( 'groups_leave_group', array( $this, 'clearWritableBoardsCache' ), 10, 2 );
    }

    public function clearGroupBoardNameCache( $group_id ) {
        $this->board_service->clearGroupBoardNameCache( $group_id );
    }

    public function clearAllBoardNamesCache() {
        delete_transient( 'pandat69_all_board_names' );
    }

    public function clearWritableBoardsCache( $group_id, $user_id ) {
        if ( $user_id > 0 ) {
            delete_transient( 'pandat69_writable_boards_v2_' . $user_id );
            delete_transient( 'pandat69_writable_boards_' . $user_id );
        }
    }
}
