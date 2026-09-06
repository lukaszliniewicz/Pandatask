<?php
/**
 * Focused query/write contract harness for recurring task persistence.
 *
 * This deliberately uses a small wpdb double so it can run without WordPress
 * or a live database: php tests/task-recurrence-repository.php
 */

if ( ! class_exists( 'Pandatask\Infrastructure\Persistence\DatabaseContext' ) ) {
    eval(
        'namespace Pandatask\Infrastructure\Persistence; final class DatabaseContext {
            public static function getDbPrefix() { return "wp_pandat69_"; }
        }'
    );
}

final class TaskRecurrenceHarnessWpdb {
    public $insert_id = 41;
    public $last_error = '';
    public $insert_result = 1;
    public $update_result = 1;
    public $row = null;
    public $results = array();
    public $column = array();
    public $value = null;
    public $queries = array();
    public $writes = array();

    public function prepare( $query, ...$args ) {
        $index = 0;

        return preg_replace_callback(
            '/%[ds]/',
            static function ( $match ) use ( $args, &$index ) {
                $arg = $args[ $index++ ];

                return '%d' === $match[0]
                    ? (string) (int) $arg
                    : "'" . addslashes( (string) $arg ) . "'";
            },
            $query
        );
    }

    public function get_row( $query ) {
        $this->queries[] = $query;
        return $this->row;
    }

    public function get_results( $query ) {
        $this->queries[] = $query;
        return $this->results;
    }

    public function get_col( $query ) {
        $this->queries[] = $query;
        return $this->column;
    }

    public function get_var( $query ) {
        $this->queries[] = $query;
        return $this->value;
    }

    public function insert( $table, $data ) {
        $this->writes[] = array( 'method' => 'insert', 'table' => $table, 'data' => $data );
        return $this->insert_result;
    }

    public function update( $table, $data, $where, $formats = null, $where_formats = null ) {
        $this->writes[] = array(
            'method'        => 'update',
            'table'         => $table,
            'data'          => $data,
            'where'         => $where,
            'formats'       => $formats,
            'where_formats' => $where_formats,
        );
        return $this->update_result;
    }
}

require_once dirname( __DIR__ ) . '/src/Infrastructure/Persistence/TaskRecurrenceRepository.php';

global $wpdb;
$wpdb = new TaskRecurrenceHarnessWpdb();
$repository = new \Pandatask\Infrastructure\Persistence\TaskRecurrenceRepository();
$failures = array();
$assert = static function ( $condition, $message ) use ( &$failures ) {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$wpdb->row = (object) array( 'id' => 7, 'board_name' => 'board-a' );
$assert( 7 === (int) $repository->findById( 7 )->id, 'findById should return the series row.' );
$assert( false !== strpos( end( $wpdb->queries ), 'wp_pandat69_task_recurrence_series' ), 'findById should use the prefixed series table.' );

$repository->findForTask( 22 );
$assert( false !== strpos( end( $wpdb->queries ), 'INNER JOIN wp_pandat69_task_recurrence_series series' ), 'findForTask should join tasks to the series table.' );

$repository->lockSeries( 7 );
$assert( false !== strpos( end( $wpdb->queries ), 'FOR UPDATE' ), 'lockSeries should lock the selected series row.' );
$repository->lockTask( 22 );
$assert( false !== strpos( end( $wpdb->queries ), 'FROM wp_pandat69_tasks WHERE id = 22 FOR UPDATE' ), 'lockTask should lock the selected task row.' );

$wpdb->insert_id = 91;
$inserted = $repository->insertSeries(
    array(
        'board_name'   => 'board-a',
        'template_json' => '{"name":"Recurring"}',
        'active'       => 1,
    )
);
$assert( 91 === $inserted, 'insertSeries should return the generated id.' );
$assert( 'wp_pandat69_task_recurrence_series' === $wpdb->writes[0]['table'], 'insertSeries should use the prefixed series table.' );

$wpdb->update_result = 0;
$assert( true === $repository->updateSeries( 91, array( 'version' => 2 ) ), 'updateSeries should treat zero affected rows as success.' );
$wpdb->update_result = false;
$assert( false === $repository->updateSeries( 91, array( 'version' => 3 ) ), 'updateSeries should report a database failure.' );
$repository->linkTask( 22, 91, 3, '2026-09-06' );
$link_write = end( $wpdb->writes );
$assert( array( 'recurrence_series_id', 'recurrence_sequence', 'recurrence_scheduled_start' ) === array_keys( $link_write['data'] ), 'linkTask should update only the three recurrence linkage fields.' );

$wpdb->results = array(
    (object) array( 'user_id' => '8', 'role' => 'assignee' ),
    (object) array( 'user_id' => '9', 'role' => 'reviewer' ),
);
$assignments = $repository->findAssignments( 22 );
$assert( 8 === (int) $assignments[0]->user_id && 'assignee' === $assignments[0]->role, 'findAssignments should return rows with user_id and role.' );

$wpdb->column = array( '11', '12' );
$assert( array( 11, 12 ) === $repository->findPredecessorIds( 22 ), 'findPredecessorIds should return integer ids.' );
$repository->findLegacyTaskIds( 1000 );
$assert( false !== strpos( end( $wpdb->queries ), 'LIMIT 100' ), 'findLegacyTaskIds should cap the query limit at 100.' );

$wpdb->results = array( (object) array( 'id' => 7 ) );
$repository->findReadySeries( '2026-09-06', 4 );
$ready_query = end( $wpdb->queries );
$assert( false === strpos( $ready_query, 'current_task.archived = 0' ), 'findReadySeries should include archived current tasks.' );
$assert( false !== strpos( $ready_query, "current_task.status = 'done'" ), 'findReadySeries should include completed current tasks.' );
$assert( false !== strpos( $ready_query, 'LIMIT 4' ), 'findReadySeries should honor bounded limits.' );

$repository->listOccurrenceTasks( 7, 5, 9 );
$occurrence_query = end( $wpdb->queries );
$assert( false !== strpos( $occurrence_query, 'recurrence_sequence < 9' ), 'listOccurrenceTasks should apply the sequence cursor.' );
$assert( false !== strpos( $occurrence_query, 'ORDER BY task.recurrence_sequence DESC' ), 'listOccurrenceTasks should order newest sequence first.' );

$wpdb->value = '6';
$assert( 6 === $repository->findMaxSequence( 7 ), 'findMaxSequence should return the integer maximum.' );

$wpdb->last_error = 'read failed';
$thrown = false;
try {
    $repository->findById( 7 );
} catch ( \RuntimeException $exception ) {
    $thrown = 'read failed' === $exception->getMessage();
}
$assert( $thrown, 'Read query errors should throw instead of appearing as missing rows.' );

if ( ! empty( $failures ) ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

echo "Task recurrence repository tests passed.\n";
