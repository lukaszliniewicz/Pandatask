<?php

namespace Pandatask\Application\Project;

use Pandatask\Application\Board\BoardService;
use Pandatask\Application\Security\BoardAccessPolicy;
use Pandatask\Application\Task\TaskCacheInvalidator;
use Pandatask\Infrastructure\Notifications\TaskBoardUrlResolver;
use Pandatask\Infrastructure\Persistence\DatabaseContext;
use Pandatask\Infrastructure\Persistence\ProjectRepository;

final class ProjectService {

    private $repository;

    private $board_service;

    private $board_access_policy;

    private $cache_invalidator;

    public function __construct( $repository = null, $board_service = null, $board_access_policy = null, $cache_invalidator = null ) {
        $this->repository          = $repository ?: new ProjectRepository();
        $this->board_service       = $board_service ?: new BoardService();
        $this->board_access_policy = $board_access_policy ?: new BoardAccessPolicy();
        $this->cache_invalidator   = $cache_invalidator ?: new TaskCacheInvalidator();
    }

    public function getProjects( $board_name, $private_only = false ) {
        if ( preg_match( '/^user_(\d+)$/', $board_name, $matches ) ) {
            $user_id = (int) $matches[1];
            $board_names = array( $board_name );

            if ( ! $private_only ) {
                $board_names = wp_list_pluck( $this->board_service->getUserWritableBoards( $user_id ), 'id' );
            }

            $projects = $this->repository->findForUserWorkspace( $user_id, $board_names );

            return $this->decorateProjectsForViewer( $projects );
        }

        $version       = DatabaseContext::getBoardCacheVersion( $board_name, 'projects' );
        $transient_key = "pandat69_projects_{$board_name}_{$version}";
        $cached        = get_transient( $transient_key );

        if ( false !== $cached ) {
            return $this->decorateProjectsForViewer( $cached );
        }

        $projects = $this->repository->findForBoard( $board_name );
        set_transient( $transient_key, $projects, 12 * HOUR_IN_SECONDS );

        return $this->decorateProjectsForViewer( $projects );
    }

    public function getProject( $project_id ) {
        $transient_key = 'pandat69_project_' . $project_id;
        $cached        = get_transient( $transient_key );

        if ( false !== $cached ) {
            return $this->decorateProjectUrl( $cached );
        }

        $project = $this->repository->findById( $project_id );

        if ( $project ) {
            set_transient( $transient_key, $project, 12 * HOUR_IN_SECONDS );
        }

        return $project ? $this->decorateProjectUrl( $project ) : $project;
    }

    /**
     * Read a project without the project transient. Workspace responses are
     * viewer-specific and must not be built from a stale cached projection.
     */
    public function getProjectUncached( $project_id ) {
        $project = $this->repository->findById( (int) $project_id );

        return $project ? $this->decorateProjectUrl( $project ) : $project;
    }

    public function addProject( $data ) {
        $project_id = $this->repository->create( $data );

        if ( $project_id ) {
            DatabaseContext::invalidateBoardCache( $data['board_name'], array( 'projects' ) );
        }

        return $project_id;
    }

    public function updateProject( $project_id, $data ) {
        $project = $this->getProject( $project_id );
        $result  = $this->repository->update( $project_id, $data );

        if ( $result && $project ) {
            $this->cache_invalidator->invalidateBoard( $project->board_name, array( 'projects', 'tasks' ) );
            delete_transient( 'pandat69_project_' . $project_id );
        }

        return $result;
    }

    public function deleteProject( $project_id ) {
        $project = $this->getProject( $project_id );

        if ( ! $project ) {
            return false;
        }

        $result = $this->repository->delete( $project_id );

        if ( $result ) {
            $this->cache_invalidator->invalidateBoard( $project->board_name, array( 'projects', 'tasks', 'parent_tasks', 'reports' ) );
            delete_transient( 'pandat69_project_' . $project_id );
        }

        return $result;
    }

    public function isProjectOnBoard( $project_id, $board_name ) {
        return $this->repository->existsOnBoard( $project_id, $board_name );
    }

    private function decorateProjectsForViewer( $projects ) {
        $decorated = array();
        $viewer_id = get_current_user_id();
        $display_names = array();

        foreach ( (array) $projects as $canonical_project ) {
            $project = clone $canonical_project;

            if ( ! isset( $display_names[ $project->board_name ] ) ) {
                $display_names[ $project->board_name ] = $this->board_service->getBoardDisplayName( $project->board_name );
            }

            $project->board_display_name = $display_names[ $project->board_name ];
            $project->board_scope = preg_match( '/^group_\d+$/', $project->board_name )
                ? 'group'
                : ( preg_match( '/^user_\d+$/', $project->board_name ) ? 'private' : 'standard' );
            $project->can_manage = true === $this->board_access_policy->canManageBoard( $project->board_name, $viewer_id );
            $project->frontend_url = TaskBoardUrlResolver::resolveProject( $project->board_name ?? '', (int) ( $project->id ?? 0 ) );
            if ( property_exists( $project, 'tasks' ) ) {
                $project->tasks = $this->decorateProjectTasks( $project->tasks, $project->board_name ?? '' );
            }
            $decorated[] = $project;
        }

        return $decorated;
    }

    private function decorateProjectUrl( $canonical_project ) {
        $project = clone $canonical_project;
        $project->frontend_url = TaskBoardUrlResolver::resolveProject( $project->board_name ?? '', (int) ( $project->id ?? 0 ) );

        return $project;
    }

    private function decorateProjectTasks( $tasks, $board_name ) {
        $decorated = array();

        foreach ( (array) $tasks as $task ) {
            if ( is_object( $task ) ) {
                $task = clone $task;
                $task->frontend_url = TaskBoardUrlResolver::resolve( $board_name, (int) ( $task->id ?? 0 ) );
            } elseif ( is_array( $task ) ) {
                $task['frontend_url'] = TaskBoardUrlResolver::resolve( $board_name, (int) ( $task['id'] ?? 0 ) );
            }

            $decorated[] = $task;
        }

        return $decorated;
    }
}
