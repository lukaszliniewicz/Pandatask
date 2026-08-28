<?php

namespace Pandatask\Application\Work;

use Pandatask\Application\Security\WorkLogShareAccessPolicy;
use Pandatask\Application\Settings\FeatureSettings;
use Pandatask\Infrastructure\Persistence\WorkEntryRepository;
use Pandatask\Infrastructure\Persistence\WorkLogShareRepository;
use Pandatask\Infrastructure\Persistence\WorkReportRepository;
use WP_Error;

final class WorkLogShareService {

    private $repository;
    private $report_repository;
    private $entry_repository;
    private $work_type_service;
    private $report_service;
    private $feature_settings;
    private $access_policy;

    public function __construct( $repository = null, $report_repository = null, $entry_repository = null, $work_type_service = null, $feature_settings = null, $access_policy = null ) {
        $this->repository       = $repository ?: new WorkLogShareRepository();
        $this->report_repository = $report_repository ?: new WorkReportRepository();
        $this->entry_repository = $entry_repository ?: new WorkEntryRepository();
        $this->work_type_service = $work_type_service ?: new WorkTypeService();
        $this->report_service     = new WorkReportService( $this->report_repository );
        $this->feature_settings  = $feature_settings ?: new FeatureSettings();
        $this->access_policy     = $access_policy ?: new WorkLogShareAccessPolicy( $this->repository, $this->feature_settings );
    }

    public function getSharing( $user_id ) {
        $permission = $this->access_policy->canManageOwnSharing( $user_id );
        if ( true !== $permission ) {
            return $permission;
        }

        $shared_ids = array_flip( $this->repository->sharedGroupIdsForUser( $user_id ) );
        $groups     = array();
        $current_group_ids = array();
        foreach ( $this->groupsForUser( $user_id ) as $group ) {
            $group_id = (int) ( $group->id ?? 0 );
            if ( $group_id <= 0 ) {
                continue;
            }
            $current_group_ids[] = $group_id;
            $enabled = $this->access_policy->isModuleEnabled( $group_id );
            $groups[] = array(
                'id'      => $group_id,
                'name'    => (string) ( $group->name ?? '' ),
                'url'     => $this->groupUrl( $group ),
                'enabled' => (bool) $enabled,
                'shared'  => $enabled && isset( $shared_ids[ $group_id ] ) && $this->access_policy->hasValidGrant( $user_id, $group_id ),
            );
        }

        $valid_shared_ids = array_values(
            array_map(
                'intval',
                array_filter(
                    array_intersect( array_keys( $shared_ids ), $current_group_ids ),
                    function ( $group_id ) use ( $user_id ) {
                        return $this->access_policy->hasValidGrant( $user_id, $group_id );
                    }
                )
            )
        );
        sort( $valid_shared_ids, SORT_NUMERIC );

        return array(
            'groups'           => $groups,
            'shared_group_ids' => $valid_shared_ids,
        );
    }

    public function replaceSharing( $user_id, array $group_ids ) {
        $permission = $this->access_policy->canManageOwnSharing( $user_id );
        if ( true !== $permission ) {
            return $permission;
        }

        $group_ids = array_values( array_unique( array_map( 'absint', $group_ids ) ) );
        foreach ( $group_ids as $group_id ) {
            if ( $group_id <= 0 || ! $this->access_policy->isModuleEnabled( $group_id ) || ! $this->access_policy->isMember( $user_id, $group_id ) ) {
                return new WP_Error( 'pandatask_invalid_work_log_group', __( 'Choose only enabled groups that you belong to.', 'pandatask' ), array( 'status' => 422 ) );
            }
        }

        if ( ! $this->repository->replaceForUser( $user_id, $group_ids ) ) {
            return new WP_Error( 'pandatask_work_log_sharing_failed', __( 'The work-log sharing settings could not be saved.', 'pandatask' ), array( 'status' => 500 ) );
        }

        return $this->getSharing( $user_id );
    }

    public function groupPresenters( $group_id, $start_date, $end_date ) {
        if ( ! $this->groupExists( $group_id ) ) {
            return new WP_Error( 'rest_not_found', __( 'Group not found.', 'pandatask' ), array( 'status' => 404 ) );
        }
        $group = $this->getGroup( $group_id );
        $owner_ids = array();
        foreach ( $this->repository->userIdsForGroup( $group_id ) as $owner_id ) {
            if ( $this->access_policy->hasValidGrant( $owner_id, $group_id ) ) {
                $owner_ids[] = (int) $owner_id;
            }
        }

        if ( function_exists( 'cache_users' ) && ! empty( $owner_ids ) ) {
            cache_users( $owner_ids );
        }

        $totals     = $this->report_repository->personalTotalsForUsers( $owner_ids, $start_date, $end_date );
        $presenters = array();
        foreach ( $owner_ids as $owner_id ) {
            $user = function_exists( 'get_userdata' ) ? get_userdata( $owner_id ) : null;
            if ( ! $user ) {
                continue;
            }
            $presenters[] = array(
                'id'             => (int) $owner_id,
                'name'           => (string) $user->display_name,
                'avatar_url'     => $this->avatarUrl( $owner_id ),
                'profile_url'    => $this->profileUrl( $owner_id ),
                'total_seconds'  => (int) ( $totals[ $owner_id ] ?? 0 ),
            );
        }

        usort(
            $presenters,
            static function ( $left, $right ) {
                return strcasecmp( $left['name'], $right['name'] );
            }
        );

        return array(
            'group'      => $this->groupData( $group ),
            'presenters' => $presenters,
            'start_date' => $start_date,
            'end_date'   => $end_date,
        );
    }

    public function groupOwnerLog( $group_id, $owner_id, $start_date, $end_date, $limit = 200, $offset = 0 ) {
        if ( ! $this->groupExists( $group_id ) ) {
            return new WP_Error( 'rest_not_found', __( 'Group not found.', 'pandatask' ), array( 'status' => 404 ) );
        }
        if ( ! $this->access_policy->hasValidGrant( $owner_id, $group_id ) ) {
            return new WP_Error( 'rest_forbidden', __( 'This work log is not shared with the group.', 'pandatask' ), array( 'status' => 403 ) );
        }
        $owner = function_exists( 'get_userdata' ) ? get_userdata( (int) $owner_id ) : null;
        if ( ! $owner ) {
            return new WP_Error( 'rest_not_found', __( 'Work-log owner not found.', 'pandatask' ), array( 'status' => 404 ) );
        }
        $group = $this->getGroup( $group_id );
        $limit = max( 1, min( 500, absint( $limit ) ) );
        $offset = max( 0, absint( $offset ) );
        $entries = $this->entry_repository->findForUser( $owner_id, $start_date, $end_date, $limit + 1, $offset );
        $has_more = count( $entries ) > $limit;
        if ( $has_more ) {
            $entries = array_slice( $entries, 0, $limit );
        }

        return array(
            'group'          => $this->groupData( $group ),
            'owner'          => array(
                'id'           => (int) $owner_id,
                'name'         => (string) $owner->display_name,
                'avatar_url'   => $this->avatarUrl( $owner_id ),
                'profile_url'  => $this->profileUrl( $owner_id ),
            ),
            'activity_types' => $this->work_type_service->all( $owner_id ),
            'entries'        => $this->shareableEntries( $entries ),
            'pagination'     => array(
                'limit'       => $limit,
                'offset'      => $offset,
                'returned'    => count( $entries ),
                'has_more'    => $has_more,
                'next_offset' => $has_more ? $offset + $limit : null,
            ),
            'report'         => $this->report_service->sharedPersonal( $owner_id, $start_date, $end_date ),
            'start_date'     => $start_date,
            'end_date'       => $end_date,
        );
    }

    private function groupsForUser( $user_id ) {
        if ( ! function_exists( 'groups_get_user_groups' ) ) {
            return array();
        }

        // BuddyPress's legacy helper accepts positional numeric arguments and
        // returns group IDs, not group objects.
        $result = groups_get_user_groups( (int) $user_id, 0, 0 );
        $group_ids = is_array( $result ) && isset( $result['groups'] ) && is_array( $result['groups'] )
            ? $result['groups']
            : array();
        $groups = array();
        foreach ( $group_ids as $group_id ) {
            $group = $this->getGroup( (int) $group_id );
            if ( $group && ! empty( $group->id ) ) {
                $groups[] = $group;
            }
        }

        return $groups;
    }

    /**
     * Project work-entry database rows onto the fields members consent to share.
     *
     * Import keys, audit actors, soft-delete metadata and occurrence IDs are
     * deliberately excluded: they are implementation details, not work-log
     * content, and are unnecessary for the read-only group presentation.
     */
    private function shareableEntries( array $entries ) {
        return array_map(
            function ( $entry ) {
                $allocations = (array) $this->recordValue( $entry, 'allocations', array() );

                return array(
                    'id'               => (int) $this->recordValue( $entry, 'id', 0 ),
                    'user_id'          => (int) $this->recordValue( $entry, 'user_id', 0 ),
                    'title'            => (string) $this->recordValue( $entry, 'title', '' ),
                    'notes'            => $this->nullableString( $this->recordValue( $entry, 'notes' ) ),
                    'activity_type'    => $this->nullableString( $this->recordValue( $entry, 'activity_type' ) ),
                    'capacity'         => $this->nullableString( $this->recordValue( $entry, 'capacity' ) ),
                    'work_date'        => (string) $this->recordValue( $entry, 'work_date', '' ),
                    'started_at_utc'   => $this->nullableString( $this->recordValue( $entry, 'started_at_utc' ) ),
                    'ended_at_utc'     => $this->nullableString( $this->recordValue( $entry, 'ended_at_utc' ) ),
                    'timezone'         => $this->nullableString( $this->recordValue( $entry, 'timezone' ) ),
                    'duration_seconds' => (int) $this->recordValue( $entry, 'duration_seconds', 0 ),
                    'kind'             => (string) $this->recordValue( $entry, 'kind', 'manual' ),
                    'allocations'      => array_map(
                        function ( $allocation ) {
                            return array(
                                'seconds'                => (int) $this->recordValue( $allocation, 'seconds', 0 ),
                                'task_id_snapshot'       => $this->nullableInt( $this->recordValue( $allocation, 'task_id_snapshot' ) ),
                                'task_name_snapshot'     => $this->nullableString( $this->recordValue( $allocation, 'task_name_snapshot' ) ),
                                'board_name_snapshot'    => $this->nullableString( $this->recordValue( $allocation, 'board_name_snapshot' ) ),
                                'project_id_snapshot'    => $this->nullableInt( $this->recordValue( $allocation, 'project_id_snapshot' ) ),
                                'project_name_snapshot'  => $this->nullableString( $this->recordValue( $allocation, 'project_name_snapshot' ) ),
                                'category_id_snapshot'   => $this->nullableInt( $this->recordValue( $allocation, 'category_id_snapshot' ) ),
                                'category_name_snapshot' => $this->nullableString( $this->recordValue( $allocation, 'category_name_snapshot' ) ),
                            );
                        },
                        $allocations
                    ),
                );
            },
            $entries
        );
    }

    private function recordValue( $record, $key, $default = null ) {
        if ( is_array( $record ) ) {
            return array_key_exists( $key, $record ) ? $record[ $key ] : $default;
        }
        if ( is_object( $record ) && property_exists( $record, $key ) ) {
            return $record->{$key};
        }
        return $default;
    }

    private function nullableString( $value ) {
        return null === $value || '' === $value ? null : (string) $value;
    }

    private function nullableInt( $value ) {
        return null === $value || '' === $value ? null : (int) $value;
    }

    private function getGroup( $group_id ) {
        return function_exists( 'groups_get_group' ) ? groups_get_group( (int) $group_id ) : null;
    }

    private function groupExists( $group_id ) {
        $group = $this->getGroup( $group_id );
        return $group && ! empty( $group->id );
    }

    private function groupData( $group ) {
        return array(
            'id'   => (int) ( $group->id ?? 0 ),
            'name' => (string) ( $group->name ?? '' ),
            'url'  => $this->groupUrl( $group ),
        );
    }

    private function groupUrl( $group ) {
        if ( function_exists( 'bp_get_group_url' ) ) {
            return (string) bp_get_group_url( $group );
        }
        if ( function_exists( 'bp_get_group_permalink' ) ) {
            return (string) bp_get_group_permalink( $group );
        }
        return '';
    }

    private function avatarUrl( $user_id ) {
        return function_exists( 'get_avatar_url' ) ? (string) get_avatar_url( (int) $user_id, array( 'size' => 96, 'default' => 'mystery' ) ) : '';
    }

    private function profileUrl( $user_id ) {
        if ( function_exists( 'bp_core_get_user_domain' ) ) {
            return (string) bp_core_get_user_domain( (int) $user_id );
        }
        return function_exists( 'get_author_posts_url' ) ? (string) get_author_posts_url( (int) $user_id ) : '';
    }
}
