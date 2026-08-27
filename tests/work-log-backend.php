<?php

/**
 * Focused, dependency-free checks for the Work Log backend contract.
 *
 * Run with: php tests/work-log-backend.php
 */

if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = null ) { return $text; }
}
if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) ); }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $text ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $text ) ) ); }
}
if ( ! function_exists( 'absint' ) ) {
    function absint( $value ) { return abs( (int) $value ); }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
    function get_current_user_id() { return 7; }
}
if ( ! function_exists( 'get_user_meta' ) ) {
    function get_user_meta( $user_id, $key, $single = false ) {
        return $GLOBALS['pandatask_test_user_meta'][ (int) $user_id ][ $key ] ?? ( $single ? '' : array() );
    }
}
if ( ! function_exists( 'update_user_meta' ) ) {
    function update_user_meta( $user_id, $key, $value ) {
        $GLOBALS['pandatask_test_user_meta'][ (int) $user_id ][ $key ] = $value;
        return true;
    }
}
if ( ! function_exists( 'get_option' ) ) {
    function get_option( $key, $default = false ) { return $GLOBALS['pandatask_test_options'][ $key ] ?? $default; }
}
if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $tag, $value ) {
        return isset( $GLOBALS['pandatask_test_filters'][ $tag ] ) ? call_user_func( $GLOBALS['pandatask_test_filters'][ $tag ], $value ) : $value;
    }
}
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        private $code;
        private $data;
        public function __construct( $code, $message, $data = null ) { $this->code = $code; $this->data = $data; }
        public function get_error_code() { return $this->code; }
        public function get_error_data() { return $this->data; }
    }
}
if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $value ) { return $value instanceof WP_Error; }
}
if ( ! function_exists( 'wp_timezone' ) ) {
    function wp_timezone() { return new DateTimeZone( 'Europe/Warsaw' ); }
}
if ( ! function_exists( 'wp_date' ) ) {
    function wp_date( $format ) { return ( new DateTimeImmutable( 'now', wp_timezone() ) )->format( $format ); }
}

require_once dirname( __DIR__ ) . '/src/Domain/Work/ActivityTypes.php';
require_once dirname( __DIR__ ) . '/src/Application/Settings/FeatureSettings.php';
require_once dirname( __DIR__ ) . '/src/Application/Security/WorkLogShareAccessPolicy.php';
require_once dirname( __DIR__ ) . '/src/Application/Work/WorkReportService.php';
require_once dirname( __DIR__ ) . '/src/Application/Work/WorkLogShareService.php';
require_once dirname( __DIR__ ) . '/src/Infrastructure/Persistence/WorkLogShareRepository.php';
require_once dirname( __DIR__ ) . '/src/Application/Work/WorkTypeService.php';
require_once dirname( __DIR__ ) . '/src/Application/Work/WorkEntryService.php';
require_once dirname( __DIR__ ) . '/src/Http/Rest/V1/WorkRouteRegistrar.php';
require_once dirname( __DIR__ ) . '/src/Http/Rest/V1/WorkRouteHandler.php';

use Pandatask\Application\Settings\FeatureSettings;
use Pandatask\Application\Security\WorkLogShareAccessPolicy;
use Pandatask\Application\Work\WorkEntryService;
use Pandatask\Application\Work\WorkLogShareService;
use Pandatask\Application\Work\WorkTypeService;
use Pandatask\Http\Rest\V1\WorkRouteHandler;
use Pandatask\Http\Rest\V1\WorkRouteRegistrar;

$failures = array();
$assert = static function ( $condition, $message ) use ( &$failures ) {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$GLOBALS['pandatask_test_options'] = array();
$assert( true === ( new FeatureSettings() )->workLogEnabled(), 'Work Log should default to enabled.' );
$GLOBALS['pandatask_test_options']['pandatask_feature_settings'] = array( 'work_log_enabled' => 0 );
$assert( false === ( new FeatureSettings() )->workLogEnabled(), 'Work Log option 0 should disable the feature.' );
$GLOBALS['pandatask_test_filters']['pandatask_work_log_enabled'] = static function ( $enabled ) { return ! $enabled; };
$assert( true === ( new FeatureSettings() )->workLogEnabled(), 'The feature flag should be filterable.' );
$GLOBALS['pandatask_test_filters'] = array();

$GLOBALS['pandatask_test_user_meta'] = array();
$types = new WorkTypeService();
$initial = $types->all( 7 );
$assert( 11 === count( $initial ), 'All built-in work types should be returned.' );
$assert( isset( $initial[0]['is_system'], $initial[0]['is_active'] ) && true === $initial[0]['is_system'] && true === $initial[0]['is_active'], 'Built-in type metadata is incomplete.' );
$development = array_values( array_filter( $initial, static function ( $type ) { return 'development' === $type['key']; } ) );
$assert( 1 === count( $development ) && 'Development' === $development[0]['label'], 'Development must be seeded exactly once as a built-in work type.' );

$custom = $types->create( 'Deep Focus', 7 );
$assert( ! is_wp_error( $custom ) && false === $custom['is_system'], 'Custom work type creation failed.' );
$custom_key = $custom['key'];
$renamed = $types->update( $custom_key, array( 'label' => 'Focused Work' ), 7 );
$assert( $custom_key === $renamed['key'] && 'Focused Work' === $renamed['label'], 'Renaming a custom type changed its stable key.' );
$archived = $types->archive( $custom_key, 7 );
$assert( false === $archived['is_active'] && $types->isKnown( $custom_key, 7 ), 'Archived custom types must remain resolvable.' );
$restored = $types->update( $custom_key, array( 'is_active' => true ), 7 );
$assert( true === $restored['is_active'], 'Archived custom type could not be restored.' );
$duplicate = $types->create( 'focused work', 7 );
$assert( is_wp_error( $duplicate ) && 409 === $duplicate->get_error_data()['status'], 'Active duplicate labels should be rejected with 409.' );
$archived_custom = $types->create( 'Archived Detail', 7 );
$types->archive( $archived_custom['key'], 7 );
$entry_service = new WorkEntryService( new stdClass(), new stdClass(), new stdClass(), new stdClass(), new stdClass(), new stdClass(), new stdClass(), $types );
$normalize = new ReflectionMethod( $entry_service, 'normalizeEntry' );
$normalize->setAccessible( true );
$archived_create = $normalize->invoke(
    $entry_service,
    array(
        'activity_type'    => $archived_custom['key'],
        'duration_seconds' => 600,
        'work_date'        => '2026-08-25',
    ),
    7,
    'manual',
    null,
    null
);
$assert( is_wp_error( $archived_create ), 'Archived work types must not be accepted for new entries.' );
$archived_entry = $normalize->invoke(
	$entry_service,
	array(
		'activity_type'    => $archived_custom['key'],
		'duration_seconds' => 600,
		'work_date'        => '2026-08-25',
	),
	7,
	'manual',
	null,
	null,
	true
);
$assert( ! is_wp_error( $archived_entry ) && $archived_custom['key'] === $archived_entry['entry']['activity_type'], 'Known archived activity keys should remain valid when preserving a historical entry.' );

foreach ( $types->all( 7 ) as $type ) {
    if ( $type['key'] !== $custom_key ) {
        $types->archive( $type['key'], 7 );
    }
}
$last_active = $types->archive( $custom_key, 7 );
$assert( is_wp_error( $last_active ) && 409 === $last_active->get_error_data()['status'], 'The last active work type must not be archivable.' );

if ( ! function_exists( 'groups_get_groupmeta' ) ) {
    function groups_get_groupmeta( $group_id, $key, $single = true ) {
        return $GLOBALS['pandatask_test_group_meta'][ (int) $group_id ][ $key ] ?? '';
    }
}
if ( ! function_exists( 'groups_is_user_member' ) ) {
    function groups_is_user_member( $user_id, $group_id ) {
        return in_array( (int) $user_id, $GLOBALS['pandatask_test_group_members'][ (int) $group_id ] ?? array(), true );
    }
}
if ( ! function_exists( 'groups_get_user_groups' ) ) {
    function groups_get_user_groups( $user_id = 0, $pag_num = 0, $pag_page = 0 ) {
        $group_ids = array();
        foreach ( $GLOBALS['pandatask_test_groups'] as $group ) {
            if ( groups_is_user_member( $user_id, $group->id ) ) {
                $group_ids[] = (int) $group->id;
            }
        }
        return array( 'groups' => $group_ids, 'total' => count( $group_ids ) );
    }
}
if ( ! function_exists( 'groups_get_group' ) ) {
    function groups_get_group( $group_id ) { return $GLOBALS['pandatask_test_groups'][ (int) $group_id ] ?? null; }
}
if ( ! function_exists( 'bp_get_group_url' ) ) {
    function bp_get_group_url( $group ) { return 'https://example.test/groups/' . $group->slug . '/'; }
}
if ( ! function_exists( 'get_userdata' ) ) {
    function get_userdata( $user_id ) { return (object) array( 'ID' => (int) $user_id, 'display_name' => 'User ' . (int) $user_id ); }
}
if ( ! function_exists( 'get_avatar_url' ) ) {
    function get_avatar_url( $user_id, $args = array() ) { return 'https://example.test/avatar/' . (int) $user_id; }
}
if ( ! function_exists( 'bp_core_get_user_domain' ) ) {
    function bp_core_get_user_domain( $user_id ) { return 'https://example.test/members/user-' . (int) $user_id . '/'; }
}
if ( ! function_exists( 'user_can' ) ) {
    function user_can( $user_id, $capability ) {
        return ! empty( $GLOBALS['pandatask_test_capabilities'][ (int) $user_id ][ $capability ] );
    }
}

$GLOBALS['pandatask_test_group_meta'] = array(
    10 => array( 'pandat69_work_logs_enabled' => '1' ),
    11 => array( 'pandat69_work_logs_enabled' => '0' ),
    12 => array( 'pandat69_work_logs_enabled' => '1' ),
);
$GLOBALS['pandatask_test_group_members'] = array(
    10 => array( 7, 8 ),
    11 => array( 7 ),
    12 => array( 8 ),
);
$GLOBALS['pandatask_test_groups'] = array(
    10 => (object) array( 'id' => 10, 'name' => 'Trustees', 'slug' => 'trustees' ),
    11 => (object) array( 'id' => 11, 'name' => 'Disabled group', 'slug' => 'disabled' ),
    12 => (object) array( 'id' => 12, 'name' => 'Other group', 'slug' => 'other' ),
);
$GLOBALS['pandatask_test_capabilities'] = array();
$share_rows = array( 7 => array( 10 ), 8 => array( 10 ) );
$share_repository = new class( $share_rows ) {
    public $rows;
    public function __construct( $rows ) { $this->rows = $rows; }
    public function sharedGroupIdsForUser( $user_id ) { return $this->rows[ (int) $user_id ] ?? array(); }
    public function userIdsForGroup( $group_id ) {
        $users = array();
        foreach ( $this->rows as $user_id => $group_ids ) {
            if ( in_array( (int) $group_id, $group_ids, true ) ) { $users[] = (int) $user_id; }
        }
        return $users;
    }
    public function hasGrant( $user_id, $group_id ) { return in_array( (int) $group_id, $this->rows[ (int) $user_id ] ?? array(), true ); }
    public function replaceForUser( $user_id, array $group_ids ) { $this->rows[ (int) $user_id ] = $group_ids; return true; }
};
$feature_on = new class {
    public function workLogEnabled() { return true; }
};
$feature_off = new class {
    public function workLogEnabled() { return false; }
};
$share_policy = new WorkLogShareAccessPolicy( $share_repository, $feature_on );
$fake_report_repository = new class {
    public $batch_calls = 0;
    public function personalTotalsForUsers( array $user_ids, $start_date, $end_date ) {
        ++$this->batch_calls;
        return array_fill_keys( $user_ids, 3600 );
    }
    public function personalSummary( $user_id, $start_date, $end_date ) {
        return array( 'total_seconds' => 3600 + (int) $user_id, 'unresolved' => array( 'private' => true ) );
    }
};
$fake_entry_repository = new class {
    public function findForUser( $user_id, $start_date, $end_date, $limit, $offset ) {
        return array(
            (object) array(
                'id'               => 81,
                'user_id'          => (int) $user_id,
                'created_by'       => 99,
                'title'            => 'Shared entry',
                'notes'            => 'Visible detail',
                'activity_type'    => 'development',
                'capacity'         => 'volunteer',
                'work_date'        => '2026-08-25',
                'started_at_utc'   => '2026-08-25 08:00:00',
                'ended_at_utc'     => '2026-08-25 09:00:00',
                'timezone'         => 'Europe/Warsaw',
                'duration_seconds' => 3600,
                'kind'             => 'imported',
                'source_key'       => 'provider:private-import-key',
                'source_url'       => 'https://private.example/item/42',
                'visibility'       => 'private',
                'deleted_at'       => null,
                'created_at'       => '2026-08-25 10:00:00',
                'updated_at'       => '2026-08-25 10:00:00',
                'allocations'      => array(
                    (object) array(
                        'id'                     => 91,
                        'work_entry_id'          => 81,
                        'occurrence_id'           => 52,
                        'seconds'                 => 3600,
                        'task_id_snapshot'        => 42,
                        'task_name_snapshot'      => 'Build sharing',
                        'board_name_snapshot'     => 'trustees',
                        'project_id_snapshot'     => 14,
                        'project_name_snapshot'   => 'Pandatask',
                        'category_id_snapshot'    => 3,
                        'category_name_snapshot'  => 'Development',
                    ),
                ),
            ),
        );
    }
};
$fake_work_types = new class {
    public function all( $user_id ) { return array( array( 'key' => 'development', 'label' => 'Development' ) ); }
};
$share_service = new WorkLogShareService( $share_repository, $fake_report_repository, $fake_entry_repository, $fake_work_types, $feature_on, $share_policy );
$sharing = $share_service->getSharing( 7 );
$assert( ! is_wp_error( $sharing ) && array( 10 ) === $sharing['shared_group_ids'], 'Sharing GET must return only valid enabled grants for current groups.' );
$assert( true === $sharing['groups'][0]['enabled'] && true === $sharing['groups'][0]['shared'], 'Enabled shared group metadata is incomplete.' );
$invalid_disabled = $share_service->replaceSharing( 7, array( 11 ) );
$assert( is_wp_error( $invalid_disabled ) && 422 === $invalid_disabled->get_error_data()['status'], 'Sharing PUT must reject disabled groups.' );
$invalid_nonmember = $share_service->replaceSharing( 7, array( 12 ) );
$assert( is_wp_error( $invalid_nonmember ) && 422 === $invalid_nonmember->get_error_data()['status'], 'Sharing PUT must reject groups the owner does not belong to.' );
$valid_replace = $share_service->replaceSharing( 7, array( 10 ) );
$assert( ! is_wp_error( $valid_replace ) && $share_repository->hasGrant( 7, 10 ), 'Sharing PUT must replace the owner grants.' );
$assert( true === $share_policy->canReadOwner( 10, 7, 8 ), 'A current group member should be able to read a valid explicitly shared log.' );
$cleared_sharing = $share_service->replaceSharing( 7, array() );
$revoked_access = $share_policy->canReadOwner( 10, 7, 8 );
$assert( ! is_wp_error( $cleared_sharing ) && is_wp_error( $revoked_access ) && 403 === $revoked_access->get_error_data()['status'], 'Clearing a sharing choice must revoke read access immediately.' );
$share_service->replaceSharing( 7, array( 10 ) );
$GLOBALS['pandatask_test_group_members'][10] = array( 8 );
$assert( ! $share_policy->hasValidGrant( 7, 10 ), 'A grant must be revoked logically when the owner leaves the group.' );
$membership_revoked = $share_policy->canReadOwner( 10, 7, 8 );
$assert( is_wp_error( $membership_revoked ) && 403 === $membership_revoked->get_error_data()['status'], 'Owner membership loss must revoke shared-log access immediately.' );
$GLOBALS['pandatask_test_group_members'][10] = array( 7, 8 );
$viewer_denied = $share_policy->canReadGroup( 12, 7 );
$assert( is_wp_error( $viewer_denied ) && 403 === $viewer_denied->get_error_data()['status'], 'Group work-log routes must reject viewers outside the group.' );
$GLOBALS['pandatask_test_capabilities'][ 9 ] = array( 'bp_moderate' => true );
$assert( true === $share_policy->canReadGroup( 10, 9 ), 'bp_moderate viewers should be able to read an enabled group.' );
$share_global_off = new WorkLogShareAccessPolicy( $share_repository, $feature_off );
$assert( is_wp_error( $share_global_off->canReadGroup( 10, 8 ) ), 'Group sharing must remain feature-gated by the global Work Log setting.' );
$presenters = $share_service->groupPresenters( 10, '2026-08-01', '2026-08-31' );
$assert( ! is_wp_error( $presenters ) && 2 === count( $presenters['presenters'] ), 'Group presenter response should include only valid grants.' );
$assert( 1 === $fake_report_repository->batch_calls, 'Group presenter totals must be fetched with one batched aggregate query.' );
$owner_log = $share_service->groupOwnerLog( 10, 7, '2026-08-01', '2026-08-31' );
$assert( ! is_wp_error( $owner_log ) && isset( $owner_log['activity_types'], $owner_log['entries'], $owner_log['report'] ), 'Shared owner response is missing a required section.' );
$assert( ! array_key_exists( 'unresolved', $owner_log['report'] ) && ! array_key_exists( 'action', $owner_log['report'] ), 'Shared reports must omit unresolved/action state.' );
$shared_entry = $owner_log['entries'][0];
$assert( 'Shared entry' === $shared_entry['title'] && 'Visible detail' === $shared_entry['notes'], 'Shared entries must retain member-visible work-log detail.' );
$assert( 42 === $shared_entry['allocations'][0]['task_id_snapshot'] && 'Build sharing' === $shared_entry['allocations'][0]['task_name_snapshot'], 'Shared entries must retain allocation context.' );
foreach ( array( 'created_by', 'source_key', 'source_url', 'visibility', 'deleted_at', 'created_at', 'updated_at' ) as $private_entry_key ) {
    $assert( ! array_key_exists( $private_entry_key, $shared_entry ), 'Shared entries must omit private/internal field: ' . $private_entry_key );
}
foreach ( array( 'id', 'work_entry_id', 'occurrence_id' ) as $private_allocation_key ) {
    $assert( ! array_key_exists( $private_allocation_key, $shared_entry['allocations'][0] ), 'Shared allocations must omit private/internal field: ' . $private_allocation_key );
}

$GLOBALS['pandatask_registered_routes'] = array();
if ( ! function_exists( 'register_rest_route' ) ) {
    function register_rest_route( $namespace, $route, $args ) { $GLOBALS['pandatask_registered_routes'][] = $namespace . $route; }
}
$fake_permissions = new stdClass();
$fake_handler = new stdClass();
$fake_policy = new stdClass();
( new WorkRouteRegistrar( 'pandatask/v1', $fake_permissions, $fake_handler, $fake_policy, $feature_off ) )->register();
$assert( array( 'pandatask/v1/tasks/(?P<id>\\d+)/complete' ) === $GLOBALS['pandatask_registered_routes'], 'Disabled Work Log should leave only task completion registered.' );
$GLOBALS['pandatask_registered_routes'] = array();
$feature_on_routes = new class {
    public function workLogEnabled() { return true; }
};
( new WorkRouteRegistrar( 'pandatask/v1', $fake_permissions, $fake_handler, $fake_policy, $feature_on_routes, $share_policy, $share_service ) )->register();
$assert( in_array( 'pandatask/v1/users/me/work-log-sharing', $GLOBALS['pandatask_registered_routes'], true ), 'Work Log sharing settings routes must be registered when Work Log is enabled.' );
$assert( in_array( 'pandatask/v1/groups/(?P<group_id>\\d+)/work-logs', $GLOBALS['pandatask_registered_routes'], true ), 'Group Work Log presenter route must be registered.' );
$assert( in_array( 'pandatask/v1/groups/(?P<group_id>\\d+)/work-logs/(?P<user_id>\\d+)', $GLOBALS['pandatask_registered_routes'], true ), 'Group Work Log detail route must be registered.' );

$handler = new WorkRouteHandler( new stdClass(), new stdClass(), new stdClass(), new stdClass(), new stdClass(), new stdClass(), $feature_off );
$method = new ReflectionMethod( $handler, 'namedDateRange' );
$method->setAccessible( true );
$range = $method->invoke( $handler, 'last_month' );
$today = new DateTimeImmutable( 'now', wp_timezone() );
$assert(
    $range[0] === $today->modify( 'first day of last month' )->format( 'Y-m-d' )
        && $range[1] === $today->modify( 'last day of last month' )->format( 'Y-m-d' ),
    'last_month must end on the last day of the previous month.'
);

$schema_source = file_get_contents( dirname( __DIR__ ) . '/src/Infrastructure/Setup/DatabaseLifecycle.php' );
$assert( false !== strpos( $schema_source, "DB_VERSION = '1.0.19'" ), 'Database version must include the current work/inbox schema.' );
foreach ( array( 'work_log_group_shares', 'user_group', 'group_user', 'KEY user_id', 'KEY group_id' ) as $schema_token ) {
    $assert( false !== strpos( $schema_source, $schema_token ), 'Work-log sharing schema contract is missing: ' . $schema_token );
}

if ( ! empty( $failures ) ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

echo "Work Log backend tests passed.\n";
