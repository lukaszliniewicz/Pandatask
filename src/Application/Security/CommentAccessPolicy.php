<?php

namespace Pandatask\Application\Security;

use Pandatask\Application\Comment\CommentService;
use WP_Error;

final class CommentAccessPolicy {

    private $comment_service;

    public function __construct( $comment_service = null ) {
        $this->comment_service = $comment_service ?: new CommentService();
    }

    public function canManageComment( $comment_id ) {
        $comment = $this->comment_service->getComment( (int) $comment_id );

        if ( ! $comment ) {
            return new WP_Error( 'rest_not_found', 'Comment not found', array( 'status' => 404 ) );
        }

        if ( ! $this->comment_service->canUserManageComment( $comment ) ) {
            return new WP_Error( 'rest_forbidden', 'You cannot manage this comment.', array( 'status' => 403 ) );
        }

        return true;
    }
}
