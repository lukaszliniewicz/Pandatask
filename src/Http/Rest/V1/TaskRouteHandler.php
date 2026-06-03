<?php

namespace Pandatask\Http\Rest\V1;

use Exception;
use Pandatask\Application\Task\HistoryService;
use Pandatask\Application\Task\TaskService;
use Pandatask\Http\Rest\V1\Support\RequestHelper;
use WP_Error;
use WP_REST_Response;

final class TaskRouteHandler {

    private $task_service;

    private $history_service;

    public function __construct( $task_service = null, $history_service = null ) {
        $this->task_service    = $task_service ?: new TaskService();
        $this->history_service = $history_service ?: new HistoryService();
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

            $tasks = $this->task_service->getTasksForUserAcrossBoards( $board_user_id, $search, $sort_by, $sort_order, $status_filter, $archived, $project_filter, $private_only, $include_templates );
        } else {
            $date_filter    = '';
            $start_date     = '';
            $end_date       = '';
            $filter_user_id = null;

            if ( isset( $params['assigned_to_me'] ) && 'true' === $params['assigned_to_me'] && is_user_logged_in() ) {
                $filter_user_id = get_current_user_id();
            }

            $tasks = $this->task_service->getTasks( $board_name, $search, $sort_by, $sort_order, $status_filter, $date_filter, $start_date, $end_date, $archived, $project_filter, $include_templates, $task_type_filter, $filter_user_id );
        }

        RequestHelper::renderTaskCollection( $tasks );

        return new WP_REST_Response( array( 'tasks' => $tasks ), 200 );
    }

    public function create_task( $request ) {
        $params = RequestHelper::bodyParams( $request );

        if ( empty( $params['name'] ) ) {
            return new WP_Error( 'rest_missing', 'Name is required', array( 'status' => 400 ) );
        }

        $data    = $this->buildTaskCreateData( $request['board_name'], $params );
        $task_id = $this->task_service->createTask( $data );

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
        $data           = $this->buildTaskUpdateData( $params );
        $change_comment = $params['change_comment'] ?? '';
        $result         = $this->task_service->updateTask( $id, $data, $change_comment, get_current_user_id() );

        if ( $result ) {
            $task = RequestHelper::renderTask( $this->task_service->getTask( $id ) );

            return new WP_REST_Response( array( 'message' => 'Task updated', 'task' => $task ), 200 );
        }

        return new WP_REST_Response( array( 'message' => 'No changes or update failed' ), 200 );
    }

    public function delete_task( $request ) {
        $result = $this->task_service->deleteTask( (int) $request['id'], $request['delete_scope'] ?? null );

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

        $task_id = $this->task_service->createTask( $this->buildTaskCreateData( $board_name, $data ) );

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
        $update_succeeded = $this->task_service->updateTask( $task_id, $this->buildTaskUpdateData( $data ), $change_comment, get_current_user_id() );

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

        if ( ! $this->task_service->deleteTask( (int) $data['id'], $data['delete_scope'] ?? null ) ) {
            return new WP_Error( 'rest_error', 'Delete failed', array( 'status' => 500 ) );
        }

        return array( 'message' => 'Task deleted' );
    }

    private function parse_id_list( $input ) {
        return RequestHelper::parseIdList( $input );
    }

    private function buildTaskCreateData( $board_name, $params ) {
        $data = array(
            'board_name'                => $board_name,
            'name'                      => sanitize_text_field( $params['name'] ),
            'status'                    => sanitize_text_field( $params['status'] ?? 'pending' ),
            'priority'                  => absint( $params['priority'] ?? 5 ),
            'description'               => isset( $params['description'] ) ? wp_kses_post( $params['description'] ) : '',
            'assigned_persons'          => $this->parse_id_list( $params['assigned_persons'] ?? '' ),
            'supervisor_persons'        => $this->parse_id_list( $params['supervisor_persons'] ?? '' ),
            'category_id'               => ! empty( $params['category_id'] ) ? absint( $params['category_id'] ) : null,
            'project_id'                => ! empty( $params['project_id'] ) ? absint( $params['project_id'] ) : null,
            'parent_task_id'            => ! empty( $params['parent_task_id'] ) ? absint( $params['parent_task_id'] ) : null,
            'deadline'                  => ! empty( $params['deadline'] ) ? sanitize_text_field( $params['deadline'] ) : null,
            'start_date'                => ! empty( $params['start_date'] ) ? sanitize_text_field( $params['start_date'] ) : null,
            'deadline_days_after_start' => ! empty( $params['deadline_days_after_start'] ) ? absint( $params['deadline_days_after_start'] ) : null,
            'is_recurring'              => ! empty( $params['is_recurring'] ) ? 1 : 0,
            'recurrence_frequency_val'  => $params['recurrence_frequency'] ?? ( $params['recurrence_frequency_val'] ?? 'weekly' ),
            'recurrence_days'           => isset( $params['recurrence_days'] ) ? sanitize_text_field( $params['recurrence_days'] ) : '',
            'recurrence_ends_on'        => ! empty( $params['recurrence_ends_on'] ) ? sanitize_text_field( $params['recurrence_ends_on'] ) : null,
            'attachment_type'           => isset( $params['attachment_type'] ) ? sanitize_text_field( $params['attachment_type'] ) : '',
            'attachment_url'            => isset( $params['attachment_url'] ) ? esc_url_raw( $params['attachment_url'] ) : '',
            'attachment_post_id'        => isset( $params['attachment_post_id'] ) ? absint( $params['attachment_post_id'] ) : 0,
            'attachment_filename'       => isset( $params['attachment_filename'] ) ? sanitize_text_field( $params['attachment_filename'] ) : '',
            'task_type'                 => isset( $params['task_type'] ) ? sanitize_key( $params['task_type'] ) : '',
            'bug_url'                   => isset( $params['bug_url'] ) ? esc_url_raw( $params['bug_url'] ) : '',
            'predecessors'              => isset( $params['predecessors'] ) ? $this->parse_id_list( $params['predecessors'] ) : array(),
        );

        if ( empty( $data['assigned_persons'] ) && ! empty( $params['default_assignee_id'] ) ) {
            $data['assigned_persons'] = array( absint( $params['default_assignee_id'] ) );
        }

        return $this->normalize_task_recurrence_data( $data );
    }

    private function buildTaskUpdateData( $params ) {
        $data = $params;

        if ( isset( $params['board_name'] ) ) {
            $data['board_name'] = sanitize_key( $params['board_name'] );
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

        return $this->normalize_task_recurrence_data( $data );
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
        }

        return $data;
    }
}
