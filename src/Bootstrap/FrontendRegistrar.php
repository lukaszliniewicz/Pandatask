<?php

namespace Pandatask\Bootstrap;

use Pandatask\Application\Security\BoardAccessPolicy;
use WP_Error;

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

        $board_name = isset( $_GET['board_name'] )
            ? sanitize_key( wp_unslash( $_GET['board_name'] ) )
            : '';
        $access     = '' !== $board_name
            ? ( new BoardAccessPolicy() )->canReadBoard( $board_name, get_current_user_id() )
            : new WP_Error( 'pandatask_missing_board', __( 'No task board specified.', 'pandatask' ), array( 'status' => 400 ) );
        $status     = true === $access ? 200 : (int) $access->get_error_data( 'status' );

        if ( $status < 400 ) {
            $status = true === $access ? 200 : 403;
        }

        // This compatibility endpoint contains private, user-specific application
        // state and must never enter search indexes or shared caches.
        status_header( $status );
        nocache_headers();
        header( 'X-Robots-Tag: noindex, nofollow, noarchive', true );
        add_filter( 'wp_robots', array( $this, 'disableFullscreenIndexing' ) );

        $pandatask_fullscreen_board_name = $board_name;
        $pandatask_fullscreen_access     = $access;

        include $fullscreen_template;
        exit;
    }

    public function disableFullscreenIndexing( $robots ) {
        $robots['noindex']   = true;
        $robots['nofollow']  = true;
        $robots['noarchive'] = true;

        return $robots;
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
             class="pandat69-root iarf-app iarf-app--pandatask iarf-plugin iarf-plugin--pandatask"
             data-iarf-product="pandatask"
             data-iarf-app="pandatask"
             data-iarf-plugin="pandatask"
             data-iarf-product-kind="react-plugin"
             data-board-name="<?php echo esc_attr( $board_name ); ?>"
             data-default-assignee-id="<?php echo esc_attr( $default_assignee_id ); ?>">
        </div>
        <?php
    }
}
