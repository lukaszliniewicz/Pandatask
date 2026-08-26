<?php

/**
 * Focused, dependency-free checks for WorkEntryService update/delete behavior.
 *
 * Run with: php tests/work-entry-update.php
 */

if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = null ) { return $text; }
}
if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) ); }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $text ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $text ) ) ); }
}
if ( ! function_exists( 'wp_kses_post' ) ) {
    function wp_kses_post( $text ) { return (string) $text; }
}
if ( ! function_exists( 'absint' ) ) {
    function absint( $value ) { return abs( (int) $value ); }
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
if ( ! function_exists( 'get_transient' ) ) {
    function get_transient( $key ) { return $GLOBALS['pandatask_test_transients'][ $key ] ?? false; }
}
if ( ! function_exists( 'set_transient' ) ) {
    function set_transient( $key, $value, $expiration ) {
        $GLOBALS['pandatask_test_transients'][ $key ] = $value;
        return true;
    }
}
if ( ! function_exists( 'delete_transient' ) ) {
    function delete_transient( $key ) {
        unset( $GLOBALS['pandatask_test_transients'][ $key ] );
        return true;
    }
}
if ( ! function_exists( 'wp_timezone' ) ) {
    function wp_timezone() { return new DateTimeZone( 'Europe/Warsaw' ); }
}
if ( ! function_exists( 'wp_date' ) ) {
    function wp_date( $format ) { return ( new DateTimeImmutable( 'now', wp_timezone() ) )->format( $format ); }
}
if ( ! function_exists( 'user_can' ) ) {
    function user_can( $user_id, $capability ) {
        return ! empty( $GLOBALS['pandatask_test_capabilities'][ (int) $user_id ][ $capability ] );
    }
}
if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $value ) { return $value instanceof WP_Error; }
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
        public function get_error_code() { return $this->code; }
        public function get_error_message() { return $this->message; }
        public function get_error_data() { return $this->data; }
    }
}

if ( ! defined( 'YEAR_IN_SECONDS' ) ) {
    define( 'YEAR_IN_SECONDS', 31536000 );
}

final class WorkEntryUpdateTestWpdb {
    public $prefix = 'wp_';

    public $queries = array();

    public function query( $query ) {
        $this->queries[] = $query;
        return true;
    }
}

final class WorkEntryUpdateTestRepository {
    public $entry;

    public $updates = array();

    public $allocation_replacements = array();

    public $deleted = false;

    public function __construct( $entry ) {
        $this->entry = $entry;
    }

    public function findById( $entry_id ) {
        return 1 === (int) $entry_id && ! $this->deleted ? $this->entry : null;
    }

    public function update( $entry_id, array $data ) {
        $this->updates[] = $data;
        foreach ( $data as $key => $value ) {
            $this->entry->{$key} = $value;
        }
        return true;
    }

    public function replaceAllocations( $entry_id, array $allocations ) {
        $this->allocation_replacements[] = $allocations;
        $this->entry->allocations = array_map(
            static function ( $allocation ) {
                return (object) array(
                    'seconds'              => $allocation['seconds'],
                    'occurrence_id'        => $allocation['occurrence_id'],
                    'task_id_snapshot'     => $allocation['task_id_snapshot'],
                    'board_name_snapshot'  => $allocation['board_name_snapshot'],
                );
            },
            $allocations
        );
        return true;
    }

    public function softDelete( $entry_id ) {
        $this->deleted = true;
        return true;
    }
}

final class WorkEntryUpdateTestTaskRepository {
    public $tasks;

    public function __construct( array $tasks ) {
        $this->tasks = $tasks;
    }

    public function findById( $task_id ) {
        return $this->tasks[ (int) $task_id ] ?? null;
    }
}

final class WorkEntryUpdateTestOccurrenceRepository {
    private $current_by_task;

    public function __construct( array $current_by_task = array() ) {
        $this->current_by_task = $current_by_task;
    }

    public function findCurrentForTask( $task_id ) {
        return $this->current_by_task[ (int) $task_id ] ?? null;
    }
}

final class WorkEntryUpdateTestTaskAccessPolicy {
    public function canReadTask( $task_id, $user_id ) {
        return in_array( (int) $task_id, array( 101, 102 ), true )
            ? true
            : new WP_Error( 'rest_forbidden', 'Task is not readable.', array( 'status' => 403 ) );
    }
}

final class WorkEntryUpdateTestAuditRepository {
    public $records = array();

    public function record( $type, $id, $action, $actor_id, $old, $new ) {
        $this->records[] = array( 'type' => $type, 'id' => $id, 'action' => $action, 'new' => $new );
        return true;
    }
}

final class WorkEntryUpdateTestTimeService {
    public $resolution_state = null;

    public $summaries = array();

    public $addition_calls = array();

    public $resolve_calls = array();

    public function hasResolvedState( $occurrence_id, $user_id ) {
        return in_array( $this->resolution_state, array( 'resolved', 'not_tracked' ), true );
    }

    public function validateSpecificAddition( $occurrence_id, $user_id, $seconds, $mode ) {
        return true;
    }

    public function applySpecificAddition( $occurrence_id, $user_id, $seconds, $mode, $actor_id ) {
        $this->addition_calls[] = array( (int) $occurrence_id, (int) $user_id, (int) $seconds, $mode, (int) $actor_id );
        return true;
    }

    public function getOccurrenceSummary( $occurrence_id, $user_id ) {
        return array( 'resolution' => $this->summaries[ (int) $occurrence_id ] ?? null );
    }

    public function resolveOccurrence( $occurrence_id, $user_id, $actual_seconds, $not_tracked, $actor_id ) {
        $this->resolve_calls[] = array( (int) $occurrence_id, (int) $user_id, $actual_seconds, (bool) $not_tracked, (int) $actor_id );
        return true;
    }
}

final class WorkEntryUpdateTestBoardAccessPolicy {
    public function canReadBoard( $board_name, $user_id ) {
        return true;
    }
}

final class WorkEntryUpdateTestWorkTypeService {
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

require_once dirname( __DIR__ ) . '/src/Infrastructure/Persistence/DatabaseContext.php';
require_once dirname( __DIR__ ) . '/src/Application/Work/WorkEntryService.php';

use Pandatask\Application\Work\WorkEntryService;

$GLOBALS['wpdb'] = new WorkEntryUpdateTestWpdb();
$GLOBALS['pandatask_test_transients'] = array();

$task_a = (object) array(
    'id'         => 101,
    'name'       => 'Task A',
    'board_name' => 'trustees',
    'project_id' => null,
    'category_id' => null,
);
$task_b = (object) array(
    'id'         => 102,
    'name'       => 'Task B',
    'board_name' => 'operations',
    'project_id' => null,
    'category_id' => null,
);

$allocation = static function ( $task_id, $seconds = 3600, $occurrence_id = null ) use ( $task_a, $task_b ) {
    $task = 101 === $task_id ? $task_a : $task_b;
    return (object) array(
        'seconds'             => $seconds,
        'occurrence_id'       => $occurrence_id,
        'task_id_snapshot'    => $task_id,
        'board_name_snapshot' => $task->board_name,
    );
};

$entry = static function ( array $allocations = array() ) use ( $allocation ) {
    return (object) array(
        'id'               => 1,
        'user_id'          => 7,
        'created_by'       => 42,
        'title'            => 'Logged work',
        'notes'            => 'Original notes',
        'activity_type'    => 'development',
        'capacity'         => 'volunteer',
        'work_date'        => '2026-08-26',
        'duration_seconds' => 3600,
        'started_at_utc'   => null,
        'ended_at_utc'     => null,
        'timezone'         => 'Europe/Warsaw',
        'visibility'       => 'private',
        'kind'             => 'manual',
        'source_key'       => null,
        'source_url'       => null,
        'allocations'      => array_map(
            static function ( $item ) use ( $allocation ) {
                return is_object( $item ) ? $item : $allocation( $item['task_id'], $item['seconds'], $item['occurrence_id'] ?? null );
            },
            $allocations
        ),
    );
};

$service = static function ( $repository, $time_service, $occurrences = null ) use ( $task_a, $task_b ) {
    $occurrences = $occurrences ?: array(
        101 => (object) array( 'id' => 201, 'task_id' => 101 ),
        102 => (object) array( 'id' => 202, 'task_id' => 102 ),
    );

    return new WorkEntryService(
        $repository,
        new WorkEntryUpdateTestTaskRepository( array( 101 => $task_a, 102 => $task_b ) ),
        new WorkEntryUpdateTestOccurrenceRepository( $occurrences ),
        new WorkEntryUpdateTestTaskAccessPolicy(),
        new WorkEntryUpdateTestAuditRepository(),
        $time_service,
        new WorkEntryUpdateTestBoardAccessPolicy(),
        new WorkEntryUpdateTestWorkTypeService()
    );
};

$failures = array();
$assert = static function ( $condition, $message ) use ( &$failures ) {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};
$errorCode = static function ( $value ) {
    return is_wp_error( $value ) ? $value->get_error_code() : null;
};

$unallocated_repository = new WorkEntryUpdateTestRepository( $entry() );
$attached = $service( $unallocated_repository, new WorkEntryUpdateTestTimeService() )->updateEntry(
    1,
    array( 'allocations' => array( array( 'task_id' => 101, 'seconds' => 3600 ) ) ),
    7
);
$attached_allocation = $unallocated_repository->allocation_replacements[0][0] ?? array();
$assert( ! is_wp_error( $attached ), 'An unallocated entry should attach to a readable task.' );
$assert( 101 === ( $attached_allocation['task_id_snapshot'] ?? null ) && 'Task A' === ( $attached_allocation['task_name_snapshot'] ?? null ), 'Attached allocation should carry the task snapshot.' );

$detached_repository = new WorkEntryUpdateTestRepository( $entry( array( array( 'task_id' => 101, 'seconds' => 3600 ) ) ) );
$detached = $service( $detached_repository, new WorkEntryUpdateTestTimeService() )->updateEntry( 1, array( 'allocations' => array() ), 7 );
$assert( ! is_wp_error( $detached ) && array() === $detached_repository->allocation_replacements[0], 'allocations=[] should detach every allocation.' );

$moved_repository = new WorkEntryUpdateTestRepository( $entry( array( array( 'task_id' => 101, 'seconds' => 3600 ) ) ) );
$moved = $service( $moved_repository, new WorkEntryUpdateTestTimeService() )->updateEntry(
    1,
    array( 'allocations' => array( array( 'task_id' => 102, 'seconds' => 3600 ) ) ),
    7
);
$moved_allocations = $moved_repository->allocation_replacements[0] ?? array();
$assert( ! is_wp_error( $moved ) && 1 === count( $moved_allocations ) && 102 === $moved_allocations[0]['task_id_snapshot'], 'Moving work should replace task A with task B as one complete allocation set.' );

$partial_repository = new WorkEntryUpdateTestRepository( $entry( array( array( 'task_id' => 101, 'seconds' => 3600, 'occurrence_id' => 77 ) ) ) );
$partial = $service( $partial_repository, new WorkEntryUpdateTestTimeService() )->updateEntry( 1, array( 'notes' => 'Updated notes' ), 7 );
$assert( ! is_wp_error( $partial ) && 'Updated notes' === $partial_repository->entry->notes, 'An ordinary field update should change notes.' );
$assert( array() === $partial_repository->allocation_replacements, 'A metadata-only edit must not replace allocations.' );
$assert( 77 === $partial_repository->entry->allocations[0]->occurrence_id && 101 === $partial_repository->entry->allocations[0]->task_id_snapshot, 'A metadata-only edit must preserve historical occurrence and task snapshots.' );

$shortened_repository = new WorkEntryUpdateTestRepository( $entry( array( array( 'task_id' => 101, 'seconds' => 3600, 'occurrence_id' => 77 ) ) ) );
$shortened = $service( $shortened_repository, new WorkEntryUpdateTestTimeService() )->updateEntry( 1, array( 'duration_seconds' => 1800 ), 7 );
$assert( 'pandatask_overallocated_work' === $errorCode( $shortened ), 'A duration-only edit must not make preserved allocations exceed the entry duration.' );
$assert( array() === $shortened_repository->updates && array() === $shortened_repository->allocation_replacements, 'A rejected duration-only edit must not write the entry or its allocations.' );

$historical_time = new WorkEntryUpdateTestTimeService();
$historical_time->summaries[77] = (object) array(
    'state'                   => 'unresolved',
    'declared_actual_seconds' => null,
);
$historical_detach_repository = new WorkEntryUpdateTestRepository( $entry( array( array( 'task_id' => 101, 'seconds' => 3600, 'occurrence_id' => 77 ) ) ) );
$historical_detach = $service( $historical_detach_repository, $historical_time )->updateEntry( 1, array( 'allocations' => array() ), 7 );
$assert( ! is_wp_error( $historical_detach ) && array( 77 ) === array_column( $historical_time->resolve_calls, 0 ), 'Detaching historical work must reconcile its durable occurrence ID.' );

$move_time = new WorkEntryUpdateTestTimeService();
$move_time->summaries[77] = (object) array(
    'state'                   => 'unresolved',
    'declared_actual_seconds' => null,
);
$historical_move_repository = new WorkEntryUpdateTestRepository( $entry( array( array( 'task_id' => 101, 'seconds' => 3600, 'occurrence_id' => 77 ) ) ) );
$historical_move = $service( $historical_move_repository, $move_time )->updateEntry(
    1,
    array( 'allocations' => array( array( 'task_id' => 102, 'seconds' => 3600 ) ) ),
    7
);
$assert( ! is_wp_error( $historical_move ) && array( 77 ) === array_column( $move_time->resolve_calls, 0 ), 'Moving historical work must reconcile the removed historical occurrence rather than the task current occurrence.' );
$assert( array( 202 ) === array_column( $move_time->addition_calls, 0 ), 'Moving work must apply the new allocation against its current durable occurrence.' );

$GLOBALS['pandatask_test_capabilities'][9]['manage_options'] = true;
$admin_repository = new WorkEntryUpdateTestRepository( $entry() );
$admin_update = $service( $admin_repository, new WorkEntryUpdateTestTimeService() )->updateEntry( 1, array( 'notes' => 'Admin correction' ), 9 );
$assert( ! is_wp_error( $admin_update ) && 42 === $admin_repository->entry->created_by && ! array_key_exists( 'created_by', $admin_repository->updates[0] ), 'An administrator edit must not overwrite work-entry creator provenance.' );

$duplicate_repository = new WorkEntryUpdateTestRepository( $entry() );
$duplicate = $service( $duplicate_repository, new WorkEntryUpdateTestTimeService() )->updateEntry(
    1,
    array(
        'duration_seconds' => 3600,
        'allocations'      => array(
            array( 'task_id' => 101, 'seconds' => 1800 ),
            array( 'task_id' => 101, 'seconds' => 1800 ),
        ),
    ),
    7
);
$assert( 'pandatask_duplicate_work_allocation' === $errorCode( $duplicate ), 'Duplicate task allocations should be rejected before writing.' );
$assert( array() === $duplicate_repository->updates && array() === $duplicate_repository->allocation_replacements, 'Duplicate allocation rejection should not update the entry.' );

$overallocated_repository = new WorkEntryUpdateTestRepository( $entry() );
$overallocated = $service( $overallocated_repository, new WorkEntryUpdateTestTimeService() )->updateEntry(
    1,
    array(
        'duration_seconds' => 3600,
        'allocations'      => array(
            array( 'task_id' => 101, 'seconds' => 2400 ),
            array( 'task_id' => 102, 'seconds' => 2400 ),
        ),
    ),
    7
);
$assert( 'pandatask_overallocated_work' === $errorCode( $overallocated ), 'Allocations exceeding replacement duration should be rejected before writing.' );
$assert( array() === $overallocated_repository->updates && array() === $overallocated_repository->allocation_replacements, 'Over-allocation rejection should not update the entry.' );

foreach ( array( 'resolved', 'not_tracked' ) as $resolution_state ) {
    $locked_time = new WorkEntryUpdateTestTimeService();
    $locked_time->resolution_state = $resolution_state;
    $locked_repository = new WorkEntryUpdateTestRepository( $entry( array( array( 'task_id' => 101, 'seconds' => 3600, 'occurrence_id' => 77 ) ) ) );
    $locked_service = $service( $locked_repository, $locked_time );
    $locked_update = $locked_service->updateEntry( 1, array( 'notes' => 'Should remain locked' ), 7 );
    $locked_delete = $locked_service->deleteEntry( 1, 7 );
    $assert( 'pandatask_resolved_work_locked' === $errorCode( $locked_update ), $resolution_state . ' linked work should not be editable.' );
    $assert( 'pandatask_resolved_work_locked' === $errorCode( $locked_delete ), $resolution_state . ' linked work should not be deletable.' );
    $assert( array() === $locked_repository->updates && ! $locked_repository->deleted, $resolution_state . ' linked work should remain unchanged.' );
}

if ( ! empty( $failures ) ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

echo "Work entry update tests passed.\n";
