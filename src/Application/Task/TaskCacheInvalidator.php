<?php

namespace Pandatask\Application\Task;

use Pandatask\Infrastructure\Persistence\DatabaseContext;
use Pandatask\Infrastructure\Persistence\TaskRepository;

/**
 * Centralizes the cache fan-out caused by task and board metadata mutations.
 */
final class TaskCacheInvalidator {

    private $repository;

    public function __construct( $repository = null ) {
        $this->repository = $repository ?: new TaskRepository();
    }

    /**
     * @param string        $board_name Board identifier.
     * @param array<string> $types Board cache families.
     * @param array<int>    $extra_user_ids Users affected outside current task membership.
     */
    public function invalidateBoard( $board_name, array $types = array( 'tasks', 'projects', 'parent_tasks', 'reports' ), array $extra_user_ids = array() ) {
        $board_name = sanitize_key( $board_name );

        if ( '' === $board_name ) {
            return;
        }

        DatabaseContext::invalidateBoardCache( $board_name, $types );
        $user_ids = array_merge(
            $this->repository->findParticipantUserIdsForBoard( $board_name ),
            $extra_user_ids
        );

        foreach ( array_unique( array_filter( array_map( 'absint', $user_ids ) ) ) as $user_id ) {
            DatabaseContext::invalidateUserCache( $user_id );
        }
    }

    /**
     * @param int        $task_id Task identifier.
     * @param string     $board_name Board identifier.
     * @param array<int> $user_ids Explicit affected users.
     */
    public function invalidateTask( $task_id, $board_name, array $user_ids = array() ) {
        DatabaseContext::invalidateTaskCache( (int) $task_id );
        DatabaseContext::invalidateBoardCache(
            sanitize_key( $board_name ),
            array( 'tasks', 'projects', 'parent_tasks', 'reports' )
        );

        foreach ( array_unique( array_filter( array_map( 'absint', $user_ids ) ) ) as $user_id ) {
            DatabaseContext::invalidateUserCache( $user_id );
        }
    }
}
