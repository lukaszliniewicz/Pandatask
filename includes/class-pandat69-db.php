<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Pandat69_DB {

    public static function activate() {
        self::create_tables();
    }

    public static function get_db_prefix() {
        global $wpdb;
        return $wpdb->prefix . 'pandat69_'; // Task Board Plugin prefix
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

        $sql_tasks = "CREATE TABLE $table_tasks (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            board_name VARCHAR(100) NOT NULL,
            name VARCHAR(255) NOT NULL,
            description LONGTEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            category_id BIGINT(20) UNSIGNED NULL,
            priority TINYINT UNSIGNED NOT NULL DEFAULT 5,
            start_date DATE NULL, /* New column for task start date */
            deadline DATE NULL,
            deadline_days_after_start INT UNSIGNED NULL, /* For dynamic deadline calculation */
            notify_deadline TINYINT(1) NOT NULL DEFAULT 0,
            notify_days_before INT UNSIGNED NOT NULL DEFAULT 3,
            archived TINYINT(1) NOT NULL DEFAULT 0, 
            parent_task_id BIGINT(20) UNSIGNED NULL,
            completed_at DATETIME NULL, /* New column for completion date */
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY board_name (board_name),
            KEY status (status),
            KEY priority (priority),
            KEY deadline (deadline),
            KEY start_date (start_date), /* New index */
            KEY category_id (category_id),
            KEY completed_at (completed_at), /* New index for completion date */
            KEY archived (archived),
            KEY parent_task_id (parent_task_id)
        ) $charset_collate;";
        dbDelta( $sql_tasks );

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
            KEY user_id (user_id)
        ) $charset_collate;";
        dbDelta( $sql_assignments );

        $sql_comments = "CREATE TABLE $table_comments (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            task_id BIGINT(20) UNSIGNED NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            comment_text TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY task_id (task_id),
            KEY user_id (user_id)
        ) $charset_collate;";
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
    
        $sql = $wpdb->prepare(
            "SELECT t.*, c.name as category_name, 
             GROUP_CONCAT(DISTINCT CASE WHEN a.role = 'assignee' OR a.role IS NULL THEN u.ID END) as assigned_user_ids, 
             GROUP_CONCAT(DISTINCT CASE WHEN a.role = 'assignee' OR a.role IS NULL THEN u.display_name END SEPARATOR ', ') as assigned_user_names,
             GROUP_CONCAT(DISTINCT CASE WHEN a.role = 'supervisor' THEN u.ID END) as supervisor_user_ids, 
             GROUP_CONCAT(DISTINCT CASE WHEN a.role = 'supervisor' THEN u.display_name END SEPARATOR ', ') as supervisor_user_names,
             parent.name as parent_task_name
             FROM {$tasks_table} t
             LEFT JOIN {$categories_table} c ON t.category_id = c.id AND c.board_name = t.board_name
             LEFT JOIN {$assignments_table} a ON t.id = a.task_id
             LEFT JOIN {$users_table} u ON a.user_id = u.ID
             LEFT JOIN {$tasks_table} parent ON t.parent_task_id = parent.id
             WHERE t.board_name = %s AND t.archived = %d",
            $board_name, $archived
        );
    
        if (!empty($search)) {
            $search_term = '%' . $wpdb->esc_like($search) . '%';
            $sql .= $wpdb->prepare(
                " AND (t.name LIKE %s OR t.description LIKE %s OR u.display_name LIKE %s)",
                $search_term, $search_term, $search_term
            );
        }
    
        if (!empty($status_filter)) {
             $sql .= $wpdb->prepare(" AND t.status = %s", $status_filter);
        }
        
        // Add date range filtering
        if ($date_filter === 'range' && !empty($start_date) && !empty($end_date)) {
            $sql .= $wpdb->prepare(
                " AND (t.deadline BETWEEN %s AND %s OR t.deadline IS NULL)",
                $start_date, $end_date
            );
        }
    
        $sql .= " GROUP BY t.id";
    
        // Sorting logic
        $allowed_sort_columns = ['name', 'priority', 'deadline', 'status', 'assigned_user_names', 'category_name'];
        if (in_array($sort_by, $allowed_sort_columns)) {
            $order = (strtoupper($sort_order) === 'DESC') ? 'DESC' : 'ASC';
             // Special case for assigned users as it's a concatenated string
             if ($sort_by === 'assigned_user_names') {
                 $sql .= " ORDER BY assigned_user_names {$order}"; // Simple string sort
             } elseif ($sort_by === 'category_name') {
                 $sql .= " ORDER BY c.name {$order}";
             } else {
                $sql .= " ORDER BY t.{$sort_by} {$order}";
            }
        } else {
            $sql .= " ORDER BY t.name ASC"; // Default sort
        }
    
        $results = $wpdb->get_results($sql);
    
        // Normalize data (e.g., split user ids)
        foreach ($results as $task) {
            $task->assigned_user_ids = !empty($task->assigned_user_ids) ? explode(',', $task->assigned_user_ids) : [];
            $task->assigned_user_names = !empty($task->assigned_user_names) ? $task->assigned_user_names : 'Unassigned';
            $task->category_name = $task->category_name ?? 'Uncategorized';
            $task->supervisor_user_ids = !empty($task->supervisor_user_ids) ? explode(',', $task->supervisor_user_ids) : [];
            $task->supervisor_user_names = !empty($task->supervisor_user_names) ? $task->supervisor_user_names : 'No supervisors';
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

        // Find users to remove
        $users_to_remove = array_diff($current_user_ids, $new_user_ids);
        if (!empty($users_to_remove)) {
            $placeholders = implode(',', array_fill(0, count($users_to_remove), '%d'));
            $query_params = array_merge( [$task_id, $role], $users_to_remove );
            
            $sql = $wpdb->prepare(
                "DELETE FROM {$assignments_table} WHERE task_id = %d AND role = %s AND user_id IN ($placeholders)",
                $query_params
            );
            $wpdb->query($sql);
        }

        // Find users to add
        $users_to_add = array_diff($new_user_ids, $current_user_ids);
        if (!empty($users_to_add)) {
            foreach ($users_to_add as $user_id) {
                $wpdb->insert(
                    $assignments_table,
                    array('task_id' => $task_id, 'user_id' => $user_id, 'role' => $role),
                    array('%d', '%d', '%s')
                );
            }

            // Send email notifications to newly assigned users
            if (class_exists('Pandat69_Email')) {
                Pandat69_Email::send_assignment_notification($task_id, $users_to_add, $role);
            }
            
            // Add BP notifications for assignment
            if (class_exists('Pandat69_Notifications')) {
                $current_user_id = get_current_user_id();
                foreach ($users_to_add as $user_id) {
                    // Skip if assigning to yourself
                    if ($user_id != $current_user_id) {
                        Pandat69_Notifications::add_assignment_notification($task_id, $user_id, $current_user_id, $role);
                    }
                }
            }
        }
        
        return true;
    }

    public static function update_db_check() {
        $current_version = get_option('pandat69_db_version', '1.0.0');
        
        if (version_compare($current_version, '1.0.8', '<')) {
            global $wpdb;
            $prefix = self::get_db_prefix();
            $table_tasks = $prefix . 'tasks';
            
            // Check if columns exist before adding
            $columns = $wpdb->get_results("SHOW COLUMNS FROM $table_tasks");
            $column_names = wp_list_pluck($columns, 'Field');
            
            if (!in_array('notify_deadline', $column_names)) {
                $wpdb->query("ALTER TABLE $table_tasks ADD COLUMN notify_deadline TINYINT(1) NOT NULL DEFAULT 0");
            }
            
            if (!in_array('notify_days_before', $column_names)) {
                $wpdb->query("ALTER TABLE $table_tasks ADD COLUMN notify_days_before INT UNSIGNED NOT NULL DEFAULT 3");
            }
            
            update_option('pandat69_db_version', '1.0.8');
        }
    }

    public static function get_task($task_id) {
        global $wpdb;
        $prefix = self::get_db_prefix();
        $tasks_table = $prefix . 'tasks';
        $assignments_table = $prefix . 'assignments';
        $users_table = $wpdb->users;
        $categories_table = $prefix . 'categories';
    
        $sql = $wpdb->prepare(
            "SELECT t.*, c.name as category_name, 
             GROUP_CONCAT(DISTINCT CASE WHEN a.role = 'assignee' OR a.role IS NULL THEN u.ID END) as assigned_user_ids, 
             GROUP_CONCAT(DISTINCT CASE WHEN a.role = 'assignee' OR a.role IS NULL THEN u.display_name END SEPARATOR ', ') as assigned_user_names,
             GROUP_CONCAT(DISTINCT CASE WHEN a.role = 'supervisor' THEN u.ID END) as supervisor_user_ids, 
             GROUP_CONCAT(DISTINCT CASE WHEN a.role = 'supervisor' THEN u.display_name END SEPARATOR ', ') as supervisor_user_names
             FROM {$tasks_table} t
             LEFT JOIN {$categories_table} c ON t.category_id = c.id AND c.board_name = t.board_name
             LEFT JOIN {$assignments_table} a ON t.id = a.task_id
             LEFT JOIN {$users_table} u ON a.user_id = u.ID
             WHERE t.id = %d
             GROUP BY t.id",
            $task_id
        );
        $task = $wpdb->get_row($sql);
    
        if ($task) {
            $task->assigned_user_ids = !empty($task->assigned_user_ids) ? explode(',', $task->assigned_user_ids) : [];
            $task->assigned_user_names = !empty($task->assigned_user_names) ? $task->assigned_user_names : 'Unassigned';
            $task->supervisor_user_ids = !empty($task->supervisor_user_ids) ? explode(',', $task->supervisor_user_ids) : [];
            $task->supervisor_user_names = !empty($task->supervisor_user_names) ? $task->supervisor_user_names : 'No supervisors';
            $task->category_name = $task->category_name ?? 'Uncategorized';
            $task->comments = self::get_comments($task_id);
            $task->description = stripslashes($task->description ?? '');
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
            'description'   => wp_kses_post($data['description']), // Allow TinyMCE content
            'status'        => sanitize_text_field($data['status']),
            'category_id'   => !empty($data['category_id']) ? absint($data['category_id']) : null,
            'priority'      => max(1, min(10, absint($data['priority']))),
            'deadline_days_after_start' => !empty($data['deadline_days_after_start']) ? 
                                    absint($data['deadline_days_after_start']) : null,
            'notify_deadline' => isset($data['notify_deadline']) ? absint($data['notify_deadline']) : 0,
            'notify_days_before' => isset($data['notify_days_before']) ? max(1, min(30, absint($data['notify_days_before']))) : 3,
            'parent_task_id' => !empty($data['parent_task_id']) ? absint($data['parent_task_id']) : null,
            'created_at'    => current_time('mysql', 1),
            'updated_at'    => current_time('mysql', 1),
        );
    
        // Handle start_date based on status and input
        if (!empty($data['start_date'])) {
            $task_data['start_date'] = sanitize_text_field($data['start_date']);
        } else if ($data['status'] === 'in-progress') {
            // Default to today for in-progress tasks
            $task_data['start_date'] = date('Y-m-d', current_time('timestamp'));
        } else {
            $task_data['start_date'] = null; // Null for pending tasks
        }
    
        // Handle deadline based on type
        if (!empty($data['deadline_days_after_start']) && !empty($task_data['start_date'])) {
            // Calculate deadline based on days after start
            $start_date = new DateTime($task_data['start_date']);
            $start_date->add(new DateInterval('P' . absint($data['deadline_days_after_start']) . 'D'));
            $task_data['deadline'] = $start_date->format('Y-m-d');
        } else if (!empty($data['deadline'])) {
            // Use specific date provided
            $task_data['deadline'] = sanitize_text_field($data['deadline']);
        } else {
            $task_data['deadline'] = null;
        }
        
        // Set completed_at if status is 'done'
        if ($data['status'] === 'done') {
            $task_data['completed_at'] = current_time('mysql', 1);
        } else {
            $task_data['completed_at'] = null;
        }
    
        $format = array('%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s');
        if ($task_data['category_id'] === null) {
            $format[4] = '%s'; // Pass null correctly
        }
        if ($task_data['start_date'] === null) {
            $format[7] = '%s'; // Format for start_date null
        }
        if ($task_data['deadline'] === null) {
            $format[8] = '%s'; // Format for deadline null
        }
        if ($task_data['deadline_days_after_start'] === null) {
            $format[6] = '%s'; // Format for deadline_days_after_start null
        }
        if ($task_data['parent_task_id'] === null) {
            $format[9] = '%s'; // Format for parent_task_id null
        }
        if ($task_data['completed_at'] === null) {
            // Adjust format index for completed_at null
            $format[14] = '%s';
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
    
        // Get current task for comparison
        $current_task = self::get_task($task_id);
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
    
        // Build the update array and format array based ONLY on what's in $data
        foreach ($data as $key => $value) {
            // Only process fields that are allowed for the tasks table
            if (!in_array($key, $allowed_task_fields)) {
                continue;
            }
    
            // Sanitize and format based on the field key
            if ($key === 'name') {
                $update_data['name'] = sanitize_text_field($value);
                $format[] = '%s';
            } elseif ($key === 'description') {
                $update_data['description'] = wp_kses_post($value);
                $format[] = '%s';
            } elseif ($key === 'status') {
                $update_data['status'] = sanitize_text_field($value);
                $format[] = '%s';
                
                // Set or clear completed_at when status changes to or from 'done'
                if ($value === 'done' && $current_task->status !== 'done') {
                    $update_data['completed_at'] = current_time('mysql', 1);
                    $format[] = '%s';
                } elseif ($value !== 'done' && $current_task->status === 'done') {
                    $update_data['completed_at'] = null;
                    $format[] = '%s';
                }
                
                // If changing to in-progress and no start date yet, set one
                if ($value === 'in-progress' && 
                    $current_task->status === 'pending' && 
                    empty($current_task->start_date)) {
                    
                    $update_data['start_date'] = date('Y-m-d', current_time('timestamp'));
                    $format[] = '%s';
                    
                    // If using days-after-start for deadline, calculate actual deadline
                    if (!empty($current_task->deadline_days_after_start)) {
                        $start_date = new DateTime($update_data['start_date']);
                        $start_date->add(new DateInterval('P' . $current_task->deadline_days_after_start . 'D'));
                        $update_data['deadline'] = $start_date->format('Y-m-d');
                        $format[] = '%s';
                    }
                }
            } elseif ($key === 'start_date') {
                if (empty($value)) {
                    $update_data['start_date'] = null;
                    $format[] = '%s';
                } else {
                    $update_data['start_date'] = sanitize_text_field($value);
                    $format[] = '%s';
                    
                    // If start date changes and using days-after-start, recalculate deadline
                    if (!empty($current_task->deadline_days_after_start) && 
                        $value !== $current_task->start_date) {
                        
                        $start_date = new DateTime($value);
                        $start_date->add(new DateInterval('P' . $current_task->deadline_days_after_start . 'D'));
                        $update_data['deadline'] = $start_date->format('Y-m-d');
                        $format[] = '%s';
                    }
                }
            } elseif ($key === 'deadline_days_after_start') {
                if (empty($value)) {
                    $update_data['deadline_days_after_start'] = null;
                    $format[] = '%s';
                } else {
                    $days = absint($value);
                    $update_data['deadline_days_after_start'] = $days;
                    $format[] = '%d';
                    
                    // If we have a start date, calculate the actual deadline
                    if (!empty($current_task->start_date)) {
                        $start_date = new DateTime($current_task->start_date);
                        $start_date->add(new DateInterval('P' . $days . 'D'));
                        $update_data['deadline'] = $start_date->format('Y-m-d');
                        $format[] = '%s';
                    }
                }
            } elseif ($key === 'category_id') {
                // Handle empty/null category selection
                $update_data['category_id'] = !empty($value) ? absint($value) : null;
                // Use %s format for NULL, %d otherwise
                $format[] = ($update_data['category_id'] === null) ? '%s' : '%d';
            } elseif ($key === 'priority') {
                $update_data['priority'] = max(1, min(10, absint($value)));
                $format[] = '%d';
            } elseif ($key === 'deadline') {
                // Handle empty/null deadline and validate format
                if (empty($value)) {
                    $update_data['deadline'] = null;
                    $format[] = '%s';
                } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) { // Basic YYYY-MM-DD check
                    $update_data['deadline'] = sanitize_text_field($value);
                    $format[] = '%s';
                } else {
                    // Invalid date format passed - skip updating this field to prevent errors
                    continue;
                }
            } elseif ($key === 'completed_at') {
                // Handle completed_at directly if passed
                if (empty($value)) {
                    $update_data['completed_at'] = null;
                    $format[] = '%s';
                } else {
                    $update_data['completed_at'] = sanitize_text_field($value);
                    $format[] = '%s';
                }
            } elseif ($key === 'archived') {
                $update_data['archived'] = absint($value) ? 1 : 0;
                $format[] = '%d';
            } elseif ($key === 'parent_task_id') {
                $update_data['parent_task_id'] = !empty($value) ? absint($value) : null;
                $format[] = ($update_data['parent_task_id'] === null) ? '%s' : '%d';
            } elseif ($key === 'notify_deadline') {
                $update_data['notify_deadline'] = absint($value) ? 1 : 0;
                $format[] = '%d';
            } elseif ($key === 'notify_days_before') {
                $update_data['notify_days_before'] = max(1, min(30, absint($value)));
                $format[] = '%d';
            }
        }
    
        // If no valid task fields were provided to update, only handle assignments if present
        if (empty($update_data)) {
            // Handle assignments (both assignees and supervisors)
            if (isset($data['assigned_persons']) || isset($data['supervisor_persons'])) {
                self::update_task_assignments(
                    $task_id, 
                    $data['assigned_persons'] ?? [], 
                    $data['supervisor_persons'] ?? []
                );
            }
            // Decide if returning true/false makes sense. Let's return true as no DB error occurred.
            return true;
        }
    
        // Always add/update the 'updated_at' timestamp
        $update_data['updated_at'] = current_time('mysql', 1);
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
        if (isset($data['assigned_persons']) || isset($data['supervisor_persons'])) {
            self::update_task_assignments(
                $task_id, 
                $data['assigned_persons'] ?? [], 
                $data['supervisor_persons'] ?? []
            );
        }
    
        // Return true if the update query executed without error
        return true;
    }
    public static function delete_task($task_id) {
        global $wpdb;
        $prefix = self::get_db_prefix();
        $tasks_table = $prefix . 'tasks';
        $assignments_table = $prefix . 'assignments';
        $comments_table = $prefix . 'comments';

        // Delete assignments first
        $wpdb->delete($assignments_table, array('task_id' => $task_id), array('%d'));

        // Delete comments
         $wpdb->delete($comments_table, array('task_id' => $task_id), array('%d'));

        // Delete task
        $result = $wpdb->delete($tasks_table, array('id' => $task_id), array('%d'));

        return $result !== false;
    }

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

        $name = sanitize_text_field($category_name);
        if (empty($name)) return false; // Name cannot be empty

        $data = array(
            'board_name' => sanitize_text_field($board_name),
            'name'       => $name,
        );
        $format = array('%s', '%s');

        // Check for duplicates for this board
        $exists = $wpdb->get_var($wpdb->prepare(
             "SELECT COUNT(*) FROM {$categories_table} WHERE board_name = %s AND name = %s",
             $data['board_name'], $data['name']
        ));
        if ($exists > 0) return false; // Already exists

        $result = $wpdb->insert($categories_table, $data, $format);
        return $result !== false ? $wpdb->insert_id : false;
    }

    public static function delete_category($category_id, $board_name) {
        global $wpdb;
        $prefix = self::get_db_prefix();
        $categories_table = $prefix . 'categories';
        $tasks_table = $prefix . 'tasks';

        // Optional: Update tasks using this category to null or a default category
        $wpdb->update(
            $tasks_table,
            array('category_id' => null),
            array('category_id' => $category_id, 'board_name' => $board_name),
            array('%s'), // Format for category_id (null)
            array('%d', '%s') // Where format
        );


        $result = $wpdb->delete(
            $categories_table,
             array('id' => $category_id, 'board_name' => $board_name),
             array('%d', '%s')
        );
        return $result !== false;
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
             JOIN {$users_table} u ON c.user_id = u.ID
             WHERE c.task_id = %d
             ORDER BY c.created_at ASC",
            $task_id
        ));
    }

    public static function add_comment($task_id, $user_id, $comment_text) {
        global $wpdb;
        $prefix = self::get_db_prefix();
        $comments_table = $prefix . 'comments';

        $data = array(
            'task_id'       => absint($task_id),
            'user_id'       => absint($user_id),
            'comment_text'  => wp_kses_post($comment_text), // Allow some HTML, adjust as needed
            'created_at'    => current_time('mysql', 1),
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
                  JOIN {$users_table} u ON c.user_id = u.ID
                  WHERE c.id = %d",
                  $comment_id
             ));

            // Send email notifications to assigned users
            if (class_exists('Pandat69_Email')) {
                Pandat69_Email::send_comment_notification($task_id, $user_id, $comment_text);
            }

            return $comment;
        }
        return false;
    }

    // --- User Fetching ---

    public static function get_buddypress_users($search = '') {
        if (!function_exists('pandat69_is_buddypress_active') || !pandat69_is_buddypress_active()) {
            // Fallback to standard WP users if BuddyPress is not active or helper function missing
            return self::get_wp_users($search);
        }

        $args = array(
            'type' => 'alphabetical', // Or 'active', 'newest', 'popular'
            'per_page' => 50, // Limit results for performance
            'search_terms' => $search ? sanitize_text_field($search) : false,
        );

        if ( bp_has_members( $args ) ) :
            $members = array();
            while ( bp_members() ) : bp_the_member();
                 global $members_template;
                 if(isset($members_template->member)) { // Add check for member object
                    $members[] = array(
                        'id' => $members_template->member->id,
                        'name' => $members_template->member->display_name,
                    );
                 }
            endwhile;
            return $members;
        else:
            return array();
        endif;

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
        
        $today = date('Y-m-d');
        
        // Find pending tasks with start_date = today
        $tasks = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$tasks_table}
            WHERE status = 'pending'
            AND start_date = %s
            AND archived = 0",
            $today
        ));
        
        foreach($tasks as $task) {
            // Update status to in-progress
            self::update_task($task->id, array(
                'status' => 'in-progress'
            ));
        }
        
        return count($tasks);
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
        
        $sql = $wpdb->prepare(
            "SELECT id, name FROM {$tasks_table} 
            WHERE board_name = %s AND archived = 0",
            $board_name
        );
        
        // If editing a task, exclude current task and its children
        if ($current_task_id > 0) {
            $sql .= $wpdb->prepare(" AND id != %d", $current_task_id);
            
            // Also exclude tasks that have this task as their parent (direct children)
            $sql .= $wpdb->prepare(" AND id NOT IN (SELECT id FROM {$tasks_table} WHERE parent_task_id = %d)", $current_task_id);
        }
        
        $sql .= " ORDER BY name ASC";
        
        return $wpdb->get_results($sql);
    }

     public static function get_wp_users($search = '') {
        $args = array(
            'orderby' => 'display_name',
            'order' => 'ASC',
            'number' => 50, // Limit results
            'fields' => array('ID', 'display_name'),
        );
        if (!empty($search)) {
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