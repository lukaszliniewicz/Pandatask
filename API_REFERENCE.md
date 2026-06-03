# Pandatask REST API Reference

**Base URL:** `/wp-json/pandatask/v1/`

## Authentication

All endpoints require the user to be authenticated via WordPress cookies (i.e., logged in). Permission checks are performed on each endpoint to ensure the user has the right to access or modify the requested resource.

---

## Agent & LLM Integration Guide

If you are an AI agent or building an automation script, follow these best practices:

1.  **Use Batching:** Always prefer the `POST /batch` endpoint for modifying data. It reduces network overhead and allows you to perform sequences of operations (e.g., creating a category and then assigning a new task to it).
2.  **Context First:** Before performing updates, fetch the relevant context (Users, Categories, Projects) to ensure you use valid IDs.
    -   Users: `GET /users`
    -   Projects: `GET /boards/{board_name}/projects`
    -   Categories: `GET /boards/{board_name}/categories`
3.  **Search:** Use the `search` parameter on `GET /boards/{board_name}/tasks` to find specific tasks before updating them.
4.  **Dates:** Always use `YYYY-MM-DD` format for dates.
5.  **IDs:** All references to other objects (Users, Projects, Categories) must be their integer IDs, not names.

---

## General

### 1. Get Boards

-   **Endpoint:** `GET /boards`
-   **Description:** Retrieves a list of all available task boards. Use this to discover valid `board_name` values.
-   **Query Parameters:**
    -   `search` (string, optional): Filter boards by name.
-   **Response Example:**
    ```json
    [
        { "id": "project_alpha", "name": "Project Alpha" },
        { "id": "group_4", "name": "Group: Marketing" }
    ]
    ```

### 2. Get Users

-   **Endpoint:** `GET /users`
-   **Description:** Retrieves a list of users. Important for obtaining `user_id` integers for assignments.
-   **Query Parameters:**
    -   `search` (string, optional): Filter by name/email.
    -   `board_name` (string, optional): Contextual search. If a group board (e.g., `group_123`), returns only group members.
-   **Response Example:**
    ```json
    {
        "users": [
            { "id": 1, "name": "Admin User" },
            { "id": 45, "name": "John Doe" }
        ]
    }
    ```

### 3. Get User's Writable Boards

-   **Endpoint:** `GET /users/me/boards`
-   **Description:** Returns a list of boards the current user has write access to (including their private user board and any BuddyPress group boards with tasks enabled).
-   **Response Example:**
    ```json
    {
        "boards": [
            { "id": "user_1", "name": "My Private Tasks" },
            { "id": "group_10", "name": "Group: Developers" }
        ]
    }
    ```

### 4. Generate AI Prompt

-   **Endpoint:** `POST /ai/generate-prompt`
-   **Description:** Generates a structured LLM prompt containing board context (users, projects, categories, API schema) combined with a user-supplied instruction. The output is designed to be pasted into an LLM; the LLM's JSON response can then be executed via `POST /batch`.
-   **Permissions:** Requires `manage_options` capability (administrator).
-   **Body Parameters:**
    -   `board_name` (string, required): The board identifier to gather context for.
    -   `user_prompt` (string, required): The natural-language instruction for the LLM.
-   **Example Request:**
    ```json
    {
        "board_name": "project_alpha",
        "user_prompt": "Create three high-priority backend tasks assigned to John"
    }
    ```
-   **Example Response (200 OK):**
    ```json
    {
        "prompt": "You are a helpful assistant for the Pandatask WordPress plugin..."
    }
    ```

---

## Batch Processing

### 1. Execute Multiple Actions

-   **Endpoint:** `POST /batch`
-   **Description:** Executes a series of actions in a single API call. This is the preferred method for agentic workflows.
-   **Body Parameters:**
    -   `actions` (array, required): An array of action objects.
        -   **Supported Actions:**
            -   `create_task`, `update_task`, `delete_task`
            -   `create_project`, `update_project`, `delete_project`
            -   `create_category`, `delete_category`
            -   `create_comment`, `update_comment`, `delete_comment`
    -   **Action Object Structure:**
        -   `action` (string): The action name.
        -   `board_name` (string): Required for `create_*` actions and `delete_category`.
        -   `data` (object): The payload. Matches the body parameters of the corresponding single endpoint.
            -   For `update_*` and `delete_*` actions, `id` must be included in `data`.
-   **Permissions:** User must be logged in. Individual sub-actions inherit the permission check of the corresponding endpoint.

-   **Example Request Body:**
    ```json
    {
        "actions": [
            {
                "action": "create_category",
                "board_name": "project_alpha",
                "data": { "name": "Backend" }
            },
            {
                "action": "create_task",
                "board_name": "project_alpha",
                "data": {
                    "name": "Setup Database",
                    "status": "pending",
                    "priority": 8,
                    "description": "Use MySQL 8.0"
                }
            },
            {
                "action": "update_task",
                "data": {
                    "id": 123,
                    "status": "done"
                }
            }
        ]
    }
    ```

-   **Example Response (200 OK):**
    ```json
    {
        "results": [
            {
                "success": true,
                "action_description": "create_category (Backend)",
                "message": "Success. ID: 5"
            },
            {
                "success": true,
                "action_description": "create_task (Setup Database)",
                "message": "Success. ID: 125"
            },
            {
                "success": true,
                "action_description": "update_task (123)",
                "message": "Task 123 updated successfully."
            }
        ]
    }
    ```

---

## Tasks

### Data Schema (Task Object)

Fields available when creating or updating tasks.

| Field | Type | Description |
| :--- | :--- | :--- |
| `id` | Integer | Unique ID (Read-only, required for updates) |
| `name` | String | Task title (Required for creation) |
| `description` | String | HTML or Text description |
| `status` | String | `pending`, `in-progress`, `done` |
| `priority` | Integer | 1 (Low) to 10 (High). Default: 5 |
| `deadline` | String | `YYYY-MM-DD` |
| `start_date` | String | `YYYY-MM-DD` |
| `deadline_days_after_start` | Integer | Relative deadline: days after start date |
| `assigned_persons` | Array[Int] | IDs of assigned users |
| `supervisor_persons` | Array[Int] | IDs of supervisor users |
| `predecessors` | Array[Int] | IDs of tasks this task depends on |
| `category_id` | Integer | ID of category |
| `project_id` | Integer | ID of project |
| `parent_task_id` | Integer | ID of parent task (for subtask hierarchy) |
| `is_recurring` | Boolean | `true` if this is a recurring template |
| `recurrence_frequency`| String | `weekly`, `monthly`, `custom_weekly` |
| `recurrence_interval` | Integer | e.g., `1` for every week, `2` for bi-weekly |
| `recurrence_days` | String | Comma-separated day numbers (e.g. `"1,3,5"` for Mon,Wed,Fri) |
| `recurrence_ends_on` | String | `YYYY-MM-DD` — recurrence stops after this date |
| `attachment_type` | String | `file` or `link` |
| `attachment_url` | String | URL of the attachment |
| `attachment_post_id` | Integer | WordPress media library attachment post ID |
| `attachment_filename` | String | Display filename for the attachment |
| `task_type` | String | Custom type label (e.g. `"bug"`) |
| `bug_url` | String | URL associated with a bug report |

### 1. Get Tasks for a Board

-   **Endpoint:** `GET /boards/{board_name}/tasks`
-   **Description:** Retrieves a list of tasks for a specified board. Supports extensive filtering and sorting.
-   **URL Parameters:**
    -   `board_name` (string, required): The unique identifier for the board.
-   **Query Parameters:**
    -   `search` (string, optional): Search tasks by name, description, or assignee.
    -   `status_filter` (string, optional): `pending`, `in-progress`, `done`, `missed_deadline`, or `pending_in-progress` (default).
    -   `sort` (string, optional): Sort field and direction separated by an underscore, e.g. `deadline_asc` (default), `priority_desc`, `created_at_asc`, `name_asc`.
    -   `project_filter` (integer, optional): Only tasks belonging to the given project ID.
    -   `archived` (integer, optional): Set to `1` to return archived (soft-deleted) tasks instead of active ones. Default: `0`.
    -   `private_only` (string, optional): `"true"` to return only tasks assigned to the current user.
    -   `assigned_to_me` (string, optional): `"true"` to filter by tasks assigned to the current user.
    -   `include_templates` (string, optional): `"true"` to include recurring template tasks. Front end always sends `"true"`.
    -   `task_type_filter` (string, optional): Filter by `task_type` value (e.g. `"bug"`).
-   **Permissions:** User must have read access to the board. Alternatively, if the user is assigned to, supervises, or created any task, they can see that task even without board-level access.
-   **Example Response (200 OK):**
    ```json
    {
        "tasks": [
            {
                "id": 1,
                "board_name": "project_alpha",
                "name": "Design the new logo",
                "description": "<p>Use the brand guidelines.</p>",
                "description_rendered": "<p>Use the brand guidelines.</p>",
                "status": "in-progress",
                "priority": 7,
                "deadline": "2025-08-15",
                "start_date": "2025-07-01",
                "assigned_user_ids": ["1", "5"],
                "supervisor_user_ids": ["2"],
                "category_id": 3,
                "project_id": 1,
                "parent_task_id": null,
                "predecessor_ids": [101, 102],
                "is_recurring": false,
                "created_at": "2025-07-01 09:00:00",
                "creator_id": 1
            }
        ]
    }
    ```

### 2. Create a Task

-   **Endpoint:** `POST /boards/{board_name}/tasks`
-   **Description:** Creates a new task.
-   **URL Parameters:**
    -   `board_name` (string, required): The board identifier.
-   **Query Parameters:**
    -   `response_format` (string, optional): Set to `minimal` to receive a lightweight response with only the ID and message.
-   **Body Parameters:** See **Data Schema (Task Object)** above. Only `name` is required.
-   **Permissions:** User must have write access to the board.
-   **Example Request:**
    ```json
    {
        "name": "Write API documentation",
        "status": "in-progress",
        "priority": 6,
        "assigned_persons": [1, 5],
        "deadline": "2025-12-31",
        "predecessors": [101, 102]
    }
    ```
-   **Example Response (201 Created):**
    ```json
    {
        "message": "Task added",
        "task": {
            "id": 124,
            "board_name": "project_alpha",
            "name": "Write API documentation",
            "status": "in-progress",
            "priority": 6,
            "deadline": "2025-12-31",
            "assigned_user_ids": ["1", "5"],
            "predecessor_ids": [101, 102]
        }
    }
    ```
-   **Example Response — minimal format (201 Created):**
    ```json
    {
        "message": "Task added",
        "id": 124
    }
    ```

### 3. Get a Single Task

-   **Endpoint:** `GET /tasks/{id}`
-   **Description:** Retrieves details for a single task.
-   **URL Parameters:**
    -   `id` (integer, required): The ID of the task.
-   **Permissions:** User must have read access to the board the task belongs to, be assigned to the task, supervise it, or be its creator.
-   **Response Example:**
    ```json
    {
        "task": {
            "id": 1,
            "board_name": "project_alpha",
            "name": "Design the new logo",
            "status": "in-progress",
            "priority": 7,
            "deadline": "2025-08-15",
            "assigned_user_ids": ["1", "5"],
            "description_rendered": "<p>Use the brand guidelines.</p>"
        }
    }
    ```

### 4. Update a Task

-   **Endpoint:** `PUT /tasks/{id}` or `PATCH /tasks/{id}`
-   **Description:** Updates an existing task. Send only the fields you want to change.
-   **URL Parameters:**
    -   `id` (integer, required): The ID of the task.
-   **Body Parameters:** See **Data Schema (Task Object)** above.
-   **Permissions:** User must have read access to the board the task belongs to, be assigned to the task, supervise it, or be its creator.
-   **Example Request:**
    ```json
    {
        "status": "done",
        "change_comment": "Finished the documentation draft."
    }
    ```
-   **Example Response (200 OK):**
    ```json
    {
        "message": "Task updated",
        "task": {
            "id": 124,
            "status": "done",
            "name": "Write API documentation"
        }
    }
    ```

### 5. Delete a Task

-   **Endpoint:** `DELETE /tasks/{id}`
-   **Description:** Deletes a task. For recurring tasks, supports partial deletion scopes.
-   **URL Parameters:**
    -   `id` (integer, required): The ID of the task to delete.
-   **Query Parameters:**
    -   `delete_scope` (string, optional): Controls deletion for recurring task instances. `"all"` — delete the template and all instances; `"this"` — delete only this instance; `"following"` — delete this and future instances. Default behaviour (when omitted) deletes a single non-recurring task.
-   **Permissions:** User must have read access to the board the task belongs to, be assigned to the task, supervise it, or be its creator.
-   **Example Response (200 OK):**
    ```json
    { "message": "Task deleted" }
    ```

### 6. Get Task History

-   **Endpoint:** `GET /tasks/{id}/history`
-   **Description:** Retrieves the audit log of changes for a specific task.
-   **URL Parameters:**
    -   `id` (integer, required): The ID of the task.
-   **Response Example:**
    ```json
    {
        "history": [
            {
                "id": 50,
                "field_changed": "status",
                "old_value": "pending",
                "new_value": "done",
                "changed_at": "2025-10-27 10:00:00",
                "user_name": "Admin",
                "user_id": 1
            },
            {
                "id": 49,
                "field_changed": "assigned_persons",
                "old_value": "1",
                "new_value": "1,5",
                "changed_at": "2025-10-27 09:30:00",
                "user_name": "Admin",
                "user_id": 1
            }
        ]
    }
    ```

### 7. Get Potential Parent Tasks

-   **Endpoint:** `GET /boards/{board_name}/potential-parents`
-   **Description:** Retrieves a list of tasks that can be set as a parent of another task. Excludes the task being edited and all of its descendants to prevent cycles.
-   **URL Parameters:**
    -   `board_name` (string, required): The board identifier.
-   **Query Parameters:**
    -   `current_task_id` (integer, optional): The ID of the task being edited. Omit when creating a new task.
-   **Permissions:** User must have read access to the board.
-   **Response Example:**
    ```json
    {
        "parent_tasks": [
            { "id": 1, "name": "Design the new logo" },
            { "id": 2, "name": "Setup CI pipeline" }
        ]
    }
    ```

---

## Projects

### 1. Get Projects for a Board

-   **Endpoint:** `GET /boards/{board_name}/projects`
-   **Description:** Retrieves all projects associated with a board.
-   **Permissions:** User must have read access to the board.
-   **Response Example:**
    ```json
    {
        "projects": [
            { "id": 1, "board_name": "project_alpha", "name": "Release 2.0", "description": "", "deadline": null, "assigned_user_ids": [], "supervisor_user_ids": [] },
            { "id": 2, "board_name": "project_alpha", "name": "Internal Tools", "description": "Tooling for the team", "deadline": "2025-12-01", "assigned_user_ids": ["5"], "supervisor_user_ids": ["1"] }
        ]
    }
    ```

### 2. Create a Project

-   **Endpoint:** `POST /boards/{board_name}/projects`
-   **Description:** Creates a new project on a board.
-   **Query Parameters:**
    -   `response_format` (string, optional): Set to `minimal` to receive only the ID and message.
-   **Body Parameters:**
    -   `name` (string, required): The name of the project.
    -   `description` (string, optional): Project description.
    -   `deadline` (string, optional): Deadline in `YYYY-MM-DD` format.
    -   `assigned_persons` (array of integers, optional): User IDs assigned to the project.
    -   `supervisor_persons` (array of integers, optional): User IDs supervising the project.
-   **Permissions:** User must have write access to the board.
-   **Example Response (201 Created):**
    ```json
    {
        "project": {
            "id": 3,
            "board_name": "project_alpha",
            "name": "Backend Rewrite",
            "description": "",
            "deadline": null,
            "assigned_user_ids": [],
            "supervisor_user_ids": []
        }
    }
    ```

### 3. Get a Single Project

-   **Endpoint:** `GET /projects/{id}`
-   **Description:** Retrieves details for a single project.
-   **Permissions:** User must have read access to the board the project belongs to.
-   **Response Example:**
    ```json
    {
        "project": {
            "id": 1,
            "board_name": "project_alpha",
            "name": "Release 2.0",
            "description": "",
            "deadline": null,
            "assigned_user_ids": [],
            "supervisor_user_ids": []
        }
    }
    ```

### 4. Update a Project

-   **Endpoint:** `PUT /projects/{id}` or `PATCH /projects/{id}`
-   **Description:** Updates an existing project.
-   **Body Parameters:** Same fields as Create a Project.
-   **Permissions:** User must have write access to the board the project belongs to.

### 5. Delete a Project

-   **Endpoint:** `DELETE /projects/{id}`
-   **Description:** Deletes a project. Tasks within this project will be unassigned from it (their `project_id` is set to null).
-   **Permissions:** User must have write access to the board the project belongs to.
-   **Response Example (200 OK):**
    ```json
    { "message": "Deleted" }
    ```

---

## Categories

### 1. Get Categories for a Board

-   **Endpoint:** `GET /boards/{board_name}/categories`
-   **Description:** Retrieves all categories for a specified board.
-   **Permissions:** User must have read access to the board.
-   **Response Example:**
    ```json
    {
        "categories": [
            { "id": 1, "name": "Frontend" },
            { "id": 2, "name": "Backend" },
            { "id": 3, "name": "DevOps" }
        ]
    }
    ```

### 2. Create a Category

-   **Endpoint:** `POST /boards/{board_name}/categories`
-   **Description:** Creates a new category on a board.
-   **Query Parameters:**
    -   `response_format` (string, optional): Set to `minimal` to receive only the ID and message.
-   **Body Parameters:**
    -   `name` (string, required): The name of the category. Must be unique within the board.
-   **Permissions:** User must have write access to the board.
-   **Example Response (201 Created):**
    ```json
    {
        "category": { "id": 4, "name": "Documentation" }
    }
    ```

### 3. Delete a Category

-   **Endpoint:** `DELETE /categories/{id}`
-   **Description:** Deletes a category.
-   **URL Parameters:**
    -   `id` (integer, required): The ID of the category to delete.
-   **Body Parameters:**
    -   `board_name` (string, required): The board name is required for permission checking.
-   **Permissions:** User must have write access to the board.
-   **Response Example (200 OK):**
    ```json
    { "message": "Deleted" }
    ```

---

## Reports

### 1. Get Board Report

-   **Endpoint:** `GET /boards/{board_name}/report`
-   **Description:** Retrieves statistical data for the board (tasks added, completed, missed deadlines, user workload) for a given period.
-   **URL Parameters:**
    -   `board_name` (string, required): The board identifier.
-   **Query Parameters:**
    -   `period` (string, optional): `this_week` (default), `last_week`, `last_7_days`, `this_month`, `last_month`, `last_30_days`, `custom`.
    -   `start_date` (string, optional): Required if period is `custom`. `YYYY-MM-DD`.
    -   `end_date` (string, optional): Required if period is `custom`. `YYYY-MM-DD`.
-   **Permissions:** User must have read access to the board.
-   **Example Response (200 OK):**
    ```json
    {
        "period": { "start": "2025-10-21", "end": "2025-10-27" },
        "summary": {
            "tasks_added": 5,
            "tasks_completed": 3,
            "missed_deadlines": 1
        },
        "user_workload": [
            { "user_id": 1, "user_name": "Admin", "added": 2, "completed": 1 },
            { "user_id": 5, "user_name": "John Doe", "added": 3, "completed": 2 }
        ]
    }
    ```

---

## Comments

### 1. Get Comments for a Task

-   **Endpoint:** `GET /tasks/{task_id}/comments`
-   **Description:** Retrieves all comments for a specific task.
-   **URL Parameters:**
    -   `task_id` (integer, required): The ID of the task.
-   **Permissions:** User must have read access to the task.
-   **Response Example:**
    ```json
    [
        {
            "id": 10,
            "task_id": 124,
            "user_id": 1,
            "comment_text": "Started working on this.",
            "created_at": "2025-10-27 10:00:00",
            "updated_at": "2025-10-27 10:00:00",
            "user_name": "Admin",
            "user_avatar_url": "https://example.com/avatar.jpg",
            "can_manage": true,
            "created_at_formatted": "2 hours ago",
            "created_at_tooltip": "October 27, 2025 10:00 am",
            "is_edited": false
        }
    ]
    ```

### 2. Create a Comment

-   **Endpoint:** `POST /tasks/{task_id}/comments`
-   **Description:** Adds a new comment to a task.
-   **URL Parameters:**
    -   `task_id` (integer, required): The ID of the task to comment on.
-   **Body Parameters:**
    -   `comment_text` (string, required): The text of the comment. Supports `@mention` syntax: `@[Username](UserID)`.
-   **Permissions:** User must have read access to the task.
-   **Example Request:**
    ```json
    {
        "comment_text": "Let me look into this. @[John Doe](5)"
    }
    ```
-   **Example Response (201 Created):**
    ```json
    {
        "comment": {
            "id": 11,
            "task_id": 124,
            "user_id": 1,
            "comment_text": "Let me look into this. @[John Doe](5)",
            "created_at": "2025-10-27 14:00:00",
            "user_name": "Admin",
            "user_avatar_url": "https://example.com/avatar.jpg",
            "can_manage": true,
            "created_at_formatted": "just now",
            "is_edited": false
        }
    }
    ```

### 3. Update a Comment

-   **Endpoint:** `PUT /comments/{id}` or `PATCH /comments/{id}`
-   **Description:** Updates an existing comment.
-   **URL Parameters:**
    -   `id` (integer, required): The ID of the comment to update.
-   **Body Parameters:**
    -   `comment_text` (string, required): The new content of the comment.
-   **Permissions:** User must be the comment author or have administrative privileges.
-   **Example Response (200 OK):**
    ```json
    {
        "comment": {
            "id": 11,
            "task_id": 124,
            "comment_text": "Updated comment content.",
            "updated_at": "2025-10-27 14:05:00"
        }
    }
    ```

### 4. Delete a Comment

-   **Endpoint:** `DELETE /comments/{id}`
-   **Description:** Deletes a comment.
-   **URL Parameters:**
    -   `id` (integer, required): The ID of the comment to delete.
-   **Permissions:** User must be the comment author or have administrative privileges.
-   **Response Example (200 OK):**
    ```json
    { "message": "Deleted" }
    ```
