<?php

namespace Pandatask\Infrastructure\Persistence;

use Pandatask\Domain\Task\TaskGraph;

final class TaskRepository {

    private $hydrated_users = array();

    public function isBlocked( $task_id ) {
        global $wpdb;

        $rel_table   = DatabaseContext::getDbPrefix() . 'task_relationships';
        $tasks_table = DatabaseContext::getDbPrefix() . 'tasks';
        $count       = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$rel_table} r
                 JOIN {$tasks_table} t ON r.predecessor_id = t.id
                 WHERE r.task_id = %d AND t.status != 'done' AND t.archived = 0",
                $task_id
            )
        );

        return $count > 0;
    }

    public function findForBoard( $board_name, $search = '', $sort_by = 'name', $sort_order = 'ASC', $status_filter = '', $date_filter = '', $start_date = '', $end_date = '', $archived = 0, $project_filter = null, $include_templates = false, $task_type_filter = '', $user_id = null, $limit = 0, $offset = 0, $inbox_filter = null ) {
        global $wpdb;

        $prefix            = DatabaseContext::getDbPrefix();
        $tasks_table       = $prefix . 'tasks';
        $assignments_table = $prefix . 'assignments';
        $users_table       = $wpdb->users;
        $categories_table  = $prefix . 'categories';
        $projects_table    = $prefix . 'projects';
        $sql_select        = "SELECT t.*, c.name as category_name, p.name as project_name,
             parent.name as parent_task_name,
             parent.status as parent_task_status,
             follow_source.name as follow_up_of_task_name
             FROM {$tasks_table} t
             LEFT JOIN {$categories_table} c ON t.category_id = c.id AND c.board_name = t.board_name
             LEFT JOIN {$projects_table} p ON t.project_id = p.id AND p.board_name = t.board_name
             LEFT JOIN {$tasks_table} parent ON t.parent_task_id = parent.id
             LEFT JOIN {$tasks_table} follow_source ON t.follow_up_of_task_id = follow_source.id";
        $sql_where         = ' WHERE t.board_name = %s AND t.archived = %d';
        $params            = array( $board_name, $archived );

        if ( ! empty( $user_id ) ) {
            $sql_where .= " AND EXISTS (SELECT 1 FROM {$assignments_table} a_user WHERE a_user.task_id = t.id AND a_user.user_id = %d)";
            $params[]   = $user_id;
        }

        if ( ! $include_templates ) {
            $sql_where .= ' AND t.is_recurring = 0';
        }

        if ( ! empty( $task_type_filter ) ) {
            $sql_where .= ' AND t.task_type = %s';
            $params[]   = $task_type_filter;
        }

        if ( null !== $inbox_filter ) {
            if ( 'any' === $inbox_filter ) {
                $sql_where .= ' AND t.inbox_state IS NOT NULL';
            } elseif ( 'none' === $inbox_filter ) {
                $sql_where .= ' AND t.inbox_state IS NULL';
            } else {
                $sql_where .= ' AND t.inbox_state = %s';
                $params[] = sanitize_key( $inbox_filter );
            }
        }

        if ( ! empty( $search ) ) {
            $search_term = '%' . $wpdb->esc_like( $search ) . '%';
            $sql_where  .= " AND (
                t.name LIKE %s
                OR t.description LIKE %s
                OR EXISTS (
                    SELECT 1
                    FROM {$assignments_table} search_assignment
                    INNER JOIN {$users_table} search_user ON search_user.ID = search_assignment.user_id
                    WHERE search_assignment.task_id = t.id
                      AND search_user.display_name LIKE %s
                )
            )";
            $params[]    = $search_term;
            $params[]    = $search_term;
            $params[]    = $search_term;
        }

        if ( ! empty( $status_filter ) ) {
            if ( 'pending_in-progress' === $status_filter ) {
                $sql_where .= " AND t.status IN ('pending', 'in-progress')";
            } elseif ( 'missed_deadline' === $status_filter ) {
                $sql_where .= ' AND t.status IN (\'pending\', \'in-progress\') AND t.deadline IS NOT NULL AND t.deadline < %s';
                $params[]   = wp_date( 'Y-m-d' );
            } else {
                $sql_where .= ' AND t.status = %s';
                $params[]   = $status_filter;
            }
        }

        if ( null !== $project_filter ) {
            if ( is_numeric( $project_filter ) && $project_filter > 0 ) {
                $sql_where .= ' AND t.project_id = %d';
                $params[]   = $project_filter;
            } elseif ( 'none' === $project_filter ) {
                $sql_where .= ' AND (t.project_id IS NULL OR t.project_id = 0)';
            }
        }

        if ( 'range' === $date_filter && ! empty( $start_date ) && ! empty( $end_date ) ) {
            $sql_where .= ' AND (t.deadline BETWEEN %s AND %s)';
            $params[]   = $start_date;
            $params[]   = $end_date;
        }

        $sql_group_order      = '';
        $allowed_sort_columns = array( 'name', 'priority', 'deadline', 'status', 'assigned_user_names', 'category_name', 'project_name', 'created_at' );

        if ( in_array( $sort_by, $allowed_sort_columns, true ) ) {
            $order = 'DESC' === strtoupper( $sort_order ) ? 'DESC' : 'ASC';

            if ( 'assigned_user_names' === $sort_by ) {
                $sql_group_order .= " ORDER BY (
                    SELECT MIN(sort_user.display_name)
                    FROM {$assignments_table} sort_assignment
                    INNER JOIN {$users_table} sort_user ON sort_user.ID = sort_assignment.user_id
                    WHERE sort_assignment.task_id = t.id
                      AND (sort_assignment.role = 'assignee' OR sort_assignment.role IS NULL)
                ) {$order}, t.id {$order}";
            } elseif ( 'category_name' === $sort_by ) {
                $sql_group_order .= " ORDER BY c.name {$order}, t.id {$order}";
            } elseif ( 'project_name' === $sort_by ) {
                $sql_group_order .= " ORDER BY (p.name IS NULL OR p.name = '') ASC, p.name {$order}, t.name ASC, t.id ASC";
            } else {
                $sql_group_order .= " ORDER BY t.{$sort_by} {$order}, t.id {$order}";
            }
        } else {
            $sql_group_order .= ' ORDER BY t.name ASC, t.id ASC';
        }

        if ( $limit > 0 ) {
            $sql_group_order .= ' LIMIT %d OFFSET %d';
            $params[]         = min( 501, max( 1, (int) $limit ) );
            $params[]         = max( 0, (int) $offset );
        }

        $results = $wpdb->get_results( $wpdb->prepare( $sql_select . $sql_where . $sql_group_order, ...$params ) );

        $this->hydrateTaskCollection( $results, $tasks_table );

        return $results;
    }

    public function findById( $task_id ) {
        global $wpdb;

        $prefix            = DatabaseContext::getDbPrefix();
        $tasks_table       = $prefix . 'tasks';
        $categories_table  = $prefix . 'categories';
        $projects_table    = $prefix . 'projects';
        $users_table       = $wpdb->users;
        $sql               = $wpdb->prepare(
            "SELECT t.*, c.name as category_name, p.name as project_name,
             parent.name as parent_task_name,
             follow_source.name as follow_up_of_task_name,
             creator.display_name as creator_name
             FROM {$tasks_table} t
             LEFT JOIN {$categories_table} c ON t.category_id = c.id AND c.board_name = t.board_name
             LEFT JOIN {$projects_table} p ON t.project_id = p.id AND p.board_name = t.board_name
             LEFT JOIN {$tasks_table} parent ON t.parent_task_id = parent.id
             LEFT JOIN {$tasks_table} follow_source ON t.follow_up_of_task_id = follow_source.id
             LEFT JOIN {$users_table} creator ON t.creator_id = creator.ID
             WHERE t.id = %d",
            $task_id
        );
        $task              = $wpdb->get_row( $sql );

        if ( ! $task ) {
            return $task;
        }

        $task->creator_id = isset( $task->creator_id ) ? (int) $task->creator_id : 0;
        $this->hydrateTaskCollection( array( $task ), $tasks_table );

        return $task;
    }

    /** Return active follow-up tasks that were causally created from the source task. */
    public function findFollowUps( $task_id ) {
        global $wpdb;

        $prefix = DatabaseContext::getDbPrefix();
        $tasks_table = $prefix . 'tasks';
        $categories_table = $prefix . 'categories';
        $projects_table = $prefix . 'projects';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT t.*, c.name as category_name, p.name as project_name,
                 parent.name as parent_task_name,
                 follow_source.name as follow_up_of_task_name
                 FROM {$tasks_table} t
                 LEFT JOIN {$categories_table} c ON t.category_id = c.id AND c.board_name = t.board_name
                 LEFT JOIN {$projects_table} p ON t.project_id = p.id AND p.board_name = t.board_name
                 LEFT JOIN {$tasks_table} parent ON t.parent_task_id = parent.id
                 LEFT JOIN {$tasks_table} follow_source ON t.follow_up_of_task_id = follow_source.id
                 WHERE t.follow_up_of_task_id = %d AND t.archived = 0
                 ORDER BY t.created_at ASC, t.id ASC",
                (int) $task_id
            )
        );

        $this->hydrateTaskCollection( $rows, $tasks_table );

        return $rows;
    }

    public function findInboxForOwner( $owner_user_id, $search = '', $status_filter = '', $limit = 100, $offset = 0 ) {
        return $this->findForBoard(
            'user_' . (int) $owner_user_id,
            $search,
            'created_at',
            'DESC',
            $status_filter,
            '',
            '',
            '',
            0,
            null,
            false,
            '',
            null,
            $limit,
            $offset,
            'any'
        );
    }

    public function findHierarchyRecordById( $task_id ) {
        global $wpdb;

        $tasks_table = DatabaseContext::getDbPrefix() . 'tasks';
        $record = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, board_name, project_id, parent_task_id
                 FROM {$tasks_table}
                 WHERE id = %d",
                $task_id
            )
        );

        if ( $record ) {
            $record->id = (int) $record->id;
            $record->project_id = $record->project_id ? (int) $record->project_id : null;
            $record->parent_task_id = $record->parent_task_id ? (int) $record->parent_task_id : null;
        }

        return $record;
    }

    public function findDescendantProjectRecords( $task_id, $board_name ) {
        global $wpdb;

        $tasks_table = DatabaseContext::getDbPrefix() . 'tasks';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, parent_task_id, project_id
                 FROM {$tasks_table}
                 WHERE board_name = %s
                 AND parent_task_id IS NOT NULL",
                $board_name
            )
        );
        $children_by_parent = array();

        foreach ( $rows as $row ) {
            $parent_id = (int) $row->parent_task_id;
            $row->id = (int) $row->id;
            $row->parent_task_id = $parent_id;
            $row->project_id = $row->project_id ? (int) $row->project_id : null;
            $children_by_parent[ $parent_id ][] = $row;
        }

        $descendants = array();
        $queue = array( (int) $task_id );
        $visited = array( (int) $task_id => true );

        for ( $index = 0; $index < count( $queue ); $index++ ) {
            $parent_id = $queue[ $index ];

            foreach ( $children_by_parent[ $parent_id ] ?? array() as $child ) {
                if ( isset( $visited[ $child->id ] ) ) {
                    continue;
                }

                $visited[ $child->id ] = true;
                $descendants[] = $child;
                $queue[] = $child->id;
            }
        }

        return $descendants;
    }

    public function findAccessRecordById( $task_id ) {
        global $wpdb;

        $prefix = DatabaseContext::getDbPrefix();
        $tasks_table = $prefix . 'tasks';
        $assignments_table = $prefix . 'assignments';
        $task = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT t.id, t.board_name, t.creator_id, t.inbox_state, t.follow_up_of_task_id
                 FROM {$tasks_table} t
                 WHERE t.id = %d",
                $task_id
            )
        );

        if ( ! $task ) {
            return null;
        }

        $assignment_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT user_id, role
                 FROM {$assignments_table}
                 WHERE task_id = %d
                 ORDER BY user_id ASC",
                $task_id
            )
        );
        $task->assigned_user_ids = array();
        $task->supervisor_user_ids = array();

        foreach ( $assignment_rows as $assignment ) {
            if ( 'supervisor' === $assignment->role ) {
                $task->supervisor_user_ids[] = (int) $assignment->user_id;
            } else {
                $task->assigned_user_ids[] = (int) $assignment->user_id;
            }
        }

        $task->creator_id = (int) $task->creator_id;

        return $task;
    }

    public function findIdByName( $board_name, $task_name ) {
        global $wpdb;

        $tasks_table = DatabaseContext::getDbPrefix() . 'tasks';
        $task_id     = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$tasks_table} WHERE board_name = %s AND name = %s AND archived = 0 LIMIT 1",
                $board_name,
                $task_name
            )
        );

        return $task_id ? (int) $task_id : null;
    }

    public function existsOnBoard( $task_id, $board_name ) {
        global $wpdb;

        $tasks_table = DatabaseContext::getDbPrefix() . 'tasks';
        $count       = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$tasks_table} WHERE id = %d AND board_name = %s",
                $task_id,
                $board_name
            )
        );

        return $count > 0;
    }

    public function wouldCreateParentCycle( $task_id, $parent_task_id, $board_name = '' ) {
        if ( ! $board_name ) {
            $parent = $this->findHierarchyRecordById( $parent_task_id );
            $board_name = $parent ? $parent->board_name : '';
        }

        if ( ! $board_name ) {
            return false;
        }

        return TaskGraph::wouldCreateCycle(
            $this->findParentGraphForBoard( $board_name ),
            (int) $task_id,
            (int) $parent_task_id
        );
    }

    public function wouldCreateDependencyCycle( $task_id, $predecessor_id, $board_name = '', $proposed_predecessors = null ) {
        if ( ! $board_name ) {
            $predecessor = $this->findHierarchyRecordById( $predecessor_id );
            $board_name = $predecessor ? $predecessor->board_name : '';
        }

        if ( ! $board_name ) {
            return false;
        }

        $graph = $this->findDependencyGraphForBoard( $board_name );

        if ( is_array( $proposed_predecessors ) ) {
            $graph[ (int) $task_id ] = array_values( array_unique( array_map( 'intval', $proposed_predecessors ) ) );
        }

        return TaskGraph::wouldCreateCycle(
            $graph,
            (int) $task_id,
            (int) $predecessor_id
        );
    }

    public function findParentGraphForBoard( $board_name ) {
        global $wpdb;

        $tasks_table = DatabaseContext::getDbPrefix() . 'tasks';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, parent_task_id
                 FROM {$tasks_table}
                 WHERE board_name = %s
                   AND parent_task_id IS NOT NULL",
                $board_name
            )
        );
        $graph = array();

        foreach ( $rows as $row ) {
            $graph[ (int) $row->id ][] = (int) $row->parent_task_id;
        }

        return $graph;
    }

    public function findDependencyGraphForBoard( $board_name ) {
        global $wpdb;

        $prefix              = DatabaseContext::getDbPrefix();
        $tasks_table         = $prefix . 'tasks';
        $relationships_table = $prefix . 'task_relationships';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT relationship.task_id, relationship.predecessor_id
                 FROM {$relationships_table} relationship
                 INNER JOIN {$tasks_table} task ON task.id = relationship.task_id
                 INNER JOIN {$tasks_table} predecessor ON predecessor.id = relationship.predecessor_id
                 WHERE task.board_name = %s
                   AND predecessor.board_name = task.board_name",
                $board_name
            )
        );
        $graph = array();

        foreach ( $rows as $row ) {
            $graph[ (int) $row->task_id ][] = (int) $row->predecessor_id;
        }

        return $graph;
    }

    public function findBoardTaskRecordsByIds( $board_name, $task_ids ) {
        global $wpdb;

        $task_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $task_ids ) ) ) );

        if ( empty( $task_ids ) ) {
            return array();
        }

        $tasks_table = DatabaseContext::getDbPrefix() . 'tasks';
        $placeholders = implode( ', ', array_fill( 0, count( $task_ids ), '%d' ) );
        $params = array_merge( array( $board_name ), $task_ids );
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, board_name, status, archived, project_id, parent_task_id
                 FROM {$tasks_table}
                 WHERE board_name = %s
                   AND id IN ({$placeholders})",
                ...$params
            )
        );
        $records = array();

        foreach ( $rows as $row ) {
            $row->id = (int) $row->id;
            $row->archived = (int) $row->archived;
            $row->project_id = $row->project_id ? (int) $row->project_id : null;
            $row->parent_task_id = $row->parent_task_id ? (int) $row->parent_task_id : null;
            $records[ $row->id ] = $row;
        }

        return $records;
    }

    public function findIncompletePredecessorIds( $predecessor_ids ) {
        global $wpdb;

        $predecessor_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $predecessor_ids ) ) ) );

        if ( empty( $predecessor_ids ) ) {
            return array();
        }

        $tasks_table = DatabaseContext::getDbPrefix() . 'tasks';
        $placeholders = implode( ', ', array_fill( 0, count( $predecessor_ids ), '%d' ) );
        $results = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id
                 FROM {$tasks_table}
                 WHERE id IN ({$placeholders})
                   AND archived = 0
                   AND status <> 'done'",
                ...$predecessor_ids
            )
        );

        return array_values( array_map( 'intval', $results ) );
    }

    public function findParticipantUserIdsForBoard( $board_name ) {
        global $wpdb;

        $prefix            = DatabaseContext::getDbPrefix();
        $tasks_table       = $prefix . 'tasks';
        $assignments_table = $prefix . 'assignments';
        $results = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT assignment.user_id
                 FROM {$assignments_table} assignment
                 INNER JOIN {$tasks_table} task ON task.id = assignment.task_id
                 WHERE task.board_name = %s
                 UNION
                 SELECT task.creator_id
                 FROM {$tasks_table} task
                 WHERE task.board_name = %s
                   AND task.creator_id IS NOT NULL",
                $board_name,
                $board_name
            )
        );

        return array_values( array_unique( array_filter( array_map( 'absint', $results ) ) ) );
    }

    public function findForUserAcrossBoards( $user_id, $search = '', $sort_by = 'name', $sort_order = 'ASC', $status_filter = '', $archived = 0, $project_filter = null, $private_only = false, $include_templates = false, $limit = 0, $offset = 0 ) {
        global $wpdb;

        $prefix            = DatabaseContext::getDbPrefix();
        $tasks_table       = $prefix . 'tasks';
        $assignments_table = $prefix . 'assignments';
        $categories_table  = $prefix . 'categories';
        $projects_table    = $prefix . 'projects';
        $sql               = $wpdb->prepare(
            "SELECT t.*, c.name as category_name, p.name as project_name,
             parent.name as parent_task_name,
             parent.status as parent_task_status
             FROM {$tasks_table} t
             LEFT JOIN {$categories_table} c ON t.category_id = c.id AND c.board_name = t.board_name
             LEFT JOIN {$projects_table} p ON t.project_id = p.id AND p.board_name = t.board_name
             LEFT JOIN {$tasks_table} parent ON t.parent_task_id = parent.id
             WHERE (
                 EXISTS (
                     SELECT 1
                     FROM {$assignments_table} user_assignment
                     WHERE user_assignment.task_id = t.id
                       AND user_assignment.user_id = %d
                 )
                 OR t.creator_id = %d
             )
             AND t.archived = %d",
            $user_id,
            $user_id,
            $archived
        );

        // Inbox is a separate personal workflow surface. Items remain normal tasks,
        // but do not duplicate themselves in the ordinary actionable Tasks view.
        $sql .= ' AND t.inbox_state IS NULL';

        if ( ! $include_templates ) {
            $sql .= ' AND t.is_recurring = 0';
        }

        if ( $private_only ) {
            $sql .= $wpdb->prepare( ' AND t.board_name = %s', 'user_' . $user_id );
        }

        if ( ! empty( $search ) ) {
            $search_term = '%' . $wpdb->esc_like( $search ) . '%';
            $sql        .= $wpdb->prepare( ' AND (t.name LIKE %s OR t.description LIKE %s)', $search_term, $search_term );
        }

        if ( ! empty( $status_filter ) ) {
            if ( 'pending_in-progress' === $status_filter ) {
                $sql .= " AND t.status IN ('pending', 'in-progress')";
            } elseif ( 'missed_deadline' === $status_filter ) {
                $sql .= $wpdb->prepare( " AND t.status IN ('pending', 'in-progress') AND t.deadline IS NOT NULL AND t.deadline < %s", wp_date( 'Y-m-d' ) );
            } else {
                $sql .= $wpdb->prepare( ' AND t.status = %s', $status_filter );
            }
        }

        if ( null !== $project_filter ) {
            if ( is_numeric( $project_filter ) && $project_filter > 0 ) {
                $sql .= $wpdb->prepare( ' AND t.project_id = %d', $project_filter );
            } elseif ( 'none' === $project_filter ) {
                $sql .= ' AND (t.project_id IS NULL OR t.project_id = 0)';
            }
        }

        $allowed_sort_columns = array( 'name', 'priority', 'deadline', 'status', 'project_name', 'created_at' );

        if ( in_array( $sort_by, $allowed_sort_columns, true ) ) {
            $order = 'DESC' === strtoupper( $sort_order ) ? 'DESC' : 'ASC';
            if ( 'project_name' === $sort_by ) {
                $sql .= " ORDER BY (p.name IS NULL OR p.name = '') ASC, p.name {$order}, t.name ASC, t.id ASC";
            } else {
                $sql .= " ORDER BY t.{$sort_by} {$order}, t.id {$order}";
            }
        } else {
            $sql .= ' ORDER BY t.name ASC, t.id ASC';
        }

        if ( $limit > 0 ) {
            $sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', min( 501, max( 1, (int) $limit ) ), max( 0, (int) $offset ) );
        }

        $results = $wpdb->get_results( $sql );

        $this->hydrateTaskCollection( $results, $tasks_table );

        foreach ( $results as $task ) {
            $task->creator_id = $task->creator_id ? (int) $task->creator_id : 0;
        }

        return $results;
    }

    /**
     * Find tasks readable through either an approved board or direct participation.
     * A null board list is an administrator query and intentionally has no access
     * predicate; an empty list still permits only direct participation.
     */
    public function findVisibleForUser( $user_id, $readable_board_names, $search = '', $sort_by = 'name', $sort_order = 'ASC', $status_filter = '', $archived = null, $project_filter = null, $include_templates = true, $task_type_filter = '', $assigned_to_me = false, $limit = 0, $offset = 0 ) {
        global $wpdb;

        $prefix            = DatabaseContext::getDbPrefix();
        $tasks_table       = $prefix . 'tasks';
        $assignments_table = $prefix . 'assignments';
        $users_table       = $wpdb->users;
        $categories_table  = $prefix . 'categories';
        $projects_table    = $prefix . 'projects';
        $sql_select        = "SELECT t.*, c.name as category_name, p.name as project_name,
             parent.name as parent_task_name,
             parent.status as parent_task_status,
             follow_source.name as follow_up_of_task_name
             FROM {$tasks_table} t
             LEFT JOIN {$categories_table} c ON t.category_id = c.id AND c.board_name = t.board_name
             LEFT JOIN {$projects_table} p ON t.project_id = p.id AND p.board_name = t.board_name
             LEFT JOIN {$tasks_table} parent ON t.parent_task_id = parent.id
             LEFT JOIN {$tasks_table} follow_source ON t.follow_up_of_task_id = follow_source.id";
        $sql_where         = ' WHERE 1 = 1';
        $params            = array();
        $user_id           = (int) $user_id;

        if ( null !== $archived ) {
            $sql_where .= ' AND t.archived = %d';
            $params[] = (int) $archived;
        }

        if ( null !== $readable_board_names ) {
            $readable_board_names = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $readable_board_names ) ) ) );
            $visibility_clauses = array(
                't.creator_id = %d',
                "EXISTS (SELECT 1 FROM {$assignments_table} visibility_assignment WHERE visibility_assignment.task_id = t.id AND visibility_assignment.user_id = %d)",
            );
            $visibility_params = array( $user_id, $user_id );

            if ( ! empty( $readable_board_names ) ) {
                $visibility_clauses[] = 't.board_name IN (' . implode( ', ', array_fill( 0, count( $readable_board_names ), '%s' ) ) . ')';
                $visibility_params = array_merge( $visibility_params, $readable_board_names );
            }

            $sql_where .= ' AND (' . implode( ' OR ', $visibility_clauses ) . ')';
            $params = array_merge( $params, $visibility_params );
        }

        if ( $assigned_to_me ) {
            $sql_where .= " AND EXISTS (
                SELECT 1 FROM {$assignments_table} assigned_to_actor
                WHERE assigned_to_actor.task_id = t.id
                  AND assigned_to_actor.user_id = %d
                  AND (assigned_to_actor.role = 'assignee' OR assigned_to_actor.role IS NULL)
            )";
            $params[] = $user_id;
        }

        if ( ! $include_templates ) {
            $sql_where .= ' AND t.is_recurring = 0';
        }

        if ( ! empty( $task_type_filter ) ) {
            $sql_where .= ' AND t.task_type = %s';
            $params[] = $task_type_filter;
        }

        if ( ! empty( $search ) ) {
            $search_term = '%' . $wpdb->esc_like( $search ) . '%';
            $sql_where .= " AND (
                t.name LIKE %s
                OR t.description LIKE %s
                OR EXISTS (
                    SELECT 1
                    FROM {$assignments_table} search_assignment
                    INNER JOIN {$users_table} search_user ON search_user.ID = search_assignment.user_id
                    WHERE search_assignment.task_id = t.id
                      AND search_user.display_name LIKE %s
                )
            )";
            $params[] = $search_term;
            $params[] = $search_term;
            $params[] = $search_term;
        }

        if ( ! empty( $status_filter ) ) {
            if ( 'pending_in-progress' === $status_filter ) {
                $sql_where .= " AND t.status IN ('pending', 'in-progress')";
            } elseif ( 'missed_deadline' === $status_filter ) {
                $sql_where .= " AND t.status IN ('pending', 'in-progress') AND t.deadline IS NOT NULL AND t.deadline < %s";
                $params[] = wp_date( 'Y-m-d' );
            } else {
                $sql_where .= ' AND t.status = %s';
                $params[] = $status_filter;
            }
        }

        if ( null !== $project_filter ) {
            if ( is_numeric( $project_filter ) && $project_filter > 0 ) {
                $sql_where .= ' AND t.project_id = %d';
                $params[] = $project_filter;
            } elseif ( 'none' === $project_filter ) {
                $sql_where .= ' AND (t.project_id IS NULL OR t.project_id = 0)';
            }
        }

        $allowed_sort_columns = array( 'name', 'priority', 'deadline', 'status', 'assigned_user_names', 'category_name', 'project_name', 'created_at' );

        if ( in_array( $sort_by, $allowed_sort_columns, true ) ) {
            $order = 'DESC' === strtoupper( $sort_order ) ? 'DESC' : 'ASC';

            if ( 'assigned_user_names' === $sort_by ) {
                $sql_order = " ORDER BY (
                    SELECT MIN(sort_user.display_name)
                    FROM {$assignments_table} sort_assignment
                    INNER JOIN {$users_table} sort_user ON sort_user.ID = sort_assignment.user_id
                    WHERE sort_assignment.task_id = t.id
                      AND (sort_assignment.role = 'assignee' OR sort_assignment.role IS NULL)
                ) {$order}, t.id {$order}";
            } elseif ( 'category_name' === $sort_by ) {
                $sql_order = " ORDER BY c.name {$order}, t.id {$order}";
            } elseif ( 'project_name' === $sort_by ) {
                $sql_order = " ORDER BY (p.name IS NULL OR p.name = '') ASC, p.name {$order}, t.name ASC, t.id ASC";
            } else {
                $sql_order = " ORDER BY t.{$sort_by} {$order}, t.id {$order}";
            }
        } else {
            $sql_order = ' ORDER BY t.name ASC, t.id ASC';
        }

        if ( $limit > 0 ) {
            $sql_order .= ' LIMIT %d OFFSET %d';
            $params[] = min( 501, max( 1, (int) $limit ) );
            $params[] = max( 0, (int) $offset );
        }

        $results = $wpdb->get_results( $wpdb->prepare( $sql_select . $sql_where . $sql_order, ...$params ) );
        $this->hydrateTaskCollection( $results, $tasks_table );

        foreach ( $results as $task ) {
            $task->creator_id = $task->creator_id ? (int) $task->creator_id : 0;
        }

        return $results;
    }

    public function findPotentialParentTasks( $board_name, $current_task_id = 0 ) {
        global $wpdb;

        $tasks_table = DatabaseContext::getDbPrefix() . 'tasks';
        $tasks = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, name, parent_task_id FROM {$tasks_table}
                 WHERE board_name = %s AND archived = 0
                 ORDER BY name ASC",
                $board_name
            )
        );

        if ( $current_task_id <= 0 ) {
            return $tasks;
        }

        $children_by_parent = array();

        foreach ( $tasks as $task ) {
            $parent_id = (int) $task->parent_task_id;

            if ( $parent_id > 0 ) {
                $children_by_parent[ $parent_id ][] = (int) $task->id;
            }
        }

        $excluded_ids = array( (int) $current_task_id => true );
        $queue = array( (int) $current_task_id );

        for ( $index = 0; $index < count( $queue ); $index++ ) {
            foreach ( $children_by_parent[ $queue[ $index ] ] ?? array() as $child_id ) {
                if ( isset( $excluded_ids[ $child_id ] ) ) {
                    continue;
                }

                $excluded_ids[ $child_id ] = true;
                $queue[] = $child_id;
            }
        }

        return array_values( array_filter( $tasks, static function ( $task ) use ( $excluded_ids ) {
            return ! isset( $excluded_ids[ (int) $task->id ] );
        } ) );
    }

    private function hydrateTaskCollection( $tasks, $tasks_table ) {
        global $wpdb;

        if ( empty( $tasks ) ) {
            return;
        }

        $task_ids = array_values( array_filter( array_map( 'absint', wp_list_pluck( $tasks, 'id' ) ) ) );

        if ( empty( $task_ids ) ) {
            return;
        }

        $task_ids_sql = implode( ',', $task_ids );
        $prefix = DatabaseContext::getDbPrefix();
        $rel_table = $prefix . 'task_relationships';
        $assignments_table = $prefix . 'assignments';
        $relationship_rows = $wpdb->get_results(
            "SELECT r.task_id, t.id, t.name, t.status, t.archived
             FROM {$rel_table} r
             INNER JOIN {$tasks_table} t ON r.predecessor_id = t.id
             WHERE r.task_id IN ({$task_ids_sql})
             ORDER BY r.task_id ASC, r.id ASC"
        );
        $relationships_by_task = array();

        foreach ( $relationship_rows as $relationship ) {
            $relationships_by_task[ (int) $relationship->task_id ][] = $relationship;
        }

        $assignment_rows = $wpdb->get_results(
            "SELECT task_id, user_id, role
             FROM {$assignments_table}
             WHERE task_id IN ({$task_ids_sql})
             ORDER BY task_id ASC, role ASC, user_id ASC"
        );
        $assignments_by_task = array();
        $all_user_ids = array();

        foreach ( $assignment_rows as $assignment ) {
            $task_id = (int) $assignment->task_id;
            $user_id = (int) $assignment->user_id;
            $role = 'supervisor' === $assignment->role ? 'supervisor' : 'assignee';
            $assignments_by_task[ $task_id ][ $role ][] = $user_id;
            $all_user_ids[] = $user_id;
        }

        $all_user_ids = array_values( array_unique( array_filter( $all_user_ids ) ) );

        if ( ! empty( $all_user_ids ) && function_exists( 'cache_users' ) ) {
            cache_users( $all_user_ids );
        }

        $this->hydrated_users = array();

        foreach ( $tasks as $task ) {
            $relationships = $relationships_by_task[ (int) $task->id ] ?? array();
            $task->category_name = $task->category_name ?? null;
            $task->predecessors = array_map( static function ( $relationship ) {
                return (object) array(
                    'id'     => (int) $relationship->id,
                    'name'   => $relationship->name,
                    'status' => $relationship->status,
                );
            }, $relationships );
            $task->predecessor_ids = array_map( 'intval', wp_list_pluck( $task->predecessors, 'id' ) );
            $task->is_blocked = false;

            foreach ( $relationships as $relationship ) {
                if ( 'done' !== $relationship->status && empty( $relationship->archived ) ) {
                    $task->is_blocked = true;
                    break;
                }
            }

            $task_assignments = $assignments_by_task[ (int) $task->id ] ?? array();
            $this->hydrateUsers(
                $task,
                $task_assignments['assignee'] ?? array(),
                $task_assignments['supervisor'] ?? array()
            );
        }

        $this->hydrated_users = array();
    }

    private function hydrateUsers( $task, $assigned_user_ids = array(), $supervisor_user_ids = array() ) {
        list( $task->assigned_users, $task->assigned_user_ids ) = $this->buildHydratedUsers( $assigned_user_ids );
        list( $task->supervisor_users, $task->supervisor_user_ids ) = $this->buildHydratedUsers( $supervisor_user_ids );
    }

    private function buildHydratedUsers( $user_ids ) {
        $raw_user_ids    = array_values( array_unique( array_filter( array_map( 'absint', (array) $user_ids ) ) ) );
        $users           = array();
        $final_user_ids  = array();

        foreach ( $raw_user_ids as $user_id ) {
            if ( isset( $this->hydrated_users[ $user_id ] ) ) {
                $users[]          = $this->hydrated_users[ $user_id ];
                $final_user_ids[] = (string) $user_id;
                continue;
            }

            $user_data = get_userdata( $user_id );

            if ( ! $user_data ) {
                continue;
            }

            $hydrated_user = (object) array(
                'id'     => (string) $user_id,
                'name'   => $user_data->display_name,
                'avatar' => get_avatar_url( $user_id, array( 'size' => 24, 'default' => 'mystery' ) ),
            );
            $this->hydrated_users[ $user_id ] = $hydrated_user;
            $users[]                            = $hydrated_user;
            $final_user_ids[]                   = (string) $user_id;
        }

        return array( $users, $final_user_ids );
    }
}
