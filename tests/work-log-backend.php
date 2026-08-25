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
require_once dirname( __DIR__ ) . '/src/Application/Work/WorkTypeService.php';
require_once dirname( __DIR__ ) . '/src/Application/Work/WorkEntryService.php';
require_once dirname( __DIR__ ) . '/src/Http/Rest/V1/WorkRouteRegistrar.php';
require_once dirname( __DIR__ ) . '/src/Http/Rest/V1/WorkRouteHandler.php';

use Pandatask\Application\Settings\FeatureSettings;
use Pandatask\Application\Work\WorkEntryService;
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

$GLOBALS['pandatask_registered_routes'] = array();
if ( ! function_exists( 'register_rest_route' ) ) {
    function register_rest_route( $namespace, $route, $args ) { $GLOBALS['pandatask_registered_routes'][] = $namespace . $route; }
}
$fake_permissions = new stdClass();
$fake_handler = new stdClass();
$fake_policy = new stdClass();
$feature_off = new class {
    public function workLogEnabled() { return false; }
};
( new WorkRouteRegistrar( 'pandatask/v1', $fake_permissions, $fake_handler, $fake_policy, $feature_off ) )->register();
$assert( array( 'pandatask/v1/tasks/(?P<id>\\d+)/complete' ) === $GLOBALS['pandatask_registered_routes'], 'Disabled Work Log should leave only task completion registered.' );

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

if ( ! empty( $failures ) ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

echo "Work Log backend tests passed.\n";
