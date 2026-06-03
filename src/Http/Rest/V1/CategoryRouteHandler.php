<?php

namespace Pandatask\Http\Rest\V1;

use Exception;
use Pandatask\Application\Category\CategoryService;
use Pandatask\Http\Rest\V1\Support\RequestHelper;
use WP_Error;
use WP_REST_Response;

final class CategoryRouteHandler {

    private $category_service;

    public function __construct( $category_service = null ) {
        $this->category_service = $category_service ?: new CategoryService();
    }

    public function get_categories( $request ) {
        return new WP_REST_Response( array( 'categories' => $this->category_service->getCategories( $request['board_name'] ) ), 200 );
    }

    public function create_category( $request ) {
        $params = RequestHelper::bodyParams( $request );
        $id     = $this->category_service->addCategory( $request['board_name'], $params['name'] );

        if ( ! $id ) {
            return new WP_Error( 'rest_error', 'Failed', array( 'status' => 500 ) );
        }

        if ( RequestHelper::isMinimalResponse( $request ) ) {
            return new WP_REST_Response( array( 'message' => 'Category added', 'id' => $id ), 201 );
        }

        return new WP_REST_Response( array( 'category' => array( 'id' => $id, 'name' => $params['name'] ) ), 201 );
    }

    public function delete_category( $request ) {
        if ( $this->category_service->deleteCategory( $request['id'], $request['board_name'] ) ) {
            return new WP_REST_Response( array( 'message' => 'Deleted' ), 200 );
        }

        return new WP_Error( 'rest_error', 'Failed', array( 'status' => 500 ) );
    }

    public function create_category_from_batch( $board_name, $data ) {
        if ( ! $board_name ) {
            throw new Exception( '`board_name` is required for create actions.' );
        }

        $category_id = $this->category_service->addCategory( $board_name, $data['name'] );

        if ( ! $category_id ) {
            return new WP_Error( 'rest_error', 'Failed', array( 'status' => 500 ) );
        }

        return array(
            'category' => array(
                'id'   => $category_id,
                'name' => $data['name'],
            ),
        );
    }

    public function delete_category_from_batch( $board_name, $data ) {
        if ( ! isset( $data['id'] ) ) {
            throw new Exception( 'ID is required for delete actions.' );
        }

        if ( ! $board_name ) {
            throw new Exception( '`board_name` is required for delete_category.' );
        }

        if ( ! $this->category_service->deleteCategory( $data['id'], $board_name ) ) {
            return new WP_Error( 'rest_error', 'Failed', array( 'status' => 500 ) );
        }

        return array( 'message' => 'Deleted' );
    }
}
