=== Pandatask ===
Contributors: l.liniewicz
Donate link:
Tags: task, project management, buddypress, todo, tasks
Requires at least: 5.0
Tested up to: 6.8
Stable tag: 1.0.9
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds task management boards using a shortcode, optionally integrated into BuddyPress groups.

== Description ==

Pandatask provides a flexible way to display and manage task lists directly within WordPress pages or posts, or automatically within BuddyPress groups. It uses the `[task_board]` shortcode to render the board interface. Create multiple distinct boards by specifying a unique `board_name` in the shortcode.

**Features:**

*   **Shortcode Driven:** Add a task board anywhere using `[task_board board_name="your_unique_board_name"]`.
*   **Multiple Boards:** Create different boards for different projects using unique `board_name` values.
*   **Task Management:**
    *   Create tasks with a name and description (using a basic TinyMCE editor).
    *   Set task status (Pending, In Progress, Done).
    *   Assign task priority (1-10).
    *   Organize tasks with categories (managed per board).
    *   Set optional deadlines using a datepicker (specific date or days after start).
    *   Assign tasks to one or more registered WordPress users (uses autocomplete search).
    *   Define task supervisors.
    *   Create subtasks under parent tasks.
*   **Comments:** Add comments to tasks. Supports @mentioning other registered users.
*   **Filtering & Sorting:** Search tasks by keyword, filter by status, and sort by name, priority, deadline, or status.
*   **User Assignment:** Search and select registered WordPress users to assign to tasks or supervise them.
*   **BuddyPress Integration (Optional):** If BuddyPress is active and the Groups component is enabled:
    *   Adds a "Tasks" tab to groups.
    *   Each group gets its own task board automatically (using `board_name="group_X"` where X is the group ID).
    *   Group admins can enable/disable the Tasks feature in the group settings.
    *   User assignment searches BuddyPress members within the group context.
*   **Email Notifications:** Sends email notifications when users are assigned to a task, when a comment is added to a task they are involved with, or when a deadline is approaching.
*   **Multiple Views:** View tasks in a standard list, week overview, or month overview.

== Installation ==

1.  Download the plugin ZIP file (`pandatask.zip`).
2.  Log in to your WordPress admin area.
3.  Go to `Plugins` -> `Add New`.
4.  Click `Upload Plugin` at the top.
5.  Upload the ZIP file you downloaded.
6.  Activate the plugin through the 'Plugins' menu in WordPress.

Alternatively, you can unzip the plugin and upload the `pandatask` folder to your `/wp-content/plugins/` directory via FTP, then activate it from the Plugins menu.

== Frequently Asked Questions ==

= How do I use the shortcode? =

1.  Create a new Page or Post (or edit an existing one).
2.  Add the following shortcode to the content area:
    `[task_board board_name="project_alpha"]`
3.  **Important:** Replace `"project_alpha"` with a unique identifier for this specific board. Use lowercase letters, numbers, and underscores only. Each board needs a different `board_name`.
4.  Publish or update the Page/Post.
5.  View the page on the front-end to see the task board.

= How do permissions work? =

*   **Standalone Boards (using shortcode on pages/posts):** Permissions are primarily based on the page/post visibility. Logged-in users who can view the page can generally interact with the board (add tasks, comments, etc.).
*   **BuddyPress Group Boards:** If enabled for a group, access is controlled by BuddyPress group privacy and membership:
    *   **Private/Hidden Groups:** Only group members can access the "Tasks" tab and the board.
    *   **Public Groups:** Any site member might view the tab (depending on BP settings), but interaction typically requires group membership.

= Are there different roles within a task board? =

Currently, there are no specific roles defined *within* the task board itself (e.g., task manager vs. regular user) beyond Assignee and Supervisor roles for notification and filtering purposes. Access control relies on page visibility or BuddyPress group membership.


== Changelog ==

= 1.0.9 =
*   New: Completion date added to metadata after status change to "Done". 
*   UI ixes and improvements.

= 1.0.7 =
*   Fix: Corrected text domain path declaration.
*   Fix: Removed redundant TinyMCE script enqueue, relying on wp_enqueue_editor().
*   Fix: Updated README.txt to meet WordPress.org standards (Headers, Stable Tag, Tested Up To, License).
*   Fix: Corrected translation usage in email signature.
*   Minor code standard adjustments.

= 1.0.0 =
*   Initial release.