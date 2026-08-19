<?php

namespace Pandatask\Infrastructure\Setup;

use Exception;
use Throwable;
use Pandatask\Domain\Task\TaskGraph;
use Pandatask\Infrastructure\Persistence\DatabaseContext;

final class DatabaseLifecycle {

    private const DB_VERSION = '1.0.15';

    public static function activate() {
        if ( self::createTables() && self::repairData() && self::verifySchema() ) {
            update_option( 'pandat69_db_version', self::DB_VERSION );
        }
    }

    public static function createTables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $prefix          = DatabaseContext::getDbPrefix();

        $table_tasks               = $prefix . 'tasks';
        $table_categories          = $prefix . 'categories';
        $table_assignments         = $prefix . 'assignments';
        $table_comments            = $prefix . 'comments';
        $table_projects            = $prefix . 'projects';
        $table_project_assignments = $prefix . 'project_assignments';
        $table_task_history        = $prefix . 'task_history';
        $table_change_buffers      = $prefix . 'task_change_buffers';
        $table_board_events        = $prefix . 'board_events';

        $upgrade_file = wp_normalize_path( ABSPATH . 'wp-admin/includes/upgrade.php' );
        if ( ! is_file( $upgrade_file ) ) {
            return false;
        }
        require_once $upgrade_file;

        $sql_tasks = "CREATE TABLE $table_tasks (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            board_name VARCHAR(100) NOT NULL,
            name VARCHAR(255) NOT NULL,
            description LONGTEXT NULL,
            task_type VARCHAR(20) NOT NULL DEFAULT 'task',
            bug_url VARCHAR(2048) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            category_id BIGINT(20) UNSIGNED NULL,
            project_id BIGINT(20) UNSIGNED NULL,
            priority TINYINT UNSIGNED NOT NULL DEFAULT 5,
            start_date DATE NULL,
            deadline DATE NULL,
            deadline_days_after_start INT UNSIGNED NULL,
            notify_deadline TINYINT(1) NOT NULL DEFAULT 0,
            notify_days_before INT UNSIGNED NOT NULL DEFAULT 3,
            archived TINYINT(1) NOT NULL DEFAULT 0,
            parent_task_id BIGINT(20) UNSIGNED NULL,
            completed_at DATETIME NULL,
            is_recurring TINYINT(1) NOT NULL DEFAULT 0,
            recurrence_frequency VARCHAR(20) NULL,
            recurrence_interval INT UNSIGNED NULL,
            recurrence_days VARCHAR(30) NULL,
            recurrence_ends_on DATE NULL,
            recurrence_anchor_day TINYINT UNSIGNED NULL,
            attachment_type VARCHAR(10) NULL,
            attachment_url VARCHAR(2048) NULL,
            attachment_post_id BIGINT(20) UNSIGNED NULL,
            attachment_filename VARCHAR(255) NULL,
            missed_deadline_notified TINYINT(1) NOT NULL DEFAULT 0,
            deadline_reminder_sent_for DATE NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY board_name (board_name),
            KEY task_type (task_type),
            KEY status (status),
            KEY priority (priority),
            KEY deadline (deadline),
            KEY start_date (start_date),
            KEY category_id (category_id),
            KEY project_id (project_id),
            KEY completed_at (completed_at),
            KEY archived (archived),
            KEY parent_task_id (parent_task_id),
            KEY is_recurring (is_recurring),
            KEY missed_deadline_notified (missed_deadline_notified),
            KEY board_active_status_deadline (board_name, archived, status, deadline),
            KEY board_list_deadline (board_name, archived, is_recurring, deadline, id),
            KEY board_created (board_name, created_at),
            KEY board_completed (board_name, status, completed_at),
            KEY scheduled_start (status, archived, start_date),
            KEY deadline_reminder_queue (notify_deadline, archived, deadline, status),
            KEY missed_deadline_queue (missed_deadline_notified, archived, deadline, status),
            KEY recurring_rollover (is_recurring, deadline, recurrence_ends_on, status),
            KEY project_active_tasks (project_id, archived, status, deadline)
        ) $charset_collate;";
        dbDelta( $sql_tasks );

        $sql_projects = "CREATE TABLE $table_projects (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            board_name VARCHAR(100) NOT NULL,
            name VARCHAR(255) NOT NULL,
            description LONGTEXT NULL,
            deadline DATE NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY board_name (board_name)
        ) $charset_collate;";
        dbDelta( $sql_projects );

        $sql_project_assignments = "CREATE TABLE $table_project_assignments (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id BIGINT(20) UNSIGNED NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            role VARCHAR(20) NOT NULL DEFAULT 'assignee',
            PRIMARY KEY  (id),
            UNIQUE KEY project_user_role (project_id, user_id, role),
            KEY project_id (project_id),
            KEY user_id (user_id)
        ) $charset_collate;";
        dbDelta( $sql_project_assignments );

        $sql_categories = "CREATE TABLE $table_categories (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            board_name VARCHAR(100) NOT NULL,
            name VARCHAR(100) NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY board_name_name (board_name, name),
            KEY board_name (board_name)
        ) $charset_collate;";
        dbDelta( $sql_categories );

        $sql_assignments = "CREATE TABLE $table_assignments (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            task_id BIGINT(20) UNSIGNED NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            role VARCHAR(20) NOT NULL DEFAULT 'assignee',
            PRIMARY KEY  (id),
            UNIQUE KEY task_user_role (task_id, user_id, role),
            KEY task_id (task_id),
            KEY user_id (user_id),
            KEY user_task_role (user_id, task_id, role)
        ) $charset_collate;";
        dbDelta( $sql_assignments );

        $sql_comments = "CREATE TABLE $table_comments (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            task_id BIGINT(20) UNSIGNED NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            comment_text TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY task_id (task_id),
            KEY user_id (user_id),
            KEY task_created (task_id, created_at),
            KEY task_created_id (task_id, created_at, id)
        ) $charset_collate;";
        dbDelta( $sql_comments );

        $sql_task_history = "CREATE TABLE $table_task_history (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            task_id BIGINT(20) UNSIGNED NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            field_changed VARCHAR(50) NOT NULL,
            old_value TEXT,
            new_value TEXT,
            change_comment TEXT,
            changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY task_id (task_id),
            KEY user_id (user_id),
            KEY task_field (task_id, field_changed),
            KEY task_changed (task_id, changed_at, id)
        ) $charset_collate;";
        dbDelta( $sql_task_history );

        $table_relationships = $prefix . 'task_relationships';
        $sql_relationships   = "CREATE TABLE $table_relationships (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            task_id BIGINT(20) UNSIGNED NOT NULL,
            predecessor_id BIGINT(20) UNSIGNED NOT NULL,
            type VARCHAR(10) NOT NULL DEFAULT 'FS',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY rel_unique (task_id, predecessor_id),
            KEY task_id (task_id),
            KEY predecessor_id (predecessor_id)
        ) $charset_collate;";
        dbDelta( $sql_relationships );

        $sql_change_buffers = "CREATE TABLE $table_change_buffers (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            task_id BIGINT(20) UNSIGNED NOT NULL,
            actor_id BIGINT(20) UNSIGNED NOT NULL,
            changes LONGTEXT NOT NULL,
            change_comment TEXT NULL,
            deliver_after DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY task_actor_delivery (task_id, actor_id, deliver_after),
            KEY delivery_queue (deliver_after)
        ) $charset_collate;";
        dbDelta( $sql_change_buffers );

        $sql_board_events = "CREATE TABLE $table_board_events (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            board_name VARCHAR(100) NOT NULL,
            task_id BIGINT(20) UNSIGNED NOT NULL,
            actor_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            event_type VARCHAR(32) NOT NULL,
            task_name VARCHAR(255) NOT NULL,
            task_status VARCHAR(20) NULL,
            event_data LONGTEXT NULL,
            promote TINYINT(1) NOT NULL DEFAULT 0,
            source_activity_id BIGINT(20) UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY source_activity (source_activity_id),
            KEY board_created (board_name, created_at, id),
            KEY task_id (task_id),
            KEY actor_id (actor_id),
            KEY event_type (event_type)
        ) $charset_collate;";
        dbDelta( $sql_board_events );

        return true;
    }

    public static function updateDbCheck() {
        $current_version = get_option( 'pandat69_db_version', '1.0.0' );

        if ( ! version_compare( $current_version, self::DB_VERSION, '<' ) ) {
            return;
        }

        if ( self::createTables() && self::repairData() && self::verifySchema() ) {
            update_option( 'pandat69_db_version', self::DB_VERSION );
        }
    }

    public static function verifySchema() {
        global $wpdb;

        $prefix = DatabaseContext::getDbPrefix();
        $required_tables = array(
            $prefix . 'tasks',
            $prefix . 'projects',
            $prefix . 'project_assignments',
            $prefix . 'categories',
            $prefix . 'assignments',
            $prefix . 'comments',
            $prefix . 'task_history',
            $prefix . 'task_relationships',
            $prefix . 'task_change_buffers',
            $prefix . 'board_events',
        );

        foreach ( $required_tables as $table ) {
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
                return false;
            }
        }

        $tasks_table = $prefix . 'tasks';
        $task_columns = wp_list_pluck( $wpdb->get_results( "SHOW COLUMNS FROM {$tasks_table}" ), 'Field' );

        foreach ( array( 'deadline_reminder_sent_for', 'recurrence_anchor_day' ) as $column ) {
            if ( ! in_array( $column, $task_columns, true ) ) {
                return false;
            }
        }

        $required_indexes = array(
            $tasks_table => array(
                'board_list_deadline',
                'scheduled_start',
                'deadline_reminder_queue',
                'missed_deadline_queue',
                'recurring_rollover',
                'project_active_tasks',
            ),
            $prefix . 'comments' => array( 'task_created_id' ),
            $prefix . 'task_history' => array( 'task_changed' ),
            $prefix . 'task_change_buffers' => array( 'task_actor_delivery', 'delivery_queue' ),
            $prefix . 'board_events' => array( 'source_activity', 'board_created', 'task_id', 'actor_id', 'event_type' ),
        );

        foreach ( $required_indexes as $table => $index_names ) {
            $present = array_unique( wp_list_pluck( $wpdb->get_results( "SHOW INDEX FROM {$table}" ), 'Key_name' ) );

            if ( ! empty( array_diff( $index_names, $present ) ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Normalize legacy rows before the new application-level invariants take over.
     */
    public static function repairData() {
        global $wpdb;

        if ( ! DatabaseContext::beginTransaction() ) {
            return false;
        }

        $prefix = DatabaseContext::getDbPrefix();
        $tasks = $prefix . 'tasks';
        $assignments = $prefix . 'assignments';
        $project_assignments = $prefix . 'project_assignments';
        $projects = $prefix . 'projects';
        $categories = $prefix . 'categories';
        $comments = $prefix . 'comments';
        $relationships = $prefix . 'task_relationships';
        $buffers = $prefix . 'task_change_buffers';
        $users = $wpdb->users;

        try {
            $queries = array(
                "DELETE assignment
                 FROM {$assignments} assignment
                 LEFT JOIN {$tasks} task ON task.id = assignment.task_id
                 LEFT JOIN {$users} user_record ON user_record.ID = assignment.user_id
                 WHERE task.id IS NULL OR user_record.ID IS NULL",
                "DELETE assignment
                 FROM {$project_assignments} assignment
                 LEFT JOIN {$projects} project ON project.id = assignment.project_id
                 LEFT JOIN {$users} user_record ON user_record.ID = assignment.user_id
                 WHERE project.id IS NULL OR user_record.ID IS NULL",
                "DELETE FROM {$assignments}
                 WHERE role NOT IN ('assignee', 'supervisor')",
                "DELETE FROM {$project_assignments}
                 WHERE role NOT IN ('assignee', 'supervisor')",
                "DELETE comment_record
                 FROM {$comments} comment_record
                 LEFT JOIN {$tasks} task ON task.id = comment_record.task_id
                 WHERE task.id IS NULL",
                "DELETE buffer_record
                 FROM {$buffers} buffer_record
                 LEFT JOIN {$tasks} task ON task.id = buffer_record.task_id
                 WHERE task.id IS NULL",
                "DELETE relationship
                 FROM {$relationships} relationship
                 LEFT JOIN {$tasks} task ON task.id = relationship.task_id
                 LEFT JOIN {$tasks} predecessor ON predecessor.id = relationship.predecessor_id
                 WHERE task.id IS NULL
                    OR predecessor.id IS NULL
                    OR relationship.task_id = relationship.predecessor_id
                    OR task.board_name <> predecessor.board_name",
                "UPDATE {$relationships}
                 SET type = 'FS'
                 WHERE type <> 'FS'",
                "UPDATE {$tasks} task
                 LEFT JOIN {$categories} category ON category.id = task.category_id
                 SET task.category_id = NULL, task.updated_at = UTC_TIMESTAMP()
                 WHERE task.category_id IS NOT NULL
                   AND (category.id IS NULL OR category.board_name <> task.board_name)",
                "UPDATE {$tasks} task
                 LEFT JOIN {$projects} project ON project.id = task.project_id
                 SET task.project_id = NULL, task.updated_at = UTC_TIMESTAMP()
                 WHERE task.project_id IS NOT NULL
                   AND (project.id IS NULL OR project.board_name <> task.board_name)",
                "UPDATE {$tasks} child
                 LEFT JOIN {$tasks} parent ON parent.id = child.parent_task_id
                 SET child.parent_task_id = NULL, child.updated_at = UTC_TIMESTAMP()
                 WHERE child.parent_task_id IS NOT NULL
                   AND (parent.id IS NULL OR parent.board_name <> child.board_name)",
                "UPDATE {$tasks}
                 SET status = 'pending', completed_at = NULL, updated_at = UTC_TIMESTAMP()
                 WHERE status NOT IN ('pending', 'in-progress', 'done')",
                "UPDATE {$tasks}
                 SET task_type = 'task', updated_at = UTC_TIMESTAMP()
                 WHERE task_type NOT IN ('task', 'bug')",
                "UPDATE {$tasks}
                 SET priority = LEAST(10, GREATEST(1, priority)),
                     notify_days_before = LEAST(30, GREATEST(1, notify_days_before))",
                "UPDATE {$tasks}
                 SET deadline = start_date,
                     deadline_reminder_sent_for = NULL,
                     missed_deadline_notified = 0,
                     updated_at = UTC_TIMESTAMP()
                 WHERE start_date IS NOT NULL AND deadline IS NOT NULL AND deadline < start_date",
                "UPDATE {$tasks}
                 SET completed_at = COALESCE(completed_at, updated_at, created_at, UTC_TIMESTAMP())
                 WHERE status = 'done' AND completed_at IS NULL",
                "UPDATE {$tasks}
                 SET completed_at = NULL
                 WHERE status <> 'done' AND completed_at IS NOT NULL",
                "UPDATE {$tasks} task
                 INNER JOIN {$relationships} relationship ON relationship.task_id = task.id
                 INNER JOIN {$tasks} predecessor ON predecessor.id = relationship.predecessor_id
                 SET task.status = 'pending',
                     task.completed_at = NULL,
                     task.updated_at = UTC_TIMESTAMP()
                 WHERE task.status <> 'pending'
                   AND predecessor.status <> 'done'
                   AND predecessor.archived = 0",
                "UPDATE {$tasks}
                 SET is_recurring = 0,
                     recurrence_frequency = NULL,
                     recurrence_interval = NULL,
                     recurrence_days = NULL,
                     recurrence_ends_on = NULL,
                     recurrence_anchor_day = NULL,
                     updated_at = UTC_TIMESTAMP()
                 WHERE is_recurring = 1
                   AND (
                       start_date IS NULL
                       OR deadline IS NULL
                       OR recurrence_frequency NOT IN ('weekly', 'monthly', 'custom_weekly')
                       OR COALESCE(recurrence_interval, 0) < 1
                       OR (
                           recurrence_frequency = 'custom_weekly'
                           AND COALESCE(recurrence_days, '') NOT REGEXP '^[1-7](,[1-7])*$'
                       )
                       OR (recurrence_ends_on IS NOT NULL AND recurrence_ends_on < start_date)
                   )",
                "UPDATE {$tasks}
                 SET recurrence_frequency = NULL,
                     recurrence_interval = NULL,
                     recurrence_days = NULL,
                     recurrence_ends_on = NULL,
                     recurrence_anchor_day = NULL,
                     updated_at = UTC_TIMESTAMP()
                 WHERE is_recurring = 0
                   AND (
                       recurrence_frequency IS NOT NULL
                       OR recurrence_interval IS NOT NULL
                       OR recurrence_days IS NOT NULL
                       OR recurrence_ends_on IS NOT NULL
                       OR recurrence_anchor_day IS NOT NULL
                   )",
                "UPDATE {$tasks}
                 SET recurrence_anchor_day = DAY(start_date)
                 WHERE is_recurring = 1
                   AND recurrence_frequency = 'monthly'
                   AND (recurrence_anchor_day IS NULL OR recurrence_anchor_day < 1 OR recurrence_anchor_day > 31)",
                "UPDATE {$tasks}
                 SET recurrence_anchor_day = NULL
                 WHERE recurrence_frequency <> 'monthly' AND recurrence_anchor_day IS NOT NULL",
            );

            foreach ( $queries as $query ) {
                if ( false === $wpdb->query( $query ) ) {
                    throw new Exception( 'A PandaTask data-repair query failed.' );
                }
            }

            if ( ! self::repairGraphCycles() || ! self::repairProjectInheritance() ) {
                throw new Exception( 'PandaTask relationship repair failed.' );
            }

            if ( ! DatabaseContext::commit() ) {
                throw new Exception( 'PandaTask data repair could not be committed.' );
            }
        } catch ( Throwable $exception ) {
            DatabaseContext::rollback();

            return false;
        }

        return true;
    }

    private static function repairGraphCycles() {
        global $wpdb;

        $prefix = DatabaseContext::getDbPrefix();
        $tasks = $prefix . 'tasks';
        $relationships = $prefix . 'task_relationships';

        for ( $pass = 0; $pass < 1000; $pass++ ) {
            $edges = array();

            foreach ( $wpdb->get_results( "SELECT id, parent_task_id FROM {$tasks} WHERE parent_task_id IS NOT NULL" ) as $row ) {
                $edges[ (int) $row->id ][] = (int) $row->parent_task_id;
            }

            $cycle_nodes = TaskGraph::findCycleNodes( $edges );

            if ( empty( $cycle_nodes ) ) {
                break;
            }

            $victim = max( $cycle_nodes );

            if (
                false === $wpdb->update(
                    $tasks,
                    array(
                        'parent_task_id' => null,
                        'updated_at'     => gmdate( 'Y-m-d H:i:s' ),
                    ),
                    array( 'id' => $victim ),
                    array( '%s', '%s' ),
                    array( '%d' )
                )
            ) {
                return false;
            }
        }

        $parent_edges = array();

        foreach ( $wpdb->get_results( "SELECT id, parent_task_id FROM {$tasks} WHERE parent_task_id IS NOT NULL" ) as $row ) {
            $parent_edges[ (int) $row->id ][] = (int) $row->parent_task_id;
        }

        if ( ! empty( TaskGraph::findCycleNodes( $parent_edges ) ) ) {
            return false;
        }

        for ( $pass = 0; $pass < 1000; $pass++ ) {
            $rows = $wpdb->get_results( "SELECT id, task_id, predecessor_id FROM {$relationships} ORDER BY id ASC" );
            $edges = array();

            foreach ( $rows as $row ) {
                $edges[ (int) $row->task_id ][] = (int) $row->predecessor_id;
            }

            $cycle_nodes = TaskGraph::findCycleNodes( $edges );

            if ( empty( $cycle_nodes ) ) {
                return true;
            }

            $cycle_lookup = array_fill_keys( $cycle_nodes, true );
            $victim_id = 0;

            foreach ( $rows as $row ) {
                if ( isset( $cycle_lookup[ (int) $row->task_id ], $cycle_lookup[ (int) $row->predecessor_id ] ) ) {
                    $victim_id = max( $victim_id, (int) $row->id );
                }
            }

            if ( $victim_id <= 0 || false === $wpdb->delete( $relationships, array( 'id' => $victim_id ), array( '%d' ) ) ) {
                return false;
            }
        }

        return false;
    }

    public static function repairProjectInheritance() {
        global $wpdb;

        $tasks_table = DatabaseContext::getDbPrefix() . 'tasks';
        $assignments_table = DatabaseContext::getDbPrefix() . 'assignments';
        $history_table = DatabaseContext::getDbPrefix() . 'task_history';
        $mismatches = $wpdb->get_results(
            "SELECT child.id, child.board_name
             FROM {$tasks_table} child
             LEFT JOIN {$tasks_table} parent ON parent.id = child.parent_task_id
             WHERE child.parent_task_id IS NOT NULL
             AND (
                 parent.id IS NULL
                 OR child.board_name <> parent.board_name
                 OR NOT (child.project_id <=> parent.project_id)
             )"
        );

        if ( empty( $mismatches ) ) {
            return true;
        }

        $board_names = array_values( array_unique( array_filter( wp_list_pluck( $mismatches, 'board_name' ) ) ) );
        $board_placeholders = implode( ', ', array_fill( 0, count( $board_names ), '%s' ) );
        $task_ids = array_values(
            array_filter(
                array_map(
                    'absint',
                    $wpdb->get_col(
                        $wpdb->prepare(
                            "SELECT id FROM {$tasks_table} WHERE board_name IN ({$board_placeholders})",
                            ...$board_names
                        )
                    )
                )
            )
        );
        $task_ids_sql = implode( ',', $task_ids );
        $user_ids = empty( $task_ids )
            ? array()
            : $wpdb->get_col(
                "SELECT DISTINCT user_id
                 FROM {$assignments_table}
                 WHERE task_id IN ({$task_ids_sql})
                 UNION
                 SELECT DISTINCT user_id
                 FROM {$history_table}
                 WHERE task_id IN ({$task_ids_sql})
                 AND field_changed = 'task_created'"
            );

        $detached = $wpdb->query(
            "UPDATE {$tasks_table} child
             LEFT JOIN {$tasks_table} parent ON parent.id = child.parent_task_id
             SET child.parent_task_id = NULL,
                 child.updated_at = UTC_TIMESTAMP()
             WHERE child.parent_task_id IS NOT NULL
             AND (
                 parent.id IS NULL
                 OR child.board_name <> parent.board_name
             )"
        );

        if ( false === $detached ) {
            return false;
        }

        for ( $pass = 0; $pass < 100; $pass++ ) {
            $updated = $wpdb->query(
                "UPDATE {$tasks_table} child
                 INNER JOIN {$tasks_table} parent ON parent.id = child.parent_task_id
                 SET child.project_id = parent.project_id,
                     child.updated_at = UTC_TIMESTAMP()
                 WHERE child.board_name = parent.board_name
                 AND NOT (child.project_id <=> parent.project_id)"
            );

            if ( false === $updated ) {
                return false;
            }

            if ( 0 === $updated ) {
                break;
            }
        }

        $remaining = (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$tasks_table} child
             LEFT JOIN {$tasks_table} parent ON parent.id = child.parent_task_id
             WHERE child.parent_task_id IS NOT NULL
             AND (
                 parent.id IS NULL
                 OR child.board_name <> parent.board_name
                 OR NOT (child.project_id <=> parent.project_id)
             )"
        );

        if ( $remaining > 0 ) {
            return false;
        }

        foreach ( $task_ids as $task_id ) {
            DatabaseContext::invalidateTaskCache( $task_id );
        }

        foreach ( $board_names as $board_name ) {
            DatabaseContext::invalidateBoardCache( $board_name, array( 'tasks', 'projects', 'reports' ) );
        }

        foreach ( array_unique( array_map( 'absint', (array) $user_ids ) ) as $user_id ) {
            DatabaseContext::invalidateUserCache( $user_id );
        }

        return true;
    }
}
