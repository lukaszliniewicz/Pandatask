<?php

namespace Pandatask\Infrastructure\Persistence;

final class ProjectRepository {

    public function findForBoard( $board_name ) {
        return $this->findForBoards( array( $board_name ) );
    }

    public function findForUserWorkspace( $user_id, $board_names ) {
        return $this->findForBoards( $board_names, (int) $user_id, 'user_' . (int) $user_id );
    }

    private function findForBoards( $board_names, $workspace_user_id = 0, $private_board_name = '' ) {
        global $wpdb;

        $board_names = array_values(
            array_unique(
                array_filter(
                    array_map( 'sanitize_key', (array) $board_names )
                )
            )
        );

        if ( empty( $board_names ) ) {
            return array();
        }

        $prefix         = DatabaseContext::getDbPrefix();
        $projects_table = $prefix . 'projects';
        $placeholders   = implode( ', ', array_fill( 0, count( $board_names ), '%s' ) );
        $projects       = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.*
                 FROM {$projects_table} p
                 WHERE p.board_name IN ({$placeholders})
                 ORDER BY p.board_name ASC, p.name ASC",
                ...$board_names
            )
        );

        if ( empty( $projects ) ) {
            return array();
        }

        $project_ids = array_values( array_filter( array_map( 'absint', wp_list_pluck( $projects, 'id' ) ) ) );
        $assignment_map = $this->findProjectAssignments( $project_ids );
        $task_map = $this->findProjectTasks( $project_ids, $workspace_user_id, $private_board_name );
        $all_user_ids = array();

        foreach ( $assignment_map as $roles ) {
            $all_user_ids = array_merge( $all_user_ids, $roles['assignee'], $roles['supervisor'] );
        }

        $all_user_ids = array_values( array_unique( array_filter( array_map( 'absint', $all_user_ids ) ) ) );

        if ( ! empty( $all_user_ids ) && function_exists( 'cache_users' ) ) {
            cache_users( $all_user_ids );
        }

        foreach ( $projects as $project ) {
            $project_id = (int) $project->id;
            $roles = $assignment_map[ $project_id ] ?? array(
                'assignee'   => array(),
                'supervisor' => array(),
            );

            $project->assigned_user_ids   = $roles['assignee'];
            $project->assigned_users      = $this->hydrateUsers( $roles['assignee'], true );
            $project->supervisor_user_ids = $roles['supervisor'];
            $project->supervisor_users    = $this->hydrateUsers( $roles['supervisor'], true );
            $project->tasks               = $task_map[ $project_id ] ?? array();
        }

        return $projects;
    }

    private function findProjectAssignments( $project_ids ) {
        global $wpdb;

        $assignment_map = array();

        if ( empty( $project_ids ) ) {
            return $assignment_map;
        }

        $assignments_table = DatabaseContext::getDbPrefix() . 'project_assignments';
        $project_ids_sql = implode( ',', array_map( 'absint', $project_ids ) );
        $rows = $wpdb->get_results(
            "SELECT project_id, user_id, role
             FROM {$assignments_table}
             WHERE project_id IN ({$project_ids_sql})
             ORDER BY project_id ASC, role ASC, user_id ASC"
        );

        foreach ( $rows as $row ) {
            $project_id = (int) $row->project_id;
            $role = 'supervisor' === $row->role ? 'supervisor' : 'assignee';

            if ( ! isset( $assignment_map[ $project_id ] ) ) {
                $assignment_map[ $project_id ] = array(
                    'assignee'   => array(),
                    'supervisor' => array(),
                );
            }

            $assignment_map[ $project_id ][ $role ][] = (int) $row->user_id;
        }

        return $assignment_map;
    }

    private function findProjectTasks( $project_ids, $workspace_user_id = 0, $private_board_name = '' ) {
        global $wpdb;

        $task_map = array();

        if ( empty( $project_ids ) ) {
            return $task_map;
        }

        $prefix          = DatabaseContext::getDbPrefix();
        $tasks_table     = $prefix . 'tasks';
        $assignments     = $prefix . 'assignments';
        $project_ids_sql = implode( ',', array_map( 'absint', $project_ids ) );
        $sql             = "SELECT
                                t.id,
                                t.name,
                                t.project_id,
                                t.status,
                                t.start_date,
                                t.deadline,
                                t.priority,
                                t.parent_task_id
                            FROM {$tasks_table} t
                            WHERE t.project_id IN ({$project_ids_sql})
                            AND t.archived = 0
                            AND t.status != 'done'";

        if ( $workspace_user_id > 0 ) {
            $sql .= $wpdb->prepare(
                " AND (
                    t.board_name = %s
                    OR EXISTS (
                        SELECT 1 FROM {$assignments} ta
                        WHERE ta.task_id = t.id AND ta.user_id = %d
                    )
                    OR t.creator_id = %d
                )",
                $private_board_name,
                $workspace_user_id,
                $workspace_user_id
            );
        }

        $sql .= " ORDER BY
                    t.project_id ASC,
                    CASE t.status
                        WHEN 'in-progress' THEN 0
                        WHEN 'pending' THEN 1
                        ELSE 2
                    END ASC,
                    COALESCE(t.deadline, '9999-12-31') ASC,
                    t.name ASC,
                    t.id ASC";
        $tasks = $wpdb->get_results( $sql );

        foreach ( $tasks as $task ) {
            $project_id = (int) $task->project_id;
            $task_map[ $project_id ][] = (object) array(
                'id'             => (int) $task->id,
                'name'           => $task->name,
                'status'         => $task->status,
                'start_date'     => $task->start_date,
                'deadline'       => $task->deadline,
                'priority'       => (int) $task->priority,
                'parent_task_id' => $task->parent_task_id ? (int) $task->parent_task_id : null,
            );
        }

        return $task_map;
    }

    public function findById( $project_id ) {
        global $wpdb;

        $projects_table = DatabaseContext::getDbPrefix() . 'projects';

        $project = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT *
                 FROM {$projects_table}
                 WHERE id = %d",
                $project_id
            )
        );

        if ( ! $project ) {
            return $project;
        }

        $assignment_map = $this->findProjectAssignments( array( (int) $project->id ) );
        $roles = $assignment_map[ (int) $project->id ] ?? array(
            'assignee'   => array(),
            'supervisor' => array(),
        );
        $all_user_ids = array_values( array_unique( array_merge( $roles['assignee'], $roles['supervisor'] ) ) );

        if ( ! empty( $all_user_ids ) && function_exists( 'cache_users' ) ) {
            cache_users( $all_user_ids );
        }

        $project->assigned_user_ids = $roles['assignee'];
        $project->assigned_users = $this->hydrateUsers( $roles['assignee'], false );
        $project->supervisor_user_ids = $roles['supervisor'];
        $project->supervisor_users = $this->hydrateUsers( $roles['supervisor'], false );

        return $project;
    }

    public function create( $data ) {
        global $wpdb;

        $projects_table = DatabaseContext::getDbPrefix() . 'projects';
        $project_data   = array(
            'board_name'  => $data['board_name'],
            'name'        => $data['name'],
            'description' => $data['description'],
            'deadline'    => $data['deadline'] ?: null,
            'created_at'  => gmdate( 'Y-m-d H:i:s' ),
            'updated_at'  => gmdate( 'Y-m-d H:i:s' ),
        );
        $format         = array( '%s', '%s', '%s', '%s', '%s', '%s' );

        if ( ! DatabaseContext::beginTransaction() ) {
            return false;
        }

        $result = $wpdb->insert( $projects_table, $project_data, $format );

        if ( false === $result ) {
            DatabaseContext::rollback();
            return false;
        }

        $project_id = $wpdb->insert_id;

        if (
            ! $this->updateAssignments( $project_id, $data['assigned_persons'] ?? array(), 'assignee' )
            || ! $this->updateAssignments( $project_id, $data['supervisor_persons'] ?? array(), 'supervisor' )
            || ! DatabaseContext::commit()
        ) {
            DatabaseContext::rollback();

            return false;
        }

        return $project_id;
    }

    public function update( $project_id, $data ) {
        global $wpdb;

        $projects_table = DatabaseContext::getDbPrefix() . 'projects';
        $allowed_fields = array( 'name', 'description', 'deadline' );
        $update_data    = array();
        $format         = array();

        foreach ( $data as $key => $value ) {
            if ( in_array( $key, $allowed_fields, true ) ) {
                $update_data[ $key ] = $value;
                $format[]            = '%s';
            }
        }

        if ( ! DatabaseContext::beginTransaction() ) {
            return false;
        }

        if ( ! empty( $update_data ) ) {
            $update_data['updated_at'] = gmdate( 'Y-m-d H:i:s' );
            $format[]                  = '%s';

            if ( false === $wpdb->update( $projects_table, $update_data, array( 'id' => $project_id ), $format, array( '%d' ) ) ) {
                DatabaseContext::rollback();

                return false;
            }
        }

        if ( isset( $data['assigned_persons'] ) && ! $this->updateAssignments( $project_id, $data['assigned_persons'], 'assignee' ) ) {
            DatabaseContext::rollback();

            return false;
        }

        if ( isset( $data['supervisor_persons'] ) && ! $this->updateAssignments( $project_id, $data['supervisor_persons'], 'supervisor' ) ) {
            DatabaseContext::rollback();

            return false;
        }

        return DatabaseContext::commit();
    }

    public function delete( $project_id ) {
        global $wpdb;

        $prefix            = DatabaseContext::getDbPrefix();
        $projects_table    = $prefix . 'projects';
        $assignments_table = $prefix . 'project_assignments';
        $references_table  = $prefix . 'project_task_references';
        $tasks_table       = $prefix . 'tasks';

        if ( ! DatabaseContext::beginTransaction() ) {
            return false;
        }

        if (
            false === $wpdb->update( $tasks_table, array( 'project_id' => null ), array( 'project_id' => $project_id ), array( '%s' ), array( '%d' ) )
            || false === $wpdb->delete( $assignments_table, array( 'project_id' => $project_id ), array( '%d' ) )
            || false === $wpdb->delete( $references_table, array( 'project_id' => $project_id ), array( '%d' ) )
        ) {
            DatabaseContext::rollback();

            return false;
        }

        $result = $wpdb->delete( $projects_table, array( 'id' => $project_id ), array( '%d' ) );

        if ( false === $result || ! DatabaseContext::commit() ) {
            DatabaseContext::rollback();

            return false;
        }

        return true;
    }

    public function existsOnBoard( $project_id, $board_name ) {
        global $wpdb;

        $projects_table = DatabaseContext::getDbPrefix() . 'projects';
        $count          = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$projects_table} WHERE id = %d AND board_name = %s",
                $project_id,
                $board_name
            )
        );

        return $count > 0;
    }

    private function updateAssignments( $project_id, $user_ids, $role = 'assignee' ) {
        global $wpdb;

        $assignments_table   = DatabaseContext::getDbPrefix() . 'project_assignments';
        $new_user_ids        = array_map( 'absint', (array) $user_ids );
        $new_user_ids        = array_filter( $new_user_ids );
        $current_assignments = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT user_id FROM {$assignments_table} WHERE project_id = %d AND role = %s",
                $project_id,
                $role
            )
        );
        $current_user_ids    = wp_list_pluck( $current_assignments, 'user_id' );
        $users_to_remove     = array_diff( $current_user_ids, $new_user_ids );

        if ( ! empty( $users_to_remove ) ) {
            $user_ids_safe_string = implode( ',', array_map( 'absint', $users_to_remove ) );
            $delete_result = $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$assignments_table} WHERE project_id = %d AND role = %s AND user_id IN ($user_ids_safe_string)",
                    $project_id,
                    $role
                )
            );

            if ( false === $delete_result ) {
                return false;
            }
        }

        $users_to_add = array_diff( $new_user_ids, $current_user_ids );

        if ( ! empty( $users_to_add ) ) {
            foreach ( $users_to_add as $user_id ) {
                $insert_result = $wpdb->insert(
                    $assignments_table,
                    array(
                        'project_id' => $project_id,
                        'user_id'    => $user_id,
                        'role'       => $role,
                    ),
                    array( '%d', '%d', '%s' )
                );

                if ( false === $insert_result ) {
                    return false;
                }
            }
        }

        return true;
    }

    private function hydrateUsers( $user_ids, $include_avatar ) {
        $users = array();

        foreach ( $user_ids as $id ) {
            $user = get_userdata( $id );

            if ( ! $user ) {
                continue;
            }

            $user_data = array(
                'id'   => $user->ID,
                'name' => $user->display_name,
            );

            if ( $include_avatar ) {
                $user_data['avatar'] = get_avatar_url( $user->ID, array( 'size' => 24, 'default' => 'mystery' ) );
            }

            $users[] = $user_data;
        }

        return $users;
    }
}
