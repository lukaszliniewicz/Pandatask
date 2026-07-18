<?php
/**
 * Controlled REST mutation smoke test for a dev Pandatask installation.
 *
 * The script creates data on a unique temporary board and always removes it.
 * Run with: wp eval-file /path/to/server-mutation-smoke.php --path=/path/to/wordpress
 */

use Pandatask\Infrastructure\Persistence\DatabaseContext;

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

wp_set_current_user( $admin_id );

$dispatch = static function ( $method, $path, $body = array() ) {
    $request = new WP_REST_Request( $method, $path );

    if ( ! empty( $body ) ) {
        $request->set_body_params( $body );
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
                'project_id'         => $project_id,
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
    $expect_status( $dispatch( 'DELETE', '/pandatask/v1/tasks/' . $child_id ), 200, 'delete_child_task' );
    $expect_status( $dispatch( 'DELETE', '/pandatask/v1/tasks/' . $root_id ), 200, 'delete_root_task' );
    $expect_status( $dispatch( 'DELETE', '/pandatask/v1/projects/' . $project_id ), 200, 'delete_project' );
    $expect_status(
        $dispatch(
            'DELETE',
            '/pandatask/v1/categories/' . $category_id,
            array( 'board_name' => $board )
        ),
        200,
        'delete_category'
    );
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
}

$residue = array(
    'tasks'      => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$prefix}tasks WHERE board_name = %s", $board ) ),
    'projects'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$prefix}projects WHERE board_name = %s", $board ) ),
    'categories' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$prefix}categories WHERE board_name = %s", $board ) ),
);

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
