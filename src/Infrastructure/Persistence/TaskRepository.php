<?php

namespace Pandatask\Infrastructure\Persistence;

final class TaskRepository {

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

    public function findForBoard( $board_name, $search = '', $sort_by = 'name', $sort_order = 'ASC', $status_filter = '', $date_filter = '', $start_date = '', $end_date = '', $archived = 0, $project_filter = null, $include_templates = false, $task_type_filter = '', $user_id = null ) {
        global $wpdb;

        $prefix            = DatabaseContext::getDbPrefix();
        $tasks_table       = $prefix . 'tasks';
        $assignments_table = $prefix . 'assignments';
        $users_table       = $wpdb->users;
        $categories_table  = $prefix . 'categories';
        $projects_table    = $prefix . 'projects';
        $sql_select        = "SELECT t.*, c.name as category_name, p.name as project_name,
             GROUP_CONCAT(DISTINCT CASE WHEN a.role = 'assignee' OR a.role IS NULL THEN u.ID END) as assigned_user_ids,
             GROUP_CONCAT(DISTINCT CASE WHEN a.role = 'assignee' OR a.role IS NULL THEN u.display_name END SEPARATOR ', ') as assigned_user_names,
             GROUP_CONCAT(DISTINCT CASE WHEN a.role = 'supervisor' THEN u.ID END) as supervisor_user_ids,
             GROUP_CONCAT(DISTINCT CASE WHEN a.role = 'supervisor' THEN u.display_name END SEPARATOR ', ') as supervisor_user_names,
             parent.name as parent_task_name,
             parent.status as parent_task_status
             FROM {$tasks_table} t
             LEFT JOIN {$categories_table} c ON t.category_id = c.id AND c.board_name = t.board_name
             LEFT JOIN {$projects_table} p ON t.project_id = p.id AND p.board_name = t.board_name
             LEFT JOIN {$assignments_table} a ON t.id = a.task_id
             LEFT JOIN {$users_table} u ON a.user_id = u.ID
             LEFT JOIN {$tasks_table} parent ON t.parent_task_id = parent.id";
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

        if ( ! empty( $search ) ) {
            $search_term = '%' . $wpdb->esc_like( $search ) . '%';
            $sql_where  .= ' AND (t.name LIKE %s OR t.description LIKE %s OR u.display_name LIKE %s)';
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

        $sql_group_order      = ' GROUP BY t.id';
        $allowed_sort_columns = array( 'name', 'priority', 'deadline', 'status', 'assigned_user_names', 'category_name', 'created_at' );

        if ( in_array( $sort_by, $allowed_sort_columns, true ) ) {
            $order = 'DESC' === strtoupper( $sort_order ) ? 'DESC' : 'ASC';

            if ( 'assigned_user_names' === $sort_by ) {
                $sql_group_order .= " ORDER BY assigned_user_names {$order}";
            } elseif ( 'category_name' === $sort_by ) {
                $sql_group_order .= " ORDER BY c.name {$order}";
            } else {
                $sql_group_order .= " ORDER BY t.{$sort_by} {$order}";
            }
        } else {
            $sql_group_order .= ' ORDER BY t.name ASC';
        }

        $results = $wpdb->get_results( $wpdb->prepare( $sql_select . $sql_where . $sql_group_order, ...$params ) );

        foreach ( $results as $task ) {
            $task->category_name  = $task->category_name ?? null;
            $task->predecessors   = $this->findPredecessors( $task->id, $tasks_table );
            $task->predecessor_ids = wp_list_pluck( $task->predecessors, 'id' );
            $task->is_blocked     = $this->isBlocked( $task->id );
            $this->hydrateUsers( $task );
        }

        return $results;
    }

    public function findById( $task_id ) {
        global $wpdb;

        $prefix            = DatabaseContext::getDbPrefix();
        $tasks_table       = $prefix . 'tasks';
        $assignments_table = $prefix . 'assignments';
        $users_table       = $wpdb->users;
        $categories_table  = $prefix . 'categories';
        $projects_table    = $prefix . 'projects';
        $history_table     = $prefix . 'task_history';
        $sql               = $wpdb->prepare(
            "SELECT t.*, c.name as category_name, p.name as project_name,
             GROUP_CONCAT(DISTINCT CASE WHEN a.role = 'assignee' OR a.role IS NULL THEN u.ID END) as assigned_user_ids,
             GROUP_CONCAT(DISTINCT CASE WHEN a.role = 'assignee' OR a.role IS NULL THEN u.display_name END SEPARATOR ', ') as assigned_user_names,
             GROUP_CONCAT(DISTINCT CASE WHEN a.role = 'supervisor' THEN u.ID END) as supervisor_user_ids,
             GROUP_CONCAT(DISTINCT CASE WHEN a.role = 'supervisor' THEN u.display_name END SEPARATOR ', ') as supervisor_user_names,
             parent.name as parent_task_name,
             creator.display_name as creator_name,
             creator.ID as creator_id
             FROM {$tasks_table} t
             LEFT JOIN {$categories_table} c ON t.category_id = c.id AND c.board_name = t.board_name
             LEFT JOIN {$projects_table} p ON t.project_id = p.id AND p.board_name = t.board_name
             LEFT JOIN {$assignments_table} a ON t.id = a.task_id
             LEFT JOIN {$users_table} u ON a.user_id = u.ID
             LEFT JOIN {$tasks_table} parent ON t.parent_task_id = parent.id
             LEFT JOIN {$history_table} h ON h.task_id = t.id AND h.field_changed = 'task_created'
             LEFT JOIN {$users_table} creator ON h.user_id = creator.ID
             WHERE t.id = %d
             GROUP BY t.id",
            $task_id
        );
        $task              = $wpdb->get_row( $sql );

        if ( ! $task ) {
            return $task;
        }

        $task->category_name = $task->category_name ?? null;
        $task->creator_id    = isset( $task->creator_id ) ? (int) $task->creator_id : 0;
        $this->hydrateUsers( $task );

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

    public function findForUserAcrossBoards( $user_id, $search = '', $sort_by = 'name', $sort_order = 'ASC', $status_filter = '', $archived = 0, $project_filter = null, $private_only = false, $include_templates = false ) {
        global $wpdb;

        $prefix            = DatabaseContext::getDbPrefix();
        $tasks_table       = $prefix . 'tasks';
        $assignments_table = $prefix . 'assignments';
        $users_table       = $wpdb->users;
        $categories_table  = $prefix . 'categories';
        $projects_table    = $prefix . 'projects';
        $sql               = $wpdb->prepare(
            "SELECT DISTINCT t.*, c.name as category_name, p.name as project_name,
             GROUP_CONCAT(DISTINCT CASE WHEN a_all.role = 'assignee' THEN u.ID END) as assigned_user_ids,
             GROUP_CONCAT(DISTINCT CASE WHEN a_all.role = 'assignee' THEN u.display_name END SEPARATOR ', ') as assigned_user_names,
             GROUP_CONCAT(DISTINCT CASE WHEN a_all.role = 'supervisor' THEN u.ID END) as supervisor_user_ids,
             GROUP_CONCAT(DISTINCT CASE WHEN a_all.role = 'supervisor' THEN u.display_name END SEPARATOR ', ') as supervisor_user_names,
             parent.name as parent_task_name,
             parent.status as parent_task_status,
             creator_h.user_id as creator_id
             FROM {$tasks_table} t
             LEFT JOIN {$assignments_table} a_user ON t.id = a_user.task_id AND a_user.user_id = %d
             LEFT JOIN {$categories_table} c ON t.category_id = c.id
             LEFT JOIN {$projects_table} p ON t.project_id = p.id
             LEFT JOIN {$assignments_table} a_all ON t.id = a_all.task_id
             LEFT JOIN {$users_table} u ON a_all.user_id = u.ID
             LEFT JOIN {$tasks_table} parent ON t.parent_task_id = parent.id
             LEFT JOIN {$prefix}task_history creator_h ON t.id = creator_h.task_id AND creator_h.field_changed = 'task_created'
             WHERE (a_user.user_id IS NOT NULL OR creator_h.user_id = %d) AND t.archived = %d",
            $user_id,
            $user_id,
            $archived
        );

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

        $sql               .= ' GROUP BY t.id';
        $allowed_sort_columns = array( 'name', 'priority', 'deadline', 'status', 'created_at' );

        if ( in_array( $sort_by, $allowed_sort_columns, true ) ) {
            $order = 'DESC' === strtoupper( $sort_order ) ? 'DESC' : 'ASC';
            $sql  .= " ORDER BY t.{$sort_by} {$order}";
        } else {
            $sql .= ' ORDER BY t.name ASC';
        }

        $results = $wpdb->get_results( $sql );

        foreach ( $results as $task ) {
            $task->category_name = $task->category_name ?? null;
            $task->creator_id    = $task->creator_id ? (int) $task->creator_id : 0;
            $this->hydrateUsers( $task );
        }

        return $results;
    }

    public function findPotentialParentTasks( $board_name, $current_task_id = 0 ) {
        global $wpdb;

        $tasks_table = DatabaseContext::getDbPrefix() . 'tasks';
        $sql         = $wpdb->prepare(
            "SELECT id, name FROM {$tasks_table}
             WHERE board_name = %s AND archived = 0",
            $board_name
        );

        if ( $current_task_id > 0 ) {
            $sql .= $wpdb->prepare( ' AND id != %d', $current_task_id );
            $sql .= $wpdb->prepare( " AND id NOT IN (SELECT id FROM {$tasks_table} WHERE parent_task_id = %d)", $current_task_id );
        }

        $sql .= ' ORDER BY name ASC';

        return $wpdb->get_results( $sql );
    }

    private function findPredecessors( $task_id, $tasks_table ) {
        global $wpdb;

        $rel_table = DatabaseContext::getDbPrefix() . 'task_relationships';

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT t.id, t.name, t.status
                 FROM {$rel_table} r
                 JOIN {$tasks_table} t ON r.predecessor_id = t.id
                 WHERE r.task_id = %d",
                $task_id
            )
        ) ?: array();
    }

    private function hydrateUsers( $task ) {
        list( $task->assigned_users, $task->assigned_user_ids )     = $this->buildHydratedUsers( $task->assigned_user_ids ?? '' );
        list( $task->supervisor_users, $task->supervisor_user_ids ) = $this->buildHydratedUsers( $task->supervisor_user_ids ?? '' );

        unset( $task->assigned_user_names, $task->supervisor_user_names );
    }

    private function buildHydratedUsers( $user_ids_csv ) {
        $raw_user_ids    = ! empty( $user_ids_csv ) ? array_filter( explode( ',', $user_ids_csv ) ) : array();
        $users           = array();
        $final_user_ids  = array();

        foreach ( $raw_user_ids as $user_id_str ) {
            $user_id = absint( $user_id_str );

            if ( $user_id <= 0 ) {
                continue;
            }

            $user_data = get_userdata( $user_id );

            if ( ! $user_data ) {
                continue;
            }

            $users[] = (object) array(
                'id'     => (string) $user_id,
                'name'   => $user_data->display_name,
                'avatar' => get_avatar_url( $user_id, array( 'size' => 24, 'default' => 'mystery' ) ),
            );
            $final_user_ids[] = (string) $user_id;
        }

        return array( $users, $final_user_ids );
    }
}
