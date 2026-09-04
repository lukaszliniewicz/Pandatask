<?php

namespace Pandatask\Application\Task;

use Pandatask\Application\Board\BoardService;
use Pandatask\Application\Comment\CommentService;
use Pandatask\Application\Security\BoardAccessPolicy;
use Pandatask\Application\Security\TaskAccessPolicy;
use Pandatask\Infrastructure\Persistence\DatabaseContext;
use Pandatask\Infrastructure\Media\ProtectedAttachmentService;
use Pandatask\Infrastructure\Notifications\TaskBoardUrlResolver;
use Pandatask\Infrastructure\Persistence\TaskRepository;

final class TaskService {

    private $repository;

    private $board_service;

    private $comment_service;

    private $mutation_service;

    private $board_access_policy;

    private $task_access_policy;

    /** @var array<int,object|null>|null */
    private $authorization_records;

    public function __construct( $repository = null, $board_service = null, $comment_service = null, $mutation_service = null, $board_access_policy = null, $task_access_policy = null ) {
        $this->repository       = $repository ?: new TaskRepository();
        $this->board_service    = $board_service ?: new BoardService();
        $this->comment_service  = $comment_service ?: new CommentService();
        $this->mutation_service = $mutation_service ?: new TaskMutationService();
        $this->board_access_policy = $board_access_policy ?: new BoardAccessPolicy();
        $this->task_access_policy = $task_access_policy;
        $this->authorization_records = null;
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

    public function completeTask( $task_id, array $completion, $change_comment = '', $actor_id = null ) {
        $actor_id = null === $actor_id ? get_current_user_id() : (int) $actor_id;

        return $this->mutation_service->completeTask( $task_id, $completion, $change_comment, $actor_id );
    }

    public function reopenTask( $task_id, $status, $reason, $actor_id = null ) {
        $actor_id = null === $actor_id ? get_current_user_id() : (int) $actor_id;
        $status = sanitize_key( $status );
        $reason = sanitize_textarea_field( $reason );

        if ( ! in_array( $status, array( 'pending', 'in-progress' ), true ) ) {
            return new \WP_Error( 'rest_invalid_param', __( 'A reopened task must return to pending or in progress.', 'pandatask' ), array( 'status' => 422 ) );
        }
        if ( '' === $reason ) {
            return new \WP_Error( 'pandatask_reopen_reason_required', __( 'Explain why the completed task is being reopened.', 'pandatask' ), array( 'status' => 422 ) );
        }

        return $this->mutation_service->updateTask(
            (int) $task_id,
            array( 'status' => $status ),
            $reason,
            $actor_id,
            null,
            'reopen'
        );
    }

    public function deleteTask( $task_id, $delete_scope = null ) {
        return $this->mutation_service->deleteTask( (int) $task_id, $delete_scope );
    }

    public function getTasks( $board_name, $search = '', $sort_by = 'name', $sort_order = 'ASC', $status_filter = '', $date_filter = '', $start_date = '', $end_date = '', $archived = 0, $project_filter = null, $include_templates = false, $task_type_filter = '', $user_id = null, $limit = 0, $offset = 0, $inbox_filter = null, $assignee_id = null ) {
        $version       = DatabaseContext::getBoardCacheVersion( $board_name, 'tasks' );
        $args_key      = md5( serialize( func_get_args() ) );
        $transient_key = "pandat69_tasks_{$board_name}_{$version}_{$args_key}";
        $cached_tasks  = get_transient( $transient_key );

        if ( false !== $cached_tasks ) {
            return $this->decorateWorkspaceTasksForViewer( $cached_tasks );
        }

        $tasks = $this->repository->findForBoard( $board_name, $search, $sort_by, $sort_order, $status_filter, $date_filter, $start_date, $end_date, $archived, $project_filter, $include_templates, $task_type_filter, $user_id, $limit, $offset, $inbox_filter, $assignee_id );
        set_transient( $transient_key, $tasks, HOUR_IN_SECONDS );

        return $this->decorateWorkspaceTasksForViewer( $tasks );
    }

    public function getTask( $task_id ) {
        $transient_key = DatabaseContext::getTaskCacheKey( $task_id );
        $cached_task   = get_transient( $transient_key );

        if ( false !== $cached_task ) {
            return $this->decorateTaskForViewer( $cached_task );
        }

        $task = $this->repository->findById( $task_id );

        if ( ! $task ) {
            return $task;
        }

        $task->description        = $task->description ?? '';

        set_transient( $transient_key, $task, 12 * HOUR_IN_SECONDS );

        return $this->decorateTaskForViewer( $task );
    }

    public function getFollowUps( $task_id ) {
        return $this->decorateWorkspaceTasksForViewer( $this->repository->findFollowUps( (int) $task_id ) );
    }

    public function getInboxTasks( $owner_user_id, $search = '', $status_filter = '', $limit = 100, $offset = 0 ) {
        $tasks = $this->repository->findInboxForOwner( (int) $owner_user_id, $search, $status_filter, $limit, $offset );
        return $this->decorateWorkspaceTasksForViewer( $tasks );
    }

    public function getTaskByName( $board_name, $task_name ) {
        return $this->repository->findIdByName( $board_name, $task_name );
    }

    public function getTaskForAuthorization( $task_id ) {
        $task_id = (int) $task_id;
        if ( is_array( $this->authorization_records ) && array_key_exists( $task_id, $this->authorization_records ) ) {
            return $this->authorization_records[ $task_id ];
        }

        return $this->repository->findAccessRecordById( $task_id );
    }

    public function getTaskHierarchyRecord( $task_id ) {
        return $this->repository->findHierarchyRecordById( (int) $task_id );
    }

    public function isTaskOnBoard( $task_id, $board_name ) {
        return $this->repository->existsOnBoard( $task_id, $board_name );
    }

    public function wouldCreateParentCycle( $task_id, $parent_task_id ) {
        return $this->repository->wouldCreateParentCycle( (int) $task_id, (int) $parent_task_id );
    }

    public function wouldCreateDependencyCycle( $task_id, $predecessor_id ) {
        return $this->repository->wouldCreateDependencyCycle( (int) $task_id, (int) $predecessor_id );
    }

    public function getTasksForUserAcrossBoards( $user_id, $search = '', $sort_by = 'name', $sort_order = 'ASC', $status_filter = '', $archived = 0, $project_filter = null, $private_only = false, $include_templates = false, $limit = 0, $offset = 0, $assignee_id = null ) {
        $version       = DatabaseContext::getUserCacheVersion( $user_id );
        $args_key      = md5( serialize( func_get_args() ) );
        $transient_key = "pandat69_user_tasks_{$user_id}_{$version}_{$args_key}";
        $cached_tasks  = get_transient( $transient_key );

        if ( false !== $cached_tasks ) {
            return $this->decorateWorkspaceTasksForViewer( $cached_tasks );
        }

        $tasks = $this->repository->findForUserAcrossBoards( $user_id, $search, $sort_by, $sort_order, $status_filter, $archived, $project_filter, $private_only, $include_templates, $limit, $offset, $assignee_id );
        set_transient( $transient_key, $tasks, HOUR_IN_SECONDS );

        return $this->decorateWorkspaceTasksForViewer( $tasks );
    }

    /**
     * Return the tasks an actor may read across every board, including tasks where
     * they participate directly even when the board is otherwise private.
     *
     * This deliberately does not cache the collection: group membership and other
     * board permissions can change independently of task/user cache versions.
     */
    public function getVisibleTasksForUser( $user_id, $search = '', $sort_by = 'name', $sort_order = 'ASC', $status_filter = '', $archived = null, $project_filter = null, $include_templates = true, $task_type_filter = '', $assigned_to_me = false, $limit = 0, $offset = 0, $assignee_id = null ) {
        $user_id = (int) $user_id;
        $readable_board_names = null;

        if ( ! user_can( $user_id, 'manage_options' ) ) {
            $readable_board_names = array();

            foreach ( (array) $this->board_service->getAllBoardNames() as $board ) {
                $board_name = sanitize_key( is_object( $board ) ? ( $board->id ?? '' ) : $board );

                if ( '' !== $board_name && true === $this->board_access_policy->canReadBoard( $board_name, $user_id ) ) {
                    $readable_board_names[] = $board_name;
                }
            }

            $readable_board_names = array_values( array_unique( $readable_board_names ) );
        }

        $tasks = $this->repository->findVisibleForUser(
            $user_id,
            $readable_board_names,
            $search,
            $sort_by,
            $sort_order,
            $status_filter,
            $archived,
            $project_filter,
            $include_templates,
            $task_type_filter,
            $assigned_to_me,
            $limit,
            $offset,
            $assignee_id
        );

        return $this->decorateWorkspaceTasksForViewer( $tasks );
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

    private function decorateTaskForViewer( $canonical_task ) {
        $task = clone $canonical_task;
        $this->decoratePredecessorsForViewer( array( $task ), get_current_user_id() );
        $task->board_display_name = $this->board_service->getBoardDisplayName( $task->board_name );
        $task->frontend_url = TaskBoardUrlResolver::resolve( $task->board_name ?? '', (int) ( $task->id ?? 0 ) );
        $task->comments = $this->comment_service->getComments( $task->id, $task );
        $task->history = array();
        $task->description = $task->description ?? '';
        $this->protectFollowUpSourceForViewer( $task, get_current_user_id() );

        return ProtectedAttachmentService::prepareTask( $task );
    }

    private function decorateWorkspaceTasksForViewer( $canonical_tasks ) {
        $tasks = $this->cloneTasks( $canonical_tasks );
        $this->decoratePredecessorsForViewer( $tasks, get_current_user_id() );
        $display_names = array();

        foreach ( $tasks as $task ) {
            if ( ! isset( $display_names[ $task->board_name ] ) ) {
                $display_names[ $task->board_name ] = $this->board_service->getBoardDisplayName( $task->board_name );
            }

            $task->board_display_name = $display_names[ $task->board_name ];
            $task->frontend_url = TaskBoardUrlResolver::resolve( $task->board_name ?? '', (int) ( $task->id ?? 0 ) );
            $this->protectFollowUpSourceForViewer( $task, get_current_user_id() );
        }

        return ProtectedAttachmentService::prepareTasks( $tasks );
    }


    /** Keep cross-board causal lineage without exposing unreadable source details. */
    private function protectFollowUpSourceForViewer( $task, $viewer_id ) {
        $source_id = (int) ( $task->follow_up_of_task_id ?? 0 );
        if ( $source_id <= 0 ) {
            return;
        }

        $source = $this->repository->findAccessRecordById( $source_id );
        $can_read = $source && $this->canViewerReadTaskRecord( $source, (int) $viewer_id );
        $task->follow_up_source_restricted = ! $can_read;

        if ( ! $can_read ) {
            $task->follow_up_of_task_name = null;
        }
    }

    private function canViewerReadTaskRecord( $task, $viewer_id ) {
        if ( ! is_object( $task ) || (int) ( $task->id ?? 0 ) <= 0 ) {
            return false;
        }

        return true === $this->getTaskAccessPolicy()->canReadTask( (int) $task->id, (int) $viewer_id );
    }

    /**
     * Redact predecessor details using the canonical task-read policy.
     *
     * The authorization records are loaded in one batch for a collection and
     * temporarily served through getTaskForAuthorization(), so TaskAccessPolicy
     * retains its complete direct-participation and board/Inbox semantics
     * without an N+1 query pattern. Only cloned response objects are changed.
     *
     * @param array<int,object> $tasks
     * @param int               $viewer_id
     */
    private function decoratePredecessorsForViewer( array $tasks, $viewer_id ) {
        $predecessor_ids = array();

        foreach ( $tasks as $task ) {
            foreach ( (array) ( $task->predecessors ?? array() ) as $predecessor ) {
                $predecessor_id = is_object( $predecessor ) ? (int) ( $predecessor->id ?? 0 ) : 0;
                if ( $predecessor_id > 0 ) {
                    $predecessor_ids[] = $predecessor_id;
                }
            }
        }

        $predecessor_ids = array_values( array_unique( $predecessor_ids ) );
        $records = array();

        foreach ( $predecessor_ids as $predecessor_id ) {
            $records[ $predecessor_id ] = null;
        }

        if ( ! empty( $predecessor_ids ) ) {
            if ( method_exists( $this->repository, 'findAccessRecordsByIds' ) ) {
                foreach ( (array) $this->repository->findAccessRecordsByIds( $predecessor_ids ) as $record_id => $record ) {
                    $resolved_id = is_object( $record ) && isset( $record->id ) ? (int) $record->id : (int) $record_id;
                    if ( $resolved_id > 0 ) {
                        $records[ $resolved_id ] = $record;
                    }
                }
            } else {
                foreach ( $predecessor_ids as $predecessor_id ) {
                    $records[ $predecessor_id ] = $this->repository->findAccessRecordById( $predecessor_id );
                }
            }
        }

        $this->authorization_records = $records;

        try {
            foreach ( $tasks as $task ) {
                $visible_predecessors = array();
                $visible_ids = array();
                $restricted_count = 0;

                foreach ( (array) ( $task->predecessors ?? array() ) as $predecessor ) {
                    $predecessor_id = is_object( $predecessor ) ? (int) ( $predecessor->id ?? 0 ) : 0;
                    $can_read = $predecessor_id > 0
                        && true === $this->getTaskAccessPolicy()->canReadTask( $predecessor_id, (int) $viewer_id );

                    if ( $can_read ) {
                        $visible_predecessor = clone $predecessor;
                        $visible_predecessors[] = $visible_predecessor;
                        $visible_ids[] = $predecessor_id;
                    } else {
                        $restricted_count++;
                    }
                }

                $task->predecessors = $visible_predecessors;
                $task->predecessor_ids = $visible_ids;
                $task->restricted_predecessor_count = $restricted_count;
            }
        } finally {
            $this->authorization_records = null;
        }
    }

    private function getTaskAccessPolicy() {
        if ( ! $this->task_access_policy ) {
            $this->task_access_policy = new TaskAccessPolicy( $this, $this->board_access_policy );
        }

        return $this->task_access_policy;
    }

    private function cloneTasks( $tasks ) {
        return array_map(
            static function ( $task ) {
                return is_object( $task ) ? clone $task : $task;
            },
            (array) $tasks
        );
    }
}
