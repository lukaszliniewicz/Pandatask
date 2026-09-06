<?php

/**
 * Focused, dependency-free checks for task lifecycle accounting.
 *
 * Run with: php tests/task-mutation-lifecycle.php
 */

namespace Pandatask\Infrastructure\Persistence {
    final class DatabaseContext {
        public static function getDbPrefix() {
            return 'wp_pandat69_';
        }

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

    if ( ! class_exists( 'WP_REST_Response' ) ) {
        class WP_REST_Response {
            public $data;
            public $status;

            public function __construct( $data, $status = 200 ) {
                $this->data = $data;
                $this->status = $status;
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
                'task_type'             => 'task',
                'priority'              => 5,
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
        public $current_occurrence_id = 99;

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
            unset( $task_id );
            $this->current_occurrence_id = (int) $occurrence_id;
            $this->events[] = 'set_current';
            return true;
        }

        public function findCurrentForTask( $task_id ) {
            unset( $task_id );
            return $this->occurrence;
        }

        public function refreshOpenSnapshot( $occurrence_id, $task, $actor_id ) {
            unset( $occurrence_id, $task, $actor_id );
            $this->events[] = 'refresh_snapshot';
            return true;
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
        public $reopens = array();

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

        public function reviseOnReopen( $occurrence_id, $actor_id ) {
            $this->reopens[] = array( (int) $occurrence_id, (int) $actor_id );
            return true;
        }
    }

    final class LifecycleTestWpdb {
        public $status = 'done';

        public function prepare( $query ) {
            return $query;
        }

        public function get_row( $query ) {
            if ( false === strpos( $query, 'FROM wp_pandat69_tasks' ) ) {
                return null;
            }

            return (object) array(
                'id'                   => 42,
                'board_name'           => 'audit-board',
                'creator_id'           => 7,
                'inbox_state'          => null,
                'follow_up_of_task_id' => null,
                'status'               => $this->status,
            );
        }

        public function get_results( $query ) {
            if ( false !== strpos( $query, 'FROM wp_pandat69_assignments' ) ) {
                return array();
            }

            return array();
        }
    }

    final class LifecycleTestRequest implements \ArrayAccess {
        private $params;

        public function __construct( array $params ) {
            $this->params = $params;
        }

        public function get_json_params() {
            return array();
        }

        public function offsetExists( $offset ) {
            return array_key_exists( $offset, $this->params );
        }

        public function offsetGet( $offset ) {
            return $this->params[ $offset ] ?? null;
        }

        public function offsetSet( $offset, $value ) {
            $this->params[ $offset ] = $value;
        }

        public function offsetUnset( $offset ) {
            unset( $this->params[ $offset ] );
        }
    }

    final class LifecycleTestRouteTaskService {
        public $access;
        public $reopen_calls = array();

        public function __construct( $access ) {
            $this->access = $access;
        }

        public function getTaskForAuthorization( $task_id ) {
            unset( $task_id );
            return $this->access;
        }

        public function reopenTask( $task_id, $status, $reason, $actor_id ) {
            $this->reopen_calls[] = array( (int) $task_id, $status, $reason, (int) $actor_id );
            return true;
        }

        public function getTask( $task_id ) {
            return (object) array( 'id' => (int) $task_id, 'description' => '' );
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

    final class LifecycleTestRecurrenceService {
        public $attach_calls = array();
        public $advance_calls = array();
        public $lock_calls = array();

        public function attachTask( $task_id, $actor_id ) {
            $this->attach_calls[] = array( (int) $task_id, (int) $actor_id );
            return 77;
        }

        public function lockForUpdate( $task_id, $scope, $expected_version, $actor_id ) {
            $this->lock_calls[] = array( (int) $task_id, $scope, $expected_version, (int) $actor_id );
            return (object) array( 'id' => 77, 'version' => 0, 'current_task_id' => (int) $task_id );
        }

        public function syncTemplate( $task_id, $series, $actor_id, $schedule_changed = false ) {
            unset( $task_id, $series, $actor_id, $schedule_changed );
        }

        public function advance( $task_id, $actor_id = 0 ) {
            $this->advance_calls[] = array( (int) $task_id, (int) $actor_id );
            $GLOBALS['pandatask_lifecycle_events'][] = 'recurrence_advance';
            return null;
        }

        public function runDue() {
            return array( 'scanned' => 0, 'advanced' => 0, 'disabled' => 0, 'failed' => 0 );
        }
    }

    $failures = array();
    $assert = static function ( $condition, $message ) use ( &$failures ) {
        if ( ! $condition ) {
            $failures[] = $message;
        }
    };

    $make_service = static function ( $task_repository, $repository, $occurrence_repository, $time_service, $feature_settings, $history_service = null, $cache_invalidator = null, $recurrence_service = null ) {
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
            $feature_settings,
            null,
            $recurrence_service
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
            'estimated_effort_seconds' => 5400,
            'assigned_persons' => array( 8, 9 ),
        )
    );

    $assert( 42 === $created, 'Already-completed task creation should return the new task ID.' );
    $assert( 5400 === $create_repository->inserted['estimated_effort_seconds'], 'Task creation must persist estimated effort.' );
    $assert( array( array( 42, 7, 7 ) ) === $create_time->marks, 'The creator should retain the legacy unresolved state.' );
    $assert( array( array( 42, array( 8, 9 ), 7 ) ) === $create_time->ensures, 'Every assigned user should receive an unresolved state.' );
    $assert(
        array_search( 'assignment_lookup:supervisor', $GLOBALS['pandatask_lifecycle_events'], true ) < array_search( 'mark:7', $GLOBALS['pandatask_lifecycle_events'], true ),
        'Completed-task time states should be initialized after assignments are known.'
    );

    $recurrence_repository = new LifecycleTestRepository();
    $recurrence_double = new LifecycleTestRecurrenceService();
    $recurrence_service = $make_service(
        new LifecycleTestTaskRepository(),
        $recurrence_repository,
        new LifecycleTestOccurrenceRepository(),
        new LifecycleTestTimeService(),
        new LifecycleTestFeatureSettings( true ),
        null,
        null,
        $recurrence_double
    );
    $recurrence_created = $recurrence_service->createTask(
        array(
            'board_name'                => 'audit-board',
            'name'                      => 'Last Sunday review',
            'description'               => '',
            'status'                    => 'pending',
            'priority'                  => 5,
            'is_recurring'              => 1,
            'recurrence_frequency'      => 'monthly_weekday',
            'recurrence_interval'       => 1,
            'recurrence_days'           => '7',
            'recurrence_month_week'     => 'last',
            'start_date'                => '2026-09-27',
            'deadline'                  => '2026-09-27',
        )
    );
    $assert( 42 === $recurrence_created, 'Monthly weekday task creation should succeed.' );
    $assert( 'monthly_weekday' === $recurrence_repository->inserted['recurrence_frequency'], 'Task creation must persist the monthly weekday frequency.' );
    $assert( '7' === $recurrence_repository->inserted['recurrence_days'], 'Task creation must persist ISO Sunday as 7.' );
    $assert( 'last' === $recurrence_repository->inserted['recurrence_month_week'], 'Task creation must persist the monthly weekday ordinal.' );
    $assert( array( array( 42, 7 ) ) === $recurrence_double->attach_calls, 'Recurring task creation should attach the created task to a series inside the transaction.' );

    $complete_task_repository = new LifecycleTestTaskRepository( 'pending' );
    $complete_task_repository->task->recurrence_series_id = 77;
    $complete_occurrence = new LifecycleTestOccurrenceRepository();
    $complete_recurrence = new LifecycleTestRecurrenceService();
    $GLOBALS['pandatask_lifecycle_events'] = array();
    $complete_service = $make_service(
        $complete_task_repository,
        new LifecycleTestRepository(),
        $complete_occurrence,
        new LifecycleTestTimeService(),
        new LifecycleTestFeatureSettings( false ),
        null,
        null,
        $complete_recurrence
    );
    $completed_recurring = $complete_service->completeTask( 42, array(), '', 7 );
    $assert( true === $completed_recurring, 'Completing an ordinary recurring task should commit the task mutation.' );
    $assert( array( array( 42, 7 ) ) === $complete_recurrence->advance_calls, 'A committed recurring completion should advance through the recurrence service.' );
    $assert( array_search( 'commit', $GLOBALS['pandatask_lifecycle_events'], true ) < array_search( 'recurrence_advance', $GLOBALS['pandatask_lifecycle_events'], true ), 'A recurring successor should be requested only after completion commits.' );
    $assert( 'completed' === $complete_occurrence->occurrence->state && 99 === $complete_occurrence->current_occurrence_id, 'Completing a recurring task should keep its own completed work occurrence selected.' );

    $complete_task_repository->task->status = 'done';
    $reopened_recurring = $complete_service->updateTask( 42, array( 'status' => 'in-progress' ), 'Corrected completion', 7, null, 'reopen' );
    $assert( true === $reopened_recurring, 'Reopening the completed recurring task should succeed.' );
    $assert( 'open' === $complete_occurrence->occurrence->state && 99 === $complete_occurrence->current_occurrence_id, 'Reopening should reuse the old work occurrence.' );
    $assert( 1 === count( $complete_recurrence->advance_calls ), 'Reopening an old recurring task should not advance the series again.' );

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

    $reassignment_service = $make_service(
        new LifecycleTestTaskRepository(),
        new LifecycleTestRepository(),
        new LifecycleTestOccurrenceRepository(),
        new LifecycleTestTimeService(),
        new LifecycleTestFeatureSettings( true )
    );
    $GLOBALS['pandatask_buddypress_notifications'] = array();
    $GLOBALS['pandatask_email_notifications'] = array();
    $reassigned = $reassignment_service->updateTask(
        42,
        array( 'assigned_persons' => array( 8, 10 ) ),
        '',
        7
    );
    $assert( true === $reassigned, 'A valid reassignment update should succeed.' );
    $assert( array( array( 42, array( 10 ), 'assignee' ) ) === $GLOBALS['pandatask_email_notifications'], 'Reassignment should email only the newly added assignee.' );
    $assert( array( array( 42, 10, 7, 'assignee' ) ) === $GLOBALS['pandatask_buddypress_notifications'], 'Reassignment should create a BuddyPress notification only for the newly added assignee.' );

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

    $reopen_time = new LifecycleTestTimeService();
    $reopen_occurrence = new LifecycleTestOccurrenceRepository( 'completed' );
    $reopen_service = $make_service(
        new LifecycleTestTaskRepository( 'done' ),
        new LifecycleTestRepository(),
        $reopen_occurrence,
        $reopen_time,
        new LifecycleTestFeatureSettings( true )
    );
    $reopened = $reopen_service->updateTask( 42, array( 'status' => 'in-progress' ), 'Corrected completion', 7, null, 'reopen' );
    $assert( true === $reopened, 'An explicit reopen should succeed for a completed task.' );
    $assert( 'open' === $reopen_occurrence->occurrence->state, 'An explicit reopen should reopen the existing current occurrence.' );
    $assert( 99 === $reopen_occurrence->current_occurrence_id, 'An explicit reopen should keep the existing occurrence selected as current.' );
    $assert( ! in_array( 'create:open', $reopen_occurrence->events, true ), 'An explicit reopen must not create a new work occurrence.' );
    $assert( array( array( 99, 7 ) ) === $reopen_time->reopens, 'An explicit reopen should revise time for the reopened occurrence.' );

    // Legacy lifecycle operation names are rejected so they cannot rewrite an
    // existing task row in place. RecurrenceService owns successor identity.
    foreach ( array( 'rollover', 'skip' ) as $operation ) {
        $task_repository = new LifecycleTestTaskRepository( 'rollover' === $operation ? 'done' : 'pending' );
        $repository = new LifecycleTestRepository();
        $occurrence = new LifecycleTestOccurrenceRepository( 'completed' );
        $service = new TaskMutationService(
            $repository, $task_repository, new LifecycleTestHistoryService(),
            new LifecycleTestInvariantService(), new LifecycleTestHistoryBufferService(),
            new LifecycleTestRecurrenceCalculator(), new LifecycleTestCacheInvalidator(),
            $occurrence, new LifecycleTestTimeService(), new LifecycleTestFeatureSettings( false ),
            null, new LifecycleTestRecurrenceService()
        );
        $GLOBALS['pandatask_lifecycle_events'] = array();
        $result = $service->updateTask(
            42,
            array( 'start_date' => '2026-09-08', 'deadline' => '2026-09-09', 'status' => 'pending' ),
            '',
            0,
            null,
            $operation
        );
        $assert( is_wp_error( $result ) && 'pandatask_occurrence_identity_required' === $result->get_error_code(), $operation . ' should require recurrence successor identity.' );
        $assert( 409 === (int) ( $result->get_error_data()['status'] ?? 0 ), $operation . ' should return HTTP 409.' );
        $assert( empty( $repository->updated ), $operation . ' should not write task fields.' );
        $assert( empty( $occurrence->events ), $operation . ' should not mutate work occurrences.' );
    }

    require_once dirname( __DIR__ ) . '/src/Infrastructure/Persistence/TaskRepository.php';
    require_once dirname( __DIR__ ) . '/src/Http/Rest/V1/Support/RequestHelper.php';
    require_once dirname( __DIR__ ) . '/src/Http/Rest/V1/TaskLifecycleRouteHandler.php';

    $GLOBALS['wpdb'] = new LifecycleTestWpdb();
    $access_repository = new \Pandatask\Infrastructure\Persistence\TaskRepository();
    $access_record = $access_repository->findAccessRecordById( 42 );
    $assert( 'done' === $access_record->status, 'The authorization access projection should expose the canonical task status.' );

    $route_task_service = new LifecycleTestRouteTaskService( $access_record );
    $route_handler = new \Pandatask\Http\Rest\V1\TaskLifecycleRouteHandler(
        $route_task_service,
        new stdClass(),
        new stdClass(),
        new stdClass(),
        new stdClass(),
        new stdClass()
    );
    $route_result = $route_handler->reopen_task( new LifecycleTestRequest( array( 'id' => 42, 'reason' => 'Fix completion' ) ) );
    $assert( $route_result instanceof \WP_REST_Response, 'A done access record should reach the explicit reopen route.' );
    $assert( 1 === count( $route_task_service->reopen_calls ), 'A done access record should invoke the reopen mutation.' );

    $GLOBALS['wpdb']->status = 'open';
    $route_task_service->access = $access_repository->findAccessRecordById( 42 );
    $route_result = $route_handler->reopen_task( new LifecycleTestRequest( array( 'id' => 42, 'reason' => 'Should be rejected' ) ) );
    $assert( is_wp_error( $route_result ) && 'pandatask_task_not_completed' === $route_result->get_error_code(), 'An open access record should be rejected by the reopen route.' );
    $assert( 1 === count( $route_task_service->reopen_calls ), 'An open access record must not invoke the reopen mutation.' );

    if ( ! empty( $failures ) ) {
        fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
        exit( 1 );
    }

    echo "Task mutation lifecycle tests passed.\n";
}
