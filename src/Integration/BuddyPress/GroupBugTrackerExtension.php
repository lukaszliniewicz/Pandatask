<?php
/**
 * Bug Tracker Group Extension
 */
namespace Pandatask\Integration\BuddyPress;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'BP_Group_Extension' ) ) {
    return;
}

class GroupBugTrackerExtension extends \BP_Group_Extension {
    
    public function __construct() {
        $group_id = bp_is_group() ? bp_get_current_group_id() : 0;
        
        $enabled = true; 
        $default_assignee = 0;
        
        if ($group_id > 0) {
            $enabled = groups_get_groupmeta($group_id, 'pandat69_bug_tracker_enabled', true);
            $default_assignee = groups_get_groupmeta($group_id, 'pandat69_bug_tracker_assignee', true);

            if ($enabled === '') {
                $enabled = true;
            } else {
                $enabled = ($enabled === '1');
            }
        } else {
             $enabled = true;
        }
        
        $args = array(
            'slug'              => 'bug-tracker',
            'name'              => __('Bug Tracker', 'pandatask'),
            'nav_item_position' => 81,
            'visibility'        => 'private',
            'show_in_create'    => true,
            'enable_nav_item'   => false, // We control this dynamically
            'screens'           => array(
                'admin' => array(
                    'enabled' => true, 
                    'name'    => __('Bug Tracker Settings', 'pandatask'),
                    'slug'    => 'bug-tracker-settings',
                    'position'=> 51,
                ),
                'create' => array(
                    'enabled' => true,
                    'name'    => __('Bug Tracker Settings', 'pandatask'),
                    'position'=> 51,
                ),
            ),
        );
        
        parent::init($args);
        
        add_action('bp_actions', array($this, 'setup_nav_visibility'), 21);
    }
    
    public function setup_nav_visibility() {
        if (!bp_is_group()) {
            return;
        }
        
        $group_id = bp_get_current_group_id();
        if (!$group_id) {
            return;
        }
        
        $enabled = groups_get_groupmeta($group_id, 'pandat69_bug_tracker_enabled', true);
        
        if ($enabled === '1') {
            $group = groups_get_group($group_id);
            if (!$group) return;
    
            $group_link = bp_get_group_permalink($group);
            $current_user_id = get_current_user_id();
            
            $is_member = groups_is_user_member($current_user_id, $group_id);
            $is_admin = groups_is_user_admin($current_user_id, $group_id);
            $is_mod = groups_is_user_mod($current_user_id, $group_id);
            
            bp_core_new_subnav_item(array(
                'name'            => __('Bug Tracker', 'pandatask'),
                'slug'            => $this->slug,
                'parent_url'      => $group_link,
                'parent_slug'     => $group->slug,
                'screen_function' => array($this, 'display_screen_callback'),
                'position'        => $this->nav_item_position,
                'user_has_access' => is_user_logged_in() && ($is_member || $is_admin || $is_mod),
            ), 'groups');
        }
    }
    
    public function display_screen_callback() {
        add_action( 'bp_template_title', function() { echo esc_html__( 'Bug Tracker', 'pandatask' ); } );
        add_action( 'bp_template_content', array( $this, 'display_screen_content' ) );
        bp_core_load_template( apply_filters( 'bp_core_template_plugin', 'groups/single/plugins' ) );
    }

    public function display_screen_content() {
        $group_id = bp_get_current_group_id();
        
        if ( !is_user_logged_in() || ( !bp_group_is_member() && !bp_is_item_admin() && !bp_is_item_mod() ) ) {
            echo '<div id="message" class="bp-feedback error"><p>' . esc_html__('You must be a member of this group to use the bug tracker.', 'pandatask') . '</p></div>';
            return;
        }
        
        $enabled = groups_get_groupmeta($group_id, 'pandat69_bug_tracker_enabled', true);
        if ($enabled !== '1') {
            echo '<div id="message" class="bp-feedback info"><p>' . esc_html__('The bug tracker is currently disabled for this group.', 'pandatask') . '</p></div>';
            return;
        }
        
        $board_name = 'group_' . $group_id;
        $default_assignee_id = groups_get_groupmeta($group_id, 'pandat69_bug_tracker_assignee', true);
        
        echo do_shortcode('[pandatask_bug_tracker board_name="' . esc_attr($board_name) . '" default_assignee_id="' . esc_attr($default_assignee_id) . '"]');
    }
    
    public function create_screen($group_id = null) {
        if (!bp_is_group_creation_step($this->slug)) {
             return;
        }
        ?>
        <h4 class="bp-create-step-title"><?php esc_html_e('Bug Tracker Settings', 'pandatask'); ?></h4>
        
        <label for="pandat69_bug_tracker_enabled" class="bp-label">
             <input type="checkbox" name="pandat69_bug_tracker_enabled" id="pandat69_bug_tracker_enabled" value="1" checked="checked">
             <?php esc_html_e('Enable bug tracker for this group?', 'pandatask'); ?>
        </label>
        <p class="description">
            <?php esc_html_e('Adds a "Bug Tracker" tab to the group for reporting and managing issues.', 'pandatask'); ?>
        </p>

        <label for="pandat69_bug_tracker_assignee"><?php esc_html_e('Default Assignee for New Bugs', 'pandatask'); ?></label>
        <?php BuddyPressSupport::renderGroupMembersDropdown($group_id, 'pandat69_bug_tracker_assignee'); ?>
        <p class="description">
            <?php esc_html_e('Select a user who will be automatically assigned to new bug reports. You can select members after the group is created.', 'pandatask'); ?>
        </p>
        <?php
        wp_nonce_field( 'groups_create_save_' . $this->slug );
    }
    
    public function create_screen_save($group_id = null) {
        check_admin_referer( 'groups_create_save_' . $this->slug );
        
        if ( !$group_id ) {
            $group_id = bp_get_new_group_id();
        }
        if (!$group_id) return;

        $enabled = isset($_POST['pandat69_bug_tracker_enabled']) ? '1' : '0';
        groups_update_groupmeta($group_id, 'pandat69_bug_tracker_enabled', $enabled);

        if (isset($_POST['pandat69_bug_tracker_assignee'])) {
            groups_update_groupmeta($group_id, 'pandat69_bug_tracker_assignee', absint($_POST['pandat69_bug_tracker_assignee']));
        }
    }
    
    public function edit_screen($group_id = null) {
        if ( !bp_is_item_admin() && !bp_is_item_mod() ) {
            echo '<div id="message" class="bp-feedback error"><p>' . esc_html__('You do not have permission to manage bug tracker settings.', 'pandatask') . '</p></div>';
            return;
        }

        if (!$group_id) {
            $group_id = bp_get_current_group_id();
        }
        
        $enabled = groups_get_groupmeta($group_id, 'pandat69_bug_tracker_enabled', true) === '1';
        $default_assignee_id = groups_get_groupmeta($group_id, 'pandat69_bug_tracker_assignee', true);
        ?>
        <h4><?php esc_html_e('Bug Tracker Settings', 'pandatask'); ?></h4>
        
        <label for="pandat69_bug_tracker_enabled" class="bp-label">
             <input type="checkbox" name="pandat69_bug_tracker_enabled" id="pandat69_bug_tracker_enabled" value="1" <?php checked($enabled); ?>>
             <?php esc_html_e('Enable bug tracker for this group?', 'pandatask'); ?>
        </label>
        <p class="description">
            <?php esc_html_e('When enabled, a "Bug Tracker" tab will be available for group members.', 'pandatask'); ?>
        </p>

        <label for="pandat69_bug_tracker_assignee"><?php esc_html_e('Default Assignee for New Bugs', 'pandatask'); ?></label>
        <?php BuddyPressSupport::renderGroupMembersDropdown($group_id, 'pandat69_bug_tracker_assignee', $default_assignee_id); ?>
        <p class="description">
            <?php esc_html_e('Select a user who will be automatically assigned to new bug reports.', 'pandatask'); ?>
        </p>
        
        <p class="submit">
            <input type="submit" name="save" value="<?php esc_attr_e('Save Settings', 'pandatask'); ?>" class="button-primary">
        </p>
        <?php
        wp_nonce_field( 'groups_edit_save_' . $this->slug );
    }
    
    public function edit_screen_save($group_id = null) {
        if ( ! isset( $_POST['save'] ) ) {
            return;
        }
        
        check_admin_referer( 'groups_edit_save_' . $this->slug );
        
        if ( !bp_is_item_admin() && !bp_is_item_mod() ) {
             bp_core_add_message(__('You do not have permission to save settings.', 'pandatask'), 'error');
            return;
        }

        if (!$group_id) {
            $group_id = bp_get_current_group_id();
        }
        if (!$group_id) return;

        $enabled = isset($_POST['pandat69_bug_tracker_enabled']) ? '1' : '0';
        groups_update_groupmeta($group_id, 'pandat69_bug_tracker_enabled', $enabled);
        
        if (isset($_POST['pandat69_bug_tracker_assignee'])) {
            groups_update_groupmeta($group_id, 'pandat69_bug_tracker_assignee', absint($_POST['pandat69_bug_tracker_assignee']));
        }

        bp_core_add_message(__('Bug Tracker settings saved successfully.', 'pandatask'));
        
        $group = groups_get_current_group();
        $redirect_url = trailingslashit(bp_get_group_permalink($group) . 'admin/' . $this->slug);
        bp_core_redirect($redirect_url);
    }
}
