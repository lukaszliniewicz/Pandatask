<?php

namespace Pandatask\Domain\Task;

use DateTimeImmutable;
use RuntimeException;

/** The fields inherited by a new occurrence, independent of prior task state. */
final class TaskRecurrenceDefinition {
    public const TASK_FIELDS = array(
        'board_name', 'name', 'creator_id', 'description', 'estimated_effort_seconds',
        'task_type', 'bug_url', 'category_id', 'project_id', 'priority',
        'deadline_days_after_start', 'notify_deadline', 'notify_days_before',
        'parent_task_id', 'follow_up_of_task_id', 'recurrence_frequency',
        'recurrence_interval', 'recurrence_days', 'recurrence_ends_on',
        'recurrence_month_week', 'recurrence_anchor_day', 'attachment_type',
        'attachment_url', 'attachment_post_id', 'attachment_filename',
    );

    public static function capture( $task, array $assignments, array $predecessors ) {
        $definition = array();
        foreach ( self::TASK_FIELDS as $field ) {
            $definition[ $field ] = $task->$field ?? null;
        }
        $definition['checklist'] = self::unchecked( TaskChecklist::decode( $task->checklist_json ?? null ) );
        $definition['assignments'] = array_map( static function ( $assignment ) {
            return array( 'user_id' => (int) $assignment->user_id, 'role' => $assignment->role ?: 'assignee' );
        }, $assignments );
        $definition['predecessors'] = array_values( array_map( 'intval', $predecessors ) );
        if ( empty( $task->start_date ) || empty( $task->deadline ) ) {
            throw new RuntimeException( 'Recurring tasks require a start date and deadline.' );
        }
        $start = new DateTimeImmutable( (string) $task->start_date );
        $deadline = new DateTimeImmutable( (string) $task->deadline );
        $definition['deadline_offset_days'] = max( 0, (int) $start->diff( $deadline )->format( '%r%a' ) );
        return $definition;
    }

    public static function unchecked( array $items ) {
        return array_map( static function ( $item ) {
            $item['checked'] = false;
            return $item;
        }, $items );
    }

    public static function encode( array $definition ) {
        $json = wp_json_encode( $definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $json ) ) {
            throw new RuntimeException( 'The recurring task definition could not be encoded.' );
        }
        return $json;
    }

    public static function decode( $json ) {
        $definition = json_decode( (string) $json, true );
        if ( ! is_array( $definition ) || empty( $definition['board_name'] ) || empty( $definition['name'] ) || empty( $definition['recurrence_frequency'] ) ) {
            throw new RuntimeException( 'The recurring task definition is invalid.' );
        }
        $items = TaskChecklist::normalize( $definition['checklist'] ?? array() );
        if ( is_wp_error( $items ) ) {
            throw new RuntimeException( 'The recurring task checklist is invalid.' );
        }
        $definition['checklist'] = self::unchecked( $items );
        return $definition;
    }

    public static function nextDate( array $definition, $after, $not_before = null, $calculator = null ) {
        $calculator = $calculator ?: new RecurrenceCalculator();
        $args = array(
            $definition['recurrence_frequency'], $definition['recurrence_interval'],
            $definition['recurrence_days'], (int) $definition['recurrence_anchor_day'],
            $definition['recurrence_month_week'],
        );
        $next = $calculator->next( $after, ...$args );
        if ( $next && $not_before && $next < $not_before ) {
            $next = $calculator->onOrAfter( $next, $not_before, ...$args );
        }
        return $next && ( empty( $definition['recurrence_ends_on'] ) || $next <= $definition['recurrence_ends_on'] ) ? $next : null;
    }

    public static function occurrence( array $definition, $start_date, $series_id, $sequence ) {
        $task = array_intersect_key( $definition, array_flip( self::TASK_FIELDS ) );
        $task['status'] = 'pending';
        $task['archived'] = 0;
        $task['is_recurring'] = 1;
        $task['start_date'] = $start_date;
        $task['deadline'] = ( new DateTimeImmutable( $start_date ) )->modify( '+' . (int) $definition['deadline_offset_days'] . ' days' )->format( 'Y-m-d' );
        $task['completed_at'] = null;
        $task['checklist_json'] = self::encode( self::unchecked( $definition['checklist'] ) );
        $task['checklist_version'] = 0;
        $task['recurrence_series_id'] = (int) $series_id;
        $task['recurrence_sequence'] = (int) $sequence;
        $task['recurrence_scheduled_start'] = $start_date;
        $task['created_at'] = gmdate( 'Y-m-d H:i:s' );
        $task['updated_at'] = $task['created_at'];
        return $task;
    }
}
