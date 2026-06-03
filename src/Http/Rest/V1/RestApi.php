<?php

namespace Pandatask\Http\Rest\V1;

use Pandatask\Http\Rest\V1\Support\SchemaProvider;

final class RestApi {

    private $route_registrar;

    public function __construct() {
        $permission_checker = new PermissionChecker();
        $schema_provider    = new SchemaProvider();

        $task_route_handler     = new TaskRouteHandler();
        $project_route_handler  = new ProjectRouteHandler();
        $category_route_handler = new CategoryRouteHandler();
        $comment_route_handler  = new CommentRouteHandler();

        $batch_action_handler = new BatchActionHandler( $task_route_handler, $project_route_handler, $category_route_handler, $comment_route_handler );
        $directory_route_handler = new DirectoryRouteHandler();
        $report_route_handler = new ReportRouteHandler();
        $ai_prompt_route_handler = new AiPromptRouteHandler( null, null, null, $schema_provider );

        $this->route_registrar = new RouteRegistrar(
            'pandatask/v1',
            $permission_checker,
            $directory_route_handler,
            $task_route_handler,
            $project_route_handler,
            $category_route_handler,
            $comment_route_handler,
            $report_route_handler,
            $ai_prompt_route_handler,
            $batch_action_handler
        );
    }

    public function registerRoutes() {
        $this->route_registrar->register();
    }

    public function register_routes() {
        $this->registerRoutes();
    }
}
