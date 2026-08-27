<?php

namespace Pandatask\Http\Rest\V1;

final class TaskLifecycleRouteRegistrar {

    private $namespace;
    private $permissions;
    private $handler;

    public function __construct( $namespace = 'pandatask/v1', $permissions = null, $handler = null ) {
        $this->namespace = $namespace;
        $this->permissions = $permissions ?: new PermissionChecker();
        $this->handler = $handler ?: new TaskLifecycleRouteHandler();
    }

    public function register() {
        foreach ( array( 'move-preview' => 'move_preview', 'move' => 'move_task' ) as $suffix => $callback ) {
            register_rest_route(
                $this->namespace,
                '/tasks/(?P<id>\d+)/' . $suffix,
                array(
                    'methods'             => 'POST',
                    'callback'            => array( $this->handler, $callback ),
                    'permission_callback' => array( $this->permissions, 'check_user_logged_in_permission' ),
                )
            );
        }

        register_rest_route(
            $this->namespace,
            '/tasks/(?P<id>\d+)/reopen',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this->handler, 'reopen_task' ),
                'permission_callback' => array( $this->permissions, 'check_task_update_permission' ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/tasks/(?P<id>\d+)/follow-ups',
            array(
                array(
                    'methods'             => 'GET',
                    'callback'            => array( $this->handler, 'list_follow_ups' ),
                    'permission_callback' => array( $this->permissions, 'check_task_read_permission' ),
                ),
                array(
                    'methods'             => 'POST',
                    'callback'            => array( $this->handler, 'create_follow_up' ),
                    'permission_callback' => array( $this->permissions, 'check_task_read_permission' ),
                ),
            )
        );
    }
}
