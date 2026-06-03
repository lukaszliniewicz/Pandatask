<?php

namespace Pandatask\Bootstrap;

final class FrontendRegistrar {

    public function register() {
        add_action( 'init', array( $this, 'addRewriteRules' ) );
        add_filter( 'query_vars', array( $this, 'addQueryVars' ) );
        add_action( 'template_redirect', array( $this, 'renderFullscreenTemplate' ) );
        add_action( 'wp_footer', array( $this, 'renderFloatingBugReporter' ) );
    }

    public function addRewriteRules() {
        add_rewrite_rule(
            '^pandatask-fullscreen/?$',
            'index.php?pandatask_fullscreen_page=1',
            'top'
        );
    }

    public function addQueryVars( $vars ) {
        $vars[] = 'pandatask_fullscreen_page';

        return $vars;
    }

    public function renderFullscreenTemplate() {
        if ( ! get_query_var( 'pandatask_fullscreen_page' ) ) {
            return;
        }

        $fullscreen_template = PANDAT69_PLUGIN_DIR . 'templates/fullscreen-template.php';

        if ( ! file_exists( $fullscreen_template ) ) {
            return;
        }

        status_header( 200 );
        include $fullscreen_template;
        exit;
    }

    public function renderFloatingBugReporter() {
        if ( is_admin() ) {
            return;
        }

        $settings = get_option( 'pandatask_bug_tracker_settings', array() );

        $default_visibility = isset( $settings['enable'] ) && $settings['enable'] ? 'logged_in' : 'off';
        $visibility         = isset( $settings['visibility'] ) ? $settings['visibility'] : $default_visibility;

        if ( 'off' === $visibility ) {
            return;
        }

        if ( 'logged_in' === $visibility && ! is_user_logged_in() ) {
            return;
        }

        if ( 'logged_out' === $visibility && is_user_logged_in() ) {
            return;
        }

        if ( empty( $settings['board'] ) ) {
            return;
        }

        $board_name          = $settings['board'];
        $default_assignee_id = isset( $settings['assignee'] ) ? $settings['assignee'] : 0;
        ?>
        <div id="pandat69-floating-bug-reporter-root"
             data-board-name="<?php echo esc_attr( $board_name ); ?>"
             data-default-assignee-id="<?php echo esc_attr( $default_assignee_id ); ?>">
        </div>
        <?php
    }
}
