<?php
/**
 * BuddyPress Profile integration for Task Board
 */
namespace Pandatask\Integration\BuddyPress;

use Pandatask\Bootstrap\AssetRegistrar;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ProfileTasksPage {

    private static $instance;

    /**
     * Get the singleton instance
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        if ( function_exists( 'buddypress' ) ) {
            $this->setup_hooks();
        }
    }

    /**
     * Setup hooks
     */
    private function setup_hooks() {
        add_action( 'bp_setup_nav', array( $this, 'add_profile_nav_item' ) );
    }

    /**
     * Add "My Tasks" tab to user profiles
     */
    public function add_profile_nav_item() {
        if ( ! bp_is_user() ) {
            return;
        }

        bp_core_new_nav_item( array(
            'name'                    => __( 'My Tasks', 'pandatask' ),
            'slug'                    => 'tasks',
            'position'                => 100,
            'screen_function'         => array( $this, 'screen_function_callback' ),
            'default_subnav_slug'     => 'tasks',
            'show_for_displayed_user' => true // Show for the user being viewed
        ) );
    }

    /**
     * Callback function for the profile tab screen.
     */
    public function screen_function_callback() {
        AssetRegistrar::enqueueFrontendAssetHandles();

        add_action( 'bp_template_content', array( $this, 'screen_content' ) );
        bp_core_load_template( apply_filters( 'bp_core_template_plugin', 'members/single/plugins' ) );
    }

    /**
     * Display the task board content on the profile page.
     */
    public function screen_content() {
        $displayed_user_id = bp_displayed_user_id();
        $current_user_id = get_current_user_id();

        // Security check: Only the user themselves or an admin can view their private task board.
        if ( $displayed_user_id !== $current_user_id && !current_user_can('manage_options') ) {
            echo '<div id="message" class="bp-feedback error"><p>' . esc_html__('You do not have permission to view this user\'s tasks.', 'pandatask') . '</p></div>';
            return;
        }

        $board_name = 'user_' . $displayed_user_id;
        echo do_shortcode('[task_board board_name="' . esc_attr($board_name) . '"]');
    }
}
