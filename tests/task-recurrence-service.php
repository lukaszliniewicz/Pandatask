<?php
/**
 * Focused, dependency-free checks for recurring task service behavior.
 *
 * Run with: php tests/task-recurrence-service.php
 */

namespace Pandatask\Infrastructure\Persistence {
    final class DatabaseContext {
        public static function acquireDependencyGraphLock() {
            $GLOBALS['pandatask_recurrence_events'][] = 'graph_lock';
            return true;
        }

        public static function releaseDependencyGraphLock() {
            $GLOBALS['pandatask_recurrence_events'][] = 'graph_unlock';
            return true;
        }

        public static function beginTransaction() {
            $GLOBALS['pandatask_recurrence_events'][] = 'begin';
            $GLOBALS['pandatask_recurrence_state']->begin();
            return true;
        }

        public static function commit() {
            $GLOBALS['pandatask_recurrence_events'][] = 'commit';
            $GLOBALS['pandatask_recurrence_state']->commit();
            return true;
        }

        public static function rollback() {
            $GLOBALS['pandatask_recurrence_events'][] = 'rollback';
            $GLOBALS['pandatask_recurrence_state']->rollback();
            return true;
        }

        public static function invalidateTaskCache( $task_id ) {
            $GLOBALS['pandatask_recurrence_events'][] = 'invalidate:' . (int) $task_id;
        }
    }
}

namespace Pandatask\Infrastructure\Media {
    final class ProtectedAttachmentService {
        public static $sync_result = array( 'created_keys' => array(), 'obsolete_keys' => array() );

        public static function syncTask( $task_id ) {
            $GLOBALS['pandatask_recurrence_events'][] = 'attachment_sync:' . (int) $task_id;
            return self::$sync_result;
        }

        public static function finalizeSync( $sync_result ) {
            unset( $sync_result );
            $GLOBALS['pandatask_recurrence_events'][] = 'attachment_finalize';
        }

        public static function rollbackSync( $sync_result ) {
            unset( $sync_result );
            $GLOBALS['pandatask_recurrence_events'][] = 'attachment_rollback';
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
        function sanitize_key( $value ) {
            return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) );
        }
    }

    if ( ! function_exists( 'sanitize_text_field' ) ) {
        function sanitize_text_field( $value ) {
            return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $value ) ) );
        }
    }

    if ( ! function_exists( 'wp_json_encode' ) ) {
        function wp_json_encode( $value, $flags = 0 ) {
            return json_encode( $value, $flags );
        }
    }

    if ( ! function_exists( 'wp_generate_uuid4' ) ) {
        function wp_generate_uuid4() {
            return '00000000-0000-4000-8000-000000000001';
        }
    }

    if ( ! function_exists( 'wp_date' ) ) {
        function wp_date( $format ) {
            return gmdate( $format, strtotime( $GLOBALS['pandatask_recurrence_today'] ) );
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
            $GLOBALS['pandatask_recurrence_events'][] = 'action:' . $hook;
        }
    }

    if ( ! function_exists( 'is_wp_error' ) ) {
        function is_wp_error( $value ) {
            return $value instanceof WP_Error;
        }
    }

    if ( ! class_exists( 'WP_Error' ) ) {
        class WP_Error {
            private $code;
            private $message;
            private $data;

            public function __construct( $code, $message = '', $data = array() ) {
                $this->code = $code;
                $this->message = $message;
                $this->data = $data;
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

    require_once dirname( __DIR__ ) . '/src/Domain/Task/RecurrenceCalculator.php';
    require_once dirname( __DIR__ ) . '/src/Domain/Task/TaskChecklist.php';
    require_once dirname( __DIR__ ) . '/src/Domain/Task/TaskRecurrenceDefinition.php';
    require_once dirname( __DIR__ ) . '/src/Application/Task/TaskRecurrenceService.php';

    final class RecurrenceHarnessState {
        public $tasks = array();
        public $series = array();
        public $assignments = array();
        public $predecessors = array();
        public $history = array();
        public $events = array();
        public $next_series_id = 100;
        public $next_task_id = 200;
        public $next_occurrence_id = 500;
        public $insert_series_fail = false;
        public $link_task_fail = false;
        public $update_series_fail = false;
        public $insert_task_fail = false;
        public $insert_assignment_fail = false;
        public $insert_relationship_fail = false;
        public $work_fail = false;
        public $set_current_fail = false;
        public $history_fail = false;
        private $snapshot;

        public function begin() {
            $this->snapshot = array(
                'tasks'       => unserialize( serialize( $this->tasks ) ),
                'series'      => unserialize( serialize( $this->series ) ),
                'assignments' => unserialize( serialize( $this->assignments ) ),
                'predecessors' => unserialize( serialize( $this->predecessors ) ),
                'history'     => unserialize( serialize( $this->history ) ),
                'next_task_id' => $this->next_task_id,
                'next_occurrence_id' => $this->next_occurrence_id,
            );
        }

        public function commit() {
            $this->snapshot = null;
        }

        public function rollback() {
            if ( ! $this->snapshot ) {
                return;
            }

            foreach ( $this->snapshot as $property => $value ) {
                $this->{$property} = $value;
            }
            $this->snapshot = null;
        }
    }

    final class RecurrenceHarnessRepository {
        private $state;
        public $lock_task_calls = array();
        public $lock_series_calls = array();
        public $update_calls = array();
        public $link_calls = array();

        public function __construct( $state ) {
            $this->state = $state;
        }

        public function lockTask( $task_id ) {
            $this->lock_task_calls[] = (int) $task_id;
            return $this->state->tasks[ (int) $task_id ] ?? null;
        }

        public function lockSeries( $series_id ) {
            $this->lock_series_calls[] = (int) $series_id;
            return $this->state->series[ (int) $series_id ] ?? null;
        }

        public function insertSeries( array $data ) {
            if ( $this->state->insert_series_fail ) {
                return false;
            }

            $id = $this->state->next_series_id++;
            $this->state->series[ $id ] = (object) array_merge( array( 'id' => $id ), $data );
            return $id;
        }

        public function linkTask( $task_id, $series_id, $sequence, $scheduled_start ) {
            $this->link_calls[] = array( $task_id, $series_id, $sequence, $scheduled_start );
            if ( $this->state->link_task_fail || ! isset( $this->state->tasks[ (int) $task_id ] ) ) {
                return false;
            }

            $task = $this->state->tasks[ (int) $task_id ];
            $task->recurrence_series_id = (int) $series_id;
            $task->recurrence_sequence = (int) $sequence;
            $task->recurrence_scheduled_start = $scheduled_start;
            return true;
        }

        public function updateSeries( $series_id, array $data ) {
            $this->update_calls[] = array( (int) $series_id, $data );
            if ( $this->state->update_series_fail || ! isset( $this->state->series[ (int) $series_id ] ) ) {
                return false;
            }

            foreach ( $data as $field => $value ) {
                $this->state->series[ (int) $series_id ]->{$field} = $value;
            }
            return true;
        }

        public function findAssignments( $task_id ) {
            return $this->state->assignments[ (int) $task_id ] ?? array();
        }

        public function findPredecessorIds( $task_id ) {
            return $this->state->predecessors[ (int) $task_id ] ?? array();
        }

        public function findMaxSequence( $series_id ) {
            $maximum = 0;
            foreach ( $this->state->tasks as $task ) {
                if ( (int) ( $task->recurrence_series_id ?? 0 ) === (int) $series_id ) {
                    $maximum = max( $maximum, (int) $task->recurrence_sequence );
                }
            }
            return $maximum;
        }

        public function findForTask( $task_id ) {
            $task = $this->state->tasks[ (int) $task_id ] ?? null;
            return $task && ! empty( $task->recurrence_series_id )
                ? ( $this->state->series[ (int) $task->recurrence_series_id ] ?? null )
                : null;
        }

        public function listOccurrenceTasks( $series_id, $limit = 100, $before_sequence = null ) {
            $rows = array();
            foreach ( $this->state->tasks as $task ) {
                if ( (int) ( $task->recurrence_series_id ?? 0 ) !== (int) $series_id ) {
                    continue;
                }
                if ( null !== $before_sequence && (int) $task->recurrence_sequence >= (int) $before_sequence ) {
                    continue;
                }
                $rows[] = $task;
            }
            usort( $rows, static function ( $left, $right ) {
                return (int) $right->recurrence_sequence <=> (int) $left->recurrence_sequence;
            } );
            return array_slice( $rows, 0, max( 1, min( 100, (int) $limit ) ) );
        }
    }

    final class RecurrenceHarnessCommands {
        private $state;

        public function __construct( $state ) {
            $this->state = $state;
        }

        public function insertTask( $data, $format ) {
            unset( $format );
            if ( $this->state->insert_task_fail ) {
                return false;
            }

            $id = $this->state->next_task_id++;
            $this->state->tasks[ $id ] = (object) array_merge( array( 'id' => $id ), $data );
            return $id;
        }

        public function insertRoleAssignment( $task_id, $user_id, $role ) {
            if ( $this->state->insert_assignment_fail ) {
                return false;
            }
            $this->state->assignments[ (int) $task_id ][] = (object) array( 'user_id' => (int) $user_id, 'role' => $role );
            return true;
        }

        public function insertTaskRelationship( $task_id, $predecessor_id ) {
            if ( $this->state->insert_relationship_fail ) {
                return false;
            }
            $this->state->predecessors[ (int) $task_id ][] = (int) $predecessor_id;
            return true;
        }

        public function updateTask( $task_id, $data, $format ) {
            unset( $format );
            $task = $this->state->tasks[ (int) $task_id ] ?? null;
            if ( ! $task ) {
                return false;
            }
            foreach ( $data as $field => $value ) {
                $task->{$field} = $value;
            }
            return 1;
        }

        public function findParticipantUserIdsForTasks( $task_ids ) {
            $users = array();
            foreach ( (array) $task_ids as $task_id ) {
                foreach ( $this->state->assignments[ (int) $task_id ] ?? array() as $assignment ) {
                    $users[] = (int) $assignment->user_id;
                }
            }
            return $users;
        }
    }

    final class RecurrenceHarnessTasks {
        private $state;

        public function __construct( $state ) {
            $this->state = $state;
        }

        public function findById( $task_id ) {
            return $this->state->tasks[ (int) $task_id ] ?? null;
        }
    }

    final class RecurrenceHarnessOccurrences {
        private $state;

        public function __construct( $state ) {
            $this->state = $state;
        }

        public function createForTask( $task, $sequence, $state ) {
            unset( $task, $sequence, $state );
            if ( $this->state->work_fail ) {
                return false;
            }
            return $this->state->next_occurrence_id++;
        }

        public function setCurrentOccurrence( $task_id, $occurrence_id ) {
            if ( $this->state->set_current_fail ) {
                return false;
            }
            $this->state->tasks[ (int) $task_id ]->current_work_occurrence_id = (int) $occurrence_id;
            return true;
        }
    }

    final class RecurrenceHarnessHistory {
        private $state;

        public function __construct( $state ) {
            $this->state = $state;
        }

        public function addEntry( $task_id, $actor_id, $field, $old, $new, $comment = '' ) {
            if ( $this->state->history_fail ) {
                return false;
            }
            $this->state->history[] = array( $task_id, $actor_id, $field, $old, $new, $comment );
            return 1;
        }
    }

    final class RecurrenceHarnessPolicy {
        public $readable = null;
        public $updatable = true;

        public function canReadTask( $task_id, $actor_id ) {
            unset( $actor_id );
            return null === $this->readable || in_array( (int) $task_id, $this->readable, true );
        }

        public function canUpdateTask( $task_id, $actor_id ) {
            unset( $task_id, $actor_id );
            return $this->updatable;
        }
    }

    final class RecurrenceHarnessInvariants {
        public $result = null;

        public function applyAndValidate( $data, $current_task = null ) {
            unset( $current_task );
            return null === $this->result ? $data : $this->result;
        }
    }

    final class RecurrenceHarnessCache {
        public $invalidated = array();

        public function invalidateTask( $task_id, $board_name, array $user_ids = array() ) {
            $this->invalidated[] = array( (int) $task_id, $board_name, $user_ids );
        }
    }

    function recurrence_task( $id = 10, $end = '2024-04-30' ) {
        return (object) array(
            'id' => $id,
            'board_name' => 'board-a',
            'name' => 'Original recurring task',
            'creator_id' => 7,
            'description' => 'Keep this description.',
            'estimated_effort_seconds' => 3600,
            'current_work_occurrence_id' => 77,
            'checklist_json' => wp_json_encode( array( array( 'id' => 'item-a', 'text' => 'Ship it', 'checked' => true ) ) ),
            'checklist_version' => 4,
            'task_type' => 'task',
            'bug_url' => null,
            'status' => 'pending',
            'category_id' => null,
            'project_id' => null,
            'priority' => 5,
            'start_date' => '2024-01-31',
            'deadline' => '2024-02-02',
            'deadline_days_after_start' => 2,
            'notify_deadline' => 0,
            'notify_days_before' => 3,
            'archived' => 0,
            'parent_task_id' => null,
            'follow_up_of_task_id' => null,
            'inbox_state' => null,
            'capture_source' => null,
            'capture_url' => null,
            'completed_at' => null,
            'is_recurring' => 1,
            'recurrence_series_id' => null,
            'recurrence_sequence' => null,
            'recurrence_scheduled_start' => null,
            'recurrence_frequency' => 'monthly',
            'recurrence_interval' => 1,
            'recurrence_days' => null,
            'recurrence_ends_on' => $end,
            'recurrence_month_week' => null,
            'recurrence_anchor_day' => 31,
            'attachment_type' => null,
            'attachment_url' => null,
            'attachment_post_id' => null,
            'attachment_filename' => null,
        );
    }

    function recurrence_series_occurrence( $id, $sequence ) {
        return (object) array(
            'id' => $id,
            'name' => 'Occurrence ' . $id,
            'status' => 'pending',
            'archived' => 0,
            'start_date' => '2024-02-29',
            'deadline' => '2024-03-02',
            'recurrence_series_id' => 100,
            'recurrence_sequence' => $sequence,
            'recurrence_scheduled_start' => '2024-02-29',
            'checklist_json' => wp_json_encode( array() ),
            'checklist_version' => 0,
        );
    }

    function recurrence_state( $end = '2024-04-30' ) {
        $state = new RecurrenceHarnessState();
        $state->tasks[10] = recurrence_task( 10, $end );
        $state->tasks[3] = (object) array( 'id' => 3, 'name' => 'Predecessor', 'status' => 'done' );
        $state->assignments[10] = array(
            (object) array( 'user_id' => 21, 'role' => 'assignee' ),
            (object) array( 'user_id' => 22, 'role' => 'supervisor' ),
        );
        $state->predecessors[10] = array( 3, 999 );
        return $state;
    }

    function recurrence_service( $state, $policy = null ) {
        $GLOBALS['pandatask_recurrence_state'] = $state;
        $GLOBALS['pandatask_recurrence_events'] = array();
        \Pandatask\Infrastructure\Media\ProtectedAttachmentService::$sync_result = array( 'created_keys' => array(), 'obsolete_keys' => array() );
        return new \Pandatask\Application\Task\TaskRecurrenceService(
            new RecurrenceHarnessRepository( $state ),
            new RecurrenceHarnessCommands( $state ),
            new RecurrenceHarnessOccurrences( $state ),
            new RecurrenceHarnessHistory( $state ),
            new RecurrenceHarnessTasks( $state ),
            $policy ?: new RecurrenceHarnessPolicy(),
            new RecurrenceHarnessInvariants(),
            new RecurrenceHarnessCache()
        );
    }

    $GLOBALS['pandatask_recurrence_today'] = '2024-01-01';
    $failures = array();
    $assert = static function ( $condition, $message ) use ( &$failures ) {
        if ( ! $condition ) {
            $failures[] = $message;
        }
    };

    $state = recurrence_state();
    $service = recurrence_service( $state );
    $original_before = clone $state->tasks[10];
    $series_id = $service->attachTask( 10, 7 );
    $template = json_decode( $state->series[ $series_id ]->template_json, true );
    $assert( 100 === $series_id, 'Attaching a legacy recurring task should create a series.' );
    $assert( 100 === (int) $state->tasks[10]->recurrence_series_id && 1 === (int) $state->tasks[10]->recurrence_sequence, 'The original task should be linked as occurrence one.' );
    $assert( '2024-02-29' === $state->series[100]->next_start_date, 'Monthly recurrence should preserve the day-of-month anchor.' );
    $assert( $original_before->name === $state->tasks[10]->name && $original_before->description === $state->tasks[10]->description, 'Attaching should preserve the original task fields.' );
    $assert( 'pending' === $state->tasks[10]->status && '2024-01-31' === $state->tasks[10]->start_date && '2024-02-02' === $state->tasks[10]->deadline, 'Attaching should preserve the original task state and dates.' );
    $assert( 77 === (int) $state->tasks[10]->current_work_occurrence_id, 'Attaching should preserve the original work occurrence.' );
    $assert( $original_before->checklist_json === $state->tasks[10]->checklist_json, 'Attaching should preserve the original checklist JSON.' );
    $assert( false === $template['checklist'][0]['checked'], 'Series template checklist should be reset to unchecked.' );
    $assert( 1 === count( $state->history ) && 'recurrence_series_created' === $state->history[0][2], 'Series attachment should record history.' );

    $GLOBALS['pandatask_recurrence_today'] = '2024-03-01';
    $new_id = $service->advance( 10, 7 );
    $new_task = $state->tasks[ $new_id ];
    $assert( 200 === $new_id, 'Advancing a due occurrence should create a separate task id.' );
    $assert( 2 === (int) $new_task->recurrence_sequence && 100 === (int) $new_task->recurrence_series_id, 'The successor should have the next series sequence.' );
    $assert( '2024-02-29' === $new_task->start_date && '2024-03-02' === $new_task->deadline, 'The successor should use the scheduled start and inherited deadline offset.' );
    $assert( 'pending' === $new_task->status && false === json_decode( $new_task->checklist_json, true )[0]['checked'], 'The successor should start pending with an unchecked checklist.' );
    $assert( 2 === count( $state->assignments[200] ) && array( 3 ) === $state->predecessors[200], 'Assignments and existing dependencies should be copied while missing predecessors are dropped.' );
    $assert( 200 === (int) $state->series[100]->current_task_id && '2024-03-31' === $state->series[100]->next_start_date, 'Advancement should move the series cursor using the monthly anchor.' );
    $assert( 1 === (int) $state->series[100]->version, 'Advancement should increment the series version.' );
    $assert( 1 === (int) $state->tasks[10]->recurrence_sequence && 77 === (int) $state->tasks[10]->current_work_occurrence_id, 'Advancement should leave the original occurrence work state intact.' );

    $archived_latest_state = recurrence_state();
    $GLOBALS['pandatask_recurrence_today'] = '2024-01-01';
    $archived_latest_service = recurrence_service( $archived_latest_state );
    $archived_latest_service->attachTask( 10, 7 );
    $archived_latest_state->tasks[10]->archived = 1;
    $GLOBALS['pandatask_recurrence_today'] = '2024-03-01';
    $archived_successor_id = $archived_latest_service->advance( 10, 7 );
    $assert( 200 === $archived_successor_id && 1 === (int) $archived_latest_state->tasks[10]->archived, 'Advancing an archived latest occurrence should create a successor while preserving the archived row.' );
    $assert( 200 === (int) $archived_latest_state->series[100]->current_task_id && 2 === (int) $archived_latest_state->tasks[200]->recurrence_sequence, 'An archived latest occurrence should advance the series cursor to a separate occurrence.' );

    $archived_legacy_state = recurrence_state();
    $archived_legacy_state->tasks[10]->archived = 1;
    $GLOBALS['pandatask_recurrence_today'] = '2024-01-01';
    $archived_legacy_service = recurrence_service( $archived_legacy_state );
    $archived_legacy_series_id = $archived_legacy_service->attachTask( 10, 7 );
    $assert( 100 === $archived_legacy_series_id && 0 === (int) $archived_legacy_state->series[100]->active, 'Attaching an archived legacy recurring task should leave the new series inactive.' );

    $task_count = count( $state->tasks );
    $version = (int) $state->series[100]->version;
    $assert( null === $service->advance( 10, 7 ), 'Retrying the historical task id should not advance twice.' );
    $assert( $task_count === count( $state->tasks ) && $version === (int) $state->series[100]->version, 'A historical retry should not create a duplicate successor.' );

    foreach ( array( 'update_series_fail', 'history_fail', 'work_fail' ) as $failure ) {
        $failure_state = recurrence_state();
        $GLOBALS['pandatask_recurrence_today'] = '2024-01-01';
        $failure_service = recurrence_service( $failure_state );
        $failure_service->attachTask( 10, 7 );
        $GLOBALS['pandatask_recurrence_today'] = '2024-03-01';
        $failure_state->{$failure} = true;
        $before_tasks = array_keys( $failure_state->tasks );
        $before_series = clone $failure_state->series[100];
        $failed = $failure_service->advance( 10, 7 );
        $assert( is_wp_error( $failed ) && 'pandatask_recurrence_failed' === $failed->get_error_code(), $failure . ' should return a recurrence failure.' );
        $assert( in_array( 'rollback', $GLOBALS['pandatask_recurrence_events'], true ), $failure . ' should roll back the transaction.' );
        $assert( $before_tasks === array_keys( $failure_state->tasks ), $failure . ' should not leave an inserted task after rollback.' );
        $assert( (int) $before_series->current_task_id === (int) $failure_state->series[100]->current_task_id && (int) $before_series->version === (int) $failure_state->series[100]->version, $failure . ' should restore the series cursor and version.' );
    }

    $conflict_state = recurrence_state();
    $GLOBALS['pandatask_recurrence_today'] = '2024-01-01';
    $conflict_service = recurrence_service( $conflict_state );
    $conflict_service->attachTask( 10, 7 );
    $stale = $conflict_service->lockForUpdate( 10, 'future', 1, 7 );
    $assert( is_wp_error( $stale ) && 'pandatask_recurrence_conflict' === $stale->get_error_code(), 'A stale series version should be rejected.' );
    $GLOBALS['pandatask_recurrence_today'] = '2024-03-01';
    $conflict_service->advance( 10, 7 );
    $historical = $conflict_service->lockForUpdate( 10, 'future', 1, 7 );
    $assert( is_wp_error( $historical ) && 'pandatask_recurrence_conflict' === $historical->get_error_code(), 'Editing a historical occurrence should be rejected.' );

    foreach ( array( 'skip', 'stop' ) as $operation ) {
        $operation_state = recurrence_state();
        $GLOBALS['pandatask_recurrence_today'] = '2024-01-01';
        $operation_service = recurrence_service( $operation_state );
        $operation_service->attachTask( 10, 7 );
        $before = clone $operation_state->tasks[10];
        $GLOBALS['pandatask_recurrence_today'] = '2024-01-01';
        if ( 'skip' === $operation ) {
            $operation_service->advance( 10, 7, false, true, false );
            $assert( 1 === (int) $operation_state->tasks[10]->archived, 'Skipping should archive the existing occurrence.' );
        } else {
            $operation_service->advance( 10, 7, false, false, true );
            $assert( 0 === (int) $operation_state->series[100]->active, 'Stopping should deactivate the series.' );
        }
        $assert( $before->status === $operation_state->tasks[10]->status && $before->start_date === $operation_state->tasks[10]->start_date && $before->deadline === $operation_state->tasks[10]->deadline && 77 === (int) $operation_state->tasks[10]->current_work_occurrence_id, $operation . ' should preserve the occurrence state, dates, and work id.' );
    }

    $end_state = recurrence_state( '2024-03-01' );
    $GLOBALS['pandatask_recurrence_today'] = '2024-01-01';
    $end_service = recurrence_service( $end_state );
    $end_service->attachTask( 10, 7 );
    $GLOBALS['pandatask_recurrence_today'] = '2024-03-01';
    $end_service->advance( 10, 7 );
    $assert( null === $end_state->series[100]->next_start_date && 0 === (int) $end_state->series[100]->active, 'A successor beyond the recurrence end date should deactivate the series.' );

    $read_state = recurrence_state();
    $read_state->tasks[10]->attachment_url = 'https://example.test/private.pdf';
    $read_state->tasks[10]->attachment_post_id = 123;
    $read_state->tasks[10]->attachment_filename = 'private.pdf';
    $GLOBALS['pandatask_recurrence_today'] = '2024-01-01';
    $read_service = recurrence_service( $read_state, new RecurrenceHarnessPolicy() );
    $read_service->attachTask( 10, 7 );
    $GLOBALS['pandatask_recurrence_today'] = '2024-03-01';
    $read_service->advance( 10, 7 );
    $policy = new RecurrenceHarnessPolicy();
    $policy->readable = array( 10 );
    $read_service = recurrence_service( $read_state, $policy );
    $series_view = $read_service->getSeries( 10, 7 );
    $assert( is_array( $series_view ) && 1 === count( $series_view['occurrences'] ), 'getSeries should filter occurrences the viewer cannot read.' );
    $assert( 10 === (int) $series_view['occurrences'][0]['id'] && null === $series_view['series']['current_task_id'], 'getSeries should hide the unreadable current occurrence identity.' );
    $assert( empty( $series_view['series']['template']['predecessors'] ), 'getSeries should filter predecessors the viewer cannot read.' );
    $assert( ! array_key_exists( 'attachment_url', $series_view['series']['template'] ) && ! array_key_exists( 'attachment_post_id', $series_view['series']['template'] ) && ! array_key_exists( 'attachment_filename', $series_view['series']['template'] ), 'getSeries should strip protected attachment fields from editable templates.' );

    $hidden_newer_state = recurrence_state();
    $GLOBALS['pandatask_recurrence_today'] = '2024-01-01';
    $hidden_newer_service = recurrence_service( $hidden_newer_state );
    $hidden_newer_service->attachTask( 10, 7 );
    $hidden_newer_state->tasks[300] = recurrence_series_occurrence( 300, 2 );
    $hidden_newer_policy = new RecurrenceHarnessPolicy();
    $hidden_newer_policy->readable = array( 10 );
    $hidden_newer_service = recurrence_service( $hidden_newer_state, $hidden_newer_policy );
    $hidden_newer_view = $hidden_newer_service->getSeries( 10, 7, 1 );
    $assert( 1 === count( $hidden_newer_view['occurrences'] ) && 10 === (int) $hidden_newer_view['occurrences'][0]['id'], 'getSeries should return the visible older occurrence when the newer occurrence is hidden.' );
    $assert( false === $hidden_newer_view['has_more'] && null === $hidden_newer_view['next_before_sequence'], 'getSeries should not advertise a page after hidden newer occurrences.' );

    $hidden_middle_state = recurrence_state();
    $GLOBALS['pandatask_recurrence_today'] = '2024-01-01';
    $hidden_middle_service = recurrence_service( $hidden_middle_state );
    $hidden_middle_service->attachTask( 10, 7 );
    $hidden_middle_state->tasks[302] = recurrence_series_occurrence( 302, 2 );
    $hidden_middle_state->tasks[303] = recurrence_series_occurrence( 303, 3 );
    $hidden_middle_policy = new RecurrenceHarnessPolicy();
    $hidden_middle_policy->readable = array( 10, 303 );
    $hidden_middle_service = recurrence_service( $hidden_middle_state, $hidden_middle_policy );
    $first_page = $hidden_middle_service->getSeries( 10, 7, 1 );
    $assert( 1 === count( $first_page['occurrences'] ) && 303 === (int) $first_page['occurrences'][0]['id'], 'getSeries should return the newest authorized occurrence first.' );
    $assert( true === $first_page['has_more'] && 3 === (int) $first_page['next_before_sequence'], 'getSeries should page from the last authorized occurrence when a hidden middle occurrence remains.' );
    $second_page = $hidden_middle_service->getSeries( 10, 7, 1, 3 );
    $assert( 1 === count( $second_page['occurrences'] ) && 10 === (int) $second_page['occurrences'][0]['id'], 'getSeries should find the older authorized occurrence after a hidden middle occurrence.' );
    $assert( false === $second_page['has_more'] && null === $second_page['next_before_sequence'], 'getSeries should end pagination after the last authorized occurrence.' );

    $stopped_state = recurrence_state();
    $GLOBALS['pandatask_recurrence_today'] = '2024-01-01';
    $stopped_service = recurrence_service( $stopped_state );
    $stopped_service->attachTask( 10, 7 );
    $stopped_state->tasks[10]->name = 'Edited stopped template';
    $stopped_series = clone $stopped_state->series[100];
    $stopped_series->active = 0;
    $stopped_service->syncTemplate( 10, $stopped_series, 7, false );
    $stopped_template = json_decode( $stopped_state->series[100]->template_json, true );
    $assert( 0 === (int) $stopped_state->series[100]->active && 'Edited stopped template' === $stopped_template['name'], 'syncTemplate should preserve a stopped series while applying future template edits.' );
    $assert( '2024-02-29' === $stopped_state->series[100]->next_start_date, 'syncTemplate should preserve the scheduled next date when the schedule is unchanged.' );
    $enabled_series = clone $stopped_state->series[100];
    $stopped_service->syncTemplate( 10, $enabled_series, 7, false, true );
    $assert( 1 === (int) $stopped_state->series[100]->active, 'syncTemplate should reactivate a stopped series when explicitly enabled.' );

    if ( ! empty( $failures ) ) {
        fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
        exit( 1 );
    }

    echo "Task recurrence service tests passed.\n";
}
