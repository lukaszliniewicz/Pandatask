<?php

namespace Pandatask\Application\Task;

use Pandatask\Application\Security\InboxAccessPolicy;
use Pandatask\Infrastructure\Persistence\DatabaseContext;
use Pandatask\Infrastructure\Persistence\InboxDelegateRepository;
use WP_Error;

final class InboxService {

    private $task_service;
    private $mutation_service;
    private $access_policy;
    private $delegate_repository;

    public function __construct( $task_service = null, $mutation_service = null, $access_policy = null, $delegate_repository = null ) {
        $this->task_service = $task_service ?: new TaskService();
        $this->mutation_service = $mutation_service ?: new TaskMutationService();
        $this->access_policy = $access_policy ?: new InboxAccessPolicy();
        $this->delegate_repository = $delegate_repository ?: new InboxDelegateRepository();
    }

    public function listInbox( $owner_user_id, $actor_id, $search = '', $status_filter = '', $limit = 100, $offset = 0 ) {
        $permission = $this->access_policy->canReadInbox( $owner_user_id, $actor_id );
        if ( true !== $permission ) {
            return $permission;
        }

        return $this->task_service->getInboxTasks( $owner_user_id, $search, $status_filter, $limit, $offset );
    }

    public function capture( $owner_user_id, array $input, $actor_id ) {
        $permission = $this->access_policy->canSubmitToInbox( $owner_user_id, $actor_id );
        if ( true !== $permission ) {
            return $permission;
        }

        $name = sanitize_text_field( $input['name'] ?? $input['title'] ?? '' );
        if ( '' === $name ) {
            return new WP_Error( 'rest_invalid_param', __( 'A captured item requires a title.', 'pandatask' ), array( 'status' => 422 ) );
        }

        $description = TaskDescriptionService::sanitize( $input['description'] ?? '' );
        $source_url = isset( $input['source_url'] ) ? esc_url_raw( $input['source_url'] ) : '';
        $source = sanitize_key( $input['capture_source'] ?? 'quick_capture' );
        if ( '' === $source ) {
            $source = 'quick_capture';
        }

        $data = array(
            'board_name'     => 'user_' . (int) $owner_user_id,
            'name'           => $name,
            'description'    => $description,
            'status'         => 'pending',
            'priority'       => max( 1, min( 10, absint( $input['priority'] ?? 5 ) ) ),
            'task_type'      => 'task',
            'inbox_state'    => 'untriaged',
            'capture_source' => substr( $source, 0, 32 ),
            'capture_url'    => $source_url ?: null,
            'is_recurring'   => false,
        );

        if ( $source_url && empty( $input['attachment_type'] ) ) {
            $data['attachment_type'] = 'link';
            $data['attachment_url'] = $source_url;
            $data['attachment_filename'] = sanitize_text_field( $input['source_title'] ?? $name );
        }

        foreach ( array( 'attachment_type', 'attachment_url', 'attachment_post_id', 'attachment_filename' ) as $field ) {
            if ( array_key_exists( $field, $input ) ) {
                $data[ $field ] = $input[ $field ];
            }
        }

        $task_id = $this->mutation_service->createTask(
            $data,
            array(
                'actor_id'   => (int) $actor_id,
                'creator_id' => (int) $owner_user_id,
            )
        );
        if ( is_wp_error( $task_id ) ) {
            return $task_id;
        }
        if ( ! $task_id ) {
            return new WP_Error( 'pandatask_capture_failed', __( 'The item could not be captured.', 'pandatask' ), array( 'status' => 500 ) );
        }

        return $this->task_service->getTask( $task_id );
    }

    public function setState( $task_id, $state, $actor_id ) {
        $task = $this->task_service->getTaskForAuthorization( (int) $task_id );
        if ( ! $task || empty( $task->inbox_state ) ) {
            return new WP_Error( 'rest_inbox_task_not_found', __( 'Inbox item not found.', 'pandatask' ), array( 'status' => 404 ) );
        }
        $owner_user_id = InboxAccessPolicy::ownerFromBoardName( $task->board_name );
        $permission = $this->access_policy->canTriageInbox( $owner_user_id, $actor_id );
        if ( true !== $permission ) {
            return $permission;
        }

        $state = sanitize_key( $state );
        if ( ! in_array( $state, array( 'untriaged', 'reviewed' ), true ) ) {
            return new WP_Error( 'rest_invalid_param', __( 'Inbox state must be untriaged or reviewed.', 'pandatask' ), array( 'status' => 422 ) );
        }

        $result = $this->mutation_service->updateTask(
            (int) $task_id,
            array( 'inbox_state' => $state ),
            __( 'Inbox triage state changed.', 'pandatask' ),
            (int) $actor_id
        );

        return is_wp_error( $result ) ? $result : $this->task_service->getTask( (int) $task_id );
    }

    public function delegates( $owner_user_id, $actor_id ) {
        if ( (int) $owner_user_id !== (int) $actor_id && ! user_can( $actor_id, 'manage_options' ) ) {
            return new WP_Error( 'rest_forbidden_inbox', __( 'Only the inbox owner may manage delegation.', 'pandatask' ), array( 'status' => 403 ) );
        }

        return $this->decorateDelegates( $this->delegate_repository->listForOwner( $owner_user_id ) );
    }

    public function replaceDelegates( $owner_user_id, array $delegates, $actor_id ) {
        if ( (int) $owner_user_id !== (int) $actor_id && ! user_can( $actor_id, 'manage_options' ) ) {
            return new WP_Error( 'rest_forbidden_inbox', __( 'Only the inbox owner may manage delegation.', 'pandatask' ), array( 'status' => 403 ) );
        }

        $normalized = array();
        $seen = array();
        foreach ( $delegates as $delegate ) {
            $user_id = absint( $delegate['user_id'] ?? 0 );
            $role = sanitize_key( $delegate['role'] ?? '' );
            if ( $user_id <= 0 || $user_id === (int) $owner_user_id || ! get_userdata( $user_id ) || ! in_array( $role, array( 'contributor', 'triager' ), true ) ) {
                return new WP_Error( 'rest_invalid_param', __( 'Each inbox delegate requires a valid user and contributor or triager role.', 'pandatask' ), array( 'status' => 422 ) );
            }
            if ( isset( $seen[ $user_id ] ) ) {
                return new WP_Error( 'rest_invalid_param', __( 'Each inbox delegate may appear only once.', 'pandatask' ), array( 'status' => 422 ) );
            }
            $seen[ $user_id ] = true;
            $normalized[] = array( 'user_id' => $user_id, 'role' => $role );
        }

        if ( ! $this->delegate_repository->replaceForOwner( $owner_user_id, $normalized ) ) {
            return new WP_Error( 'pandatask_inbox_delegation_failed', __( 'Inbox delegation could not be saved.', 'pandatask' ), array( 'status' => 500 ) );
        }

        DatabaseContext::invalidateUserCache( (int) $owner_user_id );
        return $this->decorateDelegates( $this->delegate_repository->listForOwner( $owner_user_id ) );
    }

    private function decorateDelegates( array $rows ) {
        foreach ( $rows as $row ) {
            $user = get_userdata( (int) $row->user_id );
            $row->display_name = $user ? $user->display_name : '';
        }
        return $rows;
    }
}
