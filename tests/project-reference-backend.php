<?php
/**
 * Focused contract harness for project workspace/reference REST support.
 *
 * This is intentionally dependency-light so it can run in CI without a live
 * WordPress database: php tests/project-reference-backend.php
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
        }'
    );
}

if ( ! function_exists( 'absint' ) ) {
    function absint( $value ) {
        return abs( (int) $value );
    }
}
if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( $value ) {
        return strtolower( (string) $value );
    }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $value ) {
        return (string) $value;
    }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
    function get_current_user_id() {
        return 7;
    }
}
if ( ! function_exists( 'get_transient' ) ) {
    function get_transient( $key ) {
        return false === strpos( (string) $key, 'pandat69_board_url_' ) ? false : 'https://example.test/tasks';
    }
}
if ( ! function_exists( 'set_transient' ) ) {
    function set_transient( $key, $value, $expiration ) {}
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
if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = null ) {
        unset( $domain );
        return $text;
    }
}
if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( $value ) {
        return $value;
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

require_once dirname( __DIR__ ) . '/src/Infrastructure/Notifications/TaskBoardUrlResolver.php';
require_once dirname( __DIR__ ) . '/src/Application/Project/ProjectReferenceService.php';

final class ProjectReferenceHarnessRepository {
    public $associations = array();
    public $relationships = array();
    public $tasks;

    public function __construct() {
        $this->tasks = array(
            10 => (object) array( 'id' => 10, 'board_name' => 'board_a', 'project_id' => 1, 'name' => 'Native', 'status' => 'pending', 'start_date' => null, 'deadline' => null, 'priority' => 5, 'parent_task_id' => null, 'archived' => 0, 'project_name' => 'Alpha' ),
            20 => (object) array( 'id' => 20, 'board_name' => 'board_b', 'project_id' => 2, 'name' => 'External', 'status' => 'pending', 'start_date' => null, 'deadline' => null, 'priority' => 3, 'parent_task_id' => null, 'archived' => 0, 'project_name' => 'Beta' ),
        );
    }

    public function findProject( $project_id ) { return (object) array( 'id' => 1, 'board_name' => 'board_a', 'name' => 'Alpha' ); }
    public function findNativeTasks( $project_id ) { return array( $this->tasks[10] ); }
    public function findAssociations( $project_id ) { return $this->associations; }
    public function findWorkspaceRelationships( $project_id ) { return $this->relationships; }
    public function findTasksByIds( $ids ) {
        $rows = array();
        foreach ( array_unique( array_map( 'absint', (array) $ids ) ) as $id ) {
            if ( isset( $this->tasks[ $id ] ) ) {
                $rows[] = $this->tasks[ $id ];
            }
        }
        return $rows;
    }
    public function findTask( $task_id ) { return $this->tasks[ (int) $task_id ] ?? null; }
    public function findAssociationByTask( $project_id, $task_id ) {
        foreach ( $this->associations as $row ) {
            if ( (int) $row->task_id === (int) $task_id ) { return $row; }
        }
        return null;
    }
    public function createAssociation( $project_id, $task_id, $relation_type, $created_by ) {
        $id = count( $this->associations ) + 1;
        $this->associations[] = (object) array( 'id' => $id, 'task_id' => $task_id, 'relation_type' => $relation_type );
        return $id;
    }
    public function findAssociation( $project_id, $id ) {
        foreach ( $this->associations as $row ) { if ( (int) $row->id === (int) $id ) { return $row; } }
        return null;
    }
    public function updateAssociation( $project_id, $id, $relation_type ) {
        foreach ( $this->associations as $row ) { if ( (int) $row->id === (int) $id ) { $row->relation_type = $relation_type; return 1; } }
        return false;
    }
    public function deleteAssociation( $project_id, $id ) { return 1; }
    public function findRelationshipByEndpoints( $successor_id, $predecessor_id ) {
        foreach ( $this->relationships as $row ) { if ( (int) $row->task_id === (int) $successor_id && (int) $row->predecessor_id === (int) $predecessor_id ) { return $row; } }
        return null;
    }
    public function findRelationshipIdByEndpoints( $successor_id, $predecessor_id ) {
        $row = $this->findRelationshipByEndpoints( $successor_id, $predecessor_id );
        return $row ? (int) $row->id : 0;
    }
    public function findRelationship( $id ) { foreach ( $this->relationships as $row ) { if ( (int) $row->id === (int) $id ) { return $row; } } return null; }
    public function findPredecessorIds( $successor_id ) { $ids = array(); foreach ( $this->relationships as $row ) { if ( (int) $row->task_id === (int) $successor_id ) { $ids[] = (int) $row->predecessor_id; } } return $ids; }
}

final class ProjectReferenceHarnessTaskService {
    public $update_calls = 0;
    public function isTaskBlocked( $task_id ) { return 20 === (int) $task_id; }
    public function updateTask( $task_id, $data, $comment, $actor_id ) {
        unset( $task_id, $data, $comment, $actor_id );
        $this->update_calls++;
        return true;
    }
}

final class ProjectReferenceHarnessTaskPolicy {
    public $readable = true;
    public function canReadTask( $task_id, $actor_id ) {
        $readable = is_array( $this->readable )
            ? in_array( (int) $task_id, array_map( 'intval', $this->readable ), true )
            : (bool) $this->readable;
        return $readable ? true : new WP_Error( 'rest_forbidden', 'forbidden', array( 'status' => 403 ) );
    }
    public function canUpdateTask( $task_id, $actor_id ) { return true; }
}

final class ProjectReferenceHarnessBoardPolicy {
	public $last_read_actor = null;
	public function canReadBoard( $board_name, $actor_id ) { $this->last_read_actor = (int) $actor_id; return true; }
    public function canManageBoard( $board_name, $actor_id ) { return true; }
}

final class ProjectReferenceHarnessBoardService {
    public function getBoardDisplayName( $board_name ) { return strtoupper( $board_name ); }
}

final class ProjectReferenceHarnessProjectService {
    public function getProjectUncached( $project_id ) { return (object) array( 'id' => 1, 'board_name' => 'board_a', 'name' => 'Alpha' ); }
}

$repository = new ProjectReferenceHarnessRepository();
$task_service = new ProjectReferenceHarnessTaskService();
$task_policy = new ProjectReferenceHarnessTaskPolicy();
$board_policy = new ProjectReferenceHarnessBoardPolicy();
$service = new \Pandatask\Application\Project\ProjectReferenceService(
    $repository,
    $task_service,
    $task_policy,
	$board_policy,
    new ProjectReferenceHarnessBoardService(),
    new ProjectReferenceHarnessProjectService()
);
$failures = array();
$assert = static function ( $condition, $message ) use ( &$failures ) { if ( ! $condition ) { $failures[] = $message; } };

$repository->associations[] = (object) array( 'id' => 8, 'task_id' => 20, 'relation_type' => 'included' );
$repository->relationships[] = (object) array( 'id' => 9, 'task_id' => 10, 'predecessor_id' => 20, 'predecessor_status' => 'pending', 'predecessor_archived' => 0 );
$workspace = $service->getWorkspace( 1, 7 );
$assert( ! is_wp_error( $workspace ), 'Workspace should be readable.' );
$assert( 7 === $board_policy->last_read_actor, 'Workspace reads must authorize the supplied actor, not anonymous user zero.' );
$assert( 1 === $workspace['counts']['native'] && 0 === $workspace['counts']['restricted'], 'Native/external counts should be distinct.' );
$assert( 'task-20' === $workspace['tasks'][1]['workspace_key'], 'Visible external tasks should use canonical task keys.' );
$assert( in_array( 'included', $workspace['tasks'][1]['relation_types'], true ) && in_array( 'dependency', $workspace['tasks'][1]['relation_types'], true ), 'External relation types should merge.' );
$assert( true === $workspace['tasks'][1]['is_blocked'], 'Readable external nodes should use canonical dependency blocked state.' );
$assert( true === $workspace['tasks'][0]['is_blocked'], 'Cross-project dependency should block its successor.' );

$task_policy->readable = false;
$restricted = $service->getWorkspace( 1, 7 );
$restricted_nodes = array_values( array_filter( $restricted['tasks'], static function ( $task ) { return ! empty( $task['restricted'] ); } ) );
$assert( ! empty( $restricted_nodes ), 'Unreadable external references should produce placeholders.' );
$assert( null === $restricted_nodes[0]['task_id'] && 'Restricted external task' === $restricted_nodes[0]['name'], 'Restricted placeholders must not expose task identity.' );
$assert( ! array_key_exists( 'board_name', $restricted_nodes[0] ) && ! array_key_exists( 'project_id', $restricted_nodes[0] ), 'Restricted placeholders must omit board/project metadata.' );
$export = $service->exportReferences( 1, 7 );
$assert( 2 === (int) $export['omitted_restricted'], 'Restricted export entries should be omitted and counted.' );
$restricted_delete = $service->deleteReference( 1, 'dependency-9', 7 );
$assert( is_wp_error( $restricted_delete ) && 'rest_dependency_predecessor_forbidden' === $restricted_delete->get_error_code(), 'Restricted dependencies must not be silently reported as deleted.' );

$task_policy->readable = array( 20 );
$readable_ids = new ReflectionMethod( \Pandatask\Application\Project\ProjectReferenceService::class, 'readablePredecessorIds' );
$readable_ids->setAccessible( true );
$assert( array( 20 ) === $readable_ids->invoke( $service, array( 10, 20 ), 7 ), 'Dependency updates must forward only readable existing predecessor IDs.' );
$successful_dependency_delete = $service->deleteReference( 1, 'dependency-9', 7 );
$assert( true === $successful_dependency_delete, 'Dependency deletion should preserve the existing successful mutation behavior.' );

$lock_context = 'Pandatask\Infrastructure\Persistence\DatabaseContext';
$lock_context::$lock_available = false;
$update_calls_before_lock_failure = $task_service->update_calls;
$lock_failure = $service->createReference(
    1,
    array(
        'relation_type'       => 'dependency',
        'predecessor_task_id' => 20,
        'successor_task_id'   => 10,
    ),
    7
);
$assert(
    is_wp_error( $lock_failure )
    && 'pandatask_dependency_graph_unavailable' === $lock_failure->get_error_code()
    && 503 === (int) ( $lock_failure->get_error_data()['status'] ?? 0 ),
    'Dependency creation must return 503 when the graph lock is unavailable.'
);
$assert( $update_calls_before_lock_failure === $task_service->update_calls, 'Dependency lock failure must prevent the task mutation call.' );
$lock_failure = $service->deleteReference( 1, 'dependency-9', 7 );
$assert(
    is_wp_error( $lock_failure )
    && 'pandatask_dependency_graph_unavailable' === $lock_failure->get_error_code()
    && 503 === (int) ( $lock_failure->get_error_data()['status'] ?? 0 ),
    'Dependency deletion must return 503 when the graph lock is unavailable.'
);
$lock_context::$lock_available = true;
$assert( $lock_context::$lock_released > 0, 'Successful dependency lock acquisitions must be released.' );

$oversized_import = $service->importReferences(
	1,
	array(
		'version'    => 1,
		'references' => array_fill( 0, 501, array( 'relation_type' => 'included', 'task_id' => 20 ) ),
	),
	7
);
$assert( is_wp_error( $oversized_import ) && 'rest_import_too_large' === $oversized_import->get_error_code(), 'REST imports must reject more than 500 references before processing.' );

$schema = file_get_contents( dirname( __DIR__ ) . '/src/Infrastructure/Setup/DatabaseLifecycle.php' );
$assert( false !== strpos( $schema, "private const DB_VERSION = '1.0.23'" ), 'Database version should be 1.0.23.' );
$assert( false !== strpos( $schema, 'project_task_references' ), 'Project reference table should be part of the lifecycle.' );
$routes = file_get_contents( dirname( __DIR__ ) . '/src/Http/Rest/V1/ProjectReferenceRouteRegistrar.php' );
$assert( false !== strpos( $routes, '/projects/(?P<id>\\d+)/workspace' ), 'Workspace route should be registered.' );
$assert( false !== strpos( $routes, '(?P<reference_key>(?:reference|dependency)-\\d+)' ), 'Reference and dependency keys should be constrained at the route boundary.' );
$delete_code = file_get_contents( dirname( __DIR__ ) . '/src/Application/Task/TaskMutationService.php' );
$assert( false !== strpos( $delete_code, 'deleteTaskProjectReferences' ), 'Task deletion should clean project associations.' );
$assert( false !== strpos( $delete_code, 'deleteProjectTaskReference' ), 'Task project moves should clean redundant associations.' );

if ( ! empty( $failures ) ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

echo "Project reference backend tests passed.\n";
