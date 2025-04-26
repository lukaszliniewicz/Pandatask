<?php
/**
 * Plugin Name:       Pandatask
 * Description:       Adds a shortcode [task_board board_name="unique_board_id"] to display a task management board.
 * Version:           1.0.8
 * Author:            Lukasz Liniewicz
 * Author URI:        https://github.com/lukaszliniewicz
 * Plugin URI:        https://github.com/lukaszliniewicz/Pandatask
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pandatask
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// Define constants
define( 'PANDAT69_VERSION', '1.0.8' );
define( 'PANDAT69_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PANDAT69_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PANDAT69_PLUGIN_FILE', __FILE__ );

// Include necessary files
require_once PANDAT69_PLUGIN_DIR . 'includes/class-pandat69-db.php';
require_once PANDAT69_PLUGIN_DIR . 'includes/class-pandat69-shortcode.php';
require_once PANDAT69_PLUGIN_DIR . 'includes/class-pandat69-ajax.php';
require_once PANDAT69_PLUGIN_DIR . 'includes/class-pandat69-email.php';
require_once PANDAT69_PLUGIN_DIR . 'includes/class-pandat69-notifications.php';
require_once PANDAT69_PLUGIN_DIR . 'includes/class-pandat69-deadline-notifications.php';



/**
 * Load BuddyPress integration if BuddyPress is active.
 */
function pandat69_load_buddypress_integration() {
    // Check if BuddyPress function exists AND our integration class hasn't been loaded yet
    if ( function_exists( 'buddypress' ) && ! class_exists( 'Pandat69_BuddyPress' ) ) {
        require_once PANDAT69_PLUGIN_DIR . 'includes/class-pandat69-buddypress.php';
        // Use the singleton pattern getter
        Pandat69_BuddyPress::get_instance();
    }
}
// Increase priority slightly to ensure BuddyPress is loaded first (default is 10)
add_action( 'plugins_loaded', 'pandat69_load_buddypress_integration', 20 );

/**
 * Activation hook callback.
 */
function pandat69_activate_plugin() {
    // Ensure DB class is loaded before calling activate
    require_once PANDAT69_PLUGIN_DIR . 'includes/class-pandat69-db.php';
    if ( class_exists( 'Pandat69_DB' ) ) {
        Pandat69_DB::activate();
    }
}
register_activation_hook( PANDAT69_PLUGIN_FILE, 'pandat69_activate_plugin' );

/**
 * Initialize plugin classes after plugins are loaded.
 */
function pandat69_initialize_plugin() {
    // Initialize classes if they exist
    if ( class_exists( 'Pandat69_Shortcode' ) ) {
        $pandat69_shortcode = new Pandat69_Shortcode();
        $pandat69_shortcode->register();
    }

    if ( class_exists( 'Pandat69_Ajax' ) ) {
        $pandat69_ajax = new Pandat69_Ajax();
        $pandat69_ajax->register();
    }
    
    // Initialize notifications integration
    if ( class_exists( 'Pandat69_Notifications' ) ) {
        Pandat69_Notifications::init();
    }
}
add_action('plugins_loaded', 'pandat69_initialize_plugin');


/**
 * Enqueue scripts and styles.
 */
function pandat69_enqueue_scripts() {

    // Only enqueue on front-end. Consider adding checks if the shortcode is actually present.
    if ( is_admin() ) {
        return;
    }

    // Enqueue Styles
    wp_enqueue_style(
        'pandat69-jquery-ui-base',
        PANDAT69_PLUGIN_URL . 'assets/css/jquery-ui.css', 
        array(), 
        '1.12.1' 
    );    wp_enqueue_style( 'pandat69-style', PANDAT69_PLUGIN_URL . 'assets/css/pandat69-style.css', array(), PANDAT69_VERSION );

    // Enqueue Scripts
    wp_enqueue_script( 'jquery' );
    wp_enqueue_script( 'jquery-ui-datepicker' );
    wp_enqueue_script( 'jquery-ui-autocomplete' );

    // Enqueue WordPress editor assets (for TinyMCE dependencies)
    wp_enqueue_editor();

    // Enqueue your custom script
    wp_enqueue_script(
        'pandat69-script',
        PANDAT69_PLUGIN_URL . 'assets/js/pandat69-script.js',
        array( 'jquery', 'jquery-ui-datepicker', 'jquery-ui-autocomplete', 'editor', 'quicktags' ),
        PANDAT69_VERSION,
        true // Load in footer
    );

    // Localize script - Pass data to JavaScript
    $tinymce_settings = array(
        'selector' => '.pandat69-tinymce-editor', // Target class
        'plugins' => 'lists link image', // Keep desired plugins
        'toolbar' => 'undo redo | formatselect | bold italic | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | link image | code', // Simplified toolbar
        'menubar' => false, // Hide the menubar
        'height' => 200, // Set a default height
        'promotion' => false, // Disable "Upgrade" promotion in TinyMCE 6+
    );

    wp_localize_script(
        'pandat69-script',
        'pandat69_ajax_object',
        array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'pandat69_ajax_nonce' ),
            'current_user_id' => get_current_user_id(),
            // Get display name safely
            'current_user_display_name' => ( is_user_logged_in() ? wp_get_current_user()->display_name : '' ),
            'tinymce_settings' => $tinymce_settings,
            'text' => array(
                'confirm_delete_task'     => esc_js(__( 'Are you sure you want to delete this task?', 'pandatask' )),
                'confirm_delete_category' => esc_js(__( 'Are you sure you want to delete this category? Tasks using it will lose their category.', 'pandatask' )),
                'error_general'           => esc_js(__( 'An error occurred. Please try again.', 'pandatask' )),
                'no_results_found'        => esc_js(__( 'No users found', 'pandatask' )),
                'type_to_search'          => esc_js(__( 'Type to search users...', 'pandatask' )),
                'searching'               => esc_js(__( 'Searching...', 'pandatask' )),
            )
        )
    );
}
add_action( 'wp_enqueue_scripts', 'pandat69_enqueue_scripts' );

/**
 * Check if BuddyPress is active.
 * Uses bp_is_active to check if the core component is running.
 *
 * @return bool True if BuddyPress is active, false otherwise.
 */
function pandat69_is_buddypress_active() {
    // Check if the main BuddyPress function exists AND the core component is active.
    return function_exists( 'buddypress' ) && bp_is_active('activity'); // Check a core component like activity

}