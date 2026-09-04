<?php

namespace Pandatask\Application\Task;

use DateInterval;
use DateTime;
use Exception;
use Throwable;
use Pandatask\Domain\Task\RecurrenceCalculator;
use Pandatask\Infrastructure\Notifications\BuddyPressNotifier;
use Pandatask\Infrastructure\Notifications\EmailNotifier;
use Pandatask\Infrastructure\Media\ProtectedAttachmentService;
use Pandatask\Infrastructure\Persistence\DatabaseContext;
use Pandatask\Infrastructure\Persistence\TaskCommandRepository;
use Pandatask\Infrastructure\Persistence\TaskRepository;
use Pandatask\Application\Work\TaskTimeService;
use Pandatask\Application\Work\WorkOccurrenceLifecycleService;
use Pandatask\Application\Settings\FeatureSettings;
use WP_Error;

final class TaskMutationService {

    private $repository;

    private $task_repository;

    private $history_service;

    private $invariant_service;

    private $history_buffer_service;

    private $recurrence_calculator;

    private $cache_invalidator;

    private $occurrence_repository;

    private $task_time_service;

    private $feature_settings;

    public function __construct( $repository = null, $task_repository = null, $history_service = null, $invariant_service = null, $history_buffer_service = null, $recurrence_calculator = null, $cache_invalidator = null, $occurrence_repository = null, $task_time_service = null, $feature_settings = null ) {
        $this->repository      = $repository ?: new TaskCommandRepository();
        $this->task_repository = $task_repository ?: new TaskRepository();
        $this->history_service = $history_service ?: new HistoryService();
        $this->invariant_service = $invariant_service ?: new TaskInvariantService( $this->task_repository );
        $this->history_buffer_service = $history_buffer_service ?: new TaskHistoryBufferService( null, $this->history_service, $this->task_repository );
        $this->recurrence_calculator = $recurrence_calculator ?: new RecurrenceCalculator();
        $this->cache_invalidator = $cache_invalidator ?: new TaskCacheInvalidator( $this->task_repository );
        $this->occurrence_repository = $occurrence_repository ?: new WorkOccurrenceLifecycleService();
        $this->task_time_service = $task_time_service ?: new TaskTimeService();
        $this->feature_settings = $feature_settings ?: new FeatureSettings();
    }

    /**
     * Create a task with separate authorization ownership and audit actor context.
     *
     * Ordinary callers omit the context, preserving actor-as-creator behavior.
     * Delegated Inbox capture may name the Inbox owner as creator while retaining
     * the submitting delegate as the actor recorded by audit integrations.
     */
    public function createTask( $data, array $context = array() ) {
        $data = $this->invariant_service->applyAndValidate( $data );

        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $is_recurring = ! empty( $data['is_recurring'] ) ? 1 : 0;
        $actor_id    = array_key_exists( 'actor_id', $context ) ? absint( $context['actor_id'] ) : get_current_user_id();
        $creator_id  = array_key_exists( 'creator_id', $context ) ? absint( $context['creator_id'] ) : $actor_id;
        $task_data    = array(
            'board_name'               => $data['board_name'],
            'name'                     => $data['name'],
            'creator_id'               => $creator_id > 0 ? $creator_id : null,
            'estimated_effort_seconds' => isset( $data['estimated_effort_seconds'] ) && '' !== $data['estimated_effort_seconds'] ? absint( $data['estimated_effort_seconds'] ) : null,
            'description'              => $data['description'],
            'task_type'                => $data['task_type'] ?? 'task',
            'bug_url'                  => isset( $data['bug_url'] ) ? esc_url_raw( $data['bug_url'] ) : null,
            'status'                   => $data['status'],
            'category_id'              => ! empty( $data['category_id'] ) ? $data['category_id'] : null,
            'project_id'               => ! empty( $data['project_id'] ) ? $data['project_id'] : null,
            'priority'                 => max( 1, min( 10, $data['priority'] ) ),
            'deadline_days_after_start' => ! empty( $data['deadline_days_after_start'] ) ? $data['deadline_days_after_start'] : null,
            'notify_deadline'          => isset( $data['notify_deadline'] ) ? $data['notify_deadline'] : 0,
            'notify_days_before'       => isset( $data['notify_days_before'] ) ? max( 1, min( 30, $data['notify_days_before'] ) ) : 3,
            'parent_task_id'           => ! empty( $data['parent_task_id'] ) ? $data['parent_task_id'] : null,
            'follow_up_of_task_id'     => ! empty( $data['follow_up_of_task_id'] ) ? absint( $data['follow_up_of_task_id'] ) : null,
            'inbox_state'              => ! empty( $data['inbox_state'] ) ? sanitize_key( $data['inbox_state'] ) : null,
            'capture_source'           => ! empty( $data['capture_source'] ) ? substr( sanitize_key( $data['capture_source'] ), 0, 32 ) : null,
            'capture_url'              => ! empty( $data['capture_url'] ) ? esc_url_raw( $data['capture_url'] ) : null,
            'is_recurring'             => $is_recurring,
            'recurrence_frequency'     => $is_recurring ? ( $data['recurrence_frequency'] ?? null ) : null,
            'recurrence_interval'      => $is_recurring ? ( $data['recurrence_interval'] ?? null ) : null,
            'recurrence_days'          => $is_recurring ? ( $data['recurrence_days'] ?? null ) : null,
            'recurrence_ends_on'       => $is_recurring ? ( $data['recurrence_ends_on'] ?? null ) : null,
            'attachment_type'          => $data['attachment_type'] ?? null,
            'attachment_url'           => $data['attachment_url'] ?? null,
            'attachment_post_id'       => ! empty( $data['attachment_post_id'] ) ? $data['attachment_post_id'] : null,
            'attachment_filename'      => $data['attachment_filename'] ?? null,
            'created_at'               => gmdate( 'Y-m-d H:i:s' ),
            'updated_at'               => gmdate( 'Y-m-d H:i:s' ),
        );

        if ( ! empty( $data['start_date'] ) ) {
            $task_data['start_date'] = $data['start_date'];
        } elseif ( 'in-progress' === $data['status'] ) {
            $task_data['start_date'] = wp_date( 'Y-m-d' );
        } else {
            $task_data['start_date'] = null;
        }

        if ( ! empty( $data['deadline_days_after_start'] ) && ! empty( $task_data['start_date'] ) ) {
            $start_date = new DateTime( $task_data['start_date'] );
            $start_date->add( new DateInterval( 'P' . absint( $data['deadline_days_after_start'] ) . 'D' ) );
            $task_data['deadline'] = $start_date->format( 'Y-m-d' );
        } elseif ( ! empty( $data['deadline'] ) ) {
            $task_data['deadline'] = $data['deadline'];
        } else {
            $task_data['deadline'] = null;
        }

        if ( 'done' === $data['status'] ) {
            $task_data['completed_at'] = gmdate( 'Y-m-d H:i:s' );
        } else {
            $task_data['completed_at'] = null;
        }

        if ( empty( $task_data['is_recurring'] ) ) {
            $task_data['recurrence_frequency'] = null;
            $task_data['recurrence_interval']  = null;
            $task_data['recurrence_days']      = null;
            $task_data['recurrence_ends_on']   = null;
        }

        $task_data['recurrence_anchor_day'] = (
            ! empty( $task_data['is_recurring'] )
            && 'monthly' === $task_data['recurrence_frequency']
            && ! empty( $task_data['start_date'] )
        )
            ? (int) substr( $task_data['start_date'], 8, 2 )
            : null;

        $format = $this->formatsForTaskData( $task_data );

        if ( ! DatabaseContext::beginTransaction() ) {
            return new WP_Error( 'pandatask_transaction_failed', __( 'The task could not start a database transaction.', 'pandatask' ), array( 'status' => 500 ) );
        }

        $task_id = 0;
        $attachment_sync = null;

        try {
            $task_id = $this->repository->insertTask( $task_data, $format );

            if ( ! $task_id ) {
                throw new Exception( 'Failed to insert the task.' );
            }

            $occurrence_task = (object) array_merge(
                $task_data,
                array(
                    'id'            => $task_id,
                    'project_name'  => null,
                    'category_name' => null,
                )
            );
            $occurrence_state = 'done' === $task_data['status'] ? 'completed' : 'open';
            $occurrence_id = $this->occurrence_repository->createForTask( $occurrence_task, 1, $occurrence_state, $actor_id );

            if ( ! $occurrence_id || ! $this->occurrence_repository->setCurrentOccurrence( $task_id, $occurrence_id ) ) {
                throw new Exception( 'Failed to create the task work occurrence.' );
            }
            $attachment_sync = ProtectedAttachmentService::syncTask( $task_id );

            if ( is_wp_error( $attachment_sync ) ) {
                throw new Exception( $attachment_sync->get_error_message() );
            }

            $assigned_persons   = $data['assigned_persons'] ?? array();
            $supervisor_persons = $data['supervisor_persons'] ?? array();
            $predecessors       = $data['predecessors'] ?? array();

            if ( ! empty( $predecessors ) ) {
                $predecessors = array_map( 'absint', (array) $predecessors );
                $predecessors = array_unique( array_filter( $predecessors ) );

                foreach ( $predecessors as $predecessor_id ) {
                    if ( $predecessor_id === $task_id ) {
                        continue;
                    }

                    if ( ! $this->repository->insertTaskRelationship( $task_id, $predecessor_id ) ) {
                        throw new Exception( 'Failed to create a task dependency.' );
                    }
                }
            }

            if ( preg_match( '/^user_(\d+)$/', $task_data['board_name'], $matches ) ) {
                $board_owner_id = intval( $matches[1] );

                if ( $board_owner_id > 0 && ! in_array( $board_owner_id, $assigned_persons ) ) {
                    $assigned_persons[] = $board_owner_id;
                }
            }

            $assignment_changes = $this->updateTaskAssignments( $task_id, $assigned_persons, $supervisor_persons );

            // Assignments are persisted after the occurrence is created, so preserve
            // the creator's legacy state and seed every assigned user's state only
            // after the complete assignment set is known.
            if (
                'done' === $task_data['status']
                && ! $this->preserveCompletedTaskTimeStates( $task_id, $creator_id, $assigned_persons, $actor_id )
            ) {
                throw new Exception( 'Failed to preserve unresolved time for a completed task.' );
            }

            if ( ! $this->history_service->addEntry( $task_id, $actor_id, 'task_created', '', $task_data['name'] ) ) {
                throw new Exception( 'Failed to create task history.' );
            }

            if ( ! DatabaseContext::commit() ) {
                throw new Exception( 'The task could not be committed.' );
            }
        } catch ( Throwable $exception ) {
            DatabaseContext::rollback();
            ProtectedAttachmentService::rollbackSync( $attachment_sync );

            return new WP_Error( 'pandatask_create_failed', __( 'The task could not be created.', 'pandatask' ), array( 'status' => 500 ) );
        }

        ProtectedAttachmentService::finalizeSync( $attachment_sync );

        $this->sendAssignmentNotifications( $task_id, $assignment_changes, $actor_id );
        delete_transient( 'pandat69_all_board_names' );

        $all_affected_users = array_values( array_unique( array_filter( array_merge( $assigned_persons, $supervisor_persons, array( $creator_id, $actor_id ) ) ) ) );
        $this->cache_invalidator->invalidateTask( $task_id, $task_data['board_name'], $all_affected_users );

        $created_task = $this->task_repository->findById( $task_id );
        $this->dispatchLifecycleEvent( 'pandatask_task_created', $task_id, $created_task, $actor_id );

        return $task_id;
    }

    public function updateTask( $task_id, $data, $change_comment = '', $actor_id = null, $completion = null, $lifecycle_operation = '' ) {
        $task_id   = (int) $task_id;
        $actor_id  = is_null( $actor_id ) ? get_current_user_id() : (int) $actor_id;
        $current_task = $this->task_repository->findById( $task_id );

        if ( ! $current_task ) {
            return false;
        }

        if ( 'complete' === $lifecycle_operation && 'done' === $current_task->status ) {
            return $this->alreadyCompletedError();
        }

        $data = $this->invariant_service->applyAndValidate( $data, $current_task );

        if ( is_wp_error( $data ) ) {
            return $data;
        }

        if (
            isset( $data['status'] )
            && 'done' === $data['status']
            && 'done' !== $current_task->status
            && 'complete' !== $lifecycle_operation
        ) {
            return new WP_Error(
                'pandatask_completion_required',
                __( 'Complete tasks through the explicit Complete action so work accounting is recorded.', 'pandatask' ),
                array( 'status' => 409 )
            );
        }

        if (
            isset( $data['status'] )
            && 'done' === $current_task->status
            && 'done' !== $data['status']
            && ! in_array( $lifecycle_operation, array( 'reopen', 'rollover', 'skip' ), true )
        ) {
            return new WP_Error(
                'pandatask_reopen_required',
                __( 'Completed tasks must be reopened through the explicit Reopen action so completion history is preserved.', 'pandatask' ),
                array( 'status' => 409 )
            );
        }

        $current_project_id = $current_task->project_id ? (int) $current_task->project_id : null;
        $next_project_id = array_key_exists( 'project_id', $data ) && $data['project_id']
            ? (int) $data['project_id']
            : null;
        $project_is_changing = array_key_exists( 'project_id', $data ) && $next_project_id !== $current_project_id;
        $next_board_name = $data['board_name'] ?? $current_task->board_name;
        $board_is_changing = $next_board_name !== $current_task->board_name;
        $descendant_records = ( $project_is_changing || $board_is_changing )
            ? $this->task_repository->findDescendantProjectRecords( $task_id, $current_task->board_name )
            : array();

        if ( $board_is_changing && ! empty( $descendant_records ) ) {
            return new WP_Error(
                'pandatask_task_has_children',
                __( 'Move or detach this task\'s subtasks before changing its board.', 'pandatask' ),
                array( 'status' => 409 )
            );
        }

        $descendant_ids = array_values( array_map( 'intval', wp_list_pluck( $descendant_records, 'id' ) ) );
        $descendant_user_ids = $this->repository->findParticipantUserIdsForTasks( $descendant_ids );

        if ( isset( $data['status'] ) && ( 'in-progress' === $data['status'] || 'done' === $data['status'] ) ) {
            if ( $this->task_repository->isBlocked( $task_id ) ) {
                return new WP_Error( 'pandatask_task_blocked', __( 'Complete all predecessor tasks before changing this task to that status.', 'pandatask' ), array( 'status' => 409 ) );
            }
        }

        $final_deadline = array_key_exists( 'deadline', $data ) ? $data['deadline'] : $current_task->deadline;
        $is_deadline_changing = array_key_exists( 'deadline', $data ) || array_key_exists( 'deadline_days_after_start', $data );

        if ( array_key_exists( 'deadline_days_after_start', $data ) ) {
            $start_date_for_calc = $data['start_date'] ?? $current_task->start_date;

            if ( ! empty( $start_date_for_calc ) ) {
                try {
                    $start = new DateTime( $start_date_for_calc );
                    $start->add( new DateInterval( 'P' . absint( $data['deadline_days_after_start'] ) . 'D' ) );
                    $final_deadline = $start->format( 'Y-m-d' );
                } catch ( Exception $exception ) {
                }
            }
        }

        if (
            ( $is_deadline_changing && ( null === $final_deadline || $final_deadline >= wp_date( 'Y-m-d' ) ) )
            ||
            ( isset( $data['status'] ) && 'done' !== $data['status'] && 'done' === $current_task->status )
        ) {
            $data['missed_deadline_notified'] = 0;
        }

        if (
            $is_deadline_changing
            || array_key_exists( 'notify_deadline', $data )
            || array_key_exists( 'notify_days_before', $data )
            || array_key_exists( 'assigned_persons', $data )
        ) {
            $data['deadline_reminder_sent_for'] = null;
        }

        $allowed_task_fields = array(
            'board_name',
            'name',
            'description',
            'estimated_effort_seconds',
            'status',
            'category_id',
            'project_id',
            'priority',
            'deadline',
            'task_type',
            'bug_url',
            'deadline_days_after_start',
            'start_date',
            'archived',
            'notify_deadline',
            'notify_days_before',
            'parent_task_id',
            'follow_up_of_task_id',
            'inbox_state',
            'capture_source',
            'capture_url',
            'completed_at',
            'is_recurring',
            'recurrence_frequency',
            'recurrence_interval',
            'recurrence_days',
            'recurrence_ends_on',
            'recurrence_anchor_day',
            'attachment_type',
            'attachment_url',
            'attachment_post_id',
            'attachment_filename',
            'missed_deadline_notified',
            'deadline_reminder_sent_for',
        );

        $update_data         = array();
        $format              = array();
        $changes_for_buffer = array();

        foreach ( $data as $key => $value ) {
            if ( ! in_array( $key, $allowed_task_fields, true ) ) {
                continue;
            }

            if ( array_key_exists( $key, $data ) ) {
                if ( 'status' === $key ) {
                    $update_data['status'] = $value;
                    $format[]              = '%s';

                    if ( 'done' === $value && 'done' !== $current_task->status ) {
                        $update_data['completed_at'] = gmdate( 'Y-m-d H:i:s' );
                        $format[]                    = '%s';
                    } elseif ( 'done' !== $value && 'done' === $current_task->status ) {
                        $update_data['completed_at'] = null;
                        $format[]                    = '%s';
                    }

                    if ( 'in-progress' === $value && 'pending' === $current_task->status && empty( $current_task->start_date ) ) {
                        $update_data['start_date'] = wp_date( 'Y-m-d' );
                        $format[]                  = '%s';

                        if ( ! empty( $current_task->deadline_days_after_start ) ) {
                            $start_date = new DateTime( $update_data['start_date'] );
                            $start_date->add( new DateInterval( 'P' . $current_task->deadline_days_after_start . 'D' ) );
                            $update_data['deadline'] = $start_date->format( 'Y-m-d' );
                            $format[]                = '%s';
                        }
                    }
                } elseif ( 'deadline' === $key ) {
                    if ( empty( $value ) ) {
                        $update_data['deadline'] = null;
                        $format[]                = '%s';
                    } elseif ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
                        $update_data['deadline'] = $value;
                        $format[]                = '%s';
                    }
                } elseif ( in_array( $key, array( 'board_name', 'name', 'description', 'start_date', 'recurrence_frequency', 'recurrence_days', 'recurrence_ends_on', 'attachment_type', 'attachment_url', 'attachment_filename', 'task_type', 'bug_url', 'deadline_reminder_sent_for', 'inbox_state', 'capture_source', 'capture_url' ), true ) ) {
                    $update_data[ $key ] = $value;
                    $format[]            = '%s';
                } elseif ( in_array( $key, array( 'category_id', 'project_id', 'deadline_days_after_start', 'parent_task_id', 'follow_up_of_task_id', 'recurrence_interval', 'recurrence_anchor_day', 'attachment_post_id', 'estimated_effort_seconds' ), true ) ) {
                    $update_data[ $key ] = ! empty( $value ) ? absint( $value ) : null;
                    $format[]            = is_null( $update_data[ $key ] ) ? '%s' : '%d';
                } else {
                    $update_data[ $key ] = $value;
                    $format[]            = '%d';
                }
            }
        }

        if ( array_key_exists( 'is_recurring', $data ) && empty( $data['is_recurring'] ) ) {
            foreach ( array( 'recurrence_frequency', 'recurrence_interval', 'recurrence_days', 'recurrence_ends_on', 'recurrence_anchor_day' ) as $field ) {
                if ( ! array_key_exists( $field, $update_data ) ) {
                    $format[] = '%s';
                }
                $update_data[ $field ] = null;
            }
        }

        if ( array_key_exists( 'attachment_type', $data ) && empty( $data['attachment_type'] ) ) {
            foreach ( array( 'attachment_url', 'attachment_post_id', 'attachment_filename' ) as $field ) {
                if ( ! array_key_exists( $field, $update_data ) ) {
                    $format[] = '%s';
                }
                $update_data[ $field ] = null;
            }
        }

        if ( isset( $update_data['deadline_days_after_start'] ) ) {
            $start_date_for_calc = $update_data['start_date'] ?? $current_task->start_date;

            if ( ! empty( $start_date_for_calc ) ) {
                $start = new DateTime( $start_date_for_calc );
                $start->add( new DateInterval( 'P' . absint( $update_data['deadline_days_after_start'] ) . 'D' ) );
                $new_deadline = $start->format( 'Y-m-d' );

                if ( ! isset( $update_data['deadline'] ) ) {
                    $update_data['deadline'] = $new_deadline;
                    $format[]                = '%s';
                }
            }
        }

        $final_is_recurring = array_key_exists( 'is_recurring', $data )
            ? ! empty( $data['is_recurring'] )
            : ! empty( $current_task->is_recurring );
        $final_frequency = array_key_exists( 'recurrence_frequency', $data )
            ? $data['recurrence_frequency']
            : $current_task->recurrence_frequency;
        $final_start_date = array_key_exists( 'start_date', $data )
            ? $data['start_date']
            : $current_task->start_date;

        if ( $final_is_recurring && 'monthly' === $final_frequency && $final_start_date ) {
            if ( array_key_exists( 'recurrence_anchor_day', $data ) ) {
                $update_data['recurrence_anchor_day'] = max( 1, min( 31, (int) $data['recurrence_anchor_day'] ) );
            } elseif (
                array_key_exists( 'start_date', $data )
                || array_key_exists( 'recurrence_frequency', $data )
                || empty( $current_task->recurrence_anchor_day )
            ) {
                $update_data['recurrence_anchor_day'] = (int) substr( $final_start_date, 8, 2 );
            }
        } elseif ( ! empty( $current_task->recurrence_anchor_day ) || array_key_exists( 'recurrence_anchor_day', $update_data ) ) {
            $update_data['recurrence_anchor_day'] = null;
        }

        $logged_fields = array(
            'deadline_reminder_sent_for' => true,
            'missed_deadline_notified'    => true,
            'recurrence_anchor_day'       => true,
        );
        $new_absolute_deadline = $update_data['deadline'] ?? $current_task->deadline;

        if ( $new_absolute_deadline !== $current_task->deadline ) {
            $changes_for_buffer[]       = array( 'field' => 'deadline', 'from' => $current_task->deadline, 'to' => $new_absolute_deadline );
            $logged_fields['deadline'] = true;
        }

        if ( array_key_exists( 'deadline_days_after_start', $update_data ) && $update_data['deadline_days_after_start'] != $current_task->deadline_days_after_start ) {
            if ( ! isset( $logged_fields['deadline'] ) ) {
                $changes_for_buffer[] = array(
                    'field' => 'deadline_days_after_start',
                    'from'  => $current_task->deadline_days_after_start,
                    'to'    => $update_data['deadline_days_after_start'],
                );
            }
        }

        $logged_fields['deadline_days_after_start'] = true;

        foreach ( $update_data as $key => $value ) {
            if ( isset( $logged_fields[ $key ] ) ) {
                continue;
            }

            if ( md5( (string) $value ) !== md5( (string) $current_task->$key ) ) {
                if ( 'completed_at' === $key ) {
                    continue;
                }

                $from_val             = 'description' === $key ? '...' : $current_task->$key;
                $to_val               = 'description' === $key ? '...' : $value;
                $changes_for_buffer[] = array( 'field' => $key, 'from' => $from_val, 'to' => $to_val );
            }
        }

        if ( ! DatabaseContext::beginTransaction() ) {
            return new WP_Error( 'pandatask_transaction_failed', __( 'The task update could not start a database transaction.', 'pandatask' ), array( 'status' => 500 ) );
        }

        $attachment_sync = null;

        try {
            if ( 'complete' === $lifecycle_operation ) {
                // Serialize completion accounting on the task row. A concurrent
                // request waits here, then observes the committed done status.
                $transaction_status = $this->repository->lockTaskStatusForUpdate( $task_id );
                if ( null === $transaction_status ) {
                    DatabaseContext::rollback();
                    return false;
                }
                if ( 'done' === $transaction_status ) {
                    DatabaseContext::rollback();
                    return $this->alreadyCompletedError();
                }
            }

            if ( isset( $data['predecessors'] ) ) {
                $new_predecessors = array_map( 'absint', (array) $data['predecessors'] );
                $new_predecessors = array_unique( array_filter( $new_predecessors ) );
                $current_rels     = $this->repository->getTaskPredecessorIds( $task_id );
                $to_add           = array_diff( $new_predecessors, $current_rels );
                $to_remove        = array_diff( $current_rels, $new_predecessors );

            foreach ( $to_remove as $predecessor_id ) {
                if ( ! $this->repository->deleteTaskRelationship( $task_id, $predecessor_id ) ) {
                    throw new Exception( 'Failed to remove a task dependency.' );
                }
                $changes_for_buffer[] = array( 'field' => 'dependency_removed', 'from' => $predecessor_id, 'to' => '', 'comment' => $change_comment );
            }

            foreach ( $to_add as $predecessor_id ) {
                if ( $predecessor_id === $task_id ) {
                    continue;
                }

                if ( ! $this->repository->insertTaskRelationship( $task_id, $predecessor_id ) ) {
                    throw new Exception( 'Failed to add a task dependency.' );
                }
                $changes_for_buffer[] = array( 'field' => 'dependency_added', 'from' => '', 'to' => $predecessor_id, 'comment' => $change_comment );
            }
        }

        $assignment_changes = array(
            'assignee'   => array( 'added' => array(), 'removed' => array() ),
            'supervisor' => array( 'added' => array(), 'removed' => array() ),
        );

        if ( isset( $data['assigned_persons'] ) || isset( $data['supervisor_persons'] ) ) {
            $assigned_persons = isset( $data['assigned_persons'] ) ? $data['assigned_persons'] : ( $current_task->assigned_user_ids ?? array() );
            $supervisor_persons = isset( $data['supervisor_persons'] ) ? $data['supervisor_persons'] : ( $current_task->supervisor_user_ids ?? array() );
            $assignment_changes = $this->updateTaskAssignments( $task_id, $assigned_persons, $supervisor_persons );

            foreach ( $assignment_changes['assignee']['added'] as $name ) {
                $changes_for_buffer[] = array( 'field' => 'assignee_added', 'from' => '', 'to' => $name, 'comment' => $change_comment );
            }

            foreach ( $assignment_changes['assignee']['removed'] as $name ) {
                $changes_for_buffer[] = array( 'field' => 'assignee_removed', 'from' => $name, 'to' => '', 'comment' => $change_comment );
            }

            foreach ( $assignment_changes['supervisor']['added'] as $name ) {
                $changes_for_buffer[] = array( 'field' => 'supervisor_added', 'from' => '', 'to' => $name, 'comment' => $change_comment );
            }

            foreach ( $assignment_changes['supervisor']['removed'] as $name ) {
                $changes_for_buffer[] = array( 'field' => 'supervisor_removed', 'from' => $name, 'to' => '', 'comment' => $change_comment );
            }
        }

        $is_completing = isset( $update_data['status'] ) && 'done' === $update_data['status'] && 'done' !== $current_task->status;

        if ( ! empty( $update_data ) ) {
            $update_data['updated_at'] = gmdate( 'Y-m-d H:i:s' );
            $format = $this->formatsForTaskData( $update_data );
            $update_result = $this->repository->updateTask( $task_id, $update_data, $format );

            if ( false === $update_result ) {
                throw new Exception( 'The task database update failed.' );
            }

        }

        $current_occurrence = $this->occurrence_repository->findCurrentForTask( $task_id );
        $snapshot_fields = array( 'board_name', 'name', 'project_id', 'category_id', 'start_date', 'deadline', 'estimated_effort_seconds' );
        if (
            $current_occurrence
            && 'open' === $current_occurrence->state
            && array_intersect( $snapshot_fields, array_keys( $update_data ) )
        ) {
            $snapshot_task = $this->task_repository->findById( $task_id );
            if ( ! $snapshot_task || ! $this->occurrence_repository->refreshOpenSnapshot( $current_occurrence->id, $snapshot_task, $actor_id ) ) {
                throw new Exception( 'The open work occurrence snapshot could not be refreshed.' );
            }
            $current_occurrence = $this->occurrence_repository->findCurrentForTask( $task_id );
        }
        $work_log_enabled = $this->feature_settings->workLogEnabled();

        if ( $is_completing && $current_occurrence ) {
            if ( ! $this->occurrence_repository->setState( $current_occurrence->id, 'completed', $actor_id ) ) {
                throw new Exception( 'The work occurrence could not be completed.' );
            }

            if ( ! $work_log_enabled ) {
                // Completing tasks remains available when Work Log is disabled,
                // but the optional time-tracking state must not be created.
            } elseif ( is_array( $completion ) ) {
                if ( empty( $completion['skip_personal_resolution'] ) ) {
                    $completion_user_id = ! empty( $completion['user_id'] ) ? absint( $completion['user_id'] ) : $actor_id;
                    $resolution = $this->task_time_service->resolveCurrentOccurrence(
                        $task_id,
                        $completion_user_id,
                        $completion['actual_seconds'] ?? null,
                        ! empty( $completion['not_tracked'] ),
                        $actor_id,
                        array(
                            'work_items' => is_array( $completion['work_items'] ?? null ) ? $completion['work_items'] : array(),
                            'residual'   => is_array( $completion['residual'] ?? null ) ? $completion['residual'] : array(),
                        )
                    );
                    if ( is_wp_error( $resolution ) ) {
                        DatabaseContext::rollback();
                        ProtectedAttachmentService::rollbackSync( $attachment_sync );
                        return $resolution;
                    }
                }
            } elseif ( $actor_id > 0 && ! $this->task_time_service->markUnresolved( $task_id, $actor_id, $actor_id ) ) {
                throw new Exception( 'The unresolved task time could not be recorded.' );
            }

            if ( $work_log_enabled && ! $this->task_time_service->ensureUnresolvedForUsers( $task_id, (array) ( $current_task->assigned_user_ids ?? array() ), $actor_id ) ) {
                throw new Exception( 'Assignee task-time states could not be preserved.' );
            }
        }

        $is_reopening = isset( $update_data['status'] ) && 'done' !== $update_data['status'] && 'done' === $current_task->status;
        if ( $is_reopening && $current_occurrence && 'reopen' === $lifecycle_operation ) {
            if ( ! $this->occurrence_repository->setState( $current_occurrence->id, 'open', $actor_id ) ) {
                throw new Exception( 'The completed work occurrence could not be reopened.' );
            }
            if ( ! $this->occurrence_repository->setCurrentOccurrence( $task_id, $current_occurrence->id ) ) {
                throw new Exception( 'The reopened work occurrence could not remain current.' );
            }
            if ( $work_log_enabled && ! $this->task_time_service->reviseOnReopen( (int) $current_occurrence->id, $actor_id ) ) {
                throw new Exception( 'The reopened task time could not be revised.' );
            }
        }

        if ( in_array( $lifecycle_operation, array( 'rollover', 'skip' ), true ) && $current_occurrence ) {
            $old_state = 'skip' === $lifecycle_operation ? 'skipped' : 'completed';
            if ( ! $this->occurrence_repository->setState( $current_occurrence->id, $old_state, $actor_id ) ) {
                throw new Exception( 'The previous work occurrence could not be closed.' );
            }
            $next_task = clone $current_task;
            foreach ( $update_data as $field => $value ) {
                $next_task->$field = $value;
            }
            $next_occurrence_id = $this->occurrence_repository->createForTask(
                $next_task,
                $this->occurrence_repository->nextSequence( $task_id ),
                'open',
                $actor_id
            );
            if ( ! $next_occurrence_id || ! $this->occurrence_repository->setCurrentOccurrence( $task_id, $next_occurrence_id ) ) {
                throw new Exception( 'The next work occurrence could not be created.' );
            }
        }

        if ( $project_is_changing && ! empty( $descendant_ids ) ) {
            if ( ! $this->repository->updateProjectForTasks( $descendant_ids, $next_project_id ) ) {
                throw new Exception( 'The descendant project update failed.' );
            }

            foreach ( $descendant_records as $descendant ) {
                $old_project_id = $descendant->project_id ? (int) $descendant->project_id : null;

                if ( $old_project_id === $next_project_id ) {
                    continue;
                }

                if (
                    ! $this->history_service->addEntry(
                        $descendant->id,
                        $actor_id,
                        'project_id',
                        $old_project_id ?: '',
                        $next_project_id ?: '',
                        __( 'Inherited from the parent task project.', 'pandatask' )
                    )
                ) {
                    throw new Exception( 'The descendant project history update failed.' );
                }
            }
        }

        $attachment_sync = ProtectedAttachmentService::syncTask( $task_id );

        if ( is_wp_error( $attachment_sync ) ) {
            throw new Exception( $attachment_sync->get_error_message() );
        }

        if ( $actor_id > 0 ) {
            if ( ! $this->history_buffer_service->buffer( $task_id, $actor_id, $changes_for_buffer, $change_comment ) ) {
                throw new Exception( 'The task change buffer could not be persisted.' );
            }
        } else {
            foreach ( $changes_for_buffer as $change ) {
                if (
                    ! $this->history_service->addEntry(
                        $task_id,
                        0,
                        (string) $change['field'],
                        $change['from'] ?? '',
                        $change['to'] ?? '',
                        $change_comment
                    )
                ) {
                    throw new Exception( 'A system task change could not be recorded.' );
                }
            }
        }

        if ( ! DatabaseContext::commit() ) {
            throw new Exception( 'The task database update could not be committed.' );
        }
        } catch ( Throwable $exception ) {
            DatabaseContext::rollback();
            ProtectedAttachmentService::rollbackSync( $attachment_sync );

            return new WP_Error( 'pandatask_update_failed', __( 'The task could not be updated.', 'pandatask' ), array( 'status' => 500 ) );
        }

        ProtectedAttachmentService::finalizeSync( $attachment_sync );

        foreach ( $descendant_ids as $descendant_id ) {
            DatabaseContext::invalidateTaskCache( $descendant_id );
        }

        $this->sendAssignmentNotifications( $task_id, $assignment_changes, $actor_id );

        if ( $is_completing ) {
            $this->processDependencyCascade( $task_id );
        }

        $this->history_buffer_service->schedule( $task_id, $actor_id );

        $old_users = array_merge(
            ! empty( $current_task->assigned_user_ids ) ? $current_task->assigned_user_ids : array(),
            ! empty( $current_task->supervisor_user_ids ) ? $current_task->supervisor_user_ids : array()
        );
        $new_users = array_merge(
            isset( $data['assigned_persons'] ) ? $data['assigned_persons'] : ( $current_task->assigned_user_ids ?? array() ),
            isset( $data['supervisor_persons'] ) ? $data['supervisor_persons'] : ( $current_task->supervisor_user_ids ?? array() )
        );
        $all_affected_users = array_unique(
            array_merge(
                $old_users,
                $new_users,
                $descendant_user_ids,
                array( (int) ( $current_task->creator_id ?? 0 ) )
            )
        );

        $this->cache_invalidator->invalidateTask( $task_id, $current_task->board_name, $all_affected_users );

        if ( $board_is_changing ) {
            $this->cache_invalidator->invalidateBoard( $next_board_name, array( 'tasks', 'projects', 'parent_tasks', 'reports' ), $all_affected_users );
        }

        $updated_task = $this->task_repository->findById( $task_id );
        $this->dispatchLifecycleEvent(
            'pandatask_task_changed',
            $task_id,
            $current_task,
            $updated_task,
            $changes_for_buffer,
            $actor_id,
            $change_comment
        );

        return true;
    }

    public function completeTask( $task_id, array $completion, $change_comment = '', $actor_id = null ) {
        $actor_id = null === $actor_id ? get_current_user_id() : (int) $actor_id;
        return $this->updateTask(
            (int) $task_id,
            array( 'status' => 'done' ),
            $change_comment,
            $actor_id,
            $completion,
            'complete'
        );
    }

    public function processBufferedChanges( $task_id, $actor_id ) {
        return $this->history_buffer_service->process( (int) $task_id, (int) $actor_id );
    }

    public function recoverBufferedChanges() {
        return $this->history_buffer_service->recoverDue();
    }

    public function deleteTask( $task_id, $delete_scope = null ) {
        $task_id         = (int) $task_id;
        $task_to_delete = $this->task_repository->findById( $task_id );

        if ( ! $task_to_delete ) {
            return false;
        }

        $delete_scope = null === $delete_scope ? null : sanitize_key( $delete_scope );

        if ( ! in_array( $delete_scope, array( null, 'single', 'this', 'all', 'series', 'future' ), true ) ) {
            return new WP_Error( 'pandatask_invalid_delete_scope', __( 'Invalid recurring-task deletion scope.', 'pandatask' ), array( 'status' => 422 ) );
        }

        if ( $task_to_delete->is_recurring && in_array( $delete_scope, array( 'single', 'this' ), true ) ) {
            $next_date_str = $this->recurrence_calculator->next(
                $task_to_delete->start_date,
                $task_to_delete->recurrence_frequency,
                $task_to_delete->recurrence_interval,
                $task_to_delete->recurrence_days,
                (int) ( $task_to_delete->recurrence_anchor_day ?? 0 )
            );

            if ( $next_date_str && ( ! $task_to_delete->recurrence_ends_on || $next_date_str <= $task_to_delete->recurrence_ends_on ) ) {
                $next_start_date  = new DateTime( $next_date_str );
                $new_deadline_date = clone $next_start_date;

                if ( ! empty( $task_to_delete->deadline_days_after_start ) && is_numeric( $task_to_delete->deadline_days_after_start ) ) {
                    $new_deadline_date->add( new DateInterval( 'P' . absint( $task_to_delete->deadline_days_after_start ) . 'D' ) );
                } else {
                    $old_start    = new DateTime( $task_to_delete->start_date );
                    $old_deadline = new DateTime( $task_to_delete->deadline );
                    $duration     = $old_start->diff( $old_deadline );
                    $new_deadline_date->add( $duration );
                }

                $update_data = array(
                    'start_date'   => $next_start_date->format( 'Y-m-d' ),
                    'deadline'     => $new_deadline_date->format( 'Y-m-d' ),
                    'status'       => 'pending',
                    'completed_at' => null,
                    'deadline_reminder_sent_for' => null,
                    'missed_deadline_notified' => 0,
                    'recurrence_anchor_day' => (int) ( $task_to_delete->recurrence_anchor_day ?? 0 ),
                );

                $result = $this->updateTask(
                    $task_id,
                    $update_data,
                    sprintf(
                        /* translators: %s: skipped recurring occurrence date. */
                        __( 'Skipped recurring occurrence scheduled for %s.', 'pandatask' ),
                        $task_to_delete->start_date
                    ),
                    get_current_user_id(),
                    null,
                    'skip'
                );

                if ( true !== $result ) {
                    if ( is_wp_error( $result ) ) {
                        return $result;
                    }

                    return new WP_Error( 'pandatask_update_failed', __( 'The recurring task could not be advanced.', 'pandatask' ), array( 'status' => 500 ) );
                }

                return true;
            }
        }

        if ( ! DatabaseContext::beginTransaction() ) {
            return new WP_Error( 'pandatask_transaction_failed', __( 'The task deletion could not start a database transaction.', 'pandatask' ), array( 'status' => 500 ) );
        }

        try {
            if ( ! $this->occurrence_repository->tombstoneTaskOccurrences( $task_id, get_current_user_id() ) ) {
                throw new Exception( 'The task work occurrences could not be preserved.' );
            }
            if (
                ! $this->repository->deleteTaskAssignments( $task_id )
                || ! $this->repository->deleteTaskComments( $task_id )
                || ! $this->repository->deleteTaskHistory( $task_id )
                || ! $this->repository->deleteTaskChangeBuffers( $task_id )
                || ! $this->repository->deleteTaskRelationships( $task_id )
                || false === $this->repository->unlinkChildTasks( $task_id )
                || false === $this->repository->deleteTask( $task_id )
            ) {
                throw new Exception( 'A related task record could not be deleted.' );
            }

            if ( ! DatabaseContext::commit() ) {
                throw new Exception( 'The task deletion could not be committed.' );
            }
        } catch ( Throwable $exception ) {
            DatabaseContext::rollback();

            return new WP_Error( 'pandatask_delete_failed', __( 'The task could not be deleted.', 'pandatask' ), array( 'status' => 500 ) );
        }

        ProtectedAttachmentService::deleteTaskFiles( $task_id );

        $all_affected_users = array_unique(
            array_merge(
                ! empty( $task_to_delete->assigned_user_ids ) ? $task_to_delete->assigned_user_ids : array(),
                ! empty( $task_to_delete->supervisor_user_ids ) ? $task_to_delete->supervisor_user_ids : array(),
                array( (int) ( $task_to_delete->creator_id ?? 0 ) )
            )
        );

        $this->cache_invalidator->invalidateTask( $task_id, $task_to_delete->board_name, $all_affected_users );

        $this->dispatchLifecycleEvent( 'pandatask_task_deleted', $task_id, $task_to_delete, get_current_user_id() );

        return true;
    }

    public function processDependencyCascade( $completed_task_id ) {
        $successors = $this->repository->findSuccessorIds( $completed_task_id );
        $stats = array( 'started' => 0, 'deferred' => 0, 'failed' => 0 );

        if ( empty( $successors ) ) {
            return $stats;
        }

        foreach ( $successors as $successor_id ) {
            if ( ! $this->task_repository->isBlocked( $successor_id ) ) {
                $task = $this->task_repository->findById( $successor_id );

                if ( ! $task ) {
                    continue;
                }

                if ( 'pending' === $task->status ) {
                    $today = wp_date( 'Y-m-d' );

                    if ( ! empty( $task->start_date ) && $task->start_date > $today ) {
                        $stats['deferred']++;
                        continue;
                    }

                    $result = $this->updateTask(
                        $successor_id,
                        array( 'status' => 'in-progress' ),
                        "Auto-started via dependency: Predecessor #{$completed_task_id} completed.",
                        0
                    );
                    $stats[ true === $result ? 'started' : 'failed' ]++;
                }
            }
        }

        return $stats;
    }

    public function checkTasksToStart() {
        $today = wp_date( 'Y-m-d' );
        $tasks = $this->repository->findPendingTasksToStart( $today );
        $started = 0;

        foreach ( $tasks as $task ) {
            $result = $this->updateTask(
                $task->id,
                array(
                    'status' => 'in-progress',
                ),
                '',
                0
            );

            if ( true === $result ) {
                $started++;
            }
        }

        return $started;
    }

    public function rollOverCompletedRecurringTasks() {
        $today             = wp_date( 'Y-m-d' );
        $tasks_to_roll_over = $this->repository->findRecurringTasksToRollOver( $today );
        $stats = array(
            'scanned'  => count( $tasks_to_roll_over ),
            'advanced' => 0,
            'disabled' => 0,
            'failed'   => 0,
        );

        foreach ( $tasks_to_roll_over as $task ) {
            $next_occurrence = $this->recurrence_calculator->next(
                $task->start_date,
                $task->recurrence_frequency,
                $task->recurrence_interval,
                $task->recurrence_days,
                (int) ( $task->recurrence_anchor_day ?? 0 )
            );
            $current_start_date = $next_occurrence
                ? $this->recurrence_calculator->onOrAfter(
                $next_occurrence,
                $today,
                $task->recurrence_frequency,
                $task->recurrence_interval,
                $task->recurrence_days,
                (int) ( $task->recurrence_anchor_day ?? 0 )
                )
                : null;

            if ( ! $current_start_date ) {
                $result = $this->updateTask( $task->id, array( 'is_recurring' => 0 ), '', 0 );
                $stats[ true === $result ? 'disabled' : 'failed' ]++;
                continue;
            }

            if ( $task->recurrence_ends_on && $current_start_date > $task->recurrence_ends_on ) {
                $result = $this->updateTask( $task->id, array( 'is_recurring' => 0 ), '', 0 );
                $stats[ true === $result ? 'disabled' : 'failed' ]++;
                continue;
            }

            $next_start_date  = new DateTime( $current_start_date );
            $new_deadline_date = clone $next_start_date;

            if ( ! empty( $task->deadline_days_after_start ) && is_numeric( $task->deadline_days_after_start ) ) {
                $new_deadline_date->add( new DateInterval( 'P' . absint( $task->deadline_days_after_start ) . 'D' ) );
            } else {
                $old_start    = new DateTime( $task->start_date );
                $old_deadline = new DateTime( $task->deadline );
                $duration     = $old_start->diff( $old_deadline );
                $new_deadline_date->add( $duration );
            }

            $update_data = array(
                'start_date'   => $next_start_date->format( 'Y-m-d' ),
                'deadline'     => $new_deadline_date->format( 'Y-m-d' ),
                'status'       => 'pending',
                'completed_at' => null,
                'recurrence_anchor_day' => (int) ( $task->recurrence_anchor_day ?? 0 ),
            );

            $result = $this->updateTask( $task->id, $update_data, '', 0, null, 'rollover' );

            if ( true !== $result ) {
                $stats['failed']++;
                continue;
            }

            $stats['advanced']++;
        }

        return $stats;
    }

    public function updateTaskAssignments( $task_id, $assigned_user_ids = array(), $supervisor_user_ids = array() ) {
        $assignee_changes   = $this->updateTaskRoleAssignments( $task_id, $assigned_user_ids, 'assignee' );
        $supervisor_changes = $this->updateTaskRoleAssignments( $task_id, $supervisor_user_ids, 'supervisor' );

        return array(
            'assignee'   => $assignee_changes,
            'supervisor' => $supervisor_changes,
        );
    }

    private function sendAssignmentNotifications( $task_id, $assignment_changes, $actor_id ) {
        foreach ( array( 'assignee', 'supervisor' ) as $role ) {
            $added_user_ids = array_map( 'intval', array_keys( $assignment_changes[ $role ]['added'] ?? array() ) );
            $recipient_ids = array_values( array_diff( $added_user_ids, array( (int) $actor_id ) ) );

            if ( empty( $recipient_ids ) ) {
                continue;
            }

            EmailNotifier::send_assignment_notification( $task_id, $recipient_ids, $role );

            foreach ( $recipient_ids as $user_id ) {
                BuddyPressNotifier::add_assignment_notification( $task_id, $user_id, $actor_id, $role );
            }
        }
    }

    /**
     * Preserve legacy creator accounting while initializing all assignee states
     * for tasks that are created already completed.
     */
    private function preserveCompletedTaskTimeStates( $task_id, $creator_id, array $assigned_user_ids, $actor_id ) {
        if ( ! $this->feature_settings->workLogEnabled() ) {
            return true;
        }

        if ( $creator_id > 0 && ! $this->task_time_service->markUnresolved( $task_id, $creator_id, $actor_id ) ) {
            return false;
        }

        return $this->task_time_service->ensureUnresolvedForUsers( $task_id, $assigned_user_ids, $actor_id );
    }

    private function alreadyCompletedError() {
        return new WP_Error(
            'pandatask_task_already_completed',
            __( 'This task is already completed. Reopen it before completing it again, or use task time resolution for post-completion accounting.', 'pandatask' ),
            array( 'status' => 409 )
        );
    }

    private function updateTaskRoleAssignments( $task_id, $user_ids, $role = 'assignee' ) {
        $changes = array( 'added' => array(), 'removed' => array() );

        $new_user_ids = array_map( 'absint', (array) $user_ids );
        $new_user_ids = array_filter( $new_user_ids );

        $current_user_ids = $this->repository->findRoleAssignmentUserIds( $task_id, $role );
        $users_to_remove  = array_diff( $current_user_ids, $new_user_ids );

        if ( ! empty( $users_to_remove ) ) {
            if ( ! $this->repository->deleteRoleAssignments( $task_id, $role, $users_to_remove ) ) {
                throw new Exception( 'Failed to remove task assignments.' );
            }

            foreach ( $users_to_remove as $removed_user_id ) {
                $user = get_userdata( $removed_user_id );
                $changes['removed'][ $removed_user_id ] = $user ? $user->display_name : 'User ' . $removed_user_id;
            }
        }

        $users_to_add = array_diff( $new_user_ids, $current_user_ids );

        if ( ! empty( $users_to_add ) ) {
            foreach ( $users_to_add as $user_id ) {
                if ( ! $this->repository->insertRoleAssignment( $task_id, $user_id, $role ) ) {
                    throw new Exception( 'Failed to add a task assignment.' );
                }
                $user = get_userdata( $user_id );
                $changes['added'][ $user_id ] = $user ? $user->display_name : 'User ' . $user_id;
            }
        }

        return $changes;
    }

    /**
     * Lifecycle hooks are extension points that run only after persistence has
     * committed. A failing integration must not turn a committed task mutation
     * into an apparent API failure.
     */
    private function dispatchLifecycleEvent( $hook, ...$args ) {
        try {
            do_action( $hook, ...$args );
        } catch ( Throwable $exception ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf( 'Pandatask lifecycle hook %s failed: %s', $hook, $exception->getMessage() ) );
            }
        }
    }

    /**
     * Keep wpdb formats aligned with associative data after derived fields are
     * added or overwritten.
     */
    private function formatsForTaskData( array $data ): array {
        $integer_fields = array(
            'category_id',
            'project_id',
            'creator_id',
            'estimated_effort_seconds',
            'current_work_occurrence_id',
            'priority',
            'deadline_days_after_start',
            'notify_deadline',
            'notify_days_before',
            'archived',
            'parent_task_id',
            'follow_up_of_task_id',
            'is_recurring',
            'recurrence_interval',
            'recurrence_anchor_day',
            'attachment_post_id',
            'missed_deadline_notified',
        );
        $formats = array();

        foreach ( $data as $field => $value ) {
            $formats[] = null !== $value && in_array( $field, $integer_fields, true ) ? '%d' : '%s';
        }

        return $formats;
    }
}
