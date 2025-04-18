<?php
/**
 * Plugin Name:       Pandatask
 * Description:       Adds a shortcode [task_board board_name="unique_board_id"] to display a task management board.
 * Version:           1.0.6
 * Author:            Lukasz Liniewicz
 * Author URI:        https://github.com/lukaszliniewicz
 * Plugin URI:        https://github.com/lukaszliniewicz/Pandatask
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       task-board-plugin
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// Define constants
define( 'TBP_VERSION', '1.0.6' ); 
define( 'TBP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TBP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'TBP_PLUGIN_FILE', __FILE__ );

// Include necessary files
require_once TBP_PLUGIN_DIR . 'includes/class-task-board-db.php';
require_once TBP_PLUGIN_DIR . 'includes/class-task-board-shortcode.php';
require_once TBP_PLUGIN_DIR . 'includes/class-task-board-ajax.php';
require_once TBP_PLUGIN_DIR . 'includes/class-task-board-email.php';

/**
 * Load BuddyPress integration if BuddyPress is active.
 */
function tbp_load_buddypress_integration() {
    // Check if BuddyPress function exists AND our integration class hasn't been loaded yet
    if ( function_exists( 'buddypress' ) && ! class_exists( 'Task_Board_BuddyPress' ) ) {
        require_once TBP_PLUGIN_DIR . 'includes/class-task-board-buddypress.php';
        // Use the singleton pattern getter
        Task_Board_BuddyPress::get_instance();
    }
}
// Increase priority slightly to ensure BuddyPress is loaded first (default is 10)
add_action( 'plugins_loaded', 'tbp_load_buddypress_integration', 20 );

/**
 * Activation hook callback.
 */
function tbp_activate_plugin() {
    // Ensure DB class is loaded before calling activate
    require_once TBP_PLUGIN_DIR . 'includes/class-task-board-db.php';
    if ( class_exists( 'Task_Board_DB' ) ) {
        Task_Board_DB::activate();
    }
}
register_activation_hook( TBP_PLUGIN_FILE, 'tbp_activate_plugin' );

/**
 * Initialize plugin classes after plugins are loaded.
 */
function tbp_initialize_plugin() {
    // Initialize classes if they exist
    if ( class_exists( 'Task_Board_Shortcode' ) ) {
        $tbp_shortcode = new Task_Board_Shortcode();
        $tbp_shortcode->register();
    }

    if ( class_exists( 'Task_Board_Ajax' ) ) {
        $tbp_ajax = new Task_Board_Ajax();
        $tbp_ajax->register();
    }
}
add_action('plugins_loaded', 'tbp_initialize_plugin');


/**
 * Enqueue scripts and styles.
 */
function tbp_enqueue_scripts() {

    // Only enqueue on front-end. Consider adding checks if the shortcode is actually present.
    if ( is_admin() ) {
        return;
    }
    
    // Check if the shortcode exists on the current page - more efficient but complex.
    // global $post;
    // if ( !is_a( $post, 'WP_Post' ) || !has_shortcode( $post->post_content, 'task_board' ) ) {
    //     // If you also use it in widgets or other places, this check needs expansion.
    //     // For now, let's assume it's okay to load if needed on front-end.
    // }

    // Enqueue Styles
    wp_enqueue_style( 'jquery-ui-css', '//ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/themes/base/jquery-ui.css', array(), '1.12.1' ); // Add version
    wp_enqueue_style( 'tbp-style', TBP_PLUGIN_URL . 'assets/css/task-board-style.css', array(), TBP_VERSION );

    // Enqueue Scripts
    wp_enqueue_script( 'jquery' ); // WordPress includes jQuery
    wp_enqueue_script( 'jquery-ui-datepicker' );
    wp_enqueue_script( 'jquery-ui-autocomplete' );

    // Enqueue WordPress editor assets (for TinyMCE dependencies)
    wp_enqueue_editor(); 

    // Enqueue the TinyMCE script itself - ensure it loads after WP's editor setup
    // Note: wp_enqueue_editor() often handles loading the core TinyMCE script.
    // Explicitly enqueuing it might be redundant but ensures it's there.
    // Check browser console for errors if TinyMCE fails to load.
    wp_enqueue_script( 
        'tiny-mce-script', 
        includes_url( 'js/tinymce/tinymce.min.js' ), 
        array('jquery', 'editor', 'quicktags'), // Dependencies
        false, // No version needed for core file usually
        true // Load in footer
    ); 

    // Enqueue your custom script
    wp_enqueue_script( 
        'tbp-script', 
        TBP_PLUGIN_URL . 'assets/js/task-board-script.js', 
        // Dependencies: Ensure all required jQuery UI components and TinyMCE are listed
        array( 'jquery', 'jquery-ui-datepicker', 'jquery-ui-autocomplete', 'tiny-mce-script' ), 
        TBP_VERSION, 
        true // Load in footer
    );

    // Localize script - Pass data to JavaScript
    $tinymce_settings = array(
        'selector' => '.tbp-tinymce-editor', // Target class
        'plugins' => 'lists link image', // Keep desired plugins
        'toolbar' => 'undo redo | formatselect | bold italic | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | link image | code', // Simplified toolbar
        'menubar' => false, // Hide the menubar
        'height' => 200, // Set a default height
        'promotion' => false, // Disable "Upgrade" promotion in TinyMCE 6+
    );

    wp_localize_script( 
        'tbp-script', 
        'tbp_ajax_object', 
        array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'tbp_ajax_nonce' ),
            'current_user_id' => get_current_user_id(),
            // Get display name safely
            'current_user_display_name' => ( is_user_logged_in() ? wp_get_current_user()->display_name : '' ), 
            'tinymce_settings' => $tinymce_settings,
            'text' => array( 
                'confirm_delete_task'     => esc_js(__( 'Are you sure you want to delete this task?', 'task-board-plugin' )),
                'confirm_delete_category' => esc_js(__( 'Are you sure you want to delete this category? Tasks using it will lose their category.', 'task-board-plugin' )),
                'error_general'           => esc_js(__( 'An error occurred. Please try again.', 'task-board-plugin' )),
                'no_results_found'        => esc_js(__( 'No users found', 'task-board-plugin' )),
                'type_to_search'          => esc_js(__( 'Type to search users...', 'task-board-plugin' )),
                'searching'               => esc_js(__( 'Searching...', 'task-board-plugin' )),
            )
        ) 
    );
}
add_action( 'wp_enqueue_scripts', 'tbp_enqueue_scripts' );

/**
 * Check if BuddyPress is active.
 * Uses bp_is_active to check if the core component is running.
 *
 * @return bool True if BuddyPress is active, false otherwise.
 */
function tbp_is_buddypress_active() {
    // Check if the main BuddyPress function exists AND the core component is active.
    return function_exists( 'buddypress' ) && bp_is_active('activity'); // Check a core component like activity
    // Alternative: just check if the main buddypress() function/singleton exists
    // return function_exists('buddypress'); 
}

/**
 * Load plugin textdomain for translation.
 */
function tbp_load_textdomain() {
    load_plugin_textdomain( 
        'task-board-plugin', // Text Domain
        false, // Deprecated argument
        dirname( plugin_basename( TBP_PLUGIN_FILE ) ) . '/languages/' // Relative path to .mo files
    ); 
}
add_action( 'plugins_loaded', 'tbp_load_textdomain' );