<?php

/**
 * Disposable, real-WordPress recurrence integration test.
 *
 * Run with WP-CLI inside the harness created by scripts/test-recurrence-integration.sh.
 * The harness mounts this repository read-only and uses only synthetic users/data.
 */

use Pandatask\Application\Task\TaskChecklistService;
use Pandatask\Application\Task\TaskRecurrenceService;
use Pandatask\Application\Task\TaskService;
use Pandatask\Domain\Task\RecurrenceCalculator;
use Pandatask\Infrastructure\Persistence\DatabaseContext;

function pandatask_recurrence_test_fail( $message ) {
    throw new RuntimeException( (string) $message );
}

function pandatask_recurrence_test_assert( $condition, $message ) {
    if ( ! $condition ) {
        pandatask_recurrence_test_fail( $message );
    }
}

function pandatask_recurrence_test_db_check( $context ) {
    global $wpdb;

    if ( ! empty( $wpdb->last_error ) ) {
        pandatask_recurrence_test_fail( $context . ': wpdb error: ' . $wpdb->last_error );
    }
}

function pandatask_recurrence_test_db_var( $sql ) {
    global $wpdb;

    $wpdb->last_error = '';
    $result = $wpdb->get_var( $sql );
    pandatask_recurrence_test_db_check( 'get_var' );

    return $result;
}

function pandatask_recurrence_test_db_row( $sql ) {
    global $wpdb;

    $wpdb->last_error = '';
    $result = $wpdb->get_row( $sql );
    pandatask_recurrence_test_db_check( 'get_row' );

    return $result;
}

function pandatask_recurrence_test_db_results( $sql ) {
    global $wpdb;

    $wpdb->last_error = '';
    $result = $wpdb->get_results( $sql );
    pandatask_recurrence_test_db_check( 'get_results' );

    return $result;
}

function pandatask_recurrence_test_db_write( $sql, $context ) {
    global $wpdb;

    $wpdb->last_error = '';
    $result = $wpdb->query( $sql );
    pandatask_recurrence_test_db_check( $context );
    pandatask_recurrence_test_assert( false !== $result, $context . ': query returned false' );

    return $result;
}

function pandatask_recurrence_test_call( $label, callable $callable ) {
    global $wpdb;

    $wpdb->last_error = '';
    try {
        $result = $callable();
    } catch ( Throwable $exception ) {
        pandatask_recurrence_test_fail( $label . ': ' . $exception->getMessage() );
    }
    pandatask_recurrence_test_db_check( $label );

    if ( is_wp_error( $result ) ) {
        pandatask_recurrence_test_fail( $label . ': ' . $result->get_error_code() . ' ' . $result->get_error_message() );
    }

    return $result;
}

function pandatask_recurrence_test_status( WP_Error $error ) {
    $data = $error->get_error_data();

    return is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;
}

function pandatask_recurrence_test_expected_conflict( $label, $result ) {
    global $wpdb;

    pandatask_recurrence_test_db_check( $label );
    pandatask_recurrence_test_assert( is_wp_error( $result ), $label . ': expected WP_Error' );
    pandatask_recurrence_test_assert( 409 === pandatask_recurrence_test_status( $result ), $label . ': expected HTTP 409, got ' . pandatask_recurrence_test_status( $result ) );
}

function pandatask_recurrence_test_date( $base, $days ) {
    return ( new DateTimeImmutable( $base ) )->modify( ( $days >= 0 ? '+' : '' ) . (int) $days . ' days' )->format( 'Y-m-d' );
}

try {
    global $wpdb;

    pandatask_recurrence_test_assert( defined( 'ABSPATH' ), 'WordPress is not bootstrapped.' );
    pandatask_recurrence_test_assert( '1' === getenv( 'PANDATASK_SYNTHETIC_RECURRENCE_TEST' ) && 'pandatask_test' === DB_NAME, 'Refusing to run outside the disposable synthetic database harness.' );
    pandatask_recurrence_test_assert( class_exists( TaskService::class ), 'Pandatask classes are not loaded.' );

    // Vanilla WordPress has no mail/external integration. This extra guard keeps
    // the synthetic fixture from ever attempting an outbound message.
    add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );

    $admin = get_user_by( 'login', 'pandatask_test_admin' );
    $ordinary = get_user_by( 'login', 'pandatask_test_user' );
    pandatask_recurrence_test_assert( $admin && $ordinary, 'Synthetic users are missing.' );

    $admin_id = (int) $admin->ID;
    $ordinary_id = (int) $ordinary->ID;
    wp_set_current_user( $admin_id );
    $board = 'user_' . $admin_id;
    $today = wp_date( 'Y-m-d' );
    $future_end = pandatask_recurrence_test_date( $today, 21 );
    $tasks_table = DatabaseContext::getDbPrefix() . 'tasks';
    $series_table = DatabaseContext::getDbPrefix() . 'task_recurrence_series';
    $history_table = DatabaseContext::getDbPrefix() . 'task_history';
    $occurrences_table = DatabaseContext::getDbPrefix() . 'task_work_occurrences';

    $phase = $args[0] ?? 'services';
    if ( 'race-create' === $phase ) {
        $id = pandatask_recurrence_test_call( 'create concurrent fixture', function () use ( $board, $today, $future_end ) {
            return ( new TaskService() )->createTask( array(
                'board_name' => $board, 'name' => 'Synthetic concurrent occurrence',
                'status' => 'pending', 'priority' => 5, 'start_date' => $today, 'deadline' => $today,
                'is_recurring' => 1, 'recurrence_frequency' => 'weekly',
                'recurrence_interval' => 1, 'recurrence_ends_on' => $future_end,
            ) );
        } );
        $task = pandatask_recurrence_test_db_row( $wpdb->prepare( "SELECT * FROM {$tasks_table} WHERE id = %d", $id ) );
        update_option( 'pandatask_test_recurrence_race', array( 'task' => $task, 'release_at' => microtime( true ) + 4 ), false );
        echo "PASS concurrent fixture created\n";
        return;
    }
    if ( 'race-worker' === $phase ) {
        $race = get_option( 'pandatask_test_recurrence_race' );
        while ( microtime( true ) < $race['release_at'] ) {
            usleep( 10000 );
        }
        pandatask_recurrence_test_call( 'concurrent advance', function () use ( $race, $admin_id ) {
            return ( new TaskRecurrenceService() )->advance( (int) $race['task']->id, $admin_id, true );
        } );
        echo "PASS concurrent worker\n";
        return;
    }
    if ( 'race-verify' === $phase ) {
        $old = get_option( 'pandatask_test_recurrence_race' )['task'];
        $rows = pandatask_recurrence_test_db_results( $wpdb->prepare( "SELECT * FROM {$tasks_table} WHERE recurrence_series_id = %d ORDER BY recurrence_sequence", $old->recurrence_series_id ) );
        pandatask_recurrence_test_assert( 2 === count( $rows ), 'Concurrent advance did not create exactly one successor.' );
        pandatask_recurrence_test_assert( serialize( $old ) === serialize( $rows[0] ), 'Concurrent advance mutated the original task.' );
        $cursor = pandatask_recurrence_test_db_var( $wpdb->prepare( "SELECT current_task_id FROM {$series_table} WHERE id = %d", $old->recurrence_series_id ) );
        pandatask_recurrence_test_assert( (int) $rows[1]->id === (int) $cursor && 2 === (int) $rows[1]->recurrence_sequence, 'Concurrent successor cursor/sequence is wrong.' );
        pandatask_recurrence_test_assert( 1 === (int) pandatask_recurrence_test_db_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$occurrences_table} WHERE task_id = %d", $cursor ) ), 'Concurrent successor has duplicate work occurrences.' );
        echo "PASS two independent database connections create exactly one successor\n";
        return;
    }
    if ( in_array( $phase, array( 'upgrade-prepare', 'upgrade-verify' ), true ) ) {
        $snapshot = array(
            'tasks' => pandatask_recurrence_test_db_results( "SELECT id, name, description, status, start_date, deadline, completed_at, archived, current_work_occurrence_id FROM {$tasks_table} ORDER BY id" ),
            'work' => pandatask_recurrence_test_db_results( "SELECT * FROM {$occurrences_table} ORDER BY id" ),
        );
        if ( 'upgrade-prepare' === $phase ) {
            update_option( 'pandatask_test_upgrade_snapshot', $snapshot, false );
            pandatask_recurrence_test_db_write( "DROP TABLE {$series_table}", 'remove new series schema from synthetic database' );
            pandatask_recurrence_test_db_write( "ALTER TABLE {$tasks_table} DROP INDEX recurrence_series_sequence, DROP INDEX recurrence_series_id, DROP COLUMN recurrence_series_id, DROP COLUMN recurrence_sequence, DROP COLUMN recurrence_scheduled_start, DROP COLUMN checklist_json, DROP COLUMN checklist_version", 'restore synthetic pre-feature schema' );
            pandatask_recurrence_test_db_write( "ALTER TABLE {$history_table} MODIFY old_value TEXT, MODIFY new_value TEXT", 'restore pre-feature history column types' );
            update_option( 'pandat69_db_version', '1.0.21' );
            echo "PASS synthetic database restored to pre-feature schema 1.0.21\n";
        } else {
            pandatask_recurrence_test_assert( '1.0.23' === get_option( 'pandat69_db_version' ), 'Fresh bootstrap did not finish the schema upgrade.' );
            pandatask_recurrence_test_assert( serialize( get_option( 'pandatask_test_upgrade_snapshot' ) ) === serialize( $snapshot ), 'Schema upgrade changed original task fields or work history.' );
            pandatask_recurrence_test_assert( 0 === (int) pandatask_recurrence_test_db_var( "SELECT COUNT(*) FROM {$tasks_table} WHERE is_recurring = 1 AND recurrence_series_id IS NULL" ), 'Upgrade left legacy tasks unlinked.' );
            echo "PASS fresh bootstrap upgrades 1.0.21 to 1.0.23 and preserves all original task IDs and work history\n";
        }
        return;
    }

    $table_name = pandatask_recurrence_test_db_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $series_table ) );
    pandatask_recurrence_test_assert( $series_table === $table_name, 'Fresh activation did not create the recurrence series table.' );
    $unique_index = pandatask_recurrence_test_db_row( "SHOW INDEX FROM {$tasks_table} WHERE Key_name = 'recurrence_series_sequence'" );
    pandatask_recurrence_test_assert( $unique_index && 0 === (int) $unique_index->Non_unique, 'The recurrence series/sequence index is not unique.' );
    echo "PASS schema and unique recurrence index\n";

    $task_service = new TaskService();
    $checklist_service = new TaskChecklistService();
    $recurrence_service = new TaskRecurrenceService();

    $main_id = (int) pandatask_recurrence_test_call( 'create recurring task', function () use ( $task_service, $board, $ordinary_id, $today, $future_end ) {
        return $task_service->createTask(
            array(
                'board_name' => $board,
                'name' => 'Synthetic recurring integration task',
                'description' => 'Disposable integration fixture.',
                'status' => 'pending',
                'priority' => 5,
                'estimated_effort_seconds' => 3600,
                'start_date' => $today,
                'deadline' => $today,
                'is_recurring' => 1,
                'recurrence_frequency' => 'weekly',
                'recurrence_interval' => 1,
                'recurrence_ends_on' => $future_end,
                'assigned_persons' => array( $ordinary_id ),
                'supervisor_persons' => array(),
                'predecessors' => array(),
            )
        );
    } );
    pandatask_recurrence_test_assert( $main_id > 0, 'Recurring task creation returned no ID.' );
    $main_before = pandatask_recurrence_test_db_row( $wpdb->prepare( "SELECT * FROM {$tasks_table} WHERE id = %d", $main_id ) );
    $series_before = pandatask_recurrence_test_db_row( $wpdb->prepare( "SELECT * FROM {$series_table} WHERE id = %d", (int) $main_before->recurrence_series_id ) );
    pandatask_recurrence_test_assert( $main_before && $series_before, 'Recurring task was not attached to a series.' );
    $original_work_id = (int) $main_before->current_work_occurrence_id;
    pandatask_recurrence_test_assert( 1 === (int) pandatask_recurrence_test_db_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$occurrences_table} WHERE task_id = %d", $main_id ) ), 'Initial task did not get one work occurrence.' );

    $checklist = array(
        array( 'id' => 'ship', 'text' => 'Ship the synthetic task', 'checked' => true ),
        array( 'id' => 'verify', 'text' => 'Verify the successor', 'checked' => false ),
    );
    $this_checklist = pandatask_recurrence_test_call( 'write current checklist', function () use ( $checklist_service, $main_id, $checklist, $admin_id ) {
        return $checklist_service->updateChecklist( $main_id, $checklist, 0, $admin_id, 'this' );
    } );
    pandatask_recurrence_test_assert( 1 === (int) $this_checklist['checklist_version'], 'Current checklist version did not advance to one.' );
    $future_series_version = (int) pandatask_recurrence_test_db_var( $wpdb->prepare( "SELECT version FROM {$series_table} WHERE id = %d", (int) $series_before->id ) );
    $future_checklist = pandatask_recurrence_test_call( 'write future checklist defaults', function () use ( $checklist_service, $main_id, $checklist, $admin_id, $future_series_version ) {
        return $checklist_service->updateChecklist( $main_id, $checklist, 1, $admin_id, 'future', $future_series_version );
    } );
    pandatask_recurrence_test_assert( 1 === (int) $future_checklist['checklist_version'], 'Future checklist write unexpectedly changed current checklist version.' );
    $template = json_decode( (string) pandatask_recurrence_test_db_var( $wpdb->prepare( "SELECT template_json FROM {$series_table} WHERE id = %d", (int) $series_before->id ) ), true );
    pandatask_recurrence_test_assert( is_array( $template ) && false === $template['checklist'][0]['checked'] && false === $template['checklist'][1]['checked'], 'Future defaults were not saved as unchecked.' );
    echo "PASS real TaskService checklist and explicit future series version\n";

    $complete_result = pandatask_recurrence_test_call( 'complete recurring task', function () use ( $task_service, $main_id, $admin_id ) {
        return $task_service->completeTask( $main_id, array( 'skip_personal_resolution' => true ), 'Synthetic completion', $admin_id );
    } );
    pandatask_recurrence_test_assert( true === $complete_result, 'Completion did not return true.' );
    $main_after = pandatask_recurrence_test_db_row( $wpdb->prepare( "SELECT * FROM {$tasks_table} WHERE id = %d", $main_id ) );
    $series_after_complete = pandatask_recurrence_test_db_row( $wpdb->prepare( "SELECT * FROM {$series_table} WHERE id = %d", (int) $main_before->recurrence_series_id ) );
    $successor_id = (int) $series_after_complete->current_task_id;
    $successor = pandatask_recurrence_test_db_row( $wpdb->prepare( "SELECT * FROM {$tasks_table} WHERE id = %d", $successor_id ) );
    $successor_checklist = json_decode( (string) $successor->checklist_json, true );
    pandatask_recurrence_test_assert( $main_id !== $successor_id, 'Completion did not create a distinct successor.' );
    pandatask_recurrence_test_assert( 'done' === $main_after->status && $today === $main_after->start_date, 'Completed occurrence lost its status or start date.' );
    pandatask_recurrence_test_assert( $original_work_id === (int) $main_after->current_work_occurrence_id, 'Completion changed the old task work occurrence.' );
    $main_checklist_after = json_decode( (string) $main_after->checklist_json, true );
    pandatask_recurrence_test_assert( true === $main_checklist_after[0]['checked'], 'Completed occurrence checklist was not preserved as checked.' );
    pandatask_recurrence_test_assert( (int) $successor->recurrence_series_id === (int) $main_before->recurrence_series_id && 2 === (int) $successor->recurrence_sequence, 'Successor series/sequence linkage is wrong.' );
    pandatask_recurrence_test_assert( is_array( $successor_checklist ) && false === $successor_checklist[0]['checked'] && false === $successor_checklist[1]['checked'], 'Successor checklist did not reset to unchecked defaults.' );
    pandatask_recurrence_test_assert( 1 === (int) pandatask_recurrence_test_db_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$occurrences_table} WHERE task_id = %d", $successor_id ) ), 'Successor did not get exactly one work occurrence.' );
    echo "PASS completion preserves old occurrence and creates one unchecked successor\n";

    $task_count_before_retry = (int) pandatask_recurrence_test_db_var( "SELECT COUNT(*) FROM {$tasks_table}" );
    $retry = pandatask_recurrence_test_call( 'repeat advance of old task', function () use ( $recurrence_service, $main_id, $admin_id ) {
        return $recurrence_service->advance( $main_id, $admin_id );
    } );
    pandatask_recurrence_test_assert( null === $retry || 0 === $retry || false === $retry, 'Repeated advance unexpectedly created a successor.' );
    pandatask_recurrence_test_assert( $task_count_before_retry === (int) pandatask_recurrence_test_db_var( "SELECT COUNT(*) FROM {$tasks_table}" ), 'Repeated advance changed task count.' );
    $cursor_before_reopen = (int) pandatask_recurrence_test_db_var( $wpdb->prepare( "SELECT current_task_id FROM {$series_table} WHERE id = %d", (int) $main_before->recurrence_series_id ) );
    pandatask_recurrence_test_call( 'reopen old occurrence', function () use ( $task_service, $main_id, $admin_id ) {
        return $task_service->reopenTask( $main_id, 'pending', 'Synthetic correction', $admin_id );
    } );
    pandatask_recurrence_test_assert( $cursor_before_reopen === (int) pandatask_recurrence_test_db_var( $wpdb->prepare( "SELECT current_task_id FROM {$series_table} WHERE id = %d", (int) $main_before->recurrence_series_id ) ), 'Reopening an old occurrence moved the latest series cursor.' );
    pandatask_recurrence_test_assert( 'pending' === pandatask_recurrence_test_db_var( $wpdb->prepare( "SELECT status FROM {$tasks_table} WHERE id = %d", $main_id ) ), 'Reopen did not use the real service lifecycle.' );
    echo "PASS retry idempotence and old-occurrence reopen cursor safety\n";

    $current_series_version = (int) pandatask_recurrence_test_db_var( $wpdb->prepare( "SELECT version FROM {$series_table} WHERE id = %d", (int) $main_before->recurrence_series_id ) );
    $stale_future = $task_service->updateTask(
        $successor_id,
        array( 'name' => 'Stale future edit', 'recurrence_scope' => 'future', 'expected_series_version' => max( 0, $current_series_version - 1 ) ),
        '',
        $admin_id
    );
    pandatask_recurrence_test_expected_conflict( 'stale future edit', $stale_future );
    $old_future = $task_service->updateTask(
        $main_id,
        array( 'name' => 'Old occurrence future edit', 'recurrence_scope' => 'future', 'expected_series_version' => $current_series_version ),
        '',
        $admin_id
    );
    pandatask_recurrence_test_expected_conflict( 'old occurrence future edit', $old_future );
    echo "PASS stale and old-cursor future edits return 409\n";

    $skip_result = pandatask_recurrence_test_call( 'skip latest occurrence', function () use ( $task_service, $successor_id ) {
        return $task_service->deleteTask( $successor_id, 'this' );
    } );
    pandatask_recurrence_test_assert( true === $skip_result, 'Skipping latest occurrence did not return true.' );
    $skipped = pandatask_recurrence_test_db_row( $wpdb->prepare( "SELECT * FROM {$tasks_table} WHERE id = %d", $successor_id ) );
    $series_after_skip = pandatask_recurrence_test_db_row( $wpdb->prepare( "SELECT * FROM {$series_table} WHERE id = %d", (int) $main_before->recurrence_series_id ) );
    $after_skip_id = (int) $series_after_skip->current_task_id;
    pandatask_recurrence_test_assert( 1 === (int) $skipped->archived && $after_skip_id !== $successor_id, 'Skipping did not archive the old row and advance.' );
    $count_before_stop = (int) pandatask_recurrence_test_db_var( "SELECT COUNT(*) FROM {$tasks_table}" );
    pandatask_recurrence_test_call( 'stop following occurrences', function () use ( $task_service, $after_skip_id ) {
        return $task_service->deleteTask( $after_skip_id, 'following' );
    } );
    $series_after_stop = pandatask_recurrence_test_db_row( $wpdb->prepare( "SELECT * FROM {$series_table} WHERE id = %d", (int) $main_before->recurrence_series_id ) );
    pandatask_recurrence_test_assert( 0 === (int) $series_after_stop->active && $after_skip_id === (int) $series_after_stop->current_task_id, 'Stopping following occurrences did not deactivate the series.' );
    pandatask_recurrence_test_assert( $count_before_stop === (int) pandatask_recurrence_test_db_var( "SELECT COUNT(*) FROM {$tasks_table}" ), 'Stopping following occurrences created a task.' );
    $after_stop_advance = pandatask_recurrence_test_call( 'advance stopped series', function () use ( $recurrence_service, $after_skip_id, $admin_id ) {
        return $recurrence_service->advance( $after_skip_id, $admin_id, true );
    } );
    pandatask_recurrence_test_assert( null === $after_stop_advance || 0 === $after_stop_advance || false === $after_stop_advance, 'Stopped series advanced unexpectedly.' );
    echo "PASS skip/archive and stop-following behavior\n";

    $legacy_id = (int) pandatask_recurrence_test_call( 'create migration fixture', function () use ( $task_service, $board, $ordinary_id, $today, $future_end ) {
        return $task_service->createTask(
            array(
                'board_name' => $board,
                'name' => 'Synthetic legacy recurring task',
                'description' => 'Legacy migration fixture.',
                'status' => 'pending',
                'priority' => 4,
                'start_date' => $today,
                'deadline' => $today,
                'is_recurring' => 1,
                'recurrence_frequency' => 'weekly',
                'recurrence_interval' => 1,
                'recurrence_ends_on' => $future_end,
                'assigned_persons' => array( $ordinary_id ),
                'supervisor_persons' => array(),
                'predecessors' => array(),
            )
        );
    } );
    $legacy_before = pandatask_recurrence_test_db_row( $wpdb->prepare( "SELECT * FROM {$tasks_table} WHERE id = %d", $legacy_id ) );
    $legacy_old_series_id = (int) $legacy_before->recurrence_series_id;
    pandatask_recurrence_test_db_write( $wpdb->prepare( "UPDATE {$tasks_table} SET recurrence_series_id = NULL, recurrence_sequence = NULL, recurrence_scheduled_start = NULL WHERE id = %d", $legacy_id ), 'unlink migration fixture' );
    pandatask_recurrence_test_db_write( $wpdb->prepare( "DELETE FROM {$series_table} WHERE id = %d", $legacy_old_series_id ), 'remove migration fixture series' );
    $legacy_raw = pandatask_recurrence_test_db_row( $wpdb->prepare( "SELECT * FROM {$tasks_table} WHERE id = %d", $legacy_id ) );
    $legacy_work_before = pandatask_recurrence_test_db_results( $wpdb->prepare( "SELECT * FROM {$occurrences_table} WHERE task_id = %d ORDER BY id", $legacy_id ) );
    $legacy_history_before = (int) pandatask_recurrence_test_db_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$history_table} WHERE task_id = %d", $legacy_id ) );
    $task_count_before_migration = (int) pandatask_recurrence_test_db_var( "SELECT COUNT(*) FROM {$tasks_table}" );
    $work_count_before_migration = (int) pandatask_recurrence_test_db_var( "SELECT COUNT(*) FROM {$occurrences_table}" );

    pandatask_recurrence_test_assert( true === pandatask_recurrence_test_call( 'migrate legacy recurring task', function () use ( $recurrence_service ) {
        return $recurrence_service->migrateLegacyTasks();
    } ), 'First legacy migration did not return true.' );
    $migrated = pandatask_recurrence_test_db_row( $wpdb->prepare( "SELECT * FROM {$tasks_table} WHERE id = %d", $legacy_id ) );
    $migrated_series = pandatask_recurrence_test_db_row( $wpdb->prepare( "SELECT * FROM {$series_table} WHERE id = %d", (int) $migrated->recurrence_series_id ) );
    pandatask_recurrence_test_assert( $migrated_series && (int) $migrated->id === $legacy_id && (int) $migrated->recurrence_sequence === 1, 'Migration did not create exactly one new series link.' );
    foreach ( array( 'id', 'board_name', 'name', 'description', 'status', 'start_date', 'deadline', 'is_recurring', 'recurrence_frequency', 'recurrence_interval', 'recurrence_ends_on', 'recurrence_anchor_day', 'checklist_json', 'checklist_version', 'current_work_occurrence_id' ) as $field ) {
        pandatask_recurrence_test_assert( (string) $legacy_raw->$field === (string) $migrated->$field, 'Migration changed legacy field ' . $field . '.' );
    }
    $legacy_work_after = pandatask_recurrence_test_db_results( $wpdb->prepare( "SELECT * FROM {$occurrences_table} WHERE task_id = %d ORDER BY id", $legacy_id ) );
    pandatask_recurrence_test_assert( serialize( $legacy_work_before ) === serialize( $legacy_work_after ), 'Migration changed legacy work history.' );
    pandatask_recurrence_test_assert( $task_count_before_migration === (int) pandatask_recurrence_test_db_var( "SELECT COUNT(*) FROM {$tasks_table}" ), 'Migration invented a task.' );
    pandatask_recurrence_test_assert( $work_count_before_migration === (int) pandatask_recurrence_test_db_var( "SELECT COUNT(*) FROM {$occurrences_table}" ), 'Migration invented or removed a work occurrence.' );
    $legacy_history_after_first = (int) pandatask_recurrence_test_db_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$history_table} WHERE task_id = %d", $legacy_id ) );
    pandatask_recurrence_test_assert( $legacy_history_before + 1 === $legacy_history_after_first, 'Migration did not add exactly one migration history entry.' );
    $migration_entry = pandatask_recurrence_test_db_row( $wpdb->prepare( "SELECT * FROM {$history_table} WHERE task_id = %d ORDER BY id DESC LIMIT 1", $legacy_id ) );
    pandatask_recurrence_test_assert( 'recurrence_series_created' === $migration_entry->field_changed, 'Migration history entry has the wrong field.' );

    pandatask_recurrence_test_assert( true === pandatask_recurrence_test_call( 'repeat legacy migration', function () use ( $recurrence_service ) {
        return $recurrence_service->migrateLegacyTasks();
    } ), 'Second legacy migration did not return true.' );
    $legacy_history_after_second = (int) pandatask_recurrence_test_db_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$history_table} WHERE task_id = %d", $legacy_id ) );
    pandatask_recurrence_test_assert( $legacy_history_after_first === $legacy_history_after_second, 'Repeated migration added another history entry.' );
    pandatask_recurrence_test_assert( (int) pandatask_recurrence_test_db_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tasks_table} WHERE id = %d AND recurrence_series_id IS NOT NULL", $legacy_id ) ) === 1, 'Repeated migration did not remain idempotently linked.' );
    echo "PASS idempotent legacy migration preserves task IDs, fields, and work history\n";

    $boundary_id = (int) pandatask_recurrence_test_call( 'create end-date boundary task', function () use ( $task_service, $board, $ordinary_id, $today ) {
        return $task_service->createTask(
            array(
                'board_name' => $board,
                'name' => 'Synthetic recurrence end boundary',
                'description' => '',
                'status' => 'pending',
                'priority' => 5,
                'start_date' => $today,
                'deadline' => $today,
                'is_recurring' => 1,
                'recurrence_frequency' => 'weekly',
                'recurrence_interval' => 1,
                'recurrence_ends_on' => $today,
                'assigned_persons' => array( $ordinary_id ),
                'supervisor_persons' => array(),
                'predecessors' => array(),
            )
        );
    } );
    $boundary_task = pandatask_recurrence_test_db_row( $wpdb->prepare( "SELECT recurrence_series_id FROM {$tasks_table} WHERE id = %d", $boundary_id ) );
    $boundary_series = pandatask_recurrence_test_db_row( $wpdb->prepare( "SELECT * FROM {$series_table} WHERE id = %d", (int) $boundary_task->recurrence_series_id ) );
    pandatask_recurrence_test_assert( $boundary_series && null === $boundary_series->next_start_date && 0 === (int) $boundary_series->active, 'End-date boundary did not disable the series with no next date.' );
    $clamped = ( new RecurrenceCalculator() )->next( '2026-01-31', 'monthly', 1, null, 31 );
    pandatask_recurrence_test_assert( '2026-02-28' === $clamped, 'Monthly day-31 recurrence did not clamp to February 28.' );
    echo "PASS end-date boundary and monthly day-31 clamp\n";

    pandatask_recurrence_test_db_check( 'final' );
    echo "RECURRENCE INTEGRATION PASS\n";
} catch ( Throwable $exception ) {
    fwrite( STDERR, 'RECURRENCE INTEGRATION FAIL: ' . $exception->getMessage() . "\n" );
    exit( 1 );
}
