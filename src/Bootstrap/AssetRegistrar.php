<?php

namespace Pandatask\Bootstrap;

final class AssetRegistrar {

    public function register() {
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueueFrontendAssets' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAdminAssets' ) );
    }

    public function enqueueFrontendAssets() {
        if ( is_admin() ) {
            return;
        }

        $asset_file_path = PANDAT69_PLUGIN_DIR . 'build/main.asset.php';

        if ( ! file_exists( $asset_file_path ) ) {
            return;
        }

        $asset_file = include $asset_file_path;

        wp_enqueue_style(
            'pandat69-style',
            PANDAT69_PLUGIN_URL . 'build/main.css',
            array(),
            $asset_file['version']
        );

        wp_enqueue_editor();
        wp_enqueue_media();

        wp_enqueue_script(
            'pandat69-bundle',
            PANDAT69_PLUGIN_URL . 'build/main.js',
            $asset_file['dependencies'],
            $asset_file['version'],
            true
        );

        wp_localize_script(
            'pandat69-bundle',
            'pandatask_api_settings',
            array(
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
            )
        );
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
            'pandat69_admin_object',
            array(
                'root'  => esc_url_raw( rest_url( 'pandatask/v1/' ) ),
                'nonce' => wp_create_nonce( 'wp_rest' ),
            )
        );
    }
}
