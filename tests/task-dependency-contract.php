<?php

/**
 * Focused dependency-scope, predecessor-privacy, and workspace checks.
 *
 * Run with: php tests/task-dependency-contract.php
 */

if ( ! class_exists( 'Pandatask\Infrastructure\Persistence\DatabaseContext' ) ) {
    eval(
        'namespace Pandatask\Infrastructure\Persistence; final class DatabaseContext {
            public static $lock_available = true;
            public static $lock_acquired = 0;
            public static $lock_released = 0;
            public static function acquireDependencyGraphLock( $timeout_seconds = 5 ) {
                unset( $timeout_seconds );
                if ( ! self::$lock_available ) {
                    return false;
                }
                self::$lock_acquired++;
                return true;
            }
            public static function releaseDependencyGraphLock() {
                self::$lock_released++;
                return true;
            }
            public static function beginTransaction() { return true; }
            public static function commit() { return true; }
            public static function rollback() { return true; }
        }'
    );
}

if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = null ) {
        unset( $domain );
        return $text;
    }
}
if ( ! function_exists( 'absint' ) ) {
    function absint( $value ) {
        return abs( (int) $value );
    }
}
if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( $value ) {
        return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) );
    }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $value ) {
        return trim( strip_tags( (string) $value ) );
    }
}
if ( ! function_exists( 'esc_url_raw' ) ) {
    function esc_url_raw( $value ) {
        return (string) $value;
    }
}
if ( ! function_exists( 'rest_sanitize_boolean' ) ) {
    function rest_sanitize_boolean( $value ) {
        return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
    }
}
if ( ! function_exists( 'wp_timezone' ) ) {
    function wp_timezone() {
        return new DateTimeZone( 'UTC' );
    }
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
if ( ! function_exists( 'get_current_user_id' ) ) {
    function get_current_user_id() {
        return 7;
    }
}
if ( ! function_exists( 'user_can' ) ) {
    function user_can( $user_id, $capability ) {
        unset( $user_id, $capability );
        return false;
    }
}
if ( ! function_exists( 'get_transient' ) ) {
    function get_transient( $key ) {
        return false !== strpos( (string) $key, 'pandat69_board_url_' ) ? 'https://example.test/tasks' : false;
    }
}
if ( ! function_exists( 'set_transient' ) ) {
    function set_transient( $key, $value, $expiration ) {
        unset( $key, $value, $expiration );
    }
}
if ( ! function_exists( 'add_query_arg' ) ) {
    function add_query_arg( $key, $value, $url ) {
        return $url . '?' . $key . '=' . $value;
    }
}
if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $value ) {
        return $value instanceof WP_Error;
    }
}
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        private $code;
        private $message;
        private $data;

        public function __construct( $code, $message = '', $data = array() ) {
            $this->code = $code;
            $this->message = $message;
            $this->data = $data;
        }

        public function get_error_code() {
            return $this->code;
        }

        public function get_error_message() {
            return $this->message;
        }

        public function get_error_data() {
            return $this->data;
        }
    }
}

require_once dirname( __DIR__ ) . '/src/Domain/Task/TaskGraph.php';
require_once dirname( __DIR__ ) . '/src/Application/Task/TaskDescriptionService.php';
require_once dirname( __DIR__ ) . '/src/Application/Task/TaskInvariantService.php';
require_once dirname( __DIR__ ) . '/src/Infrastructure/Notifications/TaskBoardUrlResolver.php';
require_once dirname( __DIR__ ) . '/src/Infrastructure/Media/ProtectedAttachmentService.php';
require_once dirname( __DIR__ ) . '/src/Application/Task/TaskService.php';
require_once dirname( __DIR__ ) . '/src/Http/Rest/V1/Support/RequestHelper.php';
require_once dirname( __DIR__ ) . '/src/Application/Task/TaskMutationService.php';

use Pandatask\Application\Task\TaskInvariantService;
use Pandatask\Application\Task\TaskMutationService;
use Pandatask\Application\Task\TaskService;
use Pandatask\Http\Rest\V1\Support\RequestHelper;

final class DependencyContractTaskRepository {
    public $tasks;
    public $graph;

    public function __construct() {
        $this->tasks = array(
            1 => (object) array( 'id' => 1, 'board_name' => 'board_a', 'status' => 'pending', 'archived' => 0, 'project_id' => 1, 'parent_task_id' => null ),
            2 => (object) array( 'id' => 2, 'board_name' => 'board_b', 'status' => 'pending', 'archived' => 0, 'project_id' => 2, 'parent_task_id' => null ),
            3 => (object) array( 'id' => 3, 'board_name' => 'board_b', 'status' => 'pending', 'archived' => 0, 'project_id' => 2, 'parent_task_id' => null ),
        );
        $this->graph = array();
    }

    public function findTaskRecordsByIds( $task_ids ) {
        $records = array();
        foreach ( (array) $task_ids as $task_id ) {
            if ( isset( $this->tasks[ (int) $task_id ] ) ) {
                $records[ (int) $task_id ] = $this->tasks[ (int) $task_id ];
            }
        }
        return $records;
    }

    public function findDependencyGraph() {
        return $this->graph;
    }

    public function findIncompletePredecessorIds( $ids ) {
        unset( $ids );
        return array();
    }

    public function findById( $task_id ) {
        return $this->tasks[ (int) $task_id ] ?? null;
    }

    public function findAccessRecordsByIds( $task_ids ) {
        $records = array();
        foreach ( (array) $task_ids as $task_id ) {
            $task_id = (int) $task_id;
            $task = $this->tasks[ $task_id ] ?? null;
            $records[ $task_id ] = $task;
        }
        return $records;
    }

    public function findAccessRecordById( $task_id ) {
        return $this->tasks[ (int) $task_id ] ?? null;
    }
}

final class DependencyContractScopedRepository {
    public function existsOnBoard() {
        return true;
    }
}

final class DependencyContractBoardPolicy {
    public function isUserAllowedOnBoard() {
        return true;
    }
}

final class DependencyContractMediaPolicy {
    public function authorize() {
        return true;
    }
}

final class DependencyContractAccessPolicy {
    public $readable = array( 2 );
    public $calls = array();

    public function canReadTask( $task_id, $actor_id ) {
        $this->calls[] = array( (int) $task_id, (int) $actor_id );
        return in_array( (int) $task_id, $this->readable, true )
            ? true
            : new WP_Error( 'rest_forbidden', 'forbidden', array( 'status' => 403 ) );
    }
}

final class DependencyContractBoardService {
    public function getBoardDisplayName( $board_name ) {
        return strtoupper( $board_name );
    }
}

final class DependencyContractCommentService {
    public function getComments() {
        return array();
    }
}

final class DependencyContractInvariant {
    public function applyAndValidate( $data, $current_task = null ) {
        unset( $current_task );
        return $data;
    }
}

$failures = array();
$assert = static function ( $condition, $message ) use ( &$failures ) {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$task_repository = new DependencyContractTaskRepository();
$invariants = new TaskInvariantService(
    $task_repository,
    new DependencyContractScopedRepository(),
    new DependencyContractScopedRepository(),
    new DependencyContractBoardPolicy(),
    new DependencyContractMediaPolicy()
);
$current_task = (object) array(
    'id' => 1,
    'board_name' => 'board_a',
    'status' => 'pending',
    'task_type' => 'task',
    'project_id' => 1,
    'category_id' => null,
    'parent_task_id' => null,
    'follow_up_of_task_id' => null,
    'assigned_user_ids' => array(),
    'supervisor_user_ids' => array(),
    'predecessor_ids' => array(),
    'attachment_type' => '',
);

$accepted = $invariants->applyAndValidate( array( 'predecessors' => array( 2 ) ), $current_task );
$assert( ! is_wp_error( $accepted ) && array( 2 ) === $accepted['predecessors'], 'Cross-board predecessors must pass global structural validation.' );

$task_repository->graph = array( 2 => array( 1 ) );
$cycle = $invariants->applyAndValidate( array( 'predecessors' => array( 2 ) ), $current_task );
$assert( is_wp_error( $cycle ) && 'rest_dependency_cycle' === $cycle->get_error_code(), 'Global cross-board dependency cycles must be rejected.' );

$mutation_policy = new DependencyContractAccessPolicy();
$mutation_repository = new class( $current_task ) {
    private $task;
    public function __construct( $task ) { $this->task = $task; }
    public function findById( $task_id ) { return (int) $task_id === (int) $this->task->id ? $this->task : null; }
};
$mutation_service = new TaskMutationService(
    new stdClass(),
    $mutation_repository,
    new stdClass(),
    new DependencyContractInvariant(),
    new stdClass(),
    new stdClass(),
    new stdClass(),
    new stdClass(),
    new stdClass(),
    new stdClass(),
    $mutation_policy
);
$denied_create = $mutation_service->createTask( array( 'predecessors' => array( 3 ) ), array( 'actor_id' => 7 ) );
$assert( is_wp_error( $denied_create ) && 'rest_predecessor_forbidden' === $denied_create->get_error_code(), 'Unreadable explicit create predecessors must be denied.' );
$denied_update = $mutation_service->updateTask( 1, array( 'predecessors' => array( 3 ) ), '', 7 );
$assert( is_wp_error( $denied_update ) && 'rest_predecessor_forbidden' === $denied_update->get_error_code(), 'Unreadable explicit update predecessors must be denied.' );
$lock_context = 'Pandatask\Infrastructure\Persistence\DatabaseContext';
$lock_context::$lock_available = false;
$lock_acquired_before_failure = $lock_context::$lock_acquired;
$lock_released_before_failure = $lock_context::$lock_released;
$blocked_create = $mutation_service->createTask( array( 'predecessors' => array( 2 ) ), array( 'actor_id' => 7 ) );
$assert(
    is_wp_error( $blocked_create )
    && 'pandatask_dependency_graph_unavailable' === $blocked_create->get_error_code()
    && 503 === (int) ( $blocked_create->get_error_data()['status'] ?? 0 ),
    'Create must return a stable 503 when the dependency graph lock is unavailable.'
);
$assert( $lock_acquired_before_failure === $lock_context::$lock_acquired, 'A failed dependency lock must not report an acquisition.' );
$assert( $lock_released_before_failure === $lock_context::$lock_released, 'A failed dependency lock must not release an unacquired lock.' );
$blocked_update = $mutation_service->updateTask( 1, array( 'predecessors' => array( 2 ) ), '', 7 );
$assert(
    is_wp_error( $blocked_update )
    && 'pandatask_dependency_graph_unavailable' === $blocked_update->get_error_code()
    && 503 === (int) ( $blocked_update->get_error_data()['status'] ?? 0 ),
    'Update must return a stable 503 when the dependency graph lock is unavailable.'
);
$lock_context::$lock_available = true;
$assert( $lock_context::$lock_released === $lock_released_before_failure, 'Lock failure must not mutate release state.' );

$mutation_source = file_get_contents( dirname( __DIR__ ) . '/src/Application/Task/TaskMutationService.php' );
$create_source_start = strpos( $mutation_source, 'public function createTask' );
$update_source_start = strpos( $mutation_source, 'public function updateTask' );
$delete_source_start = strpos( $mutation_source, 'public function deleteTask' );
$create_lock_position = strpos( $mutation_source, 'DatabaseContext::acquireDependencyGraphLock()', $create_source_start );
$create_validation_position = strpos( $mutation_source, 'applyAndValidate( $data )', $create_source_start );
$update_lock_position = strpos( $mutation_source, 'DatabaseContext::acquireDependencyGraphLock()', $update_source_start );
$update_validation_position = strpos( $mutation_source, 'applyAndValidate( $data, $current_task )', $update_source_start );
$update_commit_position = strpos( $mutation_source, 'DatabaseContext::commit()', $update_source_start );
$update_release_position = strpos( $mutation_source, 'DatabaseContext::releaseDependencyGraphLock()', $update_source_start );
$delete_lock_position = strpos( $mutation_source, 'DatabaseContext::acquireDependencyGraphLock()', $delete_source_start );
$delete_relationship_position = strpos( $mutation_source, 'deleteTaskRelationships( $task_id )', $delete_source_start );
$delete_release_position = strpos( $mutation_source, 'DatabaseContext::releaseDependencyGraphLock()', $delete_source_start );
$assert( $create_lock_position < $create_validation_position, 'Create must acquire the graph lock before graph validation.' );
$assert( $update_lock_position < $update_validation_position, 'Update must acquire the graph lock before graph validation.' );
$assert( $update_commit_position < $update_release_position, 'Update must release the graph lock only after commit/rollback paths.' );
$assert( $delete_lock_position < $delete_relationship_position, 'Task deletion must acquire the graph lock before removing dependency rows.' );
$assert( $delete_relationship_position < $delete_release_position, 'Task deletion must release the graph lock only after relationship removal completes.' );
$database_context_source = file_get_contents( dirname( __DIR__ ) . '/src/Infrastructure/Persistence/DatabaseContext.php' );
$assert( false !== strpos( $database_context_source, 'GET_LOCK' ) && false !== strpos( $database_context_source, 'RELEASE_LOCK' ), 'DatabaseContext must use MySQL advisory lock functions.' );
$assert( false !== strpos( $database_context_source, '$wpdb->prepare' ), 'Advisory lock queries must use wpdb prepare.' );
$assert( false !== strpos( $database_context_source, "defined( 'DB_NAME' )" ), 'Advisory lock names must include the WordPress database identity.' );
$assert( false !== strpos( $database_context_source, "md5( \$database_name . ':' . self::getDbPrefix() )" ), 'Advisory lock names must derive deterministically from the database and Pandatask table prefix.' );
$preserve_hidden = new ReflectionMethod( TaskMutationService::class, 'unreadableExistingPredecessors' );
$preserve_hidden->setAccessible( true );
$preserved = $preserve_hidden->invoke( $mutation_service, array( 2, 3 ), array( 2 ), 7 );
$assert( array( 3 ) === $preserved, 'Unreadable existing predecessors omitted by a viewer must be preserved.' );

$decorated_repository = new DependencyContractTaskRepository();
$decorated_policy = new DependencyContractAccessPolicy();
$task_service = new TaskService(
    $decorated_repository,
    new DependencyContractBoardService(),
    new DependencyContractCommentService(),
    new stdClass(),
    new stdClass(),
    $decorated_policy
);
$canonical = (object) array(
    'id' => 1,
    'board_name' => 'board_a',
    'name' => 'Native task',
    'description' => '',
    'attachment_type' => '',
    'predecessors' => array(
        (object) array( 'id' => 2, 'name' => 'Readable predecessor', 'status' => 'pending' ),
        (object) array( 'id' => 3, 'name' => 'Secret predecessor', 'status' => 'pending', 'board_name' => 'board_b', 'project_id' => 2 ),
    ),
    'predecessor_ids' => array( 2, 3 ),
);
$decorate = new ReflectionMethod( TaskService::class, 'decorateTaskForViewer' );
$decorate->setAccessible( true );
$projected = $decorate->invoke( $task_service, $canonical );
$assert( array( 2 ) === $projected->predecessor_ids, 'Unreadable predecessor IDs must be removed from REST decoration.' );
$assert( 1 === $projected->restricted_predecessor_count, 'REST decoration must expose the restricted predecessor count.' );
$assert( 2 === (int) $projected->predecessors[0]->id && 'Readable predecessor' === $projected->predecessors[0]->name, 'Readable predecessor details must remain unchanged.' );
$assert( 1 === count( $projected->predecessors ), 'Unreadable predecessors must be omitted from REST details.' );
$assert( array( 2, 3 ) === $canonical->predecessor_ids, 'Canonical cached predecessor IDs must not be mutated by decoration.' );
$assert( in_array( 'restricted_predecessor_count', RequestHelper::taskCollectionFields(), true ), 'Task collection projections must allow restricted_predecessor_count.' );

$move_service = file_get_contents( dirname( __DIR__ ) . '/src/Application/Task/TaskMoveService.php' );
$assert( false === strpos( $move_service, "incompatibilities['predecessors']" ), 'Board moves must not treat cross-board predecessors as destination incompatibilities.' );
$assert( false !== strpos( $move_service, '$predecessors_explicit' ), 'Board moves must distinguish omitted predecessor state from an explicit replacement.' );

if ( ! empty( $failures ) ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

echo "Task dependency contract tests passed.\n";
