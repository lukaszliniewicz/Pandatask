<?php

namespace Pandatask\Http\Rest\V1;

use Pandatask\Application\Project\ProjectReferenceService;
use WP_REST_Response;

final class ProjectReferenceRouteHandler {

    private $service;

    public function __construct( $service = null ) {
        $this->service = $service ?: new ProjectReferenceService();
    }

    public function workspace( $request ) {
        $result = $this->service->getWorkspace( (int) $request['id'], get_current_user_id() );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
    }

    public function list_references( $request ) {
        $result = $this->service->listReferences( (int) $request['id'], get_current_user_id() );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
    }

    public function create_reference( $request ) {
        $result = $this->service->createReference( (int) $request['id'], $this->body( $request ), get_current_user_id() );
        return is_wp_error( $result )
            ? $result
            : new WP_REST_Response( array( 'message' => __( 'Reference created.', 'pandatask' ), 'reference' => $result ), 201 );
    }

    public function update_reference( $request ) {
        $result = $this->service->updateReference( (int) $request['id'], $request['reference_key'], $this->body( $request ), get_current_user_id() );
        return is_wp_error( $result )
            ? $result
            : new WP_REST_Response( array( 'message' => __( 'Reference updated.', 'pandatask' ), 'reference' => $result ), 200 );
    }

    public function delete_reference( $request ) {
        $result = $this->service->deleteReference( (int) $request['id'], $request['reference_key'], get_current_user_id() );
        return is_wp_error( $result )
            ? $result
            : new WP_REST_Response( array( 'message' => __( 'Reference deleted.', 'pandatask' ) ), 200 );
    }

    public function export_references( $request ) {
        $result = $this->service->exportReferences( (int) $request['id'], get_current_user_id() );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
    }

    public function import_references( $request ) {
        $result = $this->service->importReferences( (int) $request['id'], $this->body( $request ), get_current_user_id() );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
    }

    private function body( $request ) {
        $body = $request->get_json_params();
        return is_array( $body ) ? $body : array();
    }
}
