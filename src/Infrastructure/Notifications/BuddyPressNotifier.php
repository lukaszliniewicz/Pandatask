<?php
namespace Pandatask\Infrastructure\Notifications;

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
class BuddyPressNotifier {

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
                $task = self::get_task($item_id);
                if (!$task) return $action;

                // Get the assigner's details (secondary_item_id is the user who assigned the task)
                $assigner = get_userdata($secondary_item_id);
                $assigner_name = $assigner ? $assigner->display_name : __('Someone', 'pandatask');

                // Create the notification text
                /* translators: 1: Assigner's display name, 2: Task name. */
                $title = sprintf(
                    __('%1$s assigned you to task: %2$s', 'pandatask'),
                    $assigner_name,
                    $task->name
                );

                // Generate the link to the task board
                $link = self::get_task_board_url($task->board_name, $item_id);
                break;

            case 'task_comment':
                // Get the task details
                $task = self::get_task($item_id);
                if (!$task) return $action;

                // Get the commenter's details
                $commenter = get_userdata($secondary_item_id);
                $commenter_name = $commenter ? $commenter->display_name : __('Someone', 'pandatask');

                // Create the notification text
                /* translators: 1: Commenter's display name, 2: Task name. */
                $title = sprintf(
                    __('%1$s commented on task: %2$s', 'pandatask'),
                    $commenter_name,
                    $task->name
                );

                // Generate the link to the task board
                $link = self::get_task_board_url($task->board_name, $item_id);
                break;

            case 'task_mention':
                // Get the task details
                $task = self::get_task($item_id);
                if (!$task) return $action;

                // Get the mentioner's details
                $mentioner = get_userdata($secondary_item_id);
                $mentioner_name = $mentioner ? $mentioner->display_name : __('Someone', 'pandatask');

                // Get comment text from meta
                $comment_text = '';
                if ($notification_id > 0 && function_exists('bp_notifications_get_meta')) {
                    $comment_id = bp_notifications_get_meta($notification_id, 'pandat69_comment_id', true);
                    if ($comment_id) {
                        global $wpdb;
                        $comments_table = \Pandatask\Infrastructure\Persistence\DatabaseContext::getDbPrefix() . 'comments';
                        $comment_text = $wpdb->get_var($wpdb->prepare("SELECT comment_text FROM {$comments_table} WHERE id = %d", $comment_id));
                    }
                }

                // Create the notification text
                if (!empty($comment_text)) {
                    // Clean up markdown mentions for display
                    $clean_text = preg_replace('/@\[([^\]]+)\]\([^\)]+\)/', '@$1', $comment_text);
                    
                    /* translators: 1: Mentioner's name, 2: Task name, 3: Comment text snippet. */
                    $title = sprintf(
                        __('%1$s mentioned you on task "%2$s": %3$s', 'pandatask'),
                        $mentioner_name,
                        $task->name,
                        '"' . wp_trim_words(strip_tags($clean_text), 15, '...') . '"'
                    );
                } else {
                    /* translators: 1: Mentioner's display name, 2: Task name. */
                    $title = sprintf(
                        __('%1$s mentioned you in a comment on task: %2$s', 'pandatask'),
                        $mentioner_name,
                        $task->name
                    );
                }
                
                // Generate the link to the task board
                $link = self::get_task_board_url($task->board_name, $item_id);
                break;

            case 'task_description_mention':
                // Get the task details
                $task = self::get_task($item_id);
                if (!$task) return $action;

                // Get the mentioner's details
                $mentioner = get_userdata($secondary_item_id);
                $mentioner_name = $mentioner ? $mentioner->display_name : __('Someone', 'pandatask');
                
                if (!empty($task->description)) {
                    /* translators: 1: User's name, 2: Task name, 3: Description snippet. */
                    $title = sprintf(
                        __('%1$s mentioned you in the description of task "%2$s": %3$s', 'pandatask'),
                        $mentioner_name,
                        $task->name,
                        '"' . wp_trim_words(strip_tags($task->description), 15, '...') . '"'
                    );
                } else {
                    /* translators: 1: Mentioner's display name, 2: Task name. */
                    $title = sprintf(
                        __('%1$s mentioned you in the description of task: %2$s', 'pandatask'),
                        $mentioner_name,
                        $task->name
                    );
                }

                $link = self::get_task_board_url($task->board_name, $item_id);
                break;

            case 'task_deadline':
                // Get the task details
                $task = self::get_task($item_id);
                if (!$task) return $action;

                // Calculate days remaining
                $days_remaining = max(1, floor((strtotime($task->deadline) - time()) / DAY_IN_SECONDS));

                // Create the notification text
                /* translators: 1: Task name, 2: Number of days remaining. */
                $title = sprintf(
                    _n(
                        'Deadline approaching for task: %1$s (%2$d day remaining)',
                        'Deadline approaching for task: %1$s (%2$d days remaining)',
                        $days_remaining,
                        'pandatask'
                    ),
                    $task->name,
                    $days_remaining
                );

                // Generate the link to the task board
                $link = self::get_task_board_url($task->board_name, $item_id);
                break;
            case 'task_supervision':
                // Get the task details
                $task = self::get_task($item_id);
                if (!$task) return $action;

                // Get the assigner's details
                $assigner = get_userdata($secondary_item_id);
                $assigner_name = $assigner ? $assigner->display_name : __('Someone', 'pandatask');

                // Create the notification text
                /* translators: 1: Assigner's display name, 2: Task name. */
                $title = sprintf(
                    __('%1$s assigned you as supervisor to task: %2$s', 'pandatask'),
                    $assigner_name,
                    $task->name
                );

                // Generate the link to the task board
                $link = self::get_task_board_url($task->board_name, $item_id);
                break;

            default:
                return $action;
        }

        // Add notification parameters to mark as read when clicked
        if ($link && $notification_id) {
            $link = add_query_arg(array(
                'pandat69_notification_id' => $notification_id,
                'pandat69_notification_action' => $notification_action,
                '_wpnonce' => wp_create_nonce('pandat69_mark_read_' . $notification_id)
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
        $notification_action = isset($_GET['pandat69_notification_action']) ? sanitize_text_field(wp_unslash($_GET['pandat69_notification_action'])) : '';

        // Verify nonce
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'pandat69_mark_read_' . $notification_id)) {
            return;
        }
        
        // Verify the user's access to this notification
        if (!$notification_id || !\BP_Notifications_Notification::check_access(get_current_user_id(), $notification_id)) {
            return;
        }

        // Mark the notification as read
        \BP_Notifications_Notification::update(
            array('is_new' => 0),
            array('id' => $notification_id)
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

        $component_action = ($role === 'supervisor') ? 'task_supervision' : 'task_assignment';

        return bp_notifications_add_notification(array(
            'user_id'           => $user_id,
            'item_id'           => $task_id,
            'secondary_item_id' => $assigner_id,
            'component_name'    => 'pandatask',
            'component_action'  => $component_action,
            'date_notified'     => bp_core_current_time(),
            'is_new'            => 1,
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

        return bp_notifications_add_notification(array(
            'user_id'           => $user_id,
            'item_id'           => $task_id,
            'secondary_item_id' => $commenter_id,
            'component_name'    => 'pandatask',
            'component_action'  => 'task_comment',
            'date_notified'     => bp_core_current_time(),
            'is_new'            => 1,
        ));
    }

    /**
     * Send a notification for an @mention
     *
     * @param int $task_id The task ID
     * @param int $user_id The user being mentioned
     * @param int $mentioner_id The user who mentioned them
     * @param string $context The context ('comment' or 'description')
     * @param int $comment_id The ID of the comment if context is 'comment'
     * @return bool|int False on failure, notification ID on success
     */
    public static function add_mention_notification($task_id, $user_id, $mentioner_id, $context = 'comment', $comment_id = 0) {
        if (!self::is_bp_notifications_active()) {
            return false;
        }
        
        $component_action = ($context === 'description') ? 'task_description_mention' : 'task_mention';

        $notification_id = bp_notifications_add_notification(array(
            'user_id'           => $user_id,
            'item_id'           => $task_id,
            'secondary_item_id' => $mentioner_id,
            'component_name'    => 'pandatask',
            'component_action'  => $component_action,
            'date_notified'     => bp_core_current_time(),
            'is_new'            => 1,
        ));
        
        // If it's a comment mention and we have a comment ID, store it in meta.
        if ($notification_id && $context === 'comment' && $comment_id > 0 && function_exists('bp_notifications_update_meta')) {
            bp_notifications_update_meta($notification_id, 'pandat69_comment_id', $comment_id);
        }

        return $notification_id;
    }

    /**
     * Helper function to get a URL for the task board
     *
     * @param string $board_name The board name
     * @return string|false The URL or false if not found
     */
    public static function get_task_board_url($board_name, $task_id = 0) {
        return \Pandatask\Infrastructure\Notifications\TaskBoardUrlResolver::resolve($board_name, $task_id);
    }

    private static function get_task($task_id) {
        $task_service = new \Pandatask\Application\Task\TaskService();

        return $task_service->getTask($task_id);
    }

    /**
     * Check if BuddyPress notifications component is active
     *
     * @return bool True if BuddyPress notifications is active
     */
    public static function is_bp_notifications_active() {
        return function_exists('buddypress') &&
               function_exists('bp_is_active') &&
               bp_is_active('notifications') &&
               class_exists('BP_Notifications_Notification');
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

        return bp_notifications_add_notification(array(
            'user_id'           => $user_id,
            'item_id'           => $task_id,
            'secondary_item_id' => 0, // No secondary actor for deadline notifications
            'component_name'    => 'pandatask',
            'component_action'  => 'task_deadline',
            'date_notified'     => bp_core_current_time(),
            'is_new'            => 1,
        ));
    }
}
