<?php
/**
 * Deadline notification handler for Task Board
 */
namespace Pandatask\Infrastructure\Scheduler;

use Pandatask\Application\Task\TaskMutationService;
use Pandatask\Infrastructure\Notifications\BuddyPressNotifier;
use Pandatask\Infrastructure\Notifications\EmailNotifier;

if (!defined('ABSPATH')) {
    exit;
}

class DeadlineNotificationHandler {

    public static function init() {
        // Register the cron event if not already scheduled
        if (!wp_next_scheduled('pandat69_check_deadlines')) {
            // Schedule to run daily, consider adjusting the time for lower server load
            // E.g., wp_schedule_event(strtotime('03:00:00'), 'daily', 'pandat69_check_deadlines');
            wp_schedule_event(time(), 'daily', 'pandat69_check_deadlines');
        }

        // Hook the action
        add_action('pandat69_check_deadlines', array(__CLASS__, 'check_approaching_deadlines'));
        add_action('pandat69_check_deadlines', array(__CLASS__, 'check_missed_deadlines'));
    }

    /**
     * Check for tasks with approaching deadlines
     */
    public static function check_approaching_deadlines() {
        global $wpdb;
        $prefix = \Pandatask\Infrastructure\Persistence\DatabaseContext::getDbPrefix();
        $tasks_table = $prefix . 'tasks';
        $assignments_table = $prefix . 'assignments';

        // Get today's date according to WordPress settings
        // --- FIX 1: Use current_time() ---
        $today = wp_date('Y-m-d');
        // --- END FIX 1 ---

        // Find tasks with deadlines approaching according to their notify_days_before setting
        // --- FIX 2: Inline query into prepare() ---
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT t.*, GROUP_CONCAT(DISTINCT a.user_id) as assigned_users
                FROM {$tasks_table} t
                LEFT JOIN {$assignments_table} a ON t.id = a.task_id AND a.role = 'assignee'
                WHERE
                    t.notify_deadline = 1
                    AND t.deadline IS NOT NULL
                    AND t.status != 'done'
                    AND t.archived = 0
                    AND DATE(t.deadline) > %s
                    AND DATEDIFF(t.deadline, %s) = t.notify_days_before
                GROUP BY t.id
                ",
                $today, // For DATE(t.deadline) > %s comparison
                $today  // For DATEDIFF(t.deadline, %s) comparison
            )
        );
        // --- END FIX 2 ---

        // Check for DB errors
        if ($wpdb->last_error) {
             // error_log('Pandatask Deadline Check DB Error: ' . $wpdb->last_error);
             return;
        }

        if (empty($results)) {
            // Optional: Log that the cron ran but found nothing
            // error_log('Pandatask Deadline Check: No approaching deadlines found.');
            return;
        }

        // Process each task with approaching deadline
        foreach ($results as $task) {
            // Skip if no assigned users found after filtering by role and DISTINCT
            if (empty($task->assigned_users)) {
                continue;
            }

            $assigned_users = explode(',', $task->assigned_users);
            $assigned_users = array_filter(array_map('absint', $assigned_users)); // Ensure they are positive integers

             if (empty($assigned_users)) {
                continue;
            }

            // Send notifications
            foreach ($assigned_users as $user_id) {
                // Verify user exists and can receive notifications
                 $user = get_userdata($user_id);
                 if (!$user) {
                     continue;
                 }

                // Send email notification
                EmailNotifier::send_deadline_notification($task->id, $user_id);

                // Send BuddyPress notification
                BuddyPressNotifier::add_deadline_notification($task->id, $user_id);
            }
        }
    }

    /**
     * Check for tasks with missed deadlines and notify assignees and supervisors.
     */
    public static function check_missed_deadlines() {
        global $wpdb;
        $prefix = \Pandatask\Infrastructure\Persistence\DatabaseContext::getDbPrefix();
        $tasks_table = $prefix . 'tasks';
        $assignments_table = $prefix . 'assignments';

        $today = wp_date('Y-m-d');

        // Find tasks with deadlines that have passed, are not done, and not archived.
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT t.*, GROUP_CONCAT(DISTINCT a.user_id) as assigned_and_supervisor_users
                FROM {$tasks_table} t
                LEFT JOIN {$assignments_table} a ON t.id = a.task_id
                WHERE
                    t.deadline IS NOT NULL
                    AND t.deadline < %s
                    AND t.status != 'done'
                    AND t.archived = 0
                    AND t.missed_deadline_notified = 0
                GROUP BY t.id
                ",
                $today
            )
        );

        if ($wpdb->last_error) {
            // error_log('Pandatask Missed Deadline Check DB Error: ' . $wpdb->last_error);
            return;
        }

        if (empty($results)) {
            return;
        }

        $task_mutation_service = new TaskMutationService();

        foreach ($results as $task) {
            if (empty($task->assigned_and_supervisor_users)) {
                $task_mutation_service->updateTask($task->id, ['missed_deadline_notified' => 1], '', 0);
                continue;
            }

            // Get a unique list of all assignees and supervisors
            $all_users = explode(',', $task->assigned_and_supervisor_users);
            $all_users = array_unique(array_filter(array_map('absint', $all_users)));

            if (empty($all_users)) {
                $task_mutation_service->updateTask($task->id, ['missed_deadline_notified' => 1], '', 0);
                continue;
            }

            // Send notifications
            foreach ($all_users as $user_id) {
                $user = get_userdata($user_id);
                if (!$user) {
                    continue;
                }

                // Send email notification for missed deadline
                EmailNotifier::send_missed_deadline_notification($task->id, $user_id);
            }

            // After notifying all users for this task, set the flag.
            $task_mutation_service->updateTask($task->id, ['missed_deadline_notified' => 1], '', 0);
        }
    }

    /**
     * Deactivation cleanup
     */
    public static function deactivate() {
        // Clear the scheduled event
        $timestamp = wp_next_scheduled('pandat69_check_deadlines');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'pandat69_check_deadlines');
        }
    }
}
