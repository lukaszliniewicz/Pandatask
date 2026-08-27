<?php

namespace Pandatask\Application\Work;

use Exception;
use Throwable;
use Pandatask\Application\Security\BoardAccessPolicy;
use Pandatask\Application\Security\TaskAccessPolicy;
use Pandatask\Infrastructure\Persistence\DatabaseContext;
use Pandatask\Infrastructure\Persistence\TaskRepository;
use Pandatask\Infrastructure\Persistence\WorkAuditRepository;
use Pandatask\Infrastructure\Persistence\WorkEntryRepository;
use Pandatask\Infrastructure\Persistence\WorkOccurrenceRepository;
use WP_Error;

final class WorkEntryService {

    private $repository;
    private $task_repository;
    private $occurrence_repository;
    private $task_access_policy;
    private $board_access_policy;
    private $audit_repository;
    private $task_time_service;

    private $work_type_service;

    public function __construct( $repository = null, $task_repository = null, $occurrence_repository = null, $task_access_policy = null, $audit_repository = null, $task_time_service = null, $board_access_policy = null, $work_type_service = null ) {
        $this->repository            = $repository ?: new WorkEntryRepository();
        $this->task_repository       = $task_repository ?: new TaskRepository();
        $this->occurrence_repository = $occurrence_repository ?: new WorkOccurrenceRepository();
        $this->task_access_policy    = $task_access_policy ?: new TaskAccessPolicy();
        $this->board_access_policy   = $board_access_policy ?: new BoardAccessPolicy();
        $this->audit_repository      = $audit_repository ?: new WorkAuditRepository();
        $this->task_time_service     = $task_time_service ?: new TaskTimeService();
        $this->work_type_service     = $work_type_service ?: new WorkTypeService();
    }

    public function activityTypes( $user_id = null ) {
        return $this->work_type_service->all( $user_id );
    }

    public function getEntriesForUser( $user_id, $start_date = '', $end_date = '', $limit = 200, $offset = 0 ) {
        return $this->repository->findForUser( (int) $user_id, $start_date, $end_date, $limit, $offset );
    }

    public function getEntriesForTask( $task_id, $user_id = 0 ) {
        return $this->repository->findForTask( (int) $task_id, (int) $user_id );
    }

    public function getTaskAggregate( $task_id ) {
        $task = $this->task_repository->findById( (int) $task_id );
        if ( ! $task ) {
            return array(
                'direct_seconds' => 0,
                'including_subtasks_seconds' => 0,
                'descendant_count' => 0,
                'original_occurrence_seconds' => 0,
                'post_completion_seconds' => 0,
                'follow_up_task_seconds' => 0,
                'related_work_seconds' => 0,
                'follow_up_task_count' => 0,
            );
        }

        $descendants = $this->task_repository->findDescendantProjectRecords( (int) $task_id, $task->board_name );
        $descendant_ids = array_values( array_map( 'intval', wp_list_pluck( $descendants, 'id' ) ) );
        $follow_ups = $this->task_repository->findFollowUps( (int) $task_id );
        $follow_up_ids = $this->followUpLineageIds( (int) $task_id, $follow_ups );
        $original_seconds = $this->repository->allocatedSecondsForTaskIdsByContext( array( (int) $task_id ), 'occurrence' );
        $post_completion_seconds = $this->repository->allocatedSecondsForTaskIdsByContext( array( (int) $task_id ), 'post_completion' );
        $follow_up_seconds = $this->repository->allocatedSecondsForTaskIds( $follow_up_ids );

        return array(
            'direct_seconds'              => $this->repository->allocatedSecondsForTaskIds( array( (int) $task_id ) ),
            'including_subtasks_seconds'  => $this->repository->allocatedSecondsForTaskIds( array_merge( array( (int) $task_id ), $descendant_ids ) ),
            'descendant_count'            => count( $descendant_ids ),
            'original_occurrence_seconds' => $original_seconds,
            'post_completion_seconds'     => $post_completion_seconds,
            'follow_up_task_seconds'      => $follow_up_seconds,
            'related_work_seconds'        => $original_seconds + $post_completion_seconds + $follow_up_seconds,
            'follow_up_task_count'        => count( $follow_up_ids ),
        );
    }

    public function getEntry( $entry_id ) {
        return $this->repository->findById( (int) $entry_id );
    }

    public function createEntry( array $input, $actor_id = null ) {
        $actor_id = null === $actor_id ? get_current_user_id() : (int) $actor_id;
        return $this->createEntryInternal( $input, $actor_id, 'manual', null, null );
    }

    public function createSourcedEntry( array $input, $source_key, $source_url = null, $actor_id = null ) {
        $actor_id = null === $actor_id ? get_current_user_id() : (int) $actor_id;
        $source_key = sanitize_text_field( (string) $source_key );
        if ( '' === $source_key ) {
            return new WP_Error( 'rest_invalid_param', __( 'A sourced work entry requires a source key.', 'pandatask' ), array( 'status' => 422 ) );
        }

        $existing = $this->repository->findBySourceKey( $source_key );
        if ( $existing ) {
            return $existing;
        }

        return $this->createEntryInternal( $input, $actor_id, 'imported', $source_key, $source_url );
    }

    private function createEntryInternal( array $input, $actor_id, $kind, $source_key, $source_url ) {
        $normalized = $this->normalizeEntry( $input, $actor_id, $kind, $source_key, $source_url, false );
        if ( is_wp_error( $normalized ) ) {
            return $normalized;
        }

        if ( ! DatabaseContext::beginTransaction() ) {
            return new WP_Error( 'pandatask_transaction_failed', __( 'The work entry could not start a database transaction.', 'pandatask' ), array( 'status' => 500 ) );
        }

        try {
            $entry_data = $normalized['entry'];
            $allocations = $normalized['allocations'];
            $entry_id = $this->repository->insert( $entry_data );
            if ( ! $entry_id ) {
                throw new Exception( 'Work entry insert failed.' );
            }
            if ( ! $this->repository->replaceAllocations( $entry_id, $allocations ) ) {
                throw new Exception( 'Work allocation insert failed.' );
            }
            foreach ( $normalized['resolution_actions'] as $action ) {
                $resolution = $this->task_time_service->applySpecificAddition(
                    $action['occurrence_id'],
                    $normalized['entry']['user_id'],
                    $action['seconds'],
                    $action['mode'],
                    $actor_id
                );
                if ( is_wp_error( $resolution ) ) {
                    DatabaseContext::rollback();
                    return $resolution;
                }
            }
            if ( ! $this->audit_repository->record( 'work_entry', $entry_id, 'created', $actor_id, null, array( 'entry' => $entry_data, 'allocations' => $allocations ) ) ) {
                throw new Exception( 'Work entry audit failed.' );
            }
            if ( ! DatabaseContext::commit() ) {
                throw new Exception( 'Work entry commit failed.' );
            }
        } catch ( Throwable $exception ) {
            DatabaseContext::rollback();
            $existing = $source_key ? $this->repository->findBySourceKey( $source_key ) : null;
            return $existing ?: new WP_Error( 'pandatask_work_entry_failed', __( 'The work entry could not be saved.', 'pandatask' ), array( 'status' => 500 ) );
        }

        $this->invalidateScopes( $normalized['entry']['user_id'], $allocations );
        return $this->repository->findById( $entry_id );
    }

    public function updateEntry( $entry_id, array $input, $actor_id = null ) {
        $actor_id = null === $actor_id ? get_current_user_id() : (int) $actor_id;
        $current = $this->repository->findById( (int) $entry_id );
        if ( ! $current ) {
            return new WP_Error( 'rest_not_found', __( 'Work entry not found.', 'pandatask' ), array( 'status' => 404 ) );
        }
        if ( 'residual' === $current->kind ) {
            return new WP_Error( 'pandatask_residual_managed_by_resolution', __( 'Residual time is managed through the task time resolution.', 'pandatask' ), array( 'status' => 409 ) );
        }
        foreach ( (array) $current->allocations as $allocation ) {
            if ( ! empty( $allocation->occurrence_id ) && $this->task_time_service->hasResolvedState( $allocation->occurrence_id, $current->user_id ) ) {
                return new WP_Error( 'pandatask_resolved_work_locked', __( 'Reopen and re-resolve the task before editing detailed work that has already been reconciled.', 'pandatask' ), array( 'status' => 409 ) );
            }
        }
        $replaces_allocations = array_key_exists( 'allocations', $input );
        $merged = array(
            'user_id'        => $current->user_id,
            'title'          => $current->title,
            'notes'          => $current->notes,
            'activity_type'  => $current->activity_type,
            'capacity'       => $current->capacity,
            'work_date'      => $current->work_date,
            'duration_seconds' => $current->duration_seconds,
            'started_at_utc' => $current->started_at_utc,
            'ended_at_utc'   => $current->ended_at_utc,
            'timezone'       => $current->timezone,
            'visibility'     => $current->visibility,
        );

        if ( $replaces_allocations ) {
            $merged['allocations'] = $input['allocations'];
        }

        $merged_input = array_merge( $merged, $input );
        $normalized = $this->normalizeEntry(
            $merged_input,
            $actor_id,
            (string) $current->kind,
            $current->source_key ?? null,
            $current->source_url ?? null,
            (string) $merged_input['activity_type'] === (string) $current->activity_type
        );
        if ( is_wp_error( $normalized ) ) {
            return $normalized;
        }

        $old_allocations = (array) $current->allocations;

        if ( ! $replaces_allocations ) {
            $normalized['allocations'] = $this->allocationSnapshots( $old_allocations );
            $normalized['resolution_actions'] = array();

            $allocated_seconds = array_sum( array_map( 'intval', wp_list_pluck( $normalized['allocations'], 'seconds' ) ) );
            if ( $allocated_seconds > (int) $normalized['entry']['duration_seconds'] ) {
                return new WP_Error( 'pandatask_overallocated_work', __( 'Allocated time cannot exceed the work entry duration.', 'pandatask' ), array( 'status' => 422 ) );
            }
        }

        if ( ! DatabaseContext::beginTransaction() ) {
            return new WP_Error( 'pandatask_transaction_failed', __( 'The work entry could not start a database transaction.', 'pandatask' ), array( 'status' => 500 ) );
        }
        try {
            $entry_data = $normalized['entry'];
            unset( $entry_data['created_at'] );
            unset( $entry_data['created_by'] );
            $entry_data['updated_at'] = gmdate( 'Y-m-d H:i:s' );
            if ( ! $this->repository->update( $entry_id, $entry_data ) ) {
                throw new Exception( 'Work entry update failed.' );
            }
            if ( $replaces_allocations && ! $this->repository->replaceAllocations( $entry_id, $normalized['allocations'] ) ) {
                throw new Exception( 'Work allocation update failed.' );
            }
            $new_resolution_actions = $this->newOccurrenceResolutionActions( $old_allocations, $normalized['allocations'], $normalized['resolution_actions'] );

            foreach ( $new_resolution_actions as $action ) {
                $resolution = $this->task_time_service->applySpecificAddition(
                    $action['occurrence_id'],
                    $normalized['entry']['user_id'],
                    $action['seconds'],
                    $action['mode'],
                    $actor_id
                );
                if ( is_wp_error( $resolution ) ) {
                    DatabaseContext::rollback();
                    return $resolution;
                }
            }
            if ( $replaces_allocations ) {
                $reconciliation = $this->reconcileChangedOccurrences(
                    $old_allocations,
                    $normalized['allocations'],
                    $normalized['entry']['user_id'],
                    $actor_id,
                    wp_list_pluck( $new_resolution_actions, 'occurrence_id' )
                );

                if ( is_wp_error( $reconciliation ) ) {
                    DatabaseContext::rollback();
                    return $reconciliation;
                }
            }
            if ( ! $this->audit_repository->record( 'work_entry', $entry_id, 'updated', $actor_id, $current, array( 'entry' => $entry_data, 'allocations' => $normalized['allocations'] ) ) ) {
                throw new Exception( 'Work entry audit failed.' );
            }
            if ( ! DatabaseContext::commit() ) {
                throw new Exception( 'Work entry update commit failed.' );
            }
        } catch ( Throwable $exception ) {
            DatabaseContext::rollback();
            return new WP_Error( 'pandatask_work_entry_failed', __( 'The work entry could not be updated.', 'pandatask' ), array( 'status' => 500 ) );
        }
        $this->invalidateScopes( (int) $current->user_id, $old_allocations );
        $this->invalidateScopes( $normalized['entry']['user_id'], $normalized['allocations'] );
        return $this->repository->findById( $entry_id );
    }

    /** Preserve historical allocation snapshots when an edit does not replace them. */
    /** Return unique recursive follow-up lineage without traversing subtask hierarchy. */
    private function followUpLineageIds( $source_task_id, array $direct_follow_ups = array() ) {
        $seen = array( (int) $source_task_id => true );
        $queue = $direct_follow_ups ?: $this->task_repository->findFollowUps( (int) $source_task_id );
        $ids = array();

        while ( $queue ) {
            $task = array_shift( $queue );
            $task_id = (int) ( $task->id ?? 0 );
            if ( $task_id <= 0 || isset( $seen[ $task_id ] ) ) {
                continue;
            }
            $seen[ $task_id ] = true;
            $ids[] = $task_id;
            foreach ( $this->task_repository->findFollowUps( $task_id ) as $child ) {
                if ( ! isset( $seen[ (int) $child->id ] ) ) {
                    $queue[] = $child;
                }
            }
        }

        return $ids;
    }

    private function allocationSnapshots( array $allocations ) {
        $fields = array(
            'occurrence_id',
            'allocation_context',
            'seconds',
            'task_id_snapshot',
            'task_name_snapshot',
            'board_name_snapshot',
            'project_id_snapshot',
            'project_name_snapshot',
            'category_id_snapshot',
            'category_name_snapshot',
        );
        $snapshots = array();

        foreach ( $allocations as $allocation ) {
            $snapshot = array();

            foreach ( $fields as $field ) {
                $snapshot[ $field ] = is_object( $allocation )
                    ? ( $allocation->{$field} ?? null )
                    : ( $allocation[ $field ] ?? null );
            }

            $snapshot['occurrence_id'] = $snapshot['occurrence_id'] ? (int) $snapshot['occurrence_id'] : null;
            $snapshot['seconds'] = (int) $snapshot['seconds'];
            $snapshot['task_id_snapshot'] = $snapshot['task_id_snapshot'] ? (int) $snapshot['task_id_snapshot'] : null;
            $snapshot['project_id_snapshot'] = $snapshot['project_id_snapshot'] ? (int) $snapshot['project_id_snapshot'] : null;
            $snapshot['category_id_snapshot'] = $snapshot['category_id_snapshot'] ? (int) $snapshot['category_id_snapshot'] : null;
            $snapshots[] = $snapshot;
        }

        return $snapshots;
    }

    /**
     * Resolution actions generated by normalization describe new allocations. They
     * must not re-apply existing allocation time when an explicit replacement is
     * merely preserving an occurrence.
     */
    private function newOccurrenceResolutionActions( array $old_allocations, array $new_allocations, array $resolution_actions ) {
        $old_seconds = $this->allocationSecondsByOccurrence( $old_allocations );
        $new_seconds = $this->allocationSecondsByOccurrence( $new_allocations );
        $actions = array();

        foreach ( $resolution_actions as $action ) {
            $occurrence_id = (int) ( $action['occurrence_id'] ?? 0 );

            if ( $occurrence_id > 0 && empty( $old_seconds[ $occurrence_id ] ) && ! empty( $new_seconds[ $occurrence_id ] ) ) {
                $actions[] = $action;
            }
        }

        return $actions;
    }

    /**
     * Reconcile an occurrence by its durable ID after replacement removes or
     * changes detail. Calling the task-level helper here would incorrectly resolve
     * a later recurring occurrence for the same task.
     */
    private function reconcileChangedOccurrences( array $old_allocations, array $new_allocations, $user_id, $actor_id, array $already_reconciled = array() ) {
        $old_seconds = $this->allocationSecondsByOccurrence( $old_allocations );
        $new_seconds = $this->allocationSecondsByOccurrence( $new_allocations );
        $occurrence_ids = array_values( array_unique( array_merge( array_keys( $old_seconds ), array_keys( $new_seconds ) ) ) );

        foreach ( $occurrence_ids as $occurrence_id ) {
            if ( in_array( (int) $occurrence_id, array_map( 'intval', $already_reconciled ), true ) ) {
                continue;
            }

            if ( (int) ( $old_seconds[ $occurrence_id ] ?? 0 ) === (int) ( $new_seconds[ $occurrence_id ] ?? 0 ) ) {
                continue;
            }

            $summary = $this->task_time_service->getOccurrenceSummary( (int) $occurrence_id, (int) $user_id );
            $latest = $summary['resolution'] ?? null;

            if ( ! $latest || ! in_array( $latest->state, array( 'unresolved', 'resolved', 'not_tracked' ), true ) ) {
                continue;
            }

            $result = $this->task_time_service->resolveOccurrence(
                (int) $occurrence_id,
                (int) $user_id,
                null === $latest->declared_actual_seconds ? null : (int) $latest->declared_actual_seconds,
                'not_tracked' === $latest->state,
                (int) $actor_id
            );

            if ( is_wp_error( $result ) ) {
                return $result;
            }
        }

        return true;
    }

    private function allocationSecondsByOccurrence( array $allocations ) {
        $seconds_by_occurrence = array();

        foreach ( $allocations as $allocation ) {
            $occurrence_id = is_object( $allocation ) ? (int) ( $allocation->occurrence_id ?? 0 ) : (int) ( $allocation['occurrence_id'] ?? 0 );

            if ( $occurrence_id <= 0 ) {
                continue;
            }

            $seconds = is_object( $allocation ) ? (int) ( $allocation->seconds ?? 0 ) : (int) ( $allocation['seconds'] ?? 0 );
            $seconds_by_occurrence[ $occurrence_id ] = ( $seconds_by_occurrence[ $occurrence_id ] ?? 0 ) + $seconds;
        }

        return $seconds_by_occurrence;
    }

    public function deleteEntry( $entry_id, $actor_id = null ) {
        $actor_id = null === $actor_id ? get_current_user_id() : (int) $actor_id;
        $current = $this->repository->findById( (int) $entry_id );
        if ( ! $current ) {
            return new WP_Error( 'rest_not_found', __( 'Work entry not found.', 'pandatask' ), array( 'status' => 404 ) );
        }
        if ( 'residual' === $current->kind ) {
            return new WP_Error( 'pandatask_residual_managed_by_resolution', __( 'Residual time is managed through the task time resolution.', 'pandatask' ), array( 'status' => 409 ) );
        }
        foreach ( (array) $current->allocations as $allocation ) {
            if ( ! empty( $allocation->occurrence_id ) && $this->task_time_service->hasResolvedState( $allocation->occurrence_id, $current->user_id ) ) {
                return new WP_Error( 'pandatask_resolved_work_locked', __( 'Reopen and re-resolve the task before deleting detailed work that has already been reconciled.', 'pandatask' ), array( 'status' => 409 ) );
            }
        }
        if ( ! DatabaseContext::beginTransaction() ) {
            return new WP_Error( 'pandatask_transaction_failed', __( 'The work entry could not start a database transaction.', 'pandatask' ), array( 'status' => 500 ) );
        }
        try {
            if ( ! $this->repository->softDelete( $entry_id ) ) {
                throw new Exception( 'Work entry deletion failed.' );
            }
            if ( ! $this->audit_repository->record( 'work_entry', $entry_id, 'deleted', $actor_id, $current, null ) ) {
                throw new Exception( 'Work entry audit failed.' );
            }
            if ( ! DatabaseContext::commit() ) {
                throw new Exception( 'Work entry deletion commit failed.' );
            }
        } catch ( Throwable $exception ) {
            DatabaseContext::rollback();
            return new WP_Error( 'pandatask_work_entry_failed', __( 'The work entry could not be deleted.', 'pandatask' ), array( 'status' => 500 ) );
        }
        $this->invalidateScopes( (int) $current->user_id, (array) $current->allocations );
        return true;
    }

    private function normalizeEntry( array $input, $actor_id, $kind = 'manual', $source_key = null, $source_url = null, $allow_inactive_type = false ) {
        $user_id = isset( $input['user_id'] ) ? absint( $input['user_id'] ) : (int) $actor_id;
        if ( $user_id <= 0 || ( $user_id !== (int) $actor_id && ! user_can( $actor_id, 'manage_options' ) ) ) {
            return new WP_Error( 'rest_forbidden', __( 'You cannot log work for that user.', 'pandatask' ), array( 'status' => 403 ) );
        }
        $activity_type = sanitize_key( $input['activity_type'] ?? '' );
        $type_is_valid = $allow_inactive_type
            ? $this->work_type_service->isKnown( $activity_type, $user_id )
            : $this->work_type_service->isActive( $activity_type, $user_id );
        if ( 'residual' !== $kind && ! $type_is_valid ) {
            return new WP_Error( 'rest_invalid_param', __( 'Choose an active work type.', 'pandatask' ), array( 'status' => 422 ) );
        }
        $duration = absint( $input['duration_seconds'] ?? 0 );
        if ( $duration <= 0 ) {
            return new WP_Error( 'rest_invalid_param', __( 'Work duration must be greater than zero.', 'pandatask' ), array( 'status' => 422 ) );
        }
        $work_date = sanitize_text_field( $input['work_date'] ?? wp_date( 'Y-m-d' ) );
        $date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $work_date, wp_timezone() );
        if ( ! $date || $date->format( 'Y-m-d' ) !== $work_date ) {
            return new WP_Error( 'rest_invalid_date', __( 'Work date must use YYYY-MM-DD.', 'pandatask' ), array( 'status' => 422 ) );
        }

        $allocation_inputs = (array) ( $input['allocations'] ?? array() );
        if ( count( $allocation_inputs ) > 50 ) {
            return new WP_Error( 'rest_invalid_param', __( 'A work entry can contain at most 50 allocations.', 'pandatask' ), array( 'status' => 422 ) );
        }

        $allocations = array();
        $resolution_actions = array();
        $allocated_seconds = 0;
        $seen_targets = array();
        foreach ( $allocation_inputs as $allocation_input ) {
            $task_id = absint( $allocation_input['task_id'] ?? 0 );
            $board_name = sanitize_key( $allocation_input['board_name'] ?? '' );
            $seconds = absint( $allocation_input['seconds'] ?? 0 );
            if ( $seconds <= 0 || ( $task_id <= 0 && '' === $board_name ) ) {
                return new WP_Error( 'rest_invalid_reference', __( 'Each allocation requires a task or board and positive duration.', 'pandatask' ), array( 'status' => 422 ) );
            }

            if ( $task_id > 0 ) {
                $target_key = 'task:' . $task_id;
                if ( isset( $seen_targets[ $target_key ] ) ) {
                    return new WP_Error( 'pandatask_duplicate_work_allocation', __( 'A task can appear only once in a work entry. Combine its allocated time into one allocation.', 'pandatask' ), array( 'status' => 422 ) );
                }
                $seen_targets[ $target_key ] = true;
                $permission = $this->task_access_policy->canReadTask( $task_id, $actor_id );
                if ( true !== $permission ) {
                    return $permission;
                }
                $task = $this->task_repository->findById( $task_id );
                if ( ! $task ) {
                    return new WP_Error( 'rest_invalid_reference', __( 'An allocated task no longer exists.', 'pandatask' ), array( 'status' => 422 ) );
                }
                $occurrence = $this->occurrence_repository->findCurrentForTask( $task_id );
                $allocation_context = sanitize_key( $allocation_input['context'] ?? 'occurrence' );
                if ( ! in_array( $allocation_context, array( 'occurrence', 'post_completion' ), true ) ) {
                    return new WP_Error( 'rest_invalid_param', __( 'Task work context must be occurrence or post_completion.', 'pandatask' ), array( 'status' => 422 ) );
                }
                if ( 'post_completion' === $allocation_context && 'done' !== $task->status ) {
                    return new WP_Error( 'pandatask_post_completion_requires_done_task', __( 'Post-completion work can only be logged against a completed task.', 'pandatask' ), array( 'status' => 422 ) );
                }

                $residual_mode = sanitize_key( $allocation_input['residual_handling'] ?? '' );
                if ( $occurrence && 'post_completion' !== $allocation_context ) {
                    $validation = $this->task_time_service->validateSpecificAddition( (int) $occurrence->id, $user_id, $seconds, $residual_mode );
                    if ( is_wp_error( $validation ) ) {
                        return $validation;
                    }
                    $resolution_actions[] = array(
                        'occurrence_id' => (int) $occurrence->id,
                        'seconds'       => $seconds,
                        'mode'          => $residual_mode,
                    );
                }
                $allocations[] = array(
                    'occurrence_id'          => $occurrence && 'post_completion' !== $allocation_context ? (int) $occurrence->id : null,
                    'allocation_context'     => $allocation_context,
                    'seconds'                => $seconds,
                    'task_id_snapshot'       => $task_id,
                    'task_name_snapshot'     => $task->name,
                    'board_name_snapshot'    => $task->board_name,
                    'project_id_snapshot'    => $task->project_id ? (int) $task->project_id : null,
                    'project_name_snapshot'  => $task->project_name ?? null,
                    'category_id_snapshot'   => $task->category_id ? (int) $task->category_id : null,
                    'category_name_snapshot' => $task->category_name ?? null,
                );
            } else {
                $target_key = 'board:' . $board_name;
                if ( isset( $seen_targets[ $target_key ] ) ) {
                    return new WP_Error( 'pandatask_duplicate_work_allocation', __( 'A board can appear only once in a work entry. Combine its allocated time into one allocation.', 'pandatask' ), array( 'status' => 422 ) );
                }
                $seen_targets[ $target_key ] = true;
                $permission = $this->board_access_policy->canReadBoard( $board_name, $actor_id );
                if ( true !== $permission ) {
                    return $permission;
                }
                $allocations[] = array(
                    'occurrence_id'          => null,
                    'allocation_context'     => 'board',
                    'seconds'                => $seconds,
                    'task_id_snapshot'       => null,
                    'task_name_snapshot'     => null,
                    'board_name_snapshot'    => $board_name,
                    'project_id_snapshot'    => null,
                    'project_name_snapshot'  => null,
                    'category_id_snapshot'   => null,
                    'category_name_snapshot' => null,
                );
            }
            $allocated_seconds += $seconds;
        }
        if ( $allocated_seconds > $duration ) {
            return new WP_Error( 'pandatask_overallocated_work', __( 'Allocated time cannot exceed the work entry duration.', 'pandatask' ), array( 'status' => 422 ) );
        }

        $now = gmdate( 'Y-m-d H:i:s' );
        $entry = array(
            'user_id'          => $user_id,
            'created_by'       => (int) $actor_id,
            'title'            => sanitize_text_field( $input['title'] ?? $this->work_type_service->label( $activity_type, $user_id ) ),
            'notes'            => isset( $input['notes'] ) ? wp_kses_post( $input['notes'] ) : null,
            'activity_type'    => 'residual' === $kind ? null : $activity_type,
            'capacity'         => in_array( sanitize_key( $input['capacity'] ?? '' ), array( 'paid', 'volunteer', 'other' ), true ) ? sanitize_key( $input['capacity'] ) : null,
            'work_date'        => $work_date,
            'started_at_utc'   => $this->normalizeUtcDateTime( $input['started_at_utc'] ?? null ),
            'ended_at_utc'     => $this->normalizeUtcDateTime( $input['ended_at_utc'] ?? null ),
            'timezone'         => isset( $input['timezone'] ) ? sanitize_text_field( $input['timezone'] ) : null,
            'duration_seconds' => $duration,
            'kind'             => $kind,
            'source_key'       => $source_key ? sanitize_text_field( (string) $source_key ) : null,
            'source_url'       => $source_url ? esc_url_raw( (string) $source_url ) : null,
            'visibility'       => in_array( sanitize_key( $input['visibility'] ?? 'private' ), array( 'private', 'aggregate', 'shared' ), true ) ? sanitize_key( $input['visibility'] ?? 'private' ) : 'private',
            'created_at'       => $now,
            'updated_at'       => $now,
        );
        if ( '' === $entry['title'] ) {
            $entry['title'] = __( 'Work', 'pandatask' );
        }
        return array( 'entry' => $entry, 'allocations' => $allocations, 'resolution_actions' => $resolution_actions );
    }

    private function normalizeUtcDateTime( $value ) {
        if ( ! $value ) {
            return null;
        }
        try {
            return ( new \DateTimeImmutable( sanitize_text_field( $value ), new \DateTimeZone( 'UTC' ) ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
        } catch ( \Exception $exception ) {
            return null;
        }
    }

    private function invalidateScopes( $user_id, array $allocations ) {
        DatabaseContext::invalidateUserCache( (int) $user_id );
        $boards = array();
        foreach ( $allocations as $allocation ) {
            $board = is_object( $allocation ) ? ( $allocation->board_name_snapshot ?? '' ) : ( $allocation['board_name_snapshot'] ?? '' );
            if ( $board ) {
                $boards[] = $board;
            }
        }
        foreach ( array_unique( $boards ) as $board ) {
            DatabaseContext::invalidateBoardCache( $board, array( 'reports', 'work' ) );
        }
    }
}
