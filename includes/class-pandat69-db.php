<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Pandat69_DB {

    public static function activate() {
        self::create_tables();
        self::update_db_check(); // Run DB check on activation too
        // Set initial DB version if not already set
        if ( false === get_option( 'pandat69_db_version' ) ) {
            update_option( 'pandat69_db_version', '1.0.8' ); // Or your current latest version
        }
    }

    public static function get_db_prefix() {
        global $wpdb;
        // Ensure the plugin prefix always ends with an underscore
        return rtrim($wpdb->prefix, '_') . '_pandat69_';
    }

    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $prefix = self::get_db_prefix();

        $table_tasks = $prefix . 'tasks';
        $table_categories = $prefix . 'categories';
        $table_assignments = $prefix . 'assignments';
        $table_comments = $prefix . 'comments';

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

        // Use backticks around table and column names for safety
        $sql_tasks = "CREATE TABLE `{$table_tasks}` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `board_name` VARCHAR(100) NOT NULL,
            `name` VARCHAR(255) NOT NULL,
            `description` LONGTEXT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
            `category_id` BIGINT(20) UNSIGNED NULL,
            `priority` TINYINT UNSIGNED NOT NULL DEFAULT 5,
            `start_date` DATE NULL,
            `deadline` DATE NULL,
            `deadline_days_after_start` INT UNSIGNED NULL,
            `notify_deadline` TINYINT(1) NOT NULL DEFAULT 0,
            `notify_days_before` INT UNSIGNED NOT NULL DEFAULT 3,
            `archived` TINYINT(1) NOT NULL DEFAULT 0,
            `parent_task_id` BIGINT(20) UNSIGNED NULL,
            `completed_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `board_name` (`board_name`),
            KEY `status` (`status`),
            KEY `priority` (`priority`),
            KEY `deadline` (`deadline`),
            KEY `start_date` (`start_date`),
            KEY `category_id` (`category_id`),
            KEY `completed_at` (`completed_at`),
            KEY `archived` (`archived`),
            KEY `parent_task_id` (`parent_task_id`)
        ) {$charset_collate};";
        dbDelta( $sql_tasks );

        $sql_categories = "CREATE TABLE `{$table_categories}` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `board_name` VARCHAR(100) NOT NULL,
            `name` VARCHAR(100) NOT NULL,
            PRIMARY KEY  (`id`),
            UNIQUE KEY `board_name_name` (`board_name`, `name`),
            KEY `board_name` (`board_name`)
        ) {$charset_collate};";
        dbDelta( $sql_categories );

        $sql_assignments = "CREATE TABLE `{$table_assignments}` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `task_id` BIGINT(20) UNSIGNED NOT NULL,
            `user_id` BIGINT(20) UNSIGNED NOT NULL,
            `role` VARCHAR(20) NOT NULL DEFAULT 'assignee',
            PRIMARY KEY  (`id`),
            UNIQUE KEY `task_user_role` (`task_id`, `user_id`, `role`),
            KEY `task_id` (`task_id`),
            KEY `user_id` (`user_id`)
        ) {$charset_collate};";
        dbDelta( $sql_assignments );

        $sql_comments = "CREATE TABLE `{$table_comments}` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `task_id` BIGINT(20) UNSIGNED NOT NULL,
            `user_id` BIGINT(20) UNSIGNED NOT NULL,
            `comment_text` TEXT NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (`id`),
            KEY `task_id` (`task_id`),
            KEY `user_id` (`user_id`)
        ) {$charset_collate};";
        dbDelta( $sql_comments );
    }

    // --- Task CRUD ---

    public static function get_tasks($board_name, $search = '', $sort_by = 'name', $sort_order = 'ASC', $status_filter = '', $date_filter = '', $start_date = '', $end_date = '', $archived = 0) {
        global $wpdb;
        $prefix = self::get_db_prefix();
        $tasks_table = $prefix . 'tasks';
        $assignments_table = $prefix . 'assignments';
        $users_table = $wpdb->users;
        $categories_table = $prefix . 'categories';

        // Base SQL parts
        $select_sql = "SELECT t.*, c.name as category_name,
             GROUP_CONCAT(DISTINCT CASE WHEN a.role = 'assignee' OR a.role IS NULL THEN u.ID END ORDER BY u.display_name) as assigned_user_ids,
             GROUP_CONCAT(DISTINCT CASE WHEN a.role = 'assignee' OR a.role IS NULL THEN u.display_name END ORDER BY u.display_name SEPARATOR ', ') as assigned_user_names,
             GROUP_CONCAT(DISTINCT CASE WHEN a.role = 'supervisor' THEN u.ID END ORDER BY u.display_name) as supervisor_user_ids,
             GROUP_CONCAT(DISTINCT CASE WHEN a.role = 'supervisor' THEN u.display_name END ORDER BY u.display_name SEPARATOR ', ') as supervisor_user_names,
             parent.name as parent_task_name";
        $from_sql = " FROM {$tasks_table} t
             LEFT JOIN {$categories_table} c ON t.category_id = c.id AND c.board_name = t.board_name
             LEFT JOIN {$assignments_table} a ON t.id = a.task_id
             LEFT JOIN {$users_table} u ON a.user_id = u.ID
             LEFT JOIN {$tasks_table} parent ON t.parent_task_id = parent.id";

        // WHERE conditions and parameters
        $where_clauses = ["t.board_name = %s", "t.archived = %d"];
        $query_params = [$board_name, $archived];

        if (!empty($search)) {
            $search_term = '%' . $wpdb->esc_like($search) . '%';
            $where_clauses[] = "(t.name LIKE %s OR t.description LIKE %s OR u.display_name LIKE %s)";
            $query_params[] = $search_term;
            $query_params[] = $search_term;
            $query_params[] = $search_term;
        }

        if (!empty($status_filter)) {
             $where_clauses[] = "t.status = %s";
             $query_params[] = $status_filter;
        }

        // Add date range filtering (ensure dates are valid YYYY-MM-DD)
        if ($date_filter === 'range' && !empty($start_date) && !empty($end_date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
             // Find tasks where the task duration (start to deadline) overlaps with the filter range [start_date, end_date]
             // Condition: (TaskStart <= FilterEnd) AND (TaskEnd >= FilterStart)
             // Handle NULL deadlines (assume they have no end date for range purposes unless required otherwise)
             // Handle NULL start_dates (assume they start infinitely early unless required otherwise)
             $where_clauses[] = "( (t.start_date IS NULL OR t.start_date <= %s) AND (t.deadline IS NULL OR t.deadline >= %s) )";
             $query_params[] = $end_date;   // TaskStart <= FilterEnd
             $query_params[] = $start_date; // TaskEnd >= FilterStart
        }

        $where_sql = " WHERE " . implode(' AND ', $where_clauses);
        $group_sql = " GROUP BY t.id";

        // Sorting logic
        $allowed_sort_columns = ['name', 'priority', 'deadline', 'status', 'assigned_user_names', 'category_name', 'start_date', 'completed_at'];
        $order_sql = " ORDER BY ";
        if (in_array($sort_by, $allowed_sort_columns)) {
            $order = (strtoupper($sort_order) === 'DESC') ? 'DESC' : 'ASC';
             // Handle sorting by aggregated/joined fields carefully
             if ($sort_by === 'assigned_user_names') {
                 // Sort by presence of assignment first, then names
                 $order_sql .= " CASE WHEN assigned_user_names IS NULL OR assigned_user_names = '' THEN 1 ELSE 0 END ASC, assigned_user_names {$order}, t.name ASC";
             } elseif ($sort_by === 'category_name') {
                 // Sort by presence of category first, then names
                 $order_sql .= " CASE WHEN c.name IS NULL THEN 1 ELSE 0 END ASC, c.name {$order}, t.name ASC";
             } else {
                // Add NULLS LAST/FIRST depending on order for date/numeric fields if needed
                if (in_array($sort_by, ['deadline', 'start_date', 'completed_at'])) {
                     $nulls_order = ($order === 'ASC' ? 'NULLS LAST' : 'NULLS FIRST'); // Adjust based on DB behavior if needed
                     $order_sql .= " t.{$sort_by} {$order} {$nulls_order}, t.name ASC";
                } else {
                    $order_sql .= " t.{$sort_by} {$order}, t.name ASC";
                }
            }
        } else {
            $order_sql .= " t.priority DESC, t.name ASC"; // Default sort: High priority first, then name
        }

        // Assemble the full query
        $final_sql = $select_sql . $from_sql . $where_sql . $group_sql . $order_sql;

        // Prepare the final query once
        $prepared_query = $wpdb->prepare($final_sql, $query_params);

        // Check if prepare failed (unlikely with correct syntax, but good practice)
         if (!$prepared_query) {
            error_log('Pandatask get_tasks DB Prepare Error: ' . $wpdb->last_error);
            return [];
         }

        $results = $wpdb->get_results($prepared_query);


        // Check for DB execution errors
        if ($wpdb->last_error) {
            // Use $prepared_query here as $wpdb->last_query might show the unprepared version sometimes
            error_log('Pandatask get_tasks DB Error: ' . $wpdb->last_error . ' | SQL: ' . $prepared_query);
            return []; // Return empty array on error
        }

        // Normalize data (e.g., split user ids)
        foreach ($results as $task) {
            $task->assigned_user_ids = !empty($task->assigned_user_ids) ? array_map('absint', explode(',', $task->assigned_user_ids)) : [];
            $task->assigned_user_names = !empty($task->assigned_user_names) ? $task->assigned_user_names : __('Unassigned', 'pandatask');
            $task->category_name = $task->category_name ?? __('Uncategorized', 'pandatask');
            $task->supervisor_user_ids = !empty($task->supervisor_user_ids) ? array_map('absint', explode(',', $task->supervisor_user_ids)) : [];
            $task->supervisor_user_names = !empty($task->supervisor_user_names) ? $task->supervisor_user_names : __('No supervisors', 'pandatask');
            $task->description = stripslashes($task->description ?? '');
        }

        return $results;
    }


    /**
     * Update task assignments for a specific role
     *
     * @param int $task_id The task ID
     * @param array $user_ids Array of user IDs
     * @param string $role Role of the users (assignee or supervisor)
     * @return bool True on success
     */
    private static function update_task_role_assignments($task_id, $user_ids, $role = 'assignee') {
        global $wpdb;
        $prefix = self::get_db_prefix();
        $assignments_table = $prefix . 'assignments';

        // Ensure input is an array of integers
        $new_user_ids = array_map('absint', (array) $user_ids);
        $new_user_ids = array_filter($new_user_ids); // Remove zeros/invalid

        // Get current assignments for this role
        $current_assignments = $wpdb->get_results( $wpdb->prepare(
            "SELECT user_id FROM {$assignments_table} WHERE task_id = %d AND role = %s",
            $task_id, $role
        ));
        $current_user_ids = wp_list_pluck( $current_assignments, 'user_id' );
        $current_user_ids = array_map('absint', $current_user_ids); // Ensure these are ints too

        // Find users to remove
        $users_to_remove = array_diff($current_user_ids, $new_user_ids);
        if (!empty($users_to_remove)) {
            // Sanitize $users_to_remove to be absolutely sure they are integers
            $users_to_remove_safe = array_map('absint', $users_to_remove);
            $placeholders = implode(',', array_fill(0, count($users_to_remove_safe), '%d'));

            // Prepare parameters for the IN clause
            $query_params = array_merge( [$task_id, $role], $users_to_remove_safe );

             // Inline prepare within query
             // Check result of query for error logging
            $delete_result = $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$assignments_table} WHERE task_id = %d AND role = %s AND user_id IN ($placeholders)",
                $query_params
            ));
            if ($delete_result === false) {
                 error_log("Pandatask DB Error deleting assignments: " . $wpdb->last_error);
            }
        }

        // Find users to add
        $users_to_add = array_diff($new_user_ids, $current_user_ids);
        if (!empty($users_to_add)) {
             $current_user_id_assigner = get_current_user_id(); // Get assigner ID once
             $insert_success = true;

            foreach ($users_to_add as $user_id) {
                $insert_result = $wpdb->insert(
                    $assignments_table,
                    array('task_id' => $task_id, 'user_id' => $user_id, 'role' => $role),
                    array('%d', '%d', '%s')
                );
                 if ($insert_result === false) {
                     error_log("Pandatask DB Error adding assignment for user $user_id to task $task_id: " . $wpdb->last_error);
                     $insert_success = false; // Mark failure but continue trying others
                 }
            }

            // Send notifications only if all inserts were successful (or adjust logic if partial success is ok)
            if ($insert_success) {
                 // Send email notifications
                if (class_exists('Pandat69_Email')) {
                    Pandat69_Email::send_assignment_notification($task_id, $users_to_add, $role);
                }

                // Add BP notifications
                if (class_exists('Pandat69_Notifications') && Pandat69_Notifications::is_bp_notifications_active()) {
                    foreach ($users_to_add as $user_id) {
                        // Skip if assigning to yourself
                        if ($user_id != $current_user_id_assigner) {
                            Pandat69_Notifications::add_assignment_notification($task_id, $user_id, $current_user_id_assigner, $role);
                        }
                    }
                }
            }
        }

        return true; // Return true generally, errors are logged
    }

    public static function update_db_check() {
        $current_version = get_option('pandat69_db_version', '1.0.0'); // Start from a base version

        // Example update path
        if (version_compare($current_version, '1.0.8', '<')) {
            global $wpdb;
            $prefix = self::get_db_prefix();
            $table_tasks = $prefix . 'tasks';

            require_once( ABSPATH . 'wp-admin/includes/upgrade.php' ); // Needed for maybe_add_column

            // Use maybe_add_column for safer additions
            maybe_add_column($table_tasks, 'notify_deadline', "ALTER TABLE {$table_tasks} ADD COLUMN notify_deadline TINYINT(1) NOT NULL DEFAULT 0");
            maybe_add_column($table_tasks, 'notify_days_before', "ALTER TABLE {$table_tasks} ADD COLUMN notify_days_before INT UNSIGNED NOT NULL DEFAULT 3");

            // Update version *after* successful changes
            update_option('pandat69_db_version', '1.0.8');
            $current_version = '1.0.8'; // Update variable for next potential check
        }

        // Add more version checks here if needed in the future
        // if (version_compare($current_version, '1.1.0', '<')) {
        //    // Make changes for 1.1.0
        //    update_option('pandat69_db_version', '1.1.0');
        //    $current_version = '1.1.0';
        // }
    }


    public static function get_task($task_id) {
        global $wpdb;
        $prefix = self::get_db_prefix();
        $tasks_table = $prefix . 'tasks';
        $assignments_table = $prefix . 'assignments';
        $users_table = $wpdb->users;
        $categories_table = $prefix . 'categories';
        $comments_table = $prefix . 'comments'; // Add comments table

        $sql = $wpdb->prepare(
            "SELECT t.*, c.name as category_name,
             GROUP_CONCAT(DISTINCT CASE WHEN a.role = 'assignee' OR a.role IS NULL THEN u.ID END ORDER BY u.display_name) as assigned_user_ids,
             GROUP_CONCAT(DISTINCT CASE WHEN a.role = 'assignee' OR a.role IS NULL THEN u.display_name END ORDER BY u.display_name SEPARATOR ', ') as assigned_user_names,
             GROUP_CONCAT(DISTINCT CASE WHEN a.role = 'supervisor' THEN u.ID END ORDER BY u.display_name) as supervisor_user_ids,
             GROUP_CONCAT(DISTINCT CASE WHEN a.role = 'supervisor' THEN u.display_name END ORDER BY u.display_name SEPARATOR ', ') as supervisor_user_names,
             (SELECT COUNT(*) FROM {$comments_table} WHERE task_id = t.id) as comment_count,
             parent.name as parent_task_name
             FROM {$tasks_table} t
             LEFT JOIN {$categories_table} c ON t.category_id = c.id AND c.board_name = t.board_name
             LEFT JOIN {$assignments_table} a ON t.id = a.task_id
             LEFT JOIN {$users_table} u ON a.user_id = u.ID
             LEFT JOIN {$tasks_table} parent ON t.parent_task_id = parent.id
             WHERE t.id = %d
             GROUP BY t.id",
            $task_id
        );
        $task = $wpdb->get_row($sql);

        if ($task) {
            $task->assigned_user_ids = !empty($task->assigned_user_ids) ? array_map('absint', explode(',', $task->assigned_user_ids)) : [];
            $task->assigned_user_names = !empty($task->assigned_user_names) ? $task->assigned_user_names : __('Unassigned', 'pandatask');
            $task->supervisor_user_ids = !empty($task->supervisor_user_ids) ? array_map('absint', explode(',', $task->supervisor_user_ids)) : [];
            $task->supervisor_user_names = !empty($task->supervisor_user_names) ? $task->supervisor_user_names : __('No supervisors', 'pandatask');
            $task->category_name = $task->category_name ?? __('Uncategorized', 'pandatask');
            $task->comments = self::get_comments($task_id); // Fetch comments separately
            $task->description = stripslashes($task->description ?? ''); // Use stripslashes
        }

        return $task;
    }


    /**
     * Adds a new task to the database.
     *
     * @param array $data Associative array of task data.
     * @return int|false The new task ID on success, false on failure.
     */
    public static function add_task($data) {
        global $wpdb;
        $prefix = self::get_db_prefix();
        $tasks_table = $prefix . 'tasks';

        $task_data = array(
            'board_name'    => sanitize_text_field($data['board_name']),
            'name'          => sanitize_text_field($data['name']),
            'description'   => wp_kses_post($data['description'] ?? ''), // Allow TinyMCE content, handle missing key
            'status'        => sanitize_text_field($data['status']),
            'category_id'   => !empty($data['category_id']) ? absint($data['category_id']) : null,
            'priority'      => max(1, min(10, absint($data['priority']))),
            'deadline_days_after_start' => !empty($data['deadline_days_after_start']) ?
                                    absint($data['deadline_days_after_start']) : null,
            'notify_deadline' => isset($data['notify_deadline']) ? absint($data['notify_deadline']) : 0,
            'notify_days_before' => isset($data['notify_days_before']) ? max(1, min(30, absint($data['notify_days_before']))) : 3,
            'parent_task_id' => !empty($data['parent_task_id']) ? absint($data['parent_task_id']) : null,
            'created_at'    => current_time('mysql', 1), // Use GMT time for DB consistency
            'updated_at'    => current_time('mysql', 1), // Use GMT time for DB consistency
        );

        // Handle start_date based on status and input
        if (!empty($data['start_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['start_date'])) {
            $task_data['start_date'] = sanitize_text_field($data['start_date']);
        } else if ($data['status'] === 'in-progress') {
            // --- CORRECTED LINE ---
            $task_data['start_date'] = current_time('Y-m-d'); // Use WP timezone for start date
        } else {
            $task_data['start_date'] = null; // Null for pending/other tasks if not provided
        }

        // Handle deadline based on type
        if (!empty($task_data['deadline_days_after_start']) && !empty($task_data['start_date'])) {
             try {
                // Calculate deadline based on days after start
                $start_date = new DateTime($task_data['start_date']); // Assumes start_date is in correct format
                $start_date->add(new DateInterval('P' . absint($task_data['deadline_days_after_start']) . 'D'));
                $task_data['deadline'] = $start_date->format('Y-m-d');
             } catch (Exception $e) {
                  error_log('Pandatask Add Task DateTime Error: ' . $e->getMessage());
                  $task_data['deadline'] = null; // Set deadline to null if calculation fails
             }
        } else if (!empty($data['deadline']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['deadline'])) {
            // Use specific date provided
            $task_data['deadline'] = sanitize_text_field($data['deadline']);
        } else {
            $task_data['deadline'] = null;
        }

        // Set completed_at if status is 'done'
        if ($data['status'] === 'done') {
            $task_data['completed_at'] = current_time('mysql', 1); // GMT
        } else {
            $task_data['completed_at'] = null;
        }

        // Dynamic formats based on NULL values
        $format = [];
        foreach($task_data as $key => $value) {
            if (is_null($value)) {
                $format[] = '%s'; // Let WordPress handle nulls with %s
            } elseif (in_array($key, ['category_id', 'priority', 'deadline_days_after_start', 'notify_deadline', 'notify_days_before', 'parent_task_id'])) {
                $format[] = '%d';
            } else {
                $format[] = '%s';
            }
        }


        $result = $wpdb->insert($tasks_table, $task_data, $format);

        if ($result === false) {
            error_log("PANDAT69 DB Error adding task: " . $wpdb->last_error);
            return false;
        }
        $task_id = $wpdb->insert_id;

        // Handle assignments for both assignees and supervisors
        self::update_task_assignments(
            $task_id,
            $data['assigned_persons'] ?? [],
            $data['supervisor_persons'] ?? []
        );

        return $task_id;
    }


    /**
     * Updates a task in the database.
     * Only updates fields that are present in the $data array.
     *
     * @param int   $task_id The ID of the task to update.
     * @param array $data    Associative array of data to update (e.g., ['status' => 'done', 'priority' => 7]).
     *                       Can also include 'assigned_persons' and 'supervisor_persons' arrays.
     * @return bool True on success, false on failure.
     */
    public static function update_task($task_id, $data) {
        global $wpdb;
        $prefix = self::get_db_prefix();
        $tasks_table = $prefix . 'tasks';

        // Get current task for comparison and ensure it exists
        $current_task = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tasks_table} WHERE id = %d", $task_id));
        if (!$current_task) {
            error_log("PANDAT69 DB Error: Attempting to update non-existent task $task_id");
            return false;
        }

        // Whitelist of allowed fields in the 'tasks' table that can be updated this way
        $allowed_task_fields = [
            'name', 'description', 'status', 'category_id', 'priority', 'deadline',
            'deadline_days_after_start', 'start_date', 'archived', 'notify_deadline',
            'notify_days_before', 'parent_task_id', 'completed_at'
        ];

        $update_data = []; // Array to hold columns to be updated in the DB
        $format = [];      // Corresponding formats for $wpdb->update

         // Preserve current start date for calculations unless explicitly changed
        $effective_start_date = $current_task->start_date;

        // Build the update array and format array based ONLY on what's in $data
        foreach ($allowed_task_fields as $key) {
            // Check if the key exists in the input data array
            if (!array_key_exists($key, $data)) {
                continue; // Skip if field not provided for update
            }

            $value = $data[$key]; // Get the value from input

            // Sanitize and format based on the field key
            // Use switch for clarity
            switch($key) {
                case 'name':
                    $update_data['name'] = sanitize_text_field($value);
                    $format[] = '%s';
                    break;
                case 'description':
                    $update_data['description'] = wp_kses_post($value);
                    $format[] = '%s';
                    break;
                case 'status':
                    $new_status = sanitize_text_field($value);
                    $update_data['status'] = $new_status;
                    $format[] = '%s';

                    // Set or clear completed_at when status changes to or from 'done'
                    if ($new_status === 'done' && $current_task->status !== 'done') {
                        // Only add completed_at if it's not already being set explicitly
                        if (!array_key_exists('completed_at', $data)) {
                            $update_data['completed_at'] = current_time('mysql', 1); // GMT
                            $format[] = '%s';
                        }
                    } elseif ($new_status !== 'done' && $current_task->status === 'done') {
                        if (!array_key_exists('completed_at', $data)) {
                             $update_data['completed_at'] = null;
                             $format[] = '%s';
                        }
                    }

                    // If changing to in-progress and no start date yet, set one
                    if ($new_status === 'in-progress' && $current_task->status === 'pending' && empty($current_task->start_date)) {
                         if (!array_key_exists('start_date', $data)) {
                            // --- CORRECTED LINE ---
                            $update_data['start_date'] = current_time('Y-m-d'); // Use WP timezone
                            $format[] = '%s';
                            $effective_start_date = $update_data['start_date']; // Update for deadline calc
                         }
                    }
                    break;
                case 'start_date':
                    if (empty($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                        $update_data['start_date'] = null;
                        $effective_start_date = null; // Update for deadline calc
                    } else {
                        $update_data['start_date'] = sanitize_text_field($value);
                        $effective_start_date = $update_data['start_date']; // Update for deadline calc
                    }
                    $format[] = '%s'; // Always string for date/null
                    break;
                 case 'deadline_days_after_start':
                    if (empty($value) || !is_numeric($value)) {
                        $update_data['deadline_days_after_start'] = null;
                        $format[] = '%s'; // Use %s for NULL
                    } else {
                        $days = absint($value);
                        $update_data['deadline_days_after_start'] = $days;
                        $format[] = '%d';
                        // Defer deadline calculation until after all fields are processed
                    }
                    break;
                 case 'deadline':
                    if (empty($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                        $update_data['deadline'] = null;
                    } else {
                        $update_data['deadline'] = sanitize_text_field($value);
                    }
                    $format[] = '%s'; // Always string for date/null
                     // If a specific deadline is set, clear the relative deadline days
                     if (!array_key_exists('deadline_days_after_start', $data)) { // Check if not already being cleared
                        $update_data['deadline_days_after_start'] = null;
                        $format[] = '%s';
                     }
                    break;
                case 'category_id':
                    $update_data['category_id'] = !empty($value) ? absint($value) : null;
                    $format[] = is_null($update_data['category_id']) ? '%s' : '%d';
                    break;
                case 'priority':
                    $update_data['priority'] = max(1, min(10, absint($value)));
                    $format[] = '%d';
                    break;
                case 'completed_at':
                     if (empty($value)) { // Check for explicit empty/null setting
                        $update_data['completed_at'] = null;
                     } else {
                        // Validate format (basic check, could be stricter)
                        $d = DateTime::createFromFormat('Y-m-d H:i:s', $value);
                        if ($d && $d->format('Y-m-d H:i:s') === $value) {
                             $update_data['completed_at'] = $value;
                        } else {
                            $update_data['completed_at'] = null; // Set null if invalid format
                        }
                     }
                     $format[] = '%s'; // Use string for datetime or null
                    break;
                case 'archived':
                    $update_data['archived'] = absint($value) ? 1 : 0;
                    $format[] = '%d';
                    break;
                case 'parent_task_id':
                    $update_data['parent_task_id'] = !empty($value) ? absint($value) : null;
                    $format[] = is_null($update_data['parent_task_id']) ? '%s' : '%d';
                    break;
                case 'notify_deadline':
                    $update_data['notify_deadline'] = absint($value) ? 1 : 0;
                    $format[] = '%d';
                    break;
                case 'notify_days_before':
                    $update_data['notify_days_before'] = max(1, min(30, absint($value)));
                    $format[] = '%d';
                    break;
            }
        }

         // Calculate deadline based on days_after_start if applicable AFTER processing other fields
         if (isset($update_data['deadline_days_after_start']) && $update_data['deadline_days_after_start'] !== null) {
            if (!empty($effective_start_date)) {
                try {
                    $start_date_obj = new DateTime($effective_start_date);
                    $start_date_obj->add(new DateInterval('P' . $update_data['deadline_days_after_start'] . 'D'));
                    $update_data['deadline'] = $start_date_obj->format('Y-m-d');
                    // Ensure format is added if deadline wasn't processed earlier
                    if (!array_key_exists('deadline', $update_data)) {
                        $format[] = '%s';
                    }
                } catch (Exception $e) {
                     error_log('Pandatask Update Task DateTime Error: ' . $e->getMessage());
                     // Only set deadline to null if it wasn't explicitly set otherwise
                     if (!array_key_exists('deadline', $update_data)) {
                        $update_data['deadline'] = null;
                        $format[] = '%s';
                    }
                }
            } else {
                 // No start date, cannot calculate relative deadline
                  if (!array_key_exists('deadline', $update_data)) {
                     $update_data['deadline'] = null;
                     $format[] = '%s';
                 }
            }
         }

        // If no valid task fields were provided to update, only handle assignments if present
        if (empty($update_data)) {
            // Handle assignments (both assignees and supervisors)
            if (isset($data['assigned_persons']) || isset($data['supervisor_persons'])) {
                self::update_task_assignments(
                    $task_id,
                    $data['assigned_persons'] ?? ($current_task->assigned_user_ids ?? []), // Pass current if not set
                    $data['supervisor_persons'] ?? ($current_task->supervisor_user_ids ?? []) // Pass current if not set
                );
            }
            return true; // No DB update needed for task table, but assignments might have changed
        }

        // Always add/update the 'updated_at' timestamp
        $update_data['updated_at'] = current_time('mysql', 1); // GMT
        $format[] = '%s';

        // Prepare the WHERE clause
        $where = array('id' => $task_id);
        $where_format = array('%d');

        // Perform the database update
        $result = $wpdb->update($tasks_table, $update_data, $where, $format, $where_format);

        // Check for DB errors
        if ($result === false) {
            error_log("PANDAT69 DB Error updating task $task_id: " . $wpdb->last_error);
            return false;
        }

        // Handle assignments (both assignees and supervisors)
        // Pass existing assignments if not provided in $data, to ensure they are checked
        self::update_task_assignments(
            $task_id,
            $data['assigned_persons'] ?? ($current_task->assigned_user_ids ?? []),
            $data['supervisor_persons'] ?? ($current_task->supervisor_user_ids ?? [])
        );


        // Return true if the update query executed without error (or if only assignments changed)
        // $result is number of rows affected or false on error. Check !== false.
        return $result !== false;
    }

    public static function delete_task($task_id) {
        global $wpdb;
        $prefix = self::get_db_prefix();
        $tasks_table = $prefix . 'tasks';
        $assignments_table = $prefix . 'assignments';
        $comments_table = $prefix . 'comments';

        $task_id = absint($task_id);
        if (empty($task_id)) {
            return false;
        }

        // Consider potential foreign key constraints if added later
        // Delete comments first
         $wpdb->delete($comments_table, array('task_id' => $task_id), array('%d'));

        // Delete assignments
        $wpdb->delete($assignments_table, array('task_id' => $task_id), array('%d'));

        // Delete task itself
        $result = $wpdb->delete($tasks_table, array('id' => $task_id), array('%d'));

        // Delete child tasks recursively? (More complex, handle carefully if needed)
        // $child_tasks = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$tasks_table} WHERE parent_task_id = %d", $task_id));
        // foreach ($child_tasks as $child_id) {
        //     self::delete_task($child_id); // Recursive call
        // }

        return $result !== false;
    }


    /**
     * Updates assignments for both roles for a task.
     * Ensures assignments are updated based on provided arrays, comparing with current state.
     *
     * @param int   $task_id             The task ID.
     * @param array $assigned_user_ids   Array of user IDs for the 'assignee' role.
     * @param array $supervisor_user_ids Array of user IDs for the 'supervisor' role.
     * @return bool True always (errors are logged within).
     */
    public static function update_task_assignments($task_id, $assigned_user_ids = [], $supervisor_user_ids = []) {
        self::update_task_role_assignments($task_id, $assigned_user_ids, 'assignee');
        self::update_task_role_assignments($task_id, $supervisor_user_ids, 'supervisor');
        return true;
    }

    // --- Category CRUD ---

     public static function get_categories($board_name) {
        global $wpdb;
        $prefix = self::get_db_prefix();
        $categories_table = $prefix . 'categories';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, name FROM {$categories_table} WHERE board_name = %s ORDER BY name ASC",
            $board_name
        ));
    }

    public static function add_category($board_name, $category_name) {
        global $wpdb;
        $prefix = self::get_db_prefix();
        $categories_table = $prefix . 'categories';

        $name = sanitize_text_field(trim($category_name)); // Trim whitespace
        if (empty($name)) return false; // Name cannot be empty

        $data = array(
            'board_name' => sanitize_text_field($board_name),
            'name'       => $name,
        );
        $format = array('%s', '%s');

        // Check for duplicates for this board (case-insensitive check might be better depending on requirements)
        $exists = $wpdb->get_var($wpdb->prepare(
             "SELECT COUNT(*) FROM {$categories_table} WHERE board_name = %s AND name = %s",
             $data['board_name'], $data['name']
        ));
        if ($exists > 0) return false; // Already exists

        $result = $wpdb->insert($categories_table, $data, $format);
         if ($result === false) {
            error_log("Pandatask DB Error adding category: " . $wpdb->last_error);
            return false;
        }
        return $wpdb->insert_id;
    }

    public static function delete_category($category_id, $board_name) {
        global $wpdb;
        $prefix = self::get_db_prefix();
        $categories_table = $prefix . 'categories';
        $tasks_table = $prefix . 'tasks';

        $category_id = absint($category_id);
        if(empty($category_id)) return false;

        // Update tasks using this category to null
        // Ensure board_name matches for safety
        $update_result = $wpdb->update(
            $tasks_table,
            array('category_id' => null),
            array('category_id' => $category_id, 'board_name' => $board_name),
            array('%s'), // Format for category_id (null)
            array('%d', '%s') // Where format
        );

        if ($update_result === false) {
             error_log("Pandatask DB Error unsetting category $category_id for tasks: " . $wpdb->last_error);
             // Decide whether to proceed with category deletion or not - safer to stop? Let's stop.
             // return false;
        }


        $result = $wpdb->delete(
            $categories_table,
             array('id' => $category_id, 'board_name' => $board_name), // Ensure board_name matches here too
             array('%d', '%s')
        );
         if ($result === false) {
             error_log("Pandatask DB Error deleting category $category_id: " . $wpdb->last_error);
             return false;
        }
        return true; // Return true only if delete was successful
    }

    // --- Comment CRUD ---

     public static function get_comments($task_id) {
        global $wpdb;
        $prefix = self::get_db_prefix();
        $comments_table = $prefix . 'comments';
        $users_table = $wpdb->users;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT c.*, u.display_name as user_name
             FROM {$comments_table} c
             LEFT JOIN {$users_table} u ON c.user_id = u.ID
             WHERE c.task_id = %d
             ORDER BY c.created_at ASC",
            absint($task_id)
        ));
    }

    public static function add_comment($task_id, $user_id, $comment_text) {
        global $wpdb;
        $prefix = self::get_db_prefix();
        $comments_table = $prefix . 'comments';

        // Basic validation
        $task_id = absint($task_id);
        $user_id = absint($user_id);
        if (empty($task_id) || empty($user_id) || empty(trim($comment_text))) {
            return false;
        }

        $data = array(
            'task_id'       => $task_id,
            'user_id'       => $user_id,
            'comment_text'  => wp_kses_post($comment_text), // Allow safe HTML
            'created_at'    => current_time('mysql', 1), // GMT
        );
        $format = array('%d', '%d', '%s', '%s');

        $result = $wpdb->insert($comments_table, $data, $format);
        if ($result) {
            // Return the newly added comment with user info
            $comment_id = $wpdb->insert_id;
            $users_table = $wpdb->users;
            $comment = $wpdb->get_row($wpdb->prepare(
                 "SELECT c.*, u.display_name as user_name
                  FROM {$comments_table} c
                  LEFT JOIN {$users_table} u ON c.user_id = u.ID
                  WHERE c.id = %d",
                  $comment_id
             ));

             if ($comment) {
                 // Prepare comment text for notifications (strip tags for plain text?)
                 // Consider if the notification functions should handle HTML/plain text conversion
                 $notification_comment_text = $comment->comment_text; // Pass potentially HTML comment

                 // Send email notifications to assigned users/supervisors (logic moved from AJAX?)
                 // This might be better handled after the AJAX response in the AJAX handler
                 // if (class_exists('Pandat69_Email')) {
                 //     Pandat69_Email::send_comment_notification($task_id, $user_id, $notification_comment_text);
                 // }

                 // Trigger BP notifications (logic moved from AJAX?)
                 // if (class_exists('Pandat69_Notifications')) {
                 //     // ... notification sending logic ...
                 // }
                 return $comment;
             }
        } else {
            error_log("Pandatask DB Error adding comment: " . $wpdb->last_error);
        }
        return false;
    }

    // --- User Fetching ---

    public static function get_buddypress_users($search = '') {
        // Ensure BuddyPress is active and bp_has_members function exists
        if (!function_exists('bp_has_members')) {
            // Fallback to standard WP users if BuddyPress is not functional
            return self::get_wp_users($search);
        }

        $args = array(
            'type' => 'alphabetical',
            'per_page' => 50, // Limit results for performance
            'search_terms' => $search ? sanitize_text_field($search) : false,
            // 'populate_extras' => false, // Optimization: Don't fetch extra data if not needed
        );

        $members_data = array(); // Initialize array to store results

        if ( bp_has_members( $args ) ) {
            global $members_template; // Access the global members loop object

            while ( bp_members() ) {
                bp_the_member(); // Sets up the current member in the loop

                 // Check if the member object exists in the template
                 if(isset($members_template->member) && !empty($members_template->member->id)) {
                    $members_data[] = array(
                        'id' => $members_template->member->id,
                        'name' => $members_template->member->display_name,
                        // Add avatar URL if needed (requires populate_extras = true or separate call)
                        // 'avatar' => bp_get_member_avatar( array( 'item_id' => $members_template->member->id, 'type' => 'thumb', 'html' => false ) )
                    );
                 }
            }
            bp_members_pagination_count(); // Recommended after loop if using pagination display elsewhere
        }

        return $members_data;

    }

    /**
     * Checks for tasks that should start today and updates their status.
     * Should be called by a daily cron job.
     *
     * @return int Number of tasks that were started
     */
    public static function check_tasks_to_start() {
        global $wpdb;
        $prefix = self::get_db_prefix();
        $tasks_table = $prefix . 'tasks';

        // --- CORRECTED LINE ---
        $today = current_time('Y-m-d'); // Use WP timezone

        // Find pending tasks with start_date = today
        $tasks_to_start = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM {$tasks_table}
            WHERE status = 'pending'
            AND start_date = %s
            AND archived = 0",
            $today
        ));

         if ($wpdb->last_error) {
            error_log("Pandatask DB Error checking tasks to start: " . $wpdb->last_error);
            return 0;
        }

        if (empty($tasks_to_start)) {
             return 0;
        }

        $started_count = 0;
        foreach($tasks_to_start as $task) {
            // Update status to in-progress
            // Call update_task which also handles deadline calculation if needed
            $updated = self::update_task($task->id, array(
                'status' => 'in-progress'
                // No need to set start_date here, update_task handles it
            ));
             if ($updated) {
                 $started_count++;
             }
        }

        return $started_count;
    }


    /**
     * Get potential parent tasks for a board
     *
     * @param string $board_name The board name
     * @param int $current_task_id Current task ID to exclude (when editing)
     * @return array List of tasks that can be parents
     */
    public static function get_potential_parent_tasks($board_name, $current_task_id = 0) {
        global $wpdb;
        $prefix = self::get_db_prefix();
        $tasks_table = $prefix . 'tasks';

        $sql = "SELECT id, name FROM {$tasks_table} WHERE board_name = %s AND archived = 0";
        $params = [$board_name];

        // If editing a task, exclude current task and its children/descendants?
        // Simple exclusion: Exclude only the current task
        if ($current_task_id > 0) {
            $sql .= " AND id != %d";
            $params[] = $current_task_id;

            // Also exclude tasks that have this task as their parent (direct children)
            // This prevents selecting an immediate child as a parent.
            // Preventing deeper circular refs (grandchild etc.) is more complex.
            $sql .= $wpdb->prepare(" AND id NOT IN (SELECT DISTINCT id FROM {$tasks_table} WHERE parent_task_id = %d)", $current_task_id);
        }

        $sql .= " ORDER BY name ASC";

        // Prepare and execute
        $prepared_query = $wpdb->prepare($sql, $params);
        if (!$prepared_query) {
            error_log("Pandatask DB Error preparing potential parent tasks query: " . $wpdb->last_error);
            return [];
        }
        $results = $wpdb->get_results($prepared_query);

        if ($wpdb->last_error) {
            error_log("Pandatask DB Error fetching potential parent tasks: " . $wpdb->last_error);
            return [];
        }
        return $results;
    }


     public static function get_wp_users($search = '') {
        $args = array(
            'orderby' => 'display_name',
            'order' => 'ASC',
            'number' => 50, // Limit results
            'fields' => array('ID', 'display_name'),
             // Ensure capability check if needed, e.g., only list users who can access the plugin
             // 'role__in' => ['editor', 'administrator', 'author'], // Example roles
        );
        if (!empty($search)) {
            // Use wildcard search across multiple fields
            $args['search'] = '*' . esc_attr( $search ) . '*';
            $args['search_columns'] = array( 'user_login', 'user_nicename', 'user_email', 'display_name' );
        }

        $users = get_users($args);
        $formatted_users = [];
        foreach ($users as $user) {
            $formatted_users[] = ['id' => $user->ID, 'name' => $user->display_name];
        }
        return $formatted_users;
    }
}