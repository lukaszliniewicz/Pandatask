<?php

namespace Pandatask\Http\Rest\V1;

use Exception;
use Pandatask\Application\Comment\CommentService;
use Pandatask\Http\Rest\V1\Support\RequestHelper;
use WP_Error;
use WP_REST_Response;

final class CommentRouteHandler {

    private $comment_service;

    public function __construct( $comment_service = null ) {
        $this->comment_service = $comment_service ?: new CommentService();
    }

    public function get_comments( $request ) {
        return new WP_REST_Response( $this->comment_service->getComments( $request['task_id'] ), 200 );
    }

    public function create_comment( $request ) {
        $params  = RequestHelper::bodyParams( $request );
        $comment = $this->comment_service->addComment( $request['task_id'], get_current_user_id(), $params['comment_text'] );

        if ( $comment ) {
            return new WP_REST_Response( array( 'comment' => $comment ), 201 );
        }

        return new WP_Error( 'rest_error', 'Failed', array( 'status' => 500 ) );
    }

    public function update_comment( $request ) {
        $params = RequestHelper::bodyParams( $request );

        if ( $this->comment_service->updateComment( $request['id'], $params['comment_text'] ) ) {
            return new WP_REST_Response( array( 'comment' => $this->comment_service->getComment( $request['id'] ) ), 200 );
        }

        return new WP_Error( 'rest_error', 'Failed', array( 'status' => 500 ) );
    }

    public function delete_comment( $request ) {
        if ( $this->comment_service->deleteComment( $request['id'] ) ) {
            return new WP_REST_Response( array( 'message' => 'Deleted' ), 200 );
        }

        return new WP_Error( 'rest_error', 'Failed', array( 'status' => 500 ) );
    }

    public function create_comment_from_batch( $data ) {
        if ( ! isset( $data['task_id'] ) ) {
            throw new Exception( 'Task ID is required for create_comment.' );
        }

        $comment = $this->comment_service->addComment( (int) $data['task_id'], get_current_user_id(), $data['comment_text'] );

        if ( ! $comment ) {
            return new WP_Error( 'rest_error', 'Failed', array( 'status' => 500 ) );
        }

        return array( 'comment' => $comment );
    }

    public function update_comment_from_batch( $data ) {
        if ( ! isset( $data['id'] ) ) {
            throw new Exception( 'Comment ID is required for update_comment.' );
        }

        $comment_id = (int) $data['id'];

        if ( ! $this->comment_service->updateComment( $comment_id, $data['comment_text'] ) ) {
            return new WP_Error( 'rest_error', 'Failed', array( 'status' => 500 ) );
        }

        return array( 'comment' => $this->comment_service->getComment( $comment_id ) );
    }

    public function delete_comment_from_batch( $data ) {
        if ( ! isset( $data['id'] ) ) {
            throw new Exception( 'Comment ID is required for delete_comment.' );
        }

        if ( ! $this->comment_service->deleteComment( (int) $data['id'] ) ) {
            return new WP_Error( 'rest_error', 'Failed', array( 'status' => 500 ) );
        }

        return array( 'message' => 'Deleted' );
    }
}
