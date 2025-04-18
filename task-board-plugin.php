<?php
/**
 * Plugin Name:       Task Board Plugin
 * Description:       Adds a shortcode [task_board board_name="unique_board_id"] to display a task management board.
 * Version:           1.0.6
 * Author:            Lukasz Liniewicz
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       task-board-plugin
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

define( 'TBP_VERSION', '1.0.6' ); // Incremented version
define( 'TBP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TBP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'TBP_PLUGIN_FILE', __FILE__ );

// Include necessary files
require_once TBP_PLUGIN_DIR . 'includes/class-task-board-db.php';
require_once TBP_PLUGIN_DIR . 'includes/class-task-board-shortcode.php';
require_once TBP_PLUGIN_DIR . 'includes/class-task-board-ajax.php';
require_once TBP_PLUGIN_DIR . 'includes/class-task-board-email.php';

// Load BuddyPress integration if BuddyPress is active
function tbp_load_buddypress_integration() {
    if ( function_exists( 'buddypress' ) && !class_exists('Task_Board_BuddyPress') ) {
        require_once TBP_PLUGIN_DIR . 'includes/class-task-board-buddypress.php';
        Task_Board_BuddyPress::get_instance();
    }
}
add_action( 'plugins_loaded', 'tbp_load_buddypress_integration', 20 );

// Activation hook
register_activation_hook( TBP_PLUGIN_FILE, array( 'Task_Board_DB', 'activate' ) );

// Initialize classes
if ( class_exists( 'Task_Board_Shortcode' ) ) {
    $tbp_shortcode = new Task_Board_Shortcode();
    $tbp_shortcode->register();
}

if ( class_exists( 'Task_Board_Ajax' ) ) {
    $tbp_ajax = new Task_Board_Ajax();
    $tbp_ajax->register();
}

function tbp_enqueue_scripts() {

        wp_enqueue_style( 'jquery-ui-css', '//ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/themes/base/jquery-ui.css' );
        wp_enqueue_style( 'tbp-style', TBP_PLUGIN_URL . 'assets/css/task-board-style.css', array(), TBP_VERSION );

        wp_enqueue_script( 'jquery' );
        wp_enqueue_script( 'jquery-ui-datepicker' );
        wp_enqueue_script( 'jquery-ui-autocomplete' ); // Added for user search

        // Ensure WordPress loads the necessary TinyMCE assets and core plugins
        wp_enqueue_editor(); // <--- ADDED THIS LINE to load WP editor assets

        // Enqueue the core TinyMCE script, making sure it runs after WP's editor setup
        wp_enqueue_script( 'tiny-mce-script', includes_url( 'js/tinymce/tinymce.min.js' ), array('jquery', 'editor', 'quicktags'), false, true ); // <--- ADDED 'editor', 'quicktags' dependencies

        wp_enqueue_script( 'tbp-script', TBP_PLUGIN_URL . 'assets/js/task-board-script.js', array( 'jquery', 'jquery-ui-datepicker', 'jquery-ui-autocomplete', 'tiny-mce-script' ), TBP_VERSION, true );

        // Pass data to script
        wp_localize_script( 'tbp-script', 'tbp_ajax_object', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'tbp_ajax_nonce' ),
            'current_user_id' => get_current_user_id(),
            'current_user_display_name' => wp_get_current_user()->display_name,
            'tinymce_settings' => array( // Basic TinyMCE settings
                'selector' => '.tbp-tinymce-editor',
                // Keep desired plugins - wp_enqueue_editor() should make them available
                'plugins' => 'lists link image',
                // Keep desired toolbar buttons
                'toolbar' => 'undo redo | formatselect | bold italic | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | link image table | code | help'
            ),
            'text' => [ // Add translatable strings if needed later
                'confirm_delete_task' => __( 'Are you sure you want to delete this task?', 'task-board-plugin' ),
                'confirm_delete_category' => __( 'Are you sure you want to delete this category? Tasks using it will lose their category.', 'task-board-plugin' ),
                'error_general' => __( 'An error occurred. Please try again.', 'task-board-plugin' ),
                'no_results_found' => __( 'No users found', 'task-board-plugin' ),
                'type_to_search' => __( 'Type to search users...', 'task-board-plugin' ),
                'searching' => __( 'Searching...', 'task-board-plugin' ),
            ]
        ) );

    //}
}
add_action( 'wp_enqueue_scripts', 'tbp_enqueue_scripts' );

// Function to check if BuddyPress is active
function tbp_is_buddypress_active() {
    return function_exists( 'bp_is_active' );
}