<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class for handling BuddyPress notifications integration
 *
 * Adds notifications for:
 * - When a user is assigned to a task
 * - When a comment is added to a task the user is assigned to
 * - When a user is @mentioned in a comment
 */
class Pandat69_Notifications {

    /**
     * Initialize the notifications integration
     */
    public static function init() {
        // Only register if BuddyPress and the notifications component are active
        if (!self::is_bp_notifications_active()) {
            return;
        }

        // Register our component with BuddyPress notifications system
        add_filter('bp_notifications_get_registered_components', array(__CLASS__, 'register_component'));

        // Format the notifications
        add_filter('bp_notifications_get_notifications_for_user', array(__CLASS__, 'format_notifications'), 10, 8);

        // Add action to process notification read status
        add_action('wp', array(__CLASS__, 'process_notification_read'));
    }

    /**
     * Register our component with BuddyPress notifications
     *
     * @param array $components Registered components
     * @return array Modified components array
     */
    public static function register_component($components) {
        // Add 'pandatask' to the array of registered components
        if (!in_array('pandatask', $components)) {
            $components[] = 'pandatask';
        }
        return $components;
    }

    /**
     * Format the notifications for display
     */
    public static function format_notifications($action, $item_id, $secondary_item_id, $total_items, $format = 'string', $component_action_name = '', $component_name = '', $notification_id = 0) {
        // We need to check both our parameters and the legacy ones because BuddyPress may pass them differently
        if ($component_name !== 'pandatask' && $component_action_name !== $action) {
            return $action;
        }

        // Default output formats
        $title = '';
        $link = '';

        // Get the relevant action - use component_action_name if available, otherwise fallback to $action
        $notification_action = !empty($component_action_name) ? $component_action_name : $action;

        // Handle different notification types
        switch ($notification_action) {
            case 'task_assignment':
                // Get the task details
                $task = Pandat69_DB::get_task($item_id);
                if (!$task) return $action;

                // Get the assigner's details (secondary_item_id is the user who assigned the task)
                $assigner = get_userdata($secondary_item_id);
                $assigner_name = $assigner ? $assigner->display_name : __('Someone', 'pandatask');

                // Create the notification text
                /* translators: %1$s: Assigner's display name, %2$s: Task name. */
                $text_format = __('%1$s assigned you to task: %2$s', 'pandatask');
                $title = sprintf(
                    $text_format,
                    $assigner_name,
                    $task->name
                );

                // Generate the link to the task board
                $link = self::get_task_board_url($task->board_name);
                break;

            case 'task_comment':
                // Get the task details
                $task = Pandat69_DB::get_task($item_id);
                if (!$task) return $action;

                // Get the commenter's details
                $commenter = get_userdata($secondary_item_id);
                $commenter_name = $commenter ? $commenter->display_name : __('Someone', 'pandatask');

                // Create the notification text
                /* translators: %1$s: Commenter's display name, %2$s: Task name. */
                $text_format = __('%1$s commented on task: %2$s', 'pandatask');
                $title = sprintf(
                    $text_format,
                    $commenter_name,
                    $task->name
                );

                // Generate the link to the task board
                $link = self::get_task_board_url($task->board_name);
                break;

            case 'task_mention':
                // Get the task details
                $task = Pandat69_DB::get_task($item_id);
                if (!$task) return $action;

                // Get the mentioner's details
                $mentioner = get_userdata($secondary_item_id);
                $mentioner_name = $mentioner ? $mentioner->display_name : __('Someone', 'pandatask');

                // Create the notification text
                /* translators: %1$s: Mentioner's display name, %2$s: Task name. */
                $text_format = __('%1$s mentioned you in task: %2$s', 'pandatask');
                $title = sprintf(
                    $text_format,
                    $mentioner_name,
                    $task->name
                );

                // Generate the link to the task board
                $link = self::get_task_board_url($task->board_name);
                break;

            case 'task_deadline':
                // Get the task details
                $task = Pandat69_DB::get_task($item_id);
                if (!$task) return $action;

                // Calculate days remaining
                $days_remaining = max(1, floor((strtotime($task->deadline) - time()) / DAY_IN_SECONDS));

                // Create the notification text
                /* translators: %1$s: Task name, %2$d: Number of days remaining. */
                $text_format = _n(
                        'Deadline approaching for task: %1$s (%2$d day remaining)',
                        'Deadline approaching for task: %1$s (%2$d days remaining)',
                        $days_remaining,
                        'pandatask'
                    );
                $title = sprintf(
                    $text_format,
                    $task->name,
                    $days_remaining
                );

                // Generate the link to the task board
                $link = self::get_task_board_url($task->board_name);
                break;
            case 'task_supervision':
                // Get the task details
                $task = Pandat69_DB::get_task($item_id);
                if (!$task) return $action;

                // Get the assigner's details
                $assigner = get_userdata($secondary_item_id);
                $assigner_name = $assigner ? $assigner->display_name : __('Someone', 'pandatask');

                // Create the notification text
                /* translators: %1$s: Assigner's display name, %2$s: Task name. */
                $text_format = __('%1$s assigned you as supervisor to task: %2$s', 'pandatask');
                $title = sprintf(
                    $text_format,
                    $assigner_name,
                    $task->name
                );

                // Generate the link to the task board
                $link = self::get_task_board_url($task->board_name);
                break;

            default:
                return $action;
        }

        // Add notification parameters to mark as read when clicked
        if ($link && $notification_id) {
            $link = add_query_arg(array(
                'pandat69_notification_id' => $notification_id,
                'pandat69_notification_action' => $notification_action
            ), $link);
        }

        // Format based on requested format
        if ('string' === $format) {
            // Return the title with a link
            return '<a href="' . esc_url($link) . '">' . esc_html($title) . '</a>';
        } else {
            // Return an array
            return array(
                'text' => $title,
                'link' => $link
            );
        }
    }

    /**
     * Process notification parameters and mark as read if needed
     * Should be called early in the page load
     */
    public static function process_notification_read() {
        // Check if we have notification parameters
        if (!isset($_GET['pandat69_notification_id']) || !isset($_GET['pandat69_notification_action'])) {
            return;
        }

        // Only process if BuddyPress notifications are active
        if (!self::is_bp_notifications_active()) {
            return;
        }

        // Get parameters
        $notification_id = absint($_GET['pandat69_notification_id']);
        $notification_action = sanitize_text_field($_GET['pandat69_notification_action']);

        // Verify the user's access to this notification
        if (!$notification_id || !bp_is_user_active( get_current_user_id() ) || !BP_Notifications_Notification::check_access(get_current_user_id(), $notification_id)) {
            // Added bp_is_user_active check for robustness, though check_access should handle it.
            return;
        }

        // Mark the notification as read
        BP_Notifications_Notification::update(
            array('is_new' => 0),
            array('id' => $notification_id, 'user_id' => get_current_user_id() ) // Added user_id for extra safety
        );
    }

    /**
     * Send a notification for task assignment
     *
     * @param int $task_id The task ID
     * @param int $user_id The user being assigned
     * @param int $assigner_id The user who made the assignment
     * @param string $role Role being assigned ('assignee' or 'supervisor').
     * @return bool|int False on failure, notification ID on success
     */
    public static function add_assignment_notification($task_id, $user_id, $assigner_id, $role = 'assignee') {
        if (!self::is_bp_notifications_active()) {
            return false;
        }

        // Ensure user IDs are valid
        if ( empty( $user_id ) || empty( $assigner_id ) || empty( $task_id ) ) {
            return false;
        }

        $component_action = ($role === 'supervisor') ? 'task_supervision' : 'task_assignment';

        return bp_notifications_add_notification(array(
            'user_id'           => $user_id,
            'item_id'           => $task_id,
            'secondary_item_id' => $assigner_id,
            'component_name'    => 'pandatask',
            'component_action'  => $component_action,
            'date_notified'     => bp_core_current_time(),
            'is_new'            => 1,
            'allow_duplicate'   => false, // Prevent duplicate notifications for the exact same event
        ));
    }

    /**
     * Send a notification for a task comment
     *
     * @param int $task_id The task ID
     * @param int $user_id The user to notify
     * @param int $commenter_id The user who commented
     * @return bool|int False on failure, notification ID on success
     */
    public static function add_comment_notification($task_id, $user_id, $commenter_id) {
        if (!self::is_bp_notifications_active()) {
            return false;
        }

        // Ensure user IDs are valid and prevent self-notification on comments
        if ( empty( $user_id ) || empty( $commenter_id ) || empty( $task_id ) || $user_id === $commenter_id ) {
            return false;
        }

        return bp_notifications_add_notification(array(
            'user_id'           => $user_id,
            'item_id'           => $task_id,
            'secondary_item_id' => $commenter_id,
            'component_name'    => 'pandatask',
            'component_action'  => 'task_comment',
            'date_notified'     => bp_core_current_time(),
            'is_new'            => 1,
            'allow_duplicate'   => true, // Allow multiple comment notifications
        ));
    }

    /**
     * Send a notification for an @mention
     *
     * @param int $task_id The task ID
     * @param int $user_id The user being mentioned
     * @param int $mentioner_id The user who mentioned them
     * @return bool|int False on failure, notification ID on success
     */
    public static function add_mention_notification($task_id, $user_id, $mentioner_id) {
        if (!self::is_bp_notifications_active()) {
            return false;
        }

        // Ensure user IDs are valid and prevent self-notification on mentions
        if ( empty( $user_id ) || empty( $mentioner_id ) || empty( $task_id ) || $user_id === $mentioner_id ) {
            return false;
        }

        return bp_notifications_add_notification(array(
            'user_id'           => $user_id,
            'item_id'           => $task_id,
            'secondary_item_id' => $mentioner_id,
            'component_name'    => 'pandatask',
            'component_action'  => 'task_mention',
            'date_notified'     => bp_core_current_time(),
            'is_new'            => 1,
            'allow_duplicate'   => true, // Allow multiple mention notifications if needed
        ));
    }

    /**
     * Helper function to get a URL for the task board
     *
     * @param string $board_name The board name
     * @return string|false The URL or false if not found
     */
    public static function get_task_board_url($board_name) {
        // Reuse the same logic from Pandat69_Email::get_task_board_url
        global $wpdb;

        // Check if this is a BuddyPress group board (named like "group_123")
        if (preg_match('/^group_(\d+)$/', $board_name, $matches)) {
            $group_id = intval($matches[1]);

            if ($group_id > 0 && function_exists('bp_get_group_permalink') && function_exists('groups_get_group')) {
                $group = groups_get_group( array( 'group_id' => $group_id ) ); // Use array syntax for newer BP versions

                if ($group && !empty($group->slug)) {
                    $group_url = bp_get_group_permalink($group);
                    // Assuming 'tasks' is the slug for your task board tab within the group
                    // Ensure this slug is correctly registered for the group extension.
                    return trailingslashit($group_url) . 'tasks/'; // Add trailing slash for consistency
                }
            }
        }

        // Fallback: Try to find a page with the shortcode containing the specific board_name
        // Use more robust pattern matching for shortcode attributes
        $shortcode_regex = '/\[task_board\s+[^\]]*board_name\s*=\s*([\'"])' . preg_quote( $board_name, '/' ) . '\1[^\]]*\]/i';

        $query = $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_content REGEXP %s
             AND post_status = 'publish'
             AND post_type IN ('page', 'post')
             ORDER BY post_date DESC
             LIMIT 1",
            $shortcode_regex
        );

        $post_id = $wpdb->get_var($query);

        if ($post_id) {
            return get_permalink($post_id);
        }

        // Final fallback: Maybe a generic tasks page exists?
        // This is less reliable and depends on site structure.
        // $tasks_page = get_page_by_path('tasks'); // Example
        // if ($tasks_page) {
        //     return get_permalink($tasks_page->ID);
        // }

        return false; // Return false if no suitable URL found
    }

    /**
     * Check if BuddyPress notifications component is active
     *
     * @return bool True if BuddyPress notifications is active
     */
    public static function is_bp_notifications_active() {
        // Check if BuddyPress plugin is active and the function exists
        if ( ! function_exists( 'buddypress' ) ) {
             return false;
        }

        // Get BuddyPress instance
        $bp = buddypress();

        // Check if the notifications component is loaded and active
        return ! empty( $bp->notifications ) && class_exists('BP_Notifications_Notification');
    }
    /**
     * Send a notification for an approaching deadline
     *
     * @param int $task_id The task ID
     * @param int $user_id The user to notify
     * @return bool|int False on failure, notification ID on success
     */
    public static function add_deadline_notification($task_id, $user_id) {
        if (!self::is_bp_notifications_active()) {
            return false;
        }

        // Ensure user IDs are valid
        if ( empty( $user_id ) || empty( $task_id ) ) {
            return false;
        }

        return bp_notifications_add_notification(array(
            'user_id'           => $user_id,
            'item_id'           => $task_id,
            'secondary_item_id' => 0, // No secondary actor for deadline notifications
            'component_name'    => 'pandatask',
            'component_action'  => 'task_deadline',
            'date_notified'     => bp_core_current_time(),
            'is_new'            => 1,
            'allow_duplicate'   => false, // Usually only one deadline notification per task/user is needed
        ));
    }
    /**
     * Debug BuddyPress notifications for a user
     *
     * @param int $user_id The user ID to debug notifications for. Defaults to current user.
     */
    public static function debug_notifications($user_id = 0) {
        if (!current_user_can('manage_options')) { // Optional: Restrict debug to admins
             error_log('Pandatask Debug: Permission denied for debugging notifications.');
             return;
        }

        if (!self::is_bp_notifications_active()) {
            error_log('Pandatask Debug: BuddyPress notifications component is not active.');
            return;
        }

        if ( empty($user_id) ) {
            $user_id = get_current_user_id();
        }

        if ( empty($user_id) ) {
             error_log('Pandatask Debug: Could not determine user ID for debugging notifications.');
             return;
        }

        $user_data = get_userdata($user_id);
        if ( ! $user_data ) {
            error_log('Pandatask Debug: Invalid user ID provided: ' . $user_id);
            return;
        }

        error_log('--- Pandatask Notification Debug Start (User: ' . $user_data->user_login . ' ID: ' . $user_id . ') ---');

        // Check if component is registered
        $components = apply_filters('bp_notifications_get_registered_components', array());
        if (in_array('pandatask', $components)) {
            error_log('Pandatask Debug: [OK] "pandatask" component is registered.');
        } else {
            error_log('Pandatask Debug: [WARNING] "pandatask" component is NOT registered.');
        }
         error_log('Pandatask Debug: All Registered notification components: ' . print_r($components, true));


        // Get raw notifications for the user
        $notifications = BP_Notifications_Notification::get(array(
            'user_id' => $user_id,
            'order_by' => 'date_notified',
            'sort_order' => 'DESC',
        ));

        error_log('Pandatask Debug: Found ' . count($notifications) . ' raw notifications for user ' . $user_id . ' in database.');

        if (empty($notifications)) {
             error_log('Pandatask Debug: No raw notifications found in the database for this user.');
        } else {
            error_log('Pandatask Debug: Listing raw notifications:');
            foreach ($notifications as $notification) {
                error_log(sprintf(
                    '  - ID: %d, Component: %s, Action: %s, Item ID: %d, Sec ID: %d, Date: %s, Is New: %d',
                    $notification->id,
                    $notification->component_name,
                    $notification->component_action,
                    $notification->item_id,
                    $notification->secondary_item_id,
                    $notification->date_notified,
                    $notification->is_new
                ));
                 // Try formatting this specific notification
                 if ($notification->component_name === 'pandatask') {
                     $formatted_single = self::format_notifications(
                         $notification->component_action,
                         $notification->item_id,
                         $notification->secondary_item_id,
                         1, // total items (doesn't matter much here)
                         'array', // get array format
                         $notification->component_action,
                         $notification->component_name,
                         $notification->id
                     );
                     error_log('    Formatted (array): ' . print_r($formatted_single, true));
                      $formatted_single_str = self::format_notifications(
                         $notification->component_action,
                         $notification->item_id,
                         $notification->secondary_item_id,
                         1,
                         'string', // get string format
                         $notification->component_action,
                         $notification->component_name,
                         $notification->id
                     );
                      error_log('    Formatted (string): ' . $formatted_single_str);
                 }

            }
        }

        // Try to get the formatted notifications using BuddyPress function
        error_log('Pandatask Debug: Attempting bp_notifications_get_notifications_for_user()...');
        $formatted = bp_notifications_get_notifications_for_user($user_id, 'array');
        error_log('Pandatask Debug: Formatted notifications via bp_notifications_get_notifications_for_user( \'array\' ): ' . print_r($formatted, true));

        if (empty($formatted)) {
            error_log('Pandatask Debug: No formatted notifications returned by bp_notifications_get_notifications_for_user(). This might indicate a formatting issue in the filter or no pandatask notifications.');
        } else {
             error_log('Pandatask Debug: [OK] bp_notifications_get_notifications_for_user() returned formatted data.');
        }

        // Check specifically for pandatask notifications in the raw data again for summary
        $pandatask_notifications = array_filter($notifications, function($n) {
            return $n->component_name === 'pandatask';
        });

        if (empty($pandatask_notifications)) {
            error_log('Pandatask Debug: No raw notifications with component_name "pandatask" found for this user.');
        } else {
             error_log('Pandatask Debug: Summary: Found ' . count($pandatask_notifications) . ' raw "pandatask" notifications.');
        }
        error_log('--- Pandatask Notification Debug End ---');

    }
}