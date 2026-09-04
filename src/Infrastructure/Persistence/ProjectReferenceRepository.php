<?php

namespace Pandatask\Infrastructure\Persistence;

/**
 * Persistence for the lightweight links that make a project workspace span
 * more than one board.  The canonical task and task_relationships rows stay
 * in their existing tables; this table only stores project associations.
 */
final class ProjectReferenceRepository {

    public function findProject( $project_id ) {
        global $wpdb;

        $projects_table = DatabaseContext::getDbPrefix() . 'projects';

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$projects_table} WHERE id = %d",
                (int) $project_id
            )
        );
    }

    public function findAssociation( $project_id, $reference_id ) {
        global $wpdb;

        $table = DatabaseContext::getDbPrefix() . 'project_task_references';

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, project_id, task_id, relation_type, created_by, created_at, updated_at
                 FROM {$table}
                 WHERE project_id = %d AND id = %d",
                (int) $project_id,
                (int) $reference_id
            )
        );
    }

    public function findAssociationByTask( $project_id, $task_id ) {
        global $wpdb;

        $table = DatabaseContext::getDbPrefix() . 'project_task_references';

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, project_id, task_id, relation_type, created_by, created_at, updated_at
                 FROM {$table}
                 WHERE project_id = %d AND task_id = %d",
                (int) $project_id,
                (int) $task_id
            )
        );
    }

    public function findAssociations( $project_id ) {
        global $wpdb;

        $table = DatabaseContext::getDbPrefix() . 'project_task_references';

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, project_id, task_id, relation_type, created_by, created_at, updated_at
                 FROM {$table}
                 WHERE project_id = %d
                 ORDER BY id ASC",
                (int) $project_id
            )
        );
    }

    public function createAssociation( $project_id, $task_id, $relation_type, $created_by ) {
        global $wpdb;

        $table = DatabaseContext::getDbPrefix() . 'project_task_references';
        $now   = gmdate( 'Y-m-d H:i:s' );

        $result = $wpdb->insert(
            $table,
            array(
                'project_id'   => (int) $project_id,
                'task_id'      => (int) $task_id,
                'relation_type' => $relation_type,
                'created_by'    => (int) $created_by,
                'created_at'   => $now,
                'updated_at'   => $now,
            ),
            array( '%d', '%d', '%s', '%d', '%s', '%s' )
        );

        return false === $result ? false : (int) $wpdb->insert_id;
    }

    public function updateAssociation( $project_id, $reference_id, $relation_type ) {
        global $wpdb;

        $table = DatabaseContext::getDbPrefix() . 'project_task_references';

        return $wpdb->update(
            $table,
            array(
                'relation_type' => $relation_type,
                'updated_at'    => gmdate( 'Y-m-d H:i:s' ),
            ),
            array(
                'id'         => (int) $reference_id,
                'project_id' => (int) $project_id,
            ),
            array( '%s', '%s' ),
            array( '%d', '%d' )
        );
    }

    public function deleteAssociation( $project_id, $reference_id ) {
        global $wpdb;

        $table = DatabaseContext::getDbPrefix() . 'project_task_references';

        return $wpdb->delete(
            $table,
            array(
                'id'         => (int) $reference_id,
                'project_id' => (int) $project_id,
            ),
            array( '%d', '%d' )
        );
    }

    public function deleteForProject( $project_id ) {
        global $wpdb;

        $table = DatabaseContext::getDbPrefix() . 'project_task_references';

        return false !== $wpdb->delete( $table, array( 'project_id' => (int) $project_id ), array( '%d' ) );
    }

    public function deleteForTask( $task_id ) {
        global $wpdb;

        $table = DatabaseContext::getDbPrefix() . 'project_task_references';

        return false !== $wpdb->delete( $table, array( 'task_id' => (int) $task_id ), array( '%d' ) );
    }

    /**
     * Return the fields needed for a workspace node.  This intentionally
     * bypasses TaskService's viewer decoration and transient cache: callers
     * decide which fields may be exposed after evaluating access.
     */
    public function findTask( $task_id ) {
        global $wpdb;

        $tasks_table = DatabaseContext::getDbPrefix() . 'tasks';

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT task.id, task.board_name, task.project_id, task.name, task.status,
                        task.start_date, task.deadline, task.priority, task.parent_task_id,
                        task.archived, project.name AS project_name
                 FROM {$tasks_table} task
                 LEFT JOIN " . DatabaseContext::getDbPrefix() . "projects project ON project.id = task.project_id
                 WHERE task.id = %d",
                (int) $task_id
            )
        );
    }

    public function findTasksByIds( $task_ids ) {
        global $wpdb;

        $task_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $task_ids ) ) ) );
        if ( empty( $task_ids ) ) {
            return array();
        }

        $tasks_table = DatabaseContext::getDbPrefix() . 'tasks';
        $projects_table = DatabaseContext::getDbPrefix() . 'projects';
        $ids_sql = implode( ',', $task_ids );

        return $wpdb->get_results(
            "SELECT task.id, task.board_name, task.project_id, task.name, task.status,
                    task.start_date, task.deadline, task.priority, task.parent_task_id,
                    task.archived, project.name AS project_name
             FROM {$tasks_table} task
             LEFT JOIN {$projects_table} project ON project.id = task.project_id
             WHERE task.id IN ({$ids_sql})"
        );
    }

    public function findNativeTasks( $project_id ) {
        global $wpdb;

        $tasks_table = DatabaseContext::getDbPrefix() . 'tasks';

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT task.id, task.board_name, task.project_id, task.name, task.status,
                        task.start_date, task.deadline, task.priority, task.parent_task_id,
                        task.archived, project.name AS project_name
                 FROM {$tasks_table} task
                 LEFT JOIN " . DatabaseContext::getDbPrefix() . "projects project ON project.id = task.project_id
                 WHERE task.project_id = %d AND task.archived = 0
                 ORDER BY id ASC",
                (int) $project_id
            )
        );
    }

    /**
     * Fetch all relationship rows touching an active native task.  The joins
     * provide endpoint board/project values without requiring a second query
     * for every edge.
     */
    public function findWorkspaceRelationships( $project_id ) {
        global $wpdb;

        $prefix = DatabaseContext::getDbPrefix();
        $tasks  = $prefix . 'tasks';
        $rels   = $prefix . 'task_relationships';

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT relationship.id, relationship.task_id, relationship.predecessor_id,
                        relationship.type,
                        successor.board_name AS successor_board_name,
                        successor.project_id AS successor_project_id,
                        successor.name AS successor_name,
                        successor.status AS successor_status,
                        successor.start_date AS successor_start_date,
                        successor.deadline AS successor_deadline,
                        successor.priority AS successor_priority,
                        successor.parent_task_id AS successor_parent_task_id,
                        successor.archived AS successor_archived,
                        predecessor.board_name AS predecessor_board_name,
                        predecessor.project_id AS predecessor_project_id,
                        predecessor.name AS predecessor_name,
                        predecessor.status AS predecessor_status,
                        predecessor.start_date AS predecessor_start_date,
                        predecessor.deadline AS predecessor_deadline,
                        predecessor.priority AS predecessor_priority,
                        predecessor.parent_task_id AS predecessor_parent_task_id,
                        predecessor.archived AS predecessor_archived
                 FROM {$rels} relationship
                 INNER JOIN {$tasks} successor ON successor.id = relationship.task_id
                 INNER JOIN {$tasks} predecessor ON predecessor.id = relationship.predecessor_id
                 WHERE (successor.project_id = %d AND successor.archived = 0)
                    OR (predecessor.project_id = %d AND predecessor.archived = 0)
                 ORDER BY relationship.id ASC",
                (int) $project_id,
                (int) $project_id
            )
        );
    }

    public function findRelationship( $relationship_id ) {
        global $wpdb;

        $table = DatabaseContext::getDbPrefix() . 'task_relationships';

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, task_id, predecessor_id, type
                 FROM {$table}
                 WHERE id = %d",
                (int) $relationship_id
            )
        );
    }

    /**
     * @return object|null
     */
    public function findRelationshipByEndpoints( $successor_id, $predecessor_id ) {
        global $wpdb;

        $table = DatabaseContext::getDbPrefix() . 'task_relationships';

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, task_id, predecessor_id, type
                 FROM {$table}
                 WHERE task_id = %d AND predecessor_id = %d",
                (int) $successor_id,
                (int) $predecessor_id
            )
        );
    }

    public function findRelationshipIdByEndpoints( $successor_id, $predecessor_id ) {
        $relationship = $this->findRelationshipByEndpoints( $successor_id, $predecessor_id );

        return $relationship ? (int) $relationship->id : 0;
    }

    public function findPredecessorIds( $successor_id ) {
        global $wpdb;

        $table = DatabaseContext::getDbPrefix() . 'task_relationships';

        return array_map(
            'intval',
            (array) $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT predecessor_id FROM {$table} WHERE task_id = %d ORDER BY id ASC",
                    (int) $successor_id
                )
            )
        );
    }
}
