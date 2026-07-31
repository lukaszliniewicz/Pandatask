<?php
/**
 * Read-only backend integrity and query-plan audit for an active PandaTask site.
 *
 * Usage:
 * wp eval-file tests/backend-audit.php
 */

use Pandatask\Infrastructure\Persistence\DatabaseContext;

global $wpdb;

$prefix = DatabaseContext::getDbPrefix();
$tables = array(
    'tasks'               => $prefix . 'tasks',
    'projects'            => $prefix . 'projects',
    'project_assignments' => $prefix . 'project_assignments',
    'categories'          => $prefix . 'categories',
    'assignments'         => $prefix . 'assignments',
    'comments'            => $prefix . 'comments',
    'task_history'        => $prefix . 'task_history',
    'task_relationships'  => $prefix . 'task_relationships',
    'task_change_buffers' => $prefix . 'task_change_buffers',
);

$result = array(
    'database'  => array(
        'server'   => $wpdb->db_version(),
        'sql_mode' => (string) $wpdb->get_var( 'SELECT @@SESSION.sql_mode' ),
    ),
    'tables'    => array(),
    'integrity' => array(),
    'cycles'    => array(),
    'plans'     => array(),
);

foreach ( $tables as $name => $table ) {
    $status = $wpdb->get_row(
        $wpdb->prepare(
            'SELECT ENGINE, TABLE_COLLATION, TABLE_ROWS
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $table
        )
    );

    $result['tables'][ $name ] = array(
        'rows'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ),
        'engine'    => $status ? (string) $status->ENGINE : null,
        'collation' => $status ? (string) $status->TABLE_COLLATION : null,
    );
}

$tasks               = $tables['tasks'];
$projects            = $tables['projects'];
$project_assignments = $tables['project_assignments'];
$categories          = $tables['categories'];
$assignments         = $tables['assignments'];
$comments            = $tables['comments'];
$history             = $tables['task_history'];
$relationships       = $tables['task_relationships'];
$buffers             = $tables['task_change_buffers'];
$users               = $wpdb->users;

$checks = array(
    'invalid_parent_links' => "
        SELECT COUNT(*)
        FROM {$tasks} child
        LEFT JOIN {$tasks} parent ON parent.id = child.parent_task_id
        WHERE child.parent_task_id IS NOT NULL
          AND (
              parent.id IS NULL
              OR child.board_name <> parent.board_name
              OR NOT (child.project_id <=> parent.project_id)
          )",
    'invalid_category_links' => "
        SELECT COUNT(*)
        FROM {$tasks} task
        LEFT JOIN {$categories} category ON category.id = task.category_id
        WHERE task.category_id IS NOT NULL
          AND (category.id IS NULL OR category.board_name <> task.board_name)",
    'invalid_project_links' => "
        SELECT COUNT(*)
        FROM {$tasks} task
        LEFT JOIN {$projects} project ON project.id = task.project_id
        WHERE task.project_id IS NOT NULL
          AND (project.id IS NULL OR project.board_name <> task.board_name)",
    'invalid_dependency_links' => "
        SELECT COUNT(*)
        FROM {$relationships} relationship
        LEFT JOIN {$tasks} task ON task.id = relationship.task_id
        LEFT JOIN {$tasks} predecessor ON predecessor.id = relationship.predecessor_id
        WHERE task.id IS NULL
           OR predecessor.id IS NULL
           OR relationship.task_id = relationship.predecessor_id
           OR task.board_name <> predecessor.board_name",
    'orphan_task_assignments' => "
        SELECT COUNT(*)
        FROM {$assignments} assignment
        LEFT JOIN {$tasks} task ON task.id = assignment.task_id
        LEFT JOIN {$users} user_record ON user_record.ID = assignment.user_id
        WHERE task.id IS NULL OR user_record.ID IS NULL",
    'task_assignments_without_task' => "
        SELECT COUNT(*)
        FROM {$assignments} assignment
        LEFT JOIN {$tasks} task ON task.id = assignment.task_id
        WHERE task.id IS NULL",
    'task_assignments_without_user' => "
        SELECT COUNT(*)
        FROM {$assignments} assignment
        LEFT JOIN {$users} user_record ON user_record.ID = assignment.user_id
        WHERE user_record.ID IS NULL",
    'orphan_project_assignments' => "
        SELECT COUNT(*)
        FROM {$project_assignments} assignment
        LEFT JOIN {$projects} project ON project.id = assignment.project_id
        LEFT JOIN {$users} user_record ON user_record.ID = assignment.user_id
        WHERE project.id IS NULL OR user_record.ID IS NULL",
    'orphan_comments' => "
        SELECT COUNT(*)
        FROM {$comments} comment
        LEFT JOIN {$tasks} task ON task.id = comment.task_id
        WHERE task.id IS NULL",
    'orphan_history' => "
        SELECT COUNT(*)
        FROM {$history} history_entry
        LEFT JOIN {$tasks} task ON task.id = history_entry.task_id
        WHERE task.id IS NULL",
    'orphan_change_buffers' => "
        SELECT COUNT(*)
        FROM {$buffers} buffer_record
        LEFT JOIN {$tasks} task ON task.id = buffer_record.task_id
        WHERE task.id IS NULL",
    'completion_timestamp_mismatches' => "
        SELECT COUNT(*)
        FROM {$tasks}
        WHERE (status = 'done' AND completed_at IS NULL)
           OR (status <> 'done' AND completed_at IS NOT NULL)",
    'invalid_date_ranges' => "
        SELECT COUNT(*)
        FROM {$tasks}
        WHERE start_date IS NOT NULL
          AND deadline IS NOT NULL
          AND deadline < start_date",
    'invalid_recurrence_state' => "
        SELECT COUNT(*)
        FROM {$tasks}
        WHERE (
            is_recurring = 1
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
            )
        )
        OR (
            is_recurring = 0
            AND (
                recurrence_frequency IS NOT NULL
                OR recurrence_interval IS NOT NULL
                OR recurrence_days IS NOT NULL
                OR recurrence_ends_on IS NOT NULL
            )
        )",
    'recurring_missing_dates' => "
        SELECT COUNT(*)
        FROM {$tasks}
        WHERE is_recurring = 1
          AND (start_date IS NULL OR deadline IS NULL)",
    'recurring_invalid_rule' => "
        SELECT COUNT(*)
        FROM {$tasks}
        WHERE is_recurring = 1
          AND (
              recurrence_frequency NOT IN ('weekly', 'monthly', 'custom_weekly')
              OR COALESCE(recurrence_interval, 0) < 1
              OR (
                  recurrence_frequency = 'custom_weekly'
                  AND COALESCE(recurrence_days, '') NOT REGEXP '^[1-7](,[1-7])*$'
              )
              OR (recurrence_ends_on IS NOT NULL AND recurrence_ends_on < start_date)
          )",
    'nonrecurring_rule_residue' => "
        SELECT COUNT(*)
        FROM {$tasks}
        WHERE is_recurring = 0
          AND (
              recurrence_frequency IS NOT NULL
              OR recurrence_interval IS NOT NULL
              OR recurrence_days IS NOT NULL
              OR recurrence_ends_on IS NOT NULL
          )",
    'duplicate_creator_history' => "
        SELECT COUNT(*)
        FROM (
            SELECT task_id
            FROM {$history}
            WHERE field_changed = 'task_created'
            GROUP BY task_id
            HAVING COUNT(*) > 1
        ) duplicate_creators",
    'invalid_monthly_anchor' => "
        SELECT COUNT(*)
        FROM {$tasks}
        WHERE (
            is_recurring = 1
            AND recurrence_frequency = 'monthly'
            AND (recurrence_anchor_day IS NULL OR recurrence_anchor_day < 1 OR recurrence_anchor_day > 31)
        )
        OR (
            (is_recurring = 0 OR recurrence_frequency <> 'monthly')
            AND recurrence_anchor_day IS NOT NULL
        )",
    'stale_deadline_reminder_marker' => "
        SELECT COUNT(*)
        FROM {$tasks}
        WHERE deadline_reminder_sent_for IS NOT NULL
          AND (deadline IS NULL OR deadline_reminder_sent_for <> deadline)",
    'blocked_nonpending_tasks' => "
        SELECT COUNT(DISTINCT task.id)
        FROM {$tasks} task
        INNER JOIN {$relationships} relationship ON relationship.task_id = task.id
        INNER JOIN {$tasks} predecessor ON predecessor.id = relationship.predecessor_id
        WHERE task.status <> 'pending'
          AND predecessor.status <> 'done'
          AND predecessor.archived = 0",
    'invalid_task_assignment_roles' => "
        SELECT COUNT(*)
        FROM {$assignments}
        WHERE role NOT IN ('assignee', 'supervisor')",
    'invalid_project_assignment_roles' => "
        SELECT COUNT(*)
        FROM {$project_assignments}
        WHERE role NOT IN ('assignee', 'supervisor')",
    'invalid_dependency_types' => "
        SELECT COUNT(*)
        FROM {$relationships}
        WHERE type <> 'FS'",
);

foreach ( $checks as $name => $sql ) {
    $result['integrity'][ $name ] = (int) $wpdb->get_var( $sql );
}

/**
 * Return the IDs that participate in a directed cycle.
 *
 * @param array<int,array<int,int>> $edges Adjacency list.
 * @return array<int,int>
 */
$find_cycle_nodes = static function ( $edges ) {
    $state       = array();
    $stack       = array();
    $stack_index = array();
    $cycle_nodes = array();

    $visit = static function ( $node ) use ( &$visit, &$edges, &$state, &$stack, &$stack_index, &$cycle_nodes ) {
        $state[ $node ]       = 1;
        $stack_index[ $node ] = count( $stack );
        $stack[]              = $node;

        foreach ( $edges[ $node ] ?? array() as $next ) {
            if ( ! isset( $state[ $next ] ) ) {
                $visit( $next );
            } elseif ( 1 === $state[ $next ] ) {
                $cycle_start = $stack_index[ $next ];

                foreach ( array_slice( $stack, $cycle_start ) as $cycle_node ) {
                    $cycle_nodes[ $cycle_node ] = $cycle_node;
                }
            }
        }

        array_pop( $stack );
        unset( $stack_index[ $node ] );
        $state[ $node ] = 2;
    };

    foreach ( array_keys( $edges ) as $node ) {
        if ( ! isset( $state[ $node ] ) ) {
            $visit( $node );
        }
    }

    sort( $cycle_nodes );

    return array_values( $cycle_nodes );
};

$parent_edges = array();

foreach ( $wpdb->get_results( "SELECT id, parent_task_id FROM {$tasks} WHERE parent_task_id IS NOT NULL" ) as $row ) {
    $parent_edges[ (int) $row->id ][] = (int) $row->parent_task_id;
}

$dependency_edges = array();

foreach ( $wpdb->get_results( "SELECT task_id, predecessor_id FROM {$relationships}" ) as $row ) {
    $dependency_edges[ (int) $row->task_id ][] = (int) $row->predecessor_id;
}

$result['cycles']['parent_task_ids'] = $find_cycle_nodes( $parent_edges );
$result['cycles']['dependency_task_ids'] = $find_cycle_nodes( $dependency_edges );

$largest_board = $wpdb->get_row(
    "SELECT board_name, COUNT(*) AS task_count
     FROM {$tasks}
     GROUP BY board_name
     ORDER BY task_count DESC
     LIMIT 1"
);

/**
 * Reduce EXPLAIN output to stable, reviewable fields.
 *
 * @param array<int,object> $rows EXPLAIN rows.
 * @return array<int,array<string,mixed>>
 */
$summarize_plan = static function ( $rows ) {
    return array_map(
        static function ( $row ) {
            return array(
                'table' => $row->table ?? null,
                'type'  => $row->type ?? null,
                'key'   => $row->key ?? null,
                'rows'  => isset( $row->rows ) ? (int) $row->rows : null,
                'extra' => $row->Extra ?? null,
            );
        },
        (array) $rows
    );
};

if ( $largest_board ) {
    $board_name = (string) $largest_board->board_name;
    $result['largest_board'] = array(
        'name'  => $board_name,
        'tasks' => (int) $largest_board->task_count,
    );
    $result['plans']['active_board_tasks'] = $summarize_plan(
        $wpdb->get_results(
            $wpdb->prepare(
                "EXPLAIN SELECT id
                 FROM {$tasks}
                 WHERE board_name = %s
                   AND archived = 0
                   AND is_recurring = 0
                   AND status IN ('pending', 'in-progress')
                 ORDER BY deadline ASC
                 LIMIT 101",
                $board_name
            )
        )
    );
}

$today = wp_date( 'Y-m-d' );
$result['plans']['scheduled_start'] = $summarize_plan(
    $wpdb->get_results(
        $wpdb->prepare(
            "EXPLAIN SELECT id
             FROM {$tasks}
             WHERE status = 'pending'
               AND start_date <= %s
               AND archived = 0",
            $today
        )
    )
);
$result['plans']['missed_deadline'] = $summarize_plan(
    $wpdb->get_results(
        $wpdb->prepare(
            "EXPLAIN SELECT id
             FROM {$tasks}
             WHERE missed_deadline_notified = 0
               AND deadline < %s
               AND status <> 'done'
               AND archived = 0",
            $today
        )
    )
);
$result['plans']['recurring_rollover'] = $summarize_plan(
    $wpdb->get_results(
        $wpdb->prepare(
            "EXPLAIN SELECT id
             FROM {$tasks}
             WHERE is_recurring = 1
               AND deadline IS NOT NULL
               AND (status = 'done' OR deadline < %s)
               AND (recurrence_ends_on IS NULL OR recurrence_ends_on >= %s)",
            $today,
            $today
        )
    )
);
$result['plans']['approaching_deadline'] = $summarize_plan(
    $wpdb->get_results(
        $wpdb->prepare(
            "EXPLAIN SELECT id
             FROM {$tasks}
             WHERE notify_deadline = 1
               AND archived = 0
               AND status <> 'done'
               AND deadline IS NOT NULL
               AND deadline > %s
               AND deadline <= DATE_ADD(%s, INTERVAL notify_days_before DAY)
               AND (deadline_reminder_sent_for IS NULL OR deadline_reminder_sent_for <> deadline)",
            $today,
            $today
        )
    )
);

$result['indexes'] = array();

foreach ( $tables as $name => $table ) {
    $result['indexes'][ $name ] = array_values(
        array_unique(
            array_map(
                'strval',
                wp_list_pluck( $wpdb->get_results( "SHOW INDEX FROM {$table}" ), 'Key_name' )
            )
        )
    );
}

echo wp_json_encode( $result, JSON_PRETTY_PRINT ) . PHP_EOL;
