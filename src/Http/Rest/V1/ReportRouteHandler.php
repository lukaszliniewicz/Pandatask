<?php

namespace Pandatask\Http\Rest\V1;

use DateTime;
use DateTimeZone;
use Pandatask\Application\Reporting\ReportService;
use WP_Error;
use WP_REST_Response;

final class ReportRouteHandler {

    private $report_service;

    public function __construct( $report_service = null ) {
        $this->report_service = $report_service ?: new ReportService();
    }

    public function get_report( $request ) {
        $board_name     = $request['board_name'];
        $period         = $request['period'];
        $start_date_str = '';
        $end_date_str   = '';

        $timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( wp_timezone_string() );
        $today    = new DateTime( 'now', $timezone );

        switch ( $period ) {
            case 'this_week':
                $start_of_week     = (int) get_option( 'start_of_week', 1 );
                $today_day_of_week = (int) $today->format( 'w' );
                $days_to_subtract  = $today_day_of_week - $start_of_week;

                if ( $days_to_subtract < 0 ) {
                    $days_to_subtract += 7;
                }

                $start_date     = ( clone $today )->modify( "-$days_to_subtract days" );
                $start_date_str = $start_date->format( 'Y-m-d' );
                $end_date_str   = ( clone $start_date )->modify( '+6 days' )->format( 'Y-m-d' );
                break;

            case 'last_week':
                $start_of_week     = (int) get_option( 'start_of_week', 1 );
                $today_day_of_week = (int) $today->format( 'w' );
                $days_to_subtract  = $today_day_of_week - $start_of_week + 7;
                $start_date        = ( clone $today )->modify( "-$days_to_subtract days" );
                $start_date_str    = $start_date->format( 'Y-m-d' );
                $end_date_str      = ( clone $start_date )->modify( '+6 days' )->format( 'Y-m-d' );
                break;

            case 'last_7_days':
                $start_date_str = ( clone $today )->modify( '-6 days' )->format( 'Y-m-d' );
                $end_date_str   = ( clone $today )->format( 'Y-m-d' );
                break;

            case 'this_month':
                $start_date_str = ( clone $today )->modify( 'first day of this month' )->format( 'Y-m-d' );
                $end_date_str   = ( clone $today )->modify( 'last day of this month' )->format( 'Y-m-d' );
                break;

            case 'last_month':
                $start_date     = ( clone $today )->modify( 'first day of last month' );
                $start_date_str = $start_date->format( 'Y-m-d' );
                $end_date_str   = ( clone $start_date )->modify( 'last day of this month' )->format( 'Y-m-d' );
                break;

            case 'last_30_days':
                $start_date_str = ( clone $today )->modify( '-29 days' )->format( 'Y-m-d' );
                $end_date_str   = ( clone $today )->format( 'Y-m-d' );
                break;

            case 'custom':
                $start_date_str = sanitize_text_field( $request['start_date'] );
                $end_date_str   = sanitize_text_field( $request['end_date'] );

                if ( empty( $start_date_str ) || empty( $end_date_str ) ) {
                    return new WP_Error( 'rest_invalid_param', 'Start and End date required for custom period', array( 'status' => 400 ) );
                }
                break;

            default:
                return new WP_Error( 'rest_invalid_param', 'Invalid period', array( 'status' => 400 ) );
        }

        $data = $this->report_service->getReportData( $board_name, $start_date_str, $end_date_str );

        if ( is_wp_error( $data ) ) {
            return $data;
        }

        return new WP_REST_Response( $data, 200 );
    }
}
