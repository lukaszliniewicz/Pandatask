<?php

namespace Pandatask\Http\Rest\V1;

final class InboxRouteRegistrar {

    private $namespace;
    private $permissions;
    private $handler;

    public function __construct( $namespace = 'pandatask/v1', $permissions = null, $handler = null ) {
        $this->namespace = $namespace;
        $this->permissions = $permissions ?: new PermissionChecker();
        $this->handler = $handler ?: new InboxRouteHandler();
    }

    public function register() {
        register_rest_route(
            $this->namespace,
            '/users/me/inbox',
            array(
                array(
                    'methods' => 'GET',
                    'callback' => array( $this->handler, 'list_my_inbox' ),
                    'permission_callback' => array( $this->permissions, 'check_user_logged_in_permission' ),
                    'args' => $this->listArgs(),
                ),
                array(
                    'methods' => 'POST',
                    'callback' => array( $this->handler, 'capture_to_my_inbox' ),
                    'permission_callback' => array( $this->permissions, 'check_user_logged_in_permission' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/users/(?P<user_id>\d+)/inbox',
            array(
                array(
                    'methods' => 'GET',
                    'callback' => array( $this->handler, 'list_owner_inbox' ),
                    'permission_callback' => array( $this->permissions, 'check_user_logged_in_permission' ),
                    'args' => $this->listArgs(),
                ),
                array(
                    'methods' => 'POST',
                    'callback' => array( $this->handler, 'capture_to_owner_inbox' ),
                    'permission_callback' => array( $this->permissions, 'check_user_logged_in_permission' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/users/me/inbox/delegates',
            array(
                array(
                    'methods' => 'GET',
                    'callback' => array( $this->handler, 'delegates' ),
                    'permission_callback' => array( $this->permissions, 'check_user_logged_in_permission' ),
                ),
                array(
                    'methods' => array( 'PUT', 'POST' ),
                    'callback' => array( $this->handler, 'replace_delegates' ),
                    'permission_callback' => array( $this->permissions, 'check_user_logged_in_permission' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/users/me/inbox/shared-with-me',
            array(
                'methods' => 'GET',
                'callback' => array( $this->handler, 'shared_with_me' ),
                'permission_callback' => array( $this->permissions, 'check_user_logged_in_permission' ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/tasks/(?P<id>\d+)/inbox-state',
            array(
                'methods' => array( 'PATCH', 'POST' ),
                'callback' => array( $this->handler, 'set_state' ),
                'permission_callback' => array( $this->permissions, 'check_user_logged_in_permission' ),
            )
        );
    }

    private function listArgs() {
        return array(
            'search' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
            'status' => array(
                'type' => 'string',
                'enum' => array( 'all', 'pending', 'in-progress', 'done', 'pending_in-progress', 'missed_deadline' ),
                'default' => 'all',
                'sanitize_callback' => 'sanitize_key',
            ),
            'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 100 ),
            'offset' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
        );
    }
}
