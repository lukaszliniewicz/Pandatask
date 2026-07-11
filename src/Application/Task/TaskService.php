<?php

namespace Pandatask\Application\Task;

use Pandatask\Application\Board\BoardService;
use Pandatask\Application\Comment\CommentService;
use Pandatask\Infrastructure\Persistence\DatabaseContext;
use Pandatask\Infrastructure\Media\ProtectedAttachmentService;
use Pandatask\Infrastructure\Persistence\TaskRepository;

final class TaskService {

    private $repository;

    private $board_service;

    private $comment_service;

    private $mutation_service;

    public function __construct( $repository = null, $board_service = null, $comment_service = null, $mutation_service = null ) {
        $this->repository       = $repository ?: new TaskRepository();
        $this->board_service    = $board_service ?: new BoardService();
        $this->comment_service  = $comment_service ?: new CommentService();
        $this->mutation_service = $mutation_service ?: new TaskMutationService();
    }

    public function isTaskBlocked( $task_id ) {
        return $this->repository->isBlocked( $task_id );
    }

    public function createTask( $data ) {
        return $this->mutation_service->createTask( $data );
    }

    public function updateTask( $task_id, $data, $change_comment = '', $actor_id = null ) {
        $actor_id = null === $actor_id ? get_current_user_id() : (int) $actor_id;

        return $this->mutation_service->updateTask( $task_id, $data, $change_comment, $actor_id );
    }

    public function deleteTask( $task_id, $delete_scope = null ) {
        return $this->mutation_service->deleteTask( (int) $task_id, $delete_scope );
    }

    public function getTasks( $board_name, $search = '', $sort_by = 'name', $sort_order = 'ASC', $status_filter = '', $date_filter = '', $start_date = '', $end_date = '', $archived = 0, $project_filter = null, $include_templates = false, $task_type_filter = '', $user_id = null ) {
        $version       = DatabaseContext::getBoardCacheVersion( $board_name, 'tasks' );
        $args_key      = md5( serialize( func_get_args() ) );
        $transient_key = "pandat69_tasks_{$board_name}_{$version}_{$args_key}";
        $cached_tasks  = get_transient( $transient_key );

        if ( false !== $cached_tasks ) {
            return ProtectedAttachmentService::prepareTasks( $cached_tasks );
        }

        $tasks = $this->repository->findForBoard( $board_name, $search, $sort_by, $sort_order, $status_filter, $date_filter, $start_date, $end_date, $archived, $project_filter, $include_templates, $task_type_filter, $user_id );
        set_transient( $transient_key, $tasks, HOUR_IN_SECONDS );

        return ProtectedAttachmentService::prepareTasks( $tasks );
    }

    public function getTask( $task_id ) {
        $transient_key = 'pandat69_task_' . $task_id;
        $cached_task   = get_transient( $transient_key );

        if ( false !== $cached_task ) {
            return ProtectedAttachmentService::prepareTask( $cached_task );
        }

        $task = $this->repository->findById( $task_id );

        if ( ! $task ) {
            return $task;
        }

        $task->board_display_name = $this->board_service->getBoardDisplayName( $task->board_name );
        $task->comments           = $this->comment_service->getComments( $task_id, $task );
        $task->history            = array();
        $task->description        = $task->description ?? '';

        set_transient( $transient_key, $task, 12 * HOUR_IN_SECONDS );

        return ProtectedAttachmentService::prepareTask( $task );
    }

    public function getTaskByName( $board_name, $task_name ) {
        return $this->repository->findIdByName( $board_name, $task_name );
    }

    public function isTaskOnBoard( $task_id, $board_name ) {
        return $this->repository->existsOnBoard( $task_id, $board_name );
    }

    public function getTasksForUserAcrossBoards( $user_id, $search = '', $sort_by = 'name', $sort_order = 'ASC', $status_filter = '', $archived = 0, $project_filter = null, $private_only = false, $include_templates = false ) {
        $version       = $this->getUserCacheVersion( $user_id );
        $args_key      = md5( serialize( func_get_args() ) );
        $transient_key = "pandat69_user_tasks_{$user_id}_{$version}_{$args_key}";
        $cached_tasks  = get_transient( $transient_key );

        if ( false !== $cached_tasks ) {
            return ProtectedAttachmentService::prepareTasks( $cached_tasks );
        }

        $tasks = $this->repository->findForUserAcrossBoards( $user_id, $search, $sort_by, $sort_order, $status_filter, $archived, $project_filter, $private_only, $include_templates );

        foreach ( $tasks as $task ) {
            $task->board_display_name = $this->board_service->getBoardDisplayName( $task->board_name );
        }

        set_transient( $transient_key, $tasks, HOUR_IN_SECONDS );

        return ProtectedAttachmentService::prepareTasks( $tasks );
    }

    public function getPotentialParentTasks( $board_name, $current_task_id = 0 ) {
        $version       = DatabaseContext::getBoardCacheVersion( $board_name, 'parent_tasks' );
        $transient_key = "pandat69_parent_tasks_{$board_name}_{$current_task_id}_{$version}";
        $cached_tasks  = get_transient( $transient_key );

        if ( false !== $cached_tasks ) {
            return $cached_tasks;
        }

        $tasks = $this->repository->findPotentialParentTasks( $board_name, $current_task_id );
        set_transient( $transient_key, $tasks, 12 * HOUR_IN_SECONDS );

        return $tasks;
    }

    private function getUserCacheVersion( $user_id ) {
        $version = get_transient( 'pandat69_v_user_' . $user_id );

        return false === $version ? 1 : (int) $version;
    }
}
