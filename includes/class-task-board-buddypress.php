<?php
/**
 * Task Board BuddyPress Integration
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Task_Board_BuddyPress {
    
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
        // Only load if BuddyPress is active
        if ( function_exists( 'buddypress' ) ) {
            $this->setup_hooks();
        }
    }
    
    /**
     * Setup hooks
     */
    private function setup_hooks() {
        // Hook into BuddyPress init
        add_action( 'bp_init', array( $this, 'register_group_extension' ) );
    }
    
    /**
     * Register the group extension
     */
    public function register_group_extension() {
        // Only register if Groups component is active
        if ( bp_is_active( 'groups' ) ) {
            require_once TBP_PLUGIN_DIR . 'includes/class-task-board-group-extension.php';
            bp_register_group_extension( 'Task_Board_Group_Extension' );
        }
    }
}