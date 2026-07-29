<?php

namespace Pandatask\Infrastructure\Setup;

use Pandatask\Infrastructure\Persistence\DatabaseContext;

final class DatabaseLifecycle {

    private const DB_VERSION = '1.0.13';

    public static function activate() {
        self::createTables();

        if ( self::repairProjectInheritance() ) {
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

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

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
            attachment_type VARCHAR(10) NULL,
            attachment_url VARCHAR(2048) NULL,
            attachment_post_id BIGINT(20) UNSIGNED NULL,
            attachment_filename VARCHAR(255) NULL,
            missed_deadline_notified TINYINT(1) NOT NULL DEFAULT 0,
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
            KEY board_created (board_name, created_at),
            KEY board_completed (board_name, status, completed_at)
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
            KEY task_created (task_id, created_at)
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
            KEY task_field (task_id, field_changed)
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
    }

    public static function updateDbCheck() {
        $current_version = get_option( 'pandat69_db_version', '1.0.0' );

        if ( ! version_compare( $current_version, self::DB_VERSION, '<' ) ) {
            return;
        }

        self::createTables();

        global $wpdb;

        $table_tasks  = DatabaseContext::getDbPrefix() . 'tasks';
        $columns      = $wpdb->get_results( "SHOW COLUMNS FROM $table_tasks" );
        $column_names = wp_list_pluck( $columns, 'Field' );

        if ( ! in_array( 'notify_deadline', $column_names, true ) ) {
            $wpdb->query( "ALTER TABLE $table_tasks ADD COLUMN notify_deadline TINYINT(1) NOT NULL DEFAULT 0" );
        }

        if ( ! in_array( 'notify_days_before', $column_names, true ) ) {
            $wpdb->query( "ALTER TABLE $table_tasks ADD COLUMN notify_days_before INT UNSIGNED NOT NULL DEFAULT 3" );
        }

        if ( self::repairProjectInheritance() ) {
            update_option( 'pandat69_db_version', self::DB_VERSION );
        }
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
