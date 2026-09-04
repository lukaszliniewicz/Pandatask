<?php

/**
 * Focused assignment-notification presentation checks.
 *
 * Run with: php tests/assignment-notifications.php
 */

namespace Pandatask\Application\Task {
    final class TaskService {
        public function getTask( $task_id ) {
            unset( $task_id );
            return $GLOBALS['pandatask_notification_task'] ?? null;
        }
    }
}

namespace Pandatask\Infrastructure\Notifications {
    final class TaskBoardUrlResolver {
        public static function resolve( $board_name, $task_id = 0 ) {
            return 'https://example.test/tasks/' . rawurlencode( $board_name ) . '/' . (int) $task_id;
        }
    }
}

namespace {
    define( 'ABSPATH', dirname( __DIR__ ) );

    function __( $text, $domain = null ) {
        unset( $domain );
        return $text;
    }
    function get_option( $name ) {
        unset( $name );
        return 'admin@example.test';
    }
    function get_bloginfo( $field ) {
        unset( $field );
        return 'Pandatask Test';
    }
    function home_url() { return 'https://example.test'; }
    function get_userdata( $user_id ) {
        return (object) array(
            'display_name' => 99 === (int) $user_id ? 'Assigning Person' : 'Assigned Person',
            'user_email'   => 'person@example.test',
        );
    }
    function esc_html( $value ) {
        return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
    }
    function esc_url( $value ) { return (string) $value; }
    function apply_filters( $hook, $value ) {
        unset( $hook );
        return $value;
    }
    function wp_mail( $to, $subject, $message, $headers ) {
        $GLOBALS['pandatask_notification_mail'][] = compact( 'to', 'subject', 'message', 'headers' );
        return true;
    }
    function wp_strip_all_tags( $value, $remove_breaks = false ) {
        $text = strip_tags( (string) $value );
        return $remove_breaks ? preg_replace( '/[\r\n\t ]+/', ' ', $text ) : $text;
    }
    function wp_trim_words( $text, $word_limit = 55, $more = null ) {
        $words = preg_split( '/\s+/', trim( (string) $text ) );
        if ( count( $words ) <= $word_limit ) {
            return trim( (string) $text );
        }
        return implode( ' ', array_slice( $words, 0, $word_limit ) ) . ( null === $more ? '&hellip;' : $more );
    }
    function add_query_arg( $args, $url ) {
        return $url . '?' . http_build_query( $args );
    }
    function wp_create_nonce( $action ) {
        unset( $action );
        return 'nonce';
    }

    require_once dirname( __DIR__ ) . '/src/Application/Task/TaskDescriptionService.php';
    require_once dirname( __DIR__ ) . '/src/Infrastructure/Notifications/EmailNotifier.php';
    require_once dirname( __DIR__ ) . '/src/Infrastructure/Notifications/BuddyPressNotifier.php';

    use Pandatask\Infrastructure\Notifications\BuddyPressNotifier;
    use Pandatask\Infrastructure\Notifications\EmailNotifier;

    $failures = array();
    $assert_contains = static function ( $needle, $haystack, $message ) use ( &$failures ) {
        if ( false === strpos( $haystack, $needle ) ) {
            $failures[] = $message;
        }
    };
    $assert_not_contains = static function ( $needle, $haystack, $message ) use ( &$failures ) {
        if ( false !== strpos( $haystack, $needle ) ) {
            $failures[] = $message;
        }
    };

    $GLOBALS['pandatask_notification_task'] = (object) array(
        'name'        => 'Short assignment',
        'description' => '<p>Review the <a href="https://example.test/spec">launch specification</a>.</p>',
        'board_name'  => 'group_delivery',
        'status'      => 'pending',
        'priority'    => 7,
        'deadline'    => null,
    );
    EmailNotifier::send_assignment_notification( 42, array( 7 ) );
    $mail = $GLOBALS['pandatask_notification_mail'][0]['message'] ?? '';
    $assert_contains( '<strong>Description</strong>', $mail, 'Assignment email must label the description excerpt.' );
    $assert_contains( 'Review the launch specification.', $mail, 'Assignment email must preserve useful linked text.' );
    $assert_not_contains( '<a href="https://example.test/spec">', $mail, 'Stored description markup must not be embedded in assignment email excerpts.' );

    $bp_assignment = BuddyPressNotifier::format_notifications( 'task_assignment', 42, 99, 1, 'string', 'task_assignment', 'pandatask', 5 );
    $bp_supervision = BuddyPressNotifier::format_notifications( 'task_supervision', 42, 99, 1, 'string', 'task_supervision', 'pandatask', 6 );
    $assert_contains( 'Review the launch specification.', $bp_assignment, 'BuddyPress assignment notification must include the description excerpt.' );
    $assert_contains( 'assigned you as supervisor', $bp_supervision, 'Supervisor assignment notification must retain role context.' );
    $assert_contains( 'Review the launch specification.', $bp_supervision, 'Supervisor assignment notification must include the description excerpt.' );

    $long_words = array_fill( 0, 60, 'context' );
    $GLOBALS['pandatask_notification_task']->description = '<p>' . implode( ' ', $long_words ) . '</p>';
    $GLOBALS['pandatask_notification_mail'] = array();
    EmailNotifier::send_assignment_notification( 42, array( 7 ) );
    $long_mail = $GLOBALS['pandatask_notification_mail'][0]['message'] ?? '';
    $assert_contains( 'context…', $long_mail, 'Long email descriptions must be trimmed with an ellipsis.' );

    $GLOBALS['pandatask_notification_task']->description = '';
    $GLOBALS['pandatask_notification_mail'] = array();
    EmailNotifier::send_assignment_notification( 42, array( 7 ) );
    $empty_mail = $GLOBALS['pandatask_notification_mail'][0]['message'] ?? '';
    $assert_not_contains( '<strong>Description</strong>', $empty_mail, 'Empty descriptions must not render an empty email row.' );
    $empty_bp = BuddyPressNotifier::format_notifications( 'task_assignment', 42, 99, 1, 'string', 'task_assignment', 'pandatask', 7 );
    $assert_contains( 'assigned you to task: Short assignment', $empty_bp, 'Description-free assignments must retain the concise fallback copy.' );

    if ( ! empty( $failures ) ) {
        fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
        exit( 1 );
    }

    echo "Assignment notification tests passed.\n";
}
