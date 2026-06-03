<?php

namespace Pandatask\Application\Security;

use Pandatask\Application\Task\TaskService;
use WP_Error;

final class TaskAccessPolicy {

    private $task_service;

    private $board_access_policy;

    public function __construct( $task_service = null, $board_access_policy = null ) {
        $this->task_service        = $task_service ?: new TaskService();
        $this->board_access_policy = $board_access_policy ?: new BoardAccessPolicy();
    }

    public function canAccessTask( $task_id, $user_id = null ) {
        $task = $this->task_service->getTask( (int) $task_id );

        if ( ! $task ) {
            return new WP_Error( 'rest_task_not_found', __( 'Task not found.', 'pandatask' ), array( 'status' => 404 ) );
        }

        $user_id = null === $user_id ? get_current_user_id() : (int) $user_id;

        if ( ! empty( $task->assigned_user_ids ) && in_array( (string) $user_id, $task->assigned_user_ids, true ) ) {
            return true;
        }

        if ( ! empty( $task->supervisor_user_ids ) && in_array( (string) $user_id, $task->supervisor_user_ids, true ) ) {
            return true;
        }

        if ( isset( $task->creator_id ) && (int) $task->creator_id === $user_id ) {
            return true;
        }

        return $this->board_access_policy->canReadBoard( $task->board_name, $user_id );
    }
}
