<?php

$pandatask_test_options = array();
$pandatask_test_caps    = array();
$pandatask_test_transients = array();
$pandatask_test_logged_in = false;

define( 'MINUTE_IN_SECONDS', 60 );

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

function sanitize_text_field( $value ) {
    return trim( strip_tags( (string) $value ) );
}

function wp_unslash( $value ) {
    return $value;
}

function wp_salt() {
    return 'pandatask-test-salt';
}

function apply_filters( $name, $value ) {
    return $value;
}

function is_user_logged_in() {
    global $pandatask_test_logged_in;
    return $pandatask_test_logged_in;
}

function get_transient( $key ) {
    global $pandatask_test_transients;
    return $pandatask_test_transients[ $key ] ?? false;
}

function set_transient( $key, $value, $expiration ) {
    global $pandatask_test_transients;
    $pandatask_test_transients[ $key ] = $value;
    return true;
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
require_once __DIR__ . '/../src/Application/Security/CommentAccessPolicy.php';

use Pandatask\Application\Security\CommentAccessPolicy;
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

$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
for ( $submission = 0; $submission < 5; $submission++ ) {
    assert_same( true, $public_policy->consumeAnonymousSubmissionBudget(), 'Anonymous submissions within the budget should be accepted.' );
}
assert_same( true, $public_policy->consumeAnonymousSubmissionBudget() instanceof WP_Error, 'Anonymous submission floods should be rate limited.' );

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
assert_same( false, $task_policy->canManageTaskRoles( 41, 4 ), 'Assignees must not be able to promote task roles.' );
assert_same( true, $task_policy->canManageTaskRoles( 41, 5 ), 'Supervisors should be able to manage task roles.' );
assert_same( true, $task_policy->canManageTaskRoles( 41, 3 ), 'Task creators should be able to manage task roles.' );
assert_same( true, $task_policy->canManageTaskRoles( 41, 7 ), 'Board managers should be able to manage task roles.' );
assert_same( false, $task_policy->canMoveTask( 41, 3 ), 'Task creators without board-manager rights must not move security scopes.' );
assert_same( true, $task_policy->canMoveTask( 41, 7 ), 'Board managers should be able to move task security scopes.' );
$unrelated_read_result = $task_policy->canReadTask( 41, 8 );
assert_same( true, $unrelated_read_result instanceof WP_Error || false === $unrelated_read_result, 'Unrelated users should not receive read access.' );
assert_same( true, $task_policy->canReadTask( 999, 4 ) instanceof WP_Error, 'Missing tasks should return a not-found error.' );

$comment_service = new class() {
    public function getComment( $comment_id ) {
        return 17 === $comment_id ? (object) array( 'id' => 17, 'task_id' => 41 ) : null;
    }
    public function canUserManageComment( $comment ) {
        return true;
    }
};
$denied_task_policy = new class() {
    public function canReadTask( $task_id, $user_id ) {
        return new WP_Error( 'rest_forbidden', 'Task access revoked.', array( 'status' => 403 ) );
    }
};
$comment_policy = new CommentAccessPolicy( $comment_service, $denied_task_policy );
assert_same( true, $comment_policy->canManageComment( 17 ) instanceof WP_Error, 'Former task viewers must not manage comments after access is revoked.' );

fwrite( STDOUT, "Security policy tests passed.\n" );
