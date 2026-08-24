<?php

namespace Pandatask\Http\Rest\V1;

use Pandatask\Application\Task\TaskService;
use Pandatask\Application\Work\TaskTimeService;
use Pandatask\Application\Work\WorkEntryService;
use Pandatask\Application\Work\WorkReportService;
use WP_Error;
use WP_REST_Response;

final class WorkRouteHandler {

    private $work_service;
    private $task_service;
    private $task_time_service;
    private $report_service;

    public function __construct( $work_service = null, $task_service = null, $task_time_service = null, $report_service = null ) {
        $this->work_service      = $work_service ?: new WorkEntryService();
        $this->task_service      = $task_service ?: new TaskService();
        $this->task_time_service = $task_time_service ?: new TaskTimeService();
        $this->report_service    = $report_service ?: new WorkReportService();
    }

    public function activity_types( $request ) {
        return new WP_REST_Response( array( 'activity_types' => $this->work_service->activityTypes() ), 200 );
    }

    public function list_my_entries( $request ) {
        $entries = $this->work_service->getEntriesForUser(
            get_current_user_id(),
            sanitize_text_field( $request['start_date'] ?? '' ),
            sanitize_text_field( $request['end_date'] ?? '' ),
            absint( $request['limit'] ?? 200 ),
            absint( $request['offset'] ?? 0 )
        );
        return new WP_REST_Response( array( 'entries' => $entries ), 200 );
    }

    public function create_entry( $request ) {
        $data = $request->get_json_params();
        $entry = $this->work_service->createEntry( is_array( $data ) ? $data : array(), get_current_user_id() );
        return is_wp_error( $entry ) ? $entry : new WP_REST_Response( array( 'entry' => $entry ), 201 );
    }

    public function update_entry( $request ) {
        $data = $request->get_json_params();
        $entry = $this->work_service->updateEntry( (int) $request['id'], is_array( $data ) ? $data : array(), get_current_user_id() );
        return is_wp_error( $entry ) ? $entry : new WP_REST_Response( array( 'entry' => $entry ), 200 );
    }

    public function delete_entry( $request ) {
        $result = $this->work_service->deleteEntry( (int) $request['id'], get_current_user_id() );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( array( 'deleted' => true ), 200 );
    }

    public function task_work( $request ) {
        $task_id = (int) $request['id'];
        $user_id = get_current_user_id();
        $task = $this->task_service->getTaskForAuthorization( $task_id );
        return new WP_REST_Response(
            array(
                'entries'   => $this->work_service->getEntriesForTask( $task_id, $user_id ),
                'my_time'   => $this->task_time_service->getTaskSummary( $task_id, $user_id ),
                'aggregate' => $this->work_service->getTaskAggregate( $task_id ),
                'can_complete_without_personal_time' => $this->canCompleteWithoutPersonalTime( $task, $user_id ),
            ),
            200
        );
    }

    public function resolve_occurrence_time( $request ) {
        $occurrence_id = (int) $request['id'];
        $user_id = get_current_user_id();
        if ( ! $this->task_time_service->canUserResolveOccurrence( $occurrence_id, $user_id ) ) {
            return new WP_Error( 'rest_forbidden', __( 'You cannot resolve time for this work occurrence.', 'pandatask' ), array( 'status' => 403 ) );
        }

        $data = $request->get_json_params();
        $data = is_array( $data ) ? $data : array();
        $not_tracked = ! empty( $data['not_tracked'] );
        $actual_seconds = array_key_exists( 'actual_seconds', $data ) && null !== $data['actual_seconds']
            ? absint( $data['actual_seconds'] )
            : null;
        if ( ! $not_tracked && null === $actual_seconds ) {
            return new WP_Error( 'pandatask_actual_time_required', __( 'Provide actual time or choose Not tracked.', 'pandatask' ), array( 'status' => 422 ) );
        }

        $result = $this->task_time_service->resolveOccurrenceStandalone(
            $occurrence_id,
            $user_id,
            $actual_seconds,
            $not_tracked,
            $user_id
        );

        return is_wp_error( $result )
            ? $result
            : new WP_REST_Response( array( 'time' => $this->task_time_service->getOccurrenceSummary( $occurrence_id, $user_id ) ), 200 );
    }

    public function resolve_task_time( $request ) {
        $data = $request->get_json_params();
        $data = is_array( $data ) ? $data : array();
        $not_tracked = ! empty( $data['not_tracked'] );
        $actual_seconds = array_key_exists( 'actual_seconds', $data ) && null !== $data['actual_seconds']
            ? absint( $data['actual_seconds'] )
            : null;

        if ( ! $not_tracked && null === $actual_seconds ) {
            return new WP_Error( 'pandatask_actual_time_required', __( 'Provide actual time or choose Not tracked.', 'pandatask' ), array( 'status' => 422 ) );
        }

        $result = $this->task_time_service->resolveCurrentOccurrenceStandalone(
            (int) $request['id'],
            get_current_user_id(),
            $actual_seconds,
            $not_tracked,
            get_current_user_id()
        );

        return is_wp_error( $result )
            ? $result
            : new WP_REST_Response( array( 'time' => $this->task_time_service->getTaskSummary( (int) $request['id'], get_current_user_id() ) ), 200 );
    }

    public function complete_task( $request ) {
        $data = $request->get_json_params();
        $data = is_array( $data ) ? $data : array();
        $not_tracked = ! empty( $data['not_tracked'] );
        $no_personal_work = ! empty( $data['no_personal_work'] );
        $actual_seconds = array_key_exists( 'actual_seconds', $data ) && null !== $data['actual_seconds'] ? absint( $data['actual_seconds'] ) : null;
        $task = $this->task_service->getTaskForAuthorization( (int) $request['id'] );

        if ( $no_personal_work && ! $this->canCompleteWithoutPersonalTime( $task, get_current_user_id() ) ) {
            return new WP_Error( 'rest_forbidden', __( 'Only a non-assignee supervisor, task creator, or administrator can complete without recording personal work.', 'pandatask' ), array( 'status' => 403 ) );
        }
        if ( ! $no_personal_work && ! $not_tracked && null === $actual_seconds ) {
            return new WP_Error( 'pandatask_actual_time_required', __( 'Provide actual time, choose Not tracked, or use the supervisor completion option.', 'pandatask' ), array( 'status' => 422 ) );
        }
        $result = $this->task_service->completeTask(
            (int) $request['id'],
            array(
                'user_id'                  => get_current_user_id(),
                'actual_seconds'           => $actual_seconds,
                'not_tracked'              => $not_tracked,
                'skip_personal_resolution' => $no_personal_work,
            ),
            sanitize_textarea_field( $data['change_comment'] ?? '' ),
            get_current_user_id()
        );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        if ( true !== $result ) {
            return new WP_Error( 'pandatask_update_failed', __( 'The task could not be completed.', 'pandatask' ), array( 'status' => 500 ) );
        }
        return new WP_REST_Response( array( 'task' => $this->task_service->getTask( (int) $request['id'] ) ), 200 );
    }

    public function personal_report( $request ) {
        list( $start, $end ) = $this->dateRange( $request );
        if ( is_wp_error( $start ) ) {
            return $start;
        }
        return new WP_REST_Response( $this->report_service->personal( get_current_user_id(), $start, $end ), 200 );
    }

    public function board_report( $request ) {
        list( $start, $end ) = $this->dateRange( $request );
        if ( is_wp_error( $start ) ) {
            return $start;
        }
        return new WP_REST_Response( $this->report_service->board( sanitize_key( $request['board_name'] ), $start, $end ), 200 );
    }

    private function canCompleteWithoutPersonalTime( $task, $user_id ) {
        if ( ! $task || $user_id <= 0 ) {
            return false;
        }

        $assignees = array_map( 'intval', (array) ( $task->assigned_user_ids ?? array() ) );
        if ( in_array( (int) $user_id, $assignees, true ) ) {
            return false;
        }

        if ( user_can( (int) $user_id, 'manage_options' ) ) {
            return true;
        }

        if ( (int) ( $task->creator_id ?? 0 ) === (int) $user_id ) {
            return true;
        }

        return in_array(
            (int) $user_id,
            array_map( 'intval', (array) ( $task->supervisor_user_ids ?? array() ) ),
            true
        );
    }

    private function namedDateRange( $period ) {
        $today = new \DateTimeImmutable( 'now', wp_timezone() );
        switch ( $period ) {
            case 'this_week':
                $start_of_week = (int) get_option( 'start_of_week', 1 );
                $days = ( (int) $today->format( 'w' ) - $start_of_week + 7 ) % 7;
                $start = $today->modify( '-' . $days . ' days' );
                return array( $start->format( 'Y-m-d' ), $start->modify( '+6 days' )->format( 'Y-m-d' ) );
            case 'last_week':
                $start_of_week = (int) get_option( 'start_of_week', 1 );
                $days = ( (int) $today->format( 'w' ) - $start_of_week + 7 ) % 7;
                $start = $today->modify( '-' . ( $days + 7 ) . ' days' );
                return array( $start->format( 'Y-m-d' ), $start->modify( '+6 days' )->format( 'Y-m-d' ) );
            case 'last_7_days':
                return array( $today->modify( '-6 days' )->format( 'Y-m-d' ), $today->format( 'Y-m-d' ) );
            case 'this_month':
                return array( $today->modify( 'first day of this month' )->format( 'Y-m-d' ), $today->modify( 'last day of this month' )->format( 'Y-m-d' ) );
            case 'last_month':
                $start = $today->modify( 'first day of last month' );
                return array( $start->format( 'Y-m-d' ), $start->modify( 'last day of this month' )->format( 'Y-m-d' ) );
            case 'last_30_days':
                return array( $today->modify( '-29 days' )->format( 'Y-m-d' ), $today->format( 'Y-m-d' ) );
            case 'custom':
                return array( new WP_Error( 'rest_invalid_param', __( 'Custom work reports require start_date and end_date.', 'pandatask' ), array( 'status' => 422 ) ), '' );
            default:
                return array( new WP_Error( 'rest_invalid_param', __( 'Invalid report period.', 'pandatask' ), array( 'status' => 422 ) ), '' );
        }
    }

    private function dateRange( $request ) {
        $period = sanitize_key( $request['period'] ?? '' );
        $explicit_start = sanitize_text_field( $request['start_date'] ?? '' );
        $explicit_end = sanitize_text_field( $request['end_date'] ?? '' );

        if ( $explicit_start || $explicit_end ) {
            $end = $explicit_end ?: wp_date( 'Y-m-d' );
            $start = $explicit_start ?: ( new \DateTimeImmutable( $end, wp_timezone() ) )->modify( '-29 days' )->format( 'Y-m-d' );
        } else {
            list( $start, $end ) = $this->namedDateRange( $period ?: 'last_30_days' );
            if ( is_wp_error( $start ) ) {
                return array( $start, '' );
            }
        }
        foreach ( array( $start, $end ) as $value ) {
            $date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );
            if ( ! $date || $date->format( 'Y-m-d' ) !== $value ) {
                return array( new WP_Error( 'rest_invalid_date', __( 'Dates must use YYYY-MM-DD.', 'pandatask' ), array( 'status' => 422 ) ), '' );
            }
        }
        if ( $start > $end ) {
            return array( new WP_Error( 'rest_invalid_date', __( 'Start date cannot be after end date.', 'pandatask' ), array( 'status' => 422 ) ), '' );
        }
        return array( $start, $end );
    }
}
