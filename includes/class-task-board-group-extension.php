<?php
/**
 * Task Board Group Extension
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Make sure the BP_Group_Extension class exists
if ( ! class_exists( 'BP_Group_Extension' ) ) {
    return;
}

class Task_Board_Group_Extension extends BP_Group_Extension {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Get current group ID
        $group_id = bp_get_current_group_id();
        
        // Default to enabled for new groups or if no group is found
        $enabled = true;
        
        // If we have a valid group ID, check the actual setting
        if ($group_id > 0) {
            $enabled = groups_get_groupmeta($group_id, 'tbp_tasks_enabled', true);
            // If it's never been set, default to enabled
            if ($enabled === '') {
                $enabled = true;
            } else {
                $enabled = $enabled === '1';
            }
        }
        
        $args = array(
            'slug'              => 'tasks',
            'name'              => __('Tasks', 'task-board-plugin'),
            'nav_item_position' => 80,
            'visibility'        => 'private', // Only visible to members
            'show_in_create'    => true,      // Show during group creation process
            'enable_nav_item'   => false,     // We'll control this dynamically
            'screens'           => array(
                'admin' => array(
                    'enabled' => true
                ),
                'create' => array(
                    'enabled' => true
                ),
            ),
        );
        
        parent::init($args);
        
        // Hook into navigation setup to dynamically show/hide tab
        add_action('bp_actions', array($this, 'setup_nav_visibility'), 20);
    }
    
    /**
     * Dynamically control the visibility of the tab based on group settings
     */
    public function setup_nav_visibility() {
        // Only run on group pages
        if (!bp_is_group()) {
            return;
        }
        
        $group_id = bp_get_current_group_id();
        if (!$group_id) {
            return;
        }
        
        // Check if tasks are enabled for this group
        $enabled = groups_get_groupmeta($group_id, 'tbp_tasks_enabled', true);
        
        // Tasks are enabled for this group, so make tab visible
        if ($enabled === '1') {
            // Get the group's main navigation menu and add our item
            $group = groups_get_group($group_id);
            $group_link = bp_get_group_permalink($group);
            
            bp_core_new_subnav_item(array(
                'name'            => __('Tasks', 'task-board-plugin'),
                'slug'            => 'tasks',
                'parent_url'      => $group_link,
                'parent_slug'     => $group->slug,
                'screen_function' => array($this, 'display_screen'),
                'position'        => 80,
                'user_has_access' => bp_is_item_admin() || bp_is_item_mod() || bp_group_is_member()
            ));
        }
    }
    
    /**
     * Wrapper for the display method - needed for screen_function
     */
    public function display_screen() {
        add_action('bp_template_content', array($this, 'display'));
        bp_core_load_template(apply_filters('bp_core_template_plugin', 'groups/single/plugins'));
    }
    
    /**
     * Display task board content
     */
    public function display($group_id = null) {
        // Get current group ID if not provided
        if (!$group_id) {
            $group_id = bp_get_current_group_id();
        }
        
        // Verify user has access
        if (!bp_group_is_member() && !bp_group_is_mod() && !bp_group_is_admin()) {
            echo '<div class="bp-feedback error"><span class="bp-icon" aria-hidden="true"></span><p>';
            _e('You need to be a member of this group to access tasks.', 'task-board-plugin');
            echo '</p></div>';
            return;
        }
        
        // Verify tasks are enabled for this group
        $enabled = groups_get_groupmeta($group_id, 'tbp_tasks_enabled', true);
        if ($enabled !== '1') {
            echo '<div class="bp-feedback info"><span class="bp-icon" aria-hidden="true"></span><p>';
            _e('Tasks are not enabled for this group.', 'task-board-plugin');
            echo '</p></div>';
            return;
        }
        
        // Get the group
        $group = groups_get_group($group_id);
        
        // Create a unique board name based on group ID
        $board_name = 'group_' . $group_id;
        
        // Display the task board using our shortcode
        echo do_shortcode('[task_board board_name="' . esc_attr($board_name) . '" group_id="' . esc_attr($group_id) . '" page_name="tasks"]');
    }
    
    /**
     * Settings during group creation
     */
    public function create_screen($group_id = null) {
        ?>
        <h3><?php _e('Task Board Settings', 'task-board-plugin'); ?></h3>
        
        <p>
            <label>
                <input type="checkbox" name="tbp_tasks_enabled" value="1" checked="checked">
                <?php _e('Enable task board for this group', 'task-board-plugin'); ?>
            </label>
        </p>
        <p class="description">
            <?php _e('When enabled, a Tasks page will be added to this group with a task board for managing group tasks.', 'task-board-plugin'); ?>
        </p>
        <?php
    }
    
    /**
     * Save settings during group creation
     */
    public function create_screen_save($group_id = null) {
        $enabled = isset($_POST['tbp_tasks_enabled']) ? '1' : '0';
        groups_update_groupmeta($group_id, 'tbp_tasks_enabled', $enabled);
    }
    
    /**
     * Settings page in group admin
     */
    public function edit_screen($group_id = null) {
        // Get current group ID if not provided
        if (!$group_id) {
            $group_id = bp_get_group_id();
        }
        
        // Check capabilities
        if (!bp_is_item_admin() && !bp_is_item_mod()) {
            echo '<div class="bp-feedback error"><span class="bp-icon" aria-hidden="true"></span><p>';
            _e('You do not have permission to edit task settings.', 'task-board-plugin');
            echo '</p></div>';
            return;
        }
        
        $enabled = groups_get_groupmeta($group_id, 'tbp_tasks_enabled', true) === '1';
        ?>
        <h2><?php _e('Task Board Settings', 'task-board-plugin'); ?></h2>
        
        <p>
            <label>
                <input type="checkbox" name="tbp_tasks_enabled" value="1" <?php checked($enabled); ?>>
                <?php _e('Enable task board for this group', 'task-board-plugin'); ?>
            </label>
        </p>
        <p class="description">
            <?php _e('When enabled, a Tasks page will be added to this group with a task board for managing group tasks.', 'task-board-plugin'); ?>
        </p>
        <p class="description">
            <?php _e('Note: You may need to refresh the page after saving to see the Tasks tab appear or disappear.', 'task-board-plugin'); ?>
        </p>
        
        <input type="submit" name="save" id="save" value="<?php esc_attr_e('Save Changes', 'task-board-plugin'); ?>" class="button-primary">
        
        <?php
    }
    
    /**
     * Save settings from group admin
     */
    public function edit_screen_save($group_id = null) {
        // Check if form was submitted
        if (!isset($_POST['save'])) {
            return;
        }
        
        // Get current group ID if not provided
        if (!$group_id) {
            $group_id = bp_get_group_id();
        }
        
        $enabled = isset($_POST['tbp_tasks_enabled']) ? '1' : '0';
        groups_update_groupmeta($group_id, 'tbp_tasks_enabled', $enabled);
        
        // Add feedback message
        bp_core_add_message(__('Task settings updated successfully.', 'task-board-plugin'));
    }
}