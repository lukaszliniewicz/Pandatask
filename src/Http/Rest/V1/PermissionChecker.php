<?php

namespace Pandatask\Http\Rest\V1;

use Pandatask\Application\Project\ProjectService;
use Pandatask\Application\Security\BoardAccessPolicy;
use Pandatask\Application\Security\CommentAccessPolicy;
use Pandatask\Application\Security\TaskAccessPolicy;
use Pandatask\Http\Rest\V1\Support\RequestHelper;
use WP_Error;

final class PermissionChecker {

    private $project_service;

    private $board_access_policy;

    private $task_access_policy;

    private $comment_access_policy;

    public function __construct() {
        $this->project_service      = new ProjectService();
        $this->board_access_policy  = new BoardAccessPolicy();
        $this->task_access_policy   = new TaskAccessPolicy();
        $this->comment_access_policy = new CommentAccessPolicy();
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
        if ( $this->is_public_bug_submission_allowed( $request ) ) {
            return true;
        }

        return $this->check_board_read_permission( $request );
    }

    public function check_task_permission( $request ) {
        $task_id = (int) ( $request['id'] ?? $request['task_id'] );

        return $this->task_access_policy->canAccessTask( $task_id, get_current_user_id() );
    }

    public function check_project_permission( $request ) {
        $project = $this->project_service->getProject( (int) $request['id'] );

        if ( ! $project ) {
            return new WP_Error( 'rest_not_found', 'Project not found', array( 'status' => 404 ) );
        }

        return $this->board_access_policy->canReadBoard( $project->board_name, get_current_user_id() );
    }

    public function check_category_permission( $request ) {
        $board_name = $request['board_name'];

        if ( ! $board_name ) {
            return new WP_Error( 'rest_missing_param', 'board_name is required', array( 'status' => 400 ) );
        }

        return $this->board_access_policy->canReadBoard( $board_name, get_current_user_id() );
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
        $settings = get_option( 'pandatask_bug_tracker_settings', array() );

        $default_visibility = isset( $settings['enable'] ) && $settings['enable'] ? 'logged_in' : 'off';
        $visibility         = isset( $settings['visibility'] ) ? $settings['visibility'] : $default_visibility;

        if ( 'both' !== $visibility && 'logged_out' !== $visibility ) {
            return false;
        }

        if ( empty( $settings['board'] ) || $settings['board'] !== $request['board_name'] ) {
            return false;
        }

        $params = RequestHelper::bodyParams( $request );

        return isset( $params['task_type'] ) && 'bug' === $params['task_type'];
    }
}
