<?php
/**
 * Plugin Name:       Pandatask
 * Description:       Adds a shortcode [task_board board_name="unique_board_id"] to display a task management board.
 * Version:           1.0.12
 * Author:            Lukasz Liniewicz
 * Author URI:        https://github.com/lukaszliniewicz
 * Plugin URI:        https://github.com/lukaszliniewicz/Pandatask
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pandatask
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'PANDAT69_VERSION' ) ) {
    define( 'PANDAT69_VERSION', '1.0.12' );
}

if ( ! defined( 'PANDAT69_PLUGIN_DIR' ) ) {
    define( 'PANDAT69_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'PANDAT69_PLUGIN_URL' ) ) {
    define( 'PANDAT69_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'PANDAT69_PLUGIN_FILE' ) ) {
    define( 'PANDAT69_PLUGIN_FILE', __FILE__ );
}

require_once PANDAT69_PLUGIN_DIR . 'src/autoload.php';

if ( ! function_exists( 'pandatask_register_legacy_alias' ) ) {
    function pandatask_register_legacy_alias( $legacy_class, $modern_class ) {
        if ( class_exists( $legacy_class, false ) ) {
            return;
        }

        if ( class_exists( $modern_class ) ) {
            class_alias( $modern_class, $legacy_class );
        }
    }
}

if ( ! function_exists( 'pandatask_register_group_extension_aliases' ) ) {
    function pandatask_register_group_extension_aliases() {
        if ( ! class_exists( 'BP_Group_Extension' ) ) {
            return;
        }

        pandatask_register_legacy_alias( 'Pandat69_Group_Extension', \Pandatask\Integration\BuddyPress\GroupTasksExtension::class );
        pandatask_register_legacy_alias( 'Pandat69_Bug_Tracker_Group_Extension', \Pandatask\Integration\BuddyPress\GroupBugTrackerExtension::class );
    }
}

pandatask_register_legacy_alias( 'Pandat69_BuddyPress', \Pandatask\Integration\BuddyPress\BuddyPressBootstrap::class );
pandatask_register_legacy_alias( 'Pandat69_BuddyPress_Profile', \Pandatask\Integration\BuddyPress\ProfileTasksPage::class );
pandatask_register_legacy_alias( 'Pandat69_Shortcode', \Pandatask\Frontend\TaskBoardShortcode::class );
pandatask_register_group_extension_aliases();

add_action( 'bp_loaded', 'pandatask_register_group_extension_aliases', 0 );
add_action( 'bp_init', 'pandatask_register_group_extension_aliases', 0 );

if ( ! function_exists( 'pandat69_group_members_dropdown' ) ) {
    function pandat69_group_members_dropdown( $group_id, $name, $selected = 0 ) {
        \Pandatask\Integration\BuddyPress\BuddyPressSupport::renderGroupMembersDropdown( $group_id, $name, $selected );
    }
}

if ( ! function_exists( 'pandat69_is_buddypress_active' ) ) {
    function pandat69_is_buddypress_active() {
        return \Pandatask\Integration\BuddyPress\BuddyPressSupport::isGroupsActive();
    }
}

register_activation_hook( PANDAT69_PLUGIN_FILE, array( 'Pandatask\\Plugin', 'activate' ) );
register_deactivation_hook( PANDAT69_PLUGIN_FILE, array( 'Pandatask\\Plugin', 'deactivate' ) );

\Pandatask\Plugin::instance()->boot();
