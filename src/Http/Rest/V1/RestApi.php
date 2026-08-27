<?php

namespace Pandatask\Http\Rest\V1;

use Pandatask\Http\Rest\V1\Support\SchemaProvider;

final class RestApi {

    private $route_registrar;

    private $work_route_registrar;

    private $task_lifecycle_route_registrar;

    private $inbox_route_registrar;

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
            $batch_action_handler,
            $schema_provider
        );
        $this->work_route_registrar = new WorkRouteRegistrar( 'pandatask/v1', $permission_checker );
        $this->task_lifecycle_route_registrar = new TaskLifecycleRouteRegistrar( 'pandatask/v1', $permission_checker );
        $this->inbox_route_registrar = new InboxRouteRegistrar( 'pandatask/v1', $permission_checker );
    }

    public function registerRoutes() {
        IdempotencyMiddleware::register();
        $this->route_registrar->register();
        $this->work_route_registrar->register();
        $this->task_lifecycle_route_registrar->register();
        $this->inbox_route_registrar->register();
    }

    public function register_routes() {
        $this->registerRoutes();
    }
}
