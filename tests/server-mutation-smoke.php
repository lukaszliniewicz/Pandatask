<?php
/**
 * Controlled REST mutation smoke test for a dev Pandatask installation.
 *
 * The script creates data on a unique temporary board and always removes it.
 * Run with: wp eval-file /path/to/server-mutation-smoke.php --path=/path/to/wordpress
 */

use Pandatask\Infrastructure\Persistence\DatabaseContext;
use Pandatask\Application\Task\TaskMutationService;

if ( ! defined( 'ABSPATH' ) || ! defined( 'PANDAT69_VERSION' ) ) {
    WP_CLI::error( 'WordPress and Pandatask must be loaded.' );
}

if ( 0 === did_action( 'rest_api_init' ) ) {
    do_action( 'rest_api_init' );
}

$admins = get_users(
    array(
        'role'   => 'administrator',
        'number' => 1,
    )
);

if ( empty( $admins ) ) {
    WP_CLI::error( 'No administrator account is available.' );
}

global $wpdb;

$admin_id  = (int) $admins[0]->ID;
$board     = 'audit_smoke_' . gmdate( 'YmdHis' ) . '_' . wp_rand( 1000, 9999 );
$prefix    = DatabaseContext::getDbPrefix();
$responses = array();
$failure   = null;
$group_id  = 0;
$group_board = '';

wp_set_current_user( $admin_id );

$dispatch = static function ( $method, $path, $body = array() ) {
    $request = new WP_REST_Request( $method, $path );

    if ( ! empty( $body ) ) {
        if ( 'GET' === strtoupper( $method ) ) {
            $request->set_query_params( $body );
        } else {
            $request->set_body_params( $body );
        }
    }

    return rest_do_request( $request );
};

$expect_status = static function ( $response, $expected_status, $label ) use ( &$responses ) {
    $status              = $response->get_status();
    $responses[ $label ] = $status;

    if ( $expected_status !== $status ) {
        throw new RuntimeException(
            sprintf(
                '%s returned HTTP %d instead of %d: %s',
                $label,
                $status,
                $expected_status,
                wp_json_encode( $response->get_data() )
            )
        );
    }

    return $response->get_data();
};

try {
    $category_data = $expect_status(
        $dispatch(
            'POST',
            '/pandatask/v1/boards/' . $board . '/categories',
            array( 'name' => 'Audit category' )
        ),
        201,
        'create_category'
    );
    $category_id   = (int) $category_data['category']['id'];

    $direct_invalid_create = ( new TaskMutationService() )->createTask(
        array(
            'board_name'        => $board . '_other',
            'name'              => 'Must not be created',
            'description'       => '',
            'status'            => 'pending',
            'priority'          => 5,
            'task_type'         => 'task',
            'category_id'       => $category_id,
            'assigned_persons'  => array(),
            'supervisor_persons' => array(),
            'predecessors'      => array(),
        )
    );

    if ( ! is_wp_error( $direct_invalid_create ) || 422 !== (int) ( $direct_invalid_create->get_error_data()['status'] ?? 0 ) ) {
        throw new RuntimeException( 'The application service allowed a cross-board category outside REST.' );
    }

    $direct_boolean_task_id = ( new TaskMutationService() )->createTask(
        array(
            'board_name'   => $board,
            'name'         => 'Direct boolean normalization audit',
            'is_recurring' => 'false',
        )
    );

    if (
        ! is_int( $direct_boolean_task_id )
        || $direct_boolean_task_id <= 0
        || 0 !== (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT is_recurring FROM {$prefix}tasks WHERE id = %d",
                $direct_boolean_task_id
            )
        )
    ) {
        throw new RuntimeException( 'The application service treated the string "false" as an enabled boolean.' );
    }

    if ( true !== ( new TaskMutationService() )->deleteTask( $direct_boolean_task_id ) ) {
        throw new RuntimeException( 'The direct boolean-normalization probe could not be removed.' );
    }

    $project_data = $expect_status(
        $dispatch(
            'POST',
            '/pandatask/v1/boards/' . $board . '/projects',
            array(
                'name'               => 'Audit project',
                'assigned_persons'   => array( $admin_id ),
                'supervisor_persons' => array( $admin_id ),
            )
        ),
        201,
        'create_project'
    );
    $project_id   = (int) $project_data['project']->id;

    $project_update_data = $expect_status(
        $dispatch(
            'PATCH',
            '/pandatask/v1/projects/' . $project_id,
            array( 'assigned_persons' => array() )
        ),
        200,
        'partial_update_project'
    );

    if ( ! in_array( $admin_id, array_map( 'intval', $project_update_data['project']->supervisor_user_ids ), true ) ) {
        throw new RuntimeException( 'A partial project assignment update removed the omitted supervisor role.' );
    }

    $second_project_data = $expect_status(
        $dispatch(
            'POST',
            '/pandatask/v1/boards/' . $board . '/projects',
            array( 'name' => 'Audit destination project' )
        ),
        201,
        'create_second_project'
    );
    $second_project_id   = (int) $second_project_data['project']->id;

    $root_data = $expect_status(
        $dispatch(
            'POST',
            '/pandatask/v1/boards/' . $board . '/tasks',
            array(
                'name'               => 'Audit root task',
                'category_id'        => $category_id,
                'project_id'         => $project_id,
                'assigned_persons'   => array( $admin_id ),
                'supervisor_persons' => array( $admin_id ),
                'notify_deadline'    => true,
                'notify_days_before' => 2,
            )
        ),
        201,
        'create_root_task'
    );
    $root_id   = (int) $root_data['task']->id;

    $child_data = $expect_status(
        $dispatch(
            'POST',
            '/pandatask/v1/boards/' . $board . '/tasks',
            array(
                'name'               => 'Audit child task',
                'parent_task_id'     => $root_id,
                'predecessors'       => array( $root_id ),
                'category_id'        => $category_id,
                // Deliberately conflict with the parent. The server must ignore this.
                'project_id'         => $second_project_id,
                'assigned_persons'   => array( $admin_id ),
                'supervisor_persons' => array( $admin_id ),
                'notify_deadline'    => true,
                'notify_days_before' => 4,
            )
        ),
        201,
        'create_child_task'
    );
    $child_id   = (int) $child_data['task']->id;

    if ( $project_id !== (int) $child_data['task']->project_id ) {
        throw new RuntimeException( 'A new subtask did not inherit its parent project.' );
    }

    $grandchild_data = $expect_status(
        $dispatch(
            'POST',
            '/pandatask/v1/boards/' . $board . '/tasks',
            array(
                'name'           => 'Audit grandchild task',
                'parent_task_id' => $child_id,
                'project_id'     => $second_project_id,
            )
        ),
        201,
        'create_grandchild_task'
    );
    $grandchild_id   = (int) $grandchild_data['task']->id;

    if ( $project_id !== (int) $grandchild_data['task']->project_id ) {
        throw new RuntimeException( 'A nested subtask did not inherit its parent project.' );
    }

    $destination_task_data = $expect_status(
        $dispatch(
            'POST',
            '/pandatask/v1/boards/' . $board . '/tasks',
            array(
                'name'       => 'Audit destination task',
                'project_id' => $second_project_id,
            )
        ),
        201,
        'create_destination_task'
    );
    $destination_task_id   = (int) $destination_task_data['task']->id;

    $project_sort_data = $expect_status(
        $dispatch(
            'GET',
            '/pandatask/v1/boards/' . $board . '/tasks',
            array(
                'sort'          => 'project_name_asc',
                'status_filter' => '',
            )
        ),
        200,
        'sort_tasks_by_project'
    );
    $sorted_project_names = array_values(
        array_unique(
            array_map(
                static function ( $task ) {
                    return (string) ( $task->project_name ?? '' );
                },
                $project_sort_data['tasks'] ?? array()
            )
        )
    );

    if ( array( 'Audit destination project', 'Audit project' ) !== $sorted_project_names ) {
        throw new RuntimeException( 'Project-name task sorting did not return deterministic project groups.' );
    }

    $page_data = $expect_status(
        $dispatch(
            'GET',
            '/pandatask/v1/boards/' . $board . '/tasks',
            array(
                'limit'         => 1,
                'offset'        => 0,
                'status_filter' => '',
            )
        ),
        200,
        'exact_pagination'
    );

    if (
        1 !== count( $page_data['tasks'] ?? array() )
        || empty( $page_data['pagination']['has_more'] )
        || 1 !== (int) ( $page_data['pagination']['next_offset'] ?? 0 )
    ) {
        throw new RuntimeException( 'Task pagination did not use an exact look-ahead row.' );
    }

    $terminal_page_data = $expect_status(
        $dispatch(
            'GET',
            '/pandatask/v1/boards/' . $board . '/tasks',
            array(
                'limit'         => 1,
                'offset'        => 3,
                'status_filter' => '',
            )
        ),
        200,
        'exact_terminal_page'
    );

    if (
        1 !== count( $terminal_page_data['tasks'] ?? array() )
        || ! empty( $terminal_page_data['pagination']['has_more'] )
        || null !== ( $terminal_page_data['pagination']['next_offset'] ?? null )
    ) {
        throw new RuntimeException( 'An exactly exhausted task page reported a false-positive continuation.' );
    }

    $expect_status(
        $dispatch(
            'PATCH',
            '/pandatask/v1/tasks/' . $root_id,
            array( 'project_id' => $second_project_id )
        ),
        200,
        'cascade_parent_project'
    );

    $descendant_project_ids = array_map(
        'intval',
        $wpdb->get_col(
            "SELECT project_id
             FROM {$prefix}tasks
             WHERE id IN ({$child_id}, {$grandchild_id})
             ORDER BY id ASC"
        )
    );

    if ( array( $second_project_id, $second_project_id ) !== $descendant_project_ids ) {
        throw new RuntimeException( 'Changing a parent project did not cascade to every descendant.' );
    }

    $expect_status(
        $dispatch(
            'PATCH',
            '/pandatask/v1/tasks/' . $root_id,
            array(
                'board_name'  => $board . '_moved',
                'category_id' => 0,
                'project_id'  => 0,
            )
        ),
        409,
        'reject_parent_board_move'
    );

    $expect_status(
        $dispatch(
            'PATCH',
            '/pandatask/v1/tasks/' . $child_id,
            array( 'status' => 'invalid-status' )
        ),
        400,
        'reject_invalid_status'
    );

    $expect_status(
        $dispatch(
            'PATCH',
            '/pandatask/v1/tasks/' . $child_id,
            array( 'status' => 'done' )
        ),
        409,
        'reject_blocked_completion'
    );

    $expect_status(
        $dispatch(
            'PATCH',
            '/pandatask/v1/tasks/' . $root_id,
            array( 'parent_task_id' => $child_id )
        ),
        409,
        'reject_hierarchy_cycle'
    );

    $task_update_data = $expect_status(
        $dispatch(
            'PATCH',
            '/pandatask/v1/tasks/' . $child_id,
            array(
                'name'             => 'Audit child task updated',
                'description'      => '<strong>Allowed</strong><script>alert(1)</script>',
                'assigned_persons' => array(),
            )
        ),
        200,
        'partial_update_task'
    );
    $updated_task     = $task_update_data['task'];

    if ( ! in_array( (string) $admin_id, $updated_task->supervisor_user_ids, true ) ) {
        throw new RuntimeException( 'A partial task assignment update removed the omitted supervisor role.' );
    }

    if ( 1 !== (int) $updated_task->notify_deadline || 4 !== (int) $updated_task->notify_days_before ) {
        throw new RuntimeException( 'A partial task update discarded deadline-notification settings.' );
    }

    if ( false !== stripos( $updated_task->description, '<script' ) || false === stripos( $updated_task->description, '<strong>Allowed</strong>' ) ) {
        throw new RuntimeException( 'Task update sanitization did not preserve allowed markup and remove scripts.' );
    }

    $expect_status(
        $dispatch(
            'PATCH',
            '/pandatask/v1/tasks/' . $child_id,
            array( 'name' => 'Audit child task final' )
        ),
        200,
        'second_buffered_task_update'
    );
    $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$prefix}task_change_buffers
             SET deliver_after = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND)
             WHERE task_id = %d AND actor_id = %d",
            $child_id,
            $admin_id
        )
    );

    if ( ! ( new TaskMutationService() )->processBufferedChanges( $child_id, $admin_id ) ) {
        throw new RuntimeException( 'The durable task-history buffer could not be processed.' );
    }

    $buffered_history = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT new_value
             FROM {$prefix}task_history
             WHERE task_id = %d AND field_changed = 'task_updated_multiple'
             ORDER BY id DESC
             LIMIT 1",
            $child_id
        )
    );
    $buffered_changes = json_decode( (string) $buffered_history, true );

    if (
        ! is_array( $buffered_changes )
        || 'Audit child task' !== ( $buffered_changes['name']['from'] ?? null )
        || 'Audit child task final' !== ( $buffered_changes['name']['to'] ?? null )
    ) {
        throw new RuntimeException( 'Buffered history did not preserve the first old and final new task name.' );
    }

    $expect_status(
        $dispatch(
            'PATCH',
            '/pandatask/v1/tasks/' . $child_id,
            array( 'start_date' => '2099-01-01' )
        ),
        200,
        'schedule_future_successor'
    );
    $expect_status(
        $dispatch(
            'PATCH',
            '/pandatask/v1/tasks/' . $root_id,
            array( 'status' => 'done' )
        ),
        200,
        'complete_predecessor'
    );
    $future_successor_status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$prefix}tasks WHERE id = %d", $child_id ) );

    if ( 'pending' !== $future_successor_status ) {
        throw new RuntimeException( 'Completing a predecessor started a future-dated successor early.' );
    }

    $expect_status(
        $dispatch(
            'POST',
            '/pandatask/v1/boards/' . $board . '/tasks',
            array(
                'name'                 => 'Invalid recurring task',
                'is_recurring'         => true,
                'recurrence_frequency' => 'weekly',
            )
        ),
        422,
        'reject_recurring_without_dates'
    );
    $recurring_data = $expect_status(
        $dispatch(
            'POST',
            '/pandatask/v1/boards/' . $board . '/tasks',
            array(
                'name'                 => 'Monthly anchor audit',
                'start_date'           => '2099-01-31',
                'deadline'             => '2099-02-02',
                'is_recurring'         => true,
                'recurrence_frequency' => 'monthly',
            )
        ),
        201,
        'create_monthly_recurring_task'
    );
    $recurring_id = (int) $recurring_data['task']->id;
    $recurrence_anchor = (int) $wpdb->get_var( $wpdb->prepare( "SELECT recurrence_anchor_day FROM {$prefix}tasks WHERE id = %d", $recurring_id ) );

    if ( 31 !== $recurrence_anchor ) {
        throw new RuntimeException( 'Monthly recurrence did not persist its original day-of-month anchor.' );
    }

    $invalid_delete = ( new TaskMutationService() )->deleteTask( $recurring_id, 'unknown-scope' );

    if ( ! is_wp_error( $invalid_delete ) || 422 !== (int) ( $invalid_delete->get_error_data()['status'] ?? 0 ) ) {
        throw new RuntimeException( 'The application service accepted an unknown recurring deletion scope.' );
    }

    $expect_status( $dispatch( 'DELETE', '/pandatask/v1/tasks/' . $recurring_id, array( 'delete_scope' => 'all' ) ), 200, 'delete_recurring_series' );

    $comment_data = $expect_status(
        $dispatch(
            'POST',
            '/pandatask/v1/tasks/' . $child_id . '/comments',
            array( 'comment_text' => '<em>Audit comment</em><script>alert(1)</script>' )
        ),
        201,
        'create_comment'
    );
    $comment_id   = (int) $comment_data['comment']->id;

    if ( false !== stripos( $comment_data['comment']->comment_text, '<script' ) ) {
        throw new RuntimeException( 'Comment creation retained a script element.' );
    }

    $expect_status(
        $dispatch(
            'PATCH',
            '/pandatask/v1/comments/' . $comment_id,
            array( 'comment_text' => '<strong>Updated comment</strong>' )
        ),
        200,
        'update_comment'
    );
    $expect_status( $dispatch( 'DELETE', '/pandatask/v1/comments/' . $comment_id ), 200, 'delete_comment' );
    $expect_status( $dispatch( 'DELETE', '/pandatask/v1/tasks/' . $destination_task_id ), 200, 'delete_destination_task' );
    $expect_status( $dispatch( 'DELETE', '/pandatask/v1/tasks/' . $grandchild_id ), 200, 'delete_grandchild_task' );
    $expect_status( $dispatch( 'DELETE', '/pandatask/v1/tasks/' . $child_id ), 200, 'delete_child_task' );
    $expect_status( $dispatch( 'DELETE', '/pandatask/v1/tasks/' . $root_id ), 200, 'delete_root_task' );
    $expect_status( $dispatch( 'DELETE', '/pandatask/v1/projects/' . $project_id ), 200, 'delete_project' );
    $expect_status( $dispatch( 'DELETE', '/pandatask/v1/projects/' . $second_project_id ), 200, 'delete_second_project' );
    $expect_status(
        $dispatch(
            'DELETE',
            '/pandatask/v1/categories/' . $category_id,
            array( 'board_name' => $board )
        ),
        200,
        'delete_category'
    );

    if ( function_exists( 'groups_create_group' ) && function_exists( 'groups_delete_group' ) ) {
        $group_id = (int) groups_create_group(
            array(
                'creator_id' => $admin_id,
                'name'       => 'Pandatask audit ' . wp_rand( 1000, 9999 ),
                'slug'       => 'pandatask-audit-' . gmdate( 'YmdHis' ) . '-' . wp_rand( 1000, 9999 ),
                'status'     => 'private',
            )
        );

        if ( $group_id <= 0 ) {
            throw new RuntimeException( 'Could not create the temporary BuddyPress group.' );
        }

        $group_board = 'group_' . $group_id;
        groups_update_groupmeta( $group_id, 'pandat69_tasks_enabled', '1' );
        delete_transient( 'pandat69_writable_boards_v2_' . $admin_id );

        $group_project_data = $expect_status(
            $dispatch(
                'POST',
                '/pandatask/v1/boards/' . $group_board . '/projects',
                array( 'name' => 'Profile-visible group project' )
            ),
            201,
            'create_group_project'
        );
        $group_project_id   = (int) $group_project_data['project']->id;

        $group_task_data = $expect_status(
            $dispatch(
                'POST',
                '/pandatask/v1/boards/' . $group_board . '/tasks',
                array(
                    'name'             => 'Profile-visible group task',
                    'project_id'       => $group_project_id,
                    'assigned_persons' => array( $admin_id ),
                )
            ),
            201,
            'create_group_task'
        );
        $group_task_id   = (int) $group_task_data['task']->id;

        $workspace_projects = $expect_status(
            $dispatch( 'GET', '/pandatask/v1/boards/user_' . $admin_id . '/projects' ),
            200,
            'get_workspace_projects'
        );
        $workspace_project_ids = array_map( 'intval', wp_list_pluck( $workspace_projects['projects'], 'id' ) );

        if ( ! in_array( $group_project_id, $workspace_project_ids, true ) ) {
            throw new RuntimeException( 'The personal workspace omitted a project from an enabled user group.' );
        }

        $private_projects = $expect_status(
            $dispatch(
                'GET',
                '/pandatask/v1/boards/user_' . $admin_id . '/projects',
                array( 'private_only' => true )
            ),
            200,
            'get_private_workspace_projects'
        );
        $private_project_ids = array_map( 'intval', wp_list_pluck( $private_projects['projects'], 'id' ) );

        if ( in_array( $group_project_id, $private_project_ids, true ) ) {
            throw new RuntimeException( 'The private-only project filter retained a group project.' );
        }

        $expect_status( $dispatch( 'DELETE', '/pandatask/v1/tasks/' . $group_task_id ), 200, 'delete_group_task' );
        $expect_status( $dispatch( 'DELETE', '/pandatask/v1/projects/' . $group_project_id ), 200, 'delete_group_project' );
        groups_delete_group( $group_id );
        delete_transient( 'pandat69_writable_boards_v2_' . $admin_id );
        $group_id = 0;
    }
} catch ( Throwable $throwable ) {
    $failure = $throwable->getMessage();
} finally {
    $task_ids = array_map(
        'intval',
        $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$prefix}tasks WHERE board_name = %s",
                $board
            )
        )
    );

    foreach ( $task_ids as $task_id ) {
        $wpdb->delete( $prefix . 'assignments', array( 'task_id' => $task_id ), array( '%d' ) );
        $wpdb->delete( $prefix . 'comments', array( 'task_id' => $task_id ), array( '%d' ) );
        $wpdb->delete( $prefix . 'task_history', array( 'task_id' => $task_id ), array( '%d' ) );
        $wpdb->delete( $prefix . 'task_change_buffers', array( 'task_id' => $task_id ), array( '%d' ) );
        $wpdb->delete( $prefix . 'task_relationships', array( 'task_id' => $task_id ), array( '%d' ) );
        $wpdb->delete( $prefix . 'task_relationships', array( 'predecessor_id' => $task_id ), array( '%d' ) );
    }

    $project_ids = array_map(
        'intval',
        $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$prefix}projects WHERE board_name = %s",
                $board
            )
        )
    );

    foreach ( $project_ids as $project_id ) {
        $wpdb->delete( $prefix . 'project_assignments', array( 'project_id' => $project_id ), array( '%d' ) );
    }

    $wpdb->delete( $prefix . 'tasks', array( 'board_name' => $board ), array( '%s' ) );
    $wpdb->delete( $prefix . 'projects', array( 'board_name' => $board ), array( '%s' ) );
    $wpdb->delete( $prefix . 'categories', array( 'board_name' => $board ), array( '%s' ) );

    if ( '' !== $group_board ) {
        $group_task_ids = array_map(
            'intval',
            $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT id FROM {$prefix}tasks WHERE board_name = %s",
                    $group_board
                )
            )
        );

        foreach ( $group_task_ids as $group_task_id ) {
            $wpdb->delete( $prefix . 'assignments', array( 'task_id' => $group_task_id ), array( '%d' ) );
            $wpdb->delete( $prefix . 'comments', array( 'task_id' => $group_task_id ), array( '%d' ) );
            $wpdb->delete( $prefix . 'task_history', array( 'task_id' => $group_task_id ), array( '%d' ) );
            $wpdb->delete( $prefix . 'task_change_buffers', array( 'task_id' => $group_task_id ), array( '%d' ) );
            $wpdb->delete( $prefix . 'task_relationships', array( 'task_id' => $group_task_id ), array( '%d' ) );
            $wpdb->delete( $prefix . 'task_relationships', array( 'predecessor_id' => $group_task_id ), array( '%d' ) );
        }

        $group_project_ids = array_map(
            'intval',
            $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT id FROM {$prefix}projects WHERE board_name = %s",
                    $group_board
                )
            )
        );

        foreach ( $group_project_ids as $group_project_id ) {
            $wpdb->delete( $prefix . 'project_assignments', array( 'project_id' => $group_project_id ), array( '%d' ) );
        }

        $wpdb->delete( $prefix . 'tasks', array( 'board_name' => $group_board ), array( '%s' ) );
        $wpdb->delete( $prefix . 'projects', array( 'board_name' => $group_board ), array( '%s' ) );
        $wpdb->delete( $prefix . 'categories', array( 'board_name' => $group_board ), array( '%s' ) );
    }

    if ( $group_id > 0 && function_exists( 'groups_delete_group' ) ) {
        groups_delete_group( $group_id );
    }

    delete_transient( 'pandat69_writable_boards_v2_' . $admin_id );
}

$residue = array(
    'tasks'      => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$prefix}tasks WHERE board_name = %s", $board ) ),
    'projects'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$prefix}projects WHERE board_name = %s", $board ) ),
    'categories' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$prefix}categories WHERE board_name = %s", $board ) ),
);

if ( '' !== $group_board ) {
    $residue['group_tasks'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$prefix}tasks WHERE board_name = %s", $group_board ) );
    $residue['group_projects'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$prefix}projects WHERE board_name = %s", $group_board ) );
}

WP_CLI::line(
    wp_json_encode(
        array(
            'board'     => $board,
            'responses' => $responses,
            'residue'   => $residue,
        ),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    )
);

if ( null !== $failure ) {
    WP_CLI::error( $failure );
}

if ( 0 !== array_sum( $residue ) ) {
    WP_CLI::error( 'The mutation smoke test left board data behind.' );
}

WP_CLI::success( 'Pandatask REST mutation smoke test passed and cleaned up.' );
