<?php

namespace Pandatask\Application\Work;

use Pandatask\Infrastructure\Persistence\WorkReportRepository;

final class WorkReportService {

    private $repository;

    public function __construct( $repository = null ) {
        $this->repository = $repository ?: new WorkReportRepository();
    }

    public function personal( $user_id, $start_date, $end_date ) {
        return array_merge(
            array(
                'scope'      => 'personal',
                'user_id'    => (int) $user_id,
                'start_date' => $start_date,
                'end_date'   => $end_date,
                'unresolved_occurrences' => $this->repository->unresolvedOccurrenceCountForUser( $user_id ),
                'unresolved' => $this->repository->unresolvedOccurrencesForUser( $user_id ),
            ),
            $this->repository->personalSummary( $user_id, $start_date, $end_date )
        );
    }

    public function board( $board_name, $start_date, $end_date ) {
        return array_merge(
            array(
                'scope'      => 'board',
                'board_name' => $board_name,
                'start_date' => $start_date,
                'end_date'   => $end_date,
                'unresolved_occurrences' => $this->repository->unresolvedOccurrenceCount( $board_name ),
            ),
            $this->repository->boardSummary( $board_name, $start_date, $end_date )
        );
    }
}
