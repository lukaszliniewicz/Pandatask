<?php

namespace Pandatask\Infrastructure\Scheduler;

use Pandatask\Infrastructure\Notifications\BuddyPressNotifier;
use Pandatask\Infrastructure\Notifications\EmailNotifier;
use Pandatask\Infrastructure\Persistence\DeadlineNotificationRepository;

final class DeadlineNotificationHandler {

    public static function init() {
        if ( ! wp_next_scheduled( 'pandat69_check_deadlines' ) ) {
            wp_schedule_event( time(), 'daily', 'pandat69_check_deadlines' );
        }

        add_action( 'pandat69_check_deadlines', array( __CLASS__, 'checkApproachingDeadlines' ) );
        add_action( 'pandat69_check_deadlines', array( __CLASS__, 'checkMissedDeadlines' ) );
    }

    /**
     * Backward-compatible entry point retained for existing cron callbacks.
     */
    public static function check_approaching_deadlines() {
        return self::checkApproachingDeadlines();
    }

    public static function checkApproachingDeadlines() {
        $repository = new DeadlineNotificationRepository();
        $tasks = $repository->findApproaching( wp_date( 'Y-m-d' ) );
        $recipient_map = $repository->findRecipientMap( wp_list_pluck( $tasks, 'id' ), array( 'assignee' ) );
        $stats = array( 'tasks' => count( $tasks ), 'recipients' => 0, 'email_failures' => 0, 'mark_failures' => 0 );

        foreach ( $tasks as $task ) {
            foreach ( $recipient_map[ (int) $task->id ] ?? array() as $user_id ) {
                if ( ! get_userdata( $user_id ) ) {
                    continue;
                }

                $stats['recipients']++;

                if ( ! EmailNotifier::send_deadline_notification( $task->id, $user_id, $task ) ) {
                    $stats['email_failures']++;
                }

                BuddyPressNotifier::add_deadline_notification( $task->id, $user_id );
            }

            if ( ! $repository->markApproachingSent( $task->id, $task->deadline ) ) {
                $stats['mark_failures']++;
            }
        }

        return $stats;
    }

    /**
     * Backward-compatible entry point retained for existing cron callbacks.
     */
    public static function check_missed_deadlines() {
        return self::checkMissedDeadlines();
    }

    public static function checkMissedDeadlines() {
        $repository = new DeadlineNotificationRepository();
        $tasks = $repository->findMissed( wp_date( 'Y-m-d' ) );
        $recipient_map = $repository->findRecipientMap( wp_list_pluck( $tasks, 'id' ), array( 'assignee', 'supervisor' ) );
        $stats = array( 'tasks' => count( $tasks ), 'recipients' => 0, 'email_failures' => 0, 'mark_failures' => 0 );

        foreach ( $tasks as $task ) {
            foreach ( $recipient_map[ (int) $task->id ] ?? array() as $user_id ) {
                if ( ! get_userdata( $user_id ) ) {
                    continue;
                }

                $stats['recipients']++;

                if ( ! EmailNotifier::send_missed_deadline_notification( $task->id, $user_id, $task ) ) {
                    $stats['email_failures']++;
                }
            }

            if ( ! $repository->markMissedSent( $task->id, $task->deadline ) ) {
                $stats['mark_failures']++;
            }
        }

        return $stats;
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( 'pandat69_check_deadlines' );
    }
}
