<?php
/**
 * Role and permission helpers.
 */

function edudisplej_normalize_user_role(?string $role, bool $is_admin = false): string {
    if ($is_admin) {
        return 'admin';
    }

    $value = strtolower(trim((string)$role));
    $allowed = ['user', 'easy_user'];

    return in_array($value, $allowed, true) ? $value : 'user';
}

function edudisplej_get_session_role(): string {
    $is_admin = !empty($_SESSION['isadmin']);
    $role = $_SESSION['user_role'] ?? null;

    return edudisplej_normalize_user_role($role, $is_admin);
}

function edudisplej_can_manage_loops(): bool {
    $role = edudisplej_get_session_role();
    return in_array($role, ['admin', 'user', 'easy_user'], true);
}

function edudisplej_can_edit_module_content(): bool {
    $role = edudisplej_get_session_role();
    return in_array($role, ['admin', 'user', 'easy_user'], true);
}

function edudisplej_ensure_user_role_column(mysqli $conn): void {
    static $ensured = false;

    if ($ensured) {
        return;
    }

    $check = $conn->query("SHOW COLUMNS FROM users LIKE 'user_role'");
    if (!$check || $check->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN user_role VARCHAR(32) NOT NULL DEFAULT 'user' AFTER isadmin");
        $conn->query("UPDATE users SET user_role = 'admin' WHERE isadmin = 1");
    }

    $ensured = true;
}
