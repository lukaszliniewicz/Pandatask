<?php

namespace Pandatask\Application\Work;

use Pandatask\Infrastructure\Persistence\WorkAuditRepository;
use Pandatask\Infrastructure\Persistence\WorkOccurrenceRepository;

final class WorkOccurrenceLifecycleService {

    private $repository;
    private $audit_repository;

    public function __construct( $repository = null, $audit_repository = null ) {
        $this->repository       = $repository ?: new WorkOccurrenceRepository();
        $this->audit_repository = $audit_repository ?: new WorkAuditRepository();
    }

    public function createForTask( $task, $sequence_number = 1, $state = 'open', $actor_id = 0 ) {
        $occurrence_id = $this->repository->createForTask( $task, $sequence_number, $state );
        if ( ! $occurrence_id ) {
            return false;
        }
        $after = $this->repository->findById( $occurrence_id );
        if ( ! $this->audit_repository->record( 'task_work_occurrence', $occurrence_id, 'created', $actor_id, null, $after ) ) {
            return false;
        }
        return $occurrence_id;
    }

    public function findById( $occurrence_id ) {
        return $this->repository->findById( $occurrence_id );
    }

    public function findCurrentForTask( $task_id ) {
        return $this->repository->findCurrentForTask( $task_id );
    }

    public function nextSequence( $task_id ) {
        return $this->repository->nextSequence( $task_id );
    }

    public function setState( $occurrence_id, $state, $actor_id = 0 ) {
        $before = $this->repository->findById( $occurrence_id );
        if ( ! $before || ! $this->repository->setState( $occurrence_id, $state ) ) {
            return false;
        }
        $after = $this->repository->findById( $occurrence_id );
        return $this->audit_repository->record( 'task_work_occurrence', $occurrence_id, 'state_' . $state, $actor_id, $before, $after );
    }

    public function refreshOpenSnapshot( $occurrence_id, $task, $actor_id = 0 ) {
        $before = $this->repository->findById( (int) $occurrence_id );
        if ( ! $before || 'open' !== $before->state ) {
            return true;
        }
        if ( ! $this->repository->refreshOpenSnapshot( (int) $occurrence_id, $task ) ) {
            return false;
        }
        $after = $this->repository->findById( (int) $occurrence_id );
        return $this->audit_repository->record(
            'task_work_occurrence',
            (int) $occurrence_id,
            'snapshot_refreshed',
            (int) $actor_id,
            $before,
            $after
        );
    }

    public function setCurrentOccurrence( $task_id, $occurrence_id ) {
        return $this->repository->setCurrentOccurrence( $task_id, $occurrence_id );
    }

    public function tombstoneTaskOccurrences( $task_id, $actor_id = 0 ) {
        $before = $this->repository->findForTask( $task_id );
        if ( ! $this->repository->tombstoneTaskOccurrences( $task_id ) ) {
            return false;
        }
        $after_by_id = array();
        foreach ( $this->repository->findForTask( $task_id ) as $occurrence ) {
            $after_by_id[ (int) $occurrence->id ] = $occurrence;
        }
        foreach ( $before as $occurrence ) {
            $occurrence_id = (int) $occurrence->id;
            if ( ! $this->audit_repository->record(
                'task_work_occurrence',
                $occurrence_id,
                'tombstoned',
                $actor_id,
                $occurrence,
                $after_by_id[ $occurrence_id ] ?? null
            ) ) {
                return false;
            }
        }
        return true;
    }
}
