<?php

namespace Pandatask\Application\Project;

use Pandatask\Application\Board\BoardService;
use Pandatask\Application\Security\BoardAccessPolicy;
use Pandatask\Application\Security\TaskAccessPolicy;
use Pandatask\Application\Task\TaskService;
use Pandatask\Infrastructure\Notifications\TaskBoardUrlResolver;
use Pandatask\Infrastructure\Persistence\DatabaseContext;
use Pandatask\Infrastructure\Persistence\ProjectReferenceRepository;
use WP_Error;

/**
 * Project workspace/reference application service.
 *
 * A project reference never becomes a second task.  The service keeps the
 * project_task_references table deliberately small and obtains all task
 * details from the canonical tasks/task_relationships tables.
 */
final class ProjectReferenceService {

    private $repository;
    private $task_service;
    private $task_access_policy;
    private $board_access_policy;
    private $board_service;
    private $project_service;

    public function __construct( $repository = null, $task_service = null, $task_access_policy = null, $board_access_policy = null, $board_service = null, $project_service = null ) {
        $this->repository          = $repository ?: new ProjectReferenceRepository();
        $this->task_service       = $task_service ?: new TaskService();
        $this->task_access_policy = $task_access_policy ?: new TaskAccessPolicy( $this->task_service );
        $this->board_access_policy = $board_access_policy ?: new BoardAccessPolicy();
        $this->board_service      = $board_service ?: new BoardService();
        $this->project_service    = $project_service ?: new ProjectService();
    }

    public function getWorkspace( $project_id, $actor_id = null ) {
        $actor_id = $this->actorId( $actor_id );
		$project  = $this->project( $project_id, false, $actor_id );

        if ( is_wp_error( $project ) ) {
            return $project;
        }

        $project_id = (int) $project->id;
        $native_rows = (array) $this->repository->findNativeTasks( $project_id );
        $native_ids  = array();
        $nodes       = array();
        $nodes_by_id = array();
        $rows_by_id  = array();

        foreach ( $native_rows as $row ) {
            $task_id = (int) $row->id;
            $native_ids[ $task_id ] = true;
            $rows_by_id[ $task_id ] = $row;
            $nodes_by_id[ $task_id ] = count( $nodes );
            $nodes[] = $this->nativeNode( $row );
        }

        $associations = (array) $this->repository->findAssociations( $project_id );
        $relationships = (array) $this->repository->findWorkspaceRelationships( $project_id );
        $external_task_ids = array();
        foreach ( $associations as $association ) {
            $external_task_ids[] = (int) $association->task_id;
        }
        foreach ( $relationships as $relationship ) {
            $external_task_ids[] = (int) $relationship->task_id;
            $external_task_ids[] = (int) $relationship->predecessor_id;
        }
        $task_rows = method_exists( $this->repository, 'findTasksByIds' )
            ? $this->repository->findTasksByIds( $external_task_ids )
            : array();
        if ( ! method_exists( $this->repository, 'findTasksByIds' ) ) {
            foreach ( array_values( array_unique( array_filter( array_map( 'absint', $external_task_ids ) ) ) ) as $external_task_id ) {
                $task = $this->repository->findTask( $external_task_id );
                if ( $task ) {
                    $task_rows[] = $task;
                }
            }
        }
        foreach ( (array) $task_rows as $task ) {
            $rows_by_id[ (int) $task->id ] = $task;
        }
        $references = array();
        $dependencies = array();

        foreach ( $associations as $association ) {
            $task_id = (int) $association->task_id;
            $task = $rows_by_id[ $task_id ] ?? null;

            // Invalid/orphaned rows are repaired at activation.  Do not turn
            // one into a fabricated restricted task if it is encountered
            // during a concurrent repair.
            if ( ! $task ) {
                continue;
            }

            $relation_type = sanitize_key( $association->relation_type ?? '' );
            if ( ! in_array( $relation_type, array( 'included', 'related' ), true ) ) {
                continue;
            }
            $endpoint = $this->workspaceEndpoint(
                $task,
                $task_id,
                $relation_type,
                'reference-' . (int) $association->id,
                $actor_id,
                $native_ids,
                $rows_by_id,
                $nodes,
				$nodes_by_id
            );

            if ( ! $endpoint['key'] ) {
                continue;
            }

            $reference = array(
                'reference_key' => 'reference-' . (int) $association->id,
                'relation_type' => $relation_type,
                'task_key'      => $endpoint['key'],
                'task_id'       => null,
                'restricted'    => $endpoint['restricted'],
            );
            if ( ! $endpoint['restricted'] ) {
                $reference['task_id'] = $task_id;
            }
            $references[] = $reference;
        }

        foreach ( $relationships as $relationship ) {
            $successor_id   = (int) $relationship->task_id;
            $predecessor_id = (int) $relationship->predecessor_id;
            $successor      = $rows_by_id[ $successor_id ] ?? null;
            $predecessor    = $rows_by_id[ $predecessor_id ] ?? null;

            if ( ! $successor || ! $predecessor ) {
                continue;
            }

            $successor_native   = isset( $native_ids[ $successor_id ] );
            $predecessor_native = isset( $native_ids[ $predecessor_id ] );

            if ( ! $successor_native && ! $predecessor_native ) {
                continue;
            }

            $is_external = $this->isExternalDependency( $successor, $predecessor, $project_id );
            if ( ! $is_external && ( ! $successor_native || ! $predecessor_native ) ) {
                // Archived tasks in the target project are not workspace
                // nodes. They are not external references either.
                continue;
            }
            $predecessor_endpoint = $this->workspaceEndpoint(
                $predecessor,
                $predecessor_id,
                'dependency',
                'dependency-' . (int) $relationship->id . '-predecessor',
                $actor_id,
                $native_ids,
                $rows_by_id,
                $nodes,
				$nodes_by_id
            );
            $successor_endpoint = $this->workspaceEndpoint(
                $successor,
                $successor_id,
                'dependency',
                'dependency-' . (int) $relationship->id . '-successor',
                $actor_id,
                $native_ids,
                $rows_by_id,
                $nodes,
				$nodes_by_id
            );

            if ( ! $predecessor_endpoint['key'] || ! $successor_endpoint['key'] ) {
                continue;
            }

            if ( isset( $nodes_by_id[ $successor_id ] ) && ! in_array( $predecessor_endpoint['key'], $nodes[ $nodes_by_id[ $successor_id ] ]['predecessor_keys'], true ) ) {
                $nodes[ $nodes_by_id[ $successor_id ] ]['predecessor_keys'][] = $predecessor_endpoint['key'];
            }

            $dependencies[] = array(
                'reference_key'   => 'dependency-' . (int) $relationship->id,
                'relationship_id' => (int) $relationship->id,
                'predecessor_key' => $predecessor_endpoint['key'],
                'successor_key'   => $successor_endpoint['key'],
                'is_external'     => $is_external,
                'is_restricted'   => $predecessor_endpoint['restricted'] || $successor_endpoint['restricted'],
            );

            // Project references contain only cross-project/board edges.
            // Native same-project edges remain available in dependencies but
            // are intentionally absent from references.
            // Only an external predecessor waiting on a native project task
            // is a mutable project reference.  The reverse orientation remains
            // visible in the workspace graph but cannot be edited/exported
            // through this project's reference API.
            if ( $is_external && $successor_native ) {
                $reference = array(
                    'reference_key'   => 'dependency-' . (int) $relationship->id,
                    'relation_type'   => 'dependency',
                    'predecessor_key' => $predecessor_endpoint['key'],
                    'successor_key'   => $successor_endpoint['key'],
                    'predecessor_task_id' => null,
                    'successor_task_id'   => null,
                    'restricted'      => $predecessor_endpoint['restricted'] || $successor_endpoint['restricted'],
                );
                if ( ! $reference['restricted'] ) {
                    $reference['predecessor_task_id'] = $predecessor_id;
                    $reference['successor_task_id'] = $successor_id;
                }
                $references[] = $reference;
            }
        }

        // Parent and predecessor workspace keys are limited to nodes that are
        // actually in this response.  This prevents an incidental relation
        // from becoming an ID disclosure channel.
        foreach ( $nodes as $index => $node ) {
            $task_id = (int) ( $node['task_id'] ?? 0 );
            if ( $task_id > 0 && isset( $rows_by_id[ $task_id ] ) ) {
                $parent_id = (int) ( $rows_by_id[ $task_id ]->parent_task_id ?? 0 );
                $nodes[ $index ]['parent_workspace_key'] = $this->workspaceKeyForNode( $parent_id, $nodes, $nodes_by_id );
            }
        }

        foreach ( $relationships as $relationship ) {
            $successor_id = (int) $relationship->task_id;
            $predecessor_id = (int) $relationship->predecessor_id;
            if ( ! isset( $nodes_by_id[ $successor_id ] ) || ! isset( $nodes_by_id[ $predecessor_id ] ) ) {
                continue;
            }
            $successor_index = $nodes_by_id[ $successor_id ];
            $predecessor_key = $nodes[ $nodes_by_id[ $predecessor_id ] ]['workspace_key'];
            if ( ! in_array( $predecessor_key, $nodes[ $successor_index ]['predecessor_keys'], true ) ) {
                $nodes[ $successor_index ]['predecessor_keys'][] = $predecessor_key;
            }
        }

        foreach ( $nodes as $index => $node ) {
            if ( ! $node['restricted'] ) {
                $nodes[ $index ]['is_blocked'] = $this->blockedForNode( $node, $relationships );
            }
        }

        $project = $this->decorateProject( $project, $actor_id );
        $counts = array(
            'native'     => 0,
            'external'   => 0,
            'restricted' => 0,
        );
        foreach ( $nodes as $node ) {
            if ( $node['restricted'] ) {
                $counts['restricted']++;
            } elseif ( 'native' === $node['origin'] ) {
                $counts['native']++;
            } else {
                $counts['external']++;
            }
        }

        return array(
            'project'      => $project,
            'tasks'        => array_values( $nodes ),
            'dependencies' => array_values( $dependencies ),
            'references'   => array_values( $references ),
            'counts'       => $counts,
        );
    }

    public function listReferences( $project_id, $actor_id = null ) {
        $workspace = $this->getWorkspace( $project_id, $actor_id );
        if ( is_wp_error( $workspace ) ) {
            return $workspace;
        }

        return array(
            'references' => $workspace['references'],
            'counts'     => $workspace['counts'],
        );
    }

    public function createReference( $project_id, array $data, $actor_id = null ) {
        $actor_id = $this->actorId( $actor_id );
        $project = $this->project( $project_id, true, $actor_id );
        if ( is_wp_error( $project ) ) {
            return $project;
        }

        $relation_type = sanitize_key( $data['relation_type'] ?? '' );
        if ( 'dependency' === $relation_type ) {
            return $this->createDependency( (int) $project->id, $data, $actor_id );
        }
        if ( ! in_array( $relation_type, array( 'included', 'related' ), true ) ) {
            return $this->error( 'rest_invalid_reference_type', __( 'relation_type must be included, related, or dependency.', 'pandatask' ), 422 );
        }

        $task_id = absint( $data['task_id'] ?? 0 );
        if ( $task_id <= 0 ) {
            return $this->error( 'rest_missing_task', __( 'task_id is required.', 'pandatask' ), 422 );
        }

        $task = $this->repository->findTask( $task_id );
        if ( ! $task ) {
            return $this->error( 'rest_task_not_found', __( 'The referenced task could not be found.', 'pandatask' ), 404 );
        }
        $read_permission = $this->task_access_policy->canReadTask( $task_id, $actor_id );
        if ( true !== $read_permission ) {
            return $this->error( 'rest_reference_task_forbidden', __( 'You cannot read the referenced task.', 'pandatask' ), 403 );
        }
        if ( (int) ( $task->project_id ?? 0 ) === (int) $project->id ) {
            return $this->error( 'rest_reference_native_task', __( 'A task already native to this project cannot be added as a reference.', 'pandatask' ), 422 );
        }
        if ( $this->repository->findAssociationByTask( (int) $project->id, $task_id ) ) {
            return $this->error( 'rest_reference_exists', __( 'This task is already referenced by the project.', 'pandatask' ), 409 );
        }

        $reference_id = $this->repository->createAssociation( (int) $project->id, $task_id, $relation_type, $actor_id );
        if ( ! $reference_id ) {
            return $this->error( 'rest_reference_exists', __( 'This task is already referenced by the project.', 'pandatask' ), 409 );
        }

        return $this->referenceFromWorkspace( (int) $project->id, 'reference-' . $reference_id, $actor_id );
    }

    public function updateReference( $project_id, $reference_key, array $data, $actor_id = null ) {
        $actor_id = $this->actorId( $actor_id );
        $project = $this->project( $project_id, true, $actor_id );
        if ( is_wp_error( $project ) ) {
            return $project;
        }
        $parsed = $this->parseReferenceKey( $reference_key );
        if ( is_wp_error( $parsed ) ) {
            return $parsed;
        }
        if ( 'dependency' === $parsed['type'] ) {
            return $this->error( 'rest_dependency_immutable', __( 'Dependencies can only be changed by removing and re-adding them.', 'pandatask' ), 422 );
        }
        $relation_type = sanitize_key( $data['relation_type'] ?? '' );
        if ( ! in_array( $relation_type, array( 'included', 'related' ), true ) ) {
            return $this->error( 'rest_invalid_reference_type', __( 'relation_type must be included or related.', 'pandatask' ), 422 );
        }
        $reference = $this->repository->findAssociation( (int) $project->id, $parsed['id'] );
        if ( ! $reference ) {
            return $this->error( 'rest_reference_not_found', __( 'Reference not found in this project.', 'pandatask' ), 404 );
        }
        if ( false === $this->repository->updateAssociation( (int) $project->id, $parsed['id'], $relation_type ) ) {
            return $this->error( 'rest_reference_update_failed', __( 'The project reference could not be updated.', 'pandatask' ), 500 );
        }

        return $this->referenceFromWorkspace( (int) $project->id, 'reference-' . $parsed['id'], $actor_id );
    }

    public function deleteReference( $project_id, $reference_key, $actor_id = null ) {
        $actor_id = $this->actorId( $actor_id );
        $project = $this->project( $project_id, true, $actor_id );
        if ( is_wp_error( $project ) ) {
            return $project;
        }
        $parsed = $this->parseReferenceKey( $reference_key );
        if ( is_wp_error( $parsed ) ) {
            return $parsed;
        }

        if ( 'reference' === $parsed['type'] ) {
            $deleted = $this->repository->deleteAssociation( (int) $project->id, $parsed['id'] );
            if ( ! $deleted ) {
                return $this->error( 'rest_reference_not_found', __( 'Reference not found in this project.', 'pandatask' ), 404 );
            }
            return true;
        }

        $relationship = $this->repository->findRelationship( $parsed['id'] );
        if ( ! is_object( $relationship ) || ! isset( $relationship->id ) ) {
            return $this->error( 'rest_dependency_not_found', __( 'Dependency not found.', 'pandatask' ), 404 );
        }
        $successor = $this->repository->findTask( (int) $relationship->task_id );
        if ( ! $successor || (int) ( $successor->project_id ?? 0 ) !== (int) $project->id || (int) $successor->archived ) {
            return $this->error( 'rest_dependency_not_found', __( 'Dependency not found in this project.', 'pandatask' ), 404 );
        }
        $update_permission = $this->task_access_policy->canUpdateTask( (int) $successor->id, $actor_id );
        if ( true !== $update_permission ) {
            return $update_permission;
        }
		$predecessor_permission = $this->task_access_policy->canReadTask( (int) $relationship->predecessor_id, $actor_id );
		if ( true !== $predecessor_permission ) {
			return $this->error( 'rest_dependency_predecessor_forbidden', __( 'You cannot read the dependency predecessor.', 'pandatask' ), 403 );
		}

        if ( ! DatabaseContext::acquireDependencyGraphLock() ) {
            return $this->dependencyGraphLockError();
        }

        try {
			$predecessors = $this->readablePredecessorIds(
				array_diff(
					$this->repository->findPredecessorIds( (int) $successor->id ),
					array( (int) $relationship->predecessor_id )
				),
				$actor_id
			);
            $result = $this->task_service->updateTask( (int) $successor->id, array( 'predecessors' => $predecessors ), '', $actor_id );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            if ( true !== $result ) {
                return $this->error( 'rest_dependency_update_failed', __( 'The dependency could not be removed.', 'pandatask' ), 500 );
            }

            return true;
        } finally {
            DatabaseContext::releaseDependencyGraphLock();
        }
    }

    public function exportReferences( $project_id, $actor_id = null ) {
        $actor_id = $this->actorId( $actor_id );
        $workspace = $this->getWorkspace( $project_id, $actor_id );
        if ( is_wp_error( $workspace ) ) {
            return $workspace;
        }
        $references = array();
        $omitted = 0;
        foreach ( $workspace['references'] as $reference ) {
            if ( ! empty( $reference['restricted'] ) ) {
                $omitted++;
                continue;
            }
            if ( 'dependency' === $reference['relation_type'] ) {
                if ( ! isset( $reference['predecessor_task_id'], $reference['successor_task_id'] ) ) {
                    $omitted++;
                    continue;
                }
                $references[] = array(
                    'relation_type'       => 'dependency',
                    'predecessor_task_id' => (int) $reference['predecessor_task_id'],
                    'successor_task_id'   => (int) $reference['successor_task_id'],
                );
            } elseif ( isset( $reference['task_id'] ) ) {
                $references[] = array(
                    'relation_type' => $reference['relation_type'],
                    'task_id'       => (int) $reference['task_id'],
                );
            } else {
                $omitted++;
            }
        }

        return array(
            'version'            => 1,
            'references'         => $references,
            'omitted_restricted' => $omitted,
        );
    }

    public function importReferences( $project_id, array $payload, $actor_id = null ) {
        $actor_id = $this->actorId( $actor_id );
        if ( 1 !== (int) ( $payload['version'] ?? 0 ) || ! is_array( $payload['references'] ?? null ) ) {
            return $this->error( 'rest_invalid_import', __( 'Import requires version 1 and a references array.', 'pandatask' ), 422 );
        }
        if ( count( $payload['references'] ) > 500 ) {
            return $this->error( 'rest_import_too_large', __( 'Imports are limited to 500 references.', 'pandatask' ), 422 );
        }
        $project = $this->project( $project_id, true, $actor_id );
        if ( is_wp_error( $project ) ) {
            return $project;
        }

        $created = 0;
        $skipped = 0;
        $errors = array();
        foreach ( array_values( $payload['references'] ) as $index => $item ) {
            if ( ! is_array( $item ) ) {
                $errors[] = array( 'index' => $index, 'code' => 'rest_invalid_reference', 'message' => __( 'Reference entry must be an object.', 'pandatask' ) );
                continue;
            }
            $result = $this->createReference( (int) $project->id, $item, $actor_id );
            if ( ! is_wp_error( $result ) ) {
                $created++;
                continue;
            }
            $status = (int) ( $result->get_error_data()['status'] ?? 422 );
            if ( in_array( $status, array( 403, 404 ), true ) ) {
                $skipped++;
                continue;
            }
            $errors[] = array(
                'index'   => $index,
                'code'    => $result->get_error_code(),
                'message' => $result->get_error_message(),
            );
        }

        return array( 'created' => $created, 'skipped' => $skipped, 'errors' => $errors );
    }

    private function createDependency( $project_id, array $data, $actor_id ) {
        $predecessor_id = absint( $data['predecessor_task_id'] ?? 0 );
        $successor_id   = absint( $data['successor_task_id'] ?? 0 );
        if ( $predecessor_id <= 0 || $successor_id <= 0 ) {
            return $this->error( 'rest_missing_dependency_endpoint', __( 'predecessor_task_id and successor_task_id are required.', 'pandatask' ), 422 );
        }
        if ( $predecessor_id === $successor_id ) {
            return $this->error( 'rest_dependency_self_reference', __( 'A task cannot depend on itself.', 'pandatask' ), 422 );
        }
        $successor = $this->repository->findTask( $successor_id );
        $predecessor = $this->repository->findTask( $predecessor_id );
        if ( ! $successor || ! $predecessor ) {
            return $this->error( 'rest_task_not_found', __( 'A dependency task could not be found.', 'pandatask' ), 404 );
        }
        if ( (int) ( $successor->project_id ?? 0 ) !== $project_id || (int) $successor->archived ) {
            return $this->error( 'rest_dependency_successor_invalid', __( 'The dependency successor must be an active native task in this project.', 'pandatask' ), 422 );
        }
        $update_permission = $this->task_access_policy->canUpdateTask( $successor_id, $actor_id );
        if ( true !== $update_permission ) {
            return $update_permission;
        }
        $read_permission = $this->task_access_policy->canReadTask( $predecessor_id, $actor_id );
        if ( true !== $read_permission ) {
            return $this->error( 'rest_dependency_predecessor_forbidden', __( 'You cannot read the dependency predecessor.', 'pandatask' ), 403 );
        }
        if ( (int) ( $predecessor->project_id ?? 0 ) === $project_id ) {
            return $this->error( 'rest_dependency_predecessor_native', __( 'The predecessor must be external to this project.', 'pandatask' ), 422 );
        }
        if ( ! DatabaseContext::acquireDependencyGraphLock() ) {
            return $this->dependencyGraphLockError();
        }

        try {
            if ( $this->repository->findRelationshipByEndpoints( $successor_id, $predecessor_id ) ) {
                return $this->error( 'rest_dependency_exists', __( 'This dependency already exists.', 'pandatask' ), 409 );
            }

			$predecessors = $this->readablePredecessorIds(
				$this->repository->findPredecessorIds( $successor_id ),
				$actor_id
			);
            $predecessors[] = $predecessor_id;
            $predecessors = array_values( array_unique( array_map( 'absint', $predecessors ) ) );
            $result = $this->task_service->updateTask( $successor_id, array( 'predecessors' => $predecessors ), '', $actor_id );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            if ( true !== $result ) {
                return $this->error( 'rest_dependency_update_failed', __( 'The dependency could not be created.', 'pandatask' ), 500 );
            }
            $relationship_id = method_exists( $this->repository, 'findRelationshipIdByEndpoints' )
                ? (int) $this->repository->findRelationshipIdByEndpoints( $successor_id, $predecessor_id )
                : 0;
            if ( $relationship_id <= 0 ) {
                return $this->error( 'rest_dependency_update_failed', __( 'The dependency could not be created.', 'pandatask' ), 500 );
            }

            return $this->referenceFromWorkspace( $project_id, 'dependency-' . $relationship_id, $actor_id );
        } finally {
            DatabaseContext::releaseDependencyGraphLock();
        }
    }

	/**
	 * Return only predecessor IDs whose canonical tasks the actor may inspect.
	 * TaskMutationService preserves any omitted opaque existing relationships.
	 *
	 * @param array<int,mixed> $predecessor_ids
	 * @return array<int,int>
	 */
	private function readablePredecessorIds( $predecessor_ids, $actor_id ) {
		$readable = array();

		foreach ( array_values( array_unique( array_filter( array_map( 'absint', (array) $predecessor_ids ) ) ) ) as $predecessor_id ) {
			if ( true === $this->task_access_policy->canReadTask( $predecessor_id, $actor_id ) ) {
				$readable[] = $predecessor_id;
			}
		}

		return $readable;
	}

	private function workspaceEndpoint( $task, $task_id, $relation_type, $relation_key, $actor_id, $native_ids, $rows_by_id, &$nodes, &$nodes_by_id ) {
        $task_id = (int) $task_id;
        if ( isset( $native_ids[ $task_id ] ) ) {
            $index = $nodes_by_id[ $task_id ];
            $this->appendRelationType( $nodes[ $index ], $relation_type );
            return array( 'key' => $nodes[ $index ]['workspace_key'], 'restricted' => false );
        }

        $read_permission = $this->task_access_policy->canReadTask( $task_id, $actor_id );
        if ( true !== $read_permission ) {
            $key = 'restricted-' . $relation_key;
            $nodes[] = $this->restrictedNode( $key, $relation_type, 'dependency' === $relation_type );
            return array( 'key' => $key, 'restricted' => true );
        }

        $key = 'task-' . $task_id;
        if ( isset( $nodes_by_id[ $task_id ] ) ) {
            $index = $nodes_by_id[ $task_id ];
            $this->appendRelationType( $nodes[ $index ], $relation_type );
            if ( 'related' !== $relation_type ) {
                $nodes[ $index ]['visible_in_visuals'] = true;
            }
            return array( 'key' => $key, 'restricted' => false );
        }

        $rows_by_id[ $task_id ] = $task;
        $nodes_by_id[ $task_id ] = count( $nodes );
        $nodes[] = $this->externalNode( $task, $relation_type, 'dependency' === $relation_type );
        return array( 'key' => $key, 'restricted' => false );
    }

    private function nativeNode( $task ) {
        $node = $this->baseNode( $task, 'native', false, 'task-' . (int) $task->id );
        $node['visible_in_visuals'] = true;
        return $node;
    }

    private function externalNode( $task, $relation_type, $visible ) {
        $node = $this->baseNode( $task, 'external', false, 'task-' . (int) $task->id );
        $node['relation_types'] = array( $relation_type );
        $node['visible_in_visuals'] = $visible || 'related' !== $relation_type;
        $node['is_blocked'] = false;
        return $node;
    }

    private function restrictedNode( $key, $relation_type, $visible ) {
        return array(
            'workspace_key'          => $key,
            'task_id'                => null,
            'name'                   => 'Restricted external task',
            'status'                 => null,
            'start_date'             => null,
            'deadline'               => null,
            'priority'               => null,
            'parent_workspace_key'  => null,
            'predecessor_keys'       => array(),
            'is_blocked'             => false,
            'origin'                 => 'external',
            'restricted'             => true,
            'relation_types'         => array( $relation_type ),
            'visible_in_visuals'     => $visible || 'related' !== $relation_type,
        );
    }

    private function baseNode( $task, $origin, $restricted, $workspace_key ) {
        return array(
            'workspace_key'         => $workspace_key,
            'task_id'               => (int) $task->id,
            'name'                  => (string) $task->name,
            'status'                => null === $task->status ? null : (string) $task->status,
            'start_date'            => $task->start_date ?? null,
            'deadline'              => $task->deadline ?? null,
            'priority'              => isset( $task->priority ) ? (int) $task->priority : null,
            'parent_workspace_key' => null,
            'predecessor_keys'      => array(),
            'is_blocked'            => false,
            'origin'                => $origin,
            'restricted'            => $restricted,
            'relation_types'        => array(),
            'visible_in_visuals'    => false,
            'board_name'            => (string) $task->board_name,
            'board_display_name'    => $this->board_service->getBoardDisplayName( (string) $task->board_name ),
            'project_id'            => null === $task->project_id ? null : (int) $task->project_id,
            'project_name'          => isset( $task->project_name ) ? (string) $task->project_name : null,
            'frontend_url'          => TaskBoardUrlResolver::resolve( (string) $task->board_name, (int) $task->id ),
        );
    }

    private function appendRelationType( array &$node, $relation_type ) {
        if ( ! in_array( $relation_type, $node['relation_types'], true ) ) {
            $node['relation_types'][] = $relation_type;
        }
        if ( 'related' !== $relation_type ) {
            $node['visible_in_visuals'] = true;
        }
    }

    private function blockedForNode( array $node, $relationships ) {
        if ( ! empty( $node['restricted'] ) || empty( $node['task_id'] ) ) {
            return false;
        }
        if ( 'external' === $node['origin'] && method_exists( $this->task_service, 'isTaskBlocked' ) ) {
            return (bool) $this->task_service->isTaskBlocked( (int) $node['task_id'] );
        }
        foreach ( $relationships as $relationship ) {
            if ( (int) $relationship->task_id !== (int) $node['task_id'] ) {
                continue;
            }
            if ( 'done' !== (string) ( $relationship->predecessor_status ?? '' ) && empty( $relationship->predecessor_archived ) ) {
                return true;
            }
        }
        return false;
    }

    private function workspaceKeyForNode( $task_id, $nodes, $nodes_by_id ) {
        if ( $task_id > 0 && isset( $nodes_by_id[ $task_id ] ) ) {
            return $nodes[ $nodes_by_id[ $task_id ] ]['workspace_key'];
        }
        return null;
    }

    private function isExternalDependency( $successor, $predecessor, $project_id ) {
        return (int) ( $successor->project_id ?? 0 ) !== $project_id
            || (int) ( $predecessor->project_id ?? 0 ) !== $project_id
            || (string) $successor->board_name !== (string) $predecessor->board_name;
    }

    private function referenceFromWorkspace( $project_id, $reference_key, $actor_id ) {
        $workspace = $this->getWorkspace( $project_id, $actor_id );
        if ( is_wp_error( $workspace ) ) {
            return $workspace;
        }
        foreach ( $workspace['references'] as $reference ) {
            if ( $reference_key === $reference['reference_key'] ) {
                return $reference;
            }
        }
        return $this->error( 'rest_reference_not_found', __( 'Reference not found in this project.', 'pandatask' ), 404 );
    }

    private function parseReferenceKey( $reference_key ) {
        $reference_key = sanitize_text_field( (string) $reference_key );
        if ( ! preg_match( '/^(reference|dependency)-(\d+)$/', $reference_key, $matches ) ) {
            return $this->error( 'rest_invalid_reference_key', __( 'Reference key must be reference-N or dependency-N.', 'pandatask' ), 422 );
        }
        if ( (int) $matches[2] <= 0 ) {
            return $this->error( 'rest_invalid_reference_key', __( 'Reference key must use a positive numeric ID.', 'pandatask' ), 422 );
        }
        return array( 'type' => $matches[1], 'id' => (int) $matches[2] );
    }

    private function project( $project_id, $manage = false, $actor_id = 0 ) {
        $project = method_exists( $this->project_service, 'getProjectUncached' )
            ? $this->project_service->getProjectUncached( (int) $project_id )
            : $this->repository->findProject( (int) $project_id );
        if ( ! $project ) {
            return $this->error( 'rest_project_not_found', __( 'Project not found.', 'pandatask' ), 404 );
        }
        if ( $manage ) {
            $permission = $this->board_access_policy->canManageBoard( $project->board_name, $actor_id );
        } else {
            $permission = $this->board_access_policy->canReadBoard( $project->board_name, $actor_id );
        }
        if ( true !== $permission ) {
            return $permission;
        }
        return $project;
    }

    private function decorateProject( $project, $actor_id ) {
        $project = clone $project;
        $board_name = (string) ( $project->board_name ?? '' );
        $project->board_display_name = $this->board_service->getBoardDisplayName( $board_name );
        $project->board_scope = preg_match( '/^group_\d+$/', $board_name )
            ? 'group'
            : ( preg_match( '/^user_\d+$/', $board_name ) ? 'private' : 'standard' );
        $project->can_manage = true === $this->board_access_policy->canManageBoard( $board_name, $actor_id );
        $project->frontend_url = TaskBoardUrlResolver::resolveProject( $board_name, (int) $project->id );
        return $project;
    }

    private function actorId( $actor_id ) {
        return null === $actor_id ? (int) get_current_user_id() : (int) $actor_id;
    }

    private function error( $code, $message, $status ) {
        return new WP_Error( $code, $message, array( 'status' => $status ) );
    }

    private function dependencyGraphLockError() {
        return $this->error(
            'pandatask_dependency_graph_unavailable',
            __( 'The dependency graph is busy. Please try again.', 'pandatask' ),
            503
        );
    }
}
