<?php

namespace Pandatask\Http\Rest\V1;

use Pandatask\Http\Rest\V1\Support\SchemaProvider;
use WP_REST_Server;

final class RouteRegistrar {

    private $namespace;

    private $permission_checker;

    private $directory_route_handler;

    private $task_route_handler;

    private $project_route_handler;

    private $category_route_handler;

    private $comment_route_handler;

    private $report_route_handler;

    private $ai_prompt_route_handler;

    private $batch_action_handler;

    private $schema_provider;

    public function __construct( $namespace, $permission_checker, $directory_route_handler, $task_route_handler, $project_route_handler, $category_route_handler, $comment_route_handler, $report_route_handler, $ai_prompt_route_handler, $batch_action_handler, $schema_provider = null ) {
        $this->namespace               = $namespace;
        $this->permission_checker      = $permission_checker;
        $this->directory_route_handler = $directory_route_handler;
        $this->task_route_handler      = $task_route_handler;
        $this->project_route_handler   = $project_route_handler;
        $this->category_route_handler  = $category_route_handler;
        $this->comment_route_handler   = $comment_route_handler;
        $this->report_route_handler    = $report_route_handler;
        $this->ai_prompt_route_handler = $ai_prompt_route_handler;
        $this->batch_action_handler    = $batch_action_handler;
        $this->schema_provider         = $schema_provider ?: new SchemaProvider();
    }

    public function register() {
        register_rest_route(
            $this->namespace,
            '/boards',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this->directory_route_handler, 'get_boards' ),
                'permission_callback' => array( $this->permission_checker, 'check_admin_permission' ),
                'args'                => array(
                    'search' => array(
                        'description' => __( 'Search for boards by name.', 'pandatask' ),
                        'type'        => 'string',
                    ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/users/me/boards',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this->directory_route_handler, 'get_user_writable_boards' ),
                'permission_callback' => array( $this->permission_checker, 'check_user_logged_in_permission' ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/users',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this->directory_route_handler, 'get_users' ),
                'permission_callback' => array( $this->permission_checker, 'check_directory_permission' ),
                'args'                => array(
                    'search'     => array(
                        'description' => __( 'Search for users by name/email.', 'pandatask' ),
                        'type'        => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'board_name' => array(
                        'description' => __( 'If a group board name is provided, search within group members.', 'pandatask' ),
                        'type'        => 'string',
                        'sanitize_callback' => 'sanitize_key',
                    ),
                    'include'    => array(
                        'description' => __( 'User IDs that must be included in the response.', 'pandatask' ),
                        'type'        => 'array',
                        'items'       => array( 'type' => 'integer' ),
                    ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/boards/(?P<board_name>[\w-]+)/potential-parents',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this->task_route_handler, 'get_potential_parent_tasks' ),
                'permission_callback' => array( $this->permission_checker, 'check_board_read_permission' ),
                'args'                => array(
                    'board_name'      => array(
                        'description' => __( 'The name of the board.', 'pandatask' ),
                        'type'        => 'string',
                        'required'    => true,
                    ),
                    'current_task_id' => array(
                        'description' => __( 'ID of the task being edited (to exclude from parents).', 'pandatask' ),
                        'type'        => 'integer',
                    ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/boards/(?P<board_name>[\w-]+)/report',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this->report_route_handler, 'get_report' ),
                'permission_callback' => array( $this->permission_checker, 'check_board_read_permission' ),
                'args'                => array(
                    'board_name' => array(
                        'required' => true,
                        'type'     => 'string',
                    ),
                    'period'     => array(
                        'type'    => 'string',
                        'default' => 'this_week',
                    ),
                    'start_date' => array(
                        'type'   => 'string',
                        'format' => 'date',
                    ),
                    'end_date'   => array(
                        'type'   => 'string',
                        'format' => 'date',
                    ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/ai/generate-prompt',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this->ai_prompt_route_handler, 'generate_ai_prompt' ),
                'permission_callback' => array( $this->permission_checker, 'check_admin_permission' ),
                'args'                => array(
                    'board_name'  => array(
                        'required' => true,
                        'type'     => 'string',
                    ),
                    'user_prompt' => array(
                        'required' => true,
                        'type'     => 'string',
                    ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/batch',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this->batch_action_handler, 'batch_process_actions' ),
                'permission_callback' => array( $this->permission_checker, 'check_admin_permission' ),
                'args'                => array(
                    'actions' => array(
                        'description' => __( 'An array of action objects to perform.', 'pandatask' ),
                        'type'        => 'array',
                        'required'    => true,
                    ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/boards/(?P<board_name>[\w-]+)/tasks',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this->task_route_handler, 'get_tasks' ),
                    'permission_callback' => array( $this->permission_checker, 'check_board_read_permission' ),
                    'args'                => array(
                        'board_name' => array(
                            'required' => true,
                            'type'     => 'string',
                        ),
                    ),
                ),
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this->task_route_handler, 'create_task' ),
                    'permission_callback' => array( $this->permission_checker, 'check_board_write_permission' ),
                    'args'                => $this->schema_provider->get_task_schema(),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/tasks/(?P<id>\d+)',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this->task_route_handler, 'get_task' ),
                    'permission_callback' => array( $this->permission_checker, 'check_task_read_permission' ),
                ),
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this->task_route_handler, 'update_task' ),
                    'permission_callback' => array( $this->permission_checker, 'check_task_update_permission' ),
                    'args'                => $this->schema_provider->get_task_schema( true ),
                ),
                array(
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => array( $this->task_route_handler, 'delete_task' ),
                    'permission_callback' => array( $this->permission_checker, 'check_task_delete_permission' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/tasks/(?P<id>\d+)/history',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this->task_route_handler, 'get_task_history' ),
                'permission_callback' => array( $this->permission_checker, 'check_task_read_permission' ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/boards/(?P<board_name>[\w-]+)/projects',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this->project_route_handler, 'get_projects' ),
                    'permission_callback' => array( $this->permission_checker, 'check_board_read_permission' ),
                ),
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this->project_route_handler, 'create_project' ),
                    'permission_callback' => array( $this->permission_checker, 'check_board_write_permission' ),
                    'args'                => $this->schema_provider->get_project_schema(),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/projects/(?P<id>\d+)',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this->project_route_handler, 'get_project' ),
                    'permission_callback' => array( $this->permission_checker, 'check_project_permission' ),
                ),
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this->project_route_handler, 'update_project' ),
                    'permission_callback' => array( $this->permission_checker, 'check_project_manage_permission' ),
                    'args'                => $this->schema_provider->get_project_schema( true ),
                ),
                array(
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => array( $this->project_route_handler, 'delete_project' ),
                    'permission_callback' => array( $this->permission_checker, 'check_project_manage_permission' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/boards/(?P<board_name>[\w-]+)/categories',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this->category_route_handler, 'get_categories' ),
                    'permission_callback' => array( $this->permission_checker, 'check_board_read_permission' ),
                ),
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this->category_route_handler, 'create_category' ),
                    'permission_callback' => array( $this->permission_checker, 'check_board_write_permission' ),
                    'args'                => $this->schema_provider->get_category_schema(),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/categories/(?P<id>\d+)',
            array(
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => array( $this->category_route_handler, 'delete_category' ),
                'permission_callback' => array( $this->permission_checker, 'check_category_manage_permission' ),
                'args'                => array(
                    'board_name' => array(
                        'required'    => true,
                        'type'        => 'string',
                        'description' => 'Board name is required for validation.',
                    ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/tasks/(?P<task_id>\d+)/comments',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this->comment_route_handler, 'get_comments' ),
                    'permission_callback' => array( $this->permission_checker, 'check_task_read_permission' ),
                ),
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this->comment_route_handler, 'create_comment' ),
                    'permission_callback' => array( $this->permission_checker, 'check_task_read_permission' ),
                    'args'                => array(
                        'comment_text' => $this->schema_provider->get_comment_schema()['comment_text'],
                    ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/comments/(?P<id>\d+)',
            array(
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this->comment_route_handler, 'update_comment' ),
                    'permission_callback' => array( $this->permission_checker, 'check_comment_permission' ),
                    'args'                => array(
                        'comment_text' => $this->schema_provider->get_comment_schema( true )['comment_text'],
                    ),
                ),
                array(
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => array( $this->comment_route_handler, 'delete_comment' ),
                    'permission_callback' => array( $this->permission_checker, 'check_comment_permission' ),
                ),
            )
        );
    }
}
