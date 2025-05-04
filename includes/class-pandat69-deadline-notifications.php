<?php
/**
 * Deadline notification handler for Task Board
 */
if (!defined('ABSPATH')) {
    exit;
}

class Pandat69_Deadline_Notifications {

    public static function init() {
        // Register the cron event if not already scheduled
        if (!wp_next_scheduled('pandat69_check_deadlines')) {
            // Schedule to run daily, consider adjusting the time for lower server load
            // E.g., wp_schedule_event(strtotime('03:00:00'), 'daily', 'pandat69_check_deadlines');
            wp_schedule_event(time(), 'daily', 'pandat69_check_deadlines');
        }

        // Hook the action
        add_action('pandat69_check_deadlines', array(__CLASS__, 'check_approaching_deadlines'));
    }

    /**
     * Check for tasks with approaching deadlines
     */
    public static function check_approaching_deadlines() {
        global $wpdb;
        $prefix = Pandat69_DB::get_db_prefix();
        $tasks_table = $prefix . 'tasks';
        $assignments_table = $prefix . 'assignments';

        // Get today's date according to WordPress settings
        // --- FIX 1: Use current_time() ---
        $today = current_time('Y-m-d');
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
             error_log('Pandatask Deadline Check DB Error: ' . $wpdb->last_error);
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
                if (class_exists('Pandat69_Email')) {
                    Pandat69_Email::send_deadline_notification($task->id, $user_id);
                }

                // Send BuddyPress notification
                if (class_exists('Pandat69_Notifications') && Pandat69_Notifications::is_bp_notifications_active()) {
                    Pandat69_Notifications::add_deadline_notification($task->id, $user_id);
                }
            }
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

// Initialize
Pandat69_Deadline_Notifications::init();