<?php

namespace Pandatask\Application\Security;

final class PublicBugSubmissionPolicy {

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
