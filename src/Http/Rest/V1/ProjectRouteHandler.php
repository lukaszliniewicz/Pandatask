<?php

namespace Pandatask\Http\Rest\V1;

use Exception;
use Pandatask\Application\Project\ProjectService;
use Pandatask\Http\Rest\V1\Support\RequestHelper;
use WP_Error;
use WP_REST_Response;

final class ProjectRouteHandler {

    private $project_service;

    public function __construct( $project_service = null ) {
        $this->project_service = $project_service ?: new ProjectService();
    }

    public function get_projects( $request ) {
        return new WP_REST_Response( array( 'projects' => $this->project_service->getProjects( $request['board_name'] ) ), 200 );
    }

    public function get_project( $request ) {
        $project = $this->project_service->getProject( (int) $request['id'] );

        if ( ! $project ) {
            return new WP_Error( 'rest_not_found', 'Project not found', array( 'status' => 404 ) );
        }

        return new WP_REST_Response( array( 'project' => $project ), 200 );
    }

    public function create_project( $request ) {
        $params               = RequestHelper::bodyParams( $request );
        $params['board_name'] = $request['board_name'];
        $id                   = $this->project_service->addProject( $params );

        if ( ! $id ) {
            return new WP_Error( 'rest_error', 'Failed', array( 'status' => 500 ) );
        }

        if ( RequestHelper::isMinimalResponse( $request ) ) {
            return new WP_REST_Response( array( 'message' => 'Project added', 'id' => $id ), 201 );
        }

        return new WP_REST_Response( array( 'project' => $this->project_service->getProject( $id ) ), 201 );
    }

    public function update_project( $request ) {
        $params = RequestHelper::bodyParams( $request );

        if ( $this->project_service->updateProject( $request['id'], $params ) ) {
            return new WP_REST_Response( array( 'project' => $this->project_service->getProject( $request['id'] ) ), 200 );
        }

        return new WP_Error( 'rest_error', 'Failed', array( 'status' => 500 ) );
    }

    public function delete_project( $request ) {
        if ( $this->project_service->deleteProject( $request['id'] ) ) {
            return new WP_REST_Response( array( 'message' => 'Deleted' ), 200 );
        }

        return new WP_Error( 'rest_error', 'Failed', array( 'status' => 500 ) );
    }

    public function create_project_from_batch( $board_name, $data ) {
        if ( ! $board_name ) {
            throw new Exception( '`board_name` is required for create actions.' );
        }

        $data['board_name'] = $board_name;
        $project_id         = $this->project_service->addProject( $data );

        if ( ! $project_id ) {
            return new WP_Error( 'rest_error', 'Failed', array( 'status' => 500 ) );
        }

        return array( 'project' => $this->project_service->getProject( $project_id ) );
    }

    public function update_project_from_batch( $data ) {
        if ( ! isset( $data['id'] ) ) {
            throw new Exception( 'ID is required for update actions.' );
        }

        $project_id = (int) $data['id'];
        $result     = $this->project_service->updateProject( $project_id, $data );

        if ( ! $result ) {
            return new WP_Error( 'rest_error', 'Failed', array( 'status' => 500 ) );
        }

        return array( 'project' => $this->project_service->getProject( $project_id ) );
    }

    public function delete_project_from_batch( $data ) {
        if ( ! isset( $data['id'] ) ) {
            throw new Exception( 'ID is required for delete actions.' );
        }

        if ( ! $this->project_service->deleteProject( (int) $data['id'] ) ) {
            return new WP_Error( 'rest_error', 'Failed', array( 'status' => 500 ) );
        }

        return array( 'message' => 'Deleted' );
    }
}
