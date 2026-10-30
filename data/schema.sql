-- IBEX Overtime Portal database (SQLite).
-- Creates the tables if they do not exist yet. Safe to run more than once.

PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS users (
    id             TEXT PRIMARY KEY,
    full_name      TEXT NOT NULL,
    email          TEXT NOT NULL UNIQUE COLLATE NOCASE,
    employee_id    TEXT NOT NULL DEFAULT '',
    department     TEXT NOT NULL DEFAULT '',
    role           TEXT NOT NULL CHECK (role IN ('Employee', 'Manager', 'Admin')),
    manager_id     TEXT NULL REFERENCES users(id),
    password_hash  TEXT NOT NULL,
    is_active      INTEGER NOT NULL DEFAULT 1
);

CREATE UNIQUE INDEX IF NOT EXISTS ux_users_employee_id
    ON users(employee_id) WHERE employee_id <> '';

CREATE TABLE IF NOT EXISTS overtime_requests (
    id                  TEXT PRIMARY KEY,
    employee_user_id    TEXT NOT NULL REFERENCES users(id),
    work_date           TEXT NOT NULL,           -- yyyy-mm-dd
    start_time          TEXT NOT NULL,           -- HH:MM
    end_time            TEXT NOT NULL,           -- HH:MM
    hours               REAL NOT NULL,
    project             TEXT NOT NULL DEFAULT '',
    reason              TEXT NOT NULL DEFAULT '',
    status              TEXT NOT NULL CHECK (status IN ('Pending', 'Approved', 'Rejected')),
    manager_note        TEXT NOT NULL DEFAULT '',
    submitted_at_utc    TEXT NOT NULL,           -- yyyy-mm-dd hh:mm:ss (UTC)
    decided_at_utc      TEXT NULL,
    decided_by_user_id  TEXT NULL REFERENCES users(id)
);

CREATE INDEX IF NOT EXISTS ix_requests_employee ON overtime_requests(employee_user_id, work_date);
CREATE INDEX IF NOT EXISTS ix_requests_status   ON overtime_requests(status);
