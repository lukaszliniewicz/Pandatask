<?php

$activity_store = array();
$activity_meta = array();
$next_activity_id = 1;
$actions = array();
$transients = array();

function __( $text, $domain = null ) { return $text; }
function esc_html__( $text, $domain = null ) { return $text; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $url ) { return (string) $url; }
function add_action() {}
function do_action( $hook, ...$args ) { global $actions; $actions[] = array( $hook, $args ); }
function apply_filters( $hook, $value ) { return $value; }
function function_exists_for_test() { return true; }
function wp_cache_delete() { return true; }
function bp_activity_reset_cache_incrementor() {}
function bp_is_active( $component ) { return 'groups' === $component; }
function buddypress() { return (object) array( 'activity' => (object) array( 'table_name' => 'wp_bp_activity' ) ); }
function groups_get_groupmeta( $group_id, $key, $single = true ) { return '1'; }
function groups_get_group( $group_id ) { return (object) array( 'id' => (int) $group_id, 'slug' => 'group-' . (int) $group_id, 'name' => 'Group ' . (int) $group_id, 'status' => 'public' ); }
function bp_get_group_url( $group ) { return 'https://example.test/groups/' . $group->slug . '/'; }
function bp_get_group_permalink( $group ) { return 'https://legacy.example.test/groups/' . $group->slug . '/'; }
function get_transient( $key ) { global $transients; return array_key_exists( $key, $transients ) ? $transients[ $key ] : false; }
function set_transient( $key, $value, $ttl ) { global $transients; $transients[ $key ] = $value; return true; }
function add_query_arg( $key, $value, $url ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . rawurlencode( $key ) . '=' . rawurlencode( $value ); }
function trailingslashit( $value ) { return rtrim( $value, '/' ) . '/'; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function bp_activity_set_action() { return true; }
function bp_activity_update_meta( $activity_id, $key, $value ) { global $activity_meta; $activity_meta[ $activity_id ][ $key ] = $value; return true; }
function bp_activity_get_meta( $activity_id, $key, $single = true ) { global $activity_meta; return $activity_meta[ $activity_id ][ $key ] ?? ''; }
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
            $query = preg_replace( '/%[sd]/', is_numeric( $arg ) ? (string) (int) $arg : "'" . addslashes( (string) $arg ) . "'", $query, 1 );
        }
        return $query;
    }
    public function get_var( $query ) {
        global $activity_store;
        if ( preg_match( '/secondary_item_id = (\d+)/', $query, $match ) ) {
            $task_id = (int) $match[1];
            foreach ( $activity_store as $activity ) {
                if ( 'groups' === $activity->component && 'pandatask_task' === $activity->type && (int) $activity->secondary_item_id === $task_id ) {
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

use Pandatask\Integration\BuddyPress\TaskActivityProjector;

function assert_projection( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, $message . "\n" );
        exit( 1 );
    }
}

$projector = new TaskActivityProjector();
$task = (object) array(
    'id' => 243,
    'board_name' => 'group_10',
    'name' => 'Task lifecycle card',
    'status' => 'pending',
    'project_name' => 'Software Suite Development',
    'deadline' => null,
    'created_at' => '2026-08-19 04:00:00',
    'completed_at' => null,
    'creator_id' => 8,
);

$projector->onTaskCreated( 243, $task, 8 );
assert_projection( 1 === count( $activity_store ), 'creation did not create exactly one activity' );
$activity_id = array_key_first( $activity_store );
assert_projection( 243 === (int) $activity_store[ $activity_id ]->secondary_item_id, 'task ID is not the activity secondary item' );
assert_projection( 10 === (int) $activity_store[ $activity_id ]->item_id, 'group ID is not the activity item ID' );
assert_projection( true === (bool) $activity_store[ $activity_id ]->hide_sitewide, 'group task activity leaked into the sitewide stream by default' );
assert_projection( '2026-08-19 04:00:00' === $activity_meta[ $activity_id ]['pandatask_created_at'], 'original creation timestamp was not preserved' );

$activity_store[ $activity_id ]->date_recorded = '2026-08-19 04:10:00';
$minor_before = clone $task;
$task->name = 'Renamed lifecycle card';
$projector->onTaskChanged( 243, $minor_before, $task, array( array( 'field' => 'name', 'from' => 'Task lifecycle card', 'to' => $task->name ) ), 8, '' );
assert_projection( 1 === count( $activity_store ), 'minor edit duplicated the activity' );
assert_projection( '2026-08-19 04:10:00' === $activity_store[ $activity_id ]->date_recorded, 'minor edit unexpectedly promoted the activity' );
assert_projection( false !== strpos( $activity_store[ $activity_id ]->action, 'Renamed lifecycle card' ), 'minor edit did not refresh activity content/action' );

$complete_before = clone $task;
$task->status = 'done';
$task->completed_at = '2026-08-19 04:20:00';
$projector->onTaskChanged( 243, $complete_before, $task, array( array( 'field' => 'status', 'from' => 'pending', 'to' => 'done' ) ), 9, '' );
assert_projection( 1 === count( $activity_store ), 'completion duplicated the activity' );
assert_projection( '2026-08-19 04:10:00' !== $activity_store[ $activity_id ]->date_recorded, 'completion did not promote the activity' );
assert_projection( 9 === (int) $activity_store[ $activity_id ]->user_id, 'promoted activity did not reflect the meaningful-event actor' );
assert_projection( false !== strpos( $activity_store[ $activity_id ]->content, 'Completed' ), 'fallback content did not reflect completion' );

$move_before = clone $task;
$task->board_name = 'group_12';
$projector->onTaskChanged( 243, $move_before, $task, array( array( 'field' => 'board_name', 'from' => 'group_10', 'to' => 'group_12' ) ), 8, '' );
assert_projection( 1 === count( $activity_store ), 'group move duplicated the activity' );
assert_projection( $activity_id === array_key_first( $activity_store ), 'group move changed the activity ID' );
assert_projection( 12 === (int) $activity_store[ $activity_id ]->item_id, 'group move did not move the projection' );

$leave_before = clone $task;
$task->board_name = 'user_8';
$projector->onTaskChanged( 243, $leave_before, $task, array( array( 'field' => 'board_name', 'from' => 'group_12', 'to' => 'user_8' ) ), 8, '' );
assert_projection( 0 === count( $activity_store ), 'moving out of a group did not remove the group activity projection' );

$legacy_before = (object) array(
    'id' => 244,
    'board_name' => 'group_10',
    'name' => 'Legacy task without projection',
    'status' => 'pending',
    'project_name' => 'Software Suite Development',
    'deadline' => null,
    'created_at' => '2026-08-01 08:00:00',
    'completed_at' => null,
    'creator_id' => 8,
);
$legacy_after = clone $legacy_before;
$legacy_after->status = 'in-progress';
$projector->onTaskChanged(
    244,
    $legacy_before,
    $legacy_after,
    array( array( 'field' => 'status', 'from' => 'pending', 'to' => 'in-progress' ) ),
    8,
    ''
);
assert_projection( 1 === count( $activity_store ), 'meaningful update did not create a missing legacy task projection' );
$legacy_activity_id = array_key_first( $activity_store );
assert_projection( '2026-08-01 08:00:00' === $activity_meta[ $legacy_activity_id ]['pandatask_created_at'], 'legacy task creation timestamp was not preserved' );
assert_projection( '2026-08-01 08:00:00' !== $activity_store[ $legacy_activity_id ]->date_recorded, 'meaningful first projection was not promoted to current feed order' );
assert_projection( true === (bool) $activity_store[ $legacy_activity_id ]->hide_sitewide, 'legacy task projection leaked into the sitewide stream by default' );

fwrite( STDOUT, "Task activity projector behavior test passed.\n" );
