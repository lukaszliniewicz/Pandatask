<?php

namespace Pandatask\Application\Security;

use Pandatask\Application\Settings\FeatureSettings;
use Pandatask\Infrastructure\Persistence\WorkLogShareRepository;
use WP_Error;

final class WorkLogShareAccessPolicy {

    private $repository;
    private $feature_settings;

    public function __construct( $repository = null, $feature_settings = null ) {
        $this->repository      = $repository ?: new WorkLogShareRepository();
        $this->feature_settings = $feature_settings ?: new FeatureSettings();
    }

    public function canManageOwnSharing( $user_id = null ) {
        $user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
        if ( $user_id <= 0 ) {
            return new WP_Error( 'rest_not_logged_in', __( 'You must be logged in.', 'pandatask' ), array( 'status' => 401 ) );
        }
        return $this->globalWorkLogPermission();
    }

    public function canReadGroup( $group_id, $viewer_id = null ) {
        $viewer_id = null === $viewer_id ? get_current_user_id() : (int) $viewer_id;
        if ( $viewer_id <= 0 ) {
            return new WP_Error( 'rest_not_logged_in', __( 'You must be logged in.', 'pandatask' ), array( 'status' => 401 ) );
        }
        $global_permission = $this->globalWorkLogPermission();
        if ( true !== $global_permission ) {
            return $global_permission;
        }
        if ( ! $this->isModuleEnabled( $group_id ) ) {
            return new WP_Error( 'rest_forbidden', __( 'Work Log sharing is not enabled for this group.', 'pandatask' ), array( 'status' => 403 ) );
        }
        if ( $this->canOverrideMembership( $viewer_id ) ) {
            return true;
        }
        if ( ! function_exists( 'groups_is_user_member' ) || ! groups_is_user_member( $viewer_id, (int) $group_id ) ) {
            return new WP_Error( 'rest_forbidden', __( 'You must be a member of this group.', 'pandatask' ), array( 'status' => 403 ) );
        }

        return true;
    }

    public function canReadOwner( $group_id, $owner_id, $viewer_id = null ) {
        $group_permission = $this->canReadGroup( $group_id, $viewer_id );
        if ( true !== $group_permission ) {
            return $group_permission;
        }
        if ( ! $this->hasValidGrant( $owner_id, $group_id ) ) {
            return new WP_Error( 'rest_forbidden', __( 'This work log is not shared with the group.', 'pandatask' ), array( 'status' => 403 ) );
        }

        return true;
    }

    public function hasValidGrant( $user_id, $group_id ) {
        return $this->feature_settings->workLogEnabled()
            && $this->isModuleEnabled( $group_id )
            && $this->isMember( $user_id, $group_id )
            && $this->repository->hasGrant( $user_id, $group_id );
    }

    public function isModuleEnabled( $group_id ) {
        return (int) $group_id > 0
            && function_exists( 'groups_get_groupmeta' )
            && '1' === (string) groups_get_groupmeta( (int) $group_id, 'pandat69_work_logs_enabled', true );
    }

    public function isMember( $user_id, $group_id ) {
        return (int) $user_id > 0
            && (int) $group_id > 0
            && function_exists( 'groups_is_user_member' )
            && groups_is_user_member( (int) $user_id, (int) $group_id );
    }

    private function globalWorkLogPermission() {
        if ( ! $this->feature_settings->workLogEnabled() ) {
            return new WP_Error( 'rest_forbidden', __( 'Work Log is disabled.', 'pandatask' ), array( 'status' => 403 ) );
        }

        return true;
    }

    private function canOverrideMembership( $user_id ) {
        return function_exists( 'user_can' )
            && ( user_can( (int) $user_id, 'manage_options' ) || user_can( (int) $user_id, 'bp_moderate' ) );
    }
}
