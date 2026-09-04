<?php

/**
 * Focused, dependency-free checks for task-time revisions created on reopen.
 *
 * Run with: php tests/task-time-reopen.php
 */

namespace Pandatask\Infrastructure\Persistence {
    final class DatabaseContext {
        public static function getDbPrefix() {
            return 'wp_pandat69_';
        }

        public static function beginTransaction() {
            return true;
        }

        public static function commit() {
            return true;
        }

        public static function rollback() {
            return true;
        }

        public static function invalidateUserCache( $user_id ) {
            unset( $user_id );
        }

        public static function invalidateBoardCache( $board_name, $types = null ) {
            unset( $board_name, $types );
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

    if ( ! function_exists( 'sanitize_text_field' ) ) {
        function sanitize_text_field( $value ) {
            return trim( (string) $value );
        }
    }

    if ( ! function_exists( 'wp_kses_post' ) ) {
        function wp_kses_post( $value ) {
            return (string) $value;
        }
    }

    if ( ! function_exists( 'wp_date' ) ) {
        function wp_date( $format ) {
            return gmdate( $format );
        }
    }

    if ( ! function_exists( 'wp_timezone' ) ) {
        function wp_timezone() {
            return new \DateTimeZone( 'UTC' );
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

    if ( ! class_exists( 'WP_Error' ) ) {
        class WP_Error {
            private $code;

            public function __construct( $code, $message = '', $data = null ) {
                unset( $message, $data );
                $this->code = $code;
            }

            public function get_error_code() {
                return $this->code;
            }
        }
    }

    if ( ! function_exists( 'is_wp_error' ) ) {
        function is_wp_error( $value ) {
            return $value instanceof WP_Error;
        }
    }

    final class TaskTimeReopenTestWpdb {
        public $queries = array();

        public function prepare( $query ) {
            $this->queries[] = $query;
            return $query;
        }

        public function get_col( $query ) {
            $this->queries[] = $query;
            return array( 8, 9 );
        }
    }

    require_once dirname( __DIR__ ) . '/src/Infrastructure/Persistence/TaskTimeRepository.php';
    require_once dirname( __DIR__ ) . '/src/Domain/Work/TimeReconciler.php';
    require_once dirname( __DIR__ ) . '/src/Application/Work/TaskTimeService.php';
    require_once dirname( __DIR__ ) . '/src/Application/Work/WorkEntryService.php';

    use Pandatask\Infrastructure\Persistence\TaskTimeRepository;
    use Pandatask\Application\Work\TaskTimeService;

    final class TaskTimeReopenTestTimeRepository {
        public $user_ids = array( 8, 9 );
        public $latest_by_user = array();
        public $revisions = array();
        public $fail_user_id = 0;
        private $next_id = 100;

        public function userIdsForOccurrence( $occurrence_id ) {
            return $this->user_ids;
        }

        public function latest( $occurrence_id, $user_id ) {
            foreach ( array_reverse( $this->revisions ) as $revision ) {
                if ( (int) $revision['occurrence_id'] === (int) $occurrence_id && (int) $revision['user_id'] === (int) $user_id ) {
                    return (object) $revision;
                }
            }
            return $this->latest_by_user[ (int) $user_id ] ?? null;
        }

        public function insertRevision( array $data ) {
            if ( (int) $this->fail_user_id === (int) $data['user_id'] ) {
                return false;
            }
            $this->revisions[] = $data;
            return $this->next_id++;
        }
    }

    final class TaskTimeReopenTestWorkRepository {
        public $specific_by_user = array( 8 => 300, 9 => 180 );
        public $valid_entries = array( 501 => true );
        public $entry;

        public function specificSecondsForOccurrenceUser( $occurrence_id, $user_id, $exclude_residual = true ) {
            if ( $this->entry && 8 === (int) $user_id ) {
                $specific = 0;
                foreach ( (array) $this->entry->allocations as $allocation ) {
                    if ( (int) ( $allocation->occurrence_id ?? 0 ) === (int) $occurrence_id ) {
                        $specific += (int) $allocation->seconds;
                    }
                }
                return $specific;
            }
            return (int) ( $this->specific_by_user[ (int) $user_id ] ?? 0 );
        }

        public function findById( $entry_id ) {
            if ( 1 === (int) $entry_id && $this->entry ) {
                return $this->entry;
            }
            if ( 501 === (int) $entry_id && ! empty( $this->valid_entries[501] ) ) {
                return (object) array(
                    'id'            => 501,
                    'kind'          => 'residual',
                    'activity_type' => 'development',
                    'capacity'      => 'other',
                    'title'         => 'Other task time',
                    'notes'         => null,
                );
            }
            return ! empty( $this->valid_entries[ (int) $entry_id ] ) ? (object) array( 'id' => (int) $entry_id ) : null;
        }

        public function update( $entry_id, array $data ) {
            $entry = $this->findById( $entry_id );
            if ( ! $entry ) {
                return false;
            }
            foreach ( $data as $key => $value ) {
                $entry->{$key} = $value;
            }
            return true;
        }

        public function replaceAllocations( $entry_id, array $allocations ) {
            $entry = $this->findById( $entry_id );
            if ( ! $entry ) {
                return false;
            }
            $entry->allocations = array_map(
                static function ( $allocation ) {
                    return (object) $allocation;
                },
                $allocations
            );
            return true;
        }
    }

    final class TaskTimeReopenTestAuditRepository {
        public $records = array();
        public $fail_user_id = 0;

        public function record( $entity_type, $entity_id, $action, $actor_id, $before, $after ) {
            $this->records[] = array(
                'entity_type' => $entity_type,
                'entity_id'   => (int) $entity_id,
                'action'      => $action,
                'actor_id'    => (int) $actor_id,
                'before'      => $before,
                'after'       => $after,
            );
            $after_user_id = is_object( $after ) ? (int) ( $after->user_id ?? 0 ) : (int) ( $after['user_id'] ?? 0 );
            return ! $this->fail_user_id || $after_user_id !== (int) $this->fail_user_id;
        }
    }

    final class TaskTimeReopenTestOccurrenceRepository {
        public $occurrence;

        public function __construct() {
            $this->occurrence = (object) array(
                'id'                     => 77,
                'task_id'                => 42,
                'state'                  => 'open',
                'completed_at'           => '2026-09-01 10:00:00',
                'task_name_snapshot'     => 'Reopened task',
                'board_name_snapshot'    => 'audit-board',
                'project_id_snapshot'    => null,
                'project_name_snapshot'  => null,
                'category_id_snapshot'  => null,
                'category_name_snapshot' => null,
            );
        }

        public function findById( $occurrence_id ) {
            return 77 === (int) $occurrence_id ? $this->occurrence : null;
        }

        public function findCurrentForTask( $task_id ) {
            return 42 === (int) $task_id ? $this->occurrence : null;
        }
    }
    final class TaskTimeReopenTestReconciler {}
    final class TaskTimeReopenTestWorkTypeService {
        public function isActive( $key, $user_id ) {
            return 'development' === $key;
        }

        public function isKnown( $key, $user_id ) {
            return 'development' === $key;
        }

        public function label( $key, $user_id ) {
            return 'Development';
        }
    }

    final class TaskTimeReopenTestTaskRepository {
        public function findById( $task_id ) {
            return 42 === (int) $task_id
                ? (object) array(
                    'id'            => 42,
                    'name'          => 'Reopened task',
                    'board_name'    => 'audit-board',
                    'status'        => 'in-progress',
                    'project_id'    => null,
                    'project_name'  => null,
                    'category_id'   => null,
                    'category_name' => null,
                )
                : null;
        }

        public function findDescendantProjectRecords( $task_id, $board_name ) {
            return array();
        }

        public function findFollowUps( $task_id ) {
            return array();
        }
    }

    final class TaskTimeReopenTestTaskAccessPolicy {
        public function canReadTask( $task_id, $user_id ) {
            return true;
        }
    }

    final class TaskTimeReopenTestBoardAccessPolicy {
        public function canReadBoard( $board_name, $user_id ) {
            return true;
        }
    }

    $failures = array();
    $assert = static function ( $condition, $message ) use ( &$failures ) {
        if ( ! $condition ) {
            $failures[] = $message;
        }
    };

    $GLOBALS['wpdb'] = new TaskTimeReopenTestWpdb();
    $database_time_repository = new TaskTimeRepository();
    $database_user_ids = $database_time_repository->userIdsForOccurrence( 77 );
    $assert( array( 8, 9 ) === $database_user_ids, 'The occurrence user query should return unique positive user IDs.' );
    $assert( false !== strpos( $GLOBALS['wpdb']->queries[0] ?? '', 'SELECT DISTINCT user_id' ), 'The occurrence user query should deduplicate users in SQL.' );
    $assert( false !== strpos( $GLOBALS['wpdb']->queries[0] ?? '', 'user_id > 0' ), 'The occurrence user query should exclude non-positive user IDs in SQL.' );

    $time_repository = new TaskTimeReopenTestTimeRepository();
    $time_repository->latest_by_user = array(
        8 => (object) array(
            'state'                   => 'resolved',
            'declared_actual_seconds' => 420,
            'residual_entry_id'       => 501,
        ),
        9 => (object) array(
            'state'                   => 'not_tracked',
            'declared_actual_seconds' => null,
            'residual_entry_id'       => 999,
        ),
    );
    $work_repository = new TaskTimeReopenTestWorkRepository();
    $audit_repository = new TaskTimeReopenTestAuditRepository();
    $service = new TaskTimeService(
        $work_repository,
        $time_repository,
        new TaskTimeReopenTestOccurrenceRepository(),
        $audit_repository,
        new TaskTimeReopenTestReconciler(),
        new TaskTimeReopenTestWorkTypeService()
    );

    $result = $service->reviseOnReopen( 77, 42 );
    $assert( true === $result, 'Reopen time revision should succeed for every user with prior resolution.' );
    $assert( 2 === count( $time_repository->revisions ), 'Reopen should create one latest unresolved revision per resolved user.' );
    $assert(
        array(
            array(
                'occurrence_id'           => 77,
                'user_id'                 => 8,
                'state'                   => 'unresolved',
                'declared_actual_seconds' => 420,
                'specific_seconds'        => 300,
                'residual_entry_id'       => 501,
                'resolved_by'             => 42,
            ),
            array(
                'occurrence_id'           => 77,
                'user_id'                 => 9,
                'state'                   => 'unresolved',
                'declared_actual_seconds' => null,
                'specific_seconds'        => 180,
                'residual_entry_id'       => null,
                'resolved_by'             => 42,
            ),
        ) === $time_repository->revisions,
        'Reopen should preserve declared context and valid residual IDs while recomputing specific seconds.'
    );
    $assert( 2 === count( $audit_repository->records ), 'Every reopened time revision should be audited.' );
    $assert( 'reopened' === $audit_repository->records[0]['action'] && 'reopened' === $audit_repository->records[1]['action'], 'Reopen revisions should use the reopened audit action.' );

    $failing_time_repository = new TaskTimeReopenTestTimeRepository();
    $failing_time_repository->latest_by_user = $time_repository->latest_by_user;
    $failing_time_repository->fail_user_id = 9;
    $failing_audit_repository = new TaskTimeReopenTestAuditRepository();
    $failing_service = new TaskTimeService(
        $work_repository,
        $failing_time_repository,
        new TaskTimeReopenTestOccurrenceRepository(),
        $failing_audit_repository,
        new TaskTimeReopenTestReconciler(),
        new TaskTimeReopenTestWorkTypeService()
    );
    $failed = $failing_service->reviseOnReopen( 77, 42 );
    $assert( false === $failed, 'A failed reopen revision must fail the enclosing mutation.' );
    $assert( 1 === count( $failing_time_repository->revisions ), 'Revision failure should stop before creating later revisions.' );

    $audit_failing_time_repository = new TaskTimeReopenTestTimeRepository();
    $audit_failing_time_repository->latest_by_user = $time_repository->latest_by_user;
    $audit_failing_audit_repository = new TaskTimeReopenTestAuditRepository();
    $audit_failing_audit_repository->fail_user_id = 9;
    $audit_failing_service = new TaskTimeService(
        $work_repository,
        $audit_failing_time_repository,
        new TaskTimeReopenTestOccurrenceRepository(),
        $audit_failing_audit_repository,
        new TaskTimeReopenTestReconciler(),
        new TaskTimeReopenTestWorkTypeService()
    );
    $audit_failed = $audit_failing_service->reviseOnReopen( 77, 42 );
    $assert( false === $audit_failed, 'An audit failure must fail the enclosing reopen mutation.' );

    $addition_time_repository = new TaskTimeReopenTestTimeRepository();
    $addition_time_repository->user_ids = array( 8 );
    $addition_time_repository->latest_by_user = array(
        8 => (object) array(
            'state'                   => 'resolved',
            'declared_actual_seconds' => 420,
            'residual_entry_id'       => 501,
        ),
    );
    $addition_work_repository = new TaskTimeReopenTestWorkRepository();
    $addition_audit_repository = new TaskTimeReopenTestAuditRepository();
    $addition_service = new TaskTimeService(
        $addition_work_repository,
        $addition_time_repository,
        new TaskTimeReopenTestOccurrenceRepository(),
        $addition_audit_repository,
        new \Pandatask\Domain\Work\TimeReconciler(),
        new TaskTimeReopenTestWorkTypeService()
    );
    $assert( true === $addition_service->reviseOnReopen( 77, 42 ), 'The addition scenario should reopen the prior resolution.' );
    $addition_work_repository->specific_by_user[8] = 360;
    $addition_result = $addition_service->applySpecificAddition( 77, 8, 60, '', 42 );
    $addition_latest = $addition_time_repository->latest( 77, 8 );
    $assert( true === $addition_result, 'New detailed work should keep a reopened occurrence unresolved.' );
    $assert(
        $addition_latest
        && 'unresolved' === $addition_latest->state
        && 420 === (int) $addition_latest->declared_actual_seconds
        && 360 === (int) $addition_latest->specific_seconds
        && 501 === (int) $addition_latest->residual_entry_id,
        'New detail after reopen must preserve the existing residual link and declared baseline until re-resolution.'
    );

    $e2e_time_repository = new TaskTimeReopenTestTimeRepository();
    $e2e_time_repository->user_ids = array( 8 );
    $e2e_time_repository->latest_by_user = array(
        8 => (object) array(
            'state'                   => 'resolved',
            'declared_actual_seconds' => 420,
            'residual_entry_id'       => 501,
        ),
    );
    $e2e_work_repository = new TaskTimeReopenTestWorkRepository();
    $e2e_work_repository->entry = (object) array(
        'id'               => 1,
        'user_id'          => 8,
        'created_by'       => 8,
        'title'            => 'Detailed work',
        'notes'            => 'Original detail',
        'activity_type'    => 'development',
        'capacity'         => 'other',
        'work_date'        => '2026-09-01',
        'duration_seconds' => 300,
        'started_at_utc'   => null,
        'ended_at_utc'     => null,
        'timezone'         => 'UTC',
        'visibility'       => 'private',
        'kind'             => 'manual',
        'source_key'       => null,
        'source_url'       => null,
        'allocations'      => array(
            (object) array(
                'occurrence_id'          => 77,
                'allocation_context'    => 'occurrence',
                'seconds'               => 300,
                'task_id_snapshot'      => 42,
                'task_name_snapshot'    => 'Reopened task',
                'board_name_snapshot'   => 'audit-board',
                'project_id_snapshot'   => null,
                'project_name_snapshot' => null,
                'category_id_snapshot'  => null,
                'category_name_snapshot' => null,
            ),
        ),
    );
    $e2e_audit_repository = new TaskTimeReopenTestAuditRepository();
    $e2e_occurrence_repository = new TaskTimeReopenTestOccurrenceRepository();
    $e2e_time_service = new TaskTimeService(
        $e2e_work_repository,
        $e2e_time_repository,
        $e2e_occurrence_repository,
        $e2e_audit_repository,
        new \Pandatask\Domain\Work\TimeReconciler(),
        new TaskTimeReopenTestWorkTypeService()
    );
    $e2e_work_service = new \Pandatask\Application\Work\WorkEntryService(
        $e2e_work_repository,
        new TaskTimeReopenTestTaskRepository(),
        $e2e_occurrence_repository,
        new TaskTimeReopenTestTaskAccessPolicy(),
        $e2e_audit_repository,
        $e2e_time_service,
        new TaskTimeReopenTestBoardAccessPolicy(),
        new TaskTimeReopenTestWorkTypeService()
    );
    $locked_before_reopen = $e2e_work_service->updateEntry( 1, array( 'notes' => 'Must remain locked' ), 8 );
    $assert( is_wp_error( $locked_before_reopen ) && 'pandatask_resolved_work_locked' === $locked_before_reopen->get_error_code(), 'Original detailed work should remain locked while its latest state is resolved.' );
    $assert( true === $e2e_time_service->reviseOnReopen( 77, 42 ), 'The end-to-end reopen revision should succeed before correcting detailed work.' );

    $corrected = $e2e_work_service->updateEntry(
        1,
        array(
            'notes'       => 'Corrected detail',
            'allocations' => array( array( 'task_id' => 42, 'seconds' => 180 ) ),
        ),
        8
    );
    $assert( ! is_wp_error( $corrected ) && 'Corrected detail' === $e2e_work_repository->entry->notes, 'Original detailed work should become editable after reopen.' );
    $assert( 180 === (int) $e2e_work_repository->entry->allocations[0]->seconds, 'The reopened detailed work should retain its corrected lower allocation.' );

    $lower_reresolution = $e2e_time_service->resolveCurrentOccurrence( 42, 8, 200, false, 42 );
    $assert( ! is_wp_error( $lower_reresolution ), 'The same occurrence should accept a lower cumulative actual after detailed work correction.' );
    $assert( 77 === (int) ( $lower_reresolution['occurrence_id'] ?? 0 ), 'Lower re-resolution should target the reopened current occurrence.' );
    $assert( 'resolved' === (string) ( $lower_reresolution['state'] ?? '' ) && 180 === (int) ( $lower_reresolution['specific_seconds'] ?? 0 ), 'Lower re-resolution should use the corrected specific seconds.' );
    $latest_e2e = $e2e_time_repository->latest( 77, 8 );
    $assert( $latest_e2e && 'resolved' === $latest_e2e->state && 200 === (int) $latest_e2e->declared_actual_seconds, 'The latest corrected occurrence resolution should record the lower cumulative actual.' );

    if ( ! empty( $failures ) ) {
        fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
        exit( 1 );
    }

    echo "Task-time reopen tests passed.\n";
}
