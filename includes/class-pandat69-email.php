<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Pandat69_Email {

    /**
     * Send notification to newly assigned users
     * 
     * @param int $task_id The task ID
     * @param array $new_user_ids Array of newly assigned user IDs
     * @param string $role The role of the assignment ('assignee' or 'supervisor')
     * @return void
     */
    public static function send_assignment_notification($task_id, $new_user_ids, $role = 'assignee') {
        if (empty($new_user_ids)) {
            return;
        }
        
        // Get task details
        $task = Pandat69_DB::get_task($task_id);
        if (!$task) {
            return;
        }
        
        $admin_email = get_option('admin_email'); // Used for 'From' address fallback
        $site_name = get_bloginfo('name');
        $site_url = home_url();
        $role_display = ($role === 'supervisor') ? 'supervisor for' : 'to';
        
        // Try to determine task URL
        $task_url = self::get_task_board_url($task->board_name);
        
        foreach ($new_user_ids as $user_id) {
            $user = get_userdata($user_id);
            if (!$user || !is_a($user, 'WP_User') || !$user->user_email) {
                continue;
            }
            
            // translators: %1$s: role (to/supervisor for), %2$s: Site name.
            $subject = sprintf(__('You have been assigned as %1$s a task on %2$s', 'pandatask'), $role_display, $site_name);
            
            // --- Plain text email ---
            // translators: %s: User display name.
            $greeting = sprintf( __('Hello %s,', 'pandatask'), $user->display_name );
            // translators: %s: role (to/supervisor for)
            $assignment_intro = sprintf( __('You have been assigned as %s the following task:', 'pandatask'), $role_display );
            // translators: %s: Task name.
            $task_line = sprintf( __('Task: %s', 'pandatask'), $task->name );
            // translators: %s: Task status (e.g., "Pending", "In Progress").
            $status_line = sprintf( __('Status: %s', 'pandatask'), ucfirst(str_replace('-', ' ', $task->status)) );
            // translators: %s: Task priority number (1-10).
            $priority_line = sprintf( __('Priority: %s', 'pandatask'), $task->priority );
            // translators: %s: Task deadline string (e.g., "YYYY-MM-DD" or "No deadline").
            $deadline_line = sprintf( __('Deadline: %s', 'pandatask'), $task->deadline ?: __('No deadline', 'pandatask') );
            $instructions = __('Please login to view the task details and update its status as needed.', 'pandatask');
            $task_link_line = '';
            if ($task_url) {
                // translators: %s: URL to the task board.
                $task_link_line = sprintf( __('View Task Board: %s', 'pandatask'), $task_url ) . "\n\n";
            }
            $regards = __('Regards,', 'pandatask');
            $signature = $site_name; 

            $text_message = $greeting . "\n\n" .
                            $assignment_intro . "\n\n" .
                            $task_line . "\n" .
                            $status_line . "\n" .
                            $priority_line . "\n" .
                            $deadline_line . "\n\n" .
                            $instructions . "\n\n" .
                            $task_link_line . // Already includes \n\n if present
                            $regards . "\n" .
                            $signature; // Use the variable directly
            
            // --- HTML email ---
            $html_message = '<p>' . esc_html($greeting) . '</p>' .
                            '<p>' . esc_html($assignment_intro) . '</p>' .
                            '<table style="border-collapse: collapse; width: 100%%; margin-bottom: 20px; border: 1px solid #ddd;">' .
                                '<tr><td style="padding: 8px; border: 1px solid #ddd; width: 30%"><strong>' . esc_html(__('Task', 'pandatask')) . '</strong></td><td style="padding: 8px; border: 1px solid #ddd;">' . esc_html($task->name) . '</td></tr>' .
                                '<tr><td style="padding: 8px; border: 1px solid #ddd;"><strong>' . esc_html(__('Status', 'pandatask')) . '</strong></td><td style="padding: 8px; border: 1px solid #ddd;">' . esc_html(ucfirst(str_replace('-', ' ', $task->status))) . '</td></tr>' .
                                '<tr><td style="padding: 8px; border: 1px solid #ddd;"><strong>' . esc_html(__('Priority', 'pandatask')) . '</strong></td><td style="padding: 8px; border: 1px solid #ddd;">' . esc_html($task->priority) . '</td></tr>' .
                                '<tr><td style="padding: 8px; border: 1px solid #ddd;"><strong>' . esc_html(__('Deadline', 'pandatask')) . '</strong></td><td style="padding: 8px; border: 1px solid #ddd;">' . esc_html($task->deadline ?: __('No deadline', 'pandatask')) . '</td></tr>' .
                            '</table>' .
                            '<p>' . esc_html($instructions) . '</p>' .
                            ($task_url ? '<p><a href="' . esc_url($task_url) . '" style="background-color: #384D68; color: white; padding: 10px 15px; text-decoration: none; border-radius: 4px; display: inline-block;">' . esc_html(__('View Task Board', 'pandatask')) . '</a></p>' : '') .
                            '<p>' . esc_html($regards) . '<br>' . esc_html($site_name) . '</p>';
            
            self::send_email($user->user_email, $subject, $text_message, $html_message);
        }
    }
    
    /**
     * Send notification to mentioned users
     * 
     * @param int $task_id The task ID
     * @param array $mentioned_user_ids Array of mentioned user IDs
     * @param int $mentioner_id The user ID who mentioned them
     * @param string $comment_text The comment text containing the mention
     * @return void
     */
    public static function send_mention_notification($task_id, $mentioned_user_ids, $mentioner_id, $comment_text) {
        if (empty($mentioned_user_ids)) {
            return;
        }
        
        // Get task details
        $task = Pandat69_DB::get_task($task_id);
        if (!$task) {
            return;
        }
        
        $admin_email = get_option('admin_email');
        $site_name = get_bloginfo('name');
        $mentioner = get_userdata($mentioner_id);
        
        if (!$mentioner || !is_a($mentioner, 'WP_User')) {
            $mentioner_name = __('A user', 'pandatask');
        } else {
            $mentioner_name = $mentioner->display_name;
        }
        
        // Clean up comment text for plain text email (strip HTML)
        $plain_text_comment = wp_strip_all_tags(preg_replace('/<a\s+[^>]*class="pandat69-mention"[^>]*>@([^<]+)<\/a>/i', '@$1', $comment_text));
        
        // Try to determine task URL
        $task_url = self::get_task_board_url($task->board_name);
        
        foreach ($mentioned_user_ids as $user_id) {
            $user = get_userdata($user_id);
            if (!$user || !is_a($user, 'WP_User') || !$user->user_email) {
                continue;
            }
            
            // translators: %s: Task name
            $subject = sprintf(__('You were mentioned in task: %s', 'pandatask'), $task->name);
            
            // --- Plain text email ---
            // translators: %s: User display name
            $greeting = sprintf(__('Hello %s,', 'pandatask'), $user->display_name);
            // translators: %s: Name of the user who mentioned them
            $mention_intro = sprintf(__('%s mentioned you in a comment on a task:', 'pandatask'), $mentioner_name);
            // translators: %s: Task name
            $task_line = sprintf(__('Task: %s', 'pandatask'), $task->name);
            // translators: %s: The plain text content of the comment
            $comment_line = sprintf(__('Comment: %s', 'pandatask'), $plain_text_comment);
            $instructions = __('Please login to view the task and respond if needed.', 'pandatask');
            $task_link_line = '';
            if ($task_url) {
                // translators: %s: URL to the task board
                $task_link_line = sprintf(__('View Task Board: %s', 'pandatask'), $task_url) . "\n\n";
            }
            $regards = __('Regards,', 'pandatask');
            $signature = $site_name;
    
            $text_message = $greeting . "\n\n" .
                            $mention_intro . "\n\n" .
                            $task_line . "\n" .
                            $comment_line . "\n\n" .
                            $instructions . "\n\n" .
                            $task_link_line .
                            $regards . "\n" .
                            $signature;
            
            // --- HTML email ---
            $html_message = '<p>' . esc_html($greeting) . '</p>' .
                            '<p>' . esc_html($mention_intro) . '</p>' .
                            '<table style="border-collapse: collapse; width: 100%; margin-bottom: 20px; border: 1px solid #ddd;">' .
                                '<tr><td style="padding: 8px; border: 1px solid #ddd; width: 30%"><strong>' . esc_html(__('Task', 'pandatask')) . '</strong></td><td style="padding: 8px; border: 1px solid #ddd;">' . esc_html($task->name) . '</td></tr>' .
                                '<tr><td style="padding: 8px; border: 1px solid #ddd;"><strong>' . esc_html(__('Comment', 'pandatask')) . '</strong></td><td style="padding: 8px; border: 1px solid #ddd;">' . wp_kses_post($comment_text) . '</td></tr>' .
                            '</table>' .
                            '<p>' . esc_html($instructions) . '</p>' .
                            ($task_url ? '<p><a href="' . esc_url($task_url) . '" style="background-color: #384D68; color: white; padding: 10px 15px; text-decoration: none; border-radius: 4px; display: inline-block;">' . esc_html(__('View Task Board', 'pandatask')) . '</a></p>' : '') .
                            '<p>' . esc_html($regards) . '<br>' . esc_html($site_name) . '</p>';
            
            self::send_email($user->user_email, $subject, $text_message, $html_message);
        }
    }    
    
    
    /**
     * Send notification to assigned users when a comment is added
     * 
     * @param int $task_id The task ID
     * @param int $comment_user_id The user ID who added the comment
     * @param string $comment_text The comment text (HTML allowed from DB)
     * @return void
     */
    public static function send_comment_notification($task_id, $comment_user_id, $comment_text) {
        // Get task details
        $task = Pandat69_DB::get_task($task_id);
        if (!$task) {
            return;
        }
        
        // Collect all notification recipients (assignees and supervisors)
        $notification_recipients = array();

        // Add assignees
        if (!empty($task->assigned_user_ids)) {
            $notification_recipients = array_merge($notification_recipients, $task->assigned_user_ids);
        }

        // Add supervisors
        if (!empty($task->supervisor_user_ids)) {
            $notification_recipients = array_merge($notification_recipients, $task->supervisor_user_ids);
        }

        // Remove duplicates
        $notification_recipients = array_unique($notification_recipients);

        // If no recipients, exit
        if (empty($notification_recipients)) {
            return;
        }
        
        $admin_email = get_option('admin_email'); // Used for 'From' address fallback
        $site_name = get_bloginfo('name');
        $commenter = get_userdata($comment_user_id);
        
        if (!$commenter || !is_a($commenter, 'WP_User')) {
            // translators: Fallback name if the user who commented cannot be found.
            $commenter_name = __('A user', 'pandatask');
        } else {
            $commenter_name = $commenter->display_name;
        }
        
        // Clean up comment text for *plain text* email (keep HTML for HTML version)
        $plain_text_comment = wp_strip_all_tags(preg_replace('/<a\s+[^>]*class="pandat69-mention"[^>]*>@([^<]+)<\/a>/i', '@$1', $comment_text));
        
        // Try to determine task URL
        $task_url = self::get_task_board_url($task->board_name);
        
        foreach ($notification_recipients as $user_id) {
            // Skip sending notification to the commenter themselves
            if ($user_id == $comment_user_id) {
                continue;
            }
            
            $user = get_userdata($user_id);
            if (!$user || !is_a($user, 'WP_User') || !$user->user_email) {
                continue;
            }
            
            // translators: %s: Task name.
            $subject = sprintf(__('New comment on task: %s', 'pandatask'), $task->name);
            
            // --- Plain text email ---
            // translators: %s: User display name.
            $greeting = sprintf( __('Hello %s,', 'pandatask'), $user->display_name );
            // translators: %s: Name of the user who added the comment.
            $comment_intro = sprintf( __('%s has commented on a task you are assigned to:', 'pandatask'), $commenter_name );
            // translators: %s: Task name.
            $task_line = sprintf( __('Task: %s', 'pandatask'), $task->name );
            // translators: %s: The plain text content of the comment.
            $comment_line = sprintf( __('Comment: %s', 'pandatask'), $plain_text_comment );
            $instructions = __('Please login to view the task and respond if needed.', 'pandatask');
            $task_link_line = '';
            if ($task_url) {
                // translators: %s: URL to the task board.
                $task_link_line = sprintf( __('View Task Board: %s', 'pandatask'), $task_url ) . "\n\n";
            }
            $regards = __('Regards,', 'pandatask');
            $signature = $site_name;

            $text_message = $greeting . "\n\n" .
                            $comment_intro . "\n\n" .
                            $task_line . "\n" .
                            $comment_line . "\n\n" .
                            $instructions . "\n\n" .
                            $task_link_line .
                            $regards . "\n" .
                            $signature; // Use the variable directly
            
            // --- HTML email ---
            $html_message = '<p>' . esc_html($greeting) . '</p>' .
                            '<p>' . esc_html($comment_intro) . '</p>' .
                            '<table style="border-collapse: collapse; width: 100%%; margin-bottom: 20px; border: 1px solid #ddd;">' .
                                '<tr><td style="padding: 8px; border: 1px solid #ddd; width: 30%"><strong>' . esc_html(__('Task', 'pandatask')) . '</strong></td><td style="padding: 8px; border: 1px solid #ddd;">' . esc_html($task->name) . '</td></tr>' .
                                '<tr><td style="padding: 8px; border: 1px solid #ddd;"><strong>' . esc_html(__('Comment', 'pandatask')) . '</strong></td><td style="padding: 8px; border: 1px solid #ddd;">' . wp_kses_post($comment_text) . '</td></tr>' . // Use wp_kses_post again for safety
                            '</table>' .
                            '<p>' . esc_html($instructions) . '</p>' .
                            ($task_url ? '<p><a href="' . esc_url($task_url) . '" style="background-color: #384D68; color: white; padding: 10px 15px; text-decoration: none; border-radius: 4px; display: inline-block;">' . esc_html(__('View Task Board', 'pandatask')) . '</a></p>' : '') .
                            '<p>' . esc_html($regards) . '<br>' . esc_html($site_name) . '</p>';
            
            self::send_email($user->user_email, $subject, $text_message, $html_message);
        }
    }
    
    /**
     * Helper function to send email with both HTML and plain text versions
     */
    private static function send_email($to, $subject, $text_message, $html_message) {
        $admin_email = get_option('admin_email');
        $site_name = get_bloginfo('name');
        
        // Use WordPress default From headers
        $from_name = apply_filters('wp_mail_from_name', get_bloginfo('name'));
        $from_email = apply_filters('wp_mail_from', $admin_email);
        
        // Gmail API compatible headers - simpler is better
        $headers = array(
            'From: ' . $from_name . ' <' . $from_email . '>',
            'Content-Type: text/html; charset=UTF-8'
        );
        
        // Gmail API prefers simple HTML messages
        $message = $html_message; // Just use the HTML part
        
        // Send email with error handling
        try {
            $result = wp_mail($to, $subject, $message, $headers);
            return $result;
        } catch (Exception $e) {
            error_log('Pandatask email error: ' . $e->getMessage());
            return false;
        }
    }
    /**
     * Send notification for approaching deadline
     *
     * @param int $task_id The task ID
     * @param int $user_id The user ID to notify
     * @return bool Whether the email was sent
     */
    public static function send_deadline_notification($task_id, $user_id) {
        // Get task details
        $task = Pandat69_DB::get_task($task_id);
        if (!$task || !$task->deadline) {
            return false;
        }

        $user = get_userdata($user_id);
        if (!$user || !is_a($user, 'WP_User') || !$user->user_email) {
            return false;
        }

        $admin_email = get_option('admin_email');
        $site_name = get_bloginfo('name');
        $days_remaining = max(1, floor((strtotime($task->deadline) - time()) / DAY_IN_SECONDS));

        // Try to determine task URL
        $task_url = self::get_task_board_url($task->board_name);

        // translators: %s: Site name
        $subject = sprintf(__('Deadline approaching for task on %s', 'pandatask'), $site_name);

        // --- Plain text email ---
        // translators: %s: User display name
        $greeting = sprintf(__('Hello %s,', 'pandatask'), $user->display_name);

        // Separate translation from formatting for the deadline intro
        /* translators: %1$s: Task name, %2$d: Number of days. */
        $deadline_format = _n(
            'The deadline for task "%1$s" is approaching (%2$d day remaining).',
            'The deadline for task "%1$s" is approaching (%2$d days remaining).',
            $days_remaining,
            'pandatask'
        );
        $deadline_intro = sprintf(
            $deadline_format,
            $task->name,
            $days_remaining
        );

        // translators: %s: Task name
        $task_line = sprintf(__('Task: %s', 'pandatask'), $task->name);
        // translators: %s: Task deadline date
        $deadline_line = sprintf(__('Deadline: %s', 'pandatask'), $task->deadline);
        // translators: %s: Task status
        $status_line = sprintf(__('Status: %s', 'pandatask'), ucfirst(str_replace('-', ' ', $task->status)));
        // translators: %s: Task priority
        $priority_line = sprintf(__('Priority: %s', 'pandatask'), $task->priority);

        $instructions = __('Please login to view the task details and update its status as needed.', 'pandatask');
        $task_link_line = '';
        if ($task_url) {
            // translators: %s: URL to the task board
            $task_link_line = sprintf(__('View Task Board: %s', 'pandatask'), $task_url) . "\n\n";
        }
        $regards = __('Regards,', 'pandatask');
        $signature = $site_name;

        $text_message = $greeting . "\n\n" .
                        $deadline_intro . "\n\n" .
                        $task_line . "\n" .
                        $deadline_line . "\n" .
                        $status_line . "\n" .
                        $priority_line . "\n\n" .
                        $instructions . "\n\n" .
                        $task_link_line .
                        $regards . "\n" .
                        $signature;

        // --- HTML email ---
        // Note: $deadline_intro already contains the correctly formatted string
        $html_message = '<p>' . esc_html($greeting) . '</p>' .
                        '<p>' . esc_html($deadline_intro) . '</p>' . // Use the generated intro string
                        '<table style="border-collapse: collapse; width: 100%; margin-bottom: 20px; border: 1px solid #ddd;">' .
                            '<tr><td style="padding: 8px; border: 1px solid #ddd; width: 30%"><strong>' . esc_html__('Task', 'pandatask') . '</strong></td><td style="padding: 8px; border: 1px solid #ddd;">' . esc_html($task->name) . '</td></tr>' .
                            '<tr><td style="padding: 8px; border: 1px solid #ddd;"><strong>' . esc_html__('Deadline', 'pandatask') . '</strong></td><td style="padding: 8px; border: 1px solid #ddd;">' . esc_html($task->deadline) . '</td></tr>' .
                            '<tr><td style="padding: 8px; border: 1px solid #ddd;"><strong>' . esc_html__('Status', 'pandatask') . '</strong></td><td style="padding: 8px; border: 1px solid #ddd;">' . esc_html(ucfirst(str_replace('-', ' ', $task->status))) . '</td></tr>' .
                            '<tr><td style="padding: 8px; border: 1px solid #ddd;"><strong>' . esc_html__('Priority', 'pandatask') . '</strong></td><td style="padding: 8px; border: 1px solid #ddd;">' . esc_html($task->priority) . '</td></tr>' .
                        '</table>' .
                        '<p>' . esc_html($instructions) . '</p>' .
                        ($task_url ? '<p><a href="' . esc_url($task_url) . '" style="background-color: #384D68; color: white; padding: 10px 15px; text-decoration: none; border-radius: 4px; display: inline-block;">' . esc_html__('View Task Board', 'pandatask') . '</a></p>' : '') .
                        '<p>' . esc_html($regards) . '<br>' . esc_html($site_name) . '</p>';

        return self::send_email($user->user_email, $subject, $text_message, $html_message);
    }    
    /**
     * Helper function to try and find a URL for the task board
     */
    private static function get_task_board_url($board_name) {
        global $wpdb;
        
        // Removed log_message call
        
        // Check if this is a BuddyPress group board (named like "group_123")
        if (preg_match('/^group_(\d+)$/', $board_name, $matches)) {
            $group_id = intval($matches[1]);
            
            if ($group_id > 0 && function_exists('bp_get_group_permalink') && function_exists('groups_get_group')) {
                // Removed log_message call
                $group = groups_get_group($group_id);
                
                if ($group && !empty($group->slug)) { // Check slug exists
                    $group_url = bp_get_group_permalink($group);
                    $final_url = trailingslashit($group_url) . 'tasks'; 
                    
                    // Removed log_message call
                    return $final_url;
                } else {
                    // Removed log_message call
                }
            }
        }
        
        // Fallback: Try to find a page with the shortcode
        $shortcode_pattern = '[task_board board_name="' . $board_name . '"';
        $alt_shortcode_pattern = "[task_board board_name='" . $board_name . "'";
        
        $post_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT ID FROM $wpdb->posts 
                WHERE (post_content LIKE %s OR post_content LIKE %s) 
                AND post_status = 'publish' AND post_type IN ('page', 'post') 
                ORDER BY post_date DESC 
                LIMIT 1", 
                '%' . $wpdb->esc_like($shortcode_pattern) . '%',
                '%' . $wpdb->esc_like($alt_shortcode_pattern) . '%'
            )
        );
        
        if ($post_id) {
            $url = get_permalink($post_id);
            if ($url) {
                 // Removed log_message call
                 return $url;
            }
        }
        
        // Removed log_message call
        return false; // Return false if no URL found
    }
}