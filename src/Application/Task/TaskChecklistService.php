<?php

namespace Pandatask\Application\Task;

use Pandatask\Application\Security\TaskAccessPolicy;
use Pandatask\Domain\Task\TaskChecklist;
use Pandatask\Infrastructure\Persistence\DatabaseContext;
use Pandatask\Infrastructure\Persistence\TaskChecklistRepository;
use WP_Error;

/**
 * Authorized, optimistic-concurrency operations for task checklists.
 */
final class TaskChecklistService {

    private $repository;

    private $task_service;

    private $history_service;

    private $task_access_policy;

    private $cache_invalidator;

    public function __construct( $repository = null, $task_service = null, $history_service = null, $task_access_policy = null, $cache_invalidator = null ) {
        $this->repository          = $repository ?: new TaskChecklistRepository();
        $this->task_service        = $task_service ?: new TaskService();
        $this->history_service     = $history_service ?: new HistoryService();
        $this->task_access_policy  = $task_access_policy ?: new TaskAccessPolicy( $this->task_service );
        $this->cache_invalidator   = $cache_invalidator ?: new TaskCacheInvalidator();
    }

    /**
     * Read a checklist after evaluating the canonical task read policy.
     *
     * @param mixed $task_id Task identifier.
     * @param mixed $user_id Viewer identifier.
     * @return array<string,mixed>|WP_Error
     */
    public function getChecklist( $task_id, $user_id = null ) {
        $task_id = $this->strictTaskId( $task_id );
        if ( is_wp_error( $task_id ) ) {
            return $task_id;
        }

        $permission = $this->task_access_policy->canReadTask( $task_id, $user_id );
        if ( true !== $permission ) {
            return $this->permissionError( $permission );
        }

        try {
            $task = $this->task_service->getTask( $task_id );
        } catch ( \Throwable $exception ) {
            return $this->storedDataError( $exception );
        }
        if ( ! $task ) {
            return $this->notFoundError();
        }

        try {
            $fields = TaskChecklist::fields( $task );
        } catch ( \Throwable $exception ) {
            return $this->storedDataError( $exception );
        }

        $fields['can_edit_checklist'] = true === $this->task_access_policy->canUpdateTask( $task_id, $user_id );

        return $fields;
    }

    /**
     * Replace a checklist atomically when the supplied version is current.
     *
     * @param mixed $task_id Task identifier.
     * @param mixed $items Checklist list.
     * @param mixed $expected_version Version read by the client.
     * @param mixed $actor_id User writing the checklist.
     * @return array<string,mixed>|WP_Error
     */
    public function updateChecklist( $task_id, $items, $expected_version, $actor_id = null, $recurrence_scope = 'this', $expected_series_version = null ) {
        $task_id = $this->strictTaskId( $task_id );
        if ( is_wp_error( $task_id ) ) {
            return $task_id;
        }

        if ( ! is_int( $expected_version ) || $expected_version < 0 ) {
            return new WP_Error(
                'rest_invalid_param',
                __( 'expected_version must be a nonnegative integer.', 'pandatask' ),
                array( 'status' => 422, 'param' => 'expected_version' )
            );
        }

        if ( ! in_array( $recurrence_scope, array( 'this', 'future' ), true ) ) {
            return new WP_Error( 'rest_invalid_param', __( 'recurrence_scope must be this or future.', 'pandatask' ), array( 'status' => 422 ) );
        }

        $normalized = TaskChecklist::normalize( $items );
        if ( is_wp_error( $normalized ) ) {
            return $normalized;
        }

        $actor_id = null === $actor_id ? get_current_user_id() : $actor_id;
        if ( ! is_int( $actor_id ) || $actor_id < 0 ) {
            return new WP_Error(
                'rest_invalid_param',
                __( 'actor_id must be a nonnegative integer.', 'pandatask' ),
                array( 'status' => 422, 'param' => 'actor_id' )
            );
        }

        if ( ! DatabaseContext::beginTransaction() ) {
            return $this->storageError( __( 'The checklist transaction could not be started.', 'pandatask' ) );
        }

        $committed = false;

        try {
            $locked_task = $this->repository->lockTask( $task_id );
            if ( ! $locked_task ) {
                DatabaseContext::rollback();
                return $this->notFoundError();
            }

            // Recheck authorization after acquiring the task row lock.
            $read_permission = $this->task_access_policy->canReadTask( $task_id, $actor_id );
            if ( true !== $read_permission ) {
                DatabaseContext::rollback();
                return $this->permissionError( $read_permission );
            }

            $write_permission = $this->task_access_policy->canUpdateTask( $task_id, $actor_id );
            if ( true !== $write_permission ) {
                DatabaseContext::rollback();
                return $this->permissionError( $write_permission );
            }

            try {
                $current_items = TaskChecklist::decode( $locked_task->checklist_json ?? null );
            } catch ( \Throwable $exception ) {
                DatabaseContext::rollback();
                return $this->storedDataError( $exception );
            }

            $current_version = isset( $locked_task->checklist_version ) ? (int) $locked_task->checklist_version : 0;
            if ( $expected_version !== $current_version ) {
                DatabaseContext::rollback();
                return new WP_Error(
                    'pandatask_checklist_conflict',
                    __( 'The checklist changed elsewhere. Reload it before saving.', 'pandatask' ),
                    array(
                        'status'          => 409,
                        'expected_version' => $expected_version,
                        'checklist_version' => $current_version,
                    )
                );
            }

            if ( 'future' === $recurrence_scope ) {
                $recurrence = new TaskRecurrenceService( null, null, null, null, null, $this->task_access_policy );
                $result = $recurrence->syncChecklist( $task_id, $normalized, $expected_series_version, $actor_id );
                if ( is_wp_error( $result ) ) {
                    DatabaseContext::rollback();
                    return $result;
                }
            }

            if ( $current_items === $normalized ) {
                if ( ! DatabaseContext::commit() ) {
                    DatabaseContext::rollback();
                    return $this->storageError( __( 'The checklist transaction could not be committed.', 'pandatask' ) );
                }
                $committed = true;

                return $this->publicFields( $normalized, $current_version, true );
            }

            $json = wp_json_encode( $normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

            if ( ! is_string( $json ) ) {
                DatabaseContext::rollback();
                return $this->storageError( __( 'The checklist could not be serialized.', 'pandatask' ) );
            }

            $new_version = $current_version + 1;
            if ( ! $this->repository->write( $task_id, $json, $new_version ) ) {
                DatabaseContext::rollback();
                return $this->storageError( __( 'The checklist could not be saved.', 'pandatask' ) );
            }

            $history_result = $this->history_service->addEntry(
                $task_id,
                $actor_id,
                'checklist_updated',
                $this->encodeHistoryValue( $current_items ),
                $this->encodeHistoryValue( $normalized ),
                ''
            );

            if ( ! $history_result || is_wp_error( $history_result ) ) {
                DatabaseContext::rollback();
                return $this->storageError( __( 'The checklist history could not be recorded.', 'pandatask' ) );
            }

            if ( ! DatabaseContext::commit() ) {
                DatabaseContext::rollback();
                return $this->storageError( __( 'The checklist transaction could not be committed.', 'pandatask' ) );
            }
            $committed = true;

            $user_ids = $this->repository->findParticipantUserIdsForTask( $task_id );
            $this->cache_invalidator->invalidateTask( $task_id, (string) ( $locked_task->board_name ?? '' ), (array) $user_ids );

            return $this->publicFields( $normalized, $new_version, true );
        } catch ( \Throwable $exception ) {
            if ( ! $committed ) {
                DatabaseContext::rollback();
            }

            return $this->storageError( __( 'The checklist could not be saved.', 'pandatask' ) );
        }
    }

    private function publicFields( array $items, $version, $can_edit ) {
        $checked = count(
            array_filter(
                $items,
                static function ( $item ) {
                    return true === $item['checked'];
                }
            )
        );

        return array(
            'checklist'         => $items,
            'checklist_version' => (int) $version,
            'checklist_total'   => count( $items ),
            'checklist_checked' => $checked,
            'can_edit_checklist' => (bool) $can_edit,
        );
    }

    private function strictTaskId( $task_id ) {
        if ( ! is_int( $task_id ) || $task_id <= 0 ) {
            return new WP_Error(
                'rest_invalid_param',
                __( 'Task id must be a positive integer.', 'pandatask' ),
                array( 'status' => 422, 'param' => 'id' )
            );
        }

        return $task_id;
    }

    private function permissionError( $permission ) {
        if ( is_wp_error( $permission ) ) {
            return $permission;
        }

        return new WP_Error( 'rest_forbidden', __( 'You do not have permission to access this checklist.', 'pandatask' ), array( 'status' => 403 ) );
    }

    private function notFoundError() {
        return new WP_Error( 'rest_task_not_found', __( 'Task not found.', 'pandatask' ), array( 'status' => 404 ) );
    }

    private function storedDataError( $exception ) {
        return $this->storageError( __( 'The stored task checklist is invalid.', 'pandatask' ) );
    }

    private function storageError( $message ) {
        return new WP_Error( 'pandatask_checklist_storage_error', $message, array( 'status' => 500 ) );
    }

    private function encodeHistoryValue( array $value ) {
        $encoded = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $encoded ) ) {
            throw new \RuntimeException( 'The checklist history could not be encoded.' );
        }
        return $encoded;
    }
}
