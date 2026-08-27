<?php

namespace Pandatask\Application\Security;

use Pandatask\Infrastructure\Persistence\InboxDelegateRepository;
use WP_Error;

final class InboxAccessPolicy {

    private $repository;

    public function __construct( $repository = null ) {
        $this->repository = $repository ?: new InboxDelegateRepository();
    }

    public function canReadInbox( $owner_user_id, $user_id = null ) {
        return $this->checkRole( $owner_user_id, $user_id, array( 'triager' ) );
    }

    public function canTriageInbox( $owner_user_id, $user_id = null ) {
        return $this->checkRole( $owner_user_id, $user_id, array( 'triager' ) );
    }

    public function canSubmitToInbox( $owner_user_id, $user_id = null ) {
        return $this->checkRole( $owner_user_id, $user_id, array( 'triager', 'contributor' ) );
    }

    public function roleFor( $owner_user_id, $user_id = null ) {
        $owner_user_id = (int) $owner_user_id;
        $user_id = null === $user_id ? get_current_user_id() : (int) $user_id;

        if ( $owner_user_id <= 0 || $user_id <= 0 ) {
            return null;
        }
        if ( $owner_user_id === $user_id || user_can( $user_id, 'manage_options' ) ) {
            return 'owner';
        }

        return $this->repository->roleFor( $owner_user_id, $user_id );
    }

    public static function ownerFromBoardName( $board_name ) {
        return preg_match( '/^user_(\d+)$/', (string) $board_name, $matches ) ? (int) $matches[1] : 0;
    }

    private function checkRole( $owner_user_id, $user_id, array $allowed_roles ) {
        $owner_user_id = (int) $owner_user_id;
        $user_id = null === $user_id ? get_current_user_id() : (int) $user_id;

        if ( $owner_user_id <= 0 || ! get_userdata( $owner_user_id ) ) {
            return new WP_Error( 'rest_inbox_not_found', __( 'Inbox owner not found.', 'pandatask' ), array( 'status' => 404 ) );
        }
        if ( $user_id <= 0 ) {
            return new WP_Error( 'rest_not_logged_in', __( 'You must be logged in.', 'pandatask' ), array( 'status' => 401 ) );
        }
        if ( $owner_user_id === $user_id || user_can( $user_id, 'manage_options' ) ) {
            return true;
        }

        $role = $this->repository->roleFor( $owner_user_id, $user_id );
        if ( $role && in_array( $role, $allowed_roles, true ) ) {
            return true;
        }

        return new WP_Error( 'rest_forbidden_inbox', __( 'You do not have access to this inbox.', 'pandatask' ), array( 'status' => 403 ) );
    }
}
