<?php

$activity_store = array();
$activity_meta = array();
$next_activity_id = 1;
$actions = array();
$transients = array();
$group_meta = array();

function __( $text, $domain = null ) { return $text; }
function _n( $single, $plural, $number, $domain = null ) { return 1 === (int) $number ? $single : $plural; }
function esc_html__( $text, $domain = null ) { return $text; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $url ) { return (string) $url; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_date( $format ) { return '2026-08-19'; }
function add_action() {}
function do_action( $hook, ...$args ) { global $actions; $actions[] = array( $hook, $args ); }
function apply_filters( $hook, $value ) { return $value; }
function wp_cache_delete() { return true; }
function bp_activity_reset_cache_incrementor() {}
function bp_is_active( $component ) { return 'groups' === $component; }
function buddypress() { return (object) array( 'activity' => (object) array( 'table_name' => 'wp_bp_activity' ) ); }
function groups_get_groupmeta( $group_id, $key, $single = true ) {
    global $group_meta;
    if ( isset( $group_meta[ $group_id ] ) && array_key_exists( $key, $group_meta[ $group_id ] ) ) {
        return $group_meta[ $group_id ][ $key ];
    }
    return 'pandat69_task_activity_preview_count' === $key ? '3' : '1';
}
function groups_get_group( $group_id ) { return (object) array( 'id' => (int) $group_id, 'slug' => 'group-' . (int) $group_id, 'name' => 'Group ' . (int) $group_id, 'status' => 'public' ); }
function bp_get_group_url( $group ) { return 'https://example.test/groups/' . $group->slug . '/'; }
function get_transient( $key ) { global $transients; return array_key_exists( $key, $transients ) ? $transients[ $key ] : false; }
function set_transient( $key, $value, $ttl ) { global $transients; $transients[ $key ] = $value; return true; }
function add_query_arg( $key, $value, $url ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . rawurlencode( $key ) . '=' . rawurlencode( $value ); }
function trailingslashit( $value ) { return rtrim( $value, '/' ) . '/'; }
function bp_activity_set_action() { return true; }
function get_current_user_id() { return 8; }
function bp_activity_update_meta( $activity_id, $key, $value ) { global $activity_meta; $activity_meta[ $activity_id ][ $key ] = $value; return true; }
function bp_activity_delete( $args ) { global $activity_store; unset( $activity_store[ (int) $args['id'] ] ); return true; }
function bp_activity_add( $args ) {
    global $activity_store, $next_activity_id;
    $id = $next_activity_id++;
    $activity_store[ $id ] = (object) array_merge( array( 'id' => $id ), $args );
    return $id;
}

class BP_Activity_Activity {
    public $id = 0;
    public $user_id = 0;
    public $action = '';
    public $content = '';
    public $component = '';
    public $type = '';
    public $item_id = 0;
    public $secondary_item_id = 0;
    public $hide_sitewide = true;
    public $date_recorded = '';

    public function __construct( $id ) {
        global $activity_store;
        if ( isset( $activity_store[ $id ] ) ) {
            foreach ( get_object_vars( $activity_store[ $id ] ) as $key => $value ) {
                $this->$key = $value;
            }
        }
    }

    public function save() {
        global $activity_store;
        $activity_store[ $this->id ] = clone $this;
        return true;
    }
}

class FakeWpdb {
    public $posts = 'wp_posts';
    public function prepare( $query, ...$args ) {
        foreach ( $args as $arg ) {
            $replacement = is_numeric( $arg ) ? (string) (int) $arg : "'" . addslashes( (string) $arg ) . "'";
            $query = preg_replace( '/%[sd]/', $replacement, $query, 1 );
        }
        return $query;
    }
    public function get_var( $query ) {
        global $activity_store;
        if ( preg_match( "/type = 'pandatask_board_activity'/", $query ) && preg_match( '/item_id = (\d+)/', $query, $match ) ) {
            $group_id = (int) $match[1];
            foreach ( $activity_store as $activity ) {
                if ( 'groups' === $activity->component && 'pandatask_board_activity' === $activity->type && (int) $activity->item_id === $group_id ) {
                    return (int) $activity->id;
                }
            }
        }
        return null;
    }
}
$wpdb = new FakeWpdb();

define( 'DAY_IN_SECONDS', 86400 );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

use Pandatask\Integration\BuddyPress\BoardActivityProjector;

final class FakeBoardEventRepository {
    public $events = array();
    public $tasks = array();
    private $next_id = 1;

    public function addEvent( $board_name, $task, $actor_id, $event_type, $promote = false, array $event_data = array(), $created_at = null, $source_activity_id = null ) {
        $task_id = (int) $task->id;
        if ( 'task_moved_out' === $event_type || 'task_deleted' === $event_type ) {
            unset( $this->tasks[ $board_name ][ $task_id ] );
        } else {
            $this->tasks[ $board_name ][ $task_id ] = clone $task;
        }
        $event = (object) array(
            'id' => $this->next_id++,
            'board_name' => $board_name,
            'task_id' => $task_id,
            'actor_id' => (int) $actor_id,
            'actor_name' => 'User ' . (int) $actor_id,
            'event_type' => $event_type,
            'task_name' => (string) $task->name,
            'task_status' => (string) $task->status,
            'event_data' => $event_data,
            'promote' => (bool) $promote,
            'source_activity_id' => $source_activity_id,
            'created_at' => $created_at ?: gmdate( 'Y-m-d H:i:s' ),
            'current_board_name' => (string) $task->board_name,
            'task_exists' => 'task_deleted' !== $event_type,
        );
        $this->events[ $board_name ][] = $event;
        return $event->id;
    }

    public function getBoardEvents( $board_name, $limit = 20 ) {
        $events = array_reverse( $this->events[ $board_name ] ?? array() );
        return array_slice( $events, 0, $limit );
    }

    public function getBoardSummary( $board_name ) {
        $summary = array( 'pending' => 0, 'in_progress' => 0, 'open' => 0, 'due_today' => 0, 'overdue' => 0 );
        foreach ( $this->tasks[ $board_name ] ?? array() as $task ) {
            if ( 'done' === $task->status ) continue;
            $summary['open']++;
            if ( 'pending' === $task->status ) $summary['pending']++;
            if ( 'in-progress' === $task->status ) $summary['in_progress']++;
        }
        return $summary;
    }

    public function hasSourceActivity( $activity_id ) { return false; }
}

function assert_projection( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, $message . "\n" );
        exit( 1 );
    }
}

$events = new FakeBoardEventRepository();
$projector = new BoardActivityProjector( $events );
$task_one = (object) array(
    'id' => 243,
    'board_name' => 'group_10',
    'name' => 'Task lifecycle widget',
    'status' => 'pending',
    'priority' => 5,
    'created_at' => '2026-08-19 04:00:00',
    'creator_id' => 8,
);
$task_two = (object) array(
    'id' => 266,
    'board_name' => 'group_10',
    'name' => 'Prepare meeting agenda',
    'status' => 'pending',
    'priority' => 5,
    'created_at' => '2026-08-19 04:05:00',
    'creator_id' => 8,
);

$projector->onTaskCreated( 243, $task_one, 8 );
assert_projection( 1 === count( $activity_store ), 'first task did not create exactly one board activity' );
$group10_activity_id = array_key_first( $activity_store );
assert_projection( 10 === (int) $activity_store[ $group10_activity_id ]->item_id, 'board activity does not belong to group 10' );
assert_projection( 0 === (int) $activity_store[ $group10_activity_id ]->secondary_item_id, 'board activity unexpectedly points at one task' );
assert_projection( false !== strpos( $activity_store[ $group10_activity_id ]->content, 'pandatask-board-activity-card' ), 'portable board widget markup is missing' );

$projector->onTaskCreated( 266, $task_two, 8 );
assert_projection( 1 === count( $activity_store ), 'second task duplicated the board activity' );
assert_projection( $group10_activity_id === array_key_first( $activity_store ), 'second task changed the board activity ID' );
assert_projection( 2 === count( $events->events['group_10'] ), 'board event stream did not record both task creations' );

$activity_store[ $group10_activity_id ]->date_recorded = '2026-08-19 04:10:00';
$minor_before = clone $task_one;
$task_one->priority = 6;
$projector->onTaskChanged( 243, $minor_before, $task_one, array( array( 'field' => 'priority', 'from' => 5, 'to' => 6 ) ), 8, '' );
assert_projection( '2026-08-19 04:10:00' === $activity_store[ $group10_activity_id ]->date_recorded, 'minor edit unexpectedly promoted the board widget' );
assert_projection( 'task_updated' === end( $events->events['group_10'] )->event_type, 'minor edit was not recorded in board history' );

$complete_before = clone $task_one;
$task_one->status = 'done';
$projector->onTaskChanged( 243, $complete_before, $task_one, array( array( 'field' => 'status', 'from' => 'pending', 'to' => 'done' ) ), 9, '' );
assert_projection( '2026-08-19 04:10:00' !== $activity_store[ $group10_activity_id ]->date_recorded, 'completion did not promote the board widget' );
assert_projection( 'task_completed' === end( $events->events['group_10'] )->event_type, 'completion did not get a semantic board event' );

$move_before = clone $task_one;
$task_one->board_name = 'group_12';
$projector->onTaskChanged( 243, $move_before, $task_one, array( array( 'field' => 'board_name', 'from' => 'group_10', 'to' => 'group_12' ) ), 8, '' );
assert_projection( 2 === count( $activity_store ), 'group move did not maintain one widget in each affected group' );
assert_projection( $group10_activity_id === array_key_first( $activity_store ), 'source board widget was replaced instead of updated' );
$group12_activity = array_values( array_filter( $activity_store, static function( $activity ) { return 12 === (int) $activity->item_id; } ) );
assert_projection( 1 === count( $group12_activity ), 'destination board widget was not created exactly once' );
assert_projection( 'task_moved_out' === end( $events->events['group_10'] )->event_type, 'source board did not record move-out' );
assert_projection( 'task_moved_in' === end( $events->events['group_12'] )->event_type, 'destination board did not record move-in' );

$group_meta[10]['pandat69_task_activity_enabled'] = '0';
$projector->onGroupSettingsUpdated( 10, array() );
$remaining_group10 = array_filter( $activity_store, static function( $activity ) { return 10 === (int) $activity->item_id; } );
assert_projection( 0 === count( $remaining_group10 ), 'disabling the feed widget did not remove the BuddyPress activity' );
assert_projection( 1 === count( $activity_store ), 'disabling one group removed an unrelated board widget' );

fwrite( STDOUT, "Board activity projector behavior test passed.\n" );
