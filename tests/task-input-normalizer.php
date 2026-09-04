<?php

/**
 * Focused recurrence and effort checks for the REST task input adapter.
 *
 * Run with: php tests/task-input-normalizer.php
 */

if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = null ) {
        unset( $domain );
        return $text;
    }
}
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        private $code;
        private $message;
        private $data;
        public function __construct( $code, $message, $data = null ) {
            $this->code = $code;
            $this->message = $message;
            $this->data = $data;
        }
        public function get_error_code() { return $this->code; }
        public function get_error_message() { return $this->message; }
        public function get_error_data() { return $this->data; }
    }
}
if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $value ) { return $value instanceof WP_Error; }
}
if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
}
if ( ! function_exists( 'sanitize_file_name' ) ) {
    function sanitize_file_name( $value ) { return (string) $value; }
}
if ( ! function_exists( 'esc_url_raw' ) ) {
    function esc_url_raw( $value ) { return (string) $value; }
}
if ( ! function_exists( 'absint' ) ) {
    function absint( $value ) { return abs( (int) $value ); }
}
if ( ! function_exists( 'rest_sanitize_boolean' ) ) {
    function rest_sanitize_boolean( $value ) { return filter_var( $value, FILTER_VALIDATE_BOOLEAN ); }
}
if ( ! function_exists( 'wp_timezone' ) ) {
    function wp_timezone() { return new DateTimeZone( 'UTC' ); }
}
if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $hook, $value ) {
        unset( $hook );
        return $value;
    }
}
if ( ! function_exists( 'wp_kses_allowed_html' ) ) {
    function wp_kses_allowed_html( $context ) {
        unset( $context );
        return array();
    }
}
if ( ! function_exists( 'wp_kses' ) ) {
    function wp_kses( $value, $allowed ) {
        unset( $allowed );
        return (string) $value;
    }
}

require_once dirname( __DIR__ ) . '/src/Application/Task/TaskDescriptionService.php';
require_once dirname( __DIR__ ) . '/src/Domain/Task/TaskGraph.php';
require_once dirname( __DIR__ ) . '/src/Application/Task/TaskInvariantService.php';
require_once dirname( __DIR__ ) . '/src/Http/Rest/V1/Support/RequestHelper.php';
require_once dirname( __DIR__ ) . '/src/Http/Rest/V1/Support/TaskInputNormalizer.php';

use Pandatask\Application\Task\TaskInvariantService;
use Pandatask\Http\Rest\V1\Support\TaskInputNormalizer;

final class TaskInputTestTaskRepository {
    public function findBoardTaskRecordsByIds() { return array(); }
    public function findIncompletePredecessorIds() { return array(); }
}
final class TaskInputTestScopedRepository {
    public function existsOnBoard() { return true; }
}
final class TaskInputTestBoardPolicy {
    public function isUserAllowedOnBoard() { return true; }
}
final class TaskInputTestMediaPolicy {
    public function authorize() { return true; }
}

$normalizer = ( new ReflectionClass( TaskInputNormalizer::class ) )->newInstanceWithoutConstructor();
$invariants = new TaskInvariantService(
    new TaskInputTestTaskRepository(),
    new TaskInputTestScopedRepository(),
    new TaskInputTestScopedRepository(),
    new TaskInputTestBoardPolicy(),
    new TaskInputTestMediaPolicy()
);
$failures = array();
$assert_same = static function ( $expected, $actual, $message ) use ( &$failures ) {
    if ( $expected !== $actual ) {
        $failures[] = $message . ' Expected ' . var_export( $expected, true ) . ', received ' . var_export( $actual, true ) . '.';
    }
};
$assert_error = static function ( $actual, $message_fragment, $message ) use ( &$failures ) {
    if ( ! is_wp_error( $actual ) || false === strpos( $actual->get_error_message(), $message_fragment ) ) {
        $failures[] = $message;
    }
};

$base = array(
    'name'         => 'Recurring task',
    'is_recurring' => true,
    'start_date'   => '2026-09-06',
    'deadline'     => '2026-09-06',
);

$weekly = $normalizer->buildCreateData( 'group_delivery', $base + array(
    'recurrence_frequency' => 'weekly',
    'recurrence_interval'  => 4,
) );
$assert_same( 4, $weekly['recurrence_interval'] ?? null, 'Create normalization must preserve weekly intervals greater than one.' );

$sunday = $normalizer->buildCreateData( 'group_delivery', $base + array(
    'recurrence_frequency' => 'custom_weekly',
    'recurrence_interval'  => 4,
    'recurrence_days'      => '7',
) );
$assert_same( '7', $sunday['recurrence_days'] ?? null, 'ISO Sunday must remain encoded as 7.' );
$assert_same( 4, $sunday['recurrence_interval'] ?? null, 'Custom weekly cadence must preserve its interval.' );

$invalid_sunday = $normalizer->buildCreateData( 'group_delivery', $base + array(
    'recurrence_frequency' => 'custom_weekly',
    'recurrence_days'      => '0',
) );
$assert_error( $invalid_sunday, '1 (Monday) through 7 (Sunday)', 'Weekday 0 must be rejected with an actionable ISO-weekday error.' );

$malformed_weekdays = $normalizer->buildCreateData( 'group_delivery', $base + array(
    'recurrence_frequency' => 'custom_weekly',
    'recurrence_days'      => '1,,7',
) );
$assert_error( $malformed_weekdays, 'without empty entries', 'Malformed weekday lists must be rejected instead of silently canonicalized.' );

$first_sunday = $normalizer->buildCreateData( 'group_delivery', $base + array(
    'recurrence_frequency'  => 'monthly_weekday',
    'recurrence_interval'   => 1,
    'recurrence_days'       => '7',
    'recurrence_month_week' => 'first',
    'estimated_effort_seconds' => 5400,
) );
$assert_same( 'monthly_weekday', $first_sunday['recurrence_frequency'] ?? null, 'Monthly weekday frequency must survive normalization.' );
$assert_same( 'first', $first_sunday['recurrence_month_week'] ?? null, 'Monthly weekday ordinal must survive normalization.' );
$assert_same( 5400, $first_sunday['estimated_effort_seconds'] ?? null, 'Estimated effort must survive create normalization.' );
$validated_first_sunday = $invariants->applyAndValidate( $first_sunday );
$assert_same( 'first', $validated_first_sunday['recurrence_month_week'] ?? null, 'A complete monthly weekday rule must pass domain validation.' );

$missing_ordinal = $normalizer->buildCreateData( 'group_delivery', $base + array(
    'recurrence_frequency' => 'monthly_weekday',
    'recurrence_days'      => '7',
) );
$assert_error( $invariants->applyAndValidate( $missing_ordinal ), 'requires recurrence_month_week', 'Monthly weekday recurrence without an ordinal must return an actionable 422 error.' );

$multiple_monthly_days = $normalizer->buildCreateData( 'group_delivery', $base + array(
    'recurrence_frequency'  => 'monthly_weekday',
    'recurrence_days'       => '1,7',
    'recurrence_month_week' => 'last',
) );
$assert_error( $invariants->applyAndValidate( $multiple_monthly_days ), 'exactly one ISO weekday', 'Monthly weekday recurrence with multiple weekdays must return an actionable 422 error.' );

$legacy_conflict = $normalizer->buildCreateData( 'group_delivery', $base + array(
    'recurrence_frequency' => 'bi-weekly',
    'recurrence_interval'  => 4,
) );
$assert_error( $legacy_conflict, 'legacy alias', 'Conflicting bi-weekly intervals must be rejected rather than overwritten.' );

$unsupported = $normalizer->buildCreateData( 'group_delivery', $base + array(
    'recurrence_frequency'  => 'weekly',
    'recurrence_month_week' => 'last',
) );
$assert_error( $unsupported, 'supported only', 'Monthly ordinal fields must be rejected for non-monthly-weekday recurrence.' );

$update = $normalizer->buildUpdateData( array(
    'recurrence_frequency' => 'weekly',
    'recurrence_interval'  => 4,
) );
$assert_same( 4, $update['recurrence_interval'] ?? null, 'Update normalization must preserve an explicit weekly interval.' );

if ( ! empty( $failures ) ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

echo "Task input normalizer tests passed.\n";
