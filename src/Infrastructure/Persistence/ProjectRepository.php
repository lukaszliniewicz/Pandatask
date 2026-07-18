<?php

namespace Pandatask\Infrastructure\Persistence;

final class ProjectRepository {

    public function findForBoard( $board_name ) {
        global $wpdb;

        $prefix                    = DatabaseContext::getDbPrefix();
        $projects_table            = $prefix . 'projects';
        $project_assignments_table = $prefix . 'project_assignments';
        $tasks_table               = $prefix . 'tasks';
        $task_assignments_table    = $prefix . 'assignments';
        $projects                  = array();

        if ( preg_match( '/^user_(\d+)$/', $board_name, $matches ) ) {
            $user_id  = intval( $matches[1] );
            $projects = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT p.*,
                        GROUP_CONCAT(DISTINCT CASE WHEN pa.role = 'assignee' THEN pa.user_id END) as assigned_user_ids,
                        GROUP_CONCAT(DISTINCT CASE WHEN pa.role = 'supervisor' THEN pa.user_id END) as supervisor_user_ids,
                        (SELECT GROUP_CONCAT(DISTINCT CONCAT(t_user.id, '::', t_user.name) SEPARATOR ';;')
                         FROM {$tasks_table} t_user
                         INNER JOIN {$task_assignments_table} ta_user ON t_user.id = ta_user.task_id
                         WHERE t_user.project_id = p.id AND ta_user.user_id = %d AND t_user.archived = 0
                        ) as tasks_data
                    FROM {$projects_table} p
                    LEFT JOIN {$project_assignments_table} pa ON p.id = pa.project_id
                    WHERE p.board_name = %s
                    GROUP BY p.id
                    ORDER BY p.name ASC",
                    $user_id,
                    $board_name
                )
            );
        } else {
            $projects = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT p.*,
                     GROUP_CONCAT(DISTINCT CASE WHEN a.role = 'assignee' THEN a.user_id END) as assigned_user_ids,
                     GROUP_CONCAT(DISTINCT CASE WHEN a.role = 'supervisor' THEN a.user_id END) as supervisor_user_ids,
                     GROUP_CONCAT(DISTINCT CONCAT(t.id, '::', t.name) SEPARATOR ';;') as tasks_data
                     FROM {$projects_table} p
                     LEFT JOIN {$project_assignments_table} a ON p.id = a.project_id
                     LEFT JOIN {$tasks_table} t ON p.id = t.project_id AND t.archived = 0
                     WHERE p.board_name = %s
                     GROUP BY p.id
                     ORDER BY p.name ASC",
                    $board_name
                )
            );
        }

        foreach ( $projects as $project ) {
            $assigned_user_ids           = ! empty( $project->assigned_user_ids ) ? array_map( 'absint', explode( ',', $project->assigned_user_ids ) ) : array();
            $project->assigned_users     = $this->hydrateUsers( $assigned_user_ids, true );
            $project->assigned_user_ids  = $assigned_user_ids;

            $supervisor_user_ids         = ! empty( $project->supervisor_user_ids ) ? array_map( 'absint', explode( ',', $project->supervisor_user_ids ) ) : array();
            $project->supervisor_users   = $this->hydrateUsers( $supervisor_user_ids, true );
            $project->supervisor_user_ids = $supervisor_user_ids;

            $project->tasks = array();

            if ( ! empty( $project->tasks_data ) ) {
                $task_items = explode( ';;', $project->tasks_data );

                foreach ( $task_items as $task_item ) {
                    if ( false !== strpos( $task_item, '::' ) ) {
                        list( $id, $name ) = explode( '::', $task_item, 2 );

                        if ( ! empty( $id ) && ! empty( $name ) ) {
                            $project->tasks[] = (object) array(
                                'id'   => (int) $id,
                                'name' => $name,
                            );
                        }
                    }
                }
            }

            unset( $project->tasks_data );
        }

        return $projects;
    }

    public function findById( $project_id ) {
        global $wpdb;

        $prefix            = DatabaseContext::getDbPrefix();
        $projects_table    = $prefix . 'projects';
        $assignments_table = $prefix . 'project_assignments';

        $project = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT p.*,
                 GROUP_CONCAT(DISTINCT CASE WHEN a.role = 'assignee' THEN a.user_id END) as assigned_user_ids,
                 GROUP_CONCAT(DISTINCT CASE WHEN a.role = 'supervisor' THEN a.user_id END) as supervisor_user_ids
                 FROM {$projects_table} p
                 LEFT JOIN {$assignments_table} a ON p.id = a.project_id
                 WHERE p.id = %d
                 GROUP BY p.id",
                $project_id
            )
        );

        if ( ! $project ) {
            return $project;
        }

        $assigned_user_ids          = ! empty( $project->assigned_user_ids ) ? explode( ',', $project->assigned_user_ids ) : array();
        $project->assigned_users    = $this->hydrateUsers( $assigned_user_ids, false );
        $project->assigned_user_ids = $assigned_user_ids;

        $supervisor_user_ids           = ! empty( $project->supervisor_user_ids ) ? explode( ',', $project->supervisor_user_ids ) : array();
        $project->supervisor_users     = $this->hydrateUsers( $supervisor_user_ids, false );
        $project->supervisor_user_ids  = $supervisor_user_ids;

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
        $tasks_table       = $prefix . 'tasks';

        if ( ! DatabaseContext::beginTransaction() ) {
            return false;
        }

        if (
            false === $wpdb->update( $tasks_table, array( 'project_id' => null ), array( 'project_id' => $project_id ), array( '%s' ), array( '%d' ) )
            || false === $wpdb->delete( $assignments_table, array( 'project_id' => $project_id ), array( '%d' ) )
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
