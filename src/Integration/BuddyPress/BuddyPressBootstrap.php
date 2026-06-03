<?php
/**
 * Task Board BuddyPress Integration
 */
namespace Pandatask\Integration\BuddyPress;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BuddyPressBootstrap {
    
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
            if ( class_exists( GroupTasksExtension::class, true ) ) {
                bp_register_group_extension( GroupTasksExtension::class );
            }
            
            // Register Bug Tracker extension if the class exists
            if ( class_exists( GroupBugTrackerExtension::class, true ) ) {
                bp_register_group_extension( GroupBugTrackerExtension::class );
            }
        }
    }
}
