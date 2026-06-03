<?php
namespace Pandatask\Infrastructure\Notifications;

use Exception;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EmailNotifier {

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
        $task = self::get_task($task_id);
        if (!$task) {
            return;
        }
        
        $admin_email = get_option('admin_email'); // Used for 'From' address fallback
        $site_name = get_bloginfo('name');
        $site_url = home_url();
        $role_display = ($role === 'supervisor') ? 'as supervisor for' : 'to';
        
        // Try to determine task URL
        $task_url = self::get_task_board_url($task->board_name, $task_id);
        $button_text = __('See Task', 'pandatask');
        
        foreach ($new_user_ids as $user_id) {
            $user = get_userdata($user_id);
            if (!$user || !is_a($user, 'WP_User') || !$user->user_email) {
                continue;
            }
            
            // translators: %1$s: role (as supervisor for/to), %2$s: Site name.
            $subject = sprintf(__('You have been assigned %1$s a task on %2$s', 'pandatask'), $role_display, $site_name);
            
            // --- Plain text email ---
            // translators: %s: User display name.
            $greeting = sprintf( __('Hello %s,', 'pandatask'), $user->display_name );
            // translators: %s: role (as supervisor for/to)
            $assignment_intro = sprintf( __('You have been assigned %s the following task:', 'pandatask'), $role_display );
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
                // translators: %1$s: Button text, %2$s: URL.
                $task_link_line = sprintf( __('%1$s: %2$s', 'pandatask'), $button_text, $task_url ) . "\n\n";
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
                            ($task_url ? '<p><a href="' . esc_url($task_url) . '" style="background-color: #384D68; color: white; padding: 10px 15px; text-decoration: none; border-radius: 4px; display: inline-block;">' . esc_html($button_text) . '</a></p>' : '') .
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
     * @param string $content The content (comment or description) containing the mention
     * @param string $context The context of the mention ('comment' or 'description')
     * @return void
     */
    public static function send_mention_notification($task_id, $mentioned_user_ids, $mentioner_id, $content, $context = 'comment') {
        if (empty($mentioned_user_ids)) {
            return;
        }
        
        // Get task details
        $task = self::get_task($task_id);
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

        // Clean up content for plain text email (strip HTML)
        // Handle both HTML anchors (TinyMCE) and Markdown (React Comments)
        $content_temp = preg_replace('/<a\s+[^>]*class="pandat69-mention"[^>]*>@([^<]+)<\/a>/i', '@$1', $content);
        $content_temp = preg_replace('/@\[([^\]]+)\]\([^\)]+\)/', '@$1', $content_temp);
        $plain_text_content = wp_strip_all_tags($content_temp);
        
        if ($context === 'description') {
            /* translators: %s: Name of the user who mentioned them */
            $mention_intro = sprintf(__('%s mentioned you in a task description:', 'pandatask'), $mentioner_name);
            /* translators: %s: The plain text content of the description */
            $content_line = sprintf(__('Description: %s', 'pandatask'), $plain_text_content);
            $content_label = __('Description', 'pandatask');
            $button_text = __('See Task', 'pandatask');
        } else { // 'comment'
            /* translators: %s: Name of the user who mentioned them */
            $mention_intro = sprintf(__('%s mentioned you in a comment on a task:', 'pandatask'), $mentioner_name);
            /* translators: %s: The plain text content of the comment */
            $content_line = sprintf(__('Comment: %s', 'pandatask'), $plain_text_content);
            $content_label = __('Comment', 'pandatask');
            $button_text = __('See Comment', 'pandatask');
        }
        
        // Try to determine task URL
        $task_url = self::get_task_board_url($task->board_name, $task_id);
        
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
            // $mention_intro is set above based on context
            // translators: %s: Task name
            $task_line = sprintf(__('Task: %s', 'pandatask'), $task->name);
            // $content_line is set above based on context
            $instructions = __('Please login to view the task and respond if needed.', 'pandatask');
            $task_link_line = '';
            if ($task_url) {
                // translators: %1$s: Button text, %2$s: URL.
                $task_link_line = sprintf(__('%1$s: %2$s', 'pandatask'), $button_text, $task_url) . "\n\n";
            }
            $regards = __('Regards,', 'pandatask');
            $signature = $site_name;
    
            $text_message = $greeting . "\n\n" .
                            $mention_intro . "\n\n" .
                            $task_line . "\n" .
                            $content_line . "\n\n" .
                            $instructions . "\n\n" .
                            $task_link_line .
                            $regards . "\n" .
                            $signature;
            
            // --- HTML email ---
            $html_message = '<p>' . esc_html($greeting) . '</p>' .
                            '<p>' . esc_html($mention_intro) . '</p>' .
                            '<table style="border-collapse: collapse; width: 100%; margin-bottom: 20px; border: 1px solid #ddd;">' .
                                '<tr><td style="padding: 8px; border: 1px solid #ddd; width: 30%"><strong>' . esc_html(__('Task', 'pandatask')) . '</strong></td><td style="padding: 8px; border: 1px solid #ddd;">' . esc_html($task->name) . '</td></tr>' .
                                '<tr><td style="padding: 8px; border: 1px solid #ddd;"><strong>' . esc_html($content_label) . '</strong></td><td style="padding: 8px; border: 1px solid #ddd;">' . wp_kses_post($content) . '</td></tr>' .
                            '</table>' .
                            '<p>' . esc_html($instructions) . '</p>' .
                            ($task_url ? '<p><a href="' . esc_url($task_url) . '" style="background-color: #384D68; color: white; padding: 10px 15px; text-decoration: none; border-radius: 4px; display: inline-block;">' . esc_html($button_text) . '</a></p>' : '') .
                            '<p>' . esc_html($regards) . '<br>' . esc_html($site_name) . '</p>';
            
            self::send_email($user->user_email, $subject, $text_message, $html_message);
        }
    }
    
    /**
     * Send notification for a missed deadline.
     *
     * @param int $task_id The task ID.
     * @param int $user_id The user ID to notify (assignee or supervisor).
     * @return bool Whether the email was sent successfully.
     */
    public static function send_missed_deadline_notification($task_id, $user_id) {
        $task = self::get_task($task_id);
        if (!$task || !$task->deadline) {
            return false;
        }

        $user = get_userdata($user_id);
        if (!$user || !is_a($user, 'WP_User') || !$user->user_email) {
            return false;
        }

        $site_name = get_bloginfo('name');
        $task_url = self::get_task_board_url($task->board_name, $task_id);
        $button_text = __('See Task', 'pandatask');

        /* translators: %s: Task name */
        $subject = sprintf(__('Task Deadline Missed: %s', 'pandatask'), $task->name);

        // --- Plain text email ---
        /* translators: %s: User display name */
        $greeting = sprintf(__('Hello %s,', 'pandatask'), $user->display_name);
        /* translators: %1$s: Task name, %2$s: Deadline date */
        $missed_intro = sprintf(__('This is a notification that the deadline for task "%1$s" was on %2$s and has been missed.', 'pandatask'), $task->name, $task->deadline);
        /* translators: %s: Task status */
        $status_line = sprintf(__('Current Status: %s', 'pandatask'), ucfirst(str_replace('-', ' ', $task->status)));
        $instructions = __('Please review the task and take appropriate action.', 'pandatask');
        $task_link_line = $task_url ? sprintf(__('%1$s: %2$s', 'pandatask'), $button_text, $task_url) . "\n\n" : '';
        $regards = __('Regards,', 'pandatask');

        $text_message = $greeting . "\n\n" .
                        $missed_intro . "\n" .
                        $status_line . "\n\n" .
                        $instructions . "\n\n" .
                        $task_link_line .
                        $regards . "\n" .
                        $site_name;

        // --- HTML email ---
        $html_message = '<p>' . esc_html($greeting) . '</p>' .
                        '<p>' . esc_html($missed_intro) . '</p>' .
                        '<table style="border-collapse: collapse; width: 100%; margin-bottom: 20px; border: 1px solid #ddd;">' .
                            '<tr><td style="padding: 8px; border: 1px solid #ddd; width: 30%"><strong>' . esc_html(__('Task', 'pandatask')) . '</strong></td><td style="padding: 8px; border: 1px solid #ddd;">' . esc_html($task->name) . '</td></tr>' .
                            '<tr><td style="padding: 8px; border: 1px solid #ddd;"><strong>' . esc_html(__('Deadline', 'pandatask')) . '</strong></td><td style="padding: 8px; border: 1px solid #ddd;">' . esc_html($task->deadline) . '</td></tr>' .
                            '<tr><td style="padding: 8px; border: 1px solid #ddd;"><strong>' . esc_html(__('Status', 'pandatask')) . '</strong></td><td style="padding: 8px; border: 1px solid #ddd;">' . esc_html(ucfirst(str_replace('-', ' ', $task->status))) . '</td></tr>' .
                        '</table>' .
                        '<p>' . esc_html($instructions) . '</p>' .
                        ($task_url ? '<p><a href="' . esc_url($task_url) . '" style="background-color: #384D68; color: white; padding: 10px 15px; text-decoration: none; border-radius: 4px; display: inline-block;">' . esc_html($button_text) . '</a></p>' : '') .
                        '<p>' . esc_html($regards) . '<br>' . esc_html($site_name) . '</p>';

        return self::send_email($user->user_email, $subject, $text_message, $html_message);
    }
    
    /**
     * Send notification to supervisors when a task is updated.
     *
     * @param int $task_id The task ID.
     * @param int $user_id The supervisor's user ID.
     * @param array $changed_fields Associative array of changed fields with 'from' and 'to' values.
     * @param object $task The updated task object.
     * @return bool Whether the email was sent successfully.
     */
    
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
        $task = self::get_task($task_id);
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
        // Handle both HTML anchors (legacy) and Markdown (React)
        $comment_temp = preg_replace('/<a\s+[^>]*class="pandat69-mention"[^>]*>@([^<]+)<\/a>/i', '@$1', $comment_text);
        $comment_temp = preg_replace('/@\[([^\]]+)\]\([^\)]+\)/', '@$1', $comment_temp);
        $plain_text_comment = wp_strip_all_tags($comment_temp);
        
        // Try to determine task URL
        $task_url = self::get_task_board_url($task->board_name, $task_id);
        $button_text = __('See Comment', 'pandatask');
        
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
                // translators: %1$s: Button text, %2$s: URL.
                $task_link_line = sprintf( __('%1$s: %2$s', 'pandatask'), $button_text, $task_url ) . "\n\n";
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
                            ($task_url ? '<p><a href="' . esc_url($task_url) . '" style="background-color: #384D68; color: white; padding: 10px 15px; text-decoration: none; border-radius: 4px; display: inline-block;">' . esc_html($button_text) . '</a></p>' : '') .
                            '<p>' . esc_html($regards) . '<br>' . esc_html($site_name) . '</p>';
            
            self::send_email($user->user_email, $subject, $text_message, $html_message);
        }
    }
    
    /**
     * Helper to get human-readable labels for field names.
     */
    private static function get_field_label($field_key) {
        $labels = [
            'name' => __('Name', 'pandatask'),
            'description' => __('Description', 'pandatask'),
            'status' => __('Status', 'pandatask'),
            'deadline' => __('Deadline', 'pandatask'),
            'priority' => __('Priority', 'pandatask'),
            'start_date' => __('Start Date', 'pandatask'),
            'category_id' => __('Category', 'pandatask'),
            'project_id' => __('Project', 'pandatask'),
            'parent_task_id' => __('Parent Task', 'pandatask'),
            'notify_deadline' => __('Deadline Notification', 'pandatask'),
            'notify_days_before' => __('Days to Notify Before Deadline', 'pandatask'),
            'assignee_added' => __('Assignee', 'pandatask'),
            'assignee_removed' => __('Assignee', 'pandatask'),
            'supervisor_added' => __('Supervisor', 'pandatask'),
            'supervisor_removed' => __('Supervisor', 'pandatask'),
        ];
        return isset($labels[$field_key]) ? $labels[$field_key] : ucfirst(str_replace('_', ' ', $field_key));
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
            // error_log('Pandatask email error: ' . $e->getMessage());
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
        $task = self::get_task($task_id);
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
        $task_url = self::get_task_board_url($task->board_name, $task_id);
        $button_text = __('See Task', 'pandatask');

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
            // translators: %1$s: Button text, %2$s: URL.
            $task_link_line = sprintf(__('%1$s: %2$s', 'pandatask'), $button_text, $task_url) . "\n\n";
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
                        ($task_url ? '<p><a href="' . esc_url($task_url) . '" style="background-color: #384D68; color: white; padding: 10px 15px; text-decoration: none; border-radius: 4px; display: inline-block;">' . esc_html($button_text) . '</a></p>' : '') .
                        '<p>' . esc_html($regards) . '<br>' . esc_html($site_name) . '</p>';

        return self::send_email($user->user_email, $subject, $text_message, $html_message);
    }    
    /**
     * Helper function to try and find a URL for the task board
     */
    /**
     * Send an aggregated notification for multiple updates on a task.
     *
     * @param int $task_id The task ID.
     * @param array $recipient_user_ids Array of user IDs to notify.
     * @param int $actor_id The user ID who made the changes.
     * @param array $log_changes Associative array of all changes.
     * @param string $aggregated_comment All comments combined.
     * @param object $task The updated task object.
     * @return void
     */
    public static function send_aggregated_update_notification($task_id, $recipient_user_ids, $actor_id, $log_changes, $aggregated_comment, $task) {
        if (empty($recipient_user_ids) || empty($log_changes)) {
            return;
        }

        $actor = get_userdata($actor_id);
        $actor_name = ($actor) ? $actor->display_name : __('A user', 'pandatask');
        $site_name = get_bloginfo('name');
        $task_url = self::get_task_board_url($task->board_name, $task_id);
        $button_text = __('See Task', 'pandatask');

        /* translators: %s: Task name */
        $subject = sprintf(__('Task Updated: %s', 'pandatask'), $task->name);

        $changes_text = '';
        $changes_html = '';

        foreach ($log_changes as $field => $change_data) {
            $field_label = self::get_field_label($field);
            $change_line = '';

            if (strpos($field, '_added') !== false) {
                $names = implode(', ', $change_data['values']);
                /* translators: %1$s: Field label (e.g., Assignee), %2$s: Name(s) of users added. */
                $change_line = sprintf(__('%1$s Added: %2$s', 'pandatask'), $field_label, $names);
            } elseif (strpos($field, '_removed') !== false) {
                $names = implode(', ', $change_data['values']);
                /* translators: %1$s: Field label (e.g., Assignee), %2$s: Name(s) of users removed. */
                $change_line = sprintf(__('%1$s Removed: %2$s', 'pandatask'), $field_label, $names);
            } else {
                $from = $change_data['from'] ?? __('not set', 'pandatask');
                $to = $change_data['to'] ?? __('not set', 'pandatask');
                if ($field === 'status') {
                    $from = ucfirst(str_replace('-', ' ', $from));
                    $to = ucfirst(str_replace('-', ' ', $to));
                }
                /* translators: %1$s: Field label, %2$s: Old value, %3$s: New value. */
                $change_line = sprintf(__('%1$s was changed from "%2$s" to "%3$s".', 'pandatask'), $field_label, $from, $to);
            }
            
            $changes_text .= '- ' . $change_line . "\n";
            $changes_html .= '<li>' . esc_html($change_line) . '</li>';
        }

        $comment_text = '';
        $comment_html = '';
        if (!empty($aggregated_comment)) {
            // Clean markdown for plain text
            $clean_comment = preg_replace('/@\[([^\]]+)\]\([^\)]+\)/', '@$1', $aggregated_comment);
            
            /* translators: %s: Comment text */
            $comment_text = "\n" . __('Comment:', 'pandatask') . "\n" . $clean_comment . "\n";
            $comment_html = '<h4>' . esc_html__('Comment:', 'pandatask') . '</h4><p><em>' . nl2br(esc_html($clean_comment)) . '</em></p>';
        }

        /* translators: %1$s: Actor name, %2$s: Task name */
        $update_intro = sprintf(__('%1$s has updated the following details for task "%2$s":', 'pandatask'), $actor_name, $task->name);
        $instructions = __('Please review the changes.', 'pandatask');
        $task_link_line = $task_url ? sprintf(__('%1$s: %2$s', 'pandatask'), $button_text, $task_url) . "\n\n" : '';
        $regards = __('Regards,', 'pandatask');
        
        $text_message_body = $update_intro . "\n" .
                             $comment_text . "\n" . // Comment moved to the top
                             $changes_text . "\n" .
                             $instructions . "\n\n" .
                             $task_link_line .
                             $regards . "\n" .
                             $site_name;

        $html_message_body = '<p>' . esc_html($update_intro) . '</p>' .
                             $comment_html . // Comment moved to the top
                             '<ul>' . $changes_html . '</ul>' .
                             '<p>' . esc_html($instructions) . '</p>' .
                             ($task_url ? '<p><a href="' . esc_url($task_url) . '" style="background-color: #384D68; color: white; padding: 10px 15px; text-decoration: none; border-radius: 4px; display: inline-block;">' . esc_html($button_text) . '</a></p>' : '') .
                             '<p>' . esc_html($regards) . '<br>' . esc_html($site_name) . '</p>';

        foreach ($recipient_user_ids as $user_id) {
            $user = get_userdata($user_id);
            if (!$user || !is_a($user, 'WP_User') || !$user->user_email) {
                continue;
            }
            /* translators: %s: User display name */
            $greeting = sprintf(__('Hello %s,', 'pandatask'), $user->display_name);
            $text_message = $greeting . "\n\n" . $text_message_body;
            $html_message = '<p>' . esc_html($greeting) . '</p>' . $html_message_body;

            self::send_email($user->user_email, $subject, $text_message, $html_message);
        }
    }

    private static function get_task($task_id) {
        $task_service = new \Pandatask\Application\Task\TaskService();

        return $task_service->getTask($task_id);
    }

    private static function get_task_board_url($board_name, $task_id = 0) {
        return \Pandatask\Infrastructure\Notifications\TaskBoardUrlResolver::resolve($board_name, $task_id);
    }
}
