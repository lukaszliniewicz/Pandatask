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
        
        // Get today's date
        $today = date('Y-m-d');
        
        // Find tasks with deadlines approaching according to their notify_days_before setting
        $query = "
            SELECT t.*, GROUP_CONCAT(a.user_id) as assigned_users
            FROM {$tasks_table} t
            LEFT JOIN {$assignments_table} a ON t.id = a.task_id
            WHERE 
                t.notify_deadline = 1 
                AND t.deadline IS NOT NULL 
                AND t.status != 'done' 
                AND DATE(t.deadline) > %s
                AND DATEDIFF(t.deadline, %s) = t.notify_days_before
            GROUP BY t.id
        ";
        
        $results = $wpdb->get_results($wpdb->prepare($query, $today, $today));
        
        if (empty($results)) {
            return;
        }
        
        // Process each task with approaching deadline
        foreach ($results as $task) {
            // Skip if no assigned users
            if (empty($task->assigned_users)) {
                continue;
            }
            
            $assigned_users = explode(',', $task->assigned_users);
            
            // Send notifications
            foreach ($assigned_users as $user_id) {
                // Send email notification
                if (class_exists('Pandat69_Email')) {
                    Pandat69_Email::send_deadline_notification($task->id, $user_id);
                }
                
                // Send BuddyPress notification
                if (class_exists('Pandat69_Notifications')) {
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