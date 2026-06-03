<?php

namespace Pandatask\Application\Task;

use DateInterval;
use DateTime;
use Exception;
use Pandatask\Infrastructure\Notifications\BuddyPressNotifier;
use Pandatask\Infrastructure\Notifications\EmailNotifier;
use Pandatask\Infrastructure\Persistence\DatabaseContext;
use Pandatask\Infrastructure\Persistence\TaskCommandRepository;
use Pandatask\Infrastructure\Persistence\TaskRepository;

final class TaskMutationService {

    private $repository;

    private $task_repository;

    private $history_service;

    public function __construct( $repository = null, $task_repository = null, $history_service = null ) {
        $this->repository      = $repository ?: new TaskCommandRepository();
        $this->task_repository = $task_repository ?: new TaskRepository();
        $this->history_service = $history_service ?: new HistoryService();
    }

    public function createTask( $data ) {
        $is_recurring = ! empty( $data['is_recurring'] ) ? 1 : 0;
        $task_data    = array(
            'board_name'               => $data['board_name'],
            'name'                     => $data['name'],
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

        $format = array(
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%d',
            '%d',
            '%d',
            '%d',
            '%d',
            '%d',
            '%d',
            '%d',
            '%s',
            '%d',
            '%s',
            '%s',
            '%s',
            '%s',
            '%d',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
        );

        if ( is_null( $task_data['category_id'] ) ) {
            $format[6] = '%s';
        }
        if ( is_null( $task_data['project_id'] ) ) {
            $format[7] = '%s';
        }
        if ( is_null( $task_data['deadline_days_after_start'] ) ) {
            $format[9] = '%s';
        }
        if ( is_null( $task_data['parent_task_id'] ) ) {
            $format[12] = '%s';
        }
        if ( is_null( $task_data['recurrence_interval'] ) ) {
            $format[15] = '%s';
        }
        if ( is_null( $task_data['attachment_post_id'] ) ) {
            $format[19] = '%s';
        }

        $task_id = $this->repository->insertTask( $task_data, $format );

        if ( ! $task_id ) {
            return false;
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

                $this->repository->insertTaskRelationship( $task_id, $predecessor_id );
            }
        }

        if ( preg_match( '/^user_(\d+)$/', $task_data['board_name'], $matches ) ) {
            $board_owner_id = intval( $matches[1] );

            if ( $board_owner_id > 0 && ! in_array( $board_owner_id, $assigned_persons ) ) {
                $assigned_persons[] = $board_owner_id;
            }
        }

        $assignment_changes = $this->updateTaskAssignments( $task_id, $assigned_persons, $supervisor_persons );
        $creator_id         = get_current_user_id();

        if ( ! empty( $assignment_changes['assignee']['added'] ) ) {
            $new_assignee_ids     = array_keys( $assignment_changes['assignee']['added'] );
            $assignees_to_notify = array_diff( $new_assignee_ids, array( $creator_id ) );

            if ( ! empty( $assignees_to_notify ) ) {
                EmailNotifier::send_assignment_notification( $task_id, $assignees_to_notify, 'assignee' );

                foreach ( $assignees_to_notify as $user_id ) {
                    BuddyPressNotifier::add_assignment_notification( $task_id, $user_id, $creator_id, 'assignee' );
                }
            }
        }

        if ( ! empty( $assignment_changes['supervisor']['added'] ) ) {
            $new_supervisor_ids     = array_keys( $assignment_changes['supervisor']['added'] );
            $supervisors_to_notify = array_diff( $new_supervisor_ids, array( $creator_id ) );

            if ( ! empty( $supervisors_to_notify ) ) {
                EmailNotifier::send_assignment_notification( $task_id, $supervisors_to_notify, 'supervisor' );

                foreach ( $supervisors_to_notify as $user_id ) {
                    BuddyPressNotifier::add_assignment_notification( $task_id, $user_id, $creator_id, 'supervisor' );
                }
            }
        }

        DatabaseContext::invalidateBoardCache( $task_data['board_name'] );
        delete_transient( 'pandat69_all_board_names' );

        $all_affected_users = array_unique( array_merge( $assigned_persons, $supervisor_persons ) );

        foreach ( $all_affected_users as $user_id ) {
            DatabaseContext::invalidateUserCache( (int) $user_id );
        }

        $this->history_service->addEntry( $task_id, get_current_user_id(), 'task_created', '', $task_data['name'] );

        return $task_id;
    }

    public function updateTask( $task_id, $data, $change_comment = '', $actor_id = null ) {
        $task_id   = (int) $task_id;
        $actor_id  = is_null( $actor_id ) ? get_current_user_id() : (int) $actor_id;
        $current_task = $this->task_repository->findById( $task_id );

        if ( ! $current_task ) {
            return false;
        }

        if ( isset( $data['status'] ) && ( 'in-progress' === $data['status'] || 'done' === $data['status'] ) ) {
            if ( $this->task_repository->isBlocked( $task_id ) ) {
                return false;
            }
        }

        $final_deadline       = $data['deadline'] ?? null;
        $is_deadline_changing = isset( $data['deadline'] ) || isset( $data['deadline_days_after_start'] );

        if ( isset( $data['deadline_days_after_start'] ) ) {
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

        $allowed_task_fields = array(
            'board_name',
            'name',
            'description',
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
            'completed_at',
            'is_recurring',
            'recurrence_frequency',
            'recurrence_interval',
            'recurrence_days',
            'recurrence_ends_on',
            'attachment_type',
            'attachment_url',
            'attachment_post_id',
            'attachment_filename',
            'missed_deadline_notified',
        );

        $update_data         = array();
        $format              = array();
        $changes_for_buffer = array();

        foreach ( $data as $key => $value ) {
            if ( ! in_array( $key, $allowed_task_fields, true ) ) {
                continue;
            }

            if ( isset( $data[ $key ] ) ) {
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
                } elseif ( in_array( $key, array( 'board_name', 'name', 'description', 'start_date', 'recurrence_frequency', 'recurrence_days', 'recurrence_ends_on', 'attachment_type', 'attachment_url', 'attachment_filename', 'task_type', 'bug_url' ), true ) ) {
                    $update_data[ $key ] = $value;
                    $format[]            = '%s';
                } elseif ( in_array( $key, array( 'category_id', 'project_id', 'deadline_days_after_start', 'parent_task_id', 'recurrence_interval', 'attachment_post_id' ), true ) ) {
                    $update_data[ $key ] = ! empty( $value ) ? absint( $value ) : null;
                    $format[]            = is_null( $update_data[ $key ] ) ? '%s' : '%d';
                } else {
                    $update_data[ $key ] = $value;
                    $format[]            = '%d';
                }
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

        $logged_fields         = array();
        $new_absolute_deadline = $update_data['deadline'] ?? $current_task->deadline;

        if ( $new_absolute_deadline !== $current_task->deadline ) {
            $changes_for_buffer[]       = array( 'field' => 'deadline', 'from' => $current_task->deadline, 'to' => $new_absolute_deadline );
            $logged_fields['deadline'] = true;
        }

        if ( isset( $update_data['deadline_days_after_start'] ) && $update_data['deadline_days_after_start'] != $current_task->deadline_days_after_start ) {
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

        if ( isset( $data['predecessors'] ) ) {
            $new_predecessors = array_map( 'absint', (array) $data['predecessors'] );
            $new_predecessors = array_unique( array_filter( $new_predecessors ) );
            $current_rels     = $this->repository->getTaskPredecessorIds( $task_id );
            $to_add           = array_diff( $new_predecessors, $current_rels );
            $to_remove        = array_diff( $current_rels, $new_predecessors );

            foreach ( $to_remove as $predecessor_id ) {
                $this->repository->deleteTaskRelationship( $task_id, $predecessor_id );
                $changes_for_buffer[] = array( 'field' => 'dependency_removed', 'from' => $predecessor_id, 'to' => '', 'comment' => $change_comment );
            }

            foreach ( $to_add as $predecessor_id ) {
                if ( $predecessor_id === $task_id ) {
                    continue;
                }

                $this->repository->insertTaskRelationship( $task_id, $predecessor_id );
                $changes_for_buffer[] = array( 'field' => 'dependency_added', 'from' => '', 'to' => $predecessor_id, 'comment' => $change_comment );
            }
        }

        if ( isset( $data['assigned_persons'] ) || isset( $data['supervisor_persons'] ) ) {
            $assignment_changes = $this->updateTaskAssignments( $task_id, $data['assigned_persons'] ?? array(), $data['supervisor_persons'] ?? array() );

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
            $format[]                  = '%s';
            $this->repository->updateTask( $task_id, $update_data, $format );

            if ( isset( $update_data['board_name'] ) && $update_data['board_name'] !== $current_task->board_name ) {
                DatabaseContext::invalidateBoardCache( $current_task->board_name );
                DatabaseContext::invalidateBoardCache( $update_data['board_name'] );
                $changes_for_buffer[] = array( 'field' => 'board_name', 'from' => $current_task->board_name, 'to' => $update_data['board_name'] );
            } else {
                DatabaseContext::invalidateBoardCache( $current_task->board_name );
            }
        }

        if ( $is_completing ) {
            $this->processDependencyCascade( $task_id );
        }

        if ( $actor_id > 0 && ( ! empty( $changes_for_buffer ) || ! empty( $change_comment ) ) ) {
            $transient_key   = 'pandat69_buffered_changes_' . $task_id . '_' . $actor_id;
            $existing_buffer = get_transient( $transient_key );

            if ( ! is_array( $existing_buffer ) || ! isset( $existing_buffer['changes'] ) ) {
                $existing_buffer = array( 'changes' => array(), 'comment' => '' );
            }

            $all_changes  = array_merge( $existing_buffer['changes'], $changes_for_buffer );
            $all_comments = array_filter( array( $existing_buffer['comment'], $change_comment ) );
            $new_comment  = implode( "\n\n", $all_comments );
            $new_buffer   = array( 'changes' => $all_changes, 'comment' => $new_comment );

            set_transient( $transient_key, $new_buffer, 6 * MINUTE_IN_SECONDS );

            if ( wp_next_scheduled( 'pandatask_process_buffered_changes', array( $task_id, $actor_id ) ) ) {
                wp_clear_scheduled_hook( 'pandatask_process_buffered_changes', array( $task_id, $actor_id ) );
            }

            wp_schedule_single_event( time() + ( 5 * MINUTE_IN_SECONDS ), 'pandatask_process_buffered_changes', array( $task_id, $actor_id ) );
        }

        delete_transient( 'pandat69_task_' . $task_id );
        DatabaseContext::invalidateBoardCache( $current_task->board_name );

        $old_users = array_merge(
            ! empty( $current_task->assigned_user_ids ) ? $current_task->assigned_user_ids : array(),
            ! empty( $current_task->supervisor_user_ids ) ? $current_task->supervisor_user_ids : array()
        );
        $new_users = array_merge( $data['assigned_persons'] ?? array(), $data['supervisor_persons'] ?? array() );
        $all_affected_users = array_unique( array_merge( $old_users, $new_users ) );

        foreach ( $all_affected_users as $user_id ) {
            DatabaseContext::invalidateUserCache( (int) $user_id );
        }

        return true;
    }

    public function processBufferedChanges( $task_id, $actor_id ) {
        $transient_key = 'pandat69_buffered_changes_' . $task_id . '_' . $actor_id;
        $buffered_data = get_transient( $transient_key );

        if ( empty( $buffered_data ) || ! is_array( $buffered_data ) ) {
            return;
        }

        $changes            = $buffered_data['changes'] ?? array();
        $aggregated_comment = $buffered_data['comment'] ?? '';

        if ( empty( $changes ) && empty( $aggregated_comment ) ) {
            delete_transient( $transient_key );

            return;
        }

        delete_transient( $transient_key );

        $task = $this->task_repository->findById( $task_id );

        if ( ! $task ) {
            return;
        }

        $final_changes = array();

        foreach ( $changes as $change ) {
            $final_changes[ $change['field'] ][] = $change;
        }

        $log_changes = array();

        foreach ( $final_changes as $field => $change_list ) {
            if ( false !== strpos( $field, '_added' ) || false !== strpos( $field, '_removed' ) ) {
                $names                = wp_list_pluck( $change_list, false !== strpos( $field, '_added' ) ? 'to' : 'from' );
                $log_changes[ $field ] = array( 'values' => array_unique( $names ) );
            } else {
                $last_change          = end( $change_list );
                $log_changes[ $field ] = array( 'from' => $last_change['from'], 'to' => $last_change['to'] );
            }
        }

        if ( ! empty( $log_changes ) ) {
            $this->history_service->addEntry(
                $task_id,
                $actor_id,
                'task_updated_multiple',
                '',
                wp_json_encode( $log_changes ),
                trim( $aggregated_comment )
            );
        }

        $supervisor_ids = ! empty( $task->supervisor_user_ids ) ? array_map( 'intval', $task->supervisor_user_ids ) : array();
        $assignee_ids   = ! empty( $task->assigned_user_ids ) ? array_map( 'intval', $task->assigned_user_ids ) : array();

        if ( in_array( $actor_id, $supervisor_ids, true ) ) {
            $recipients = $assignee_ids;
        } else {
            $recipients = $supervisor_ids;
        }

        $final_recipients = array_unique( array_diff( $recipients, array( $actor_id ) ) );

        if ( ! empty( $final_recipients ) && ! empty( $log_changes ) ) {
            EmailNotifier::send_aggregated_update_notification( $task_id, $final_recipients, $actor_id, $log_changes, trim( $aggregated_comment ), $task );
        }
    }

    public function deleteTask( $task_id, $delete_scope = null ) {
        $task_id         = (int) $task_id;
        $task_to_delete = $this->task_repository->findById( $task_id );

        if ( ! $task_to_delete ) {
            return false;
        }

        if ( $task_to_delete->is_recurring && 'single' === $delete_scope ) {
            $next_date_str = $this->calculateNextRecurrenceDate(
                $task_to_delete->start_date,
                $task_to_delete->recurrence_frequency,
                $task_to_delete->recurrence_interval,
                $task_to_delete->recurrence_days
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
                );

                $result = $this->repository->updateTask( $task_id, $update_data, array( '%s', '%s', '%s', '%s' ) );
                $this->history_service->addEntry( $task_id, get_current_user_id(), 'recurring_instance_skipped', 'Skipped instance for ' . $task_to_delete->start_date );

                delete_transient( 'pandat69_task_' . $task_id );
                DatabaseContext::invalidateBoardCache( $task_to_delete->board_name );

                return false !== $result;
            }
        }

        $this->history_service->addEntry( $task_id, get_current_user_id(), 'task_deleted', $task_to_delete->name );
        delete_transient( 'pandat69_task_' . $task_id );

        $this->repository->deleteTaskAssignments( $task_id );
        $this->repository->deleteTaskComments( $task_id );
        $this->repository->deleteTaskHistory( $task_id );

        $result = $this->repository->deleteTask( $task_id );

        if ( false !== $result && $task_to_delete ) {
            $this->repository->unlinkChildTasks( $task_id );
            DatabaseContext::invalidateBoardCache( $task_to_delete->board_name );

            $all_affected_users = array_unique(
                array_merge(
                    ! empty( $task_to_delete->assigned_user_ids ) ? $task_to_delete->assigned_user_ids : array(),
                    ! empty( $task_to_delete->supervisor_user_ids ) ? $task_to_delete->supervisor_user_ids : array()
                )
            );

            foreach ( $all_affected_users as $user_id ) {
                DatabaseContext::invalidateUserCache( (int) $user_id );
            }
        }

        return false !== $result;
    }

    public function processDependencyCascade( $completed_task_id ) {
        $successors = $this->repository->findSuccessorIds( $completed_task_id );

        if ( empty( $successors ) ) {
            return;
        }

        foreach ( $successors as $successor_id ) {
            if ( ! $this->task_repository->isBlocked( $successor_id ) ) {
                $task = $this->task_repository->findById( $successor_id );

                if ( ! $task ) {
                    continue;
                }

                if ( 'pending' === $task->status ) {
                    $update_data = array(
                        'status'     => 'in-progress',
                        'start_date' => current_time( 'Y-m-d' ),
                    );

                    if ( ! empty( $task->deadline_days_after_start ) ) {
                        $start_date = new DateTime( $update_data['start_date'] );
                        $days       = absint( $task->deadline_days_after_start );
                        $start_date->add( new DateInterval( 'P' . $days . 'D' ) );
                        $update_data['deadline'] = $start_date->format( 'Y-m-d' );
                    }

                    $this->updateTask( $successor_id, $update_data, "Auto-started via dependency: Predecessor #{$completed_task_id} completed." );
                }
            }
        }
    }

    public function checkTasksToStart() {
        $today = wp_date( 'Y-m-d' );
        $tasks = $this->repository->findPendingTasksToStart( $today );

        foreach ( $tasks as $task ) {
            $this->updateTask(
                $task->id,
                array(
                    'status' => 'in-progress',
                )
            );
        }

        return count( $tasks );
    }

    public function rollOverCompletedRecurringTasks() {
        $today             = wp_date( 'Y-m-d' );
        $tasks_to_roll_over = $this->repository->findRecurringTasksToRollOver( $today );

        foreach ( $tasks_to_roll_over as $task ) {
            $current_start_date = $task->start_date;

            while ( $current_start_date < $today ) {
                $next_date_str = $this->calculateNextRecurrenceDate(
                    $current_start_date,
                    $task->recurrence_frequency,
                    $task->recurrence_interval,
                    $task->recurrence_days
                );

                if ( ! $next_date_str ) {
                    $current_start_date = null;
                    break;
                }

                $current_start_date = $next_date_str;
            }

            if ( ! $current_start_date ) {
                $this->repository->setTaskRecurringState( $task->id, 0 );
                continue;
            }

            if ( $task->recurrence_ends_on && $current_start_date > $task->recurrence_ends_on ) {
                $this->repository->setTaskRecurringState( $task->id, 0 );
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
            );

            $this->repository->updateTask( $task->id, $update_data, array( '%s', '%s', '%s', '%s' ) );

            delete_transient( 'pandat69_task_' . $task->id );
            DatabaseContext::invalidateBoardCache( $task->board_name );
        }
    }

    public function updateTaskAssignments( $task_id, $assigned_user_ids = array(), $supervisor_user_ids = array() ) {
        $assignee_changes   = $this->updateTaskRoleAssignments( $task_id, $assigned_user_ids, 'assignee' );
        $supervisor_changes = $this->updateTaskRoleAssignments( $task_id, $supervisor_user_ids, 'supervisor' );

        return array(
            'assignee'   => $assignee_changes,
            'supervisor' => $supervisor_changes,
        );
    }

    private function updateTaskRoleAssignments( $task_id, $user_ids, $role = 'assignee' ) {
        $changes = array( 'added' => array(), 'removed' => array() );

        $new_user_ids = array_map( 'absint', (array) $user_ids );
        $new_user_ids = array_filter( $new_user_ids );

        $current_user_ids = $this->repository->findRoleAssignmentUserIds( $task_id, $role );
        $users_to_remove  = array_diff( $current_user_ids, $new_user_ids );

        if ( ! empty( $users_to_remove ) ) {
            $this->repository->deleteRoleAssignments( $task_id, $role, $users_to_remove );

            foreach ( $users_to_remove as $removed_user_id ) {
                $user = get_userdata( $removed_user_id );
                $changes['removed'][ $removed_user_id ] = $user ? $user->display_name : 'User ' . $removed_user_id;
            }
        }

        $users_to_add = array_diff( $new_user_ids, $current_user_ids );

        if ( ! empty( $users_to_add ) ) {
            foreach ( $users_to_add as $user_id ) {
                $this->repository->insertRoleAssignment( $task_id, $user_id, $role );
                $user = get_userdata( $user_id );
                $changes['added'][ $user_id ] = $user ? $user->display_name : 'User ' . $user_id;
            }
        }

        return $changes;
    }

    private function calculateNextRecurrenceDate( $from_date_str, $frequency, $interval, $days_of_week_str ) {
        if ( empty( $from_date_str ) || empty( $frequency ) ) {
            return null;
        }

        try {
            $from_date = new DateTime( $from_date_str );
            $next_date = clone $from_date;
            $interval  = absint( $interval ) ?: 1;

            if ( 'weekly' === $frequency || 'bi-weekly' === $frequency ) {
                $next_date->modify( '+' . $interval . ' week' );
            } elseif ( 'monthly' === $frequency ) {
                $next_date->modify( '+' . $interval . ' month' );
            } elseif ( 'custom_weekly' === $frequency ) {
                if ( empty( $days_of_week_str ) ) {
                    return null;
                }

                $days_of_week = array_map( 'intval', explode( ',', $days_of_week_str ) );
                sort( $days_of_week );

                $current_day_of_week = (int) $from_date->format( 'N' );
                $next_day_found      = false;

                foreach ( $days_of_week as $day ) {
                    if ( $day > $current_day_of_week ) {
                        $next_date->modify( '+' . ( $day - $current_day_of_week ) . ' days' );
                        $next_day_found = true;
                        break;
                    }
                }

                if ( ! $next_day_found ) {
                    $first_day_of_list               = $days_of_week[0];
                    $days_until_next_week_first_day = ( 7 - $current_day_of_week ) + $first_day_of_list;
                    $next_date->modify( '+' . $days_until_next_week_first_day . ' days' );
                }
            } else {
                return null;
            }

            return $next_date->format( 'Y-m-d' );
        } catch ( Exception $exception ) {
            return null;
        }
    }
}
