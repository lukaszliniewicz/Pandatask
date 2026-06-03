<?php

namespace Pandatask\Application\Comment;

use Pandatask\Infrastructure\Notifications\EmailNotifier;
use Pandatask\Infrastructure\Persistence\DatabaseContext;
use Pandatask\Infrastructure\Persistence\CommentRepository;
use Pandatask\Infrastructure\Persistence\TaskRepository;

final class CommentService {

    private $repository;

    private $task_repository;

    public function __construct( $repository = null, $task_repository = null ) {
        $this->repository      = $repository ?: new CommentRepository();
        $this->task_repository = $task_repository ?: new TaskRepository();
    }

    public function getComments( $task_id, $task = null ) {
        return $this->repository->findForTask( $task_id, $task );
    }

    public function addComment( $task_id, $user_id, $comment_text ) {
        $comment = $this->repository->create( $task_id, $user_id, $comment_text );

        if ( ! $comment ) {
            return false;
        }

        EmailNotifier::send_comment_notification( $task_id, $user_id, $comment_text );

        $this->invalidateTaskCaches( $task_id );

        return $comment;
    }

    public function canUserManageComment( $comment, $task = null ) {
        return $this->repository->canUserManageComment( $comment, $task );
    }

    public function getComment( $comment_id ) {
        return $this->repository->findById( $comment_id );
    }

    public function updateComment( $comment_id, $comment_text ) {
        $result = $this->repository->update( $comment_id, $comment_text );

        if ( $result ) {
            $comment = $this->repository->findById( $comment_id );

            if ( $comment ) {
                $this->invalidateTaskCaches( $comment->task_id );
            }
        }

        return $result;
    }

    public function deleteComment( $comment_id ) {
        $comment = $this->repository->findById( $comment_id );

        if ( ! $comment ) {
            return false;
        }

        $result = $this->repository->delete( $comment_id );

        if ( $result ) {
            $this->invalidateTaskCaches( $comment->task_id );
        }

        return $result;
    }

    private function invalidateTaskCaches( $task_id ) {
        delete_transient( 'pandat69_task_' . $task_id );

        $task = $this->task_repository->findById( $task_id );

        if ( $task ) {
            DatabaseContext::invalidateBoardCache( $task->board_name );
        }
    }
}
