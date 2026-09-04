<?php

$pandatask_test_options = array();
$pandatask_test_caps    = array();
$pandatask_test_transients = array();
$pandatask_test_logged_in = false;
$pandatask_test_post_types = array();

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

function get_userdata( $user_id ) {
    $user_id = (int) $user_id;
    return $user_id > 0 ? (object) array( 'ID' => $user_id, 'display_name' => 'User ' . $user_id ) : false;
}

function user_can( $user_id, $capability ) {
    global $pandatask_test_caps;
    return ! empty( $pandatask_test_caps[ (int) $user_id ][ $capability ] );
}

function current_user_can( $capability, ...$args ) {
    global $pandatask_test_caps;
    $object_key = $args ? $capability . ':' . (int) $args[0] : $capability;
    return ! empty( $pandatask_test_caps[99][ $object_key ] ) || ! empty( $pandatask_test_caps[99][ $capability ] );
}

function get_post_type( $post_id ) {
    global $pandatask_test_post_types;
    return $pandatask_test_post_types[ (int) $post_id ] ?? false;
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

final class SecurityPolicyTestWpdb {
    public $prefix = 'wp_';

    public function prepare( $query, ...$args ) {
        unset( $args );
        return $query;
    }

    public function get_row( $query ) {
        unset( $query );

        return (object) array(
            'id'                  => 41,
            'board_name'          => 'group_12',
            'creator_id'          => null,
            'inbox_state'         => null,
            'follow_up_of_task_id' => null,
            'status'              => 'pending',
        );
    }

    public function get_results( $query ) {
        if ( false !== strpos( $query, 'SELECT t.id' ) ) {
            return array(
                (object) array(
                    'id'                  => 41,
                    'board_name'          => 'group_12',
                    'creator_id'          => null,
                    'inbox_state'         => null,
                    'follow_up_of_task_id' => null,
                    'status'              => 'pending',
                ),
            );
        }

        return array();
    }
}

require_once __DIR__ . '/../src/Infrastructure/Persistence/DatabaseContext.php';
require_once __DIR__ . '/../src/Infrastructure/Persistence/TaskRepository.php';

function assert_same( $expected, $actual, $message ) {
    if ( $expected !== $actual ) {
        fwrite( STDERR, $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
        exit( 1 );
    }
}

require_once __DIR__ . '/../src/Application/Security/PublicBugSubmissionPolicy.php';
require_once __DIR__ . '/../src/Application/Security/InboxAccessPolicy.php';
require_once __DIR__ . '/../src/Application/Security/TaskAccessPolicy.php';
require_once __DIR__ . '/../src/Application/Security/CommentAccessPolicy.php';
require_once __DIR__ . '/../src/Application/Security/MediaAttachmentAccessPolicy.php';

use Pandatask\Application\Security\CommentAccessPolicy;
use Pandatask\Application\Security\PublicBugSubmissionPolicy;
use Pandatask\Application\Security\InboxAccessPolicy;
use Pandatask\Application\Security\MediaAttachmentAccessPolicy;
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

$inbox_repository = new class() {
    public function roleFor( $owner_user_id, $user_id ) {
        return 8 === (int) $owner_user_id && 9 === (int) $user_id ? 'triager' : null;
    }
};
$inbox_policy = new InboxAccessPolicy( $inbox_repository );
$task_policy = new TaskAccessPolicy( $task_service, $board_policy, $inbox_policy );
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

$anonymous_task = (object) array(
    'id'                  => 43,
    'board_name'          => 'user_8',
    'creator_id'          => null,
    'assigned_user_ids'   => array( 0 ),
    'supervisor_user_ids' => array( 0 ),
    'inbox_state'         => 'untriaged',
);
$anonymous_task_service = new class( $anonymous_task ) {
    private $task;

    public function __construct( $task ) {
        $this->task = $task;
    }

    public function getTaskForAuthorization( $task_id ) {
        return 43 === (int) $task_id ? $this->task : null;
    }
};
$anonymous_board_policy = new class() {
    public function canReadBoard( $board_name, $user_id ) {
        unset( $board_name, $user_id );
        return true;
    }

    public function canManageBoard( $board_name, $user_id ) {
        unset( $board_name, $user_id );
        return true;
    }
};
$anonymous_inbox_policy = new class() {
    public function canTriageInbox( $owner_user_id, $user_id ) {
        unset( $owner_user_id, $user_id );
        return true;
    }
};
$pandatask_test_caps[0] = array( 'manage_options' => true );
$anonymous_task_policy = new TaskAccessPolicy( $anonymous_task_service, $anonymous_board_policy, $anonymous_inbox_policy );
foreach ( array( null, 0 ) as $creator_id ) {
    $anonymous_task->creator_id = $creator_id;

    foreach ( array( 'canReadTask', 'canAccessTask', 'canUpdateTask', 'canDeleteTask', 'canManageTaskRoles', 'canMoveTask' ) as $method ) {
        $anonymous_result = $anonymous_task_policy->{$method}( 43, 0 );
        assert_same( true, $anonymous_result instanceof WP_Error, 'Anonymous actors must not authorize tasks with a null or zero creator through ' . $method . '.' );
        assert_same( 401, $anonymous_result->data['status'] ?? null, 'Anonymous task authorization must return HTTP 401 through ' . $method . '.' );
    }
}

$GLOBALS['wpdb'] = new SecurityPolicyTestWpdb();
$task_repository = new \Pandatask\Infrastructure\Persistence\TaskRepository();
$single_access_record = $task_repository->findAccessRecordById( 41 );
assert_same( null, $single_access_record->creator_id, 'Single-task access normalization must preserve a NULL creator ID.' );
$batch_access_records = $task_repository->findAccessRecordsByIds( array( 41 ) );
assert_same( null, $batch_access_records[41]->creator_id, 'Batch-task access normalization must preserve a NULL creator ID.' );

$inbox_task = (object) array(
    'id'                  => 42,
    'board_name'          => 'user_8',
    'creator_id'          => 8,
    'assigned_user_ids'   => array( 8 ),
    'supervisor_user_ids' => array(),
    'inbox_state'         => 'untriaged',
);
$inbox_task_service = new class( $inbox_task ) {
    private $task;
    public function __construct( $task ) { $this->task = $task; }
    public function getTaskForAuthorization( $task_id ) { return 42 === (int) $task_id ? $this->task : null; }
};
$inbox_task_policy = new TaskAccessPolicy( $inbox_task_service, $board_policy, $inbox_policy );
assert_same( true, $inbox_task_policy->canReadTask( 42, 9 ), 'Inbox triagers should be able to read delegated inbox tasks.' );
assert_same( true, $inbox_task_policy->canUpdateTask( 42, 9 ), 'Inbox triagers should be able to edit delegated inbox tasks.' );
assert_same( true, $inbox_task_policy->canMoveTask( 42, 9 ), 'Inbox triagers should be able to initiate a move, subject to destination-board permission.' );
assert_same( false, true === $inbox_task_policy->canDeleteTask( 42, 9 ), 'Inbox triage must not grant task deletion authority.' );

$media_policy = new MediaAttachmentAccessPolicy();
$pandatask_test_post_types[501] = 'attachment';
$pandatask_test_caps[99] = array( 'upload_files' => true );
$attachment_denied = $media_policy->authorize( 501 );
assert_same( 'rest_forbidden_attachment', $attachment_denied->code ?? null, 'Media attachments must require object-level edit permission.' );
assert_same( 403, $attachment_denied->data['status'] ?? null, 'Unauthorized Media attachments should return HTTP 403 semantics.' );
$pandatask_test_caps[99]['edit_post:501'] = true;
assert_same( true, $media_policy->authorize( 501 ), 'Authorized Media attachments should remain attachable.' );
$pandatask_test_caps[99] = array();
assert_same( true, $media_policy->authorize( 501, (object) array( 'attachment_post_id' => 501 ) ), 'Unrelated updates must retain an existing attachment without requiring Media capabilities again.' );
$attachment_invalid = $media_policy->authorize( 999 );
assert_same( 'rest_invalid_attachment', $attachment_invalid->code ?? null, 'Non-attachment post IDs must retain validation failure semantics.' );
assert_same( 422, $attachment_invalid->data['status'] ?? null, 'Invalid Media references should return HTTP 422 semantics.' );

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
