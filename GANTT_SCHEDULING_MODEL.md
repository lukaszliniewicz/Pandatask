# Pandatask scheduling and Gantt model

## Sources of truth

Pandatask deliberately keeps three different relationships separate:

1. A parent/subtask link describes decomposition and ownership.
2. A predecessor link describes sequencing and blocking.
3. A project describes a container and may have an independent commitment date.

Sibling subtasks are never assumed to be chronological and never receive
implicit predecessor links. If one subtask must follow another, an administrator
adds that predecessor explicitly.

## Task dates

`start_date` and `deadline` remain authoritative task fields. The Gantt is a
read-only projection of those fields and does not persist inferred dates.

- Both dates: show the declared inclusive range.
- Deadline only: show a one-day deadline marker.
- Start only: show a one-day start marker and retain its partial-schedule style.
- Neither date: keep the task in the Unscheduled tray; never invent a date.
- Dynamic schedule: the existing dependency-completion automation remains
  authoritative. The Gantt displays its resulting dates.

## Parent roll-ups

A parent task has its own Gantt row. Its display span is the union of:

- the parent's declared range; and
- the earliest scheduled descendant start through the latest scheduled
  descendant deadline.

Consequently, a later explicit parent deadline is retained, while an earlier
child can extend the displayed start. The derived span is display-only. If a
child falls outside the parent's own declared dates, the row shows a warning
instead of silently rewriting either record.

An undated parent with dated descendants receives a roll-up-only summary bar.
An undated parent whose descendants are also undated remains Unscheduled.

## Dependencies and conflicts

Arrows are rendered only for stored predecessor relationships. Completed
predecessors and completed parent tasks remain as faded context when completed
work is otherwise hidden.

The view warns when:

- a successor starts before a predecessor's displayed end;
- a descendant falls outside the parent's declared range;
- a legacy record contains an inverted range; or
- a malformed hierarchy cycle is encountered.

Warnings do not mutate data. Selecting the task opens its details, and
Unscheduled items provide a direct Set dates action.

## Projects

A project deadline is an independent commitment date, not an instruction to
spread or sequence its tasks. Projects without deadlines remain valid.

The Projects tab labels them `No deadline` and lists only active work. In a
future project-summary layer, the recommended display range is:

- earliest scheduled task start through the later of the latest task deadline
  and the project's own deadline;
- a deadline milestone when the project has a deadline but no scheduled tasks;
  or
- an Unscheduled project entry when neither the project nor any task has dates.

That summary must also remain derived and must never write dates back to tasks.

## Interaction policy

Version 1.0.14 intentionally avoids drag-to-reschedule. Date and dependency
changes continue through the validated task form, where permissions, cycle
checks, notifications, history, and dynamic scheduling rules already apply.

If direct manipulation is added later, it should use a preview-and-confirm
interaction, expose the exact records that will change, snap in the user's
timezone, preserve idempotency, and call the existing mutation path rather than
maintaining a second scheduling implementation.
