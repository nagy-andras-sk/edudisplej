<?php
/**
 * API Activity Logs - Minimal Table
 */

session_start();
require_once '../dbkonfiguracia.php';
require_once __DIR__ . '/db_autofix_bootstrap.php';

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!isset($_SESSION['user_id']) || !isset($_SESSION['isadmin']) || !$_SESSION['isadmin']) {
    header('Location: ../login.php');
    exit();
}

$error = '';
$success = '';

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 50;
$offset = ($page - 1) * $per_page;

$filter_company = $_GET['company'] ?? '';
$filter_endpoint = $_GET['endpoint'] ?? '';
$filter_status = $_GET['status'] ?? '';
$filter_date = $_GET['date'] ?? '';

$logs = [];
$total_logs = 0;
$companies = [];

try {
    $conn = getDbConnection();

    $table_check = $conn->query("SHOW TABLES LIKE 'api_logs'");
    if ($table_check->num_rows === 0) {
        $create_table = "
        CREATE TABLE IF NOT EXISTS api_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NULL,
            kiosk_id INT NULL,
            endpoint VARCHAR(255) NOT NULL,
            method VARCHAR(10) NOT NULL DEFAULT 'GET',
            status_code INT NOT NULL DEFAULT 200,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            request_data TEXT NULL,
            response_data TEXT NULL,
            execution_time FLOAT NULL,
            timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_company (company_id),
            INDEX idx_endpoint (endpoint),
            INDEX idx_timestamp (timestamp),
            INDEX idx_status (status_code),
            FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
            FOREIGN KEY (kiosk_id) REFERENCES kiosks(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";

        if ($conn->query($create_table)) {
            $success = 'API logs table created successfully';
        }
    }

    $result = $conn->query("SELECT id, name FROM companies ORDER BY name");
    while ($row = $result->fetch_assoc()) {
        $companies[] = $row;
    }

    $where_clauses = [];
    $params = [];
    $types = '';

    if (!empty($filter_company)) {
        $where_clauses[] = "l.company_id = ?";
        $params[] = $filter_company;
        $types .= 'i';
    }

    if (!empty($filter_endpoint)) {
        $where_clauses[] = "l.endpoint LIKE ?";
        $safe_endpoint = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filter_endpoint);
        $params[] = "%$safe_endpoint%";
        $types .= 's';
    }

    if (!empty($filter_status)) {
        if ($filter_status === 'success') {
            $where_clauses[] = "l.status_code < 300";
        } elseif ($filter_status === 'error') {
            $where_clauses[] = "l.status_code >= 400";
        }
    }

    if (!empty($filter_date)) {
        $where_clauses[] = "DATE(l.timestamp) = ?";
        $params[] = $filter_date;
        $types .= 's';
    }

    $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

    $count_query = "SELECT COUNT(*) as total FROM api_logs l $where_sql";
    if (!empty($params)) {
        $stmt = $conn->prepare($count_query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $total_logs = $stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();
    } else {
        $total_logs = $conn->query($count_query)->fetch_assoc()['total'];
    }

    $query = "
        SELECT l.*, c.name as company_name, k.hostname as kiosk_name
        FROM api_logs l
        LEFT JOIN companies c ON l.company_id = c.id
        LEFT JOIN kiosks k ON l.kiosk_id = k.id
        $where_sql
        ORDER BY l.timestamp DESC
        LIMIT ? OFFSET ?
    ";

    $stmt = $conn->prepare($query);
    if (!empty($params)) {
        $params[] = $per_page;
        $params[] = $offset;
        $types .= 'ii';
        $stmt->bind_param($types, ...$params);
    } else {
        $stmt->bind_param('ii', $per_page, $offset);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $logs[] = $row;
    }

    $stmt->close();
    closeDbConnection($conn);
} catch (Exception $e) {
    $error = 'Failed to load logs: ' . $e->getMessage();
    error_log('API logs error: ' . $e->getMessage());
}

$total_pages = max(1, (int)ceil($total_logs / $per_page));

function edudisplej_format_payload_for_view($payload): string
{
    if ($payload === null) {
        return '—';
    }

    $raw = trim((string)$payload);
    if ($raw === '') {
        return '—';
    }

    $decoded = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        $pretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $pretty !== false ? $pretty : $raw;
    }

    if (strpos($raw, '=') !== false && strpos($raw, '{') === false && strpos($raw, '[') === false) {
        parse_str($raw, $parsed);
        if (!empty($parsed)) {
            $pretty = json_encode($parsed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return $pretty !== false ? $pretty : $raw;
        }
    }

    return $raw;
}

function edudisplej_extract_get_params(array $log): array
{
    $params = [];
    $endpoint = (string)($log['endpoint'] ?? '');

    if ($endpoint !== '') {
        $query = (string)parse_url($endpoint, PHP_URL_QUERY);
        if ($query !== '') {
            parse_str($query, $params);
        }
    }

    if (!empty($params)) {
        return $params;
    }

    if (strtoupper((string)($log['method'] ?? '')) !== 'GET') {
        return [];
    }

    $requestDataRaw = trim((string)($log['request_data'] ?? ''));
    if ($requestDataRaw === '') {
        return [];
    }

    $decoded = json_decode($requestDataRaw, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        return $decoded;
    }

    parse_str($requestDataRaw, $parsed);
    return is_array($parsed) ? $parsed : [];
}

include 'header.php';
?>

<?php if ($error): ?>
    <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<div class="panel">
    <div class="panel-title">Szurok</div>
    <form method="get" class="toolbar">
        <div class="form-field">
            <label for="company">Ceg</label>
            <select id="company" name="company">
                <option value="">Osszes</option>
                <?php foreach ($companies as $company): ?>
                    <option value="<?php echo (int)$company['id']; ?>" <?php echo $filter_company == $company['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($company['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-field">
            <label for="endpoint">Endpoint</label>
            <input id="endpoint" name="endpoint" type="text" value="<?php echo htmlspecialchars($filter_endpoint); ?>">
        </div>
        <div class="form-field">
            <label for="status">Statusz</label>
            <select id="status" name="status">
                <option value="">Osszes</option>
                <option value="success" <?php echo $filter_status === 'success' ? 'selected' : ''; ?>>Success</option>
                <option value="error" <?php echo $filter_status === 'error' ? 'selected' : ''; ?>>Error</option>
            </select>
        </div>
        <div class="form-field">
            <label for="date">Datum</label>
            <input id="date" name="date" type="date" value="<?php echo htmlspecialchars($filter_date); ?>">
        </div>
        <div class="form-field">
            <button class="btn btn-primary" type="submit">Szures</button>
        </div>
        <div class="form-field">
            <a class="btn btn-secondary" href="api_logs.php">Reset</a>
        </div>
    </form>
</div>

<div class="panel">
    <div class="panel-title">Logok (<?php echo (int)$total_logs; ?>)</div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Timestamp</th>
                    <th>Company</th>
                    <th>Kiosk</th>
                    <th>Endpoint</th>
                    <th>Method</th>
                    <th>Status</th>
                    <th>IP</th>
                    <th>Exec ms</th>
                    <th>Reszletek</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="10" class="muted">Nincs log.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                        <?php
                            $get_params = edudisplej_extract_get_params($log);
                            $formatted_get_params = empty($get_params)
                                ? '—'
                                : (json_encode($get_params, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '—');
                            $formatted_request = edudisplej_format_payload_for_view($log['request_data'] ?? null);
                            $formatted_response = edudisplej_format_payload_for_view($log['response_data'] ?? null);
                        ?>
                        <tr>
                            <td><?php echo (int)$log['id']; ?></td>
                            <td class="nowrap"><?php echo date('Y-m-d H:i:s', strtotime($log['timestamp'])); ?></td>
                            <td><?php echo htmlspecialchars($log['company_name'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($log['kiosk_name'] ?? '-'); ?></td>
                            <td class="mono"><?php echo htmlspecialchars($log['endpoint']); ?></td>
                            <td><?php echo htmlspecialchars($log['method']); ?></td>
                            <td><?php echo (int)$log['status_code']; ?></td>
                            <td class="mono"><?php echo htmlspecialchars($log['ip_address'] ?? '-'); ?></td>
                            <td><?php echo $log['execution_time'] !== null ? number_format((float)$log['execution_time'] * 1000, 1) : '-'; ?></td>
                            <td>
                                <details>
                                    <summary style="cursor:pointer;">Megnezes</summary>
                                    <div style="margin-top:8px; display:grid; gap:8px; min-width:420px;">
                                        <div>
                                            <strong>GET params:</strong>
                                            <pre class="mono" style="white-space:pre-wrap; margin:4px 0 0; background:#f6f8fa; padding:8px; border-radius:6px;"><?php echo htmlspecialchars($formatted_get_params); ?></pre>
                                        </div>
                                        <div>
                                            <strong>Request:</strong>
                                            <pre class="mono" style="white-space:pre-wrap; margin:4px 0 0; background:#f6f8fa; padding:8px; border-radius:6px;"><?php echo htmlspecialchars($formatted_request); ?></pre>
                                        </div>
                                        <div>
                                            <strong>Response:</strong>
                                            <pre class="mono" style="white-space:pre-wrap; margin:4px 0 0; background:#f6f8fa; padding:8px; border-radius:6px;"><?php echo htmlspecialchars($formatted_response); ?></pre>
                                        </div>
                                    </div>
                                </details>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div style="margin-top: 10px;">
        <?php if ($total_pages > 1): ?>
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <?php
                    $params = $_GET;
                    $params['page'] = $i;
                    $url = 'api_logs.php?' . http_build_query($params);
                ?>
                <a class="btn btn-small <?php echo $i === $page ? 'btn-primary' : ''; ?>" href="<?php echo htmlspecialchars($url); ?>"><?php echo $i; ?></a>
            <?php endfor; ?>
        <?php endif; ?>
    </div>
</div>

<?php include 'footer.php'; ?>
