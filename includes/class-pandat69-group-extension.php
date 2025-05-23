<?php
/**
 * Task Board Group Extension
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Make sure the BP_Group_Extension class exists
if ( ! class_exists( 'BP_Group_Extension' ) ) {
    // Maybe log an error or add an admin notice if this happens unexpectedly
    return;
}

class Pandat69_Group_Extension extends BP_Group_Extension {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Get current group ID if available
        $group_id = bp_is_group() ? bp_get_current_group_id() : 0; // Check if we are on a group page
        
        // Default to enabled for new groups or if no group context yet
        $enabled = true; 
        
        // If we have a valid group ID, check the actual setting
        if ($group_id > 0) {
            $enabled = groups_get_groupmeta($group_id, 'pandat69_tasks_enabled', true);
            // If it's never been set, default to enabled ('1')
            if ($enabled === '') {
                $enabled = true; // Treat unset as enabled for logic below
            } else {
                $enabled = ($enabled === '1'); // Convert meta value to boolean
            }
        } else {
             // On create screen, there's no group_id yet, rely on defaults/create_screen logic
             $enabled = true;
        }
        
        $args = array(
            'slug'              => 'tasks',
            'name'              => __('Tasks', 'pandatask'), // Use __() for name, BP handles display
            'nav_item_position' => 80,
            'visibility'        => 'private', // Only visible to group members
            'show_in_create'    => true,      // Show settings during group creation
            'enable_nav_item'   => false,     // We control visibility via setup_nav_visibility
            'screens'           => array(
                'admin' => array( // Settings in Group Admin > Manage
                    'enabled' => true, 
                    'name'    => __('Tasks Settings', 'pandatask'), // Settings page title
                    'slug'    => 'task-settings', // Slug for admin sub-nav
                    'position'=> 50,
                ),
                'create' => array( // Settings during Group Create process
                    'enabled' => true,
                    'name'    => __('Tasks Settings', 'pandatask'), // Creation step title
                    'position'=> 50,
                ),
                // The main display screen is handled by screen_function below
            ),
        );
        
        parent::init($args);
        
        // Hook into navigation setup to dynamically show/hide tab based on meta
        add_action('bp_actions', array($this, 'setup_nav_visibility'), 20);
    }
    
    /**
     * Dynamically control the visibility of the "Tasks" tab based on group settings.
     */
    public function setup_nav_visibility() {
        // Only run on single group pages
        if (!bp_is_group()) {
            return;
        }
        
        $group_id = bp_get_current_group_id();
        if (!$group_id) {
            return;
        }
        
        // Check if tasks are enabled for this group ('1' means enabled)
        $enabled = groups_get_groupmeta($group_id, 'pandat69_tasks_enabled', true);
        
        // Add the nav item only if enabled is explicitly '1'
        if ($enabled === '1') {
            $group = groups_get_group($group_id);
            if (!$group) return; // Bail if group not found
    
            $group_link = bp_get_group_permalink($group);
            $current_user_id = get_current_user_id();
            
            // Use direct function calls with explicit parameters instead of context-dependent functions
            $is_member = groups_is_user_member($current_user_id, $group_id);
            $is_admin = groups_is_user_admin($current_user_id, $group_id);
            $is_mod = groups_is_user_mod($current_user_id, $group_id);
            
            bp_core_new_subnav_item(array(
                'name'            => __('Tasks', 'pandatask'),
                'slug'            => $this->slug,
                'parent_url'      => $group_link,
                'parent_slug'     => $group->slug,
                'screen_function' => array($this, 'display_screen_callback'),
                'position'        => $this->nav_item_position,
                'user_has_access' => is_user_logged_in() && ($is_member || $is_admin || $is_mod),
            ), 'groups');
        }
    }
    
    /**
     * Callback function required by screen_function in bp_core_new_subnav_item.
     * Loads the template structure.
     */
    public function display_screen_callback() {
        // Set the title for the page
        add_action( 'bp_template_title', array( $this, 'display_screen_title' ) );
        // Add the content display function
        add_action( 'bp_template_content', array( $this, 'display_screen_content' ) );
        // Load the standard BuddyPress plugin template
        bp_core_load_template( apply_filters( 'bp_core_template_plugin', 'groups/single/plugins' ) );
    }

    /** Set page title */
    public function display_screen_title() {
         echo esc_html__( 'Tasks', 'pandatask' ); 
    }
    
    /**
     * Display the actual task board content within the BP template.
     */
    public function display_screen_content() {
        $group_id = bp_get_current_group_id();
        
        // Double-check access here as well
        if ( !is_user_logged_in() || ( !bp_group_is_member() && !bp_is_item_admin() && !bp_is_item_mod() ) ) {
            echo '<div id="message" class="bp-feedback error"><span class="bp-icon" aria-hidden="true"></span><p>';
            // Use esc_html_e for safe HTML output
            esc_html_e('You must be a member of this group to view tasks.', 'pandatask');
            echo '</p></div>';
            return;
        }
        
        // Verify tasks are enabled for this group
        $enabled = groups_get_groupmeta($group_id, 'pandat69_tasks_enabled', true);
        if ($enabled !== '1') {
            echo '<div id="message" class="bp-feedback info"><span class="bp-icon" aria-hidden="true"></span><p>';
            // Use esc_html_e for safe HTML output
            esc_html_e('Tasks are currently disabled for this group.', 'pandatask');
            echo '</p></div>';
            return;
        }
        
        // Create a unique board name based on group ID
        $board_name = 'group_' . $group_id;
        
        // Display the task board using our shortcode, passing group_id and page_name (slug)
        // Shortcode output should be escaped internally if needed, but echo do_shortcode is standard.
        echo do_shortcode('[task_board board_name="' . esc_attr($board_name) . '" group_id="' . esc_attr($group_id) . '" page_name="' . esc_attr($this->slug) .'"]');
    }
    
    /**
     * Settings screen displayed during group creation.
     * screen_function for the 'create' screen defined in init().
     */
    public function create_screen($group_id = null) {
        // Check if the user is allowed to create groups
        if (!bp_is_group_creation_step($this->slug)) {
             return;
        }
        ?>
        <h4 class="bp-create-step-title"><?php esc_html_e('Task Board Settings', 'pandatask'); ?></h4>
        
        <label for="pandat69_tasks_enabled" class="bp-label">
             <input type="checkbox" name="pandat69_tasks_enabled" id="pandat69_tasks_enabled" value="1" checked="checked">
             <?php esc_html_e('Enable task board for this group?', 'pandatask'); ?>
        </label>
        <p class="description">
            <?php esc_html_e('Adds a "Tasks" tab to the group for managing projects and tasks.', 'pandatask'); ?>
        </p>
        <?php
        // Nonce for security
        wp_nonce_field( 'groups_create_save_' . $this->slug );
    }
    
    /**
     * Save settings during group creation.
     * screen_save function for the 'create' screen defined in init().
     */
    public function create_screen_save($group_id = null) {
        check_admin_referer( 'groups_create_save_' . $this->slug );
        
        // group_id is automatically passed by BuddyPress here after the group is created
        if ( !$group_id ) {
            $group_id = bp_get_new_group_id();
        }
        if (!$group_id) return; // Bail if group ID is not found

        $enabled = isset($_POST['pandat69_tasks_enabled']) ? '1' : '0';
        groups_update_groupmeta($group_id, 'pandat69_tasks_enabled', $enabled);
    }
    
    /**
     * Settings screen displayed in Group Admin > [Task Settings].
     * screen_function for the 'admin' screen defined in init().
     */
    public function edit_screen($group_id = null) {
         // Check capabilities: Must be admin or mod of the group
        if ( !bp_is_item_admin() && !bp_is_item_mod() ) {
            echo '<div id="message" class="bp-feedback error"><span class="bp-icon" aria-hidden="true"></span><p>';
            // Use esc_html_e for safe HTML output
            esc_html_e('You do not have permission to manage task settings.', 'pandatask');
            echo '</p></div>';
            return;
        }

        if (!$group_id) {
            $group_id = bp_get_current_group_id();
        }
        
        // Check if tasks are enabled ('1' means enabled, other values or unset mean disabled)
        $enabled = groups_get_groupmeta($group_id, 'pandat69_tasks_enabled', true) === '1';
        ?>
        <h4><?php esc_html_e('Task Board Settings', 'pandatask'); ?></h4>
        
        <label for="pandat69_tasks_enabled" class="bp-label">
             <input type="checkbox" name="pandat69_tasks_enabled" id="pandat69_tasks_enabled" value="1" <?php checked($enabled); ?>>
             <?php esc_html_e('Enable task board for this group?', 'pandatask'); ?>
        </label>
        <p class="description">
            <?php esc_html_e('When enabled, a "Tasks" tab will be available for group members.', 'pandatask'); ?>
        </p>
        <p class="description">
            <?php esc_html_e('Note: You might need to refresh the page after saving to see the Tasks tab appear or disappear in the navigation.', 'pandatask'); ?>
        </p>
        
        <p class="submit">
            <input type="submit" name="save" value="<?php esc_attr_e('Save Settings', 'pandatask'); ?>" class="button-primary">
        </p>
        
        <?php
        // Nonce for security
        wp_nonce_field( 'groups_edit_save_' . $this->slug );
    }
    
    /**
     * Save settings from the Group Admin screen.
     * screen_save function for the 'admin' screen defined in init().
     */
    public function edit_screen_save($group_id = null) {
         // Check if the form was actually submitted
        if ( ! isset( $_POST['save'] ) ) {
            return;
        }
        
        // Verify nonce
        check_admin_referer( 'groups_edit_save_' . $this->slug );
        
        // Check capabilities again for safety
        if ( !bp_is_item_admin() && !bp_is_item_mod() ) {
             bp_core_add_message(__('You do not have permission to save task settings.', 'pandatask'), 'error');
            return;
        }

        if (!$group_id) {
            $group_id = bp_get_current_group_id();
        }
        if (!$group_id) return; // Bail if group ID not found

        $enabled = isset($_POST['pandat69_tasks_enabled']) ? '1' : '0';
        $updated = groups_update_groupmeta($group_id, 'pandat69_tasks_enabled', $enabled);
        
        if ($updated) {
            bp_core_add_message(__('Task settings saved successfully.', 'pandatask'));
        } else {
             bp_core_add_message(__('There was an error saving task settings. Please try again.', 'pandatask'), 'error');
        }

        $group = groups_get_current_group();
        $redirect_url = trailingslashit(bp_get_group_permalink($group) . 'admin/' . $this->slug);
        bp_core_redirect($redirect_url);
    }
}

// Function to register the extension with BuddyPress
function pandat69_register_group_extension() {
    if ( bp_is_active( 'groups' ) ) {
        bp_register_group_extension( 'Pandat69_Group_Extension' );
    }
}
add_action( 'bp_loaded', 'pandat69_register_group_extension' ); // Register once BuddyPress is fully loaded