<?php

namespace Pandatask\Http\Rest\V1;

use Exception;
use Pandatask\Application\Board\BoardActivityService;
use Pandatask\Application\Security\BoardAccessPolicy;
use Pandatask\Application\Security\PublicBugSubmissionPolicy;
use Pandatask\Application\Security\TaskAccessPolicy;
use Pandatask\Application\Task\HistoryService;
use Pandatask\Application\Task\TaskService;
use Pandatask\Http\Rest\V1\Support\RequestHelper;
use Pandatask\Http\Rest\V1\Support\TaskInputNormalizer;
use WP_Error;
use WP_REST_Response;

final class TaskRouteHandler {

    private $task_service;

    private $history_service;

    private $board_access_policy;

    private $public_bug_submission_policy;

    private $task_access_policy;

    private $input_normalizer;

    private $board_activity_service;

    public function __construct( $task_service = null, $history_service = null, $board_access_policy = null, $public_bug_submission_policy = null, $task_access_policy = null, $input_normalizer = null, $board_activity_service = null ) {
        $this->task_service    = $task_service ?: new TaskService();
        $this->history_service = $history_service ?: new HistoryService();
        $this->board_access_policy = $board_access_policy ?: new BoardAccessPolicy();
        $this->public_bug_submission_policy = $public_bug_submission_policy ?: new PublicBugSubmissionPolicy();
        $this->task_access_policy = $task_access_policy ?: new TaskAccessPolicy( $this->task_service, $this->board_access_policy );
        $this->input_normalizer = $input_normalizer ?: new TaskInputNormalizer();
        $this->board_activity_service = $board_activity_service ?: new BoardActivityService();
    }

    public function get_board_activity( $request ) {
        $board_name = sanitize_key( (string) $request['board_name'] );
        $limit = isset( $request['limit'] ) ? max( 1, min( 100, (int) $request['limit'] ) ) : 20;

        return new WP_REST_Response(
            $this->board_activity_service->getBoardActivity( $board_name, $limit ),
            200
        );
    }

    public function get_potential_parent_tasks( $request ) {
        $board_name = $request['board_name'];
        $current_id = $request->get_param( 'current_task_id' ) ? (int) $request->get_param( 'current_task_id' ) : 0;
        $tasks      = $this->task_service->getPotentialParentTasks( $board_name, $current_id );

        return new WP_REST_Response( array( 'parent_tasks' => $tasks ), 200 );
    }

    public function get_task_history( $request ) {
        $history = $this->history_service->getTaskHistory( (int) $request['id'] );

        return new WP_REST_Response( array( 'history' => $history ), 200 );
    }

    public function get_tasks( $request ) {
        $board_name = $request['board_name'];
        $params     = $request->get_params();

        $search            = $params['search'] ?? '';
        $sort              = $params['sort'] ?? 'deadline_asc';
        $status_filter     = $params['status_filter'] ?? 'pending_in-progress';
        $project_filter    = $params['project_filter'] ?? null;
        $archived          = isset( $params['archived'] ) ? (int) $params['archived'] : 0;
        $private_only      = isset( $params['private_only'] ) && rest_sanitize_boolean( $params['private_only'] );
        $include_templates = isset( $params['include_templates'] ) && rest_sanitize_boolean( $params['include_templates'] );
        $task_type_filter  = $params['task_type_filter'] ?? '';
        $limit             = isset( $params['limit'] ) ? max( 1, min( 500, (int) $params['limit'] ) ) : 500;
        $offset            = max( 0, (int) ( $params['offset'] ?? 0 ) );

        $last_underscore_pos = strrpos( $sort, '_' );

        if ( false !== $last_underscore_pos ) {
            $sort_by    = substr( $sort, 0, $last_underscore_pos );
            $sort_order = substr( $sort, $last_underscore_pos + 1 );
        } else {
            $sort_by    = $sort;
            $sort_order = 'asc';
        }

        $sort_order = 'DESC' === strtoupper( $sort_order ) ? 'DESC' : 'ASC';

        if ( preg_match( '/^user_(\d+)$/', $board_name, $matches ) ) {
            $board_user_id = intval( $matches[1] );

            if ( $board_user_id !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
                return new WP_Error( 'rest_forbidden', 'Access denied', array( 'status' => 403 ) );
            }

            $tasks = $this->task_service->getTasksForUserAcrossBoards( $board_user_id, $search, $sort_by, $sort_order, $status_filter, $archived, $project_filter, $private_only, $include_templates, $limit + 1, $offset );
        } else {
            $date_filter    = '';
            $start_date     = '';
            $end_date       = '';
            $filter_user_id = null;

            if ( isset( $params['assigned_to_me'] ) && rest_sanitize_boolean( $params['assigned_to_me'] ) && is_user_logged_in() ) {
                $filter_user_id = get_current_user_id();
            }

            $tasks = $this->task_service->getTasks( $board_name, $search, $sort_by, $sort_order, $status_filter, $date_filter, $start_date, $end_date, $archived, $project_filter, $include_templates, $task_type_filter, $filter_user_id, $limit + 1, $offset );
        }

        $has_more = count( $tasks ) > $limit;

        if ( $has_more ) {
            $tasks = array_slice( $tasks, 0, $limit );
        }

        RequestHelper::renderTaskCollection( $tasks );

        return new WP_REST_Response(
            array(
                'tasks'      => $tasks,
                'pagination' => array(
                    'limit'     => $limit,
                    'offset'    => $offset,
                    'returned'  => count( $tasks ),
                    'has_more'  => $has_more,
                    'next_offset' => $has_more ? $offset + $limit : null,
                ),
            ),
            200
        );
    }

    /**
     * List every task the current user can read without requiring clients to walk
     * boards one at a time. Direct task participation is honoured for private
     * boards, matching TaskAccessPolicy::canReadTask().
     */
    public function get_visible_tasks( $request ) {
        $params = $request->get_params();

        $search            = $params['search'] ?? '';
        $sort              = $params['sort'] ?? 'deadline_asc';
        $status_filter     = $params['status_filter'] ?? '';
        $project_filter    = $params['project_filter'] ?? null;
        $archived          = isset( $params['archived'] ) ? (int) $params['archived'] : null;
        $include_templates = ! isset( $params['include_templates'] ) || rest_sanitize_boolean( $params['include_templates'] );
        $task_type_filter  = $params['task_type_filter'] ?? '';
        $assigned_to_me    = isset( $params['assigned_to_me'] ) && rest_sanitize_boolean( $params['assigned_to_me'] );
        $limit             = isset( $params['limit'] ) ? max( 1, min( 500, (int) $params['limit'] ) ) : 500;
        $offset            = max( 0, (int) ( $params['offset'] ?? 0 ) );
        $last_underscore_pos = strrpos( $sort, '_' );

        if ( false !== $last_underscore_pos ) {
            $sort_by    = substr( $sort, 0, $last_underscore_pos );
            $sort_order = substr( $sort, $last_underscore_pos + 1 );
        } else {
            $sort_by    = $sort;
            $sort_order = 'asc';
        }

        $tasks = $this->task_service->getVisibleTasksForUser(
            get_current_user_id(),
            $search,
            $sort_by,
            'DESC' === strtoupper( $sort_order ) ? 'DESC' : 'ASC',
            $status_filter,
            $archived,
            $project_filter,
            $include_templates,
            $task_type_filter,
            $assigned_to_me,
            $limit + 1,
            $offset
        );

        $has_more = count( $tasks ) > $limit;

        if ( $has_more ) {
            $tasks = array_slice( $tasks, 0, $limit );
        }

        RequestHelper::renderTaskCollection( $tasks );

        return new WP_REST_Response(
            array(
                'tasks'      => $tasks,
                'pagination' => array(
                    'limit'       => $limit,
                    'offset'      => $offset,
                    'returned'    => count( $tasks ),
                    'has_more'    => $has_more,
                    'next_offset' => $has_more ? $offset + $limit : null,
                ),
            ),
            200
        );
    }

    public function create_task( $request ) {
        $params = RequestHelper::bodyParams( $request );

        if ( empty( $params['name'] ) ) {
            return new WP_Error( 'rest_missing', 'Name is required', array( 'status' => 400 ) );
        }

        $attributes = $request->get_attributes();
        $is_public_bug_submission = ! empty( $attributes['pandatask_public_bug_submission'] );
        $data = $is_public_bug_submission
            ? $this->buildPublicBugCreateData( $request['board_name'], $params )
            : $this->input_normalizer->buildCreateData( $request['board_name'], $params );

        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $task_id = $this->task_service->createTask( $data );

        if ( is_wp_error( $task_id ) ) {
            return $task_id;
        }

        if ( ! $task_id ) {
            return new WP_Error( 'rest_error', 'Failed to create task', array( 'status' => 500 ) );
        }

        if ( RequestHelper::isMinimalResponse( $request ) ) {
            return new WP_REST_Response( array( 'message' => 'Task added', 'id' => $task_id ), 201 );
        }

        $new_task = RequestHelper::renderTask( $this->task_service->getTask( $task_id ) );

        return new WP_REST_Response( array( 'message' => 'Task added', 'task' => $new_task ), 201 );
    }

    public function get_task( $request ) {
        $task = RequestHelper::renderTask( $this->task_service->getTask( (int) $request['id'] ) );

        return new WP_REST_Response( array( 'task' => $task ), 200 );
    }

    public function update_task( $request ) {
        $id             = (int) $request['id'];
        $params         = RequestHelper::bodyParams( $request );
        $change_comment = $params['change_comment'] ?? '';
        $current_task = $this->task_service->getTask( $id );

        if ( ! $current_task ) {
            return new WP_Error( 'rest_task_not_found', __( 'Task not found.', 'pandatask' ), array( 'status' => 404 ) );
        }

        $data = $this->input_normalizer->buildUpdateData( $params, $current_task );

        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $field_permission = $this->validateSensitiveTaskUpdate( $id, $data, $current_task );

        if ( is_wp_error( $field_permission ) ) {
            return $field_permission;
        }

        $target_board = $data['board_name'] ?? $current_task->board_name;

        if ( $target_board !== $current_task->board_name ) {
            $destination_permission = $this->board_access_policy->canWriteBoard( $target_board, get_current_user_id() );

            if ( true !== $destination_permission ) {
                return $destination_permission;
            }
        }

        $result         = $this->task_service->updateTask( $id, $data, $change_comment, get_current_user_id() );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        if ( $result ) {
            $task = RequestHelper::renderTask( $this->task_service->getTask( $id ) );

            return new WP_REST_Response( array( 'message' => 'Task updated', 'task' => $task ), 200 );
        }

        return new WP_Error( 'pandatask_update_failed', __( 'The task could not be updated.', 'pandatask' ), array( 'status' => 500 ) );
    }

    public function delete_task( $request ) {
        $result = $this->task_service->deleteTask( (int) $request['id'], $request['delete_scope'] ?? null );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        if ( $result ) {
            return new WP_REST_Response( array( 'message' => 'Task deleted' ), 200 );
        }

        return new WP_Error( 'rest_error', 'Delete failed', array( 'status' => 500 ) );
    }

    public function create_task_from_batch( $board_name, $data ) {
        if ( ! $board_name ) {
            throw new Exception( '`board_name` is required for create actions.' );
        }

        if ( empty( $data['name'] ) ) {
            return new WP_Error( 'rest_missing', 'Name is required', array( 'status' => 400 ) );
        }

        $task_data = $this->input_normalizer->buildCreateData( $board_name, $data );

        if ( is_wp_error( $task_data ) ) {
            return $task_data;
        }

        $task_id = $this->task_service->createTask( $task_data );

        if ( is_wp_error( $task_id ) ) {
            return $task_id;
        }

        if ( ! $task_id ) {
            return new WP_Error( 'rest_error', 'Failed to create task', array( 'status' => 500 ) );
        }

        return array(
            'message' => 'Task added',
            'task'    => RequestHelper::renderTask( $this->task_service->getTask( $task_id ) ),
        );
    }

    public function update_task_from_batch( $data ) {
        if ( ! isset( $data['id'] ) ) {
            throw new Exception( 'ID is required for update actions.' );
        }

        $task_id          = (int) $data['id'];
        $change_comment   = $data['change_comment'] ?? '';
        $current_task = $this->task_service->getTask( $task_id );

        if ( ! $current_task ) {
            return new WP_Error( 'rest_task_not_found', __( 'Task not found.', 'pandatask' ), array( 'status' => 404 ) );
        }

        $task_data = $this->input_normalizer->buildUpdateData( $data, $current_task );

        if ( is_wp_error( $task_data ) ) {
            return $task_data;
        }

        $field_permission = $this->validateSensitiveTaskUpdate( $task_id, $task_data, $current_task );

        if ( is_wp_error( $field_permission ) ) {
            return $field_permission;
        }

        $target_board = $task_data['board_name'] ?? $current_task->board_name;

        if ( $target_board !== $current_task->board_name ) {
            $destination_permission = $this->board_access_policy->canWriteBoard( $target_board, get_current_user_id() );

            if ( true !== $destination_permission ) {
                return $destination_permission;
            }
        }

        $update_succeeded = $this->task_service->updateTask( $task_id, $task_data, $change_comment, get_current_user_id() );

        if ( is_wp_error( $update_succeeded ) ) {
            return $update_succeeded;
        }

        if ( ! $update_succeeded ) {
            return array( 'message' => 'No changes or update failed' );
        }

        return array(
            'message' => 'Task updated',
            'task'    => RequestHelper::renderTask( $this->task_service->getTask( $task_id ) ),
        );
    }

    public function delete_task_from_batch( $data ) {
        if ( ! isset( $data['id'] ) ) {
            throw new Exception( 'ID is required for delete actions.' );
        }

        $result = $this->task_service->deleteTask( (int) $data['id'], $data['delete_scope'] ?? null );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        if ( ! $result ) {
            return new WP_Error( 'rest_error', 'Delete failed', array( 'status' => 500 ) );
        }

        return array( 'message' => 'Task deleted' );
    }

    private function buildPublicBugCreateData( $board_name, $params ) {
        $board_name = sanitize_key( $board_name );

        if (
            ! $this->public_bug_submission_policy->canSubmit(
                $board_name,
                sanitize_key( $params['task_type'] ?? '' ),
                is_user_logged_in()
            )
        ) {
            return new WP_Error( 'rest_forbidden', __( 'Public bug submission is not enabled for this board.', 'pandatask' ), array( 'status' => 403 ) );
        }

        $rate_limit = $this->public_bug_submission_policy->consumeAnonymousSubmissionBudget();

        if ( is_wp_error( $rate_limit ) ) {
            return $rate_limit;
        }

        $assignee_id = $this->public_bug_submission_policy->getConfiguredAssigneeId();
        $public_params = array(
            'name'        => $params['name'] ?? '',
            'description' => $params['description'] ?? '',
            'task_type'   => 'bug',
            'bug_url'     => $params['bug_url'] ?? '',
            'status'      => 'pending',
            'priority'    => 5,
        );

        if ( $assignee_id > 0 && $this->board_access_policy->isUserAllowedOnBoard( $board_name, $assignee_id ) ) {
            $public_params['assigned_persons'] = array( $assignee_id );
        }

        return $this->input_normalizer->buildCreateData( $board_name, $public_params );
    }

    private function validateSensitiveTaskUpdate( $task_id, $data, $current_task ) {
        if ( array_key_exists( 'assigned_persons', $data ) || array_key_exists( 'supervisor_persons', $data ) ) {
            $role_permission = $this->task_access_policy->canManageTaskRoles( $task_id, get_current_user_id() );

            if ( true !== $role_permission ) {
                return new WP_Error(
                    'rest_forbidden_task_roles',
                    __( 'Only the task creator, a supervisor, or a board manager may change task roles.', 'pandatask' ),
                    array( 'status' => 403 )
                );
            }
        }

        if ( isset( $data['board_name'] ) && $data['board_name'] !== $current_task->board_name ) {
            $move_permission = $this->task_access_policy->canMoveTask( $task_id, get_current_user_id() );

            if ( true !== $move_permission ) {
                return new WP_Error(
                    'rest_forbidden_task_move',
                    __( 'Only a board manager may move a task to another board.', 'pandatask' ),
                    array( 'status' => 403 )
                );
            }
        }

        return true;
    }

}
