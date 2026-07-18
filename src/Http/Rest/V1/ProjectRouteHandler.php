<?php

namespace Pandatask\Http\Rest\V1;

use Exception;
use Pandatask\Application\Project\ProjectService;
use Pandatask\Application\Security\BoardAccessPolicy;
use Pandatask\Http\Rest\V1\Support\RequestHelper;
use WP_Error;
use WP_REST_Response;

final class ProjectRouteHandler {

    private $project_service;

    private $board_access_policy;

    public function __construct( $project_service = null, $board_access_policy = null ) {
        $this->project_service     = $project_service ?: new ProjectService();
        $this->board_access_policy = $board_access_policy ?: new BoardAccessPolicy();
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
        $params               = $this->sanitizeProjectData( RequestHelper::bodyParams( $request ), false, $request['board_name'] );

        if ( is_wp_error( $params ) ) {
            return $params;
        }

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
        $project = $this->project_service->getProject( (int) $request['id'] );

        if ( ! $project ) {
            return new WP_Error( 'rest_not_found', 'Project not found', array( 'status' => 404 ) );
        }

        $params = $this->sanitizeProjectData( RequestHelper::bodyParams( $request ), true, $project->board_name );

        if ( is_wp_error( $params ) ) {
            return $params;
        }

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

        $data = $this->sanitizeProjectData( $data, false, $board_name );

        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $data['board_name'] = sanitize_key( $board_name );
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
        $project = $this->project_service->getProject( $project_id );

        if ( ! $project ) {
            return new WP_Error( 'rest_not_found', 'Project not found', array( 'status' => 404 ) );
        }

        $sanitized_data = $this->sanitizeProjectData( $data, true, $project->board_name );

        if ( is_wp_error( $sanitized_data ) ) {
            return $sanitized_data;
        }

        $result = $this->project_service->updateProject( $project_id, $sanitized_data );

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

    private function sanitizeProjectData( $data, $is_update = false, $board_name = '' ) {
        $sanitized = array();

        if ( ! $is_update || array_key_exists( 'name', $data ) ) {
            $sanitized['name'] = sanitize_text_field( $data['name'] ?? '' );

            if ( '' === $sanitized['name'] ) {
                return new WP_Error( 'rest_missing', __( 'Project name is required.', 'pandatask' ), array( 'status' => 400 ) );
            }
        }

        if ( array_key_exists( 'description', $data ) ) {
            $sanitized['description'] = sanitize_textarea_field( $data['description'] );
        } elseif ( ! $is_update ) {
            $sanitized['description'] = '';
        }

        if ( array_key_exists( 'deadline', $data ) ) {
            $deadline = sanitize_text_field( $data['deadline'] );

            $parsed_deadline = $deadline ? \DateTimeImmutable::createFromFormat( '!Y-m-d', $deadline, wp_timezone() ) : false;

            if ( $deadline && ( ! $parsed_deadline || $parsed_deadline->format( 'Y-m-d' ) !== $deadline ) ) {
                return new WP_Error( 'rest_invalid_date', __( 'Project deadline must use YYYY-MM-DD.', 'pandatask' ), array( 'status' => 422 ) );
            }

            $sanitized['deadline'] = $deadline;
        } elseif ( ! $is_update ) {
            $sanitized['deadline'] = '';
        }

        foreach ( array( 'assigned_persons', 'supervisor_persons' ) as $field ) {
            if ( array_key_exists( $field, $data ) ) {
                $sanitized[ $field ] = RequestHelper::parseIdList( $data[ $field ] );

                foreach ( $sanitized[ $field ] as $user_id ) {
                    if ( ! $this->board_access_policy->isUserAllowedOnBoard( $board_name, $user_id ) ) {
                        return new WP_Error( 'rest_invalid_user', __( 'A selected project user is not eligible for this board.', 'pandatask' ), array( 'status' => 422 ) );
                    }
                }
            }
        }

        return $sanitized;
    }
}
