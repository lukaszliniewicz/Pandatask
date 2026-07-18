<?php

namespace Pandatask\Http\Rest\V1;

use Pandatask\Application\Project\ProjectService;
use Pandatask\Application\Security\BoardAccessPolicy;
use Pandatask\Application\Security\CommentAccessPolicy;
use Pandatask\Application\Security\PublicBugSubmissionPolicy;
use Pandatask\Application\Security\TaskAccessPolicy;
use Pandatask\Http\Rest\V1\Support\RequestHelper;
use WP_Error;

final class PermissionChecker {

    private $project_service;

    private $board_access_policy;

    private $task_access_policy;

    private $comment_access_policy;

    private $public_bug_submission_policy;

    public function __construct() {
        $this->project_service      = new ProjectService();
        $this->board_access_policy  = new BoardAccessPolicy();
        $this->task_access_policy   = new TaskAccessPolicy();
        $this->comment_access_policy = new CommentAccessPolicy();
        $this->public_bug_submission_policy = new PublicBugSubmissionPolicy();
    }

    public function check_user_logged_in_permission( $request ) {
        if ( ! is_user_logged_in() ) {
            return new WP_Error( 'rest_not_logged_in', __( 'You must be logged in.', 'pandatask' ), array( 'status' => 401 ) );
        }

        return true;
    }

    public function check_board_read_permission( $request ) {
        return $this->board_access_policy->canReadBoard( $request['board_name'], get_current_user_id() );
    }

    public function check_board_write_permission( $request ) {
        $normal_permission = $this->board_access_policy->canWriteBoard( $request['board_name'], get_current_user_id() );

        if ( true === $normal_permission ) {
            return true;
        }

        if ( $this->is_public_bug_submission_allowed( $request ) ) {
            $attributes = $request->get_attributes();
            $attributes['pandatask_public_bug_submission'] = true;
            $request->set_attributes( $attributes );

            return true;
        }

        return $normal_permission;
    }

    public function check_task_read_permission( $request ) {
        $task_id = (int) ( $request['id'] ?? $request['task_id'] );

        return $this->task_access_policy->canReadTask( $task_id, get_current_user_id() );
    }

    public function check_task_update_permission( $request ) {
        return $this->task_access_policy->canUpdateTask( (int) $request['id'], get_current_user_id() );
    }

    public function check_task_delete_permission( $request ) {
        return $this->task_access_policy->canDeleteTask( (int) $request['id'], get_current_user_id() );
    }

    public function check_task_permission( $request ) {
        return $this->check_task_read_permission( $request );
    }

    public function check_project_permission( $request ) {
        $project = $this->project_service->getProject( (int) $request['id'] );

        if ( ! $project ) {
            return new WP_Error( 'rest_not_found', 'Project not found', array( 'status' => 404 ) );
        }

        return $this->board_access_policy->canReadBoard( $project->board_name, get_current_user_id() );
    }

    public function check_project_manage_permission( $request ) {
        $project = $this->project_service->getProject( (int) $request['id'] );

        if ( ! $project ) {
            return new WP_Error( 'rest_not_found', 'Project not found', array( 'status' => 404 ) );
        }

        return $this->board_access_policy->canManageBoard( $project->board_name, get_current_user_id() );
    }

    public function check_category_permission( $request ) {
        $board_name = $request['board_name'];

        if ( ! $board_name ) {
            return new WP_Error( 'rest_missing_param', 'board_name is required', array( 'status' => 400 ) );
        }

        return $this->board_access_policy->canReadBoard( $board_name, get_current_user_id() );
    }

    public function check_category_manage_permission( $request ) {
        $board_name = $request['board_name'];

        if ( ! $board_name ) {
            return new WP_Error( 'rest_missing_param', 'board_name is required', array( 'status' => 400 ) );
        }

        return $this->board_access_policy->canManageBoard( $board_name, get_current_user_id() );
    }

    public function check_directory_permission( $request ) {
        $board_name = sanitize_key( $request['board_name'] ?? '' );

        if ( $board_name ) {
            return $this->board_access_policy->canReadBoard( $board_name, get_current_user_id() );
        }

        return $this->check_admin_permission( $request );
    }

    public function check_comment_permission( $request ) {
        return $this->comment_access_policy->canManageComment( (int) $request['id'] );
    }

    public function check_admin_permission( $request ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'rest_forbidden', __( 'You do not have permission.', 'pandatask' ), array( 'status' => 403 ) );
        }

        return true;
    }

    private function is_public_bug_submission_allowed( $request ) {
        $params = RequestHelper::bodyParams( $request );

        return $this->public_bug_submission_policy->canSubmit(
            sanitize_key( $request['board_name'] ),
            sanitize_key( $params['task_type'] ?? '' ),
            is_user_logged_in()
        );
    }
}
