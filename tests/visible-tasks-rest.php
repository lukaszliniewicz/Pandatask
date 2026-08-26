<?php

/**
 * Focused contract checks for the cross-board task and work-entry REST additions.
 *
 * Run with: php tests/visible-tasks-rest.php
 */

if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = null ) { return $text; }
}
if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_-]/', '', (string) $key ) ); }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $text ) { return trim( strip_tags( (string) $text ) ); }
}
if ( ! function_exists( 'absint' ) ) {
    function absint( $value ) { return abs( (int) $value ); }
}
if ( ! function_exists( 'rest_sanitize_boolean' ) ) {
    function rest_sanitize_boolean( $value ) { return filter_var( $value, FILTER_VALIDATE_BOOLEAN ); }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
    function get_current_user_id() { return 7; }
}
if ( ! function_exists( 'user_can' ) ) {
    function user_can( $user_id, $capability ) {
        return ! empty( $GLOBALS['pandatask_test_caps'][ (int) $user_id ][ $capability ] );
    }
}
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        private $code;
        private $data;
        public function __construct( $code, $message, $data = array() ) { $this->code = $code; $this->data = $data; }
        public function get_error_code() { return $this->code; }
        public function get_error_data() { return $this->data; }
    }
}
if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $value ) { return $value instanceof WP_Error; }
}
if ( ! class_exists( 'WP_REST_Response' ) ) {
    class WP_REST_Response {
        private $data;
        private $status;
        public function __construct( $data, $status = 200 ) { $this->data = $data; $this->status = $status; }
        public function get_data() { return $this->data; }
        public function get_status() { return $this->status; }
    }
}

require_once dirname( __DIR__ ) . '/src/Infrastructure/Media/ProtectedAttachmentService.php';
require_once dirname( __DIR__ ) . '/src/Application/Task/TaskService.php';
require_once dirname( __DIR__ ) . '/src/Application/Security/WorkEntryAccessPolicy.php';
require_once dirname( __DIR__ ) . '/src/Http/Rest/V1/Support/RequestHelper.php';
require_once dirname( __DIR__ ) . '/src/Http/Rest/V1/TaskRouteHandler.php';
require_once dirname( __DIR__ ) . '/src/Http/Rest/V1/WorkRouteHandler.php';

use Pandatask\Application\Security\WorkEntryAccessPolicy;
use Pandatask\Application\Task\TaskService;
use Pandatask\Http\Rest\V1\TaskRouteHandler;
use Pandatask\Http\Rest\V1\WorkRouteHandler;

$failures = array();
$assert = static function ( $condition, $message ) use ( &$failures ) {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};
$GLOBALS['pandatask_test_caps'] = array();

$repository = new class {
    public $call;
    public function findVisibleForUser( ...$arguments ) {
        $this->call = $arguments;
        return array(
            (object) array( 'id' => 1, 'board_name' => 'standard', 'description' => '', 'attachment_type' => '' ),
            (object) array( 'id' => 2, 'board_name' => 'group_10', 'description' => '', 'attachment_type' => '' ),
            // This models the direct-assignment exception for an unreadable board.
            (object) array( 'id' => 3, 'board_name' => 'user_99', 'description' => '', 'attachment_type' => '' ),
        );
    }
};
$boards = new class {
    public function getAllBoardNames() {
        return array(
            (object) array( 'id' => 'standard' ),
            (object) array( 'id' => 'group_10' ),
            (object) array( 'id' => 'user_99' ),
        );
    }
    public function getBoardDisplayName( $board_name ) { return 'Board ' . $board_name; }
};
$board_policy = new class {
    public function canReadBoard( $board_name, $user_id ) {
        return in_array( $board_name, array( 'standard', 'group_10' ), true );
    }
};
$service = new TaskService( $repository, $boards, new stdClass(), new stdClass(), $board_policy );
$visible = $service->getVisibleTasksForUser( 7, 'alpha', 'deadline', 'DESC', 'pending_in-progress', 0, 12, false, 'task', true, 3, 4 );
$assert( array( 'standard', 'group_10' ) === $repository->call[1], 'Visible tasks must pass only board-policy-approved boards to the repository.' );
$assert( true === $repository->call[10], 'assigned_to_me must be forwarded as a repository-level restriction.' );
$assert( 3 === count( $visible ) && 'Board group_10' === $visible[1]->board_display_name, 'Visible tasks must preserve participant results and hydrate board display names.' );

$request = new class( array( 'limit' => 2, 'offset' => 3, 'sort' => 'deadline_desc', 'assigned_to_me' => true ) ) implements ArrayAccess {
    private $params;
    public function __construct( $params ) { $this->params = $params; }
    public function get_params() { return $this->params; }
    public function offsetExists( $offset ) { return isset( $this->params[ $offset ] ); }
    public function offsetGet( $offset ) { return $this->params[ $offset ] ?? null; }
    public function offsetSet( $offset, $value ) { $this->params[ $offset ] = $value; }
    public function offsetUnset( $offset ) { unset( $this->params[ $offset ] ); }
};
$handler_service = new class {
    public $call;
    public function getVisibleTasksForUser( ...$arguments ) {
        $this->call = $arguments;
        return array(
            (object) array( 'id' => 1, 'description' => '' ),
            (object) array( 'id' => 2, 'description' => '' ),
            (object) array( 'id' => 3, 'description' => '' ),
        );
    }
};
$task_handler = new TaskRouteHandler( $handler_service, new stdClass(), new stdClass(), new stdClass(), new stdClass(), new stdClass(), new stdClass() );
$response = $task_handler->get_visible_tasks( $request );
$payload = $response->get_data();
$assert( '' === $handler_service->call[4], 'Visible tasks must include every status when no status filter is supplied.' );
$assert( null === $handler_service->call[5], 'Visible tasks must include active and archived tasks when no archive filter is supplied.' );
$assert( true === $handler_service->call[7], 'Visible tasks must include recurring templates when no template filter is supplied.' );
$assert( 3 === $handler_service->call[10] && 3 === $handler_service->call[11], 'Visible task pagination must use limit + 1 without changing the offset.' );
$assert( 2 === count( $payload['tasks'] ) && true === $payload['pagination']['has_more'] && 5 === $payload['pagination']['next_offset'], 'Visible task pagination metadata is incorrect.' );

$entry_policy = new WorkEntryAccessPolicy( new class {
    public function findById( $entry_id ) { return 5 === $entry_id ? (object) array( 'id' => 5, 'user_id' => 7 ) : null; }
} );
$assert( true === $entry_policy->canManageEntry( 5, 7 ), 'Work-entry owners must be able to fetch their entry.' );
$assert( is_wp_error( $entry_policy->canManageEntry( 5, 8 ) ), 'Other users must not be able to fetch a work entry.' );
$GLOBALS['pandatask_test_caps'][9]['manage_options'] = true;
$assert( true === $entry_policy->canManageEntry( 5, 9 ), 'Administrators must be able to fetch a work entry.' );

$work_service = new class {
    public $call;
    public function getEntriesForUser( ...$arguments ) {
        $this->call = $arguments;
        return array( (object) array( 'id' => 1 ), (object) array( 'id' => 2 ), (object) array( 'id' => 3 ) );
    }
    public function getEntry( $entry_id ) { return 9 === $entry_id ? (object) array( 'id' => 9 ) : null; }
};
$work_handler = new WorkRouteHandler( $work_service, new stdClass(), new stdClass(), new stdClass(), new stdClass(), new stdClass(), new stdClass() );
$work_request = new class( array( 'limit' => 2, 'offset' => 4 ) ) implements ArrayAccess {
    private $params;
    public function __construct( $params ) { $this->params = $params; }
    public function offsetExists( $offset ) { return isset( $this->params[ $offset ] ); }
    public function offsetGet( $offset ) { return $this->params[ $offset ] ?? null; }
    public function offsetSet( $offset, $value ) { $this->params[ $offset ] = $value; }
    public function offsetUnset( $offset ) { unset( $this->params[ $offset ] ); }
};
$work_response = $work_handler->list_my_entries( $work_request )->get_data();
$assert( 3 === $work_service->call[3] && 4 === $work_service->call[4], 'Work-entry pagination must query limit + 1 at the requested offset.' );
$assert( 2 === count( $work_response['entries'] ) && true === $work_response['pagination']['has_more'] && 6 === $work_response['pagination']['next_offset'], 'Work-entry pagination metadata is incorrect.' );
$entry_response = $work_handler->get_entry( new class implements ArrayAccess {
    public function offsetExists( $offset ) { return 'id' === $offset; }
    public function offsetGet( $offset ) { return 9; }
    public function offsetSet( $offset, $value ) {}
    public function offsetUnset( $offset ) {}
} );
$assert( 200 === $entry_response->get_status() && 9 === $entry_response->get_data()['entry']->id, 'Entry GET must return an entry envelope.' );

$route_source = file_get_contents( dirname( __DIR__ ) . '/src/Http/Rest/V1/RouteRegistrar.php' );
$work_route_source = file_get_contents( dirname( __DIR__ ) . '/src/Http/Rest/V1/WorkRouteRegistrar.php' );
$board_repository_source = file_get_contents( dirname( __DIR__ ) . '/src/Infrastructure/Persistence/BoardRepository.php' );
$assert( false !== strpos( $route_source, "'/users/me/tasks'" ), 'The visible-task route must be registered.' );
$assert( false !== strpos( $work_route_source, "'callback'            => array( \$this->handler, 'get_entry' )" ), 'The work-entry GET route must be registered.' );
$assert( false === strpos( $board_repository_source, "board_name NOT LIKE 'group_%'" ), 'Task-backed hidden group boards must remain candidates for policy-filtered visible-task discovery.' );

if ( ! empty( $failures ) ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

echo "Visible task and work-entry REST tests passed.\n";
