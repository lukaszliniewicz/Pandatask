<?php

namespace Pandatask\Integration\BuddyPress;

use Pandatask\Application\Board\BoardService;
use Pandatask\Infrastructure\Persistence\WorkLogShareRepository;

final class BuddyPressRegistrar {

    private $board_service;

    private $board_activity_projector;

    public function __construct( $board_service = null, $board_activity_projector = null ) {
        $this->board_service = $board_service ?: new BoardService();
        $this->board_activity_projector = $board_activity_projector ?: new BoardActivityProjector();
    }

    public function register() {
        $this->board_activity_projector->register();
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
        add_action( 'groups_leave_group', array( $this, 'deleteWorkLogShareForUserGroup' ), 10, 2 );
        add_action( 'groups_remove_member', array( $this, 'deleteWorkLogShareForUserGroup' ), 10, 2 );
        add_action( 'groups_ban_member', array( $this, 'deleteWorkLogShareForUserGroup' ), 10, 2 );
        add_action( 'groups_delete_group', array( $this, 'deleteWorkLogSharesForGroup' ), 10, 1 );
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

    /**
     * BuddyPress emits its leave, remove, and ban hooks as
     * ($group_id, $user_id).
     */
    public function deleteWorkLogShareForUserGroup( $group_id, $user_id ) {
        $group_id = absint( $group_id );
        $user_id  = absint( $user_id );

        if ( $group_id > 0 && $user_id > 0 ) {
            WorkLogShareRepository::deleteForUserGroup( $user_id, $group_id );
        }
    }

    public function deleteWorkLogSharesForGroup( $group_id ) {
        $group_id = absint( $group_id );

        if ( $group_id > 0 ) {
            WorkLogShareRepository::deleteForGroup( $group_id );
        }
    }
}
