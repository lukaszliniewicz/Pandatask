<?php

namespace Pandatask\Bootstrap;

use Pandatask\Admin\AdminPage;
use Pandatask\Frontend\TaskBoardShortcode;
use Pandatask\Infrastructure\Setup\DatabaseLifecycle;
use Pandatask\Infrastructure\Notifications\BuddyPressNotifier;

final class CoreRegistrar {

    public function register() {
        add_action( 'plugins_loaded', array( $this, 'initializePlugin' ) );
        add_action( 'rest_api_init', array( $this, 'registerRestApi' ) );
    }

    public function initializePlugin() {
        $shortcode = new TaskBoardShortcode();
        $shortcode->register();

        BuddyPressNotifier::init();

        if ( is_admin() ) {
            $admin = new AdminPage();
            $admin->register();
        }

        if ( is_admin() ) {
            DatabaseLifecycle::updateDbCheck();
        }
    }

    public function registerRestApi() {
        $rest_api = new \Pandatask\Http\Rest\V1\RestApi();
        $rest_api->registerRoutes();
    }
}
