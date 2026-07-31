<?php

namespace Pandatask\Bootstrap;

use Pandatask\Application\Task\TaskMutationService;
use Pandatask\Http\Rest\V1\IdempotencyMiddleware;
use Pandatask\Infrastructure\Scheduler\DeadlineNotificationHandler;

final class CronRegistrar {

    private $task_mutation_service;

    public function __construct( $task_mutation_service = null ) {
        $this->task_mutation_service = $task_mutation_service ?: new TaskMutationService();
    }

    public function register() {
        add_action( 'init', array( $this, 'registerSchedulers' ) );
        add_action( 'init', array( $this, 'initializeDeadlineNotifications' ) );
        add_action( 'pandat69_daily_task_start_check', array( $this, 'runDailyTaskStartCheck' ) );
        add_action( 'pandat69_check_recurring_tasks', array( $this, 'runRecurringTaskCheck' ) );
        add_action( 'pandatask_process_buffered_changes', array( $this, 'processBufferedChanges' ), 10, 2 );
        add_action( 'pandatask_recover_change_buffers', array( $this, 'recoverBufferedChanges' ) );
        add_action( 'pandatask_cleanup_idempotency_locks', array( $this, 'cleanupIdempotencyLocks' ) );
    }

    public function registerSchedulers() {
        if ( ! wp_next_scheduled( 'pandat69_daily_task_start_check' ) ) {
            wp_schedule_event( time(), 'daily', 'pandat69_daily_task_start_check' );
        }

        if ( ! wp_next_scheduled( 'pandat69_check_recurring_tasks' ) ) {
            wp_schedule_event( time(), 'daily', 'pandat69_check_recurring_tasks' );
        }

        if ( ! wp_next_scheduled( 'pandatask_recover_change_buffers' ) ) {
            wp_schedule_event( time(), 'hourly', 'pandatask_recover_change_buffers' );
        }

        if ( ! wp_next_scheduled( 'pandatask_cleanup_idempotency_locks' ) ) {
            wp_schedule_event( time(), 'hourly', 'pandatask_cleanup_idempotency_locks' );
        }
    }

    public function initializeDeadlineNotifications() {
        DeadlineNotificationHandler::init();
    }

    public function runDailyTaskStartCheck() {
        $this->task_mutation_service->checkTasksToStart();
    }

    public function runRecurringTaskCheck() {
        $this->task_mutation_service->rollOverCompletedRecurringTasks();
    }

    public function processBufferedChanges( $task_id, $user_id ) {
        if ( ! $this->task_mutation_service->processBufferedChanges( $task_id, $user_id ) ) {
            wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'pandatask_process_buffered_changes', array( (int) $task_id, (int) $user_id ) );
        }
    }

    public function recoverBufferedChanges() {
        $this->task_mutation_service->recoverBufferedChanges();
    }

    public function cleanupIdempotencyLocks() {
        IdempotencyMiddleware::cleanupExpiredLocks();
    }
}
