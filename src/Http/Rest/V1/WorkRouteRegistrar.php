<?php

namespace Pandatask\Http\Rest\V1;

use Pandatask\Application\Settings\FeatureSettings;
use Pandatask\Application\Security\WorkEntryAccessPolicy;
use Pandatask\Application\Security\WorkLogShareAccessPolicy;
use Pandatask\Application\Work\WorkLogShareService;

final class WorkRouteRegistrar {

    private $namespace;
    private $permissions;
    private $handler;
    private $work_entry_access_policy;
    private $feature_settings;
    private $work_log_share_access_policy;
    private $work_log_share_service;

    public function __construct( $namespace = 'pandatask/v1', $permissions = null, $handler = null, $work_entry_access_policy = null, $feature_settings = null, $work_log_share_access_policy = null, $work_log_share_service = null ) {
        $this->namespace                = $namespace;
        $this->permissions              = $permissions ?: new PermissionChecker();
        $this->handler                  = $handler ?: new WorkRouteHandler();
        $this->work_entry_access_policy = $work_entry_access_policy ?: new WorkEntryAccessPolicy();
        $this->feature_settings         = $feature_settings ?: new FeatureSettings();
        $this->work_log_share_access_policy = $work_log_share_access_policy;
        $this->work_log_share_service = $work_log_share_service;
    }

    public function register() {
        if ( ! $this->feature_settings->workLogEnabled() ) {
            $this->registerTaskCompletionRoute();
            return;
        }

        $this->work_log_share_access_policy = $this->work_log_share_access_policy ?: new WorkLogShareAccessPolicy();
        $this->work_log_share_service = $this->work_log_share_service ?: new WorkLogShareService();

        register_rest_route(
            $this->namespace,
            '/work/activity-types',
            array(
                array(
                    'methods'             => 'GET',
                    'callback'            => array( $this->handler, 'activity_types' ),
                    'permission_callback' => array( $this->permissions, 'check_user_logged_in_permission' ),
                ),
                array(
                    'methods'             => 'POST',
                    'callback'            => array( $this->handler, 'create_activity_type' ),
                    'permission_callback' => array( $this->permissions, 'check_user_logged_in_permission' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/work/activity-types/(?P<key>[a-zA-Z0-9_-]{1,32})',
            array(
                array(
                    'methods'             => array( 'PATCH', 'POST' ),
                    'callback'            => array( $this->handler, 'update_activity_type' ),
                    'permission_callback' => array( $this->permissions, 'check_user_logged_in_permission' ),
                ),
                array(
                    'methods'             => 'DELETE',
                    'callback'            => array( $this->handler, 'delete_activity_type' ),
                    'permission_callback' => array( $this->permissions, 'check_user_logged_in_permission' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/users/me/work-entries',
            array(
                array(
                    'methods'             => 'GET',
                    'callback'            => array( $this->handler, 'list_my_entries' ),
                    'permission_callback' => array( $this->permissions, 'check_user_logged_in_permission' ),
                    'args'                => array(
                        'start_date' => array( 'type' => 'string' ),
                        'end_date'   => array( 'type' => 'string' ),
                        'limit'      => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 200 ),
                        'offset'     => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
                    ),
                ),
                array(
                    'methods'             => 'POST',
                    'callback'            => array( $this->handler, 'create_entry' ),
                    'permission_callback' => array( $this->permissions, 'check_user_logged_in_permission' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/users/me/work-suggestions',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this->handler, 'list_my_suggestions' ),
                'permission_callback' => array( $this->permissions, 'check_user_logged_in_permission' ),
                'args'                => array(
                    'start_date' => array( 'type' => 'string' ),
                    'end_date'   => array( 'type' => 'string' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/users/me/work-suggestions/confirm',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this->handler, 'confirm_suggestion' ),
                'permission_callback' => array( $this->permissions, 'check_user_logged_in_permission' ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/users/me/work-suggestions/dismiss',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this->handler, 'dismiss_suggestion' ),
                'permission_callback' => array( $this->permissions, 'check_user_logged_in_permission' ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/work-entries/(?P<id>\\d+)',
            array(
                array(
                    'methods'             => array( 'PATCH', 'POST' ),
                    'callback'            => array( $this->handler, 'update_entry' ),
                    'permission_callback' => array( $this, 'check_entry_manage_permission' ),
                ),
                array(
                    'methods'             => 'DELETE',
                    'callback'            => array( $this->handler, 'delete_entry' ),
                    'permission_callback' => array( $this, 'check_entry_manage_permission' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/tasks/(?P<id>\\d+)/work',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this->handler, 'task_work' ),
                'permission_callback' => array( $this->permissions, 'check_task_read_permission' ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/work-occurrences/(?P<id>\\d+)/time-resolution',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this->handler, 'resolve_occurrence_time' ),
                'permission_callback' => array( $this->permissions, 'check_user_logged_in_permission' ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/tasks/(?P<id>\\d+)/time-resolution',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this->handler, 'resolve_task_time' ),
                'permission_callback' => array( $this->permissions, 'check_task_read_permission' ),
            )
        );

        $this->registerTaskCompletionRoute();

        register_rest_route(
            $this->namespace,
            '/users/me/work-report',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this->handler, 'personal_report' ),
                'permission_callback' => array( $this->permissions, 'check_user_logged_in_permission' ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/users/me/work-log-sharing',
            array(
                array(
                    'methods'             => 'GET',
                    'callback'            => array( $this->handler, 'work_log_sharing' ),
                    'permission_callback' => array( $this, 'check_work_log_sharing_permission' ),
                ),
                array(
                    'methods'             => 'PUT',
                    'callback'            => array( $this->handler, 'replace_work_log_sharing' ),
                    'permission_callback' => array( $this, 'check_work_log_sharing_permission' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/groups/(?P<group_id>\\d+)/work-logs',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this->handler, 'group_work_logs' ),
                'permission_callback' => array( $this, 'check_group_work_logs_permission' ),
                'args'                => array(
                    'start_date' => array( 'type' => 'string' ),
                    'end_date'   => array( 'type' => 'string' ),
                    'period'     => array( 'type' => 'string' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/groups/(?P<group_id>\\d+)/work-logs/(?P<user_id>\\d+)',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this->handler, 'group_work_log' ),
                'permission_callback' => array( $this, 'check_group_work_log_permission' ),
                'args'                => array(
                    'start_date' => array( 'type' => 'string' ),
                    'end_date'   => array( 'type' => 'string' ),
                    'period'     => array( 'type' => 'string' ),
                    'limit'      => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 200 ),
                    'offset'     => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/boards/(?P<board_name>[a-zA-Z0-9_-]+)/work-report',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this->handler, 'board_report' ),
                'permission_callback' => array( $this->permissions, 'check_board_read_permission' ),
            )
        );
    }

    public function check_entry_manage_permission( $request ) {
        return $this->work_entry_access_policy->canManageEntry( (int) $request['id'], get_current_user_id() );
    }

    public function check_work_log_sharing_permission( $request ) {
        return $this->workLogShareAccessPolicy()->canManageOwnSharing( get_current_user_id() );
    }

    public function check_group_work_logs_permission( $request ) {
        return $this->workLogShareAccessPolicy()->canReadGroup( (int) $request['group_id'], get_current_user_id() );
    }

    public function check_group_work_log_permission( $request ) {
        return $this->workLogShareAccessPolicy()->canReadOwner(
            (int) $request['group_id'],
            (int) $request['user_id'],
            get_current_user_id()
        );
    }

    private function workLogShareAccessPolicy() {
        if ( ! $this->work_log_share_access_policy ) {
            $this->work_log_share_access_policy = new WorkLogShareAccessPolicy();
        }
        return $this->work_log_share_access_policy;
    }

    private function registerTaskCompletionRoute() {
        register_rest_route(
            $this->namespace,
            '/tasks/(?P<id>\\d+)/complete',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this->handler, 'complete_task' ),
                'permission_callback' => array( $this->permissions, 'check_task_update_permission' ),
            )
        );
    }
}
