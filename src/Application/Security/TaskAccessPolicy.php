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

    public function canReadTask( $task_id, $user_id = null ) {
        $task = $this->task_service->getTaskForAuthorization( (int) $task_id );

        if ( ! $task ) {
            return new WP_Error( 'rest_task_not_found', __( 'Task not found.', 'pandatask' ), array( 'status' => 404 ) );
        }

        $user_id = null === $user_id ? get_current_user_id() : (int) $user_id;

        if ( $this->containsUserId( $task->assigned_user_ids ?? array(), $user_id ) ) {
            return true;
        }

        if ( $this->containsUserId( $task->supervisor_user_ids ?? array(), $user_id ) ) {
            return true;
        }

        if ( isset( $task->creator_id ) && (int) $task->creator_id === $user_id ) {
            return true;
        }

        return $this->board_access_policy->canReadBoard( $task->board_name, $user_id );
    }

    public function canUpdateTask( $task_id, $user_id = null ) {
        $task = $this->task_service->getTaskForAuthorization( (int) $task_id );

        if ( ! $task ) {
            return new WP_Error( 'rest_task_not_found', __( 'Task not found.', 'pandatask' ), array( 'status' => 404 ) );
        }

        $user_id = null === $user_id ? get_current_user_id() : (int) $user_id;

        if ( user_can( $user_id, 'manage_options' ) || $this->isTaskParticipant( $task, $user_id ) ) {
            return true;
        }

        return $this->board_access_policy->canManageBoard( $task->board_name, $user_id );
    }

    public function canDeleteTask( $task_id, $user_id = null ) {
        $task = $this->task_service->getTaskForAuthorization( (int) $task_id );

        if ( ! $task ) {
            return new WP_Error( 'rest_task_not_found', __( 'Task not found.', 'pandatask' ), array( 'status' => 404 ) );
        }

        $user_id = null === $user_id ? get_current_user_id() : (int) $user_id;

        if (
            user_can( $user_id, 'manage_options' )
            || ( isset( $task->creator_id ) && (int) $task->creator_id === $user_id )
            || $this->containsUserId( $task->supervisor_user_ids ?? array(), $user_id )
        ) {
            return true;
        }

        return $this->board_access_policy->canManageBoard( $task->board_name, $user_id );
    }

    public function canAccessTask( $task_id, $user_id = null ) {
        return $this->canReadTask( $task_id, $user_id );
    }

    private function isTaskParticipant( $task, $user_id ) {
        return $this->containsUserId( $task->assigned_user_ids ?? array(), $user_id )
            || $this->containsUserId( $task->supervisor_user_ids ?? array(), $user_id )
            || ( isset( $task->creator_id ) && (int) $task->creator_id === $user_id );
    }

    private function containsUserId( $user_ids, $user_id ) {
        return in_array( (int) $user_id, array_map( 'intval', (array) $user_ids ), true );
    }
}
