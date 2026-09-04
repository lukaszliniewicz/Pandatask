<?php

namespace Pandatask\Http\Rest\V1;

final class ProjectReferenceRouteRegistrar {

    private $namespace;
    private $permissions;
    private $handler;

    public function __construct( $namespace = 'pandatask/v1', $permissions = null, $handler = null ) {
        $this->namespace = $namespace;
        $this->permissions = $permissions ?: new PermissionChecker();
        $this->handler = $handler ?: new ProjectReferenceRouteHandler();
    }

    public function register() {
        register_rest_route(
            $this->namespace,
            '/projects/(?P<id>\d+)/workspace',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this->handler, 'workspace' ),
                'permission_callback' => array( $this->permissions, 'check_project_permission' ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/projects/(?P<id>\d+)/references',
            array(
                array(
                    'methods'             => 'GET',
                    'callback'            => array( $this->handler, 'list_references' ),
                    'permission_callback' => array( $this->permissions, 'check_project_permission' ),
                ),
                array(
                    'methods'             => 'POST',
                    'callback'            => array( $this->handler, 'create_reference' ),
                    'permission_callback' => array( $this->permissions, 'check_project_manage_permission' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/projects/(?P<id>\d+)/references/(?P<reference_key>(?:reference|dependency)-\d+)',
            array(
                array(
                    'methods'             => 'PATCH',
                    'callback'            => array( $this->handler, 'update_reference' ),
                    'permission_callback' => array( $this->permissions, 'check_project_manage_permission' ),
                ),
                array(
                    'methods'             => 'DELETE',
                    'callback'            => array( $this->handler, 'delete_reference' ),
                    'permission_callback' => array( $this->permissions, 'check_project_manage_permission' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/projects/(?P<id>\d+)/references/export',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this->handler, 'export_references' ),
                'permission_callback' => array( $this->permissions, 'check_project_manage_permission' ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/projects/(?P<id>\d+)/references/import',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this->handler, 'import_references' ),
                'permission_callback' => array( $this->permissions, 'check_project_manage_permission' ),
            )
        );
    }
}
