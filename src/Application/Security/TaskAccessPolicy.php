<?php

namespace Pandatask\Application\Security;

use Pandatask\Application\Task\TaskService;
use WP_Error;

final class TaskAccessPolicy {

    private $task_service;

    private $board_access_policy;

    private $inbox_access_policy;

    public function __construct( $task_service = null, $board_access_policy = null, $inbox_access_policy = null ) {
        $this->task_service        = $task_service ?: new TaskService();
        $this->board_access_policy = $board_access_policy ?: new BoardAccessPolicy();
        $this->inbox_access_policy = $inbox_access_policy ?: new InboxAccessPolicy();
    }

    public function canReadTask( $task_id, $user_id = null ) {
        $user_id = $this->normalizeUserId( $user_id );

        if ( $user_id <= 0 ) {
            return $this->notLoggedInError();
        }

        $task = $this->task_service->getTaskForAuthorization( (int) $task_id );

        if ( ! $task ) {
            return new WP_Error( 'rest_task_not_found', __( 'Task not found.', 'pandatask' ), array( 'status' => 404 ) );
        }

        if ( $this->containsUserId( $task->assigned_user_ids ?? array(), $user_id ) ) {
            return true;
        }

        if ( $this->containsUserId( $task->supervisor_user_ids ?? array(), $user_id ) ) {
            return true;
        }

        if ( $this->isTaskCreator( $task, $user_id ) ) {
            return true;
        }

        if ( $this->canTriageInboxTask( $task, $user_id ) ) {
            return true;
        }

        return $this->board_access_policy->canReadBoard( $task->board_name, $user_id );
    }

    public function canUpdateTask( $task_id, $user_id = null ) {
        $user_id = $this->normalizeUserId( $user_id );

        if ( $user_id <= 0 ) {
            return $this->notLoggedInError();
        }

        $task = $this->task_service->getTaskForAuthorization( (int) $task_id );

        if ( ! $task ) {
            return new WP_Error( 'rest_task_not_found', __( 'Task not found.', 'pandatask' ), array( 'status' => 404 ) );
        }

        if ( user_can( $user_id, 'manage_options' ) || $this->isTaskParticipant( $task, $user_id ) || $this->canTriageInboxTask( $task, $user_id ) ) {
            return true;
        }

        return $this->board_access_policy->canManageBoard( $task->board_name, $user_id );
    }

    public function canDeleteTask( $task_id, $user_id = null ) {
        $user_id = $this->normalizeUserId( $user_id );

        if ( $user_id <= 0 ) {
            return $this->notLoggedInError();
        }

        $task = $this->task_service->getTaskForAuthorization( (int) $task_id );

        if ( ! $task ) {
            return new WP_Error( 'rest_task_not_found', __( 'Task not found.', 'pandatask' ), array( 'status' => 404 ) );
        }

        if (
            user_can( $user_id, 'manage_options' )
            || $this->isTaskCreator( $task, $user_id )
            || $this->containsUserId( $task->supervisor_user_ids ?? array(), $user_id )
        ) {
            return true;
        }

        return $this->board_access_policy->canManageBoard( $task->board_name, $user_id );
    }

    /**
     * Assignment and supervisor changes are more privileged than ordinary task edits.
     *
     * Assignees may update their work, but must not be able to promote themselves to
     * supervisor and then gain delete authority.
     */
    public function canManageTaskRoles( $task_id, $user_id = null ) {
        $user_id = $this->normalizeUserId( $user_id );

        if ( $user_id <= 0 ) {
            return $this->notLoggedInError();
        }

        $task = $this->task_service->getTaskForAuthorization( (int) $task_id );

        if ( ! $task ) {
            return new WP_Error( 'rest_task_not_found', __( 'Task not found.', 'pandatask' ), array( 'status' => 404 ) );
        }

        if (
            user_can( $user_id, 'manage_options' )
            || $this->isTaskCreator( $task, $user_id )
            || $this->containsUserId( $task->supervisor_user_ids ?? array(), $user_id )
        ) {
            return true;
        }

        return $this->board_access_policy->canManageBoard( $task->board_name, $user_id );
    }

    /**
     * Moving a task changes the board-level security boundary.
     */
    public function canMoveTask( $task_id, $user_id = null ) {
        $user_id = $this->normalizeUserId( $user_id );

        if ( $user_id <= 0 ) {
            return $this->notLoggedInError();
        }

        $task = $this->task_service->getTaskForAuthorization( (int) $task_id );

        if ( ! $task ) {
            return new WP_Error( 'rest_task_not_found', __( 'Task not found.', 'pandatask' ), array( 'status' => 404 ) );
        }

        if ( user_can( $user_id, 'manage_options' ) || $this->canTriageInboxTask( $task, $user_id ) ) {
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
            || $this->isTaskCreator( $task, $user_id );
    }

    private function canTriageInboxTask( $task, $user_id ) {
        if ( $user_id <= 0 || empty( $task->inbox_state ) ) {
            return false;
        }

        $owner_user_id = InboxAccessPolicy::ownerFromBoardName( $task->board_name );
        if ( $owner_user_id <= 0 ) {
            return false;
        }

        return true === $this->inbox_access_policy->canTriageInbox( $owner_user_id, $user_id );
    }

    private function containsUserId( $user_ids, $user_id ) {
        if ( (int) $user_id <= 0 ) {
            return false;
        }

        return in_array( (int) $user_id, array_map( 'intval', (array) $user_ids ), true );
    }

    private function isTaskCreator( $task, $user_id ) {
        if ( $user_id <= 0 || ! isset( $task->creator_id ) ) {
            return false;
        }

        $creator_id = (int) $task->creator_id;

        return $creator_id > 0 && $creator_id === $user_id;
    }

    private function normalizeUserId( $user_id ) {
        return (int) ( null === $user_id ? get_current_user_id() : $user_id );
    }

    private function notLoggedInError() {
        return new WP_Error( 'rest_not_logged_in', __( 'You must be logged in.', 'pandatask' ), array( 'status' => 401 ) );
    }
}
