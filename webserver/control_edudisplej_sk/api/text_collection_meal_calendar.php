<?php
/**
 * API - Text Collection Meal Calendar (manual prefill)
 */
session_start();
require_once '../dbkonfiguracia.php';
require_once '../auth_roles.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || !edudisplej_can_edit_module_content()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Hozzáférés megtagadva'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

function edudisplej_tcmc_company_id(): int {
    $company_id = (int)($_SESSION['company_id'] ?? 0);
    if ($company_id > 0) {
        return $company_id;
    }
    $acting = (int)($_SESSION['admin_acting_company_id'] ?? 0);
    return $acting > 0 ? $acting : 0;
}

function edudisplej_tcmc_ensure_schema(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS text_collection_meal_calendar (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        menu_date DATE NOT NULL,
        institution_label VARCHAR(220) NOT NULL DEFAULT '',
        breakfast TEXT NULL,
        snack_am TEXT NULL,
        lunch TEXT NULL,
        snack_pm TEXT NULL,
        dinner TEXT NULL,
        note_text TEXT NULL,
        created_by INT NULL,
        updated_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_company_date_institution (company_id, menu_date, institution_label),
        INDEX idx_company_date (company_id, menu_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function edudisplej_tcmc_trim($value, int $max = 15000): string {
    $text = trim((string)$value);
    if (mb_strlen($text, 'UTF-8') > $max) {
        $text = mb_substr($text, 0, $max, 'UTF-8');
    }
    return $text;
}

$company_id = edudisplej_tcmc_company_id();
if ($company_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Érvénytelen cég'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

$action = strtolower(trim((string)($_REQUEST['action'] ?? 'list')));
$user_id = (int)($_SESSION['user_id'] ?? 0);

try {
    $conn = getDbConnection();
    edudisplej_tcmc_ensure_schema($conn);

    if ($action === 'list') {
        $from_date = trim((string)($_GET['from_date'] ?? $_POST['from_date'] ?? date('Y-m-01')));
        $to_date = trim((string)($_GET['to_date'] ?? $_POST['to_date'] ?? date('Y-m-t')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_date)) { $from_date = date('Y-m-01'); }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to_date)) { $to_date = date('Y-m-t'); }

        $stmt = $conn->prepare("SELECT id, menu_date, institution_label, breakfast, snack_am, lunch, snack_pm, dinner, note_text, updated_at
                                FROM text_collection_meal_calendar
                                WHERE company_id = ? AND menu_date BETWEEN ? AND ?
                                ORDER BY menu_date ASC, institution_label ASC");
        $stmt->bind_param('iss', $company_id, $from_date, $to_date);
        $stmt->execute();
        $result = $stmt->get_result();

        $items = [];
        while ($row = $result->fetch_assoc()) {
            $items[] = [
                'id' => (int)$row['id'],
                'menu_date' => (string)$row['menu_date'],
                'institution_label' => (string)($row['institution_label'] ?? ''),
                'breakfast' => (string)($row['breakfast'] ?? ''),
                'snack_am' => (string)($row['snack_am'] ?? ''),
                'lunch' => (string)($row['lunch'] ?? ''),
                'snack_pm' => (string)($row['snack_pm'] ?? ''),
                'dinner' => (string)($row['dinner'] ?? ''),
                'note_text' => (string)($row['note_text'] ?? ''),
                'updated_at' => (string)($row['updated_at'] ?? ''),
            ];
        }
        $stmt->close();

        closeDbConnection($conn);
        echo json_encode(['success' => true, 'items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }

    if ($action === 'save') {
        $payload = json_decode((string)file_get_contents('php://input'), true);
        $input = is_array($payload) ? $payload : $_POST;

        $menu_date = trim((string)($input['menu_date'] ?? ''));
        $institution_label = edudisplej_tcmc_trim($input['institution_label'] ?? '', 220);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $menu_date) || $institution_label === '') {
            closeDbConnection($conn);
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Dátum és intézmény kötelező'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit();
        }

        $breakfast = edudisplej_tcmc_trim($input['breakfast'] ?? '');
        $snack_am = edudisplej_tcmc_trim($input['snack_am'] ?? '');
        $lunch = edudisplej_tcmc_trim($input['lunch'] ?? '');
        $snack_pm = edudisplej_tcmc_trim($input['snack_pm'] ?? '');
        $dinner = edudisplej_tcmc_trim($input['dinner'] ?? '');
        $note_text = edudisplej_tcmc_trim($input['note_text'] ?? '');

        $stmt = $conn->prepare("INSERT INTO text_collection_meal_calendar (company_id, menu_date, institution_label, breakfast, snack_am, lunch, snack_pm, dinner, note_text, created_by, updated_by)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE breakfast = VALUES(breakfast), snack_am = VALUES(snack_am), lunch = VALUES(lunch), snack_pm = VALUES(snack_pm), dinner = VALUES(dinner), note_text = VALUES(note_text), updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP");
        $stmt->bind_param('issssssssii', $company_id, $menu_date, $institution_label, $breakfast, $snack_am, $lunch, $snack_pm, $dinner, $note_text, $user_id, $user_id);
        $stmt->execute();
        $stmt->close();

        closeDbConnection($conn);
        echo json_encode(['success' => true, 'message' => 'Napi étrend mentve'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }

    if ($action === 'delete') {
        $payload = json_decode((string)file_get_contents('php://input'), true);
        $input = is_array($payload) ? $payload : $_POST;
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            closeDbConnection($conn);
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Érvénytelen azonosító'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit();
        }

        $stmt = $conn->prepare("DELETE FROM text_collection_meal_calendar WHERE id = ? AND company_id = ? LIMIT 1");
        $stmt->bind_param('ii', $id, $company_id);
        $stmt->execute();
        $stmt->close();

        closeDbConnection($conn);
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }

    closeDbConnection($conn);
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Ismeretlen művelet'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('api/text_collection_meal_calendar.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Szerver hiba'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
