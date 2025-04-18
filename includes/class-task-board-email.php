<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Task_Board_Email {
    /**
     * Debug logging function
     */
    public static function log_message($message) {
        // Log to a file in the plugin directory
        $log_file = TBP_PLUGIN_DIR . 'email-debug.log';
        $timestamp = current_time('mysql');
        error_log("[{$timestamp}] {$message}\n", 3, $log_file);
    }
    
    /**
     * Test mail function - call this directly to verify email functionality
     */
    public static function test_mail() {
        $admin_email = get_option('admin_email');
        $site_name = get_bloginfo('name');
        
        self::log_message("Running mail test to admin: {$admin_email}");
        
        $subject = 'Task Board Plugin - Mail Test';
        $message = 'This is a test email from the Task Board Plugin to verify email functionality.';
        $headers = array('From: ' . $site_name . ' <' . $admin_email . '>');
        
        $result = wp_mail($admin_email, $subject, $message, $headers);
        self::log_message("Test email result: " . ($result ? "SUCCESS" : "FAILED"));
        
        return $result;
    }

    /**
     * Send notification to newly assigned users
     * 
     * @param int $task_id The task ID
     * @param array $new_user_ids Array of newly assigned user IDs
     * @return void
     */
    public static function send_assignment_notification($task_id, $new_user_ids) {
        self::log_message("Assignment notification triggered for task ID: {$task_id}, users: " . implode(',', $new_user_ids));
        
        if (empty($new_user_ids)) {
            self::log_message("No new users to notify - exiting");
            return;
        }
        
        // Get task details
        $task = Task_Board_DB::get_task($task_id);
        if (!$task) {
            self::log_message("Task not found with ID: {$task_id}");
            return;
        }
        
        self::log_message("Retrieved task: {$task->name}");
        
        $admin_email = get_option('admin_email');
        $site_name = get_bloginfo('name');
        $site_url = home_url();
        
        // Try to determine task URL (if we can find a page with shortcode)
        $task_url = self::get_task_board_url($task->board_name);
        self::log_message("Task URL resolved to: " . ($task_url ? $task_url : "Not found"));
        
        foreach ($new_user_ids as $user_id) {
            $user = get_userdata($user_id);
            if (!$user || !is_a($user, 'WP_User') || !$user->user_email) {
                self::log_message("User {$user_id} not found or has no email - skipping");
                continue;
            }
            
            self::log_message("Preparing email for user: {$user->user_email}");
            
            $subject = sprintf(__('You have been assigned to a task on %s', 'task-board-plugin'), $site_name);
            
            // Plain text email
            $text_message = sprintf(
                __('Hello %s,', 'task-board-plugin') . "\n\n" .
                __('You have been assigned to the following task:', 'task-board-plugin') . "\n\n" .
                __('Task: %s', 'task-board-plugin') . "\n" .
                __('Status: %s', 'task-board-plugin') . "\n" .
                __('Priority: %s', 'task-board-plugin') . "\n" .
                __('Deadline: %s', 'task-board-plugin') . "\n\n" .
                __('Please login to view the task details and update its status as needed.', 'task-board-plugin') . "\n\n" .
                ($task_url ? __('View Task Board: %s', 'task-board-plugin') . "\n\n" : '') .
                __('Regards,', 'task-board-plugin') . "\n" .
                '%s',
                $user->display_name,
                $task->name,
                ucfirst(str_replace('-', ' ', $task->status)),
                $task->priority,
                $task->deadline ?: __('No deadline', 'task-board-plugin'),
                $task_url,
                $site_name
            );
            
            // HTML email
            $html_message = sprintf(
                '<p>' . __('Hello %s,', 'task-board-plugin') . '</p>' .
                '<p>' . __('You have been assigned to the following task:', 'task-board-plugin') . '</p>' .
                '<table style="border-collapse: collapse; width: 100%%; margin-bottom: 20px;">' .
                    '<tr><td style="padding: 8px; border: 1px solid #ddd; width: 30%%"><strong>' . __('Task', 'task-board-plugin') . '</strong></td><td style="padding: 8px; border: 1px solid #ddd;">%s</td></tr>' .
                    '<tr><td style="padding: 8px; border: 1px solid #ddd;"><strong>' . __('Status', 'task-board-plugin') . '</strong></td><td style="padding: 8px; border: 1px solid #ddd;">%s</td></tr>' .
                    '<tr><td style="padding: 8px; border: 1px solid #ddd;"><strong>' . __('Priority', 'task-board-plugin') . '</strong></td><td style="padding: 8px; border: 1px solid #ddd;">%s</td></tr>' .
                    '<tr><td style="padding: 8px; border: 1px solid #ddd;"><strong>' . __('Deadline', 'task-board-plugin') . '</strong></td><td style="padding: 8px; border: 1px solid #ddd;">%s</td></tr>' .
                '</table>' .
                '<p>' . __('Please login to view the task details and update its status as needed.', 'task-board-plugin') . '</p>' .
                ($task_url ? '<p><a href="' . $task_url . '" style="background-color: #4CAF50; color: white; padding: 10px 15px; text-decoration: none; border-radius: 4px; display: inline-block;">' . __('View Task Board', 'task-board-plugin') . '</a></p>' : '') .
                '<p>' . __('Regards,', 'task-board-plugin') . '<br>%s</p>',
                $user->display_name,
                esc_html($task->name),
                esc_html(ucfirst(str_replace('-', ' ', $task->status))),
                esc_html($task->priority),
                esc_html($task->deadline ?: __('No deadline', 'task-board-plugin')),
                esc_html($site_name)
            );
            
            self::send_email($user->user_email, $subject, $text_message, $html_message);
        }
    }
    
    /**
     * Send notification to assigned users when a comment is added
     * 
     * @param int $task_id The task ID
     * @param int $comment_user_id The user ID who added the comment
     * @param string $comment_text The comment text
     * @return void
     */
    public static function send_comment_notification($task_id, $comment_user_id, $comment_text) {
        self::log_message("Comment notification triggered for task ID: {$task_id}, commenter: {$comment_user_id}");
        
        // Get task details
        $task = Task_Board_DB::get_task($task_id);
        if (!$task || empty($task->assigned_user_ids)) {
            self::log_message("Task not found or has no assignees: {$task_id}");
            return;
        }
        
        self::log_message("Retrieved task: {$task->name} with " . count($task->assigned_user_ids) . " assignees");
        
        $admin_email = get_option('admin_email');
        $site_name = get_bloginfo('name');
        $commenter = get_userdata($comment_user_id);
        
        if (!$commenter || !is_a($commenter, 'WP_User')) {
            $commenter_name = __('A user', 'task-board-plugin');
            self::log_message("Commenter user not found: {$comment_user_id}");
        } else {
            $commenter_name = $commenter->display_name;
            self::log_message("Commenter identified as: {$commenter_name}");
        }
        
        // Clean up comment text for email
        $clean_comment = wp_strip_all_tags(preg_replace('/<a class="tbp-mention"[^>]*>@([^<]+)<\/a>/', '@$1', $comment_text));
        self::log_message("Cleaned comment text: " . substr($clean_comment, 0, 100) . (strlen($clean_comment) > 100 ? '...' : ''));
        
        // Try to determine task URL
        $task_url = self::get_task_board_url($task->board_name);
        self::log_message("Task URL resolved to: " . ($task_url ? $task_url : "Not found"));
        
        foreach ($task->assigned_user_ids as $user_id) {
            // Skip sending notification to the commenter themselves
            if ($user_id == $comment_user_id) {
                self::log_message("Skipping notification to commenter (user ID: {$user_id})");
                continue;
            }
            
            $user = get_userdata($user_id);
            if (!$user || !is_a($user, 'WP_User') || !$user->user_email) {
                self::log_message("User {$user_id} not found or has no email - skipping");
                continue;
            }
            
            self::log_message("Preparing comment notification email for: {$user->user_email}");
            
            $subject = sprintf(__('New comment on task: %s', 'task-board-plugin'), $task->name);
            
            // Plain text email
            $text_message = sprintf(
                __('Hello %s,', 'task-board-plugin') . "\n\n" .
                __('%s has commented on a task you are assigned to:', 'task-board-plugin') . "\n\n" .
                __('Task: %s', 'task-board-plugin') . "\n" .
                __('Comment: %s', 'task-board-plugin') . "\n\n" .
                __('Please login to view the task and respond if needed.', 'task-board-plugin') . "\n\n" .
                ($task_url ? __('View Task Board: %s', 'task-board-plugin') . "\n\n" : '') .
                __('Regards,', 'task-board-plugin') . "\n" .
                '%s',
                $user->display_name,
                $commenter_name,
                $task->name,
                $clean_comment,
                $task_url,
                $site_name
            );
            
            // HTML email
            $html_message = sprintf(
                '<p>' . __('Hello %s,', 'task-board-plugin') . '</p>' .
                '<p>' . __('%s has commented on a task you are assigned to:', 'task-board-plugin') . '</p>' .
                '<table style="border-collapse: collapse; width: 100%%; margin-bottom: 20px;">' .
                    '<tr><td style="padding: 8px; border: 1px solid #ddd; width: 30%%"><strong>' . __('Task', 'task-board-plugin') . '</strong></td><td style="padding: 8px; border: 1px solid #ddd;">%s</td></tr>' .
                    '<tr><td style="padding: 8px; border: 1px solid #ddd;"><strong>' . __('Comment', 'task-board-plugin') . '</strong></td><td style="padding: 8px; border: 1px solid #ddd;">%s</td></tr>' .
                '</table>' .
                '<p>' . __('Please login to view the task and respond if needed.', 'task-board-plugin') . '</p>' .
                ($task_url ? '<p><a href="' . $task_url . '" style="background-color: #4CAF50; color: white; padding: 10px 15px; text-decoration: none; border-radius: 4px; display: inline-block;">' . __('View Task Board', 'task-board-plugin') . '</a></p>' : '') .
                '<p>' . __('Regards,', 'task-board-plugin') . '<br>%s</p>',
                $user->display_name,
                esc_html($commenter_name),
                esc_html($task->name),
                esc_html($clean_comment),
                esc_html($site_name)
            );
            
            self::send_email($user->user_email, $subject, $text_message, $html_message);
        }
    }
    
    /**
     * Helper function to send email with both HTML and plain text versions
     */
    private static function send_email($to, $subject, $text_message, $html_message) {
        $admin_email = get_option('admin_email');
        $site_name = get_bloginfo('name');
        
        self::log_message("Attempting to send email to: {$to}, subject: {$subject}");
        
        // SIMPLIFIED EMAIL METHOD FOR TROUBLESHOOTING
        // Just using HTML email format for now to test
        $headers = array(
            'From: ' . $site_name . ' <' . $admin_email . '>',
            'Content-Type: text/html; charset=UTF-8'
        );
        
        foreach ($headers as $header) {
            self::log_message("Header: {$header}");
        }
        
        // For debug purposes, check that WordPress functions exist
        if (!function_exists('wp_mail')) {
            self::log_message("ERROR: wp_mail function does not exist!");
            return false;
        }
        
        $result = wp_mail($to, $subject, "<html><body>" . $html_message . "</body></html>", $headers);
        self::log_message("Email send result: " . ($result ? "SUCCESS" : "FAILED"));
        
        // If failed, try to get more information
        if (!$result) {
            global $phpmailer;
            if (isset($phpmailer->ErrorInfo) && !empty($phpmailer->ErrorInfo)) {
                self::log_message("Mail error: " . $phpmailer->ErrorInfo);
            } else {
                self::log_message("No specific error information available from PHPMailer");
            }
        }
        
        return $result;
        
        /* Original multipart code - uncomment when basic email works
        // Generate a boundary for the multipart message
        $boundary = md5(time());
        
        $headers = array(
            'From: ' . $site_name . ' <' . $admin_email . '>',
            'Content-Type: multipart/alternative; boundary=' . $boundary
        );
        
        // Create the multipart message body
        $message = "--$boundary\r\n" .
                   "Content-Type: text/plain; charset=UTF-8\r\n" .
                   "Content-Transfer-Encoding: 8bit\r\n\r\n" .
                   $text_message . "\r\n\r\n" .
                   "--$boundary\r\n" .
                   "Content-Type: text/html; charset=UTF-8\r\n" .
                   "Content-Transfer-Encoding: 8bit\r\n\r\n" .
                   "<html><body>" . $html_message . "</body></html>\r\n\r\n" .
                   "--$boundary--";
        
        return wp_mail($to, $subject, $message, $headers);
        */
    }
    
    /**
     * Helper function to try and find a URL for the task board
     */
    private static function get_task_board_url($board_name) {
        global $wpdb;
        
        self::log_message("Looking for task board URL for board: {$board_name}");
        
        // Check if this is a BuddyPress group board (named like "group_123")
        if (preg_match('/^group_(\d+)$/', $board_name, $matches)) {
            $group_id = intval($matches[1]);
            
            if ($group_id > 0 && function_exists('bp_get_group_permalink')) {
                self::log_message("Detected board is for BuddyPress group ID: {$group_id}");
                $group = groups_get_group($group_id);
                
                if ($group && !empty($group->id)) {
                    $group_url = bp_get_group_permalink($group);
                    $final_url = trailingslashit($group_url) . 'tasks';
                    
                    self::log_message("Constructed group task board URL: {$final_url}");
                    return $final_url;
                }
            }
        }
        
        // Fallback to the original post content search if not a group board
        // Try to find a page with the shortcode for this board and extract group_id and page_name
        $shortcode_pattern = '[task_board board_name="' . $board_name . '"';
        $alt_shortcode_pattern = "[task_board board_name='" . $board_name . "'";
        
        $posts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_content FROM $wpdb->posts 
                WHERE (post_content LIKE %s OR post_content LIKE %s) 
                AND post_status = 'publish' 
                LIMIT 1",
                '%' . $wpdb->esc_like($shortcode_pattern) . '%',
                '%' . $wpdb->esc_like($alt_shortcode_pattern) . '%'
            )
        );
        
        if (!empty($posts)) {
            $post_content = $posts[0]->post_content;
            
            // Extract group_id from shortcode
            $group_id = 0;
            if (preg_match('/group_id=[\"\']?(\d+)[\"\']?/i', $post_content, $group_matches)) {
                $group_id = intval($group_matches[1]);
            }
            
            // Extract page_name from shortcode
            $page_name = '';
            if (preg_match('/page_name=[\"\']?([^\"\'\s\]]+)[\"\']?/i', $post_content, $page_matches)) {
                $page_name = sanitize_title($page_matches[1]);
            }
            
            // If we have both group_id and page_name, construct the BuddyPress group URL
            if ($group_id > 0 && !empty($page_name)) {
                if (function_exists('bp_get_group_permalink')) {
                    $group_url = bp_get_group_permalink(groups_get_group($group_id));
                    if ($group_url) {
                        // Add the page name to the URL
                        $final_url = trailingslashit($group_url) . $page_name;
                        self::log_message("Constructed task board URL from group and page: {$final_url}");
                        return $final_url;
                    }
                }
            }
            
            // Fallback to regular page permalink if group URL construction fails
            $url = get_permalink($posts[0]->ID);
            self::log_message("Found task board URL from page content: {$url}");
            return $url;
        }
        
        self::log_message("Could not find a published page with the task board shortcode");
        return false;
    }
}