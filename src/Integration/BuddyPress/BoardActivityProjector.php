<?php

namespace Pandatask\Integration\BuddyPress;

use Pandatask\Infrastructure\Notifications\TaskBoardUrlResolver;
use Pandatask\Infrastructure\Persistence\BoardEventRepository;
use Pandatask\Infrastructure\Persistence\DatabaseContext;

final class BoardActivityProjector {

    private const ACTIVITY_TYPE = 'pandatask_board_activity';
    private const LEGACY_ACTIVITY_TYPE = 'pandatask_task';
    private const CREATED_META = 'pandatask_board_created_at';
    private const LAST_EVENT_META = 'pandatask_board_last_event_at';
    private const MIGRATION_OPTION = 'pandat69_board_activity_migration_v1';

    private $event_repository;

    public function __construct( $event_repository = null ) {
        $this->event_repository = $event_repository ?: new BoardEventRepository();
    }

    public function register() {
        add_action( 'bp_register_activity_actions', array( $this, 'registerActivityAction' ) );
        add_action( 'pandatask_task_created', array( $this, 'onTaskCreated' ), 10, 3 );
        add_action( 'pandatask_task_changed', array( $this, 'onTaskChanged' ), 10, 6 );
        add_action( 'pandatask_task_deleted', array( $this, 'onTaskDeleted' ), 10, 3 );
        add_action( 'pandatask_group_task_settings_updated', array( $this, 'onGroupSettingsUpdated' ), 10, 2 );
        add_action( 'bp_init', array( $this, 'migrateLegacyActivities' ), 30 );
    }

    public function registerActivityAction() {
        if ( function_exists( 'bp_activity_set_action' ) ) {
            bp_activity_set_action(
                'groups',
                self::ACTIVITY_TYPE,
                __( 'Task board activity', 'pandatask' )
            );
        }
    }

    public function onTaskCreated( $task_id, $task, $actor_id ) {
        if ( ! $task ) {
            return;
        }

        $board_name = (string) ( $task->board_name ?? '' );
        if ( $this->groupIdFromBoard( $board_name ) < 1 ) {
            return;
        }

        $this->recordAndProject(
            $board_name,
            $task,
            (int) $actor_id,
            'task_created',
            true,
            array()
        );
    }

    public function onTaskChanged( $task_id, $before, $after, $changes, $actor_id, $comment ) {
        if ( ! $after ) {
            return;
        }

        $changes = (array) $changes;
        $actor_id = (int) $actor_id;
        $before_board = (string) ( $before->board_name ?? '' );
        $after_board = (string) ( $after->board_name ?? '' );
        $before_group_id = $this->groupIdFromBoard( $before_board );
        $after_group_id = $this->groupIdFromBoard( $after_board );
        $event_data = array(
            'changes' => $this->normalizeChanges( $changes ),
        );
        if ( '' !== trim( (string) $comment ) ) {
            $event_data['comment'] = sanitize_text_field( (string) $comment );
        }

        if ( $before_board !== $after_board ) {
            $event_data['from_board'] = $before_board;
            $event_data['to_board'] = $after_board;

            if ( $before_group_id > 0 ) {
                $this->recordAndProject(
                    $before_board,
                    $after,
                    $actor_id,
                    'task_moved_out',
                    true,
                    $event_data
                );
            }

            if ( $after_group_id > 0 ) {
                $this->recordAndProject(
                    $after_board,
                    $after,
                    $actor_id,
                    'task_moved_in',
                    true,
                    $event_data
                );
            }
            return;
        }

        if ( $after_group_id < 1 || empty( $changes ) ) {
            return;
        }

        $event_type = $this->eventTypeForChange( $before, $after, $changes );
        $promote = $this->shouldPromote( $after, $before, $changes );
        $this->recordAndProject( $after_board, $after, $actor_id, $event_type, $promote, $event_data );
    }

    public function onTaskDeleted( $task_id, $task, $actor_id ) {
        if ( ! $task ) {
            return;
        }

        $board_name = (string) ( $task->board_name ?? '' );
        if ( $this->groupIdFromBoard( $board_name ) < 1 ) {
            return;
        }

        $this->recordAndProject(
            $board_name,
            $task,
            (int) $actor_id,
            'task_deleted',
            true,
            array()
        );
    }

    public function onGroupSettingsUpdated( $group_id, $settings ) {
        $group_id = (int) $group_id;
        if ( $group_id < 1 ) {
            return;
        }

        if ( ! $this->activityEnabled( $group_id ) ) {
            $activity_id = $this->findActivityId( $group_id );
            $this->deleteBoardActivity( $group_id );
            if ( $activity_id > 0 ) {
                do_action(
                    'pandatask_board_activity_projected',
                    $activity_id,
                    'group_' . $group_id,
                    $group_id,
                    'settings_updated',
                    false,
                    get_current_user_id(),
                    0
                );
            }
            return;
        }

        $events = $this->event_repository->getBoardEvents( 'group_' . $group_id, 1 );
        $recorded_at = ! empty( $events[0]->created_at ) ? (string) $events[0]->created_at : gmdate( 'Y-m-d H:i:s' );
        $this->projectBoard( 'group_' . $group_id, 0, false, 'settings_updated', 0, $recorded_at );
    }

    public function migrateLegacyActivities() {
        if ( '1' === (string) get_option( self::MIGRATION_OPTION, '0' ) ) {
            return;
        }
        if ( ! BuddyPressSupport::isGroupsActive() || ! function_exists( 'buddypress' ) || ! function_exists( 'bp_activity_delete' ) ) {
            return;
        }

        global $wpdb;
        $bp = buddypress();
        if ( empty( $bp->activity->table_name ) ) {
            return;
        }

        $legacy = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, user_id, item_id, secondary_item_id, date_recorded
                 FROM {$bp->activity->table_name}
                 WHERE component = 'groups' AND type = %s
                 ORDER BY date_recorded ASC, id ASC",
                self::LEGACY_ACTIVITY_TYPE
            )
        );

        $latest_by_board = array();
        $tasks_table = DatabaseContext::getDbPrefix() . 'tasks';

        foreach ( (array) $legacy as $activity ) {
            $group_id = (int) $activity->item_id;
            $task_id = (int) $activity->secondary_item_id;
            $task = $task_id > 0
                ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tasks_table} WHERE id = %d", $task_id ) )
                : null;

            if ( $group_id > 0 && $task && ! $this->event_repository->hasSourceActivity( (int) $activity->id ) ) {
                $event_type = 'task_created';
                if ( 'done' === (string) ( $task->status ?? '' ) ) {
                    $event_type = 'task_completed';
                } elseif ( 'in-progress' === (string) ( $task->status ?? '' ) ) {
                    $event_type = 'task_started';
                }
                $this->event_repository->addEvent(
                    'group_' . $group_id,
                    $task,
                    (int) $activity->user_id,
                    $event_type,
                    true,
                    array( 'migrated_from' => self::LEGACY_ACTIVITY_TYPE ),
                    (string) $activity->date_recorded,
                    (int) $activity->id
                );
            }

            if ( $group_id > 0 ) {
                $board_name = 'group_' . $group_id;
                if ( ! isset( $latest_by_board[ $board_name ] ) || $latest_by_board[ $board_name ]['date'] < (string) $activity->date_recorded ) {
                    $latest_by_board[ $board_name ] = array(
                        'date'     => (string) $activity->date_recorded,
                        'actor_id' => (int) $activity->user_id,
                    );
                }
            }

            bp_activity_delete( array( 'id' => (int) $activity->id ) );
        }

        foreach ( $latest_by_board as $board_name => $latest ) {
            $this->projectBoard(
                $board_name,
                (int) $latest['actor_id'],
                false,
                'migrated',
                0,
                (string) $latest['date']
            );
        }

        update_option( self::MIGRATION_OPTION, '1', false );
    }

    private function recordAndProject( $board_name, $task, $actor_id, $event_type, $promote, array $event_data ) {
        $event_id = $this->event_repository->addEvent(
            $board_name,
            $task,
            $actor_id,
            $event_type,
            $promote,
            $event_data
        );

        if ( ! $event_id ) {
            return 0;
        }

        return $this->projectBoard(
            $board_name,
            $actor_id,
            $promote,
            $event_type,
            (int) ( $task->id ?? 0 ),
            gmdate( 'Y-m-d H:i:s' )
        );
    }

    private function projectBoard( $board_name, $actor_id, $promote, $event_type, $task_id, $recorded_at = null ) {
        if ( ! BuddyPressSupport::isGroupsActive() || ! function_exists( 'bp_activity_add' ) ) {
            return 0;
        }

        $group_id = $this->groupIdFromBoard( $board_name );
        if ( $group_id < 1 ) {
            return 0;
        }

        if ( ! $this->activityEnabled( $group_id ) ) {
            $this->deleteBoardActivity( $group_id );
            return 0;
        }

        $activity_id = $this->findActivityId( $group_id );
        $summary = $this->event_repository->getBoardSummary( $board_name );
        $events = $this->event_repository->getBoardEvents( $board_name, $this->previewCount( $group_id ) );
        $board_url = TaskBoardUrlResolver::resolve( $board_name );
        $action = $this->buildAction( $group_id, $board_url );
        $content = $this->buildContent( $board_name, $summary, $events, $board_url );
        $now = gmdate( 'Y-m-d H:i:s' );
        $recorded_at = $recorded_at ?: $now;

        if ( $activity_id <= 0 ) {
            $activity_id = (int) bp_activity_add(
                array(
                    'user_id'           => $actor_id > 0 ? $actor_id : get_current_user_id(),
                    'action'            => $action,
                    'content'           => $content,
                    'component'         => 'groups',
                    'type'              => self::ACTIVITY_TYPE,
                    'item_id'           => $group_id,
                    'secondary_item_id' => 0,
                    'hide_sitewide'     => $this->hideSitewide( $board_name, $group_id ),
                    'date_recorded'     => $promote ? $now : $recorded_at,
                )
            );

            if ( $activity_id <= 0 ) {
                return 0;
            }

            if ( function_exists( 'bp_activity_update_meta' ) ) {
                bp_activity_update_meta( $activity_id, self::CREATED_META, $recorded_at );
                bp_activity_update_meta( $activity_id, self::LAST_EVENT_META, $now );
            }
        } else {
            if ( ! class_exists( 'BP_Activity_Activity' ) ) {
                return 0;
            }

            $activity = new \BP_Activity_Activity( $activity_id );
            if ( empty( $activity->id ) ) {
                return 0;
            }

            $activity->action = $action;
            $activity->content = $content;
            $activity->component = 'groups';
            $activity->type = self::ACTIVITY_TYPE;
            $activity->item_id = $group_id;
            $activity->secondary_item_id = 0;
            $activity->hide_sitewide = $this->hideSitewide( $board_name, $group_id );
            if ( $promote ) {
                $activity->date_recorded = $now;
                if ( $actor_id > 0 ) {
                    $activity->user_id = $actor_id;
                }
            }
            $activity->save();
            $this->clearActivityCaches( $activity );

            if ( function_exists( 'bp_activity_update_meta' ) ) {
                bp_activity_update_meta( $activity_id, self::LAST_EVENT_META, $now );
            }
        }

        do_action(
            'pandatask_board_activity_projected',
            $activity_id,
            $board_name,
            $group_id,
            (string) $event_type,
            (bool) $promote,
            (int) $actor_id,
            (int) $task_id
        );

        return $activity_id;
    }

    private function eventTypeForChange( $before, $after, array $changes ) {
        $old_status = (string) ( $before->status ?? '' );
        $new_status = (string) ( $after->status ?? '' );

        if ( $old_status !== $new_status ) {
            if ( 'done' === $new_status ) {
                return 'task_completed';
            }
            if ( 'done' === $old_status && 'done' !== $new_status ) {
                return 'task_reopened';
            }
            if ( 'in-progress' === $new_status ) {
                return 'task_started';
            }
        }

        return 'task_updated';
    }

    private function shouldPromote( $task, $before, array $changes ) {
        $promoting_fields = array(
            'status',
            'assignee_added',
            'assignee_removed',
            'supervisor_added',
            'supervisor_removed',
            'deadline',
            'deadline_days_after_start',
            'board_name',
            'project_id',
        );

        $promote = false;
        foreach ( $changes as $change ) {
            $field = is_array( $change ) ? (string) ( $change['field'] ?? '' ) : '';
            if ( in_array( $field, $promoting_fields, true ) ) {
                $promote = true;
                break;
            }
        }

        return (bool) apply_filters(
            'pandatask_board_activity_should_promote',
            $promote,
            $task,
            $before,
            $changes
        );
    }

    private function normalizeChanges( array $changes ) {
        $normalized = array();
        foreach ( $changes as $change ) {
            if ( ! is_array( $change ) ) {
                continue;
            }
            $field = sanitize_key( (string) ( $change['field'] ?? '' ) );
            if ( '' === $field ) {
                continue;
            }
            $normalized[] = array(
                'field' => $field,
                'from'  => $this->boundedEventValue( $change['from'] ?? '' ),
                'to'    => $this->boundedEventValue( $change['to'] ?? '' ),
            );
        }
        return $normalized;
    }

    private function boundedEventValue( $value ) {
        if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
            return $value;
        }
        if ( is_array( $value ) ) {
            return array_slice( array_map( 'strval', $value ), 0, 20 );
        }
        return mb_substr( wp_strip_all_tags( (string) $value ), 0, 500 );
    }

    private function activityEnabled( $group_id ) {
        $enabled = BuddyPressSupport::groupFeatureEnabled( $group_id, 'pandat69_tasks_enabled' )
            && BuddyPressSupport::groupFeatureEnabled( $group_id, 'pandat69_task_activity_enabled' );

        return (bool) apply_filters( 'pandatask_board_activity_enabled', $enabled, (int) $group_id );
    }

    private function previewCount( $group_id ) {
        $count = function_exists( 'groups_get_groupmeta' )
            ? (int) groups_get_groupmeta( $group_id, 'pandat69_task_activity_preview_count', true )
            : 0;
        if ( ! in_array( $count, array( 3, 5, 8 ), true ) ) {
            $count = 3;
        }
        return (int) apply_filters( 'pandatask_board_activity_preview_count', $count, (int) $group_id );
    }

    private function groupIdFromBoard( $board_name ) {
        if ( preg_match( '/^group_(\d+)$/', (string) $board_name, $matches ) ) {
            return (int) $matches[1];
        }
        return 0;
    }

    private function findActivityId( $group_id ) {
        if ( $group_id <= 0 || ! function_exists( 'buddypress' ) ) {
            return 0;
        }

        $bp = buddypress();
        if ( empty( $bp->activity->table_name ) ) {
            return 0;
        }

        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$bp->activity->table_name}
                 WHERE component = 'groups'
                   AND type = %s
                   AND item_id = %d
                 ORDER BY id ASC
                 LIMIT 1",
                self::ACTIVITY_TYPE,
                $group_id
            )
        );
    }

    private function deleteBoardActivity( $group_id ) {
        $activity_id = $this->findActivityId( (int) $group_id );
        if ( $activity_id > 0 && function_exists( 'bp_activity_delete' ) ) {
            return (bool) bp_activity_delete( array( 'id' => $activity_id ) );
        }
        return true;
    }

    private function hideSitewide( $board_name, $group_id ) {
        return (bool) apply_filters(
            'pandatask_board_activity_hide_sitewide',
            true,
            (string) $board_name,
            (int) $group_id
        );
    }

    private function buildAction( $group_id, $board_url ) {
        $group_name = __( 'group', 'pandatask' );
        $group_url = '';
        if ( function_exists( 'groups_get_group' ) ) {
            $group = groups_get_group( $group_id );
            if ( $group ) {
                $group_name = (string) ( $group->name ?? $group_name );
                $group_url = BuddyPressSupport::groupUrl( $group );
            }
        }

        $group_html = $group_url
            ? '<a href="' . esc_url( $group_url ) . '">' . esc_html( $group_name ) . '</a>'
            : esc_html( $group_name );

        return sprintf(
            /* translators: %s: group link/name. */
            __( 'Task board activity in %s', 'pandatask' ),
            $group_html
        );
    }

    private function buildContent( $board_name, array $summary, array $events, $board_url ) {
        $parts = array();
        $parts[] = sprintf( _n( '%d open task', '%d open tasks', (int) $summary['open'], 'pandatask' ), (int) $summary['open'] );
        if ( ! empty( $summary['in_progress'] ) ) {
            $parts[] = sprintf( _n( '%d in progress', '%d in progress', (int) $summary['in_progress'], 'pandatask' ), (int) $summary['in_progress'] );
        }
        if ( ! empty( $summary['due_today'] ) ) {
            $parts[] = sprintf( _n( '%d due today', '%d due today', (int) $summary['due_today'], 'pandatask' ), (int) $summary['due_today'] );
        }
        if ( ! empty( $summary['overdue'] ) ) {
            $parts[] = sprintf( _n( '%d overdue', '%d overdue', (int) $summary['overdue'], 'pandatask' ), (int) $summary['overdue'] );
        }

        $html = '<div class="pandatask-board-activity-card" data-pandatask-board="' . esc_attr( $board_name ) . '">';
        $html .= '<p><strong>' . esc_html__( 'Tasks', 'pandatask' ) . '</strong> · ' . esc_html( implode( ' · ', $parts ) ) . '</p>';

        if ( ! empty( $events ) ) {
            $html .= '<ul class="pandatask-board-activity-events">';
            foreach ( $events as $event ) {
                $event_text = $this->eventSummary( $event );
                $task_url = false;
                if ( ! empty( $event->task_exists ) && (string) $event->current_board_name === $board_name ) {
                    $task_url = TaskBoardUrlResolver::resolve( $board_name, (int) $event->task_id );
                }
                $html .= '<li>';
                if ( $task_url ) {
                    $html .= '<a href="' . esc_url( $task_url ) . '">' . esc_html( $event_text ) . '</a>';
                } else {
                    $html .= esc_html( $event_text );
                }
                $html .= '</li>';
            }
            $html .= '</ul>';
        }

        if ( $board_url ) {
            $html .= '<p><a href="' . esc_url( $board_url ) . '">' . esc_html__( 'Open task board', 'pandatask' ) . '</a></p>';
        }
        $html .= '</div>';

        return $html;
    }

    private function eventSummary( $event ) {
        $actor = trim( (string) ( $event->actor_name ?? '' ) );
        if ( '' === $actor ) {
            $actor = __( 'System', 'pandatask' );
        }
        $task_name = (string) ( $event->task_name ?? __( 'task', 'pandatask' ) );

        switch ( (string) ( $event->event_type ?? '' ) ) {
            case 'task_completed':
                return sprintf( __( '%1$s completed “%2$s”', 'pandatask' ), $actor, $task_name );
            case 'task_reopened':
                return sprintf( __( '%1$s reopened “%2$s”', 'pandatask' ), $actor, $task_name );
            case 'task_started':
                return sprintf( __( '%1$s started “%2$s”', 'pandatask' ), $actor, $task_name );
            case 'task_moved_in':
                return sprintf( __( '%1$s moved “%2$s” into this board', 'pandatask' ), $actor, $task_name );
            case 'task_moved_out':
                return sprintf( __( '%1$s moved “%2$s” out of this board', 'pandatask' ), $actor, $task_name );
            case 'task_deleted':
                return sprintf( __( '%1$s deleted “%2$s”', 'pandatask' ), $actor, $task_name );
            case 'task_updated':
                return sprintf( __( '%1$s updated “%2$s”', 'pandatask' ), $actor, $task_name );
            case 'task_created':
            default:
                return sprintf( __( '%1$s created “%2$s”', 'pandatask' ), $actor, $task_name );
        }
    }

    private function clearActivityCaches( $activity ) {
        if ( empty( $activity->id ) ) {
            return;
        }

        wp_cache_delete( (int) $activity->id, 'bp_activity' );
        wp_cache_delete( 'bp_activity_sitewide', 'bp_activity' );
        if ( ! empty( $activity->user_id ) ) {
            wp_cache_delete( 'bp_activity_user_' . (int) $activity->user_id, 'bp_activity' );
        }
        if ( ! empty( $activity->item_id ) ) {
            wp_cache_delete( 'bp_activity_group_' . (int) $activity->item_id, 'bp_activity' );
        }

        if ( function_exists( 'bp_activity_reset_cache_incrementor' ) ) {
            bp_activity_reset_cache_incrementor();
        } elseif ( function_exists( 'bp_core_reset_incrementor' ) ) {
            bp_core_reset_incrementor( 'bp_activity' );
        }
    }
}
