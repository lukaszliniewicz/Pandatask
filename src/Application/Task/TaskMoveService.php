<?php

namespace Pandatask\Application\Task;

use Pandatask\Application\Security\BoardAccessPolicy;
use Pandatask\Application\Security\TaskAccessPolicy;
use Pandatask\Infrastructure\Persistence\CategoryRepository;
use Pandatask\Infrastructure\Persistence\ProjectRepository;
use Pandatask\Infrastructure\Persistence\TaskRepository;
use WP_Error;

/**
 * Owns cross-board task movement so UI, REST and agents share one explicit
 * compatibility policy instead of recreating tasks or guessing which fields survive.
 */
final class TaskMoveService {

    private $task_service;
    private $task_repository;
    private $mutation_service;
    private $task_access_policy;
    private $board_access_policy;
    private $project_repository;
    private $category_repository;

    public function __construct( $task_service = null, $task_repository = null, $mutation_service = null, $task_access_policy = null, $board_access_policy = null, $project_repository = null, $category_repository = null ) {
        $this->task_service = $task_service ?: new TaskService();
        $this->task_repository = $task_repository ?: new TaskRepository();
        $this->mutation_service = $mutation_service ?: new TaskMutationService();
        $this->board_access_policy = $board_access_policy ?: new BoardAccessPolicy();
        $this->task_access_policy = $task_access_policy ?: new TaskAccessPolicy( $this->task_service, $this->board_access_policy );
        $this->project_repository = $project_repository ?: new ProjectRepository();
        $this->category_repository = $category_repository ?: new CategoryRepository();
    }

    public function preview( $task_id, $destination_board, array $input, $actor_id ) {
        return $this->buildPlan( $task_id, $destination_board, $input, $actor_id );
    }

    public function move( $task_id, $destination_board, array $input, $actor_id ) {
        $plan = $this->buildPlan( $task_id, $destination_board, $input, $actor_id );
        if ( is_wp_error( $plan ) ) {
            return $plan;
        }
        if ( empty( $plan['can_move'] ) ) {
            return new WP_Error(
                'pandatask_move_incompatible',
                __( 'The task has board-scoped values that are incompatible with the destination. Review the move preview or use reset_incompatible.', 'pandatask' ),
                array( 'status' => 409, 'plan' => $plan )
            );
        }

        $reason = sanitize_textarea_field( $input['change_comment'] ?? '' );
        $result = $this->mutation_service->updateTask(
            (int) $task_id,
            $plan['update'],
            $reason ?: __( 'Task moved between boards.', 'pandatask' ),
            (int) $actor_id
        );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        if ( true !== $result ) {
            return new WP_Error( 'pandatask_move_failed', __( 'The task could not be moved.', 'pandatask' ), array( 'status' => 500 ) );
        }

        return array(
            'plan' => $plan,
            'task' => $this->task_service->getTask( (int) $task_id ),
        );
    }

    private function buildPlan( $task_id, $destination_board, array $input, $actor_id ) {
        $task_id = (int) $task_id;
        $actor_id = (int) $actor_id;
        $destination_board = sanitize_key( $destination_board );
        $task = $this->task_service->getTaskForAuthorization( $task_id );
        if ( ! $task ) {
            return new WP_Error( 'rest_task_not_found', __( 'Task not found.', 'pandatask' ), array( 'status' => 404 ) );
        }
        if ( '' === $destination_board ) {
            return new WP_Error( 'rest_invalid_param', __( 'A destination board is required.', 'pandatask' ), array( 'status' => 422 ) );
        }

        $source_permission = $this->task_access_policy->canMoveTask( $task_id, $actor_id );
        if ( true !== $source_permission ) {
            return $source_permission;
        }
        $destination_permission = $this->board_access_policy->canWriteBoard( $destination_board, $actor_id );
        if ( true !== $destination_permission ) {
            return $destination_permission;
        }

        if ( $destination_board === $task->board_name ) {
            return array(
                'can_move' => true,
                'noop' => true,
                'source_board' => $task->board_name,
                'destination_board' => $destination_board,
                'mode' => sanitize_key( $input['mode'] ?? 'strict' ),
                'incompatibilities' => array(),
                'preserved' => array( 'task_id', 'history', 'comments', 'attachments', 'work', 'follow_up_lineage' ),
                'update' => array(),
            );
        }

        $mode = sanitize_key( $input['mode'] ?? 'strict' );
        if ( ! in_array( $mode, array( 'strict', 'reset_incompatible' ), true ) ) {
            return new WP_Error( 'rest_invalid_param', __( 'Move mode must be strict or reset_incompatible.', 'pandatask' ), array( 'status' => 422 ) );
        }

        $descendants = $this->task_repository->findDescendantProjectRecords( $task_id, $task->board_name );
        $incompatibilities = array();
        if ( ! empty( $descendants ) ) {
            $incompatibilities['subtasks'] = array(
                'reason' => 'has_descendants',
                'task_ids' => array_values( array_map( 'intval', wp_list_pluck( $descendants, 'id' ) ) ),
                'action' => 'move_or_detach_subtasks_first',
            );
        }

        $current_predecessors = array_values( array_map( 'intval', (array) ( $task->predecessor_ids ?? array() ) ) );
        $fields = array(
            'project_id' => array_key_exists( 'project_id', $input ) ? absint( $input['project_id'] ) : absint( $task->project_id ?? 0 ),
            'category_id' => array_key_exists( 'category_id', $input ) ? absint( $input['category_id'] ) : absint( $task->category_id ?? 0 ),
            'parent_task_id' => array_key_exists( 'parent_task_id', $input ) ? absint( $input['parent_task_id'] ) : absint( $task->parent_task_id ?? 0 ),
            'predecessors' => array_key_exists( 'predecessors', $input ) ? $this->ids( $input['predecessors'] ) : $current_predecessors,
            'assigned_persons' => array_key_exists( 'assigned_persons', $input ) ? $this->ids( $input['assigned_persons'] ) : $this->ids( $task->assigned_user_ids ?? array() ),
            'supervisor_persons' => array_key_exists( 'supervisor_persons', $input ) ? $this->ids( $input['supervisor_persons'] ) : $this->ids( $task->supervisor_user_ids ?? array() ),
        );

        if ( $fields['project_id'] > 0 && ! $this->project_repository->existsOnBoard( $fields['project_id'], $destination_board ) ) {
            $incompatibilities['project_id'] = array( 'value' => $fields['project_id'], 'reason' => 'not_on_destination' );
        }
        if ( $fields['category_id'] > 0 && ! $this->category_repository->existsOnBoard( $fields['category_id'], $destination_board ) ) {
            $incompatibilities['category_id'] = array( 'value' => $fields['category_id'], 'reason' => 'not_on_destination' );
        }
        if ( $fields['parent_task_id'] > 0 && ! $this->task_repository->existsOnBoard( $fields['parent_task_id'], $destination_board ) ) {
            $incompatibilities['parent_task_id'] = array( 'value' => $fields['parent_task_id'], 'reason' => 'not_on_destination' );
        }

        $valid_predecessors = array();
        foreach ( $fields['predecessors'] as $predecessor_id ) {
            if ( $this->task_repository->existsOnBoard( $predecessor_id, $destination_board ) ) {
                $valid_predecessors[] = $predecessor_id;
            }
        }
        $invalid_predecessors = array_values( array_diff( $fields['predecessors'], $valid_predecessors ) );
        if ( $invalid_predecessors ) {
            $incompatibilities['predecessors'] = array( 'values' => $invalid_predecessors, 'reason' => 'not_on_destination' );
        }

        foreach ( array( 'assigned_persons', 'supervisor_persons' ) as $role_field ) {
            $valid = array();
            foreach ( $fields[ $role_field ] as $user_id ) {
                if ( $this->board_access_policy->isUserAllowedOnBoard( $destination_board, $user_id ) ) {
                    $valid[] = $user_id;
                }
            }
            $invalid = array_values( array_diff( $fields[ $role_field ], $valid ) );
            if ( $invalid ) {
                $incompatibilities[ $role_field ] = array( 'values' => $invalid, 'reason' => 'user_not_allowed_on_destination' );
            }
            $fields[ 'valid_' . $role_field ] = $valid;
        }

        $update = array( 'board_name' => $destination_board );
        if ( 'reset_incompatible' === $mode ) {
            $update['project_id'] = isset( $incompatibilities['project_id'] ) ? null : ( $fields['project_id'] ?: null );
            $update['category_id'] = isset( $incompatibilities['category_id'] ) ? null : ( $fields['category_id'] ?: null );
            $update['parent_task_id'] = isset( $incompatibilities['parent_task_id'] ) ? null : ( $fields['parent_task_id'] ?: null );
            $update['predecessors'] = $valid_predecessors;
            $update['assigned_persons'] = $fields['valid_assigned_persons'];
            $update['supervisor_persons'] = $fields['valid_supervisor_persons'];
        } else {
            $update['project_id'] = $fields['project_id'] ?: null;
            $update['category_id'] = $fields['category_id'] ?: null;
            $update['parent_task_id'] = $fields['parent_task_id'] ?: null;
            $update['predecessors'] = $fields['predecessors'];
            $update['assigned_persons'] = $fields['assigned_persons'];
            $update['supervisor_persons'] = $fields['supervisor_persons'];
        }

        if ( preg_match( '/^user_(\d+)$/', $destination_board, $matches ) ) {
            $owner_id = (int) $matches[1];
            if ( $owner_id > 0 && ! in_array( $owner_id, $update['assigned_persons'], true ) ) {
                $update['assigned_persons'][] = $owner_id;
            }
        }

        if ( ! empty( $task->inbox_state ) ) {
            $update['inbox_state'] = null;
        }

        $blocking = $incompatibilities;
        if ( 'reset_incompatible' === $mode ) {
            unset(
                $blocking['project_id'],
                $blocking['category_id'],
                $blocking['parent_task_id'],
                $blocking['predecessors'],
                $blocking['assigned_persons'],
                $blocking['supervisor_persons']
            );
        }

        return array(
            'can_move' => empty( $blocking ),
            'noop' => false,
            'source_board' => $task->board_name,
            'destination_board' => $destination_board,
            'mode' => $mode,
            'incompatibilities' => $incompatibilities,
            'preserved' => array( 'task_id', 'history', 'comments', 'attachments', 'work', 'capture_provenance', 'follow_up_lineage' ),
            'update' => $update,
        );
    }

    private function ids( $values ) {
        return array_values( array_unique( array_filter( array_map( 'absint', (array) $values ) ) ) );
    }
}
