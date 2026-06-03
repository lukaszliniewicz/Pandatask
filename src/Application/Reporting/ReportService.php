<?php

namespace Pandatask\Application\Reporting;

use Pandatask\Infrastructure\Persistence\DatabaseContext;
use Pandatask\Infrastructure\Persistence\ReportRepository;

final class ReportService {

    private $repository;

    public function __construct( $repository = null ) {
        $this->repository = $repository ?: new ReportRepository();
    }

    public function getReportData( $board_name, $start_date, $end_date ) {
        $version       = DatabaseContext::getBoardCacheVersion( $board_name, 'reports' );
        $args_key      = md5( serialize( func_get_args() ) );
        $transient_key = "pandat69_report_{$board_name}_{$version}_{$args_key}";
        $cached_report = get_transient( $transient_key );

        if ( false !== $cached_report ) {
            return $cached_report;
        }

        $report_data = $this->repository->findReportData( $board_name, $start_date, $end_date );
        set_transient( $transient_key, $report_data, HOUR_IN_SECONDS );

        return $report_data;
    }
}
