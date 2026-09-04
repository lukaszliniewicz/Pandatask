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
     * @param string      $frequency weekly, monthly, monthly_weekday, or custom_weekly.
     * @param int         $interval Positive recurrence interval.
     * @param string|null $days_of_week Comma-separated ISO weekdays (1-7).
     * @param int         $anchor_day Original day-of-month for monthly recurrence.
     * @param string|null $recurrence_month_week Ordinal month week for monthly_weekday recurrence.
     */
    public function next( $from_date, $frequency, $interval = 1, $days_of_week = null, $anchor_day = 0, $recurrence_month_week = null ): ?string {
        try {
            $from = new DateTimeImmutable( $from_date );
        } catch ( Exception $exception ) {
            return null;
        }

        $interval = $this->parsePositiveInterval( $interval );
        if ( null === $interval ) {
            if ( in_array( $frequency, array( 'weekly', 'bi-weekly', 'monthly' ), true ) ) {
                $interval = max( 1, (int) $interval );
            } else {
                return null;
            }
        }

        if ( 'weekly' === $frequency || 'bi-weekly' === $frequency ) {
            return $from->modify( '+' . ( 7 * $interval ) . ' days' )->format( 'Y-m-d' );
        }

        if ( 'monthly' === $frequency ) {
            return $this->addMonthsClamped( $from, $interval, $anchor_day )->format( 'Y-m-d' );
        }

        if ( 'monthly_weekday' === $frequency ) {
            $weekday = $this->parseSingleWeekday( $days_of_week );
            $month_week = $this->parseMonthWeek( $recurrence_month_week );

            if ( null === $weekday || null === $month_week ) {
                return null;
            }

            $candidate = $this->monthlyWeekdayOccurrence( $from, $interval, $weekday, $month_week );

            return null === $candidate ? null : $candidate->format( 'Y-m-d' );
        }

        if ( 'custom_weekly' !== $frequency ) {
            return null;
        }

        $weekdays = $this->parseWeekdays( $days_of_week );

        if ( empty( $weekdays ) ) {
            return null;
        }

        $from_weekday = (int) $from->format( 'N' );
        $week_start   = $from->modify( '-' . ( $from_weekday - 1 ) . ' days' );

        foreach ( $weekdays as $weekday ) {
            if ( $weekday > $from_weekday ) {
                return $week_start->modify( '+' . ( $weekday - 1 ) . ' days' )->format( 'Y-m-d' );
            }
        }

        return $week_start->modify( '+' . ( 7 * $interval + $weekdays[0] - 1 ) . ' days' )->format( 'Y-m-d' );
    }

    /**
     * Return the first occurrence on or after a target without an unbounded loop.
     */
    public function onOrAfter( $from_date, $target_date, $frequency, $interval = 1, $days_of_week = null, $anchor_day = 0, $recurrence_month_week = null ): ?string {
        try {
            $from = new DateTimeImmutable( $from_date );
            $target = new DateTimeImmutable( $target_date );
        } catch ( Exception $exception ) {
            return null;
        }

        if ( ! in_array( $frequency, array( 'weekly', 'bi-weekly', 'monthly', 'monthly_weekday', 'custom_weekly' ), true ) ) {
            return null;
        }

        $interval = $this->parsePositiveInterval( $interval );
        if ( null === $interval ) {
            if ( in_array( $frequency, array( 'weekly', 'bi-weekly', 'monthly' ), true ) ) {
                $interval = max( 1, (int) $interval );
            } else {
                return null;
            }
        }

        $weekdays = null;
        if ( 'custom_weekly' === $frequency ) {
            $weekdays = $this->parseWeekdays( $days_of_week );

            if ( empty( $weekdays ) ) {
                return null;
            }
        }

        $weekday = null;
        $month_week = null;
        if ( 'monthly_weekday' === $frequency ) {
            $weekday = $this->parseSingleWeekday( $days_of_week );
            $month_week = $this->parseMonthWeek( $recurrence_month_week );

            if ( null === $weekday || null === $month_week ) {
                return null;
            }
        }

        if ( $from >= $target ) {
            return $from->format( 'Y-m-d' );
        }

        if ( 'weekly' === $frequency || 'bi-weekly' === $frequency ) {
            $period_days = 7 * $interval;
            $elapsed_days = (int) $from->diff( $target )->format( '%a' );
            $periods = max( 1, (int) ceil( $elapsed_days / $period_days ) );

            return $from->modify( '+' . ( $periods * $period_days ) . ' days' )->format( 'Y-m-d' );
        }

        if ( 'custom_weekly' === $frequency ) {
            $from_weekday = (int) $from->format( 'N' );
            $from_week_start = $from->modify( '-' . ( $from_weekday - 1 ) . ' days' );
            $target_weekday = (int) $target->format( 'N' );
            $target_week_start = $target->modify( '-' . ( $target_weekday - 1 ) . ' days' );
            $weeks_between = (int) floor( (int) $from_week_start->diff( $target_week_start )->format( '%a' ) / 7 );
            $periods = max( 0, (int) ceil( $weeks_between / $interval ) );
            $active_week_start = $from_week_start->modify( '+' . ( 7 * $periods * $interval ) . ' days' );

            foreach ( $weekdays as $configured_weekday ) {
                $candidate = $active_week_start->modify( '+' . ( $configured_weekday - 1 ) . ' days' );
                if ( $candidate >= $target && $candidate > $from ) {
                    return $candidate->format( 'Y-m-d' );
                }
            }

            return $active_week_start
                ->modify( '+' . ( 7 * $interval + $weekdays[0] - 1 ) . ' days' )
                ->format( 'Y-m-d' );
        }

        if ( 'monthly_weekday' === $frequency ) {
            $months_between = ( (int) $target->format( 'Y' ) - (int) $from->format( 'Y' ) ) * 12
                + (int) $target->format( 'n' )
                - (int) $from->format( 'n' );
            $periods = max( 1, (int) floor( $months_between / $interval ) );
            $candidate = $this->monthlyWeekdayOccurrence( $from, $periods * $interval, $weekday, $month_week );

            if ( null === $candidate ) {
                return null;
            }

            if ( $candidate < $target ) {
                $candidate = $this->monthlyWeekdayOccurrence( $from, ( $periods + 1 ) * $interval, $weekday, $month_week );
            }

            return null === $candidate ? null : $candidate->format( 'Y-m-d' );
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

    private function monthlyWeekdayOccurrence( DateTimeImmutable $date, $months, $weekday, $month_week ): ?DateTimeImmutable {
        $month_index = ( (int) $date->format( 'Y' ) * 12 ) + (int) $date->format( 'n' ) - 1 + (int) $months;
        $year = (int) floor( $month_index / 12 );
        $month = ( $month_index % 12 ) + 1;
        $first = new DateTimeImmutable( sprintf( '%04d-%02d-01', $year, $month ) );
        $last_day = (int) $first->format( 't' );

        if ( 'last' === $month_week ) {
            $last = $first->setDate( $year, $month, $last_day );
            $day = $last_day - ( ( (int) $last->format( 'N' ) - $weekday + 7 ) % 7 );
        } else {
            $ordinal = array_search( $month_week, array( 'first', 'second', 'third', 'fourth' ), true );
            if ( false === $ordinal ) {
                return null;
            }

            $day = 1 + ( ( $weekday - (int) $first->format( 'N' ) + 7 ) % 7 ) + ( 7 * $ordinal );
        }

        return $first->setDate( $year, $month, $day );
    }

    private function parsePositiveInterval( $interval ): ?int {
        $parsed = filter_var( $interval, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );

        return false === $parsed ? null : (int) $parsed;
    }

    private function parseMonthWeek( $month_week ): ?string {
        $valid_month_weeks = array( 'first', 'second', 'third', 'fourth', 'last' );

        return in_array( $month_week, $valid_month_weeks, true ) ? $month_week : null;
    }

    private function parseSingleWeekday( $days_of_week ): ?int {
        if ( is_int( $days_of_week ) && $days_of_week >= 1 && $days_of_week <= 7 ) {
            return $days_of_week;
        }

        if ( ! is_string( $days_of_week ) || ! preg_match( '/^[1-7]$/D', $days_of_week ) ) {
            return null;
        }

        return (int) $days_of_week;
    }

    /**
     * @return array<int>
     */
    private function parseWeekdays( $days_of_week ): array {
        if ( is_array( $days_of_week ) || ! preg_match( '/^[1-7](,[1-7])*$/D', (string) $days_of_week ) ) {
            return array();
        }

        $days = array_values( array_unique( array_map( 'intval', explode( ',', (string) $days_of_week ) ) ) );
        sort( $days );

        return $days;
    }
}
