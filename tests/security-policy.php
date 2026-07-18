<?php

$pandatask_test_options = array();
$pandatask_test_caps    = array();

function get_option( $name, $default = false ) {
    global $pandatask_test_options;
    return $pandatask_test_options[ $name ] ?? $default;
}

function sanitize_key( $value ) {
    return strtolower( preg_replace( '/[^a-z0-9_-]/', '', (string) $value ) );
}

function absint( $value ) {
    return abs( (int) $value );
}

function get_current_user_id() {
    return 99;
}

function user_can( $user_id, $capability ) {
    global $pandatask_test_caps;
    return ! empty( $pandatask_test_caps[ (int) $user_id ][ $capability ] );
}

function __( $message ) {
    return $message;
}

class WP_Error {
    public $code;

    public $message;

    public $data;

    public function __construct( $code = '', $message = '', $data = array() ) {
        $this->code    = $code;
        $this->message = $message;
        $this->data    = $data;
    }
}

function assert_same( $expected, $actual, $message ) {
    if ( $expected !== $actual ) {
        fwrite( STDERR, $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
        exit( 1 );
    }
}

require_once __DIR__ . '/../src/Application/Security/PublicBugSubmissionPolicy.php';
require_once __DIR__ . '/../src/Application/Security/TaskAccessPolicy.php';

use Pandatask\Application\Security\PublicBugSubmissionPolicy;
use Pandatask\Application\Security\TaskAccessPolicy;

$pandatask_test_options['pandatask_bug_tracker_settings'] = array(
    'visibility' => 'logged_out',
    'board'      => 'group_12',
    'assignee'   => 7,
);

$public_policy = new PublicBugSubmissionPolicy();
assert_same( true, $public_policy->canSubmit( 'group_12', 'bug', false ), 'Anonymous configured bug submissions should be accepted.' );
assert_same( false, $public_policy->canSubmit( 'group_12', 'task', false ), 'Public task creation must be rejected.' );
assert_same( false, $public_policy->canSubmit( 'group_13', 'bug', false ), 'Public submissions to another board must be rejected.' );
assert_same( false, $public_policy->canSubmit( 'group_12', 'bug', true ), 'Logged-in submissions must respect logged-out-only visibility.' );

$pandatask_test_options['pandatask_bug_tracker_settings']['visibility'] = 'logged_in';
assert_same( true, $public_policy->canSubmit( 'group_12', 'bug', true ), 'Logged-in visibility should accept logged-in bug reports.' );
assert_same( false, $public_policy->canSubmit( 'group_12', 'bug', false ), 'Logged-in visibility should reject anonymous reports.' );
assert_same( 7, $public_policy->getConfiguredAssigneeId(), 'The configured assignee should be normalized.' );

$task = (object) array(
    'id'                  => 41,
    'board_name'          => 'group_12',
    'creator_id'          => 3,
    'assigned_user_ids'   => array( '4' ),
    'supervisor_user_ids' => array( '5' ),
);

$task_service = new class( $task ) {
    private $task;

    public function __construct( $task ) {
        $this->task = $task;
    }
    public function getTaskForAuthorization( $task_id ) {
        return 41 === $task_id ? $this->task : null;
    }
};

$board_policy = new class() {
    public function canReadBoard( $board_name, $user_id ) {
        return 6 === $user_id;
    }
    public function canManageBoard( $board_name, $user_id ) {
        return 7 === $user_id;
    }
};

$task_policy = new TaskAccessPolicy( $task_service, $board_policy );
assert_same( true, $task_policy->canReadTask( 41, 4 ), 'Assignees should be able to read their task.' );
assert_same( true, $task_policy->canUpdateTask( 41, 4 ), 'Assignees should be able to update their task.' );
assert_same( false, $task_policy->canDeleteTask( 41, 4 ), 'Assignees should not automatically be able to delete tasks.' );
assert_same( true, $task_policy->canDeleteTask( 41, 5 ), 'Supervisors should be able to delete tasks.' );
assert_same( true, $task_policy->canDeleteTask( 41, 3 ), 'Creators should be able to delete tasks.' );
assert_same( true, $task_policy->canReadTask( 41, 6 ), 'Board readers should be able to read tasks.' );
assert_same( true, $task_policy->canUpdateTask( 41, 7 ), 'Board managers should be able to update tasks.' );
assert_same( false, $task_policy->canUpdateTask( 41, 8 ), 'Unrelated users should not be able to update tasks.' );
$unrelated_read_result = $task_policy->canReadTask( 41, 8 );
assert_same( true, $unrelated_read_result instanceof WP_Error || false === $unrelated_read_result, 'Unrelated users should not receive read access.' );
assert_same( true, $task_policy->canReadTask( 999, 4 ) instanceof WP_Error, 'Missing tasks should return a not-found error.' );

fwrite( STDOUT, "Security policy tests passed.\n" );
