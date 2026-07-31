<?php

namespace Pandatask\Application\Task;

use DateInterval;
use DateTimeImmutable;
use Exception;
use Pandatask\Application\Security\BoardAccessPolicy;
use Pandatask\Domain\Task\TaskGraph;
use Pandatask\Infrastructure\Persistence\CategoryRepository;
use Pandatask\Infrastructure\Persistence\ProjectRepository;
use Pandatask\Infrastructure\Persistence\TaskRepository;
use WP_Error;

/**
 * Enforces task-domain rules independently of any HTTP adapter.
 */
final class TaskInvariantService {

    private $task_repository;

    private $category_repository;

    private $project_repository;

    private $board_access_policy;

    public function __construct( $task_repository = null, $category_repository = null, $project_repository = null, $board_access_policy = null ) {
        $this->task_repository     = $task_repository ?: new TaskRepository();
        $this->category_repository = $category_repository ?: new CategoryRepository();
        $this->project_repository  = $project_repository ?: new ProjectRepository();
        $this->board_access_policy = $board_access_policy ?: new BoardAccessPolicy();
    }

    /**
     * Apply inheritance and validate the effective task state.
     *
     * @param array<string,mixed> $data Patch or create data.
     * @param object|null         $current_task Existing task for an update.
     * @return array<string,mixed>|WP_Error
     */
    public function applyAndValidate( array $data, $current_task = null ) {
        $data = $this->normalizeCoreFields( $data, $current_task );

        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $board_name = sanitize_key( $data['board_name'] ?? ( $current_task->board_name ?? '' ) );

        if ( '' === $board_name ) {
            return new WP_Error(
                'rest_invalid_param',
                __( 'A valid board is required.', 'pandatask' ),
                array( 'status' => 422 )
            );
        }

        if ( ! $current_task || array_key_exists( 'board_name', $data ) ) {
            $data['board_name'] = $board_name;
        }
        $task_id = (int) ( $current_task->id ?? 0 );
        $board_is_changing = $current_task && $board_name !== $current_task->board_name;
        $parent_task_id = array_key_exists( 'parent_task_id', $data )
            ? (int) $data['parent_task_id']
            : (int) ( $current_task->parent_task_id ?? 0 );

        if ( $parent_task_id > 0 ) {
            $parents = $this->task_repository->findBoardTaskRecordsByIds( $board_name, array( $parent_task_id ) );
            $parent = $parents[ $parent_task_id ] ?? null;

            if ( ! $parent || $parent_task_id === $task_id ) {
                return new WP_Error(
                    'rest_invalid_reference',
                    __( 'The selected parent task is invalid for this board.', 'pandatask' ),
                    array( 'status' => 422 )
                );
            }

            if ( $task_id > 0 && $this->task_repository->wouldCreateParentCycle( $task_id, $parent_task_id, $board_name ) ) {
                return new WP_Error(
                    'rest_hierarchy_cycle',
                    __( 'A task cannot be moved below one of its descendants.', 'pandatask' ),
                    array( 'status' => 409 )
                );
            }

            $data['project_id'] = $parent->project_id ?: null;
        }

        $category_id = array_key_exists( 'category_id', $data )
            ? (int) $data['category_id']
            : (int) ( $current_task->category_id ?? 0 );

        if ( $category_id > 0 && ! $this->category_repository->existsOnBoard( $category_id, $board_name ) ) {
            return new WP_Error(
                'rest_invalid_reference',
                __( 'The selected category does not belong to the destination board.', 'pandatask' ),
                array( 'status' => 422 )
            );
        }

        $project_id = array_key_exists( 'project_id', $data )
            ? (int) $data['project_id']
            : (int) ( $current_task->project_id ?? 0 );

        if ( $project_id > 0 && ! $this->project_repository->existsOnBoard( $project_id, $board_name ) ) {
            return new WP_Error(
                'rest_invalid_reference',
                __( 'The selected project does not belong to the destination board.', 'pandatask' ),
                array( 'status' => 422 )
            );
        }

        $validate_predecessors = array_key_exists( 'predecessors', $data ) || $board_is_changing || ! $current_task;
        $predecessor_ids = $validate_predecessors
            ? $this->normalizeIds( $data['predecessors'] ?? ( $current_task->predecessor_ids ?? array() ) )
            : array();

        if ( $validate_predecessors ) {
            $predecessors = $this->task_repository->findBoardTaskRecordsByIds( $board_name, $predecessor_ids );

            if ( count( $predecessors ) !== count( $predecessor_ids ) || ( $task_id > 0 && in_array( $task_id, $predecessor_ids, true ) ) ) {
                return new WP_Error(
                    'rest_invalid_reference',
                    __( 'A selected predecessor is invalid for this board.', 'pandatask' ),
                    array( 'status' => 422 )
                );
            }

            if ( $task_id > 0 ) {
                $graph = $this->task_repository->findDependencyGraphForBoard( $board_name );
                $graph[ $task_id ] = $predecessor_ids;

                if ( in_array( $task_id, TaskGraph::findCycleNodes( $graph ), true ) ) {
                    return new WP_Error(
                        'rest_dependency_cycle',
                        __( 'The selected predecessors would create a dependency cycle.', 'pandatask' ),
                        array( 'status' => 409 )
                    );
                }
            }

            $data['predecessors'] = $predecessor_ids;
            $effective_status = (string) ( $data['status'] ?? ( $current_task->status ?? 'pending' ) );

            if ( 'pending' !== $effective_status && $this->task_repository->findIncompletePredecessorIds( $predecessor_ids ) ) {
                return new WP_Error(
                    'pandatask_task_blocked',
                    __( 'A task with incomplete predecessors must remain pending.', 'pandatask' ),
                    array( 'status' => 409 )
                );
            }
        }

        foreach ( array( 'assigned_persons', 'supervisor_persons' ) as $user_field ) {
            $should_validate = array_key_exists( $user_field, $data ) || $board_is_changing;

            if ( ! $should_validate ) {
                continue;
            }

            $user_ids = $this->normalizeIds( $data[ $user_field ] ?? ( 'assigned_persons' === $user_field
                ? ( $current_task->assigned_user_ids ?? array() )
                : ( $current_task->supervisor_user_ids ?? array() ) ) );

            foreach ( $user_ids as $user_id ) {
                if ( ! $this->board_access_policy->isUserAllowedOnBoard( $board_name, $user_id ) ) {
                    return new WP_Error(
                        'rest_invalid_reference',
                        __( 'A selected user cannot be assigned on this board.', 'pandatask' ),
                        array( 'status' => 422 )
                    );
                }
            }

            $data[ $user_field ] = $user_ids;
        }

        $start_date = array_key_exists( 'start_date', $data )
            ? $data['start_date']
            : ( $current_task->start_date ?? null );
        $deadline = array_key_exists( 'deadline', $data )
            ? $data['deadline']
            : ( $current_task->deadline ?? null );
        $duration = array_key_exists( 'deadline_days_after_start', $data )
            ? (int) $data['deadline_days_after_start']
            : (int) ( $current_task->deadline_days_after_start ?? 0 );

        if ( $duration > 0 && $start_date ) {
            try {
                $deadline = ( new DateTimeImmutable( $start_date, wp_timezone() ) )
                    ->add( new DateInterval( 'P' . $duration . 'D' ) )
                    ->format( 'Y-m-d' );
            } catch ( Exception $exception ) {
                return new WP_Error(
                    'rest_invalid_schedule',
                    __( 'The task schedule is invalid.', 'pandatask' ),
                    array( 'status' => 422 )
                );
            }
        }

        if ( $start_date && $deadline && $deadline < $start_date ) {
            return new WP_Error(
                'rest_invalid_schedule',
                __( 'The deadline cannot be earlier than the start date.', 'pandatask' ),
                array( 'status' => 422 )
            );
        }

        $is_recurring = array_key_exists( 'is_recurring', $data )
            ? ! empty( $data['is_recurring'] )
            : ! empty( $current_task->is_recurring ?? false );

        if ( $is_recurring ) {
            $frequency = (string) ( $data['recurrence_frequency'] ?? ( $current_task->recurrence_frequency ?? '' ) );
            $interval = (int) ( $data['recurrence_interval'] ?? ( $current_task->recurrence_interval ?? 0 ) );
            $days = (string) ( $data['recurrence_days'] ?? ( $current_task->recurrence_days ?? '' ) );
            $ends_on = $data['recurrence_ends_on'] ?? ( $current_task->recurrence_ends_on ?? null );

            if ( ! $start_date || ! $deadline ) {
                return new WP_Error(
                    'rest_invalid_recurrence',
                    __( 'Recurring tasks require both a start date and a deadline.', 'pandatask' ),
                    array( 'status' => 422 )
                );
            }

            if ( ! in_array( $frequency, array( 'weekly', 'monthly', 'custom_weekly' ), true ) || $interval < 1 ) {
                return new WP_Error(
                    'rest_invalid_recurrence',
                    __( 'The recurrence rule is invalid.', 'pandatask' ),
                    array( 'status' => 422 )
                );
            }

            if ( 'custom_weekly' === $frequency && '' === $days ) {
                return new WP_Error(
                    'rest_invalid_recurrence',
                    __( 'Custom weekly recurrence requires at least one weekday.', 'pandatask' ),
                    array( 'status' => 422 )
                );
            }

            if ( 'custom_weekly' === $frequency ) {
                $data['recurrence_days'] = $this->normalizeRecurrenceDays( $days );
            } elseif ( array_key_exists( 'recurrence_days', $data ) || 'custom_weekly' === ( $current_task->recurrence_frequency ?? '' ) ) {
                $data['recurrence_days'] = null;
            }

            if ( $ends_on && $ends_on < $start_date ) {
                return new WP_Error(
                    'rest_invalid_recurrence',
                    __( 'Recurrence cannot end before the first occurrence.', 'pandatask' ),
                    array( 'status' => 422 )
                );
            }
        }

        return $data;
    }

    /**
     * Protect non-HTTP callers from bypassing the same basic command contract.
     *
     * @return array<string,mixed>|WP_Error
     */
    private function normalizeCoreFields( array $data, $current_task ) {
        if ( ! $current_task ) {
            $data = array_merge(
                array(
                    'name'               => '',
                    'description'        => '',
                    'status'             => 'pending',
                    'priority'           => 5,
                    'task_type'          => 'task',
                    'assigned_persons'   => array(),
                    'supervisor_persons' => array(),
                    'predecessors'       => array(),
                ),
                $data
            );
        }

        if ( array_key_exists( 'name', $data ) ) {
            $data['name'] = sanitize_text_field( $data['name'] );

            if ( '' === $data['name'] ) {
                return new WP_Error( 'rest_invalid_param', __( 'Task name cannot be empty.', 'pandatask' ), array( 'status' => 422 ) );
            }
        }

        if ( array_key_exists( 'description', $data ) ) {
            $data['description'] = wp_kses_post( $data['description'] );
        }

        if ( array_key_exists( 'bug_url', $data ) ) {
            $data['bug_url'] = esc_url_raw( $data['bug_url'] );
        }

        $status = (string) ( $data['status'] ?? ( $current_task->status ?? 'pending' ) );

        if ( ! in_array( $status, array( 'pending', 'in-progress', 'done' ), true ) ) {
            return new WP_Error( 'rest_invalid_param', __( 'Invalid task status.', 'pandatask' ), array( 'status' => 422 ) );
        }

        $task_type = (string) ( $data['task_type'] ?? ( $current_task->task_type ?? 'task' ) );

        if ( ! in_array( $task_type, array( 'task', 'bug' ), true ) ) {
            return new WP_Error( 'rest_invalid_param', __( 'Invalid task type.', 'pandatask' ), array( 'status' => 422 ) );
        }

        if ( array_key_exists( 'priority', $data ) ) {
            $data['priority'] = max( 1, min( 10, (int) $data['priority'] ) );
        }

        foreach ( array( 'assigned_persons', 'supervisor_persons', 'predecessors' ) as $id_field ) {
            if ( array_key_exists( $id_field, $data ) ) {
                $data[ $id_field ] = $this->normalizeIds( $data[ $id_field ] );
            }
        }

        foreach ( array( 'archived', 'notify_deadline', 'is_recurring', 'missed_deadline_notified' ) as $boolean_field ) {
            if ( array_key_exists( $boolean_field, $data ) ) {
                $data[ $boolean_field ] = rest_sanitize_boolean( $data[ $boolean_field ] ) ? 1 : 0;
            }
        }

        if ( array_key_exists( 'notify_days_before', $data ) ) {
            $data['notify_days_before'] = max( 1, min( 30, absint( $data['notify_days_before'] ) ) );
        }

        if ( array_key_exists( 'recurrence_frequency', $data ) ) {
            $data['recurrence_frequency'] = sanitize_key( $data['recurrence_frequency'] );
        }

        if ( array_key_exists( 'recurrence_interval', $data ) ) {
            $data['recurrence_interval'] = absint( $data['recurrence_interval'] );
        }

        if ( array_key_exists( 'recurrence_days', $data ) ) {
            $data['recurrence_days'] = $this->normalizeRecurrenceDays( $data['recurrence_days'] );
        }

        foreach ( array( 'start_date', 'deadline', 'recurrence_ends_on' ) as $date_field ) {
            if ( ! array_key_exists( $date_field, $data ) || null === $data[ $date_field ] || '' === $data[ $date_field ] ) {
                continue;
            }

            $value = sanitize_text_field( $data[ $date_field ] );
            $date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );
            $minimum = (string) apply_filters( 'pandatask_minimum_task_date', '1900-01-01' );
            $maximum = (string) apply_filters( 'pandatask_maximum_task_date', '2200-12-31' );

            if ( ! $date || $date->format( 'Y-m-d' ) !== $value || $value < $minimum || $value > $maximum ) {
                return new WP_Error( 'rest_invalid_date', __( 'Dates must use a supported YYYY-MM-DD value.', 'pandatask' ), array( 'status' => 422 ) );
            }

            $data[ $date_field ] = $value;
        }

        $attachment_touched = ! empty(
            array_intersect(
                array( 'attachment_type', 'attachment_url', 'attachment_post_id', 'attachment_filename' ),
                array_keys( $data )
            )
        );
        $attachment_type = array_key_exists( 'attachment_type', $data )
            ? sanitize_key( $data['attachment_type'] )
            : sanitize_key( $current_task->attachment_type ?? '' );

        if ( ! in_array( $attachment_type, array( '', 'file', 'link' ), true ) ) {
            return new WP_Error( 'rest_invalid_attachment', __( 'Invalid attachment type.', 'pandatask' ), array( 'status' => 422 ) );
        }

        if ( array_key_exists( 'attachment_type', $data ) ) {
            $data['attachment_type'] = $attachment_type;
        }

        $attachment_id = array_key_exists( 'attachment_post_id', $data )
            ? absint( $data['attachment_post_id'] )
            : absint( $current_task->attachment_post_id ?? 0 );
        $attachment_url = array_key_exists( 'attachment_url', $data )
            ? esc_url_raw( $data['attachment_url'] )
            : esc_url_raw( $current_task->attachment_url ?? '' );

        if ( 'file' === $attachment_type && ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) ) {
            return new WP_Error( 'rest_invalid_attachment', __( 'A valid Media Library attachment is required.', 'pandatask' ), array( 'status' => 422 ) );
        }

        if ( 'link' === $attachment_type && '' === $attachment_url ) {
            return new WP_Error( 'rest_invalid_attachment', __( 'A link attachment requires a valid URL.', 'pandatask' ), array( 'status' => 422 ) );
        }

        if ( $attachment_touched ) {
            if ( 'file' === $attachment_type ) {
                $data['attachment_url'] = null;
            } elseif ( 'link' === $attachment_type ) {
                $data['attachment_post_id'] = null;
            } else {
                $data['attachment_url'] = null;
                $data['attachment_post_id'] = null;
                $data['attachment_filename'] = null;
            }
        }

        return $data;
    }

    /**
     * @param mixed $values Raw IDs.
     * @return array<int,int>
     */
    private function normalizeIds( $values ): array {
        return array_values( array_unique( array_filter( array_map( 'absint', (array) $values ) ) ) );
    }

    /**
     * @param mixed $values Raw ISO weekday values.
     */
    private function normalizeRecurrenceDays( $values ): string {
        $values = is_array( $values ) ? $values : explode( ',', (string) $values );
        $days = array_values(
            array_unique(
                array_filter(
                    array_map( 'absint', $values ),
                    static function ( $day ) {
                        return $day >= 1 && $day <= 7;
                    }
                )
            )
        );
        sort( $days );

        return implode( ',', $days );
    }
}
