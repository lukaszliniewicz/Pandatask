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
        deadline DATE NULL,
        notify_deadline TINYINT(1) NOT NULL DEFAULT 0,
        notify_days_before INT UNSIGNED NOT NULL DEFAULT 3,
        archived TINYINT(1) NOT NULL DEFAULT 0, 
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY board_name (board_name),
        KEY status (status),
        KEY priority (priority),
        KEY deadline (deadline),
        KEY category_id (category_id),
        KEY archived (archived) 
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
             GROUP_CONCAT(DISTINCT CASE WHEN a.role = 'supervisor' THEN u.display_name END SEPARATOR ', ') as supervisor_user_names
             FROM {$tasks_table} t
             LEFT JOIN {$categories_table} c ON t.category_id = c.id AND c.board_name = t.board_name
             LEFT JOIN {$assignments_table} a ON t.id = a.task_id
             LEFT JOIN {$users_table} u ON a.user_id = u.ID
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
            'deadline'      => !empty($data['deadline']) ? sanitize_text_field($data['deadline']) : null,
            'notify_deadline' => isset($data['notify_deadline']) ? absint($data['notify_deadline']) : 0,
            'notify_days_before' => isset($data['notify_days_before']) ? max(1, min(30, absint($data['notify_days_before']))) : 3,
            'created_at'    => current_time('mysql', 1),
            'updated_at'    => current_time('mysql', 1),
        );

        $format = array('%s', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%d', '%s', '%s');
        if ($task_data['category_id'] === null) {
            $format[4] = '%s'; // Pass null correctly
        }
        if ($task_data['deadline'] === null) {
            $format[6] = '%s';
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

        // Whitelist of allowed fields in the 'tasks' table that can be updated this way
        $allowed_task_fields = [
            'name', 'description', 'status', 'category_id', 'priority', 'deadline', 'archived', 'notify_deadline', 'notify_days_before'
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
                    // Optionally log this: error_log("PANDAT69 Invalid deadline format received for task $task_id: $value");
                    continue;
                }
            } elseif ($key === 'archived') {
                $update_data['archived'] = absint($value) ? 1 : 0;
                $format[] = '%d';
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
        // Note: $result might be 0 if the data didn't actually change, but that's not an error.
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