<?php

namespace Pandatask\Application\Project;

use Pandatask\Infrastructure\Persistence\DatabaseContext;
use Pandatask\Infrastructure\Persistence\ProjectRepository;

final class ProjectService {

    private $repository;

    public function __construct( $repository = null ) {
        $this->repository = $repository ?: new ProjectRepository();
    }

    public function getProjects( $board_name ) {
        if ( preg_match( '/^user_(\d+)$/', $board_name ) ) {
            return $this->repository->findForBoard( $board_name );
        }

        $version       = DatabaseContext::getBoardCacheVersion( $board_name, 'projects' );
        $transient_key = "pandat69_projects_{$board_name}_{$version}";
        $cached        = get_transient( $transient_key );

        if ( false !== $cached ) {
            return $cached;
        }

        $projects = $this->repository->findForBoard( $board_name );
        set_transient( $transient_key, $projects, 12 * HOUR_IN_SECONDS );

        return $projects;
    }

    public function getProject( $project_id ) {
        $transient_key = 'pandat69_project_' . $project_id;
        $cached        = get_transient( $transient_key );

        if ( false !== $cached ) {
            return $cached;
        }

        $project = $this->repository->findById( $project_id );

        if ( $project ) {
            set_transient( $transient_key, $project, 12 * HOUR_IN_SECONDS );
        }

        return $project;
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
            DatabaseContext::invalidateBoardCache( $project->board_name, array( 'projects', 'tasks' ) );
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
            DatabaseContext::invalidateBoardCache( $project->board_name, array( 'projects', 'tasks', 'parent_tasks' ) );
            delete_transient( 'pandat69_project_' . $project_id );
        }

        return $result;
    }

    public function isProjectOnBoard( $project_id, $board_name ) {
        return $this->repository->existsOnBoard( $project_id, $board_name );
    }
}
