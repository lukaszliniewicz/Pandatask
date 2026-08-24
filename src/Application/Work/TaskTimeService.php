<?php

namespace Pandatask\Application\Work;

use Throwable;
use Pandatask\Domain\Work\TimeReconciler;
use Pandatask\Infrastructure\Persistence\DatabaseContext;
use Pandatask\Infrastructure\Persistence\TaskTimeRepository;
use Pandatask\Infrastructure\Persistence\WorkAuditRepository;
use Pandatask\Infrastructure\Persistence\WorkEntryRepository;
use Pandatask\Infrastructure\Persistence\WorkOccurrenceRepository;
use WP_Error;

final class TaskTimeService {

    private $work_repository;
    private $time_repository;
    private $occurrence_repository;
    private $audit_repository;
    private $reconciler;

    public function __construct( $work_repository = null, $time_repository = null, $occurrence_repository = null, $audit_repository = null, $reconciler = null ) {
        $this->work_repository       = $work_repository ?: new WorkEntryRepository();
        $this->time_repository       = $time_repository ?: new TaskTimeRepository();
        $this->occurrence_repository = $occurrence_repository ?: new WorkOccurrenceRepository();
        $this->audit_repository      = $audit_repository ?: new WorkAuditRepository();
        $this->reconciler            = $reconciler ?: new TimeReconciler();
    }

    public function resolveOccurrenceStandalone( $occurrence_id, $user_id, $declared_actual_seconds, $not_tracked, $resolved_by ) {
        if ( ! DatabaseContext::beginTransaction() ) {
            return new WP_Error( 'pandatask_transaction_failed', __( 'Task time resolution could not start a database transaction.', 'pandatask' ), array( 'status' => 500 ) );
        }

        try {
            $result = $this->resolveOccurrence( $occurrence_id, $user_id, $declared_actual_seconds, $not_tracked, $resolved_by );
            if ( is_wp_error( $result ) ) {
                DatabaseContext::rollback();
                return $result;
            }
            if ( ! DatabaseContext::commit() ) {
                throw new \RuntimeException( 'Task time resolution could not be committed.' );
            }
            return $result;
        } catch ( Throwable $exception ) {
            DatabaseContext::rollback();
            return new WP_Error( 'pandatask_time_resolution_failed', __( 'Task time could not be resolved.', 'pandatask' ), array( 'status' => 500 ) );
        }
    }

    public function resolveCurrentOccurrenceStandalone( $task_id, $user_id, $declared_actual_seconds, $not_tracked, $resolved_by ) {
        if ( ! DatabaseContext::beginTransaction() ) {
            return new WP_Error( 'pandatask_transaction_failed', __( 'Task time resolution could not start a database transaction.', 'pandatask' ), array( 'status' => 500 ) );
        }

        try {
            $result = $this->resolveCurrentOccurrence( $task_id, $user_id, $declared_actual_seconds, $not_tracked, $resolved_by );
            if ( is_wp_error( $result ) ) {
                DatabaseContext::rollback();
                return $result;
            }
            if ( ! DatabaseContext::commit() ) {
                throw new \RuntimeException( 'Task time resolution could not be committed.' );
            }
            return $result;
        } catch ( Throwable $exception ) {
            DatabaseContext::rollback();
            return new WP_Error( 'pandatask_time_resolution_failed', __( 'Task time could not be resolved.', 'pandatask' ), array( 'status' => 500 ) );
        }
    }

    /**
     * Resolve a user's cumulative actual time for the task's current occurrence.
     * Must be called inside the task mutation transaction.
     */
    public function resolveCurrentOccurrence( $task_id, $user_id, $declared_actual_seconds, $not_tracked, $resolved_by ) {
        $occurrence = $this->occurrence_repository->findCurrentForTask( (int) $task_id );
        if ( ! $occurrence ) {
            return new WP_Error( 'pandatask_occurrence_missing', __( 'The task has no active work occurrence.', 'pandatask' ), array( 'status' => 409 ) );
        }

        return $this->resolveOccurrence(
            (int) $occurrence->id,
            $user_id,
            $declared_actual_seconds,
            $not_tracked,
            $resolved_by
        );
    }

    /** Resolve a specific durable occurrence; callers must provide a transaction. */
    public function resolveOccurrence( $occurrence_id, $user_id, $declared_actual_seconds, $not_tracked, $resolved_by ) {
        $occurrence = $this->occurrence_repository->findById( (int) $occurrence_id );
        if ( ! $occurrence ) {
            return new WP_Error( 'pandatask_occurrence_missing', __( 'The work occurrence no longer exists.', 'pandatask' ), array( 'status' => 409 ) );
        }

        $specific = $this->work_repository->specificSecondsForOccurrenceUser( (int) $occurrence->id, (int) $user_id, true );
        $result = $this->reconciler->reconcile( $specific, $declared_actual_seconds, $not_tracked );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $latest = $this->time_repository->latest( (int) $occurrence->id, (int) $user_id );
        $residual_entry_id = $latest && ! empty( $latest->residual_entry_id ) ? (int) $latest->residual_entry_id : 0;

        if ( 'not_tracked' === $result['state'] || 0 === $result['residual_seconds'] ) {
            if ( $residual_entry_id > 0 ) {
                $residual_entry = $this->work_repository->findById( $residual_entry_id );
                if ( $residual_entry && ! $this->work_repository->softDelete( $residual_entry_id ) ) {
                    return new WP_Error( 'pandatask_residual_update_failed', __( 'Residual time could not be reconciled.', 'pandatask' ), array( 'status' => 500 ) );
                }
                if ( ! $this->audit_repository->record( 'work_entry', $residual_entry_id, 'residual_removed', $resolved_by, $residual_entry, null ) ) {
                    return new WP_Error( 'pandatask_work_audit_failed', __( 'Residual time could not be audited.', 'pandatask' ), array( 'status' => 500 ) );
                }
            }
            $residual_entry_id = 0;
        } else {
            $residual_entry_id = $this->saveResidualEntry( $occurrence, $user_id, $result['residual_seconds'], $resolved_by, $residual_entry_id );
            if ( is_wp_error( $residual_entry_id ) ) {
                return $residual_entry_id;
            }
        }

        $resolution_data = array(
            'occurrence_id'           => (int) $occurrence->id,
            'user_id'                 => (int) $user_id,
            'state'                   => $result['state'],
            'declared_actual_seconds' => $result['declared_actual_seconds'],
            'specific_seconds'        => $result['specific_seconds'],
            'residual_entry_id'       => $residual_entry_id ?: null,
            'resolved_by'             => max( 0, (int) $resolved_by ),
        );
        $resolution_id = $this->time_repository->insertRevision( $resolution_data );
        if ( ! $resolution_id ) {
            return new WP_Error( 'pandatask_time_resolution_failed', __( 'Task time could not be resolved.', 'pandatask' ), array( 'status' => 500 ) );
        }
        if ( ! $this->audit_repository->record( 'task_time_resolution', $resolution_id, 'resolved', $resolved_by, $latest, $result ) ) {
            return new WP_Error( 'pandatask_work_audit_failed', __( 'Task time resolution could not be audited.', 'pandatask' ), array( 'status' => 500 ) );
        }
        return array_merge( $result, array( 'resolution_id' => $resolution_id, 'occurrence_id' => (int) $occurrence->id ) );
    }

    public function canUserResolveOccurrence( $occurrence_id, $user_id ) {
        $occurrence = $this->occurrence_repository->findById( (int) $occurrence_id );
        if ( ! $occurrence || $user_id <= 0 ) {
            return false;
        }
        if ( $this->time_repository->latest( (int) $occurrence_id, (int) $user_id ) ) {
            return true;
        }
        return $this->work_repository->specificSecondsForOccurrenceUser( (int) $occurrence_id, (int) $user_id, true ) > 0;
    }

    public function getOccurrenceSummary( $occurrence_id, $user_id ) {
        $occurrence = $this->occurrence_repository->findById( (int) $occurrence_id );
        if ( ! $occurrence ) {
            return array( 'occurrence' => null, 'specific_seconds' => 0, 'resolution' => null );
        }
        return array(
            'occurrence'       => $occurrence,
            'specific_seconds' => $this->work_repository->specificSecondsForOccurrenceUser( (int) $occurrence_id, (int) $user_id, true ),
            'resolution'       => $this->time_repository->latest( (int) $occurrence_id, (int) $user_id ),
        );
    }

    /** Legacy status transitions explicitly preserve unknown time rather than treating it as zero. */
    public function markUnresolved( $task_id, $user_id, $resolved_by = 0 ) {
        $occurrence = $this->occurrence_repository->findCurrentForTask( (int) $task_id );
        if ( ! $occurrence || $user_id <= 0 ) {
            return true;
        }
        $specific = $this->work_repository->specificSecondsForOccurrenceUser( (int) $occurrence->id, (int) $user_id, true );
        $latest = $this->time_repository->latest( (int) $occurrence->id, (int) $user_id );
        $resolution_data = array(
            'occurrence_id'           => (int) $occurrence->id,
            'user_id'                 => (int) $user_id,
            'state'                   => 'unresolved',
            'declared_actual_seconds' => null,
            'specific_seconds'        => $specific,
            'residual_entry_id'       => null,
            'resolved_by'             => max( 0, (int) $resolved_by ),
        );
        $resolution_id = $this->time_repository->insertRevision( $resolution_data );
        if ( ! $resolution_id ) {
            return false;
        }
        return $this->audit_repository->record( 'task_time_resolution', $resolution_id, 'unresolved', $resolved_by, $latest, $resolution_data );
    }

    /** Reopening preserves the last known cumulative resolution as a new revision. */
    public function reviseOnReopen( $task_id, $user_id, $actor_id ) {
        $occurrence = $this->occurrence_repository->findCurrentForTask( (int) $task_id );
        if ( ! $occurrence || $user_id <= 0 ) {
            return true;
        }

        $latest = $this->time_repository->latest( (int) $occurrence->id, (int) $user_id );
        if ( ! $latest ) {
            return true;
        }

        $specific = $this->work_repository->specificSecondsForOccurrenceUser( (int) $occurrence->id, (int) $user_id, true );
        $residual_entry_id = ! empty( $latest->residual_entry_id ) && $this->work_repository->findById( (int) $latest->residual_entry_id )
            ? (int) $latest->residual_entry_id
            : null;
        $resolution_data = array(
            'occurrence_id'           => (int) $occurrence->id,
            'user_id'                 => (int) $user_id,
            'state'                   => (string) $latest->state,
            'declared_actual_seconds' => null === $latest->declared_actual_seconds ? null : (int) $latest->declared_actual_seconds,
            'specific_seconds'        => $specific,
            'residual_entry_id'       => $residual_entry_id,
            'resolved_by'             => max( 0, (int) $actor_id ),
        );
        $resolution_id = $this->time_repository->insertRevision( $resolution_data );
        if ( ! $resolution_id ) {
            return false;
        }
        return $this->audit_repository->record( 'task_time_resolution', $resolution_id, 'reopened', $actor_id, $latest, $resolution_data );
    }

    /** Ensure every assignee has a durable state for a completed occurrence. */
    public function ensureUnresolvedForUsers( $task_id, array $user_ids, $actor_id = 0 ) {
        $occurrence = $this->occurrence_repository->findCurrentForTask( (int) $task_id );
        if ( ! $occurrence ) {
            return false;
        }

        foreach ( array_values( array_unique( array_filter( array_map( 'absint', $user_ids ) ) ) ) as $user_id ) {
            if ( $this->time_repository->latest( (int) $occurrence->id, $user_id ) ) {
                continue;
            }
            $specific = $this->work_repository->specificSecondsForOccurrenceUser( (int) $occurrence->id, $user_id, true );
            $resolution_data = array(
                'occurrence_id'           => (int) $occurrence->id,
                'user_id'                 => $user_id,
                'state'                   => 'unresolved',
                'declared_actual_seconds' => null,
                'specific_seconds'        => $specific,
                'residual_entry_id'       => null,
                'resolved_by'             => max( 0, (int) $actor_id ),
            );
            $resolution_id = $this->time_repository->insertRevision( $resolution_data );
            if ( ! $resolution_id || ! $this->audit_repository->record( 'task_time_resolution', $resolution_id, 'unresolved', $actor_id, null, $resolution_data ) ) {
                return false;
            }
        }

        return true;
    }

    public function hasResolvedState( $occurrence_id, $user_id ) {
        $latest = $this->time_repository->latest( (int) $occurrence_id, (int) $user_id );
        return $latest && in_array( $latest->state, array( 'resolved', 'not_tracked' ), true );
    }

    public function validateSpecificAddition( $occurrence_id, $user_id, $seconds, $mode = '' ) {
        $latest = $this->time_repository->latest( (int) $occurrence_id, (int) $user_id );
        if ( ! $latest || 'resolved' !== $latest->state ) {
            return true;
        }

        $has_residual = ! empty( $latest->residual_entry_id );
        if ( ! in_array( $mode, array( 'refine_residual', 'additional' ), true ) ) {
            return new WP_Error(
                'pandatask_residual_intent_required',
                $has_residual
                    ? __( 'This task has unitemised time. Choose whether the new detail refines that residual or is additional work.', 'pandatask' )
                    : __( 'This task time was already resolved. Confirm that the new detail is additional work.', 'pandatask' ),
                array( 'status' => 409 )
            );
        }

        if ( 'refine_residual' === $mode ) {
            if ( ! $has_residual ) {
                return new WP_Error(
                    'pandatask_no_residual_to_refine',
                    __( 'There is no remaining unitemised time to refine. Mark this as additional work instead.', 'pandatask' ),
                    array( 'status' => 422 )
                );
            }
            $specific = $this->work_repository->specificSecondsForOccurrenceUser( (int) $occurrence_id, (int) $user_id, true );
            if ( $specific + (int) $seconds > (int) $latest->declared_actual_seconds ) {
                return new WP_Error(
                    'pandatask_refinement_exceeds_residual',
                    __( 'The detailed time exceeds the remaining unitemised time. Split it or mark the new work as additional.', 'pandatask' ),
                    array( 'status' => 422 )
                );
            }
        }

        return true;
    }

    /** Must be called in the same transaction as the newly inserted specific work. */
    public function applySpecificAddition( $occurrence_id, $user_id, $seconds, $mode, $actor_id ) {
        $latest = $this->time_repository->latest( (int) $occurrence_id, (int) $user_id );
        if ( ! $latest ) {
            return true;
        }

        $occurrence = $this->occurrence_repository->findById( (int) $occurrence_id );
        if ( ! $occurrence ) {
            return new WP_Error( 'pandatask_occurrence_missing', __( 'The work occurrence no longer exists.', 'pandatask' ), array( 'status' => 409 ) );
        }

        if ( 'resolved' === $latest->state ) {
            $declared = (int) $latest->declared_actual_seconds;
            if ( 'additional' === $mode ) {
                $declared += max( 0, (int) $seconds );
            }
            return $this->resolveCurrentOccurrence( (int) $occurrence->task_id, (int) $user_id, $declared, false, (int) $actor_id );
        }

        if ( 'not_tracked' === $latest->state ) {
            return $this->resolveCurrentOccurrence( (int) $occurrence->task_id, (int) $user_id, null, true, (int) $actor_id );
        }

        if ( 'unresolved' === $latest->state ) {
            return $this->markUnresolved( (int) $occurrence->task_id, (int) $user_id, (int) $actor_id )
                ? true
                : new WP_Error( 'pandatask_time_resolution_failed', __( 'Unresolved task time could not be updated.', 'pandatask' ), array( 'status' => 500 ) );
        }

        return true;
    }

    public function getTaskSummary( $task_id, $user_id ) {
        $occurrence = $this->occurrence_repository->findCurrentForTask( (int) $task_id );
        if ( ! $occurrence ) {
            return array( 'occurrence' => null, 'specific_seconds' => 0, 'resolution' => null );
        }
        $specific = $this->work_repository->specificSecondsForOccurrenceUser( (int) $occurrence->id, (int) $user_id, true );
        $latest = $this->time_repository->latest( (int) $occurrence->id, (int) $user_id );
        return array(
            'occurrence'       => $occurrence,
            'specific_seconds' => $specific,
            'resolution'       => $latest,
        );
    }

    private function saveResidualEntry( $occurrence, $user_id, $seconds, $actor_id, $existing_id ) {
        $now = gmdate( 'Y-m-d H:i:s' );
        $entry_data = array(
            'user_id'          => (int) $user_id,
            'created_by'       => max( 0, (int) $actor_id ),
            'title'            => __( 'Unitemised task time', 'pandatask' ),
            'notes'            => null,
            'activity_type'    => null,
            'capacity'         => null,
            'work_date'        => $occurrence->completed_at ? substr( $occurrence->completed_at, 0, 10 ) : wp_date( 'Y-m-d' ),
            'duration_seconds' => (int) $seconds,
            'kind'             => 'residual',
            'visibility'       => 'private',
            'updated_at'       => $now,
        );
        $allocation = array(
            'occurrence_id'          => (int) $occurrence->id,
            'seconds'                => (int) $seconds,
            'task_id_snapshot'       => (int) $occurrence->task_id,
            'task_name_snapshot'     => $occurrence->task_name_snapshot,
            'board_name_snapshot'    => $occurrence->board_name_snapshot,
            'project_id_snapshot'    => $occurrence->project_id_snapshot ?: null,
            'project_name_snapshot'  => $occurrence->project_name_snapshot ?: null,
            'category_id_snapshot'   => $occurrence->category_id_snapshot ?: null,
            'category_name_snapshot' => $occurrence->category_name_snapshot ?: null,
        );

        if ( $existing_id > 0 && $this->work_repository->findById( $existing_id ) ) {
            if ( ! $this->work_repository->update( $existing_id, $entry_data ) || ! $this->work_repository->replaceAllocations( $existing_id, array( $allocation ) ) ) {
                return new WP_Error( 'pandatask_residual_update_failed', __( 'Residual time could not be updated.', 'pandatask' ), array( 'status' => 500 ) );
            }
            if ( ! $this->audit_repository->record( 'work_entry', $existing_id, 'residual_reconciled', $actor_id, null, $entry_data ) ) {
                return new WP_Error( 'pandatask_work_audit_failed', __( 'Residual time could not be audited.', 'pandatask' ), array( 'status' => 500 ) );
            }
            return $existing_id;
        }

        $entry_data['created_at'] = $now;
        $entry_id = $this->work_repository->insert( $entry_data );
        if ( ! $entry_id || ! $this->work_repository->replaceAllocations( $entry_id, array( $allocation ) ) ) {
            return new WP_Error( 'pandatask_residual_create_failed', __( 'Residual time could not be created.', 'pandatask' ), array( 'status' => 500 ) );
        }
        if ( ! $this->audit_repository->record( 'work_entry', $entry_id, 'residual_created', $actor_id, null, $entry_data ) ) {
            return new WP_Error( 'pandatask_work_audit_failed', __( 'Residual time could not be audited.', 'pandatask' ), array( 'status' => 500 ) );
        }
        return $entry_id;
    }
}
