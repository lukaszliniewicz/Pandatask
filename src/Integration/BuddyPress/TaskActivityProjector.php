<?php

namespace Pandatask\Integration\BuddyPress;

use Pandatask\Infrastructure\Notifications\TaskBoardUrlResolver;

final class TaskActivityProjector {

    private const ACTIVITY_TYPE = 'pandatask_task';
    private const CREATED_META = 'pandatask_created_at';
    private const LAST_EVENT_META = 'pandatask_last_event_at';

    public function register() {
        add_action( 'bp_register_activity_actions', array( $this, 'registerActivityAction' ) );
        add_action( 'pandatask_task_created', array( $this, 'onTaskCreated' ), 10, 3 );
        add_action( 'pandatask_task_changed', array( $this, 'onTaskChanged' ), 10, 6 );
        add_action( 'pandatask_task_deleted', array( $this, 'onTaskDeleted' ), 10, 3 );
    }

    public function registerActivityAction() {
        if ( function_exists( 'bp_activity_set_action' ) ) {
            bp_activity_set_action(
                'groups',
                self::ACTIVITY_TYPE,
                __( 'Task activity', 'pandatask' )
            );
        }
    }

    public function onTaskCreated( $task_id, $task, $actor_id ) {
        if ( ! $task ) {
            return;
        }

        $this->project( $task, null, array(), (int) $actor_id, 'created', true );
    }

    public function onTaskChanged( $task_id, $before, $after, $changes, $actor_id, $comment ) {
        if ( ! $after ) {
            return;
        }

        $promote = $this->shouldPromote( $after, $before, (array) $changes );
        $this->project( $after, $before, (array) $changes, (int) $actor_id, 'changed', $promote );
    }

    public function onTaskDeleted( $task_id, $task, $actor_id ) {
        $activity_id = $this->findActivityId( (int) $task_id );
        if ( $activity_id > 0 && function_exists( 'bp_activity_delete' ) ) {
            bp_activity_delete( array( 'id' => $activity_id ) );
            do_action(
                'pandatask_task_activity_projected',
                $activity_id,
                $task,
                'deleted',
                false,
                (int) $actor_id,
                $this->groupIdFromBoard( (string) ( $task->board_name ?? '' ) )
            );
        }
    }

    private function project( $task, $before, array $changes, $actor_id, $event, $promote ) {
        if ( ! BuddyPressSupport::isGroupsActive() || ! function_exists( 'bp_activity_add' ) ) {
            return;
        }

        $task_id = (int) ( $task->id ?? 0 );
        if ( $task_id <= 0 ) {
            return;
        }

        $activity_id = $this->findActivityId( $task_id );
        $group_id = $this->groupIdFromBoard( (string) ( $task->board_name ?? '' ) );
        $enabled = $group_id > 0
            && BuddyPressSupport::groupFeatureEnabled( $group_id, 'pandat69_tasks_enabled' );
        $enabled = (bool) apply_filters( 'pandatask_task_activity_enabled', $enabled, $task, $group_id );

        if ( ! $enabled ) {
            if ( $activity_id > 0 && function_exists( 'bp_activity_delete' ) ) {
                bp_activity_delete( array( 'id' => $activity_id ) );
                $previous_group_id = $before
                    ? $this->groupIdFromBoard( (string) ( $before->board_name ?? '' ) )
                    : 0;
                do_action(
                    'pandatask_task_activity_projected',
                    $activity_id,
                    $task,
                    'removed',
                    false,
                    (int) $actor_id,
                    $previous_group_id
                );
            }
            return;
        }

        $link = TaskBoardUrlResolver::resolve( (string) $task->board_name, $task_id );
        $action = $this->buildAction( $task, $group_id, $link );
        $content = $this->buildContent( $task, $link );
        $now = gmdate( 'Y-m-d H:i:s' );

        if ( $activity_id <= 0 ) {
            $activity_id = (int) bp_activity_add(
                array(
                    'user_id'           => $actor_id > 0 ? $actor_id : (int) ( $task->creator_id ?? 0 ),
                    'action'            => $action,
                    'content'           => $content,
                    'component'         => 'groups',
                    'type'              => self::ACTIVITY_TYPE,
                    'item_id'           => $group_id,
                    'secondary_item_id' => $task_id,
                    'hide_sitewide'     => $this->hideSitewide( $task, $group_id ),
                    'date_recorded'     => $promote
                        ? $now
                        : ( ! empty( $task->created_at ) ? (string) $task->created_at : $now ),
                )
            );

            if ( $activity_id <= 0 ) {
                return;
            }

            if ( function_exists( 'bp_activity_update_meta' ) ) {
                bp_activity_update_meta(
                    $activity_id,
                    self::CREATED_META,
                    ! empty( $task->created_at ) ? (string) $task->created_at : $now
                );
                bp_activity_update_meta( $activity_id, self::LAST_EVENT_META, $now );
            }

            do_action(
                'pandatask_task_activity_projected',
                $activity_id,
                $task,
                'created',
                true,
                (int) $actor_id,
                $group_id
            );
            return;
        }

        if ( ! class_exists( 'BP_Activity_Activity' ) ) {
            return;
        }

        $activity = new \BP_Activity_Activity( $activity_id );
        if ( empty( $activity->id ) ) {
            return;
        }

        if ( function_exists( 'bp_activity_get_meta' ) && function_exists( 'bp_activity_update_meta' ) ) {
            $original_date = bp_activity_get_meta( $activity_id, self::CREATED_META, true );
            if ( ! $original_date ) {
                bp_activity_update_meta(
                    $activity_id,
                    self::CREATED_META,
                    ! empty( $task->created_at ) ? (string) $task->created_at : (string) $activity->date_recorded
                );
            }
            bp_activity_update_meta( $activity_id, self::LAST_EVENT_META, $now );
        }

        $previous_group_id = (int) $activity->item_id;
        $activity->action = $action;
        $activity->content = $content;
        $activity->component = 'groups';
        $activity->type = self::ACTIVITY_TYPE;
        $activity->item_id = $group_id;
        $activity->secondary_item_id = $task_id;
        $activity->hide_sitewide = $this->hideSitewide( $task, $group_id );
        if ( $promote ) {
            $activity->date_recorded = $now;
            if ( $actor_id > 0 ) {
                $activity->user_id = $actor_id;
            }
        }
        $activity->save();

        $this->clearActivityCaches( $activity, $previous_group_id );
        do_action(
            'pandatask_task_activity_projected',
            $activity_id,
            $task,
            $event,
            (bool) $promote,
            (int) $actor_id,
            $group_id
        );
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
            'pandatask_task_activity_should_promote',
            $promote,
            $task,
            $before,
            $changes
        );
    }

    private function groupIdFromBoard( $board_name ) {
        if ( preg_match( '/^group_(\d+)$/', $board_name, $matches ) ) {
            return (int) $matches[1];
        }
        return 0;
    }

    private function findActivityId( $task_id ) {
        if ( $task_id <= 0 || ! function_exists( 'buddypress' ) ) {
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
                   AND secondary_item_id = %d
                 ORDER BY id ASC
                 LIMIT 1",
                self::ACTIVITY_TYPE,
                $task_id
            )
        );
    }

    private function hideSitewide( $task, $group_id ) {
        return (bool) apply_filters(
            'pandatask_task_activity_hide_sitewide',
            true,
            $task,
            (int) $group_id
        );
    }

    private function buildAction( $task, $group_id, $link ) {
        $group_name = __( 'group', 'pandatask' );
        $group_url = '';
        if ( function_exists( 'groups_get_group' ) ) {
            $group = groups_get_group( $group_id );
            if ( $group ) {
                $group_name = (string) ( $group->name ?? $group_name );
                $group_url = BuddyPressSupport::groupUrl( $group );
            }
        }

        $task_name = (string) ( $task->name ?? __( 'Task', 'pandatask' ) );
        $task_html = $link
            ? '<a href="' . esc_url( $link ) . '">' . esc_html( $task_name ) . '</a>'
            : esc_html( $task_name );
        $group_html = $group_url
            ? '<a href="' . esc_url( $group_url ) . '">' . esc_html( $group_name ) . '</a>'
            : esc_html( $group_name );

        return sprintf(
            /* translators: 1: task link/title, 2: group link/name. */
            __( 'Task %1$s in %2$s', 'pandatask' ),
            $task_html,
            $group_html
        );
    }

    private function buildContent( $task, $link ) {
        $status = $this->statusLabel( (string) ( $task->status ?? 'pending' ) );
        $project = trim( (string) ( $task->project_name ?? '' ) );
        $deadline = trim( (string) ( $task->deadline ?? '' ) );
        $created = trim( (string) ( $task->created_at ?? '' ) );
        $completed = trim( (string) ( $task->completed_at ?? '' ) );

        $meta = array_filter(
            array(
                $project ? sprintf( __( 'Project: %s', 'pandatask' ), $project ) : '',
                $deadline ? sprintf( __( 'Due: %s', 'pandatask' ), $deadline ) : '',
                $created ? sprintf( __( 'Created: %s UTC', 'pandatask' ), $created ) : '',
                $completed ? sprintf( __( 'Completed: %s UTC', 'pandatask' ), $completed ) : '',
            )
        );

        $html = '<div class="pandatask-activity-card" data-pandatask-task-id="' . esc_attr( (int) $task->id ) . '">';
        $html .= '<p><strong>' . esc_html( $status ) . '</strong></p>';
        if ( ! empty( $meta ) ) {
            $html .= '<p>' . esc_html( implode( ' · ', $meta ) ) . '</p>';
        }
        if ( $link ) {
            $html .= '<p><a href="' . esc_url( $link ) . '">' . esc_html__( 'View task', 'pandatask' ) . '</a></p>';
        }
        $html .= '</div>';

        return $html;
    }

    private function statusLabel( $status ) {
        switch ( $status ) {
            case 'in-progress':
                return __( 'In progress', 'pandatask' );
            case 'done':
                return __( 'Completed', 'pandatask' );
            default:
                return __( 'Pending', 'pandatask' );
        }
    }

    private function clearActivityCaches( $activity, $previous_group_id = 0 ) {
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
        if ( $previous_group_id > 0 && $previous_group_id !== (int) $activity->item_id ) {
            wp_cache_delete( 'bp_activity_group_' . $previous_group_id, 'bp_activity' );
        }

        if ( function_exists( 'bp_activity_reset_cache_incrementor' ) ) {
            bp_activity_reset_cache_incrementor();
        } elseif ( function_exists( 'bp_core_reset_incrementor' ) ) {
            bp_core_reset_incrementor( 'bp_activity' );
        }
    }
}
