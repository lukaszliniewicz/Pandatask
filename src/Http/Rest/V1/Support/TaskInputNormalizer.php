<?php

namespace Pandatask\Http\Rest\V1\Support;

use DateTimeImmutable;
use Pandatask\Application\Task\TaskDescriptionService;
use WP_Error;

/**
 * Converts REST/batch payloads into the application task command shape.
 */
final class TaskInputNormalizer {

    /**
     * @param string              $board_name Destination board.
     * @param array<string,mixed> $params Raw request values.
     * @return array<string,mixed>|WP_Error
     */
    public function buildCreateData( $board_name, array $params ) {
        $status = sanitize_key( $params['status'] ?? 'pending' );
        $task_type = sanitize_key( $params['task_type'] ?? 'task' );
        $name = sanitize_text_field( $params['name'] ?? '' );

        if ( '' === $name ) {
            return new WP_Error( 'rest_missing', __( 'Name is required.', 'pandatask' ), array( 'status' => 400 ) );
        }

        if ( ! in_array( $status, array( 'pending', 'in-progress', 'done' ), true ) ) {
            return new WP_Error( 'rest_invalid_param', __( 'Invalid task status.', 'pandatask' ), array( 'status' => 422 ) );
        }

        if ( ! in_array( $task_type, array( 'task', 'bug' ), true ) ) {
            return new WP_Error( 'rest_invalid_param', __( 'Invalid task type.', 'pandatask' ), array( 'status' => 422 ) );
        }

        $data = array(
            'board_name'                => sanitize_key( $board_name ),
            'name'                      => $name,
            'status'                    => $status,
            'priority'                  => max( 1, min( 10, absint( $params['priority'] ?? 5 ) ) ),
            'description'               => isset( $params['description'] ) ? TaskDescriptionService::sanitize( $params['description'] ) : '',
            'assigned_persons'          => RequestHelper::parseIdList( $params['assigned_persons'] ?? '' ),
            'supervisor_persons'        => RequestHelper::parseIdList( $params['supervisor_persons'] ?? '' ),
            'category_id'               => ! empty( $params['category_id'] ) ? absint( $params['category_id'] ) : null,
            'project_id'                => ! empty( $params['project_id'] ) ? absint( $params['project_id'] ) : null,
            'parent_task_id'            => ! empty( $params['parent_task_id'] ) ? absint( $params['parent_task_id'] ) : null,
            'deadline'                  => ! empty( $params['deadline'] ) ? $this->sanitizeDate( $params['deadline'] ) : null,
            'start_date'                => ! empty( $params['start_date'] ) ? $this->sanitizeDate( $params['start_date'] ) : null,
            'deadline_days_after_start' => ! empty( $params['deadline_days_after_start'] ) ? absint( $params['deadline_days_after_start'] ) : null,
            'notify_deadline'           => isset( $params['notify_deadline'] ) && rest_sanitize_boolean( $params['notify_deadline'] ) ? 1 : 0,
            'notify_days_before'        => max( 1, min( 30, absint( $params['notify_days_before'] ?? 3 ) ) ),
            'is_recurring'              => isset( $params['is_recurring'] ) && rest_sanitize_boolean( $params['is_recurring'] ) ? 1 : 0,
            'recurrence_frequency_val'  => sanitize_key( $params['recurrence_frequency'] ?? ( $params['recurrence_frequency_val'] ?? 'weekly' ) ),
            'recurrence_days'           => $this->sanitizeRecurrenceDays( $params['recurrence_days'] ?? '' ),
            'recurrence_ends_on'        => ! empty( $params['recurrence_ends_on'] ) ? $this->sanitizeDate( $params['recurrence_ends_on'] ) : null,
            'attachment_type'           => isset( $params['attachment_type'] ) ? sanitize_key( $params['attachment_type'] ) : '',
            'attachment_url'            => isset( $params['attachment_url'] ) ? esc_url_raw( $params['attachment_url'] ) : '',
            'attachment_post_id'        => isset( $params['attachment_post_id'] ) ? absint( $params['attachment_post_id'] ) : 0,
            'attachment_filename'       => isset( $params['attachment_filename'] ) ? sanitize_file_name( $params['attachment_filename'] ) : '',
            'task_type'                 => $task_type,
            'bug_url'                   => isset( $params['bug_url'] ) ? esc_url_raw( $params['bug_url'] ) : '',
            'predecessors'              => RequestHelper::parseIdList( $params['predecessors'] ?? array() ),
        );

        foreach ( array( 'deadline', 'start_date', 'recurrence_ends_on' ) as $date_field ) {
            if ( is_wp_error( $data[ $date_field ] ) ) {
                return $data[ $date_field ];
            }
        }

        if ( empty( $data['assigned_persons'] ) && ! empty( $params['default_assignee_id'] ) ) {
            $data['assigned_persons'] = array( absint( $params['default_assignee_id'] ) );
        }

        $data = $this->normalizeRecurrenceData( $data );

        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $attachment_validation = $this->validateAttachmentInput( $data );

        return is_wp_error( $attachment_validation ) ? $attachment_validation : $data;
    }

    /**
     * @param array<string,mixed> $params Raw patch values.
     * @param object|null         $current_task Current task for attachment validation.
     * @return array<string,mixed>|WP_Error
     */
    public function buildUpdateData( array $params, $current_task = null ) {
        $data = array();

        if ( array_key_exists( 'board_name', $params ) ) {
            $data['board_name'] = sanitize_key( $params['board_name'] );
        }

        if ( array_key_exists( 'name', $params ) ) {
            $data['name'] = sanitize_text_field( $params['name'] );

            if ( '' === $data['name'] ) {
                return new WP_Error( 'rest_invalid_param', __( 'Task name cannot be empty.', 'pandatask' ), array( 'status' => 422 ) );
            }
        }

        if ( array_key_exists( 'description', $params ) ) {
            $data['description'] = TaskDescriptionService::sanitize( $params['description'] );
        }

        if ( array_key_exists( 'status', $params ) ) {
            $status = sanitize_key( $params['status'] );

            if ( ! in_array( $status, array( 'pending', 'in-progress', 'done' ), true ) ) {
                return new WP_Error( 'rest_invalid_param', __( 'Invalid task status.', 'pandatask' ), array( 'status' => 422 ) );
            }

            $data['status'] = $status;
        }

        if ( array_key_exists( 'task_type', $params ) ) {
            $task_type = sanitize_key( $params['task_type'] );

            if ( ! in_array( $task_type, array( 'task', 'bug' ), true ) ) {
                return new WP_Error( 'rest_invalid_param', __( 'Invalid task type.', 'pandatask' ), array( 'status' => 422 ) );
            }

            $data['task_type'] = $task_type;
        }

        if ( array_key_exists( 'priority', $params ) ) {
            $data['priority'] = max( 1, min( 10, absint( $params['priority'] ) ) );
        }

        foreach ( array( 'category_id', 'project_id', 'parent_task_id', 'deadline_days_after_start', 'notify_days_before', 'recurrence_interval', 'attachment_post_id', 'estimated_effort_seconds' ) as $integer_field ) {
            if ( array_key_exists( $integer_field, $params ) ) {
                $data[ $integer_field ] = '' === $params[ $integer_field ] || null === $params[ $integer_field ]
                    ? null
                    : absint( $params[ $integer_field ] );
            }
        }

        if ( isset( $data['notify_days_before'] ) ) {
            $data['notify_days_before'] = max( 1, min( 30, $data['notify_days_before'] ) );
        }

        foreach ( array( 'archived', 'notify_deadline', 'is_recurring' ) as $boolean_field ) {
            if ( array_key_exists( $boolean_field, $params ) ) {
                $data[ $boolean_field ] = rest_sanitize_boolean( $params[ $boolean_field ] ) ? 1 : 0;
            }
        }

        foreach ( array( 'deadline', 'start_date', 'recurrence_ends_on' ) as $date_field ) {
            if ( array_key_exists( $date_field, $params ) ) {
                $data[ $date_field ] = empty( $params[ $date_field ] ) ? null : $this->sanitizeDate( $params[ $date_field ] );

                if ( is_wp_error( $data[ $date_field ] ) ) {
                    return $data[ $date_field ];
                }
            }
        }

        if ( array_key_exists( 'bug_url', $params ) ) {
            $data['bug_url'] = esc_url_raw( $params['bug_url'] );
        }

        if ( array_key_exists( 'recurrence_days', $params ) ) {
            $data['recurrence_days'] = $this->sanitizeRecurrenceDays( $params['recurrence_days'] );
        }

        if ( array_key_exists( 'attachment_type', $params ) ) {
            $data['attachment_type'] = sanitize_key( $params['attachment_type'] );
        }

        if ( array_key_exists( 'attachment_filename', $params ) ) {
            $data['attachment_filename'] = sanitize_file_name( $params['attachment_filename'] );
        }

        if ( array_key_exists( 'attachment_url', $params ) ) {
            $data['attachment_url'] = esc_url_raw( $params['attachment_url'] );
        }

        foreach ( array( 'assigned_persons', 'supervisor_persons', 'predecessors' ) as $id_list_field ) {
            if ( array_key_exists( $id_list_field, $params ) ) {
                $data[ $id_list_field ] = RequestHelper::parseIdList( $params[ $id_list_field ] );
            }
        }

        if ( array_key_exists( 'recurrence_frequency', $params ) || array_key_exists( 'recurrence_frequency_val', $params ) ) {
            $data['recurrence_frequency_val'] = sanitize_key( $params['recurrence_frequency'] ?? $params['recurrence_frequency_val'] );
        }

        $data = $this->normalizeRecurrenceData( $data );

        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $attachment_validation = $this->validateAttachmentInput( $data, $current_task );

        return is_wp_error( $attachment_validation ) ? $attachment_validation : $data;
    }

    /**
     * @param array<string,mixed> $data Normalized task data.
     * @param object|null         $current_task Current task.
     * @return true|WP_Error
     */
    private function validateAttachmentInput( array $data, $current_task = null ) {
        if ( ! array_key_exists( 'attachment_type', $data ) && ! array_key_exists( 'attachment_post_id', $data ) && ! array_key_exists( 'attachment_url', $data ) ) {
            return true;
        }

        $attachment_type = array_key_exists( 'attachment_type', $data )
            ? sanitize_key( $data['attachment_type'] )
            : sanitize_key( $current_task->attachment_type ?? '' );
        $attachment_post_id = array_key_exists( 'attachment_post_id', $data )
            ? absint( $data['attachment_post_id'] )
            : absint( $current_task->attachment_post_id ?? 0 );
        $attachment_url = array_key_exists( 'attachment_url', $data )
            ? esc_url_raw( $data['attachment_url'] )
            : esc_url_raw( $current_task->attachment_url ?? '' );

        if ( ! in_array( $attachment_type, array( '', 'file', 'link' ), true ) ) {
            return new WP_Error( 'rest_invalid_attachment', __( 'Invalid attachment type.', 'pandatask' ), array( 'status' => 422 ) );
        }

        if ( '' === $attachment_type ) {
            return $attachment_post_id > 0
                ? new WP_Error( 'rest_invalid_attachment', __( 'An attachment ID requires the file attachment type.', 'pandatask' ), array( 'status' => 422 ) )
                : true;
        }

        if ( 'link' === $attachment_type ) {
            return '' !== $attachment_url
                ? true
                : new WP_Error( 'rest_invalid_attachment', __( 'A link attachment requires a valid URL.', 'pandatask' ), array( 'status' => 422 ) );
        }

        if ( $attachment_post_id <= 0 || 'attachment' !== get_post_type( $attachment_post_id ) ) {
            return new WP_Error( 'rest_invalid_attachment', __( 'A valid Media Library attachment is required.', 'pandatask' ), array( 'status' => 422 ) );
        }

        if ( $current_task && (int) $current_task->attachment_post_id === $attachment_post_id ) {
            return true;
        }

        if ( ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $attachment_post_id ) ) {
            return new WP_Error( 'rest_forbidden_attachment', __( 'You cannot attach this Media Library item.', 'pandatask' ), array( 'status' => 403 ) );
        }

        return true;
    }

    /**
     * @param mixed $value Raw date.
     * @return string|WP_Error
     */
    private function sanitizeDate( $value ) {
        $value = sanitize_text_field( $value );
        $date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );

        if ( ! $date || $date->format( 'Y-m-d' ) !== $value ) {
            return new WP_Error( 'rest_invalid_date', __( 'Dates must use the YYYY-MM-DD format.', 'pandatask' ), array( 'status' => 422 ) );
        }

        $minimum = (string) apply_filters( 'pandatask_minimum_task_date', '1900-01-01' );
        $maximum = (string) apply_filters( 'pandatask_maximum_task_date', '2200-12-31' );

        if ( $value < $minimum || $value > $maximum ) {
            return new WP_Error(
                'rest_invalid_date_range',
                sprintf(
                    /* translators: 1: earliest supported date, 2: latest supported date. */
                    __( 'Dates must be between %1$s and %2$s.', 'pandatask' ),
                    $minimum,
                    $maximum
                ),
                array( 'status' => 422 )
            );
        }

        return $value;
    }

    /**
     * @param mixed $value Raw recurrence weekdays.
     */
    private function sanitizeRecurrenceDays( $value ): string {
        $values = is_array( $value ) ? $value : explode( ',', (string) $value );
        $days = array_values(
            array_unique(
                array_filter(
                    array_map( 'absint', $values ),
                    static function ( $day ) {
                        return $day >= 1 && $day <= 7;
                    }
                )
            )
        );
        sort( $days );

        return implode( ',', $days );
    }

    /**
     * @param array<string,mixed> $data Task command data.
     * @return array<string,mixed>|WP_Error
     */
    private function normalizeRecurrenceData( array $data ) {
        if ( array_key_exists( 'is_recurring', $data ) && empty( $data['is_recurring'] ) ) {
            unset( $data['recurrence_frequency_val'] );

            return $data;
        }

        if ( ! isset( $data['recurrence_frequency'] ) && ! isset( $data['recurrence_frequency_val'] ) ) {
            return $data;
        }

        $frequency = sanitize_key( $data['recurrence_frequency'] ?? $data['recurrence_frequency_val'] );

        switch ( $frequency ) {
            case 'weekly':
                $data['recurrence_frequency'] = 'weekly';
                $data['recurrence_interval'] = 1;
                break;

            case 'bi-weekly':
                $data['recurrence_frequency'] = 'weekly';
                $data['recurrence_interval'] = 2;
                break;

            case 'monthly':
                $data['recurrence_frequency'] = 'monthly';
                $data['recurrence_interval'] = 1;
                break;

            case 'custom_weekly':
                $data['recurrence_frequency'] = 'custom_weekly';
                $data['recurrence_interval'] = 1;
                break;

            default:
                return new WP_Error( 'rest_invalid_recurrence', __( 'Invalid recurrence frequency.', 'pandatask' ), array( 'status' => 422 ) );
        }

        unset( $data['recurrence_frequency_val'] );

        return $data;
    }
}
