<?php

namespace Pandatask\Http\Rest\V1;

use Exception;
use Pandatask\Application\Category\CategoryService;
use Pandatask\Application\Project\ProjectService;
use Pandatask\Application\Security\BoardAccessPolicy;
use Pandatask\Application\Security\PublicBugSubmissionPolicy;
use Pandatask\Application\Task\HistoryService;
use Pandatask\Application\Task\TaskService;
use Pandatask\Http\Rest\V1\Support\RequestHelper;
use WP_Error;
use WP_REST_Response;

final class TaskRouteHandler {

    private $task_service;

    private $history_service;

    private $category_service;

    private $project_service;

    private $board_access_policy;

    private $public_bug_submission_policy;

    public function __construct( $task_service = null, $history_service = null, $category_service = null, $project_service = null, $board_access_policy = null, $public_bug_submission_policy = null ) {
        $this->task_service    = $task_service ?: new TaskService();
        $this->history_service = $history_service ?: new HistoryService();
        $this->category_service = $category_service ?: new CategoryService();
        $this->project_service = $project_service ?: new ProjectService();
        $this->board_access_policy = $board_access_policy ?: new BoardAccessPolicy();
        $this->public_bug_submission_policy = $public_bug_submission_policy ?: new PublicBugSubmissionPolicy();
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
        $private_only      = isset( $params['private_only'] ) && 'true' === $params['private_only'];
        $include_templates = isset( $params['include_templates'] ) && 'true' === $params['include_templates'];
        $task_type_filter  = $params['task_type_filter'] ?? '';
        $limit             = isset( $params['limit'] ) ? max( 1, min( 500, (int) $params['limit'] ) ) : 0;
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

            $tasks = $this->task_service->getTasksForUserAcrossBoards( $board_user_id, $search, $sort_by, $sort_order, $status_filter, $archived, $project_filter, $private_only, $include_templates, $limit, $offset );
        } else {
            $date_filter    = '';
            $start_date     = '';
            $end_date       = '';
            $filter_user_id = null;

            if ( isset( $params['assigned_to_me'] ) && 'true' === $params['assigned_to_me'] && is_user_logged_in() ) {
                $filter_user_id = get_current_user_id();
            }

            $tasks = $this->task_service->getTasks( $board_name, $search, $sort_by, $sort_order, $status_filter, $date_filter, $start_date, $end_date, $archived, $project_filter, $include_templates, $task_type_filter, $filter_user_id, $limit, $offset );
        }

        RequestHelper::renderTaskCollection( $tasks );

        return new WP_REST_Response(
            array(
                'tasks'      => $tasks,
                'pagination' => array(
                    'limit'     => $limit ?: null,
                    'offset'    => $offset,
                    'returned'  => count( $tasks ),
                    'has_more'  => $limit > 0 && count( $tasks ) === $limit,
                    'next_offset' => $limit > 0 && count( $tasks ) === $limit ? $offset + $limit : null,
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
            : $this->buildTaskCreateData( $request['board_name'], $params );

        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $reference_validation = $this->validateTaskReferences( $data['board_name'], $data );

        if ( is_wp_error( $reference_validation ) ) {
            return $reference_validation;
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
        $data           = $this->buildTaskUpdateData( $params, $id );
        $change_comment = $params['change_comment'] ?? '';

        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $current_task = $this->task_service->getTask( $id );
        $target_board = $data['board_name'] ?? $current_task->board_name;
        $reference_validation = $this->validateTaskReferences( $target_board, $data, $id, $current_task );

        if ( is_wp_error( $reference_validation ) ) {
            return $reference_validation;
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

        $task_data = $this->buildTaskCreateData( $board_name, $data );

        if ( is_wp_error( $task_data ) ) {
            return $task_data;
        }

        $reference_validation = $this->validateTaskReferences( $task_data['board_name'], $task_data );

        if ( is_wp_error( $reference_validation ) ) {
            return $reference_validation;
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
        $task_data = $this->buildTaskUpdateData( $data, $task_id );

        if ( is_wp_error( $task_data ) ) {
            return $task_data;
        }

        $current_task = $this->task_service->getTask( $task_id );

        if ( ! $current_task ) {
            return new WP_Error( 'rest_task_not_found', __( 'Task not found.', 'pandatask' ), array( 'status' => 404 ) );
        }

        $target_board = $task_data['board_name'] ?? $current_task->board_name;
        $reference_validation = $this->validateTaskReferences( $target_board, $task_data, $task_id, $current_task );

        if ( is_wp_error( $reference_validation ) ) {
            return $reference_validation;
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

    private function parse_id_list( $input ) {
        return RequestHelper::parseIdList( $input );
    }

    private function buildTaskCreateData( $board_name, $params ) {
        $status = sanitize_key( $params['status'] ?? 'pending' );
        $task_type = sanitize_key( $params['task_type'] ?? 'task' );

        if ( ! in_array( $status, array( 'pending', 'in-progress', 'done' ), true ) ) {
            return new WP_Error( 'rest_invalid_param', __( 'Invalid task status.', 'pandatask' ), array( 'status' => 422 ) );
        }

        if ( ! in_array( $task_type, array( 'task', 'bug' ), true ) ) {
            return new WP_Error( 'rest_invalid_param', __( 'Invalid task type.', 'pandatask' ), array( 'status' => 422 ) );
        }

        $data = array(
            'board_name'                => sanitize_key( $board_name ),
            'name'                      => sanitize_text_field( $params['name'] ?? '' ),
            'status'                    => $status,
            'priority'                  => max( 1, min( 10, absint( $params['priority'] ?? 5 ) ) ),
            'description'               => isset( $params['description'] ) ? wp_kses_post( $params['description'] ) : '',
            'assigned_persons'          => $this->parse_id_list( $params['assigned_persons'] ?? '' ),
            'supervisor_persons'        => $this->parse_id_list( $params['supervisor_persons'] ?? '' ),
            'category_id'               => ! empty( $params['category_id'] ) ? absint( $params['category_id'] ) : null,
            'project_id'                => ! empty( $params['project_id'] ) ? absint( $params['project_id'] ) : null,
            'parent_task_id'            => ! empty( $params['parent_task_id'] ) ? absint( $params['parent_task_id'] ) : null,
            'deadline'                  => ! empty( $params['deadline'] ) ? $this->sanitizeDate( $params['deadline'] ) : null,
            'start_date'                => ! empty( $params['start_date'] ) ? $this->sanitizeDate( $params['start_date'] ) : null,
            'deadline_days_after_start' => ! empty( $params['deadline_days_after_start'] ) ? absint( $params['deadline_days_after_start'] ) : null,
            'notify_deadline'           => ! empty( $params['notify_deadline'] ) ? 1 : 0,
            'notify_days_before'        => max( 1, min( 30, absint( $params['notify_days_before'] ?? 3 ) ) ),
            'is_recurring'              => ! empty( $params['is_recurring'] ) ? 1 : 0,
            'recurrence_frequency_val'  => $params['recurrence_frequency'] ?? ( $params['recurrence_frequency_val'] ?? 'weekly' ),
            'recurrence_days'           => $this->sanitizeRecurrenceDays( $params['recurrence_days'] ?? '' ),
            'recurrence_ends_on'        => ! empty( $params['recurrence_ends_on'] ) ? $this->sanitizeDate( $params['recurrence_ends_on'] ) : null,
            'attachment_type'           => isset( $params['attachment_type'] ) ? sanitize_text_field( $params['attachment_type'] ) : '',
            'attachment_url'            => isset( $params['attachment_url'] ) ? esc_url_raw( $params['attachment_url'] ) : '',
            'attachment_post_id'        => isset( $params['attachment_post_id'] ) ? absint( $params['attachment_post_id'] ) : 0,
            'attachment_filename'       => isset( $params['attachment_filename'] ) ? sanitize_text_field( $params['attachment_filename'] ) : '',
            'task_type'                 => $task_type,
            'bug_url'                   => isset( $params['bug_url'] ) ? esc_url_raw( $params['bug_url'] ) : '',
            'predecessors'              => isset( $params['predecessors'] ) ? $this->parse_id_list( $params['predecessors'] ) : array(),
        );

        if ( is_wp_error( $data['deadline'] ) || is_wp_error( $data['start_date'] ) || is_wp_error( $data['recurrence_ends_on'] ) ) {
            return new WP_Error( 'rest_invalid_param', __( 'Dates must use the YYYY-MM-DD format.', 'pandatask' ), array( 'status' => 422 ) );
        }

        $attachment_validation = $this->validateAttachmentInput( $data );

        if ( is_wp_error( $attachment_validation ) ) {
            return $attachment_validation;
        }

        if ( empty( $data['assigned_persons'] ) && ! empty( $params['default_assignee_id'] ) ) {
            $data['assigned_persons'] = array( absint( $params['default_assignee_id'] ) );
        }

        return $this->normalize_task_recurrence_data( $data );
    }

    private function buildTaskUpdateData( $params, $task_id = 0 ) {
        $data = array();

        if ( isset( $params['board_name'] ) ) {
            $data['board_name'] = sanitize_key( $params['board_name'] );
        }

        if ( isset( $params['name'] ) ) {
            $data['name'] = sanitize_text_field( $params['name'] );
        }

        if ( isset( $params['description'] ) ) {
            $data['description'] = wp_kses_post( $params['description'] );
        }

        if ( isset( $params['status'] ) ) {
            $status = sanitize_key( $params['status'] );

            if ( ! in_array( $status, array( 'pending', 'in-progress', 'done' ), true ) ) {
                return new WP_Error( 'rest_invalid_param', __( 'Invalid task status.', 'pandatask' ), array( 'status' => 422 ) );
            }

            $data['status'] = $status;
        }

        if ( isset( $params['task_type'] ) ) {
            $task_type = sanitize_key( $params['task_type'] );

            if ( ! in_array( $task_type, array( 'task', 'bug' ), true ) ) {
                return new WP_Error( 'rest_invalid_param', __( 'Invalid task type.', 'pandatask' ), array( 'status' => 422 ) );
            }

            $data['task_type'] = $task_type;
        }

        if ( isset( $params['priority'] ) ) {
            $data['priority'] = max( 1, min( 10, absint( $params['priority'] ) ) );
        }

        foreach ( array( 'category_id', 'project_id', 'parent_task_id', 'deadline_days_after_start', 'notify_days_before', 'recurrence_interval', 'attachment_post_id' ) as $integer_field ) {
            if ( array_key_exists( $integer_field, $params ) ) {
                $data[ $integer_field ] = '' === $params[ $integer_field ] || null === $params[ $integer_field ] ? null : absint( $params[ $integer_field ] );
            }
        }

        if ( isset( $data['notify_days_before'] ) ) {
            $data['notify_days_before'] = max( 1, min( 30, $data['notify_days_before'] ) );
        }

        foreach ( array( 'archived', 'notify_deadline', 'is_recurring' ) as $boolean_field ) {
            if ( array_key_exists( $boolean_field, $params ) ) {
                $data[ $boolean_field ] = ! empty( $params[ $boolean_field ] ) ? 1 : 0;
            }
        }

        foreach ( array( 'deadline', 'start_date', 'recurrence_ends_on' ) as $date_field ) {
            if ( array_key_exists( $date_field, $params ) ) {
                $data[ $date_field ] = empty( $params[ $date_field ] ) ? null : $this->sanitizeDate( $params[ $date_field ] );

                if ( is_wp_error( $data[ $date_field ] ) ) {
                    return $data[ $date_field ];
                }
            }
        }

        if ( isset( $params['bug_url'] ) ) {
            $data['bug_url'] = esc_url_raw( $params['bug_url'] );
        }

        if ( isset( $params['recurrence_days'] ) ) {
            $data['recurrence_days'] = $this->sanitizeRecurrenceDays( $params['recurrence_days'] );
        }

        foreach ( array( 'attachment_type', 'attachment_filename' ) as $text_field ) {
            if ( isset( $params[ $text_field ] ) ) {
                $data[ $text_field ] = sanitize_text_field( $params[ $text_field ] );
            }
        }

        if ( isset( $params['attachment_url'] ) ) {
            $data['attachment_url'] = esc_url_raw( $params['attachment_url'] );
        }

        if ( isset( $params['assigned_persons'] ) ) {
            $data['assigned_persons'] = $this->parse_id_list( $params['assigned_persons'] );
        }

        if ( isset( $params['supervisor_persons'] ) ) {
            $data['supervisor_persons'] = $this->parse_id_list( $params['supervisor_persons'] );
        }

        if ( isset( $params['predecessors'] ) ) {
            $data['predecessors'] = $this->parse_id_list( $params['predecessors'] );
        }

        if ( isset( $params['recurrence_frequency'] ) || isset( $params['recurrence_frequency_val'] ) ) {
            $data['recurrence_frequency_val'] = sanitize_key( $params['recurrence_frequency'] ?? $params['recurrence_frequency_val'] );
        }

        $current_task = $task_id ? $this->task_service->getTask( $task_id ) : null;
        $attachment_validation = $this->validateAttachmentInput( $data, $current_task );

        if ( is_wp_error( $attachment_validation ) ) {
            return $attachment_validation;
        }

        return $this->normalize_task_recurrence_data( $data );
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

        return $this->buildTaskCreateData( $board_name, $public_params );
    }

    private function validateTaskReferences( $board_name, $data, $task_id = 0, $current_task = null ) {
        $board_name = sanitize_key( $board_name );

        if ( ! $board_name ) {
            return new WP_Error( 'rest_invalid_param', __( 'A valid board is required.', 'pandatask' ), array( 'status' => 422 ) );
        }

        if ( $current_task && $board_name !== $current_task->board_name ) {
            $destination_permission = $this->board_access_policy->canWriteBoard( $board_name, get_current_user_id() );

            if ( true !== $destination_permission ) {
                return $destination_permission;
            }
        }

        if ( ! empty( $data['category_id'] ) && ! $this->category_service->isCategoryOnBoard( $data['category_id'], $board_name ) ) {
            return new WP_Error( 'rest_invalid_reference', __( 'The selected category does not belong to the destination board.', 'pandatask' ), array( 'status' => 422 ) );
        }

        if ( ! empty( $data['project_id'] ) && ! $this->project_service->isProjectOnBoard( $data['project_id'], $board_name ) ) {
            return new WP_Error( 'rest_invalid_reference', __( 'The selected project does not belong to the destination board.', 'pandatask' ), array( 'status' => 422 ) );
        }

        if ( ! empty( $data['parent_task_id'] ) ) {
            $parent_task_id = (int) $data['parent_task_id'];

            if ( $parent_task_id === (int) $task_id || ! $this->task_service->isTaskOnBoard( $parent_task_id, $board_name ) ) {
                return new WP_Error( 'rest_invalid_reference', __( 'The selected parent task is invalid.', 'pandatask' ), array( 'status' => 422 ) );
            }

            if ( $task_id > 0 && $this->task_service->wouldCreateParentCycle( $task_id, $parent_task_id ) ) {
                return new WP_Error( 'rest_hierarchy_cycle', __( 'A task cannot be moved below one of its descendants.', 'pandatask' ), array( 'status' => 409 ) );
            }
        }

        foreach ( (array) ( $data['predecessors'] ?? array() ) as $predecessor_id ) {
            $predecessor_id = (int) $predecessor_id;

            if ( $predecessor_id === (int) $task_id || ! $this->task_service->isTaskOnBoard( $predecessor_id, $board_name ) ) {
                return new WP_Error( 'rest_invalid_reference', __( 'A selected predecessor is invalid.', 'pandatask' ), array( 'status' => 422 ) );
            }

            if ( $task_id > 0 && $this->task_service->wouldCreateDependencyCycle( $task_id, $predecessor_id ) ) {
                return new WP_Error( 'rest_dependency_cycle', __( 'The selected predecessor would create a dependency cycle.', 'pandatask' ), array( 'status' => 409 ) );
            }
        }

        foreach ( array( 'assigned_persons', 'supervisor_persons' ) as $user_field ) {
            foreach ( (array) ( $data[ $user_field ] ?? array() ) as $user_id ) {
                if ( ! $this->board_access_policy->isUserAllowedOnBoard( $board_name, $user_id ) ) {
                    return new WP_Error( 'rest_invalid_reference', __( 'A selected user cannot be assigned on this board.', 'pandatask' ), array( 'status' => 422 ) );
                }
            }
        }

        $start_date = $data['start_date'] ?? ( $current_task->start_date ?? null );
        $deadline = $data['deadline'] ?? ( $current_task->deadline ?? null );

        if ( $start_date && $deadline && $deadline < $start_date ) {
            return new WP_Error( 'rest_invalid_schedule', __( 'The deadline cannot be earlier than the start date.', 'pandatask' ), array( 'status' => 422 ) );
        }

        $is_recurring = array_key_exists( 'is_recurring', $data ) ? ! empty( $data['is_recurring'] ) : ! empty( $current_task->is_recurring ?? false );
        $recurrence_frequency = $data['recurrence_frequency'] ?? ( $current_task->recurrence_frequency ?? '' );
        $recurrence_days = $data['recurrence_days'] ?? ( $current_task->recurrence_days ?? '' );

        if ( $is_recurring && 'custom_weekly' === $recurrence_frequency && ! $recurrence_days ) {
            return new WP_Error( 'rest_invalid_recurrence', __( 'Custom weekly recurrence requires at least one weekday.', 'pandatask' ), array( 'status' => 422 ) );
        }

        return true;
    }

    private function validateAttachmentInput( $data, $current_task = null ) {
        if ( ! array_key_exists( 'attachment_type', $data ) && ! array_key_exists( 'attachment_post_id', $data ) ) {
            return true;
        }

        $attachment_type = sanitize_key( $data['attachment_type'] ?? ( $current_task->attachment_type ?? '' ) );
        $attachment_post_id = absint( $data['attachment_post_id'] ?? ( $current_task->attachment_post_id ?? 0 ) );

        if ( ! in_array( $attachment_type, array( '', 'file', 'link' ), true ) ) {
            return new WP_Error( 'rest_invalid_attachment', __( 'Invalid attachment type.', 'pandatask' ), array( 'status' => 422 ) );
        }

        if ( '' === $attachment_type ) {
            return $attachment_post_id > 0
                ? new WP_Error( 'rest_invalid_attachment', __( 'An attachment ID requires the file attachment type.', 'pandatask' ), array( 'status' => 422 ) )
                : true;
        }

        if ( 'link' === $attachment_type ) {
            return true;
        }

        if ( $attachment_post_id <= 0 || 'attachment' !== get_post_type( $attachment_post_id ) ) {
            return new WP_Error( 'rest_invalid_attachment', __( 'A valid Media Library attachment is required.', 'pandatask' ), array( 'status' => 422 ) );
        }

        if ( $current_task && (int) $current_task->attachment_post_id === $attachment_post_id ) {
            return true;
        }

        if ( ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $attachment_post_id ) ) {
            return new WP_Error( 'rest_forbidden_attachment', __( 'You cannot attach this Media Library item.', 'pandatask' ), array( 'status' => 403 ) );
        }

        return true;
    }

    private function sanitizeDate( $value ) {
        $value = sanitize_text_field( $value );
        $date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );

        if ( ! $date || $date->format( 'Y-m-d' ) !== $value ) {
            return new WP_Error( 'rest_invalid_date', __( 'Dates must use the YYYY-MM-DD format.', 'pandatask' ), array( 'status' => 422 ) );
        }

        return $value;
    }

    private function sanitizeRecurrenceDays( $value ) {
        $values = is_array( $value ) ? $value : explode( ',', (string) $value );
        $days = array_values( array_unique( array_filter( array_map( 'absint', $values ), static function ( $day ) {
            return $day >= 1 && $day <= 7;
        } ) ) );
        sort( $days );

        return implode( ',', $days );
    }

    private function normalize_task_recurrence_data( $data ) {
        if ( ! isset( $data['is_recurring'] ) && ! isset( $data['recurrence_frequency'] ) && ! isset( $data['recurrence_frequency_val'] ) ) {
            return $data;
        }

        $freq_val = $data['recurrence_frequency'] ?? ( $data['recurrence_frequency_val'] ?? 'weekly' );

        switch ( $freq_val ) {
            case 'weekly':
                $data['recurrence_frequency'] = 'weekly';
                $data['recurrence_interval']  = 1;
                break;

            case 'bi-weekly':
                $data['recurrence_frequency'] = 'weekly';
                $data['recurrence_interval']  = 2;
                break;

            case 'monthly':
                $data['recurrence_frequency'] = 'monthly';
                $data['recurrence_interval']  = 1;
                break;

            case 'custom_weekly':
                $data['recurrence_frequency'] = 'custom_weekly';
                $data['recurrence_interval']  = 1;
                break;

            default:
                return new WP_Error( 'rest_invalid_recurrence', __( 'Invalid recurrence frequency.', 'pandatask' ), array( 'status' => 422 ) );
        }

        return $data;
    }
}
