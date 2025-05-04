# Pandatask

A WordPress plugin that adds task management boards via a shortcode, including optional integration with BuddyPress groups. Features list view, week/month calendar overviews, subtasks, user assignments & supervisors, and notifications.

![Task Board Screenshot](https://github.com/user-attachments/assets/08a516fc-2987-486f-8dab-8ff3f79c9190)

## Overview

This plugin provides a way to display and manage task lists directly within WordPress pages or posts using the `[task_board]` shortcode. You can create multiple distinct boards by specifying a unique `board_name`. It also integrates seamlessly with BuddyPress groups, adding a dedicated "Tasks" tab if enabled by the group admin.

## Features

*   **Shortcode Driven:** Add a task board anywhere using `[task_board board_name="your_unique_board_name"]`.
*   **Multiple Boards:** Create different boards for different projects using unique `board_name` values.
*   **Tabbed Interface:** Organize your view with 'All Tasks', 'Week Overview', 'Month Overview', and 'Archive' tabs.
*   **Task Management:**
    *   Create tasks with a name and rich-text description (TinyMCE).
    *   Set task status (Pending, In Progress, Done) with quick-change options.
    *   Assign task priority (1-10).
    *   Organize tasks with categories (managed per board).
    *   Set **Start Dates** (optional, automatically set when moved to 'In Progress' if empty).
    *   Set **Deadlines**: Choose a specific date or set it relative to the start date (e.g., "7 days after start").
    *   Assign tasks to one or more registered WordPress users (uses autocomplete search).
    *   Assign **Task Supervisors**.
    *   Create **Subtasks** under parent tasks for hierarchical organization.
    *   **Archive/Unarchive** completed or irrelevant tasks instead of deleting.
*   **Calendar Views:**
    *   **Week Overview:** View tasks starting or due within the selected week. Navigate week-by-week.
    *   **Month Overview:** View tasks starting or due within the selected month. Navigate month-by-month.
    *   **View Modes:** Choose between "Per Day" grouping or "Show All" relevant tasks for the period in calendar views.
    *   Optionally show tasks *starting* during the period in calendar views.
*   **Comments & Mentions:** Add comments to tasks. Supports **@mentioning** other registered users within comments.
*   **Filtering & Sorting:**
    *   Search tasks by keyword.
    *   Filter by status (Pending, In Progress, Done).
    *   Filter to see **"Only My Tasks"** (tasks assigned to the logged-in user).
    *   Sort by name, priority, deadline, start date, or status.
*   **User Assignment:** Search and select registered WordPress users to assign as **Assignees** or **Supervisors**.
*   **BuddyPress Integration (Optional):**
    *   Adds a "Tasks" tab to groups if enabled in group settings.
    *   Each group gets its own task board automatically.
    *   User assignment searches group members first.
    *   Leverages group privacy for access control.
*   **Enhanced Notifications:**
    *   **Email:** Alerts for task assignments (assignee/supervisor), new comments, and approaching deadlines.
    *   **BuddyPress Notifications (if active):** Real-time notifications for assignments, comments, @mentions, and approaching deadlines.
*   **Modal UI:** Clean modal pop-ups for adding/editing tasks and managing categories.
*   **Description Preview:** Long descriptions are truncated with a "Read more" link for a cleaner list view.

## Requirements

*   WordPress 5.0 or higher (tested up to 6.8)
*   PHP 7.4 or higher
*   jQuery (Bundled with WordPress)
*   jQuery UI (Datepicker, Autocomplete - Bundled with WordPress)
*   TinyMCE (Bundled with WordPress)
*   BuddyPress (Optional, but recommended for group-based task management and notifications)

## Installation

1.  Download the plugin ZIP file (`pandatask.zip`).
2.  Log in to your WordPress admin area.
3.  Go to `Plugins` -> `Add New`.
4.  Click `Upload Plugin` at the top.
5.  Upload the ZIP file you downloaded.
6.  Activate the plugin through the 'Plugins' menu in WordPress.

Alternatively, you can unzip the plugin and upload the `pandatask` folder to your `/wp-content/plugins/` directory via FTP, then activate it from the Plugins menu.

## Usage

1.  Create a new Page or Post (or edit an existing one).
2.  Add the following shortcode to the content area:
    `[task_board board_name="project_alpha"]`
    *   **Important:** Replace `"project_alpha"` with a unique identifier for this specific board. Use lowercase letters, numbers, and underscores only. Each board needs a different `board_name`.
3.  Publish or update the Page/Post.
4.  View the page on the front-end. You should see the task board interface with tabs for different views.

### Navigating Views

*   Use the tabs ("All Tasks", "Week Overview", "Month Overview", "Archive") to switch between different layouts.
*   In Week/Month views, use the arrow buttons to navigate between periods.
*   Use the view options within the Week/Month tabs to toggle between "Per Day" grouping and "Show All" relevant tasks.

### Permissions (Standalone Boards)

For boards created directly on pages/posts using the shortcode, the default behavior relies on the page/post visibility. Logged-in users who can view the page can generally interact with the board. Use the "Only My Tasks" toggle to filter the view.

### BuddyPress Groups

If you have BuddyPress active with the Groups component enabled:

1.  When creating or editing a group, you will see an option to "Enable task board for this group".
2.  If enabled, a "Tasks" tab will appear in the group's navigation.
3.  This tab displays a task board specific to that group (automatically using `board_name="group_123"`).
4.  User assignment within a group task board will primarily search members of that group.

### Permissions (BuddyPress Groups)

When used within BuddyPress groups, access control is primarily managed by the **group's settings**:

1.  In a **Private** or **Hidden** group, only members of that group will be able to access the "Tasks" tab and interact with the board.
2.  In a **Public** group, any site member might be able to view the tab (depending on BuddyPress settings), but interaction typically requires group membership.

The plugin leverages BuddyPress's existing group privacy and membership system. Use the "Only My Tasks" toggle to filter the view.

### Limitations

*   Standalone boards (not in groups) have basic permission handling based on page visibility. BuddyPress integration offers more robust control via group membership.
*   Relies heavily on the WordPress/BuddyPress user system for assignments and comments.
*   No granular roles *within the board itself* beyond Assignee/Supervisor for notifications/filtering. Overall access is via page/group permissions.
*   Subtask hierarchy doesn't prevent deep circular references (e.g., making a grandchild task the parent of its grandparent) through the UI currently.

## Screenshots

![Task Board Screenshot](https://github.com/user-attachments/assets/08a516fc-2987-486f-8dab-8ff3f79c9190)
*(Main task list view)*

## Changelog

### 1.0.9
*   Feature: Added Subtask functionality.
*   Feature: Added Task Supervisor role.
*   Feature: Added explicit Start Date field.
*   Feature: Added relative deadlines ("Days after start").
*   Feature: Added Task Archiving functionality.
*   Feature: Implemented Tabbed View (All, Week, Month, Archive).
*   Feature: Added Week and Month Calendar Overviews with navigation.
*   Feature: Added View Options (Per Day/Show All, Show Starting Tasks) for calendar views.
*   Feature: Added "Only My Tasks" filter toggle.
*   Feature: Added BuddyPress notifications for assignments, comments, mentions, deadlines.
*   Feature: Added Email notifications for approaching deadlines.
*   Feature: Implemented quick status change from the list view.
*   Feature: Implemented Modal UI for forms.
*   Feature: Added "Read More" for long descriptions.
*   Fix: Updated Stable Tag in readme.txt.
*   Internal: Added database columns for new features (start\_date, deadline\_days\_after\_start, archived, parent\_task\_id, completed\_at, assignment roles, notification settings).
*   Internal: Added daily schedulers for auto-starting tasks and checking deadlines.

### 1.0.7
*   Fix: Corrected text domain path declaration.
*   Fix: Removed redundant TinyMCE script enqueue, relying on wp_enqueue_editor().
*   Fix: Updated README.md to meet WordPress.org standards (Headers, Stable Tag, Tested Up To, License).
*   Fix: Corrected translation usage in email signature.
*   Minor code standard adjustments.

### 1.0.0
*   Initial release.

## Plugin Info

*   **Contributors:** lukaszliniewicz
*   **Tags:** task, project management, buddypress, todo, tasks, calendar, subtasks, archive
*   **Requires at least:** 5.0
*   **Tested up to:** 6.8
*   **Requires PHP:** 7.4
*   **Stable tag:** 1.0.9
*   **License:** GPLv2 or later
*   **License URI:** https://www.gnu.org/licenses/gpl-2.0.html
