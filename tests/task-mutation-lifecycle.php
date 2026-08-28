<?php

/**
 * Focused, dependency-free checks for task lifecycle accounting.
 *
 * Run with: php tests/task-mutation-lifecycle.php
 */

namespace Pandatask\Infrastructure\Persistence {
    final class DatabaseContext {
        public static function beginTransaction() {
            $GLOBALS['pandatask_lifecycle_events'][] = 'begin';
            return true;
        }

        public static function commit() {
            $GLOBALS['pandatask_lifecycle_events'][] = 'commit';
            return true;
        }

        public static function rollback() {
            $GLOBALS['pandatask_lifecycle_events'][] = 'rollback';
            return true;
        }
    }
}

namespace Pandatask\Infrastructure\Media {
    final class ProtectedAttachmentService {
        public static function syncTask( $task_id ) {
            $GLOBALS['pandatask_lifecycle_events'][] = 'attachment_sync';
            return array( 'created_keys' => array(), 'obsolete_keys' => array() );
        }

        public static function finalizeSync( $sync_result ) {
            unset( $sync_result );
        }

        public static function rollbackSync( $sync_result ) {
            unset( $sync_result );
        }
    }
}

namespace Pandatask\Infrastructure\Notifications {
    final class BuddyPressNotifier {
        public static function add_assignment_notification( $task_id, $user_id, $actor_id, $role ) {
            $GLOBALS['pandatask_buddypress_notifications'][] = array( $task_id, $user_id, $actor_id, $role );
        }
    }

    final class EmailNotifier {
        public static function send_assignment_notification( $task_id, $user_ids, $role ) {
            $GLOBALS['pandatask_email_notifications'][] = array( $task_id, $user_ids, $role );
        }
    }
}

namespace {
    if ( ! function_exists( '__' ) ) {
        function __( $text, $domain = null ) {
            unset( $domain );
            return $text;
        }
    }

    if ( ! function_exists( 'absint' ) ) {
        function absint( $value ) {
            return abs( (int) $value );
        }
    }

    if ( ! function_exists( 'sanitize_key' ) ) {
        function sanitize_key( $key ) {
            return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) );
        }
    }

    if ( ! function_exists( 'esc_url_raw' ) ) {
        function esc_url_raw( $url ) {
            return (string) $url;
        }
    }

    if ( ! function_exists( 'get_current_user_id' ) ) {
        function get_current_user_id() {
            return 7;
        }
    }

    if ( ! function_exists( 'get_userdata' ) ) {
        function get_userdata( $user_id ) {
            return (object) array( 'display_name' => 'User ' . (int) $user_id );
        }
    }

    if ( ! function_exists( 'wp_date' ) ) {
        function wp_date( $format ) {
            return gmdate( $format );
        }
    }

    if ( ! function_exists( 'wp_list_pluck' ) ) {
        function wp_list_pluck( $list, $field ) {
            return array_map(
                static function ( $item ) use ( $field ) {
                    return is_object( $item ) ? ( $item->{$field} ?? null ) : ( $item[ $field ] ?? null );
                },
                (array) $list
            );
        }
    }

    if ( ! function_exists( 'delete_transient' ) ) {
        function delete_transient( $key ) {
            unset( $key );
            return true;
        }
    }

    if ( ! function_exists( 'do_action' ) ) {
        function do_action( $hook ) {
            $GLOBALS['pandatask_lifecycle_events'][] = 'action:' . $hook;
            $GLOBALS['pandatask_action_args'][] = func_get_args();
        }
    }

    if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
        function wp_clear_scheduled_hook( $hook, $args = array() ) {
            unset( $hook, $args );
        }
    }

    if ( ! function_exists( 'wp_schedule_single_event' ) ) {
        function wp_schedule_single_event( $timestamp, $hook, $args = array() ) {
            unset( $timestamp, $hook, $args );
        }
    }

    if ( ! class_exists( 'WP_Error' ) ) {
        class WP_Error {
            private $code;
            private $message;
            private $data;

            public function __construct( $code, $message, $data = null ) {
                $this->code    = $code;
                $this->message = $message;
                $this->data    = $data;
            }

            public function get_error_code() {
                return $this->code;
            }

            public function get_error_message() {
                return $this->message;
            }

            public function get_error_data() {
                return $this->data;
            }
        }
    }

    if ( ! function_exists( 'is_wp_error' ) ) {
        function is_wp_error( $value ) {
            return $value instanceof WP_Error;
        }
    }

    require_once dirname( __DIR__ ) . '/src/Application/Task/TaskMutationService.php';

    use Pandatask\Application\Task\TaskMutationService;

    final class LifecycleTestRepository {
        public $events = array();
        public $updated = array();
        public $inserted = array();
        private $locked_status;

        public function __construct( $locked_status = 'pending' ) {
            $this->locked_status = $locked_status;
        }

        public function insertTask( $task_data, $format ) {
            unset( $format );
            $this->inserted = $task_data;
            $this->events[] = 'insert';
            return 42;
        }

        public function updateTask( $task_id, $update_data, $format ) {
            unset( $task_id, $format );
            $this->updated = $update_data;
            $this->events[] = 'update';
            return 1;
        }

        public function lockTaskStatusForUpdate( $task_id ) {
            unset( $task_id );
            $GLOBALS['pandatask_lifecycle_events'][] = 'lock';
            return $this->locked_status;
        }

        public function findRoleAssignmentUserIds( $task_id, $role ) {
            unset( $task_id );
            $this->events[] = 'assignment_lookup:' . $role;
            return 'assignee' === $role ? array( 8, 9 ) : array();
        }

        public function findParticipantUserIdsForTasks( $task_ids ) {
            unset( $task_ids );
            return array();
        }

        public function findSuccessorIds( $task_id ) {
            unset( $task_id );
            return array();
        }

        public function insertRoleAssignment( $task_id, $user_id, $role ) {
            unset( $task_id, $user_id, $role );
            return true;
        }

        public function deleteRoleAssignments( $task_id, $role, $user_ids ) {
            unset( $task_id, $role, $user_ids );
            return true;
        }
    }

    final class LifecycleTestTaskRepository {
        public $task;
        private $done_after_find;
        private $find_count = 0;

        public function __construct( $status = 'pending', $done_after_find = null ) {
            $this->done_after_find = $done_after_find;
            $this->task = (object) array(
                'id'                    => 42,
                'board_name'            => 'audit-board',
                'name'                  => 'Lifecycle task',
                'description'           => '',
                'status'                => $status,
                'completed_at'          => null,
                'creator_id'            => 7,
                'project_id'            => null,
                'category_id'           => null,
                'start_date'            => null,
                'deadline'              => null,
                'estimated_effort_seconds' => null,
                'deadline_days_after_start' => null,
                'assigned_user_ids'     => array( 8 ),
                'supervisor_user_ids'   => array(),
                'is_recurring'          => 0,
                'recurrence_frequency'  => null,
            );
        }

        public function findById( $task_id ) {
            $this->find_count++;
            if ( null !== $this->done_after_find && $this->find_count >= $this->done_after_find ) {
                $this->task->status = 'done';
            }
            return 42 === (int) $task_id ? $this->task : null;
        }

        public function findDescendantProjectRecords( $task_id, $board_name ) {
            unset( $task_id, $board_name );
            return array();
        }

        public function isBlocked( $task_id ) {
            unset( $task_id );
            return false;
        }
    }

    final class LifecycleTestInvariantService {
        public function applyAndValidate( $data, $current_task = null ) {
            unset( $current_task );
            if ( array_key_exists( 'status', $data ) ) {
                return $data;
            }

            return array_merge(
                array(
                    'board_name' => 'audit-board',
                    'name'       => 'Lifecycle task',
                    'description' => '',
                    'status'     => 'pending',
                    'task_type'  => 'task',
                    'priority'   => 5,
                ),
                $data
            );
        }
    }

    final class LifecycleTestOccurrenceRepository {
        public $events = array();
        public $occurrence;
        public $create_actor_ids = array();

        public function __construct( $state = 'open' ) {
            $this->occurrence = (object) array(
                'id'           => 99,
                'task_id'      => 42,
                'state'        => $state,
                'completed_at' => null,
            );
        }

        public function createForTask( $task, $sequence_number, $state, $actor_id ) {
            unset( $task, $sequence_number );
            $this->create_actor_ids[] = $actor_id;
            $this->occurrence->state = 'completed' === $state ? 'completed' : 'open';
            $this->events[] = 'create:' . $state;
            return 99;
        }

        public function setCurrentOccurrence( $task_id, $occurrence_id ) {
            unset( $task_id, $occurrence_id );
            $this->events[] = 'set_current';
            return true;
        }

        public function findCurrentForTask( $task_id ) {
            unset( $task_id );
            return $this->occurrence;
        }

        public function setState( $occurrence_id, $state, $actor_id ) {
            unset( $occurrence_id, $actor_id );
            $this->occurrence->state = $state;
            $this->events[] = 'state:' . $state;
            return true;
        }
    }

    final class LifecycleTestTimeService {
        public $marks = array();
        public $ensures = array();
        public $resolutions = array();

        public function markUnresolved( $task_id, $user_id, $resolved_by ) {
            $this->marks[] = array( $task_id, $user_id, $resolved_by );
            $GLOBALS['pandatask_lifecycle_events'][] = 'mark:' . $user_id;
            return true;
        }

        public function ensureUnresolvedForUsers( $task_id, $user_ids, $actor_id ) {
            $this->ensures[] = array( $task_id, $user_ids, $actor_id );
            $GLOBALS['pandatask_lifecycle_events'][] = 'ensure';
            return true;
        }

        public function resolveCurrentOccurrence( $task_id, $user_id, $actual_seconds, $not_tracked, $resolved_by, $options ) {
            $this->resolutions[] = array( $task_id, $user_id, $actual_seconds, $not_tracked, $resolved_by, $options );
            return array( 'state' => 'resolved' );
        }
    }

    class LifecycleTestFeatureSettings {
        private $enabled;

        public function __construct( $enabled ) {
            $this->enabled = $enabled;
        }

        public function workLogEnabled() {
            return $this->enabled;
        }
    }

    final class LifecycleTestHistoryService {
        public $actor_ids = array();

        public function addEntry( $task_id, $actor_id ) {
            unset( $task_id );
            $this->actor_ids[] = $actor_id;
            return true;
        }
    }

    final class LifecycleTestHistoryBufferService {
        public function buffer() {
            return true;
        }

        public function schedule() {}
    }

    final class LifecycleTestCacheInvalidator {
        public $user_ids = array();

        public function invalidateTask( $task_id, $board_name, $user_ids ) {
            unset( $task_id, $board_name );
            $this->user_ids = $user_ids;
        }
        public function invalidateBoard() {}
    }

    final class LifecycleTestRecurrenceCalculator {}

    $failures = array();
    $assert = static function ( $condition, $message ) use ( &$failures ) {
        if ( ! $condition ) {
            $failures[] = $message;
        }
    };

    $make_service = static function ( $task_repository, $repository, $occurrence_repository, $time_service, $feature_settings, $history_service = null, $cache_invalidator = null ) {
        return new TaskMutationService(
            $repository,
            $task_repository,
            $history_service ?: new LifecycleTestHistoryService(),
            new LifecycleTestInvariantService(),
            new LifecycleTestHistoryBufferService(),
            new LifecycleTestRecurrenceCalculator(),
            $cache_invalidator ?: new LifecycleTestCacheInvalidator(),
            $occurrence_repository,
            $time_service,
            $feature_settings
        );
    };

    $GLOBALS['pandatask_lifecycle_events'] = array();
    $create_repository = new LifecycleTestRepository();
    $create_occurrence = new LifecycleTestOccurrenceRepository();
    $create_time = new LifecycleTestTimeService();
    $create_service = $make_service(
        new LifecycleTestTaskRepository(),
        $create_repository,
        $create_occurrence,
        $create_time,
        new LifecycleTestFeatureSettings( true )
    );
    $created = $create_service->createTask(
        array(
            'board_name'       => 'audit-board',
            'name'             => 'Completed with assignees',
            'description'      => '',
            'status'           => 'done',
            'priority'         => 5,
            'assigned_persons' => array( 8, 9 ),
        )
    );

    $assert( 42 === $created, 'Already-completed task creation should return the new task ID.' );
    $assert( array( array( 42, 7, 7 ) ) === $create_time->marks, 'The creator should retain the legacy unresolved state.' );
    $assert( array( array( 42, array( 8, 9 ), 7 ) ) === $create_time->ensures, 'Every assigned user should receive an unresolved state.' );
    $assert(
        array_search( 'assignment_lookup:supervisor', $GLOBALS['pandatask_lifecycle_events'], true ) < array_search( 'mark:7', $GLOBALS['pandatask_lifecycle_events'], true ),
        'Completed-task time states should be initialized after assignments are known.'
    );

    $delegated_repository = new LifecycleTestRepository();
    $delegated_occurrence = new LifecycleTestOccurrenceRepository();
    $delegated_history = new LifecycleTestHistoryService();
    $delegated_cache = new LifecycleTestCacheInvalidator();
    $delegated_service = $make_service(
        new LifecycleTestTaskRepository(),
        $delegated_repository,
        $delegated_occurrence,
        new LifecycleTestTimeService(),
        new LifecycleTestFeatureSettings( true ),
        $delegated_history,
        $delegated_cache
    );
    $GLOBALS['pandatask_action_args'] = array();
    $GLOBALS['pandatask_buddypress_notifications'] = array();
    $GLOBALS['pandatask_email_notifications'] = array();
    $delegated_created = $delegated_service->createTask(
        array(
            'board_name'  => 'user_11',
            'name'        => 'Captured for the owner',
            'description' => '',
            'status'      => 'pending',
            'priority'    => 5,
        ),
        array(
            'actor_id'   => 7,
            'creator_id' => 11,
        )
    );
    $assert( 42 === $delegated_created, 'Delegated capture should create the task.' );
    $assert( 11 === $delegated_repository->inserted['creator_id'], 'Delegated capture must preserve the inbox owner as task creator.' );
    $assert( array( 7 ) === $delegated_history->actor_ids, 'Delegated capture history must identify the submitting actor.' );
    $assert( array( 7 ) === $delegated_occurrence->create_actor_ids, 'Delegated capture occurrence audit must identify the submitting actor.' );
    $assert( empty( array_diff( array( 7, 11 ), $delegated_cache->user_ids ) ), 'Delegated capture must invalidate both actor and owner caches.' );
    $assert( 7 === (int) ( $GLOBALS['pandatask_action_args'][0][3] ?? 0 ), 'Delegated capture lifecycle events must identify the submitting actor.' );
    $assert( array( array( 42, array( 11 ), 'assignee' ) ) === $GLOBALS['pandatask_email_notifications'], 'Delegated capture should notify the assigned Inbox owner.' );
    $assert( array( array( 42, 11, 7, 'assignee' ) ) === $GLOBALS['pandatask_buddypress_notifications'], 'Delegated capture notifications must identify the submitting actor.' );

    $disabled_time = new LifecycleTestTimeService();
    $disabled_service = $make_service(
        new LifecycleTestTaskRepository(),
        new LifecycleTestRepository(),
        new LifecycleTestOccurrenceRepository(),
        $disabled_time,
        new LifecycleTestFeatureSettings( false )
    );
    $disabled_service->createTask(
        array(
            'board_name'       => 'audit-board',
            'name'             => 'Completed without work log',
            'description'      => '',
            'status'           => 'done',
            'priority'         => 5,
            'assigned_persons' => array( 8, 9 ),
        )
    );
    $assert( empty( $disabled_time->marks ) && empty( $disabled_time->ensures ), 'Work-log-disabled creation must not create time states.' );

    $already_done_time = new LifecycleTestTimeService();
    $already_done_repository = new LifecycleTestRepository();
    $already_done_service = $make_service(
        new LifecycleTestTaskRepository( 'done' ),
        $already_done_repository,
        new LifecycleTestOccurrenceRepository( 'completed' ),
        $already_done_time,
        new LifecycleTestFeatureSettings( true )
    );
    $GLOBALS['pandatask_lifecycle_events'] = array();
    $already_done = $already_done_service->completeTask( 42, array( 'actual_seconds' => 3600 ), '', 7 );
    $assert( is_wp_error( $already_done ), 'Completing an already-completed task should return an error.' );
    $assert( is_wp_error( $already_done ) && 'pandatask_task_already_completed' === $already_done->get_error_code(), 'The duplicate completion error code is not explicit.' );
    $assert( is_wp_error( $already_done ) && 409 === (int) ( $already_done->get_error_data()['status'] ?? 0 ), 'Duplicate completion should use HTTP 409 semantics.' );
    $assert( empty( $already_done_time->resolutions ), 'Duplicate completion must not record accounting.' );
    $assert( ! in_array( 'begin', $GLOBALS['pandatask_lifecycle_events'], true ), 'Duplicate completion should be rejected before opening a mutation transaction.' );

    $generic_done_time = new LifecycleTestTimeService();
    $generic_done_service = $make_service(
        new LifecycleTestTaskRepository( 'pending' ),
        new LifecycleTestRepository(),
        new LifecycleTestOccurrenceRepository(),
        $generic_done_time,
        new LifecycleTestFeatureSettings( true )
    );
    $GLOBALS['pandatask_lifecycle_events'] = array();
    $generic_done = $generic_done_service->updateTask( 42, array( 'status' => 'done' ), '', 7 );
    $assert( is_wp_error( $generic_done ) && 'pandatask_completion_required' === $generic_done->get_error_code(), 'Generic updates must not bypass the explicit completion accounting boundary.' );
    $assert( is_wp_error( $generic_done ) && 409 === (int) ( $generic_done->get_error_data()['status'] ?? 0 ), 'Generic completion bypasses should use HTTP 409 semantics.' );
    $assert( empty( $generic_done_time->resolutions ), 'Rejected generic completion must not record partial accounting.' );
    $assert( ! in_array( 'begin', $GLOBALS['pandatask_lifecycle_events'], true ), 'Generic completion bypasses should be rejected before opening a mutation transaction.' );

    $racing_time = new LifecycleTestTimeService();
    $racing_service = $make_service(
        new LifecycleTestTaskRepository( 'pending' ),
        new LifecycleTestRepository( 'done' ),
        new LifecycleTestOccurrenceRepository(),
        $racing_time,
        new LifecycleTestFeatureSettings( true )
    );
    $racing = $racing_service->completeTask( 42, array( 'actual_seconds' => 3600 ), '', 7 );
    $assert( is_wp_error( $racing ) && 'pandatask_task_already_completed' === $racing->get_error_code(), 'A completion that becomes stale before its transaction should be rejected.' );
    $assert( empty( $racing_time->resolutions ), 'A stale completion must not record accounting.' );

    $genuine_time = new LifecycleTestTimeService();
    $genuine_occurrence = new LifecycleTestOccurrenceRepository();
    $genuine_service = $make_service(
        new LifecycleTestTaskRepository( 'pending' ),
        new LifecycleTestRepository(),
        $genuine_occurrence,
        $genuine_time,
        new LifecycleTestFeatureSettings( true )
    );
    $genuine = $genuine_service->completeTask(
        42,
        array(
            'user_id'        => 7,
            'actual_seconds' => 3600,
            'not_tracked'    => false,
        ),
        '',
        7
    );
    $assert( true === $genuine, 'A pending task should still complete successfully.' );
    $assert( 1 === count( $genuine_time->resolutions ), 'Genuine completion should preserve accounting resolution.' );
    $assert( 'completed' === $genuine_occurrence->occurrence->state, 'Genuine completion should close the current occurrence.' );

    if ( ! empty( $failures ) ) {
        fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
        exit( 1 );
    }

    echo "Task mutation lifecycle tests passed.\n";
}
