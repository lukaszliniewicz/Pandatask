<?php

namespace Pandatask\Http\Rest\V1;

use Pandatask\Application\Category\CategoryService;
use Pandatask\Application\Project\ProjectService;
use Pandatask\Application\User\UserDirectoryService;
use Pandatask\Http\Rest\V1\Support\SchemaProvider;
use WP_REST_Response;

final class AiPromptRouteHandler {

    private $user_directory_service;

    private $project_service;

    private $category_service;

    private $schema_provider;

    public function __construct( $user_directory_service = null, $project_service = null, $category_service = null, $schema_provider = null ) {
        $this->user_directory_service = $user_directory_service ?: new UserDirectoryService();
        $this->project_service        = $project_service ?: new ProjectService();
        $this->category_service       = $category_service ?: new CategoryService();
        $this->schema_provider        = $schema_provider ?: new SchemaProvider();
    }

    public function generate_ai_prompt( $request ) {
        $board_name  = sanitize_key( $request['board_name'] );
        $user_prompt = sanitize_textarea_field( $request['user_prompt'] );
        $users       = $this->user_directory_service->getUsersForBoard( $board_name, '' );

        $user_list = "AVAILABLE USERS:\n";

        foreach ( $users as $user ) {
            $user_list .= '- Name: ' . $user['name'] . ', ID: ' . $user['id'] . "\n";
        }

        $full_prompt  = "You are a helpful assistant for the Pandatask WordPress plugin. Your task is to analyze the user's request and convert it into a structured JSON array of actions to be executed via a REST API. Each object in the array represents a single API call.\n\n";
        $full_prompt .= "USER REQUEST:\n\"" . $user_prompt . "\"\n\n";
        $full_prompt .= "You must respond with ONLY a valid JSON array of action objects. Do not include any explanatory text before or after the JSON.\n\n";
        $full_prompt .= "The JSON output must be an array, even if there is only one action. The format for each action object is: {\"action\": \"action_name\", \"data\": {...}}\n\n";
        $full_prompt .= "The selected board for these actions is '{$board_name}'. You do not need to include `board_name` in the `data` payload for actions like `create_task`, as it's handled by the endpoint URL. However, for some actions like `delete_category`, you MUST include it if the API documentation says so.\n\n";
        $full_prompt .= $this->schema_provider->get_api_schema_for_prompt();
        $full_prompt .= $user_list . "\n";

        $projects = $this->project_service->getProjects( $board_name );

        if ( ! empty( $projects ) ) {
            $project_list = "AVAILABLE PROJECTS on this board:\n";

            foreach ( $projects as $project ) {
                $project_list .= '- Name: ' . esc_html( $project->name ) . ', ID: ' . $project->id . "\n";
            }

            $full_prompt .= $project_list . "\n";
        }

        $categories = $this->category_service->getCategories( $board_name );

        if ( ! empty( $categories ) ) {
            $category_list = "AVAILABLE CATEGORIES on this board:\n";

            foreach ( $categories as $category ) {
                $category_list .= '- Name: ' . esc_html( $category->name ) . ', ID: ' . $category->id . "\n";
            }

            $full_prompt .= $category_list . "\n";
        }

        return new WP_REST_Response( array( 'prompt' => $full_prompt ), 200 );
    }
}
