<?php

/**
 * Focused checklist domain and service behavior harness.
 *
 * Run with: php tests/task-checklist.php
 */

namespace Pandatask\Infrastructure\Persistence {
    final class DatabaseContext {
        public static $events = array();
        public static $begin_result = true;
        public static $commit_result = true;

        public static function beginTransaction() {
            self::$events[] = 'begin';
            return self::$begin_result;
        }

        public static function commit() {
            self::$events[] = 'commit';
            return self::$commit_result;
        }

        public static function rollback() {
            self::$events[] = 'rollback';
            return true;
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

    if ( ! function_exists( 'sanitize_text_field' ) ) {
        function sanitize_text_field( $value ) {
            return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $value ) ) );
        }
    }

    if ( ! function_exists( 'get_current_user_id' ) ) {
        function get_current_user_id() {
            return 7;
        }
    }

    if ( ! class_exists( 'WP_Error' ) ) {
        class WP_Error {
            private $code;
            private $message;
            private $data;

            public function __construct( $code, $message, $data = array() ) {
                $this->code = $code;
                $this->message = $message;
                $this->data = $data;
            }

            public function get_error_code() { return $this->code; }
            public function get_error_message() { return $this->message; }
            public function get_error_data() { return $this->data; }
        }
    }

    if ( ! function_exists( 'is_wp_error' ) ) {
        function is_wp_error( $value ) { return $value instanceof WP_Error; }
    }

    if ( ! function_exists( 'wp_generate_uuid4' ) ) {
        function wp_generate_uuid4() { return '123e4567-e89b-12d3-a456-426614174000'; }
    }

    if ( ! function_exists( 'wp_json_encode' ) ) {
        function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
    }

    require_once dirname( __DIR__ ) . '/src/Domain/Task/TaskChecklist.php';
    require_once dirname( __DIR__ ) . '/src/Infrastructure/Persistence/TaskChecklistRepository.php';
    require_once dirname( __DIR__ ) . '/src/Application/Task/TaskChecklistService.php';

    use Pandatask\Application\Task\TaskChecklistService;
    use Pandatask\Domain\Task\TaskChecklist;
    use Pandatask\Infrastructure\Persistence\DatabaseContext;

    $failures = array();
    $assert = static function ( $condition, $message ) use ( &$failures ) {
        if ( ! $condition ) {
            $failures[] = $message;
        }
    };

    $normalized = TaskChecklist::normalize(
        array(
            array( 'text' => '  <b>Ship it</b>  ', 'checked' => true ),
        )
    );
    $assert( ! is_wp_error( $normalized ), 'A valid checklist should normalize.' );
    $assert( 'Ship it' === $normalized[0]['text'] && true === $normalized[0]['checked'], 'Checklist text should be plain, trimmed text.' );
    $assert( 36 === strlen( $normalized[0]['id'] ), 'Missing item IDs should be generated as UUIDs.' );

    $invalid_cases = array(
        array( array( array( 'text' => 'x', 'checked' => 'true' ) ), 'strict checked type' ),
        array( array( array( 'text' => 'x', 'checked' => false, 'extra' => 1 ) ), 'unknown item field' ),
        array( array( array( 'id' => 'same', 'text' => 'x', 'checked' => false ), array( 'id' => 'same', 'text' => 'y', 'checked' => true ) ), 'duplicate IDs' ),
        array( array( array( 'id' => 'bad id', 'text' => 'x', 'checked' => false ) ), 'invalid ID' ),
        array( array( array( 'text' => str_repeat( 'x', 501 ), 'checked' => false ) ), 'sanitized text limit' ),
        array( array( array( 'text' => str_repeat( 'x', 2001 ), 'checked' => false ) ), 'raw text limit' ),
    );

    foreach ( $invalid_cases as $case ) {
        $assert( is_wp_error( TaskChecklist::normalize( $case[0] ) ), 'Invalid checklist case should reject: ' . $case[1] . '.' );
    }

    $assert( ! is_wp_error( TaskChecklist::normalize( array( array( 'text' => str_repeat( 'ą', 500 ), 'checked' => false ) ) ) ), '500 UTF-8 characters should be accepted.' );
    $assert( is_wp_error( TaskChecklist::normalize( array( array( 'text' => str_repeat( 'ą', 501 ), 'checked' => false ) ) ) ), '501 UTF-8 characters should reject.' );
    $assert( ! is_wp_error( TaskChecklist::normalize( array( array( 'text' => str_repeat( '😀', 500 ), 'checked' => false ) ) ) ), '500 astral Unicode characters should be accepted.' );
    $assert( is_wp_error( TaskChecklist::normalize( array( array( 'text' => str_repeat( '😀', 501 ), 'checked' => false ) ) ) ), '501 astral Unicode characters should reject.' );

    $too_many = array_fill( 0, 101, array( 'id' => 'item', 'text' => 'x', 'checked' => false ) );
    $assert( is_wp_error( TaskChecklist::normalize( $too_many ) ), 'More than 100 checklist items should reject.' );
    $assert( array() === TaskChecklist::decode( null ), 'Legacy null checklist should decode as empty.' );

    try {
        TaskChecklist::decode( '{"id":"broken"}' );
        $failures[] = 'A non-array stored JSON value should throw.';
    } catch ( \Throwable $exception ) {
        // Expected.
    }

    $fields = TaskChecklist::fields(
        (object) array(
            'checklist_json' => '[{"id":"a","text":"one","checked":true},{"id":"b","text":"two","checked":false}]',
            'checklist_version' => '4',
        )
    );
    $assert( 2 === $fields['checklist_total'] && 1 === $fields['checklist_checked'] && 4 === $fields['checklist_version'], 'Checklist summary fields should be accurate.' );

    final class ChecklistHarnessRepository {
        public $task;
        public $write_result = true;
        public $writes = array();
        public $lock_count = 0;

        public function lockTask( $task_id ) {
            unset( $task_id );
            $this->lock_count++;
            return $this->task;
        }

        public function write( $task_id, $json, $version ) {
            $this->writes[] = array( $task_id, $json, $version );
            if ( $this->write_result ) {
                $this->task->checklist_json = $json;
                $this->task->checklist_version = $version;
            }
            return $this->write_result;
        }

        public function findParticipantUserIdsForTask( $task_id ) {
            unset( $task_id );
            return array( 7, 8 );
        }
    }

    final class ChecklistHarnessTaskService {
        public $task;
        public function getTask( $task_id ) {
            unset( $task_id );
            return $this->task;
        }
    }

    final class ChecklistHarnessPolicy {
        public $read = true;
        public $write = true;
        public $calls = array();

        public function canReadTask( $task_id, $user_id ) {
            $this->calls[] = array( 'read', $task_id, $user_id );
            return $this->read;
        }

        public function canUpdateTask( $task_id, $user_id ) {
            $this->calls[] = array( 'write', $task_id, $user_id );
            return $this->write;
        }
    }

    final class ChecklistHarnessHistory {
        public $result = true;
        public $entries = array();

        public function addEntry( $task_id, $user_id, $field, $old, $new, $comment ) {
            $this->entries[] = array( $task_id, $user_id, $field, $old, $new, $comment );
            return $this->result;
        }
    }

    final class ChecklistHarnessCache {
        public $calls = array();
        public function invalidateTask( $task_id, $board_name, array $user_ids = array() ) {
            $this->calls[] = array( $task_id, $board_name, $user_ids );
        }
    }

    $repository = new ChecklistHarnessRepository();
    $repository->task = (object) array(
        'id' => 42,
        'board_name' => 'board',
        'status' => 'pending',
        'checklist_json' => '[{"id":"a","text":"one","checked":false}]',
        'checklist_version' => 2,
    );
    $task_service = new ChecklistHarnessTaskService();
    $task_service->task = $repository->task;
    $policy = new ChecklistHarnessPolicy();
    $policy->write = false;
    $history = new ChecklistHarnessHistory();
    $cache = new ChecklistHarnessCache();
    $service = new TaskChecklistService( $repository, $task_service, $history, $policy, $cache );

    $read = $service->getChecklist( 42, 7 );
    $assert( 1 === $read['checklist_total'] && false === $read['can_edit_checklist'], 'Checklist read should expose summary and editability.' );
    $policy->write = true;
    $updated = $service->updateChecklist(
        42,
        array(
            array( 'id' => 'a', 'text' => 'one', 'checked' => true ),
            array( 'id' => 'b', 'text' => 'two', 'checked' => false ),
        ),
        2,
        7
    );
    $assert( ! is_wp_error( $updated ) && 3 === $updated['checklist_version'] && 1 === $updated['checklist_checked'], 'Checklist update should round-trip ordering and checked state.' );
    $assert( 'checklist_updated' === $history->entries[0][2] && 1 === count( $cache->calls ), 'Successful updates should record history and invalidate caches.' );
    $assert( 'pending' === $repository->task->status, 'Checklist updates must not mutate task status.' );

    $same = $service->updateChecklist( 42, $updated['checklist'], 3, 7 );
    $assert( ! is_wp_error( $same ) && 3 === $same['checklist_version'] && 1 === count( $history->entries ), 'Identical checklist updates should not bump version or history.' );

    $conflict = $service->updateChecklist( 42, $updated['checklist'], 2, 7 );
    $assert( is_wp_error( $conflict ) && 'pandatask_checklist_conflict' === $conflict->get_error_code(), 'Stale checklist versions should conflict.' );
    $assert( 409 === $conflict->get_error_data()['status'], 'Checklist conflicts should return HTTP 409.' );

    $policy->write = false;
    $forbidden = $service->updateChecklist( 42, $updated['checklist'], 3, 7 );
    $assert( is_wp_error( $forbidden ) && 403 === $forbidden->get_error_data()['status'], 'Checklist writes should recheck update permission under lock.' );
    $policy->write = true;

    $repository->write_result = false;
    DatabaseContext::$events = array();
    $database_failure = $service->updateChecklist( 42, array(), 3, 7 );
    $assert( is_wp_error( $database_failure ) && in_array( 'rollback', DatabaseContext::$events, true ), 'Database write failure should roll back.' );
    $repository->write_result = true;

    $history->result = false;
    DatabaseContext::$events = array();
    $history_failure = $service->updateChecklist( 42, array(), 3, 7 );
    $assert( is_wp_error( $history_failure ) && in_array( 'rollback', DatabaseContext::$events, true ), 'History failure should roll back the checklist write.' );
    $history->result = true;

    if ( ! empty( $failures ) ) {
        fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
        exit( 1 );
    }

    echo "Task checklist tests passed.\n";
}
