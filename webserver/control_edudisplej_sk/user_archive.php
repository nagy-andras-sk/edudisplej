<?php
/**
 * Archived user lifecycle helpers.
 */

require_once __DIR__ . '/dbkonfiguracia.php';

function edudisplej_ensure_archived_users_table(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS archived_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        original_user_id INT NULL,
        username VARCHAR(255) NOT NULL,
        email VARCHAR(255) NULL,
        password_hash VARCHAR(255) NOT NULL,
        company_id INT NULL,
        isadmin TINYINT(1) NOT NULL DEFAULT 0,
        user_role VARCHAR(32) NOT NULL DEFAULT 'user',
        otp_enabled TINYINT(1) NOT NULL DEFAULT 0,
        otp_verified TINYINT(1) NOT NULL DEFAULT 0,
        otp_secret VARCHAR(255) NULL,
        last_login DATETIME NULL,
        original_created_at DATETIME NULL,
        archived_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        archived_by_user_id INT NULL,
        archive_reason VARCHAR(64) NOT NULL DEFAULT 'manual_delete',
        archive_note TEXT NULL,
        restored_at DATETIME NULL,
        restored_by_user_id INT NULL,
        restored_user_id INT NULL,
        INDEX idx_archived_at (archived_at),
        INDEX idx_original_user (original_user_id),
        INDEX idx_username (username),
        INDEX idx_company (company_id),
        INDEX idx_restored_at (restored_at),
        CONSTRAINT archived_users_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
        CONSTRAINT archived_users_archived_by_fk FOREIGN KEY (archived_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
        CONSTRAINT archived_users_restored_by_fk FOREIGN KEY (restored_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
        CONSTRAINT archived_users_restored_user_fk FOREIGN KEY (restored_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function edudisplej_archive_user(mysqli $conn, int $target_user_id, int $archived_by_user_id, string $reason = 'manual_delete', ?string $note = null, bool $wrap_transaction = true): array {
    edudisplej_ensure_archived_users_table($conn);

    $select_stmt = $conn->prepare("SELECT id, username, email, password, company_id, isadmin, user_role, otp_enabled, otp_verified, otp_secret, last_login, created_at FROM users WHERE id = ? LIMIT 1");
    $select_stmt->bind_param('i', $target_user_id);
    $select_stmt->execute();
    $user = $select_stmt->get_result()->fetch_assoc();
    $select_stmt->close();

    if (!$user) {
        return [
            'success' => false,
            'message' => 'User not found'
        ];
    }

    if ($wrap_transaction) {
        $conn->begin_transaction();
    }
    try {
        $insert_stmt = $conn->prepare("INSERT INTO archived_users (original_user_id, username, email, password_hash, company_id, isadmin, user_role, otp_enabled, otp_verified, otp_secret, last_login, original_created_at, archived_by_user_id, archive_reason, archive_note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $original_user_id = (int)$user['id'];
        $username = (string)$user['username'];
        $email = isset($user['email']) ? (string)$user['email'] : null;
        $password_hash = (string)$user['password'];
        $company_id = !empty($user['company_id']) ? (int)$user['company_id'] : null;
        $isadmin = !empty($user['isadmin']) ? 1 : 0;
        $user_role = (string)($user['user_role'] ?? 'user');
        $otp_enabled = !empty($user['otp_enabled']) ? 1 : 0;
        $otp_verified = !empty($user['otp_verified']) ? 1 : 0;
        $otp_secret = $user['otp_secret'] ?? null;
        $last_login = $user['last_login'] ?? null;
        $original_created_at = $user['created_at'] ?? null;

        $insert_stmt->bind_param(
            'isssiisiiississ',
            $original_user_id,
            $username,
            $email,
            $password_hash,
            $company_id,
            $isadmin,
            $user_role,
            $otp_enabled,
            $otp_verified,
            $otp_secret,
            $last_login,
            $original_created_at,
            $archived_by_user_id,
            $reason,
            $note
        );
        $insert_stmt->execute();
        $archive_id = (int)$conn->insert_id;
        $insert_stmt->close();

        $delete_stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $delete_stmt->bind_param('i', $target_user_id);
        $delete_stmt->execute();
        $delete_stmt->close();

        if ($wrap_transaction) {
            $conn->commit();
        }

        return [
            'success' => true,
            'archive_id' => $archive_id,
            'username' => $username,
            'company_id' => $company_id,
            'isadmin' => $isadmin,
            'message' => 'User archived successfully'
        ];
    } catch (Throwable $e) {
        if ($wrap_transaction) {
            $conn->rollback();
        }
        return [
            'success' => false,
            'message' => 'Archive failed: ' . $e->getMessage()
        ];
    }
}

function edudisplej_restore_archived_user(mysqli $conn, int $archive_id, int $restored_by_user_id, bool $wrap_transaction = true): array {
    edudisplej_ensure_archived_users_table($conn);

    $select_stmt = $conn->prepare("SELECT * FROM archived_users WHERE id = ? LIMIT 1");
    $select_stmt->bind_param('i', $archive_id);
    $select_stmt->execute();
    $archived_user = $select_stmt->get_result()->fetch_assoc();
    $select_stmt->close();

    if (!$archived_user) {
        return [
            'success' => false,
            'message' => 'Archived user not found'
        ];
    }

    if (!empty($archived_user['restored_at'])) {
        return [
            'success' => false,
            'message' => 'User already restored'
        ];
    }

    $username = (string)$archived_user['username'];
    $base_username = $username;
    $suffix = 1;

    while (true) {
        $check_stmt = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $check_stmt->bind_param('s', $username);
        $check_stmt->execute();
        $exists = $check_stmt->get_result()->num_rows > 0;
        $check_stmt->close();

        if (!$exists) {
            break;
        }

        $username = $base_username . '_restored' . $suffix;
        $suffix++;
    }

    if ($wrap_transaction) {
        $conn->begin_transaction();
    }
    try {
        $insert_stmt = $conn->prepare("INSERT INTO users (username, email, password, company_id, isadmin, user_role, otp_enabled, otp_verified, otp_secret, last_login) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $email = $archived_user['email'] ?? null;
        $password_hash = (string)$archived_user['password_hash'];
        $company_id = !empty($archived_user['company_id']) ? (int)$archived_user['company_id'] : null;
        $isadmin = !empty($archived_user['isadmin']) ? 1 : 0;
        $user_role = (string)($archived_user['user_role'] ?? 'user');
        $otp_enabled = !empty($archived_user['otp_enabled']) ? 1 : 0;
        $otp_verified = !empty($archived_user['otp_verified']) ? 1 : 0;
        $otp_secret = $archived_user['otp_secret'] ?? null;
        $last_login = $archived_user['last_login'] ?? null;

        $insert_stmt->bind_param(
            'sssiisiiis',
            $username,
            $email,
            $password_hash,
            $company_id,
            $isadmin,
            $user_role,
            $otp_enabled,
            $otp_verified,
            $otp_secret,
            $last_login
        );
        $insert_stmt->execute();
        $restored_user_id = (int)$conn->insert_id;
        $insert_stmt->close();

        $update_archive_stmt = $conn->prepare("UPDATE archived_users SET restored_at = NOW(), restored_by_user_id = ?, restored_user_id = ? WHERE id = ?");
        $update_archive_stmt->bind_param('iii', $restored_by_user_id, $restored_user_id, $archive_id);
        $update_archive_stmt->execute();
        $update_archive_stmt->close();

        if ($wrap_transaction) {
            $conn->commit();
        }

        return [
            'success' => true,
            'username' => $username,
            'restored_user_id' => $restored_user_id,
            'message' => 'User restored successfully'
        ];
    } catch (Throwable $e) {
        if ($wrap_transaction) {
            $conn->rollback();
        }
        return [
            'success' => false,
            'message' => 'Restore failed: ' . $e->getMessage()
        ];
    }
}
