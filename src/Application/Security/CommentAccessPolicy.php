<?php

namespace Pandatask\Application\Security;

use Pandatask\Application\Comment\CommentService;
use WP_Error;

final class CommentAccessPolicy {

    private $comment_service;

    private $task_access_policy;

    public function __construct( $comment_service = null, $task_access_policy = null ) {
        $this->comment_service = $comment_service ?: new CommentService();
        $this->task_access_policy = $task_access_policy ?: new TaskAccessPolicy();
    }

    public function canManageComment( $comment_id ) {
        $comment = $this->comment_service->getComment( (int) $comment_id );

        if ( ! $comment ) {
            return new WP_Error( 'rest_not_found', 'Comment not found', array( 'status' => 404 ) );
        }

        $task_permission = $this->task_access_policy->canReadTask( (int) $comment->task_id, get_current_user_id() );

        if ( true !== $task_permission ) {
            return $task_permission;
        }

        if ( ! $this->comment_service->canUserManageComment( $comment ) ) {
            return new WP_Error( 'rest_forbidden', 'You cannot manage this comment.', array( 'status' => 403 ) );
        }

        return true;
    }
}
