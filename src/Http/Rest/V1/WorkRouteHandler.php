<?php

namespace Pandatask\Http\Rest\V1;

use Pandatask\Application\Settings\FeatureSettings;
use Pandatask\Application\Task\TaskService;
use Pandatask\Application\Work\TaskTimeService;
use Pandatask\Application\Work\WorkEntryService;
use Pandatask\Application\Work\WorkReportService;
use Pandatask\Application\Work\WorkSuggestionService;
use Pandatask\Application\Work\WorkTypeService;
use Pandatask\Application\Work\WorkLogShareService;
use WP_Error;
use WP_REST_Response;

final class WorkRouteHandler {

    private $work_service;
    private $task_service;
    private $task_time_service;
    private $report_service;
    private $suggestion_service;
    private $work_type_service;
    private $feature_settings;
    private $work_log_share_service;

    public function __construct( $work_service = null, $task_service = null, $task_time_service = null, $report_service = null, $suggestion_service = null, $work_type_service = null, $feature_settings = null, $work_log_share_service = null ) {
        $this->work_service       = $work_service ?: new WorkEntryService();
        $this->task_service       = $task_service ?: new TaskService();
        $this->task_time_service  = $task_time_service ?: new TaskTimeService();
        $this->report_service     = $report_service ?: new WorkReportService();
        $this->suggestion_service = $suggestion_service ?: new WorkSuggestionService();
        $this->work_type_service  = $work_type_service ?: new WorkTypeService();
        $this->feature_settings   = $feature_settings ?: new FeatureSettings();
        $this->work_log_share_service = $work_log_share_service;
    }

    public function activity_types( $request ) {
        return new WP_REST_Response( array( 'activity_types' => $this->work_service->activityTypes( get_current_user_id() ) ), 200 );
    }

    public function create_activity_type( $request ) {
        $data = $request->get_json_params();
        $data = is_array( $data ) ? $data : array();
        $type = $this->work_type_service->create( $data['label'] ?? '', get_current_user_id() );
        return is_wp_error( $type ) ? $type : new WP_REST_Response( array( 'activity_type' => $type ), 201 );
    }

    public function update_activity_type( $request ) {
        $data = $request->get_json_params();
        $data = is_array( $data ) ? $data : array();
        $changes = array();
        if ( array_key_exists( 'label', $data ) ) {
            $changes['label'] = $data['label'];
        }
        if ( array_key_exists( 'is_active', $data ) ) {
            $changes['is_active'] = $data['is_active'];
        }
        $type = $this->work_type_service->update( $request['key'], $changes, get_current_user_id() );
        return is_wp_error( $type ) ? $type : new WP_REST_Response( array( 'activity_type' => $type ), 200 );
    }

    public function delete_activity_type( $request ) {
        $type = $this->work_type_service->archive( $request['key'], get_current_user_id() );
        return is_wp_error( $type ) ? $type : new WP_REST_Response( array( 'activity_type' => $type ), 200 );
    }

    public function list_my_entries( $request ) {
        $limit = max( 1, min( 500, absint( $request['limit'] ?? 200 ) ) );
        $offset = max( 0, absint( $request['offset'] ?? 0 ) );
        $entries = $this->work_service->getEntriesForUser(
            get_current_user_id(),
            sanitize_text_field( $request['start_date'] ?? '' ),
            sanitize_text_field( $request['end_date'] ?? '' ),
            $limit + 1,
            $offset
        );
        $has_more = count( $entries ) > $limit;

        if ( $has_more ) {
            $entries = array_slice( $entries, 0, $limit );
        }

        return new WP_REST_Response(
            array(
                'entries' => $entries,
                'pagination' => array(
                    'limit'       => $limit,
                    'offset'      => $offset,
                    'returned'    => count( $entries ),
                    'has_more'    => $has_more,
                    'next_offset' => $has_more ? $offset + $limit : null,
                ),
            ),
            200
        );
    }

    public function list_my_suggestions( $request ) {
        list( $start, $end ) = $this->dateRange( $request );
        if ( is_wp_error( $start ) ) {
            return $start;
        }
        $suggestions = $this->suggestion_service->listForUser(
            get_current_user_id(),
            array(
                'start_date' => $start,
                'end_date'   => $end,
                'now_utc'    => gmdate( 'c' ),
            )
        );
        return new WP_REST_Response( array( 'suggestions' => $suggestions ), 200 );
    }

    public function confirm_suggestion( $request ) {
        $data = $request->get_json_params();
        $data = is_array( $data ) ? $data : array();
        $overrides = array();
        if ( array_key_exists( 'duration_seconds', $data ) ) {
            $overrides['duration_seconds'] = absint( $data['duration_seconds'] );
        }
        foreach ( array( 'title', 'notes', 'activity_type', 'capacity', 'work_date' ) as $field ) {
            if ( array_key_exists( $field, $data ) ) {
                $overrides[ $field ] = $data[ $field ];
            }
        }
        if ( array_key_exists( 'allocations', $data ) && is_array( $data['allocations'] ) ) {
            $overrides['allocations'] = $data['allocations'];
        }
        $result = $this->suggestion_service->confirm(
            get_current_user_id(),
            sanitize_key( $data['provider_key'] ?? '' ),
            sanitize_text_field( $data['external_key'] ?? '' ),
            $overrides,
            get_current_user_id()
        );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return new WP_REST_Response( $result, ! empty( $result['already_confirmed'] ) ? 200 : 201 );
    }

    public function dismiss_suggestion( $request ) {
        $data = $request->get_json_params();
        $data = is_array( $data ) ? $data : array();
        $decision = $this->suggestion_service->dismiss(
            get_current_user_id(),
            sanitize_key( $data['provider_key'] ?? '' ),
            sanitize_text_field( $data['external_key'] ?? '' ),
            get_current_user_id()
        );
        return is_wp_error( $decision )
            ? $decision
            : new WP_REST_Response( array( 'decision' => $decision ), 200 );
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

    public function get_entry( $request ) {
        $entry = $this->work_service->getEntry( (int) $request['id'] );

        if ( ! $entry ) {
            return new WP_Error( 'rest_not_found', __( 'Work entry not found.', 'pandatask' ), array( 'status' => 404 ) );
        }

        return new WP_REST_Response( array( 'entry' => $entry ), 200 );
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

        $work_log_enabled = $this->feature_settings->workLogEnabled();
        if ( ! $work_log_enabled ) {
            $not_tracked = true;
            $actual_seconds = null;
            $no_personal_work = true;
        }
        if ( $work_log_enabled && $no_personal_work && ! $this->canCompleteWithoutPersonalTime( $task, get_current_user_id() ) ) {
            return new WP_Error( 'rest_forbidden', __( 'Only a non-assignee supervisor, task creator, or administrator can complete without recording personal work.', 'pandatask' ), array( 'status' => 403 ) );
        }
        if ( $work_log_enabled && ! $no_personal_work && ! $not_tracked && null === $actual_seconds ) {
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

    public function work_log_sharing( $request ) {
        $result = $this->workLogShareService()->getSharing( get_current_user_id() );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
    }

    public function replace_work_log_sharing( $request ) {
        $data = $request->get_json_params();
        $data = is_array( $data ) ? $data : array();
        $group_ids = $data['shared_group_ids'] ?? $data['group_ids'] ?? null;
        if ( ! is_array( $group_ids ) ) {
            return new WP_Error( 'rest_invalid_param', __( 'shared_group_ids must be an array.', 'pandatask' ), array( 'status' => 422 ) );
        }

        $result = $this->workLogShareService()->replaceSharing( get_current_user_id(), $group_ids );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
    }

    public function group_work_logs( $request ) {
        list( $start, $end ) = $this->dateRange( $request );
        if ( is_wp_error( $start ) ) {
            return $start;
        }
        $result = $this->workLogShareService()->groupPresenters( (int) $request['group_id'], $start, $end );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
    }

    public function group_work_log( $request ) {
        list( $start, $end ) = $this->dateRange( $request );
        if ( is_wp_error( $start ) ) {
            return $start;
        }
        $result = $this->workLogShareService()->groupOwnerLog(
            (int) $request['group_id'],
            (int) $request['user_id'],
            $start,
            $end,
            absint( $request['limit'] ?? 200 ),
            absint( $request['offset'] ?? 0 )
        );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
    }

    private function workLogShareService() {
        if ( ! $this->work_log_share_service ) {
            $this->work_log_share_service = new WorkLogShareService();
        }
        return $this->work_log_share_service;
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
                $end   = $today->modify( 'last day of last month' );
                return array( $start->format( 'Y-m-d' ), $end->format( 'Y-m-d' ) );
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
