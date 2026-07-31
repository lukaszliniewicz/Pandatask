<?php

require_once dirname( __DIR__ ) . '/src/Domain/Task/TaskGraph.php';
require_once dirname( __DIR__ ) . '/src/Domain/Task/RecurrenceCalculator.php';

use Pandatask\Domain\Task\RecurrenceCalculator;
use Pandatask\Domain\Task\TaskGraph;

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
$assert_same( '2026-08-03', $recurrence->next( '2026-07-31', 'custom_weekly', 1, '1,3,5' ), 'Custom weekday recurrence is incorrect.' );
$assert_same( '2026-08-14', $recurrence->onOrAfter( '2026-07-31', '2026-08-13', 'custom_weekly', 1, '1,3,5' ), 'Custom weekday catch-up is incorrect.' );
$assert_same( null, $recurrence->next( '2026-07-31', 'custom_weekly', 1, '' ), 'Invalid custom recurrence should be rejected.' );

if ( ! empty( $failures ) ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

echo "Backend domain tests passed.\n";
