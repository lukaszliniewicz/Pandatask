<?php

namespace Pandatask;

use Pandatask\Bootstrap\AssetRegistrar;
use Pandatask\Bootstrap\CoreRegistrar;
use Pandatask\Bootstrap\CronRegistrar;
use Pandatask\Bootstrap\FrontendRegistrar;
use Pandatask\Infrastructure\Scheduler\DeadlineNotificationHandler;
use Pandatask\Infrastructure\Setup\DatabaseLifecycle;
use Pandatask\Integration\BuddyPress\BuddyPressRegistrar;

final class Plugin {

    private static $instance;

    private $core_registrar;

    private $asset_registrar;

    private $frontend_registrar;

    private $cron_registrar;

    private $buddypress_registrar;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        $this->core_registrar       = new CoreRegistrar();
        $this->asset_registrar      = new AssetRegistrar();
        $this->frontend_registrar   = new FrontendRegistrar();
        $this->cron_registrar       = new CronRegistrar();
        $this->buddypress_registrar = new BuddyPressRegistrar();
    }

    public function boot() {
        $this->core_registrar->register();
        $this->asset_registrar->register();
        $this->frontend_registrar->register();
        $this->cron_registrar->register();
        $this->buddypress_registrar->register();
    }

    public static function activate() {
        DatabaseLifecycle::activate();

        $frontend_registrar = new FrontendRegistrar();
        $frontend_registrar->addRewriteRules();

        $cron_registrar = new CronRegistrar();
        $cron_registrar->registerSchedulers();
        $cron_registrar->initializeDeadlineNotifications();

        flush_rewrite_rules();
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( 'pandat69_daily_task_start_check' );
        wp_clear_scheduled_hook( 'pandat69_check_recurring_tasks' );
        wp_clear_scheduled_hook( 'pandat69_check_deadlines' );
        wp_clear_scheduled_hook( 'pandatask_process_buffered_changes' );

        DeadlineNotificationHandler::deactivate();

        flush_rewrite_rules();
    }
}
