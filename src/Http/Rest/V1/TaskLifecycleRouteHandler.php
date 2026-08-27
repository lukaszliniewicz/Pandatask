<?php

namespace Pandatask\Http\Rest\V1;

use Pandatask\Application\Security\BoardAccessPolicy;
use Pandatask\Application\Security\TaskAccessPolicy;
use Pandatask\Application\Task\HistoryService;
use Pandatask\Application\Task\TaskMoveService;
use Pandatask\Application\Task\TaskService;
use Pandatask\Http\Rest\V1\Support\RequestHelper;
use Pandatask\Http\Rest\V1\Support\TaskInputNormalizer;
use WP_Error;
use WP_REST_Response;

final class TaskLifecycleRouteHandler {

    private $task_service;
    private $move_service;
    private $task_access_policy;
    private $board_access_policy;
    private $history_service;
    private $input_normalizer;

    public function __construct( $task_service = null, $move_service = null, $task_access_policy = null, $board_access_policy = null, $history_service = null, $input_normalizer = null ) {
        $this->task_service = $task_service ?: new TaskService();
        $this->board_access_policy = $board_access_policy ?: new BoardAccessPolicy();
        $this->task_access_policy = $task_access_policy ?: new TaskAccessPolicy( $this->task_service, $this->board_access_policy );
        $this->move_service = $move_service ?: new TaskMoveService( $this->task_service, null, null, $this->task_access_policy, $this->board_access_policy );
        $this->history_service = $history_service ?: new HistoryService();
        $this->input_normalizer = $input_normalizer ?: new TaskInputNormalizer();
    }

    public function move_preview( $request ) {
        $data = $this->body( $request );
        $destination = sanitize_key( $data['destination_board'] ?? $data['board_name'] ?? '' );
        $plan = $this->move_service->preview( (int) $request['id'], $destination, $data, get_current_user_id() );

        return is_wp_error( $plan ) ? $plan : new WP_REST_Response( array( 'plan' => $plan ), 200 );
    }

    public function move_task( $request ) {
        $data = $this->body( $request );
        $destination = sanitize_key( $data['destination_board'] ?? $data['board_name'] ?? '' );
        $result = $this->move_service->move( (int) $request['id'], $destination, $data, get_current_user_id() );

        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
    }

    public function reopen_task( $request ) {
        $data = $this->body( $request );
        $task = $this->task_service->getTaskForAuthorization( (int) $request['id'] );
        if ( ! $task ) {
            return new WP_Error( 'rest_task_not_found', __( 'Task not found.', 'pandatask' ), array( 'status' => 404 ) );
        }
        if ( 'done' !== $task->status ) {
            return new WP_Error( 'pandatask_task_not_completed', __( 'Only a completed task can be reopened.', 'pandatask' ), array( 'status' => 409 ) );
        }

        $result = $this->task_service->reopenTask(
            (int) $request['id'],
            $data['status'] ?? 'in-progress',
            $data['reason'] ?? $data['change_comment'] ?? '',
            get_current_user_id()
        );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        if ( true !== $result ) {
            return new WP_Error( 'pandatask_reopen_failed', __( 'The task could not be reopened.', 'pandatask' ), array( 'status' => 500 ) );
        }

        return new WP_REST_Response( array( 'task' => RequestHelper::renderTask( $this->task_service->getTask( (int) $request['id'] ) ) ), 200 );
    }

    public function list_follow_ups( $request ) {
        $items = array();
        foreach ( $this->task_service->getFollowUps( (int) $request['id'] ) as $task ) {
            if ( true === $this->task_access_policy->canReadTask( (int) $task->id, get_current_user_id() ) ) {
                $items[] = RequestHelper::renderTask( $task );
            }
        }

        return new WP_REST_Response( array( 'tasks' => $items ), 200 );
    }

    public function create_follow_up( $request ) {
        $source = $this->task_service->getTaskForAuthorization( (int) $request['id'] );
        if ( ! $source ) {
            return new WP_Error( 'rest_task_not_found', __( 'Source task not found.', 'pandatask' ), array( 'status' => 404 ) );
        }

        $data = $this->body( $request );
        $destination_board = sanitize_key( $data['board_name'] ?? $source->board_name );
        $destination_permission = $this->board_access_policy->canWriteBoard( $destination_board, get_current_user_id() );
        if ( true !== $destination_permission ) {
            return $destination_permission;
        }

        $same_board = $destination_board === $source->board_name;
        $defaults = array(
            'name'               => sprintf( __( 'Follow up: %s', 'pandatask' ), $source->name ),
            'description'        => '',
            'status'             => 'pending',
            'priority'           => (int) $source->priority,
            'task_type'          => 'task',
            'project_id'         => $same_board ? $source->project_id : null,
            'category_id'        => $same_board ? $source->category_id : null,
            'assigned_persons'   => $this->allowedUsersOnBoard( $destination_board, $source->assigned_user_ids ?? array() ),
            'supervisor_persons' => $this->allowedUsersOnBoard( $destination_board, $source->supervisor_user_ids ?? array() ),
            'follow_up_of_task_id' => (int) $source->id,
        );
        $params = array_merge( $defaults, $data );
        $params['follow_up_of_task_id'] = (int) $source->id;
        $params['status'] = 'done' === ( $params['status'] ?? 'pending' ) ? 'pending' : ( $params['status'] ?? 'pending' );
        $normalized = $this->input_normalizer->buildCreateData( $destination_board, $params );
        if ( is_wp_error( $normalized ) ) {
            return $normalized;
        }
        $normalized['follow_up_of_task_id'] = (int) $source->id;

        $task_id = $this->task_service->createTask( $normalized );
        if ( is_wp_error( $task_id ) ) {
            return $task_id;
        }
        if ( ! $task_id ) {
            return new WP_Error( 'pandatask_follow_up_failed', __( 'The follow-up task could not be created.', 'pandatask' ), array( 'status' => 500 ) );
        }

        $reason = sanitize_textarea_field( $data['trigger'] ?? $data['change_comment'] ?? '' );
        $this->history_service->addEntry(
            (int) $source->id,
            get_current_user_id(),
            'follow_up_created',
            '',
            (string) $task_id,
            $reason
        );
        $this->history_service->addEntry(
            (int) $task_id,
            get_current_user_id(),
            'follow_up_of_task_id',
            '',
            (string) $source->id,
            $reason
        );

        return new WP_REST_Response(
            array( 'task' => RequestHelper::renderTask( $this->task_service->getTask( $task_id ) ) ),
            201
        );
    }

    private function allowedUsersOnBoard( $board_name, $user_ids ) {
        $allowed = array();
        foreach ( array_values( array_unique( array_filter( array_map( 'absint', (array) $user_ids ) ) ) ) as $user_id ) {
            if ( $this->board_access_policy->isUserAllowedOnBoard( $board_name, $user_id ) ) {
                $allowed[] = $user_id;
            }
        }
        return $allowed;
    }

    private function body( $request ) {
        $data = $request->get_json_params();
        return is_array( $data ) ? $data : array();
    }
}
