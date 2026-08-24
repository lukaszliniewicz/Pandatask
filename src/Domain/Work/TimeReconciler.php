<?php

namespace Pandatask\Domain\Work;

use WP_Error;

final class TimeReconciler {

    /**
     * Reconcile specific logged time against a declared cumulative actual.
     *
     * @return array{specific_seconds:int,declared_actual_seconds:?int,residual_seconds:int,state:string}|WP_Error
     */
    public function reconcile( $specific_seconds, $declared_actual_seconds, $not_tracked = false ) {
        $specific_seconds = max( 0, (int) $specific_seconds );

        if ( $not_tracked ) {
            return array(
                'specific_seconds'        => $specific_seconds,
                'declared_actual_seconds' => null,
                'residual_seconds'        => 0,
                'state'                   => 'not_tracked',
            );
        }

        if ( null === $declared_actual_seconds || '' === $declared_actual_seconds ) {
            return new WP_Error(
                'pandatask_actual_time_required',
                __( 'Provide actual time or explicitly mark it as not tracked.', 'pandatask' ),
                array( 'status' => 422 )
            );
        }

        $declared_actual_seconds = max( 0, (int) $declared_actual_seconds );

        if ( $declared_actual_seconds < $specific_seconds ) {
            return new WP_Error(
                'pandatask_actual_below_specific',
                __( 'Actual time cannot be lower than already logged specific time.', 'pandatask' ),
                array(
                    'status'           => 422,
                    'specific_seconds' => $specific_seconds,
                )
            );
        }

        return array(
            'specific_seconds'        => $specific_seconds,
            'declared_actual_seconds' => $declared_actual_seconds,
            'residual_seconds'        => $declared_actual_seconds - $specific_seconds,
            'state'                   => 'resolved',
        );
    }
}
