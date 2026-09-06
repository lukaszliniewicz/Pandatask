<?php

/**
 * Focused checks for canonical task/project board URLs and service decoration.
 *
 * Run with: php tests/task-board-url.php
 */

namespace Pandatask\Integration\BuddyPress {
    final class BuddyPressSupport {
        public static function groupUrl( $group ) {
            return 'https://example.test/groups/' . $group->slug . '/';
        }
    }
}

namespace {
    if ( ! function_exists( '__' ) ) {
        function __( $text, $domain = null ) { return $text; }
    }
    if ( ! function_exists( 'sanitize_key' ) ) {
        function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_-]/', '', (string) $key ) ); }
    }
    if ( ! function_exists( 'sanitize_text_field' ) ) {
        function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
    }
    if ( ! function_exists( 'is_wp_error' ) ) {
        function is_wp_error( $value ) { return $value instanceof \WP_Error; }
    }
    if ( ! function_exists( 'get_transient' ) ) {
        function get_transient( $key ) { return $GLOBALS['pandatask_url_transients'][ $key ] ?? false; }
    }
    if ( ! function_exists( 'set_transient' ) ) {
        function set_transient( $key, $value, $expiration ) {
            $GLOBALS['pandatask_url_transients'][ $key ] = $value;
            return true;
        }
    }
    if ( ! function_exists( 'delete_transient' ) ) {
        function delete_transient( $key ) {
            unset( $GLOBALS['pandatask_url_transients'][ $key ] );
            return true;
        }
    }
    if ( ! function_exists( 'trailingslashit' ) ) {
        function trailingslashit( $value ) { return rtrim( (string) $value, '/' ) . '/'; }
    }
    if ( ! function_exists( 'add_query_arg' ) ) {
        function add_query_arg( $key, $value, $url ) {
            return (string) $url . ( false === strpos( (string) $url, '?' ) ? '?' : '&' ) . rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
        }
    }
    if ( ! function_exists( 'get_permalink' ) ) {
        function get_permalink( $post_id ) { return 'https://example.test/shortcode-board/' . (int) $post_id . '/'; }
    }
    if ( ! function_exists( 'groups_get_group' ) ) {
        function groups_get_group( $group_id ) { return (object) array( 'slug' => 'group-' . (int) $group_id ); }
    }
    if ( ! function_exists( 'bp_core_get_user_domain' ) ) {
        function bp_core_get_user_domain( $user_id ) { return 'https://example.test/members/user-' . (int) $user_id . '/'; }
    }
    if ( ! function_exists( 'get_current_user_id' ) ) {
        function get_current_user_id() { return 7; }
    }
    if ( ! function_exists( 'user_can' ) ) {
        function user_can( $user_id, $capability ) { return false; }
    }
    if ( ! function_exists( 'is_user_logged_in' ) ) {
        function is_user_logged_in() { return true; }
    }

    if ( ! defined( 'DAY_IN_SECONDS' ) ) {
        define( 'DAY_IN_SECONDS', 86400 );
    }
    if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
        define( 'HOUR_IN_SECONDS', 3600 );
    }

    final class TaskBoardUrlTestWpdb {
        public $prefix = 'wp_';
        public $posts = 'wp_posts';
        public $shortcode_post_id = 0;

        public function prepare( $query, ...$args ) {
            return $query;
        }

        public function esc_like( $value ) {
            return $value;
        }

        public function get_var( $query ) {
            return $this->shortcode_post_id;
        }
    }

    final class TaskBoardUrlTestBoardService {
        public function getBoardDisplayName( $board_name ) {
            return 'Board ' . $board_name;
        }
    }

    final class TaskBoardUrlTestCommentService {
        public function getComments( $task_id, $task ) {
            return array();
        }
    }

    final class TaskBoardUrlTestPolicy {
        public function canUpdateTask( $task_id, $viewer_id ) {
            return true;
        }
        public function canManageBoard( $board_name, $viewer_id ) {
            return true;
        }
    }

    final class TaskBoardUrlTestTaskRepository {
        public $task;
        public $tasks;

        public function findById( $task_id ) {
            return $this->task;
        }

        public function findForBoard( ...$arguments ) {
            return $this->tasks;
        }
    }

    final class TaskBoardUrlTestProjectRepository {
        public $projects = array();
        public $project;

        public function findForBoard( $board_name ) {
            return $this->projects;
        }

        public function findForUserWorkspace( $user_id, $board_names ) {
            return $this->projects;
        }

        public function findById( $project_id ) {
            return $this->project;
        }
    }

    $GLOBALS['pandatask_url_transients'] = array();
    $GLOBALS['wpdb'] = new TaskBoardUrlTestWpdb();
    $failures = array();
    $assert = static function ( $condition, $message ) use ( &$failures ) {
        if ( ! $condition ) {
            $failures[] = $message;
        }
    };

    require_once dirname( __DIR__ ) . '/src/Infrastructure/Notifications/TaskBoardUrlResolver.php';
    require_once dirname( __DIR__ ) . '/src/Infrastructure/Persistence/DatabaseContext.php';
    require_once dirname( __DIR__ ) . '/src/Infrastructure/Media/ProtectedAttachmentService.php';
    require_once dirname( __DIR__ ) . '/src/Domain/Task/TaskChecklist.php';
    require_once dirname( __DIR__ ) . '/src/Application/Task/TaskService.php';
    require_once dirname( __DIR__ ) . '/src/Application/Project/ProjectService.php';
    require_once dirname( __DIR__ ) . '/src/Http/Rest/V1/Support/RequestHelper.php';

    use Pandatask\Application\Project\ProjectService;
    use Pandatask\Application\Task\TaskService;
    use Pandatask\Http\Rest\V1\Support\RequestHelper;
    use Pandatask\Infrastructure\Notifications\TaskBoardUrlResolver;

    $group_task_url = TaskBoardUrlResolver::resolve( 'group_42', 7 );
    $assert( 'https://example.test/groups/group-42/tasks?open_task=7' === $group_task_url, 'Group task URLs must use the BuddyPress group task base.' );
    $assert( 'https://example.test/groups/group-42/tasks?pandatask_project=12' === TaskBoardUrlResolver::resolveProject( 'group_42', 12 ), 'Project URLs must append the canonical project query parameter.' );
    $assert( 'https://example.test/members/user-8/tasks?open_task=7' === TaskBoardUrlResolver::resolve( 'user_8', 7 ), 'Private task URLs must use the owner\'s BuddyPress task page.' );
    $assert( 'https://example.test/members/user-8/tasks?pandatask_project=12' === TaskBoardUrlResolver::resolveProject( 'user_8', 12 ), 'Private project URLs must use the owner\'s BuddyPress task page.' );
    $assert( false === TaskBoardUrlResolver::resolveProject( 'group_42', 0 ), 'Nonpositive project IDs must not produce links.' );
    $assert( false === TaskBoardUrlResolver::resolveProject( 'group_42', 'not-an-id' ), 'Invalid project IDs must not produce links.' );

    $GLOBALS['wpdb']->shortcode_post_id = 101;
    $shortcode_url = TaskBoardUrlResolver::resolve( 'standard' );
    $assert( 'https://example.test/shortcode-board/101/' === $shortcode_url, 'Non-group boards must retain shortcode URL fallback.' );
    $assert( 'https://example.test/shortcode-board/101/?open_task=7' === TaskBoardUrlResolver::resolve( 'standard', 7 ), 'Task links must preserve the resolved shortcode base.' );

    $GLOBALS['wpdb']->shortcode_post_id = 0;
    $assert( false === TaskBoardUrlResolver::resolve( 'unresolved', 7 ), 'Unresolved boards must return false for tasks.' );
    $assert( false === TaskBoardUrlResolver::resolveProject( 'unresolved', 12 ), 'Unresolved boards must return false for projects.' );

    $GLOBALS['wpdb']->shortcode_post_id = 101;
    $GLOBALS['pandatask_url_transients']['pandat69_board_url_standard'] = 'https://example.test/board/';
    $canonical_task = (object) array(
        'id'              => 7,
        'board_name'      => 'standard',
        'name'            => 'Cached task',
        'description'     => '',
        'attachment_type' => '',
        'checklist_json'  => '[{"id":"links","text":"Check links","checked":true},{"id":"send","text":"Send newsletter","checked":false}]',
        'checklist_version' => 3,
    );
    $task_repository = new TaskBoardUrlTestTaskRepository();
    $task_repository->task = $canonical_task;
    $task_repository->tasks = array( $canonical_task );
    $task_service = new TaskService(
        $task_repository,
        new TaskBoardUrlTestBoardService(),
        new TaskBoardUrlTestCommentService(),
        new \stdClass(),
        new \stdClass(),
        new TaskBoardUrlTestPolicy()
    );
    $decorated_task = $task_service->getTask( 7 );
    $assert( 'https://example.test/board/?open_task=7' === $decorated_task->frontend_url, 'Task detail decoration must expose the authoritative task URL.' );
    $assert( ! property_exists( $canonical_task, 'frontend_url' ), 'Task URL decoration must not mutate the canonical task object.' );
    $assert( 2 === count( $decorated_task->checklist ) && 'links' === $decorated_task->checklist[0]['id'], 'Task reads must decode stored checklist items before removing raw storage fields.' );
    $assert( 3 === $decorated_task->checklist_version && 2 === $decorated_task->checklist_total && 1 === $decorated_task->checklist_checked, 'Task detail reads must retain the saved checklist revision and counts.' );
    $assert( true === $decorated_task->can_edit_checklist && ! property_exists( $decorated_task, 'checklist_json' ), 'Task detail exposes checklist permission without leaking raw storage fields.' );
    $assert( property_exists( $canonical_task, 'checklist_json' ), 'Checklist decoration must not erase the canonical or cached storage value.' );
    $decorated_tasks = $task_service->getTasks( 'standard' );
    $assert( 'https://example.test/board/?open_task=7' === $decorated_tasks[0]->frontend_url, 'Task list decoration must expose the authoritative task URL.' );
    $assert( ! property_exists( $canonical_task, 'frontend_url' ), 'Task list URL decoration must not mutate the cached task object.' );
    $assert( $decorated_task->checklist === $decorated_tasks[0]->checklist && ! property_exists( $decorated_tasks[0], 'checklist_json' ), 'Task collections must expose the same saved checklist items as task details.' );

    $canonical_task->board_name = 'group_42';
    $moved_task = $task_service->getTask( 7 );
    $assert( 'https://example.test/groups/group-42/tasks?open_task=7' === $moved_task->frontend_url, 'Task links must follow the task\'s current board after a move.' );
    $canonical_task->board_name = 'standard';

    $canonical_project_task = (object) array( 'id' => 7, 'name' => 'Project task', 'status' => 'pending' );
    $canonical_project_task->checklist_json = $canonical_task->checklist_json;
    $canonical_project_task->checklist_version = 3;
    $canonical_project = (object) array(
        'id'         => 12,
        'board_name' => 'standard',
        'name'       => 'Cached project',
        'tasks'      => array( $canonical_project_task ),
    );
    $project_repository = new TaskBoardUrlTestProjectRepository();
    $project_repository->projects = array( $canonical_project );
    $project_repository->project = $canonical_project;
    $project_service = new ProjectService(
        $project_repository,
        new TaskBoardUrlTestBoardService(),
        new TaskBoardUrlTestPolicy(),
        new \stdClass()
    );
    $decorated_projects = $project_service->getProjects( 'standard' );
    $assert( 'https://example.test/board/?pandatask_project=12' === $decorated_projects[0]->frontend_url, 'Project list decoration must expose the authoritative project URL.' );
    $assert( 'https://example.test/board/?open_task=7' === $decorated_projects[0]->tasks[0]->frontend_url, 'Embedded project task summaries must expose task URLs.' );
    $assert( ! property_exists( $canonical_project, 'frontend_url' ) && ! property_exists( $canonical_project_task, 'frontend_url' ), 'Project list decoration must not mutate cached project or nested task objects.' );
    $assert( 2 === $decorated_projects[0]->tasks[0]->checklist_total && 1 === $decorated_projects[0]->tasks[0]->checklist_checked, 'Embedded project task rows must include correct checklist counts.' );
    $assert( ! property_exists( $decorated_projects[0]->tasks[0], 'checklist_json' ) && property_exists( $canonical_project_task, 'checklist_json' ), 'Project checklist decoration must remove raw storage only from response clones.' );

    $GLOBALS['pandatask_url_transients']['pandat69_project_12'] = $canonical_project;
    $decorated_project = $project_service->getProject( 12 );
    $assert( 'https://example.test/board/?pandatask_project=12' === $decorated_project->frontend_url, 'Project detail decoration must expose the authoritative project URL.' );
    $canonical_project->name = 'Renamed project';
    $renamed_project = $project_service->getProject( 12 );
    $assert( $decorated_project->frontend_url === $renamed_project->frontend_url, 'Project links must remain stable across project renames.' );
    $assert( ! property_exists( $canonical_project, 'frontend_url' ), 'Project detail URL decoration must not mutate the cached project object.' );
    $assert( in_array( 'frontend_url', RequestHelper::taskCollectionFields(), true ), 'REST task projections must allow frontend_url.' );

    if ( ! empty( $failures ) ) {
        fwrite( STDERR, implode( "\n", $failures ) . "\n" );
        exit( 1 );
    }

    echo "Task board URL checks passed.\n";
}
