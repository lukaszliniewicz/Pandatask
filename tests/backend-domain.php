<?php

if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = null ) { return $text; }
}
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        private $code;
        private $message;
        private $data;
        public function __construct( $code, $message, $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
        public function get_error_code() { return $this->code; }
        public function get_error_message() { return $this->message; }
        public function get_error_data() { return $this->data; }
    }
}
if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $value ) { return $value instanceof WP_Error; }
}

require_once dirname( __DIR__ ) . '/src/Domain/Task/TaskGraph.php';
require_once dirname( __DIR__ ) . '/src/Domain/Task/RecurrenceCalculator.php';
require_once dirname( __DIR__ ) . '/src/Domain/Work/ActivityTypes.php';
require_once dirname( __DIR__ ) . '/src/Domain/Work/TimeReconciler.php';

use Pandatask\Domain\Task\RecurrenceCalculator;
use Pandatask\Domain\Task\TaskGraph;
use Pandatask\Domain\Work\ActivityTypes;
use Pandatask\Domain\Work\TimeReconciler;

$failures = array();

$assert_same = static function ( $expected, $actual, $message ) use ( &$failures ) {
    if ( $expected !== $actual ) {
        $failures[] = $message . ' Expected ' . var_export( $expected, true ) . ', received ' . var_export( $actual, true ) . '.';
    }
};

$graph = array(
    1 => array( 2 ),
    2 => array( 3 ),
);
$assert_same( true, TaskGraph::wouldCreateCycle( $graph, 3, 1 ), 'Dependency cycle detection failed.' );
$assert_same( false, TaskGraph::wouldCreateCycle( $graph, 4, 1 ), 'Acyclic dependency was rejected.' );
$assert_same(
    array( 1, 2, 3 ),
    TaskGraph::findCycleNodes(
        array(
            1 => array( 2 ),
            2 => array( 3 ),
            3 => array( 1 ),
            4 => array( 2 ),
        )
    ),
    'Cycle membership is incorrect.'
);

$recurrence = new RecurrenceCalculator();
$assert_same( '2024-02-29', $recurrence->next( '2024-01-31', 'monthly', 1, '', 31 ), 'January month-end did not clamp to leap day.' );
$assert_same( '2024-03-31', $recurrence->next( '2024-02-29', 'monthly', 1, '', 31 ), 'Monthly recurrence drifted after a clamped occurrence.' );
$assert_same( '2024-05-31', $recurrence->onOrAfter( '2024-01-31', '2024-05-01', 'monthly', 1, '', 31 ), 'Monthly catch-up lost its anchor.' );
$assert_same( '2026-08-28', $recurrence->next( '2026-07-31', 'weekly', 4 ), 'Weekly interval recurrence is incorrect.' );
$assert_same( '2026-08-03', $recurrence->next( '2026-07-31', 'custom_weekly', 1, '1,3,5' ), 'Custom weekday recurrence is incorrect.' );
$assert_same( '2026-07-29', $recurrence->next( '2026-07-27', 'custom_weekly', 4, '1,3,5' ), 'Custom weekday recurrence did not advance within its active week.' );
$assert_same( '2026-08-24', $recurrence->next( '2026-07-31', 'custom_weekly', 4, '1,3,5' ), 'Custom weekday recurrence did not jump by its interval after the last weekday.' );
$assert_same( '2026-08-14', $recurrence->onOrAfter( '2026-07-31', '2026-08-13', 'custom_weekly', 1, '1,3,5' ), 'Custom weekday catch-up is incorrect.' );
$assert_same( null, $recurrence->next( '2026-07-31', 'custom_weekly', 1, '' ), 'Invalid custom recurrence should be rejected.' );
$assert_same( '2024-02-04', $recurrence->next( '2024-01-15', 'monthly_weekday', 1, '7', 0, 'first' ), 'First Sunday monthly recurrence is incorrect.' );
$assert_same( '2024-02-18', $recurrence->next( '2024-01-15', 'monthly_weekday', 1, '7', 0, 'third' ), 'Middle ordinal Sunday monthly recurrence is incorrect.' );
$assert_same( '2024-02-25', $recurrence->next( '2024-01-15', 'monthly_weekday', 1, '7', 0, 'last' ), 'Last Sunday monthly recurrence is incorrect.' );
$assert_same( '2024-03-31', $recurrence->onOrAfter( '2024-01-07', '2024-03-01', 'monthly_weekday', 1, '7', 0, 'last' ), 'Monthly weekday catch-up is incorrect.' );
$assert_same( null, $recurrence->next( '2024-01-15', 'monthly_weekday', 1, '0', 0, 'first' ), 'Invalid monthly weekday should be rejected.' );
$assert_same( null, $recurrence->next( '2024-01-15', 'monthly_weekday', 1, '1,7', 0, 'first' ), 'Multiple monthly weekdays should be rejected.' );
$assert_same( null, $recurrence->next( '2024-01-15', 'monthly_weekday', 1, '7', 0, 'fifth' ), 'Invalid monthly week should be rejected.' );
$assert_same( null, $recurrence->next( '2024-01-15', 'monthly_weekday', 0, '7', 0, 'first' ), 'Invalid monthly weekday interval should be rejected.' );

$assert_same( true, ActivityTypes::isValid( 'research' ), 'Research activity type should be valid.' );
$assert_same( false, ActivityTypes::isValid( 'governance' ), 'Organisational subject must not become an activity type.' );
$reconciler = new TimeReconciler();
$assert_same(
    array( 'specific_seconds' => 6300, 'declared_actual_seconds' => 9000, 'residual_seconds' => 2700, 'state' => 'resolved' ),
    $reconciler->reconcile( 6300, 9000, false ),
    'Time reconciliation did not preserve the residual.'
);
$not_tracked = $reconciler->reconcile( 1800, null, true );
$assert_same( 'not_tracked', $not_tracked['state'], 'Not tracked was collapsed into zero.' );
$below_specific = $reconciler->reconcile( 3600, 1800, false );
$assert_same( true, $below_specific instanceof WP_Error, 'Actual below specific time should be rejected.' );

if ( ! empty( $failures ) ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

echo "Backend domain tests passed.\n";
