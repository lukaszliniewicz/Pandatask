<?php
/**
 * Read-only server smoke test for a deployed Pandatask build.
 *
 * Run with: wp eval-file /path/to/server-smoke.php --path=/path/to/wordpress
 */

use Pandatask\Http\Rest\V1\Support\RequestHelper;
use Pandatask\Application\Project\ProjectService;
use Pandatask\Infrastructure\Persistence\DatabaseContext;
use Pandatask\Infrastructure\Persistence\TaskRepository;

if ( ! defined( 'ABSPATH' ) || ! class_exists( TaskRepository::class ) ) {
    WP_CLI::error( 'WordPress and Pandatask must be loaded.' );
}

global $wpdb;

if ( 0 === did_action( 'rest_api_init' ) ) {
    do_action( 'rest_api_init' );
}

$failures = array();
$result   = array(
    'plugin_version' => defined( 'PANDAT69_VERSION' ) ? PANDAT69_VERSION : null,
    'db_version'     => get_option( 'pandat69_db_version' ),
);

$non_admins = get_users(
    array(
        'role__not_in' => array( 'administrator' ),
        'number'       => 1,
    )
);

if ( empty( $non_admins ) ) {
    $failures[] = 'No non-administrator account was available for the batch authorization probe.';
} else {
    wp_set_current_user( (int) $non_admins[0]->ID );

    $batch_request = new WP_REST_Request( 'POST', '/pandatask/v1/batch' );
    $batch_request->set_body_params(
        array(
            'actions' => array(
                array( 'action' => 'unknown_noop' ),
            ),
        )
    );
    $batch_response       = rest_do_request( $batch_request );
    $result['batch_auth'] = array(
        'user_id' => (int) $non_admins[0]->ID,
        'roles'   => array_values( $non_admins[0]->roles ),
        'status'  => $batch_response->get_status(),
    );

    if ( 403 !== $batch_response->get_status() ) {
        $failures[] = 'The batch route did not reject a non-administrator with HTTP 403.';
    }
}

add_shortcode(
    'pandatask_smoke_shortcode',
    static function () {
        return 'SHORTCODE_EXECUTED';
    }
);

$rendered_task = RequestHelper::renderTask(
    (object) array(
        'description' => '<script>alert(1)</script>[pandatask_smoke_shortcode]',
    )
);
remove_shortcode( 'pandatask_smoke_shortcode' );

$result['content_rendering'] = array(
    'script_removed'     => false === stripos( $rendered_task->description_rendered, '<script' ),
    'shortcode_inert'    => false === strpos( $rendered_task->description_rendered, 'SHORTCODE_EXECUTED' ),
    'shortcode_preserved' => false !== strpos( $rendered_task->description_rendered, '[pandatask_smoke_shortcode]' ),
);

if ( ! $result['content_rendering']['script_removed'] || ! $result['content_rendering']['shortcode_inert'] ) {
    $failures[] = 'Task rendering did not preserve the no-script/no-shortcode invariant.';
}

$admins = get_users(
    array(
        'role'   => 'administrator',
        'number' => 1,
    )
);

if ( empty( $admins ) ) {
    $failures[] = 'No administrator account was available for the repository performance probe.';
} else {
    $admin_id = (int) $admins[0]->ID;
    wp_set_current_user( $admin_id );

    $tasks_table = DatabaseContext::getDbPrefix() . 'tasks';
    $board_name  = $wpdb->get_var(
        "SELECT board_name FROM {$tasks_table} WHERE archived = 0 GROUP BY board_name ORDER BY COUNT(*) DESC LIMIT 1"
    );

    if ( $board_name ) {
        $query_start = $wpdb->num_queries;
        $time_start  = microtime( true );
        $tasks       = ( new TaskRepository() )->findForBoard( $board_name );

        $result['largest_board'] = array(
            'board'   => $board_name,
            'tasks'   => count( $tasks ),
            'queries' => $wpdb->num_queries - $query_start,
            'ms'      => round( ( microtime( true ) - $time_start ) * 1000, 1 ),
        );

        $tasks_request  = new WP_REST_Request( 'GET', '/pandatask/v1/boards/' . rawurlencode( $board_name ) . '/tasks' );
        $tasks_response = rest_do_request( $tasks_request );
        $tasks_data     = $tasks_response->get_data();

        $result['task_rest'] = array(
            'status' => $tasks_response->get_status(),
            'tasks'  => is_array( $tasks_data ) && isset( $tasks_data['tasks'] ) && is_array( $tasks_data['tasks'] )
                ? count( $tasks_data['tasks'] )
                : null,
        );

        if ( 200 !== $tasks_response->get_status() || null === $result['task_rest']['tasks'] ) {
            $failures[] = 'The largest board task REST request did not return a task collection.';
        }

        $page_request = new WP_REST_Request( 'GET', '/pandatask/v1/boards/' . rawurlencode( $board_name ) . '/tasks' );
        $page_request->set_query_params( array( 'limit' => 1, 'offset' => 0, 'status_filter' => '' ) );
        $page_response = rest_do_request( $page_request );
        $page_data = $page_response->get_data();
        $result['pagination_probe'] = is_array( $page_data ) ? ( $page_data['pagination'] ?? null ) : null;

        if (
            200 !== $page_response->get_status()
            || ! is_array( $result['pagination_probe'] )
            || 1 !== (int) ( $result['pagination_probe']['returned'] ?? 0 )
            || ( (int) $result['largest_board']['tasks'] > 1 && empty( $result['pagination_probe']['has_more'] ) )
        ) {
            $failures[] = 'Bounded task pagination did not expose an exact look-ahead result.';
        }
    }

    $project_query_start = $wpdb->num_queries;
    $project_time_start  = microtime( true );
    $workspace_projects  = ( new ProjectService() )->getProjects( 'user_' . $admin_id );
    $workspace_ids       = array_map( 'intval', wp_list_pluck( $workspace_projects, 'id' ) );

    $result['personal_workspace_projects'] = array(
        'user_id'       => $admin_id,
        'projects'      => count( $workspace_projects ),
        'group_projects' => count(
            array_filter(
                $workspace_projects,
                static function ( $project ) {
                    return 'group' === ( $project->board_scope ?? '' );
                }
            )
        ),
        'queries'       => $wpdb->num_queries - $project_query_start,
        'ms'            => round( ( microtime( true ) - $project_time_start ) * 1000, 1 ),
        'duplicate_ids' => count( $workspace_ids ) - count( array_unique( $workspace_ids ) ),
    );

    if ( $result['personal_workspace_projects']['duplicate_ids'] > 0 ) {
        $failures[] = 'The personal workspace returned a project more than once.';
    }
}

$tasks_table = DatabaseContext::getDbPrefix() . 'tasks';
$result['hierarchy_invalid_links'] = (int) $wpdb->get_var(
    "SELECT COUNT(*)
     FROM {$tasks_table} child
     LEFT JOIN {$tasks_table} parent ON parent.id = child.parent_task_id
     WHERE child.parent_task_id IS NOT NULL
     AND (
         parent.id IS NULL
         OR child.board_name <> parent.board_name
         OR NOT (child.project_id <=> parent.project_id)
     )"
);

if ( $result['hierarchy_invalid_links'] > 0 ) {
    $failures[] = 'An orphaned, cross-board, or project-mismatched subtask remains after the schema repair.';
}

$index_requirements = array(
    'tasks'        => array( 'board_list_deadline', 'board_created', 'board_completed', 'scheduled_start', 'deadline_reminder_queue', 'missed_deadline_queue', 'recurring_rollover', 'project_active_tasks' ),
    'assignments'  => array( 'user_task_role' ),
    'comments'     => array( 'task_created_id' ),
    'task_history' => array( 'task_field', 'task_changed' ),
    'task_change_buffers' => array( 'task_actor_delivery', 'delivery_queue' ),
);
$result['indexes'] = array();

foreach ( $index_requirements as $table_suffix => $required_indexes ) {
    $table_name      = DatabaseContext::getDbPrefix() . $table_suffix;
    $present_indexes = array_unique( $wpdb->get_col( "SHOW INDEX FROM {$table_name}", 2 ) );
    $missing_indexes = array_values( array_diff( $required_indexes, $present_indexes ) );

    $result['indexes'][ $table_suffix ] = array(
        'present' => empty( $missing_indexes ),
        'missing' => $missing_indexes,
    );

    if ( ! empty( $missing_indexes ) ) {
        $failures[] = "Missing indexes on {$table_suffix}: " . implode( ', ', $missing_indexes );
    }
}

$plugin_dir      = untrailingslashit( PANDAT69_PLUGIN_DIR );
$required_assets = array(
    'build/main.js',
    'build/156.js',
    'build/725.js',
    'build/226.js',
    'build/638.js',
    'build/651.js',
    'build/785.js',
    'build/940.js',
    'assets/css/floating-bug-reporter.css',
    'assets/js/floating-bug-reporter.js',
);
$missing_assets  = array_values(
    array_filter(
        $required_assets,
        static function ( $relative_path ) use ( $plugin_dir ) {
            return ! is_file( $plugin_dir . '/' . $relative_path );
        }
    )
);

$result['packaging'] = array(
    'present' => empty( $missing_assets ),
    'missing' => $missing_assets,
);

if ( ! empty( $missing_assets ) ) {
    $failures[] = 'Missing packaged assets: ' . implode( ', ', $missing_assets );
}

$task_columns = wp_list_pluck( $wpdb->get_results( "SHOW COLUMNS FROM {$tasks_table}" ), 'Field' );
$result['schema_columns'] = array(
    'deadline_reminder_sent_for' => in_array( 'deadline_reminder_sent_for', $task_columns, true ),
    'recurrence_anchor_day'      => in_array( 'recurrence_anchor_day', $task_columns, true ),
);

if ( in_array( false, $result['schema_columns'], true ) ) {
    $failures[] = 'A required task schema column is missing.';
}

if ( '1.0.19' !== $result['plugin_version'] || '1.0.14' !== $result['db_version'] ) {
    $failures[] = 'Expected plugin version 1.0.19 with database schema 1.0.14.';
}

WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

if ( ! empty( $failures ) ) {
    WP_CLI::error( implode( ' ', $failures ) );
}

WP_CLI::success( 'Pandatask server smoke test passed.' );
