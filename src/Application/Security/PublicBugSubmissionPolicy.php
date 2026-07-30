<?php

namespace Pandatask\Application\Security;

final class PublicBugSubmissionPolicy {

    const RATE_LIMIT_WINDOW = 10 * MINUTE_IN_SECONDS;

    const RATE_LIMIT_MAX_SUBMISSIONS = 5;

    public function canSubmit( $board_name, $task_type, $is_logged_in ) {
        $settings = $this->getSettings();
        $visibility = $settings['visibility'];

        if ( 'bug' !== $task_type || empty( $settings['board'] ) || $settings['board'] !== $board_name ) {
            return false;
        }

        if ( $is_logged_in ) {
            return 'both' === $visibility || 'logged_in' === $visibility;
        }

        return 'both' === $visibility || 'logged_out' === $visibility;
    }

    public function getConfiguredAssigneeId() {
        $settings = $this->getSettings();

        return absint( $settings['assignee'] ?? 0 );
    }

    /**
     * Apply a small anonymous-only submission budget without trusting proxy headers.
     *
     * The address is salted and hashed before it is used as a transient key.
     */
    public function consumeAnonymousSubmissionBudget() {
        if ( is_user_logged_in() ) {
            return true;
        }

        $remote_address = isset( $_SERVER['REMOTE_ADDR'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
            : '';

        if ( '' === $remote_address ) {
            return new \WP_Error(
                'pandatask_rate_limited',
                __( 'Unable to verify this anonymous submission. Please try again after signing in.', 'pandatask' ),
                array( 'status' => 429 )
            );
        }

        $key = 'pandatask_public_bug_' . hash_hmac( 'sha256', $remote_address, wp_salt( 'auth' ) );
        $count = (int) get_transient( $key );
        $maximum = max( 1, (int) apply_filters( 'pandatask_public_bug_rate_limit', self::RATE_LIMIT_MAX_SUBMISSIONS ) );
        $window = max( MINUTE_IN_SECONDS, (int) apply_filters( 'pandatask_public_bug_rate_window', self::RATE_LIMIT_WINDOW ) );

        if ( $count >= $maximum ) {
            return new \WP_Error(
                'pandatask_rate_limited',
                __( 'Too many bug reports were submitted from this address. Please try again later.', 'pandatask' ),
                array( 'status' => 429, 'retry_after' => $window )
            );
        }

        set_transient( $key, $count + 1, $window );

        return true;
    }

    private function getSettings() {
        $settings = get_option( 'pandatask_bug_tracker_settings', array() );

        if ( ! is_array( $settings ) ) {
            $settings = array();
        }

        $default_visibility = ! empty( $settings['enable'] ) ? 'logged_in' : 'off';
        $visibility = $settings['visibility'] ?? $default_visibility;

        if ( ! in_array( $visibility, array( 'off', 'logged_in', 'logged_out', 'both' ), true ) ) {
            $visibility = 'off';
        }

        return array(
            'visibility' => $visibility,
            'board'      => isset( $settings['board'] ) ? sanitize_key( $settings['board'] ) : '',
            'assignee'   => absint( $settings['assignee'] ?? 0 ),
        );
    }
}
