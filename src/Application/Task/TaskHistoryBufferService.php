<?php

namespace Pandatask\Application\Task;

use DateTimeImmutable;
use Exception;
use Throwable;
use Pandatask\Infrastructure\Notifications\EmailNotifier;
use Pandatask\Infrastructure\Persistence\DatabaseContext;
use Pandatask\Infrastructure\Persistence\TaskChangeBufferRepository;
use Pandatask\Infrastructure\Persistence\TaskRepository;

/**
 * Persists debounce records with the task mutation, then creates one durable
 * history entry after the quiet period.
 */
final class TaskHistoryBufferService {

    private const QUIET_SECONDS = 300;

    private $repository;

    private $history_service;

    private $task_repository;

    public function __construct( $repository = null, $history_service = null, $task_repository = null ) {
        $this->repository = $repository ?: new TaskChangeBufferRepository();
        $this->history_service = $history_service ?: new HistoryService();
        $this->task_repository = $task_repository ?: new TaskRepository();
    }

    /**
     * Must be called inside the same database transaction as the task change.
     *
     * @param array<array<mixed>> $changes Change records.
     */
    public function buffer( $task_id, $actor_id, array $changes, $comment ): bool {
        if ( $actor_id <= 0 || ( empty( $changes ) && '' === trim( (string) $comment ) ) ) {
            return true;
        }

        $deliver_after = gmdate( 'Y-m-d H:i:s', time() + self::QUIET_SECONDS );

        return $this->repository->append( $task_id, $actor_id, $changes, trim( (string) $comment ), $deliver_after );
    }

    public function schedule( $task_id, $actor_id ) {
        if ( $actor_id <= 0 ) {
            return;
        }

        $args = array( (int) $task_id, (int) $actor_id );
        wp_clear_scheduled_hook( 'pandatask_process_buffered_changes', $args );
        wp_schedule_single_event( time() + self::QUIET_SECONDS, 'pandatask_process_buffered_changes', $args );
    }

    /**
     * @return bool True when the group was processed or did not exist.
     */
    public function process( $task_id, $actor_id ): bool {
        if ( ! DatabaseContext::beginTransaction() ) {
            return false;
        }

        $rows = array();
        $legacy_transient_key = 'pandat69_buffered_changes_' . (int) $task_id . '_' . (int) $actor_id;
        $legacy_data = null;

        try {
            $rows = $this->repository->findGroupForUpdate( $task_id, $actor_id );

            if ( empty( $rows ) ) {
                $legacy_data = get_transient( $legacy_transient_key );

                if ( ! is_array( $legacy_data ) ) {
                    DatabaseContext::commit();

                    return true;
                }
            } else {
                $latest_delivery = max( wp_list_pluck( $rows, 'deliver_after' ) );
                $latest_timestamp = ( new DateTimeImmutable( $latest_delivery . ' UTC' ) )->getTimestamp();

                if ( $latest_timestamp > time() ) {
                    DatabaseContext::commit();
                    wp_schedule_single_event(
                        $latest_timestamp,
                        'pandatask_process_buffered_changes',
                        array( (int) $task_id, (int) $actor_id )
                    );

                    return true;
                }
            }

            $task = $this->task_repository->findById( $task_id );

            if ( ! $task ) {
                if ( ! empty( $rows ) && ! $this->repository->deleteIds( wp_list_pluck( $rows, 'id' ) ) ) {
                    throw new Exception( 'Failed to discard buffers for a deleted task.' );
                }

                if ( ! DatabaseContext::commit() ) {
                    throw new Exception( 'Failed to commit deleted-task buffer cleanup.' );
                }

                if ( is_array( $legacy_data ) ) {
                    delete_transient( $legacy_transient_key );
                }

                return true;
            }

            list( $changes, $comment ) = $this->decodeRecords( $rows, $legacy_data );
            $log_changes = $this->aggregateChanges( $changes );

            if (
                ( ! empty( $log_changes ) || '' !== $comment )
                && ! $this->history_service->addEntry(
                    $task_id,
                    $actor_id,
                    'task_updated_multiple',
                    '',
                    wp_json_encode( $log_changes ),
                    $comment
                )
            ) {
                throw new Exception( 'Failed to persist buffered task history.' );
            }

            if ( ! empty( $rows ) && ! $this->repository->deleteIds( wp_list_pluck( $rows, 'id' ) ) ) {
                throw new Exception( 'Failed to remove persisted task buffers.' );
            }

            if ( ! DatabaseContext::commit() ) {
                throw new Exception( 'Failed to commit buffered task history.' );
            }
        } catch ( Throwable $exception ) {
            DatabaseContext::rollback();

            return false;
        }

        if ( is_array( $legacy_data ) ) {
            delete_transient( $legacy_transient_key );
        }

        $this->notifyParticipants( $task, $actor_id, $log_changes, $comment );

        return true;
    }

    public function recoverDue( $limit = 100 ): int {
        $processed = 0;

        foreach ( $this->repository->findDueGroups( $limit ) as $group ) {
            if ( $this->process( (int) $group->task_id, (int) $group->actor_id ) ) {
                $processed++;
            }
        }

        return $processed;
    }

    /**
     * @return array{0:array<array<mixed>>,1:string}
     */
    private function decodeRecords( array $rows, $legacy_data ): array {
        $changes = array();
        $comments = array();

        foreach ( $rows as $row ) {
            $decoded = json_decode( (string) $row->changes, true );

            if ( is_array( $decoded ) ) {
                $changes = array_merge( $changes, $decoded );
            }

            if ( '' !== trim( (string) $row->change_comment ) ) {
                $comments[] = trim( (string) $row->change_comment );
            }
        }

        if ( is_array( $legacy_data ) ) {
            $changes = array_merge( $changes, (array) ( $legacy_data['changes'] ?? array() ) );

            if ( '' !== trim( (string) ( $legacy_data['comment'] ?? '' ) ) ) {
                $comments[] = trim( (string) $legacy_data['comment'] );
            }
        }

        return array( $changes, implode( "\n\n", array_values( array_unique( $comments ) ) ) );
    }

    /**
     * Preserve the first old value and final new value for scalar fields.
     *
     * @param array<array<mixed>> $changes Change records.
     * @return array<string,array<mixed>>
     */
    private function aggregateChanges( array $changes ): array {
        $grouped = array();

        foreach ( $changes as $change ) {
            if ( empty( $change['field'] ) ) {
                continue;
            }

            $grouped[ $change['field'] ][] = $change;
        }

        $result = array();

        foreach ( $grouped as $field => $field_changes ) {
            if ( false !== strpos( $field, '_added' ) || false !== strpos( $field, '_removed' ) ) {
                $value_key = false !== strpos( $field, '_added' ) ? 'to' : 'from';
                $result[ $field ] = array(
                    'values' => array_values( array_unique( wp_list_pluck( $field_changes, $value_key ) ) ),
                );
                continue;
            }

            $first = reset( $field_changes );
            $last = end( $field_changes );
            $result[ $field ] = array(
                'from' => $first['from'] ?? '',
                'to'   => $last['to'] ?? '',
            );
        }

        return $result;
    }

    private function notifyParticipants( $task, $actor_id, array $log_changes, $comment ) {
        if ( empty( $log_changes ) ) {
            return;
        }

        $supervisor_ids = array_map( 'intval', (array) ( $task->supervisor_user_ids ?? array() ) );
        $assignee_ids = array_map( 'intval', (array) ( $task->assigned_user_ids ?? array() ) );
        $recipients = in_array( $actor_id, $supervisor_ids, true ) ? $assignee_ids : $supervisor_ids;
        $recipients = array_values( array_unique( array_diff( $recipients, array( $actor_id ) ) ) );

        if ( ! empty( $recipients ) ) {
            EmailNotifier::send_aggregated_update_notification( $task->id, $recipients, $actor_id, $log_changes, $comment, $task );
        }
    }
}
