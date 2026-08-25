<?php

namespace Pandatask\Infrastructure\Setup;

use Exception;
use Throwable;
use Pandatask\Domain\Task\TaskGraph;
use Pandatask\Infrastructure\Persistence\DatabaseContext;

final class DatabaseLifecycle {

    private const DB_VERSION = '1.0.18';

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
        $table_work_occurrences    = $prefix . 'task_work_occurrences';
        $table_work_entries        = $prefix . 'work_entries';
        $table_work_allocations    = $prefix . 'work_allocations';
        $table_time_resolutions    = $prefix . 'task_time_resolutions';
        $table_work_audit_log      = $prefix . 'work_audit_log';
        $table_work_suggestion_decisions = $prefix . 'work_suggestion_decisions';
        $table_work_log_group_shares = $prefix . 'work_log_group_shares';

        $upgrade_file = wp_normalize_path( ABSPATH . 'wp-admin/includes/upgrade.php' );
        if ( ! is_file( $upgrade_file ) ) {
            return false;
        }
        require_once $upgrade_file;

        $sql_tasks = "CREATE TABLE $table_tasks (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            board_name VARCHAR(191) NOT NULL,
            name VARCHAR(255) NOT NULL,
            creator_id BIGINT(20) UNSIGNED NULL,
            estimated_effort_seconds INT UNSIGNED NULL,
            current_work_occurrence_id BIGINT(20) UNSIGNED NULL,
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
            KEY creator_id (creator_id),
            KEY current_work_occurrence_id (current_work_occurrence_id),
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
            board_name VARCHAR(191) NOT NULL,
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
            board_name VARCHAR(191) NOT NULL,
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
            board_name VARCHAR(191) NOT NULL,
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

        $sql_work_occurrences = "CREATE TABLE $table_work_occurrences (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            task_id BIGINT(20) UNSIGNED NOT NULL,
            creator_id_snapshot BIGINT(20) UNSIGNED NULL,
            sequence_number INT UNSIGNED NOT NULL DEFAULT 1,
            occurrence_key VARCHAR(191) NOT NULL,
            state VARCHAR(20) NOT NULL DEFAULT 'open',
            board_name_snapshot VARCHAR(191) NOT NULL,
            task_name_snapshot VARCHAR(255) NOT NULL,
            project_id_snapshot BIGINT(20) UNSIGNED NULL,
            project_name_snapshot VARCHAR(255) NULL,
            category_id_snapshot BIGINT(20) UNSIGNED NULL,
            category_name_snapshot VARCHAR(100) NULL,
            start_date_snapshot DATE NULL,
            deadline_snapshot DATE NULL,
            estimated_effort_seconds INT UNSIGNED NULL,
            opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME NULL,
            skipped_at DATETIME NULL,
            cancelled_at DATETIME NULL,
            tombstoned_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY task_sequence (task_id, sequence_number),
            UNIQUE KEY occurrence_key (occurrence_key),
            KEY creator_id_snapshot (creator_id_snapshot),
            KEY task_state (task_id, state),
            KEY board_state (board_name_snapshot, state),
            KEY opened_at (opened_at)
        ) $charset_collate;";
        dbDelta( $sql_work_occurrences );

        $sql_work_entries = "CREATE TABLE $table_work_entries (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            created_by BIGINT(20) UNSIGNED NOT NULL,
            title VARCHAR(255) NOT NULL,
            notes LONGTEXT NULL,
            activity_type VARCHAR(32) NULL,
            capacity VARCHAR(20) NULL,
            work_date DATE NOT NULL,
            started_at_utc DATETIME NULL,
            ended_at_utc DATETIME NULL,
            timezone VARCHAR(64) NULL,
            duration_seconds INT UNSIGNED NOT NULL,
            kind VARCHAR(20) NOT NULL DEFAULT 'manual',
            source_key VARCHAR(191) NULL,
            source_url VARCHAR(2048) NULL,
            visibility VARCHAR(20) NOT NULL DEFAULT 'private',
            deleted_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY source_key (source_key),
            KEY user_date (user_id, work_date, id),
            KEY activity_type (activity_type),
            KEY kind (kind),
            KEY deleted_at (deleted_at)
        ) $charset_collate;";
        dbDelta( $sql_work_entries );

        $sql_work_allocations = "CREATE TABLE $table_work_allocations (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            work_entry_id BIGINT(20) UNSIGNED NOT NULL,
            occurrence_id BIGINT(20) UNSIGNED NULL,
            seconds INT UNSIGNED NOT NULL,
            task_id_snapshot BIGINT(20) UNSIGNED NULL,
            task_name_snapshot VARCHAR(255) NULL,
            board_name_snapshot VARCHAR(191) NULL,
            project_id_snapshot BIGINT(20) UNSIGNED NULL,
            project_name_snapshot VARCHAR(255) NULL,
            category_id_snapshot BIGINT(20) UNSIGNED NULL,
            category_name_snapshot VARCHAR(100) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY work_entry_id (work_entry_id),
            KEY occurrence_id (occurrence_id),
            KEY task_id_snapshot (task_id_snapshot),
            KEY board_date_scope (board_name_snapshot, work_entry_id),
            KEY project_id_snapshot (project_id_snapshot),
            KEY category_id_snapshot (category_id_snapshot)
        ) $charset_collate;";
        dbDelta( $sql_work_allocations );

        $sql_time_resolutions = "CREATE TABLE $table_time_resolutions (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            occurrence_id BIGINT(20) UNSIGNED NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            revision INT UNSIGNED NOT NULL DEFAULT 1,
            state VARCHAR(20) NOT NULL DEFAULT 'unresolved',
            declared_actual_seconds INT UNSIGNED NULL,
            specific_seconds INT UNSIGNED NOT NULL DEFAULT 0,
            residual_entry_id BIGINT(20) UNSIGNED NULL,
            resolved_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY occurrence_user_revision (occurrence_id, user_id, revision),
            KEY occurrence_user (occurrence_id, user_id),
            KEY state (state),
            KEY residual_entry_id (residual_entry_id)
        ) $charset_collate;";
        dbDelta( $sql_time_resolutions );

        $sql_work_audit_log = "CREATE TABLE $table_work_audit_log (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            entity_type VARCHAR(32) NOT NULL,
            entity_id BIGINT(20) UNSIGNED NOT NULL,
            action VARCHAR(32) NOT NULL,
            actor_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            before_data LONGTEXT NULL,
            after_data LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY entity_history (entity_type, entity_id, id),
            KEY actor_id (actor_id),
            KEY created_at (created_at)
        ) $charset_collate;";
        dbDelta( $sql_work_audit_log );

        $sql_work_suggestion_decisions = "CREATE TABLE $table_work_suggestion_decisions (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            provider_key VARCHAR(64) NOT NULL,
            external_key VARCHAR(191) NOT NULL,
            decision VARCHAR(20) NOT NULL,
            work_entry_id BIGINT(20) UNSIGNED NULL,
            decided_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY user_provider_external (user_id, provider_key, external_key),
            KEY user_decision (user_id, decision, updated_at),
            KEY work_entry_id (work_entry_id)
        ) $charset_collate;";
        dbDelta( $sql_work_suggestion_decisions );

        $sql_work_log_group_shares = "CREATE TABLE $table_work_log_group_shares (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            group_id BIGINT(20) UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY user_group (user_id, group_id),
            KEY group_user (group_id, user_id),
            KEY user_id (user_id),
            KEY group_id (group_id)
        ) $charset_collate;";
        dbDelta( $sql_work_log_group_shares );

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
            $prefix . 'task_work_occurrences',
            $prefix . 'work_entries',
            $prefix . 'work_allocations',
            $prefix . 'task_time_resolutions',
            $prefix . 'work_audit_log',
            $prefix . 'work_suggestion_decisions',
            $prefix . 'work_log_group_shares',
        );

        foreach ( $required_tables as $table ) {
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
                return false;
            }
        }

        $tasks_table = $prefix . 'tasks';
        $task_columns = wp_list_pluck( $wpdb->get_results( "SHOW COLUMNS FROM {$tasks_table}" ), 'Field' );

        foreach ( array( 'deadline_reminder_sent_for', 'recurrence_anchor_day', 'creator_id', 'estimated_effort_seconds', 'current_work_occurrence_id' ) as $column ) {
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
            $prefix . 'task_work_occurrences' => array( 'task_sequence', 'occurrence_key', 'creator_id_snapshot', 'task_state', 'board_state', 'opened_at' ),
            $prefix . 'work_entries' => array( 'source_key', 'user_date', 'activity_type', 'kind', 'deleted_at' ),
            $prefix . 'work_allocations' => array( 'work_entry_id', 'occurrence_id', 'task_id_snapshot', 'board_date_scope', 'project_id_snapshot', 'category_id_snapshot' ),
            $prefix . 'task_time_resolutions' => array( 'occurrence_user_revision', 'occurrence_user', 'state', 'residual_entry_id' ),
            $prefix . 'work_audit_log' => array( 'entity_history', 'actor_id', 'created_at' ),
            $prefix . 'work_suggestion_decisions' => array( 'user_provider_external', 'user_decision', 'work_entry_id' ),
            $prefix . 'work_log_group_shares' => array( 'user_group', 'group_user', 'user_id', 'group_id' ),
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
        $history = $prefix . 'task_history';
        $occurrences = $prefix . 'task_work_occurrences';
        $time_resolutions = $prefix . 'task_time_resolutions';
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
                "UPDATE {$tasks} task
                 LEFT JOIN {$users} user_record ON user_record.ID = task.creator_id
                 SET task.creator_id = NULL
                 WHERE task.creator_id IS NOT NULL AND user_record.ID IS NULL",
                "UPDATE {$tasks} task
                 SET task.creator_id = (
                     SELECT creator_history.user_id
                     FROM {$history} creator_history
                     WHERE creator_history.task_id = task.id
                       AND creator_history.field_changed = 'task_created'
                     ORDER BY creator_history.id ASC
                     LIMIT 1
                 )
                 WHERE task.creator_id IS NULL",
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

            $missing_occurrences = $wpdb->get_results(
                "SELECT task.*,
                        project.name AS project_name_snapshot,
                        category.name AS category_name_snapshot
                 FROM {$tasks} task
                 LEFT JOIN {$projects} project ON project.id = task.project_id AND project.board_name = task.board_name
                 LEFT JOIN {$categories} category ON category.id = task.category_id AND category.board_name = task.board_name
                 LEFT JOIN {$occurrences} occurrence ON occurrence.task_id = task.id
                 WHERE occurrence.id IS NULL
                 ORDER BY task.id ASC"
            );

            foreach ( $missing_occurrences as $task ) {
                $state = 'done' === $task->status ? 'completed' : 'open';
                $created = $wpdb->insert(
                    $occurrences,
                    array(
                        'task_id'                  => (int) $task->id,
                        'creator_id_snapshot'      => $task->creator_id ? (int) $task->creator_id : null,
                        'sequence_number'          => 1,
                        'occurrence_key'           => 'task-' . (int) $task->id . '-1',
                        'state'                    => $state,
                        'board_name_snapshot'      => $task->board_name,
                        'task_name_snapshot'       => $task->name,
                        'project_id_snapshot'      => $task->project_id ?: null,
                        'project_name_snapshot'    => $task->project_name_snapshot ?: null,
                        'category_id_snapshot'     => $task->category_id ?: null,
                        'category_name_snapshot'   => $task->category_name_snapshot ?: null,
                        'start_date_snapshot'      => $task->start_date ?: null,
                        'deadline_snapshot'        => $task->deadline ?: null,
                        'estimated_effort_seconds' => $task->estimated_effort_seconds ?: null,
                        'opened_at'                => $task->created_at ?: gmdate( 'Y-m-d H:i:s' ),
                        'completed_at'             => 'completed' === $state ? ( $task->completed_at ?: $task->updated_at ) : null,
                    )
                );

                if ( false === $created ) {
                    throw new Exception( 'A PandaTask work-occurrence backfill failed.' );
                }

                $occurrence_id = (int) $wpdb->insert_id;
                if ( false === $wpdb->update( $tasks, array( 'current_work_occurrence_id' => $occurrence_id ), array( 'id' => (int) $task->id ), array( '%d' ), array( '%d' ) ) ) {
                    throw new Exception( 'A PandaTask current occurrence backfill failed.' );
                }
            }

            $current_occurrence_repair = $wpdb->query(
                "UPDATE {$tasks} task
                 LEFT JOIN {$occurrences} current_occurrence
                    ON current_occurrence.id = task.current_work_occurrence_id
                   AND current_occurrence.task_id = task.id
                 SET task.current_work_occurrence_id = (
                     SELECT latest.id
                     FROM {$occurrences} latest
                     WHERE latest.task_id = task.id
                     ORDER BY latest.sequence_number DESC, latest.id DESC
                     LIMIT 1
                 )
                 WHERE current_occurrence.id IS NULL"
            );
            if ( false === $current_occurrence_repair ) {
                throw new Exception( 'A PandaTask current work-occurrence repair failed.' );
            }

            $unresolved_backfill = $wpdb->query(
                "INSERT INTO {$time_resolutions}
                    (occurrence_id, user_id, revision, state, declared_actual_seconds, specific_seconds, residual_entry_id, resolved_by, created_at, updated_at)
                 SELECT occurrence.id, assignment.user_id, 1, 'unresolved', NULL, 0, NULL, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP()
                 FROM {$occurrences} occurrence
                 INNER JOIN {$assignments} assignment
                    ON assignment.task_id = occurrence.task_id
                   AND assignment.role = 'assignee'
                 LEFT JOIN {$time_resolutions} existing
                    ON existing.occurrence_id = occurrence.id
                   AND existing.user_id = assignment.user_id
                 WHERE occurrence.state = 'completed'
                   AND existing.id IS NULL"
            );
            if ( false === $unresolved_backfill ) {
                throw new Exception( 'A PandaTask unresolved task-time backfill failed.' );
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
                 SELECT DISTINCT creator_id
                 FROM {$tasks_table}
                 WHERE id IN ({$task_ids_sql})
                 AND creator_id IS NOT NULL"
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
