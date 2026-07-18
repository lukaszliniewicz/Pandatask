<?php

namespace Pandatask\Bootstrap;

final class AssetRegistrar {

    private static $frontend_assets_registered = false;
    private static $floating_assets_registered = false;

    public function register() {
        add_action( 'wp_enqueue_scripts', array( $this, 'registerFrontendAssets' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'registerFloatingReporterAssets' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'maybeEnqueueFrontendAssets' ), 20 );
        add_action( 'wp_enqueue_scripts', array( $this, 'maybeEnqueueFloatingReporterAssets' ), 30 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAdminAssets' ) );
    }

    public function registerFrontendAssets() {
        self::registerFrontendAssetHandles();
    }

    public function registerFloatingReporterAssets() {
        self::registerFloatingReporterAssetHandles();
    }

    public function maybeEnqueueFrontendAssets() {
        if ( is_admin() || ! $this->shouldEnqueueFrontendAssets() ) {
            return;
        }

        $this->enqueueFrontendAssets();
    }

    public function maybeEnqueueFloatingReporterAssets() {
        if ( is_admin() || ! $this->floatingBugReporterIsVisible() || $this->shouldEnqueueFrontendAssets() ) {
            return;
        }

        self::enqueueFloatingReporterAssetHandles();
    }

    public function enqueueFrontendAssets() {
        if ( is_admin() ) {
            return;
        }

        self::enqueueFrontendAssetHandles();
    }

    public static function enqueueFrontendAssetHandles() {
        if ( ! self::registerFrontendAssetHandles() ) {
            return;
        }

        wp_enqueue_style( 'pandat69-style' );

        if ( current_user_can( 'upload_files' ) ) {
            wp_enqueue_media();
        }

        wp_enqueue_script( 'pandat69-bundle' );
    }

    public static function enqueueFloatingReporterAssetHandles() {
        if ( ! self::registerFloatingReporterAssetHandles() ) {
            return;
        }

        wp_enqueue_style( 'pandat69-floating-reporter-style' );
        wp_enqueue_script( 'pandat69-floating-reporter' );
    }

    private static function registerFrontendAssetHandles() {
        if ( self::$frontend_assets_registered ) {
            return true;
        }

        $asset_file_path = PANDAT69_PLUGIN_DIR . 'build/main.asset.php';

        if ( ! file_exists( $asset_file_path ) ) {
            return false;
        }

        $asset_file = include $asset_file_path;

        wp_register_style(
            'pandat69-style',
            PANDAT69_PLUGIN_URL . 'build/main.css',
            array(),
            $asset_file['version']
        );

        wp_register_script(
            'pandat69-bundle',
            PANDAT69_PLUGIN_URL . 'build/main.js',
            $asset_file['dependencies'],
            $asset_file['version'],
            true
        );

        wp_localize_script(
            'pandat69-bundle',
            'pandatask_api_settings',
            self::getFrontendApiSettings()
        );

        self::$frontend_assets_registered = true;

        return true;
    }

    private static function registerFloatingReporterAssetHandles() {
        if ( self::$floating_assets_registered ) {
            return true;
        }

        $asset_file_path = PANDAT69_PLUGIN_DIR . 'build/main.asset.php';
        $asset_file      = file_exists( $asset_file_path ) ? include $asset_file_path : array();
        $asset_version   = isset( $asset_file['version'] ) ? $asset_file['version'] : PANDAT69_VERSION;

        wp_register_style(
            'pandat69-floating-reporter-style',
            PANDAT69_PLUGIN_URL . 'assets/css/floating-bug-reporter.css',
            array(),
            $asset_version
        );

        wp_register_script(
            'pandat69-floating-reporter',
            PANDAT69_PLUGIN_URL . 'assets/js/floating-bug-reporter.js',
            array(),
            $asset_version,
            true
        );

        wp_localize_script(
            'pandat69-floating-reporter',
            'pandatask_floating_reporter_settings',
            array(
                'fullScriptUrl' => esc_url_raw( add_query_arg( 'ver', $asset_version, PANDAT69_PLUGIN_URL . 'build/main.js' ) ),
                'fullStyleUrl'  => esc_url_raw( add_query_arg( 'ver', $asset_version, PANDAT69_PLUGIN_URL . 'build/main.css' ) ),
                'apiSettings'   => self::getFrontendApiSettings(),
            )
        );

        self::$floating_assets_registered = true;

        return true;
    }

    private static function getFrontendApiSettings() {
        return array(
            'root'                      => esc_url_raw( rest_url( 'pandatask/v1/' ) ),
            'nonce'                     => wp_create_nonce( 'wp_rest' ),
            'home_url'                  => home_url( '/' ),
            'current_user_id'           => get_current_user_id(),
            'current_user_display_name' => is_user_logged_in() ? wp_get_current_user()->display_name : '',
            'text'                      => array(
                'confirm_delete_task'     => esc_js( __( 'Are you sure you want to delete this task?', 'pandatask' ) ),
                'confirm_delete_category' => esc_js( __( 'Are you sure you want to delete this category? Tasks using it will lose their category.', 'pandatask' ) ),
                'error_general'           => esc_js( __( 'An error occurred. Please try again.', 'pandatask' ) ),
                'no_results_found'        => esc_js( __( 'No users found', 'pandatask' ) ),
                'type_to_search'          => esc_js( __( 'Type to search users...', 'pandatask' ) ),
                'searching'               => esc_js( __( 'Searching...', 'pandatask' ) ),
                'delete_recurring_title'  => esc_js( __( 'Delete Recurring Task', 'pandatask' ) ),
                'delete_recurring_text'   => esc_js( __( 'This is a recurring task. How would you like to delete it?', 'pandatask' ) ),
                'delete_single_instance'  => esc_js( __( 'This Instance Only', 'pandatask' ) ),
                'delete_all_instances'    => esc_js( __( 'This & All Future', 'pandatask' ) ),
                'cancel'                  => esc_js( __( 'Cancel', 'pandatask' ) ),
            ),
        );
    }

    private function shouldEnqueueFrontendAssets() {
        if ( get_query_var( 'pandatask_fullscreen_page' ) ) {
            return true;
        }

        if ( $this->postContainsPandataskShortcode() ) {
            return true;
        }

        if ( $this->isBuddyPressPandataskSurface() ) {
            return true;
        }

        return false;
    }

    private function postContainsPandataskShortcode() {
        global $post;

        if ( ! is_a( $post, 'WP_Post' ) ) {
            return false;
        }

        return has_shortcode( $post->post_content, 'task_board' )
            || has_shortcode( $post->post_content, 'pandatask_bug_tracker' );
    }

    private function isBuddyPressPandataskSurface() {
        $request_path = isset( $_SERVER['REQUEST_URI'] )
            ? ( wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) ?: '' )
            : '';

        if ( preg_match( '#/(groups|members)/[^/]+/(tasks|bug-tracker)(/|$)#', $request_path ) ) {
            return true;
        }

        if (
            function_exists( 'bp_is_group_single' )
            && bp_is_group_single()
            && function_exists( 'bp_is_current_action' )
            && ( bp_is_current_action( 'tasks' ) || bp_is_current_action( 'bug-tracker' ) )
        ) {
            return true;
        }

        if (
            function_exists( 'bp_is_user' )
            && bp_is_user()
            && function_exists( 'bp_current_component' )
            && 'tasks' === bp_current_component()
        ) {
            return true;
        }

        return false;
    }

    private function floatingBugReporterIsVisible() {
        $settings = get_option( 'pandatask_bug_tracker_settings', array() );

        $default_visibility = isset( $settings['enable'] ) && $settings['enable'] ? 'logged_in' : 'off';
        $visibility         = isset( $settings['visibility'] ) ? $settings['visibility'] : $default_visibility;

        if ( 'off' === $visibility || empty( $settings['board'] ) ) {
            return false;
        }

        if ( 'logged_in' === $visibility ) {
            return is_user_logged_in();
        }

        if ( 'logged_out' === $visibility ) {
            return ! is_user_logged_in();
        }

        return true;
    }

    public function enqueueAdminAssets( $hook_suffix ) {
        if ( 'toplevel_page_pandatask-ai-assistant' !== $hook_suffix ) {
            return;
        }

        wp_enqueue_style(
            'pandat69-admin-style',
            PANDAT69_PLUGIN_URL . 'assets/css/pandat69-admin-style.css',
            array(),
            PANDAT69_VERSION
        );

        wp_enqueue_script(
            'pandat69-admin-script',
            PANDAT69_PLUGIN_URL . 'assets/js/pandat69-admin.js',
            array( 'jquery' ),
            PANDAT69_VERSION,
            true
        );

        wp_localize_script(
            'pandat69-admin-script',
            'pandataskAdminSettings',
            array(
                'root'  => esc_url_raw( rest_url( 'pandatask/v1/' ) ),
                'nonce' => wp_create_nonce( 'wp_rest' ),
            )
        );
    }
}
