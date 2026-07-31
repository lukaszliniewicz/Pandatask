<?php

namespace Pandatask\Domain\Task;

use DateTimeImmutable;
use Exception;

/**
 * Pure recurrence arithmetic shared by interactive deletes and cron rollover.
 */
final class RecurrenceCalculator {

    /**
     * @param string      $from_date ISO date for the current occurrence.
     * @param string      $frequency weekly, monthly, or custom_weekly.
     * @param int         $interval Positive recurrence interval.
     * @param string|null $days_of_week Comma-separated ISO weekdays (1-7).
     * @param int         $anchor_day Original day-of-month for monthly recurrence.
     */
    public function next( $from_date, $frequency, $interval = 1, $days_of_week = null, $anchor_day = 0 ): ?string {
        try {
            $from = new DateTimeImmutable( $from_date );
        } catch ( Exception $exception ) {
            return null;
        }

        $interval = max( 1, (int) $interval );

        if ( 'weekly' === $frequency || 'bi-weekly' === $frequency ) {
            return $from->modify( '+' . ( 7 * $interval ) . ' days' )->format( 'Y-m-d' );
        }

        if ( 'monthly' === $frequency ) {
            return $this->addMonthsClamped( $from, $interval, $anchor_day )->format( 'Y-m-d' );
        }

        if ( 'custom_weekly' !== $frequency ) {
            return null;
        }

        $weekdays = $this->parseWeekdays( $days_of_week );

        if ( empty( $weekdays ) ) {
            return null;
        }

        $candidate = $from->modify( '+1 day' );

        for ( $offset = 0; $offset < 7; $offset++ ) {
            if ( in_array( (int) $candidate->format( 'N' ), $weekdays, true ) ) {
                return $candidate->format( 'Y-m-d' );
            }

            $candidate = $candidate->modify( '+1 day' );
        }

        return null;
    }

    /**
     * Return the first occurrence on or after a target without an unbounded loop.
     */
    public function onOrAfter( $from_date, $target_date, $frequency, $interval = 1, $days_of_week = null, $anchor_day = 0 ): ?string {
        try {
            $from = new DateTimeImmutable( $from_date );
            $target = new DateTimeImmutable( $target_date );
        } catch ( Exception $exception ) {
            return null;
        }

        if ( $from >= $target ) {
            return $from->format( 'Y-m-d' );
        }

        $interval = max( 1, (int) $interval );

        if ( 'weekly' === $frequency || 'bi-weekly' === $frequency ) {
            $period_days = 7 * $interval;
            $elapsed_days = (int) $from->diff( $target )->format( '%a' );
            $periods = max( 1, (int) ceil( $elapsed_days / $period_days ) );

            return $from->modify( '+' . ( $periods * $period_days ) . ' days' )->format( 'Y-m-d' );
        }

        if ( 'custom_weekly' === $frequency ) {
            $weekdays = $this->parseWeekdays( $days_of_week );

            if ( empty( $weekdays ) ) {
                return null;
            }

            $candidate = $target;

            for ( $offset = 0; $offset < 7; $offset++ ) {
                if ( in_array( (int) $candidate->format( 'N' ), $weekdays, true ) ) {
                    return $candidate->format( 'Y-m-d' );
                }

                $candidate = $candidate->modify( '+1 day' );
            }

            return null;
        }

        if ( 'monthly' !== $frequency ) {
            return null;
        }

        $months_between = ( (int) $target->format( 'Y' ) - (int) $from->format( 'Y' ) ) * 12
            + (int) $target->format( 'n' )
            - (int) $from->format( 'n' );
        $periods = max( 1, (int) floor( $months_between / $interval ) );
        $candidate = $this->addMonthsClamped( $from, $periods * $interval, $anchor_day );

        if ( $candidate < $target ) {
            $candidate = $this->addMonthsClamped( $from, ( $periods + 1 ) * $interval, $anchor_day );
        }

        return $candidate->format( 'Y-m-d' );
    }

    private function addMonthsClamped( DateTimeImmutable $date, $months, $anchor_day ): DateTimeImmutable {
        $month_index = ( (int) $date->format( 'Y' ) * 12 ) + (int) $date->format( 'n' ) - 1 + (int) $months;
        $year = (int) floor( $month_index / 12 );
        $month = ( $month_index % 12 ) + 1;
        $first = new DateTimeImmutable( sprintf( '%04d-%02d-01', $year, $month ) );
        $last_day = (int) $first->format( 't' );
        $day = max( 1, min( $last_day, (int) $anchor_day ?: (int) $date->format( 'j' ) ) );

        return $first->setDate( $year, $month, $day );
    }

    /**
     * @return array<int>
     */
    private function parseWeekdays( $days_of_week ): array {
        $days = array_values(
            array_unique(
                array_filter(
                    array_map( 'intval', explode( ',', (string) $days_of_week ) ),
                    static function ( $day ) {
                        return $day >= 1 && $day <= 7;
                    }
                )
            )
        );
        sort( $days );

        return $days;
    }
}
