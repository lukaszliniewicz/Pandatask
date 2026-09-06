<?php

namespace Pandatask\Application\Task;

use Pandatask\Application\Security\TaskAccessPolicy;
use Pandatask\Domain\Task\TaskChecklist;
use Pandatask\Domain\Task\TaskRecurrenceDefinition as Definition;
use Pandatask\Infrastructure\Media\ProtectedAttachmentService;
use Pandatask\Infrastructure\Persistence\DatabaseContext;
use Pandatask\Infrastructure\Persistence\TaskCommandRepository;
use Pandatask\Infrastructure\Persistence\TaskRecurrenceRepository;
use Pandatask\Infrastructure\Persistence\TaskRepository;
use Pandatask\Infrastructure\Persistence\WorkOccurrenceRepository;
use RuntimeException;
use Throwable;
use WP_Error;

/** Persist series defaults and create independent task occurrences. */
final class TaskRecurrenceService {
    private $repository;
    private $commands;
    private $occurrences;
    private $history;
    private $tasks;
    private $policy;
    private $invariants;
    private $cache;
    private $calculator;

    public function __construct( $repository = null, $commands = null, $occurrences = null, $history = null, $tasks = null, $policy = null, $invariants = null, $cache = null, $calculator = null ) {
        $this->repository = $repository ?: new TaskRecurrenceRepository();
        $this->commands = $commands ?: new TaskCommandRepository();
        $this->occurrences = $occurrences ?: new WorkOccurrenceRepository();
        $this->history = $history ?: new HistoryService();
        $this->tasks = $tasks ?: new TaskRepository();
        $this->policy = $policy;
        $this->invariants = $invariants ?: new TaskInvariantService();
        $this->cache = $cache ?: new TaskCacheInvalidator();
        $this->calculator = $calculator;
    }

    /** Caller owns the transaction. Existing task IDs and all work history stay put. */
    public function attachTask( $task_id, $actor_id = 0 ) {
        $task = $this->repository->lockTask( $task_id );
        if ( ! $task || empty( $task->is_recurring ) ) {
            return null;
        }
        if ( ! empty( $task->recurrence_series_id ) ) {
            return (int) $task->recurrence_series_id;
        }
        $definition = $this->capture( $task );
        $next = Definition::nextDate( $definition, $task->start_date, wp_date( 'Y-m-d' ), $this->calculator );
        $series_id = $this->repository->insertSeries( array(
            'board_name' => $task->board_name,
            'template_json' => Definition::encode( $definition ),
            'current_task_id' => $task_id,
            'next_start_date' => $next,
            'active' => $next && empty( $task->archived ) ? 1 : 0,
            'version' => 0,
        ) );
        if ( ! $series_id || ! $this->repository->linkTask( $task_id, $series_id, 1, $task->start_date ) ) {
            throw new RuntimeException( 'The recurring series could not be created.' );
        }
        $this->record( $task_id, $actor_id, 'recurrence_series_created', '', $series_id );
        return $series_id;
    }

    public function migrateLegacyTasks() {
        try {
            while ( $ids = $this->repository->findLegacyTaskIds( 100 ) ) {
                foreach ( $ids as $id ) {
                    if ( ! DatabaseContext::beginTransaction() ) {
                        return false;
                    }
                    try {
                        $this->attachTask( $id );
                        if ( ! DatabaseContext::commit() ) {
                            throw new RuntimeException( 'The series migration could not commit.' );
                        }
                    } catch ( Throwable $exception ) {
                        DatabaseContext::rollback();
                        return false;
                    }
                    $this->invalidate( $id );
                }
            }
            return true;
        } catch ( Throwable $exception ) {
            return false;
        }
    }

    /** Lock order throughout recurrence mutations is task, then series. */
    public function lockForUpdate( $task_id, $scope, $expected_version, $actor_id ) {
        $task = $this->repository->lockTask( $task_id );
        if ( ! $task || empty( $task->recurrence_series_id ) ) {
            return new WP_Error( 'pandatask_recurrence_not_found', __( 'Recurring series not found.', 'pandatask' ), array( 'status' => 404 ) );
        }
        $series = $this->repository->lockSeries( (int) $task->recurrence_series_id );
        if ( ! $series ) {
            throw new RuntimeException( 'The recurring series is missing.' );
        }
        if ( 'future' === $scope ) {
            if ( ! is_int( $expected_version ) || $expected_version < 0 ) {
                return new WP_Error( 'rest_invalid_param', __( 'Read the series and provide expected_series_version before changing future occurrences.', 'pandatask' ), array( 'status' => 422 ) );
            }
            if ( (int) $series->version !== $expected_version || (int) $series->current_task_id !== (int) $task_id ) {
                return new WP_Error( 'pandatask_recurrence_conflict', __( 'The series changed. Open its latest occurrence before editing future tasks.', 'pandatask' ), array( 'status' => 409, 'series_version' => (int) $series->version, 'current_task_id' => true === $this->getPolicy()->canReadTask( (int) $series->current_task_id, $actor_id ) ? (int) $series->current_task_id : null ) );
            }
            $permission = $this->getPolicy()->canUpdateTask( $task_id, $actor_id );
            if ( true !== $permission ) {
                return is_wp_error( $permission ) ? $permission : new WP_Error( 'rest_forbidden', __( 'You cannot edit this series.', 'pandatask' ), array( 'status' => 403 ) );
            }
        }
        return $series;
    }

    /** Called after the task and its assignments are written, before their commit. */
    public function syncTemplate( $task_id, $series, $actor_id, $schedule_changed = false, $enabled = null ) {
        $task = $this->repository->lockTask( $task_id );
        $definition = $this->capture( $task );
        $next = $schedule_changed ? Definition::nextDate( $definition, $task->start_date, null, $this->calculator ) : $series->next_start_date;
        if ( $next && ! empty( $definition['recurrence_ends_on'] ) && $next > $definition['recurrence_ends_on'] ) {
            $next = null;
        }
        $this->writeSeries( $series, array(
            'board_name' => $task->board_name,
            'template_json' => Definition::encode( $definition ),
            'next_start_date' => $next,
            'active' => ( null === $enabled ? ! empty( $series->active ) : (bool) $enabled ) && $next ? 1 : 0,
        ) );
        $this->record( $task_id, $actor_id, 'recurrence_defaults_updated', $series->version, (int) $series->version + 1 );
    }

    /** Checklist writes already hold the task row lock and own the transaction. */
    public function syncChecklist( $task_id, array $items, $expected_version, $actor_id ) {
        $series = $this->lockForUpdate( $task_id, 'future', $expected_version, $actor_id );
        if ( is_wp_error( $series ) ) {
            return $series;
        }
        $definition = Definition::decode( $series->template_json );
        $unchecked = Definition::unchecked( $items );
        if ( $definition['checklist'] !== $unchecked ) {
            $definition['checklist'] = $unchecked;
            $this->writeSeries( $series, array( 'template_json' => Definition::encode( $definition ) ) );
            $this->record( $task_id, $actor_id, 'recurrence_checklist_updated', $series->version, (int) $series->version + 1 );
        }
        return true;
    }

    /** A successor transaction can be retried without completing the old task again. */
    public function advance( $task_id, $actor_id = 0, $force = false, $skip = false, $stop = false ) {
        if ( ! DatabaseContext::acquireDependencyGraphLock() ) {
            return new WP_Error( 'pandatask_dependency_graph_unavailable', __( 'The task graph is busy. Try again.', 'pandatask' ), array( 'status' => 503 ) );
        }
        $sync = null;
        $new_id = null;
        try {
            if ( ! DatabaseContext::beginTransaction() ) {
                throw new RuntimeException( 'The occurrence transaction could not begin.' );
            }
            $task = $this->repository->lockTask( $task_id );
            if ( ! $task || empty( $task->recurrence_series_id ) ) {
                throw new RuntimeException( 'The recurring task is missing.' );
            }
            $series = $this->repository->lockSeries( (int) $task->recurrence_series_id );
            if ( ! $series ) {
                throw new RuntimeException( 'The recurring series is missing.' );
            }
            if ( $skip || $stop ) {
                $permission = $this->getPolicy()->canUpdateTask( $task_id, $actor_id );
                if ( true !== $permission ) {
                    DatabaseContext::rollback();
                    return is_wp_error( $permission ) ? $permission : new WP_Error( 'rest_forbidden', __( 'You cannot change this occurrence.', 'pandatask' ), array( 'status' => 403 ) );
                }
                if ( $stop && (int) $series->current_task_id !== (int) $task_id && true !== $this->getPolicy()->canUpdateTask( (int) $series->current_task_id, $actor_id ) ) {
                    DatabaseContext::rollback();
                    return new WP_Error( 'rest_forbidden', __( 'You cannot stop this series.', 'pandatask' ), array( 'status' => 403 ) );
                }
            }
            if ( $skip ) {
                if ( false === $this->commands->updateTask( $task_id, array( 'archived' => 1 ), array( '%d' ) ) ) {
                    throw new RuntimeException( 'The occurrence could not be skipped.' );
                }
                $this->record( $task_id, $actor_id, 'recurrence_skipped', '', 'archived' );
            }
            if ( $stop ) {
                $this->writeSeries( $series, array( 'active' => 0 ) );
                $this->record( $task_id, $actor_id, 'recurrence_stopped', 1, 0 );
            } elseif (
                (int) $series->current_task_id === (int) $task_id
                && ! empty( $series->active ) && $series->next_start_date
                && ( $force || 'done' === $task->status || $series->next_start_date <= wp_date( 'Y-m-d' ) )
            ) {
                $definition = Definition::decode( $series->template_json );
                $candidate = Definition::occurrence( $definition, $series->next_start_date, $series->id, $this->repository->findMaxSequence( $series->id ) + 1 );
                // The stored definition was authorized when created/edited. The
                // update context preserves its existing protected-media grant;
                // references and memberships are still revalidated at generation.
                $validation_context = (object) array_merge( $candidate, array( 'id' => 0 ) );
                $validation_data = $candidate;
                $validation_data['predecessors'] = array_values( array_filter( $definition['predecessors'], function ( $id ) { return (bool) $this->tasks->findById( $id ); } ) );
                foreach ( array( 'assignee' => 'assigned_persons', 'supervisor' => 'supervisor_persons' ) as $role => $field ) {
                    $validation_data[ $field ] = array_column( array_filter( $definition['assignments'], static function ( $assignment ) use ( $role ) { return $assignment['role'] === $role; } ), 'user_id' );
                }
                $validated = $this->invariants->applyAndValidate( $validation_data, $validation_context );
                if ( is_wp_error( $validated ) ) {
                    DatabaseContext::rollback();
                    return $validated;
                }
                $candidate = array_intersect_key( $validated, $candidate );
                $new_id = $this->commands->insertTask( $candidate, $this->formats( $candidate ) );
                if ( ! $new_id ) {
                    throw new RuntimeException( 'The new occurrence could not be inserted.' );
                }
                foreach ( $definition['assignments'] as $assignment ) {
                    if ( ! $this->commands->insertRoleAssignment( $new_id, $assignment['user_id'], $assignment['role'] ) ) {
                        throw new RuntimeException( 'Occurrence assignments could not be copied.' );
                    }
                }
                foreach ( $definition['predecessors'] as $predecessor ) {
                    if ( $this->tasks->findById( $predecessor ) && ! $this->commands->insertTaskRelationship( $new_id, $predecessor ) ) {
                        throw new RuntimeException( 'Occurrence dependencies could not be copied.' );
                    }
                }
                $new_task = $this->tasks->findById( $new_id );
                $occurrence_id = $this->occurrences->createForTask( $new_task, 1, 'open' );
                if ( ! $occurrence_id || ! $this->occurrences->setCurrentOccurrence( $new_id, $occurrence_id ) ) {
                    throw new RuntimeException( 'The new work occurrence could not be created.' );
                }
                $sync = ProtectedAttachmentService::syncTask( $new_id );
                if ( is_wp_error( $sync ) ) {
                    throw new RuntimeException( 'The occurrence attachment could not be copied.' );
                }
                $next = Definition::nextDate( $definition, $series->next_start_date, null, $this->calculator );
                $this->writeSeries( $series, array( 'current_task_id' => $new_id, 'next_start_date' => $next, 'active' => $next ? 1 : 0 ) );
                $this->record( $new_id, $actor_id, 'task_created', '', $new_task->name );
                $this->record( $new_id, $actor_id, 'recurrence_occurrence_created', $task_id, $new_id );
                $this->record( $task_id, $actor_id, 'recurrence_successor_created', '', $new_id );
            }
            if ( ! DatabaseContext::commit() ) {
                throw new RuntimeException( 'The occurrence transaction could not commit.' );
            }
        } catch ( Throwable $exception ) {
            DatabaseContext::rollback();
            ProtectedAttachmentService::rollbackSync( $sync );
            return new WP_Error( 'pandatask_recurrence_failed', __( 'The recurring occurrence could not be saved. Its existing task and work history are unchanged.', 'pandatask' ), array( 'status' => 500 ) );
        } finally {
            DatabaseContext::releaseDependencyGraphLock();
        }
        try {
        ProtectedAttachmentService::finalizeSync( $sync );
        $this->invalidate( $task_id );
        if ( $new_id ) {
            $this->invalidate( $new_id );
            do_action( 'pandatask_task_created', $new_id, $this->tasks->findById( $new_id ), $actor_id );
        }
        } catch ( Throwable $exception ) {
            error_log( 'Pandatask recurrence post-commit notification or cache update failed.' );
        }
        return $new_id;
    }

    public function runDue() {
        $rows = $this->repository->findReadySeries( wp_date( 'Y-m-d' ), 100 );
        $stats = array( 'scanned' => count( $rows ), 'advanced' => 0, 'disabled' => 0, 'failed' => 0 );
        foreach ( $rows as $row ) {
            $result = $this->advance( (int) $row->current_task_id );
            if ( is_wp_error( $result ) ) {
                $stats['failed']++;
            } elseif ( $result ) {
                $stats['advanced']++;
            }
        }
        return $stats;
    }

    public function getSeries( $task_id, $actor_id, $limit = 50, $before_sequence = null ) {
        $permission = $this->getPolicy()->canReadTask( $task_id, $actor_id );
        if ( true !== $permission ) {
            return is_wp_error( $permission ) ? $permission : new WP_Error( 'rest_forbidden', __( 'You cannot read this task.', 'pandatask' ), array( 'status' => 403 ) );
        }
        try {
            $series = $this->repository->findForTask( $task_id );
            if ( ! $series ) {
                return new WP_Error( 'pandatask_recurrence_not_found', __( 'Recurring series not found.', 'pandatask' ), array( 'status' => 404 ) );
            }
            $can_edit = true === $this->getPolicy()->canUpdateTask( (int) $series->current_task_id, $actor_id );
            $can_read_current = true === $this->getPolicy()->canReadTask( (int) $series->current_task_id, $actor_id );
            $limit = max( 1, min( 100, (int) $limit ) );
            $visible = array();
            $cursor = $before_sequence;
            do {
                $rows = $this->repository->listOccurrenceTasks( $series->id, 100, $cursor );
                foreach ( $rows as $row ) {
                    $cursor = (int) $row->recurrence_sequence;
                    if ( true === $this->getPolicy()->canReadTask( (int) $row->id, $actor_id ) ) {
                        $visible[] = $row;
                        if ( count( $visible ) > $limit ) {
                            break;
                        }
                    }
                }
            } while ( count( $rows ) === 100 && count( $visible ) <= $limit );
            $has_more = count( $visible ) > $limit;
            $visible = array_slice( $visible, 0, $limit );
            $items = array();
            foreach ( $visible as $task ) {
                if ( true !== $this->getPolicy()->canReadTask( (int) $task->id, $actor_id ) ) {
                    continue;
                }
                $fields = TaskChecklist::fields( $task );
                $items[] = array(
                    'id' => (int) $task->id, 'name' => $task->name,
                    'status' => $task->status, 'archived' => (int) $task->archived,
                    'start_date' => $task->start_date, 'deadline' => $task->deadline,
                    'recurrence_sequence' => (int) $task->recurrence_sequence,
                    'recurrence_scheduled_start' => $task->recurrence_scheduled_start,
                    'checklist_total' => $fields['checklist_total'], 'checklist_checked' => $fields['checklist_checked'],
                );
            }
            $template = $can_edit ? Definition::decode( $series->template_json ) : null;
            if ( $template ) {
                $template['predecessors'] = array_values( array_filter( $template['predecessors'], function ( $id ) use ( $actor_id ) { return true === $this->getPolicy()->canReadTask( $id, $actor_id ); } ) );
                unset( $template['attachment_url'], $template['attachment_post_id'], $template['attachment_filename'] );
            }
            return array(
                'series' => array(
                    'id' => (int) $series->id, 'version' => (int) $series->version,
                    'active' => (bool) $series->active, 'can_edit' => $can_edit,
                    'current_task_id' => $can_read_current ? (int) $series->current_task_id : null,
                    'next_start_date' => $can_read_current ? $series->next_start_date : null,
                    'template' => $template,
                ),
                'occurrences' => $items,
                'has_more' => $has_more,
                'next_before_sequence' => $has_more ? (int) end( $visible )->recurrence_sequence : null,
            );
        } catch ( Throwable $exception ) {
            return new WP_Error( 'pandatask_recurrence_failed', __( 'The recurring series could not be read.', 'pandatask' ), array( 'status' => 500 ) );
        }
    }

    private function capture( $task ) {
        return Definition::capture( $task, $this->repository->findAssignments( $task->id ), $this->repository->findPredecessorIds( $task->id ) );
    }

    private function writeSeries( $series, array $data ) {
        $data['version'] = (int) $series->version + 1;
        if ( ! $this->repository->updateSeries( $series->id, $data ) ) {
            throw new RuntimeException( 'The recurring series could not be updated.' );
        }
    }

    private function record( $task_id, $actor_id, $field, $old, $new ) {
        if ( ! $this->history->addEntry( $task_id, $actor_id, $field, $old, $new ) ) {
            throw new RuntimeException( 'The recurring task history could not be recorded.' );
        }
    }

    private function invalidate( $task_id ) {
        DatabaseContext::invalidateTaskCache( $task_id );
        $task = $this->tasks->findById( $task_id );
        if ( $task ) {
            $users = $this->commands->findParticipantUserIdsForTasks( array( $task_id ) );
            $this->cache->invalidateTask( $task_id, $task->board_name, $users );
        }
    }

    private function getPolicy() {
        if ( ! $this->policy ) {
            $this->policy = new TaskAccessPolicy();
        }
        return $this->policy;
    }

    private function formats( array $data ) {
        return array_map( static function ( $value ) { return is_int( $value ) || is_bool( $value ) ? '%d' : '%s'; }, array_values( $data ) );
    }
}
