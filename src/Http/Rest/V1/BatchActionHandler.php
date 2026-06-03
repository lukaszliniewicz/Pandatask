<?php

namespace Pandatask\Http\Rest\V1;

use Exception;
use WP_REST_Response;

final class BatchActionHandler {

    private $task_route_handler;

    private $project_route_handler;

    private $category_route_handler;

    private $comment_route_handler;

    public function __construct( $task_route_handler = null, $project_route_handler = null, $category_route_handler = null, $comment_route_handler = null ) {
        $this->task_route_handler     = $task_route_handler ?: new TaskRouteHandler();
        $this->project_route_handler  = $project_route_handler ?: new ProjectRouteHandler();
        $this->category_route_handler = $category_route_handler ?: new CategoryRouteHandler();
        $this->comment_route_handler  = $comment_route_handler ?: new CommentRouteHandler();
    }

    public function batch_process_actions( $request ) {
        $actions = $request->get_param( 'actions' );
        $results = array();

        foreach ( $actions as $action_item ) {
            $action             = $action_item['action'] ?? '';
            $data               = $action_item['data'] ?? array();
            $board_name         = $action_item['board_name'] ?? ( $data['board_name'] ?? null );
            $is_success         = false;
            $result_message     = '';
            $action_description = $action . ' (' . esc_html( $data['name'] ?? $data['id'] ?? 'N/A' ) . ')';

            try {
                $response_data = $this->executeBatchAction( $action, $data, $board_name );

                if ( is_wp_error( $response_data ) ) {
                    $result_message = $response_data->get_error_message();
                } else {
                    $is_success    = true;
                    $result_message = $response_data['message'] ?? 'Success.';

                    if ( isset( $response_data['id'] ) ) {
                        $result_message .= ' ID: ' . $response_data['id'];
                    }
                }
            } catch ( Exception $exception ) {
                $result_message = $exception->getMessage();
            }

            $results[] = array(
                'success'            => $is_success,
                'action_description' => $action_description,
                'message'            => esc_html( $result_message ),
            );
        }

        return new WP_REST_Response( array( 'results' => $results ), 200 );
    }

    private function executeBatchAction( $action, $data, $board_name ) {
        switch ( $action ) {
            case 'create_task':
                return $this->task_route_handler->create_task_from_batch( $board_name, $data );

            case 'update_task':
                return $this->task_route_handler->update_task_from_batch( $data );

            case 'delete_task':
                return $this->task_route_handler->delete_task_from_batch( $data );

            case 'create_project':
                return $this->project_route_handler->create_project_from_batch( $board_name, $data );

            case 'update_project':
                return $this->project_route_handler->update_project_from_batch( $data );

            case 'delete_project':
                return $this->project_route_handler->delete_project_from_batch( $data );

            case 'create_category':
                return $this->category_route_handler->create_category_from_batch( $board_name, $data );

            case 'delete_category':
                return $this->category_route_handler->delete_category_from_batch( $board_name, $data );

            case 'create_comment':
                return $this->comment_route_handler->create_comment_from_batch( $data );

            case 'update_comment':
                return $this->comment_route_handler->update_comment_from_batch( $data );

            case 'delete_comment':
                return $this->comment_route_handler->delete_comment_from_batch( $data );

            default:
                throw new Exception( 'Unknown action: ' . $action );
        }
    }
}
