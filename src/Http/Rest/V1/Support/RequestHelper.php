<?php

namespace Pandatask\Http\Rest\V1\Support;

use Pandatask\Application\Task\TaskDescriptionService;

final class RequestHelper {

    public static function bodyParams( $request ) {
        $json_params = $request->get_json_params();

        if ( ! empty( $json_params ) ) {
            return $json_params;
        }

        $body_params = $request->get_body_params();

        return is_array( $body_params ) ? $body_params : array();
    }

    public static function parseIdList( $input ) {
        if ( is_array( $input ) ) {
            $values = $input;
        } elseif ( is_string( $input ) && '' !== $input ) {
            $values = explode( ',', $input );
        } else {
            return array();
        }

        return array_values( array_unique( array_filter( array_map( 'absint', $values ) ) ) );
    }

    public static function renderTask( $task ) {
        if ( ! $task ) {
            return $task;
        }

        $task->description_rendered = '';

        if ( ! empty( $task->description ) ) {
            $task->description_rendered = TaskDescriptionService::render( $task->description );
        }

        return $task;
    }

    public static function renderTaskCollection( $tasks ) {
        if ( ! is_array( $tasks ) ) {
            return $tasks;
        }

        foreach ( $tasks as $task ) {
            self::renderTask( $task );
        }

        return $tasks;
    }

    /**
     * Return the fields that may be selected from a task collection response.
     *
     * Keep this list explicit: task records originate in several SQL queries and
     * must not become an arbitrary-property projection surface.
     *
     * @return array
     */
    public static function taskCollectionFields() {
        return array(
            'id',
            'board_name',
            'board_display_name',
            'frontend_url',
            'name',
            'description',
            'description_rendered',
            'status',
            'priority',
            'start_date',
            'deadline',
            'category_id',
            'category_name',
            'project_id',
            'project_name',
            'parent_task_id',
            'parent_task_name',
            'parent_task_status',
            'task_type',
            'bug_url',
            'archived',
            'created_at',
            'updated_at',
            'completed_at',
            'creator_id',
            'estimated_effort_seconds',
            'assigned_users',
            'assigned_user_ids',
            'supervisor_users',
            'supervisor_user_ids',
            'predecessors',
            'predecessor_ids',
            'is_blocked',
            'follow_up_of_task_id',
            'follow_up_of_task_name',
            'follow_up_source_restricted',
            'inbox_state',
            'capture_source',
            'capture_url',
            'attachment_type',
            'attachment_url',
            'attachment_post_id',
            'attachment_filename',
            'current_work_occurrence_id',
            'deadline_days_after_start',
            'notify_deadline',
            'notify_days_before',
            'is_recurring',
            'recurrence_frequency',
            'recurrence_interval',
            'recurrence_days',
            'recurrence_month_week',
            'recurrence_ends_on',
            'next_recurrence_date',
            'parent_recurring_task_id',
            'recurrence_anchor_day',
            'missed_deadline_notified',
            'deadline_reminder_sent_for',
            'attachment_protected',
            'attachment_public_source_retained',
        );
    }

    /**
     * Parse and validate the optional task collection projection.
     *
     * @param mixed $input Comma-separated field names or an array of names.
     * @return array|\WP_Error
     */
    public static function parseTaskFields( $input ) {
        if ( is_string( $input ) && '' === trim( $input ) ) {
            return self::invalidTaskFieldsError( __( 'The fields parameter must contain at least one task field.', 'pandatask' ) );
        }

        if ( is_array( $input ) ) {
            $values = $input;
        } elseif ( is_string( $input ) ) {
            $values = explode( ',', $input );
        } else {
            $values = array( $input );
        }

        if ( empty( $values ) ) {
            return self::invalidTaskFieldsError( __( 'The fields parameter must contain at least one task field.', 'pandatask' ) );
        }

        $allowed = self::taskCollectionFields();
        $fields  = array();

        foreach ( $values as $value ) {
            if ( ! is_scalar( $value ) ) {
                return self::invalidTaskFieldsError( __( 'The fields parameter contains an invalid task field.', 'pandatask' ) );
            }

            foreach ( explode( ',', (string) $value ) as $field ) {
                $field = trim( $field );

                // Validate before sanitizing so punctuation cannot be stripped
                // into an otherwise valid allowlisted field name.
                if ( '' === $field || ! preg_match( '/^[a-z0-9_]+$/', $field ) ) {
                    return self::invalidTaskFieldsError(
                        sprintf(
                            /* translators: %s: requested task field. */
                            __( 'Unknown or disallowed task field: %s.', 'pandatask' ),
                            (string) $field
                        )
                    );
                }

                $field = sanitize_key( $field );

                if ( '' === $field || ! in_array( $field, $allowed, true ) ) {
                    return self::invalidTaskFieldsError(
                        sprintf(
                            /* translators: %s: requested task field. */
                            __( 'Unknown or disallowed task field: %s.', 'pandatask' ),
                            (string) $field
                        )
                    );
                }

                $fields[] = $field;
            }
        }

        return array_values( array_unique( $fields ) );
    }

    /**
     * Parse a positive integer request parameter without accepting values that
     * become valid only after lossy integer coercion.
     *
     * @param mixed  $input Request value.
     * @param string $param Parameter name for the error message.
     * @return int|\WP_Error
     */
    public static function parsePositiveId( $input, $param = 'assignee_id' ) {
        $value = is_string( $input ) ? trim( $input ) : $input;

        if ( ( is_int( $value ) && $value > 0 ) || ( is_string( $value ) && preg_match( '/^[1-9][0-9]*$/', $value ) ) ) {
            return (int) $value;
        }

        return new \WP_Error(
            'rest_invalid_param',
            sprintf(
                /* translators: %s: request parameter name. */
                __( '%s must be a positive integer.', 'pandatask' ),
                sanitize_key( $param )
            ),
            array( 'status' => 400, 'param' => $param )
        );
    }

    /**
     * Project task objects after all collection filtering and pagination.
     *
     * @param array $tasks  Task objects.
     * @param array $fields Requested, validated field names.
     * @return array
     */
    public static function projectTaskCollection( $tasks, $fields ) {
        foreach ( $tasks as $index => $task ) {
            if ( is_object( $task ) ) {
                $projected = new \stdClass();

                foreach ( $fields as $field ) {
                    if ( property_exists( $task, $field ) ) {
                        $projected->{$field} = $task->{$field};
                    }
                }

                $tasks[ $index ] = $projected;
            } elseif ( is_array( $task ) ) {
                $tasks[ $index ] = array_intersect_key( $task, array_flip( $fields ) );
            }
        }

        return $tasks;
    }

    private static function invalidTaskFieldsError( $message ) {
        return new \WP_Error( 'rest_invalid_param', $message, array( 'status' => 400, 'param' => 'fields' ) );
    }

    public static function isMinimalResponse( $request ) {
        return 'minimal' === $request['response_format'];
    }
}
