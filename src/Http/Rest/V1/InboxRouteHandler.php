<?php

namespace Pandatask\Http\Rest\V1;

use Pandatask\Application\Security\InboxAccessPolicy;
use Pandatask\Application\Task\InboxService;
use Pandatask\Http\Rest\V1\Support\RequestHelper;
use Pandatask\Infrastructure\Persistence\InboxDelegateRepository;
use WP_Error;
use WP_REST_Response;

final class InboxRouteHandler {

    private $service;
    private $access_policy;
    private $delegate_repository;

    public function __construct( $service = null, $access_policy = null, $delegate_repository = null ) {
        $this->access_policy = $access_policy ?: new InboxAccessPolicy();
        $this->delegate_repository = $delegate_repository ?: new InboxDelegateRepository();
        $this->service = $service ?: new InboxService( null, null, $this->access_policy, $this->delegate_repository );
    }

    public function list_my_inbox( $request ) {
        return $this->listOwnerInbox( get_current_user_id(), $request );
    }

    public function capture_to_my_inbox( $request ) {
        return $this->captureForOwner( get_current_user_id(), $request );
    }

    public function list_owner_inbox( $request ) {
        return $this->listOwnerInbox( (int) $request['user_id'], $request );
    }

    public function capture_to_owner_inbox( $request ) {
        return $this->captureForOwner( (int) $request['user_id'], $request );
    }

    public function set_state( $request ) {
        $data = $this->body( $request );
        $task = $this->service->setState( (int) $request['id'], $data['state'] ?? '', get_current_user_id() );
        return is_wp_error( $task ) ? $task : new WP_REST_Response( array( 'task' => RequestHelper::renderTask( $task ) ), 200 );
    }

    public function delegates( $request ) {
        $delegates = $this->service->delegates( get_current_user_id(), get_current_user_id() );
        return is_wp_error( $delegates ) ? $delegates : new WP_REST_Response( array( 'delegates' => $delegates ), 200 );
    }

    public function replace_delegates( $request ) {
        $data = $this->body( $request );
        $delegates = $data['delegates'] ?? null;
        if ( ! is_array( $delegates ) ) {
            return new WP_Error( 'rest_invalid_param', __( 'delegates must be an array.', 'pandatask' ), array( 'status' => 422 ) );
        }
        $result = $this->service->replaceDelegates( get_current_user_id(), $delegates, get_current_user_id() );
        return is_wp_error( $result ) ? $result : new WP_REST_Response( array( 'delegates' => $result ), 200 );
    }

    public function shared_with_me( $request ) {
        $rows = $this->delegate_repository->ownersForUser( get_current_user_id() );
        $items = array();
        foreach ( $rows as $row ) {
            $owner = get_userdata( (int) $row->owner_user_id );
            if ( ! $owner ) {
                continue;
            }
            $items[] = array(
                'owner_user_id' => (int) $row->owner_user_id,
                'owner_name'    => $owner->display_name,
                'role'          => $row->role,
                'can_read'      => 'triager' === $row->role,
                'can_submit'    => in_array( $row->role, array( 'triager', 'contributor' ), true ),
            );
        }
        return new WP_REST_Response( array( 'inboxes' => $items ), 200 );
    }

    private function listOwnerInbox( $owner_user_id, $request ) {
        $status = sanitize_key( $request['status'] ?? 'all' );
        if ( 'all' === $status ) {
            $status = '';
        }
        $limit = max( 1, min( 500, absint( $request['limit'] ?? 100 ) ) );
        $offset = max( 0, absint( $request['offset'] ?? 0 ) );
        $tasks = $this->service->listInbox(
            (int) $owner_user_id,
            get_current_user_id(),
            sanitize_text_field( $request['search'] ?? '' ),
            $status,
            $limit + 1,
            $offset
        );
        if ( is_wp_error( $tasks ) ) {
            return $tasks;
        }
        $has_more = count( $tasks ) > $limit;
        if ( $has_more ) {
            $tasks = array_slice( $tasks, 0, $limit );
        }
        RequestHelper::renderTaskCollection( $tasks );
        return new WP_REST_Response(
            array(
                'tasks' => $tasks,
                'owner_user_id' => (int) $owner_user_id,
                'role' => $this->access_policy->roleFor( $owner_user_id, get_current_user_id() ),
                'pagination' => array(
                    'limit' => $limit,
                    'offset' => $offset,
                    'returned' => count( $tasks ),
                    'has_more' => $has_more,
                    'next_offset' => $has_more ? $offset + $limit : null,
                ),
            ),
            200
        );
    }

    private function captureForOwner( $owner_user_id, $request ) {
        $task = $this->service->capture( (int) $owner_user_id, $this->body( $request ), get_current_user_id() );
        return is_wp_error( $task ) ? $task : new WP_REST_Response( array( 'task' => RequestHelper::renderTask( $task ) ), 201 );
    }

    private function body( $request ) {
        $data = $request->get_json_params();
        return is_array( $data ) ? $data : array();
    }
}
