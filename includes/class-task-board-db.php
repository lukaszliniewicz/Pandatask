<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Task_Board_DB {

    public static function activate() {
        self::create_tables();
    }

    public static function get_db_prefix() {
        global $wpdb;
        return $wpdb->prefix . 'tbp_'; // Task Board Plugin prefix
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
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY board_name (board_name),
            KEY status (status),
            KEY priority (priority),
            KEY deadline (deadline),
            KEY category_id (category_id)
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
            PRIMARY KEY  (id),
            UNIQUE KEY task_user (task_id, user_id),
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

    public static function get_tasks($board_name, $search = '', $sort_by = 'name', $sort_order = 'ASC', $status_filter = '') {
        global $wpdb;
        $prefix = self::get_db_prefix();
        $tasks_table = $prefix . 'tasks';
        $assignments_table = $prefix . 'assignments';
        $users_table = $wpdb->users;
        $categories_table = $prefix . 'categories';

        $sql = $wpdb->prepare(
            "SELECT t.*, c.name as category_name, GROUP_CONCAT(DISTINCT u.ID SEPARATOR ',') as assigned_user_ids, GROUP_CONCAT(DISTINCT u.display_name SEPARATOR ', ') as assigned_user_names
             FROM {$tasks_table} t
             LEFT JOIN {$categories_table} c ON t.category_id = c.id AND c.board_name = t.board_name
             LEFT JOIN {$assignments_table} a ON t.id = a.task_id
             LEFT JOIN {$users_table} u ON a.user_id = u.ID
             WHERE t.board_name = %s",
            $board_name
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
        }

        return $results;
    }

    public static function get_task($task_id) {
        global $wpdb;
        $prefix = self::get_db_prefix();
        $tasks_table = $prefix . 'tasks';
        $assignments_table = $prefix . 'assignments';
        $users_table = $wpdb->users;
        $categories_table = $prefix . 'categories';

        $sql = $wpdb->prepare(
            "SELECT t.*, c.name as category_name, GROUP_CONCAT(DISTINCT u.ID) as assigned_user_ids, GROUP_CONCAT(DISTINCT u.display_name SEPARATOR ', ') as assigned_user_names
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
             $task->category_name = $task->category_name ?? 'Uncategorized';
             $task->comments = self::get_comments($task_id);
             // Ensure description is properly handled (might need stripslashes if addslashes was used incorrectly elsewhere)
             $task->description = stripslashes($task->description ?? '');
        }

        return $task;
    }

     public static function add_task($data) {
        global $wpdb;
        $prefix = self::get_db_prefix();
        $tasks_table = $prefix . 'tasks';
        $assignments_table = $prefix . 'assignments';

        $task_data = array(
            'board_name'    => sanitize_text_field($data['board_name']),
            'name'          => sanitize_text_field($data['name']),
            'description'   => wp_kses_post($data['description']), // Allow TinyMCE content
            'status'        => sanitize_text_field($data['status']),
            'category_id'   => !empty($data['category_id']) ? absint($data['category_id']) : null,
            'priority'      => max(1, min(10, absint($data['priority']))),
            'deadline'      => !empty($data['deadline']) ? sanitize_text_field($data['deadline']) : null,
            'created_at'    => current_time('mysql', 1),
            'updated_at'    => current_time('mysql', 1),
        );

        $format = array('%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s');
        if ($task_data['category_id'] === null) {
            $format[4] = '%s'; // Pass null correctly
        }
        if ($task_data['deadline'] === null) {
            $format[6] = '%s';
        }


        $result = $wpdb->insert($tasks_table, $task_data, $format);

        if ($result === false) {
            error_log("TBP DB Error adding task: " . $wpdb->last_error);
            return false;
        }
        $task_id = $wpdb->insert_id;

        // Handle assignments
        self::update_task_assignments($task_id, $data['assigned_persons'] ?? []);

        return $task_id;
    }

    /**
     * Updates a task in the database.
     * Only updates fields that are present in the $data array.
     *
     * @param int   $task_id The ID of the task to update.
     * @param array $data    Associative array of data to update (e.g., ['status' => 'done', 'priority' => 7]).
     *                       Can also include 'assigned_persons' => [1, 2, 3] for assignment updates.
     * @return bool True on success, false on failure.
     */
    public static function update_task($task_id, $data) {
        global $wpdb;
        $prefix = self::get_db_prefix();
        $tasks_table = $prefix . 'tasks';

        // Whitelist of allowed fields in the 'tasks' table that can be updated this way
        $allowed_task_fields = [
            'name', 'description', 'status', 'category_id', 'priority', 'deadline'
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
                     // Optionally log this: error_log("TBP Invalid deadline format received for task $task_id: $value");
                     continue;
                 }
            }
        }

        // If no valid task fields were provided to update, only handle assignments if present
        if (empty($update_data)) {
            if (isset($data['assigned_persons'])) {
                self::update_task_assignments($task_id, $data['assigned_persons']);
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
            error_log("TBP DB Error updating task $task_id: " . $wpdb->last_error);
            return false;
        }

        // Handle assignments (if 'assigned_persons' was passed in the original $data)
        // This is kept separate because assignments are in a different table
        if (isset($data['assigned_persons'])) {
            self::update_task_assignments($task_id, $data['assigned_persons']);
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

    private static function update_task_assignments($task_id, $assigned_user_ids) {
        global $wpdb;
        $prefix = self::get_db_prefix();
        $assignments_table = $prefix . 'assignments';

        // Ensure input is an array of integers
        $new_assigned_ids = array_map('absint', (array) $assigned_user_ids);
        $new_assigned_ids = array_filter($new_assigned_ids); // Remove zeros/invalid

        // Get current assignments
        $current_assignments = $wpdb->get_results( $wpdb->prepare( "SELECT user_id FROM {$assignments_table} WHERE task_id = %d", $task_id ) );
        $current_assigned_ids = wp_list_pluck( $current_assignments, 'user_id' );

        // Find users to remove
        $users_to_remove = array_diff($current_assigned_ids, $new_assigned_ids);
        if (!empty($users_to_remove)) {
            $placeholders = implode(',', array_fill(0, count($users_to_remove), '%d'));
            $sql = $wpdb->prepare(
                "DELETE FROM {$assignments_table} WHERE task_id = %d AND user_id IN ($placeholders)",
                 array_merge( [$task_id], $users_to_remove )
            );
             $wpdb->query($sql);
        }

        // Find users to add
        $users_to_add = array_diff($new_assigned_ids, $current_assigned_ids);
        if (!empty($users_to_add)) {
            foreach ($users_to_add as $user_id) {
                $wpdb->insert(
                    $assignments_table,
                    array('task_id' => $task_id, 'user_id' => $user_id),
                    array('%d', '%d')
                );
            }

            // Send email notifications to newly assigned users
            if (class_exists('Task_Board_Email')) {
                Task_Board_Email::send_assignment_notification($task_id, $users_to_add);
            }
        }
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
            if (class_exists('Task_Board_Email')) {
                Task_Board_Email::send_comment_notification($task_id, $user_id, $comment_text);
            }

            return $comment;
        }
        return false;
    }

    // --- User Fetching ---

    public static function get_buddypress_users($search = '') {
        if (!function_exists('tbp_is_buddypress_active') || !tbp_is_buddypress_active()) {
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