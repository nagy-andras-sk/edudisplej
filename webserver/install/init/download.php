<?php
$baseDir = __DIR__;

require_once __DIR__ . '/../../control_edudisplej_sk/dbkonfiguracia.php';

function require_download_auth() {
    $headers = getallheaders();
    $auth_header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    $token_from_query = $_GET['token'] ?? '';
    $token_from_header = $headers['X-API-Token'] ?? $headers['x-api-token'] ?? '';

    $token = '';
    if (preg_match('/Bearer\s+(.+)$/i', $auth_header, $matches)) {
        $token = trim($matches[1]);
    } elseif (!empty($token_from_header)) {
        $token = trim($token_from_header);
    } elseif (!empty($token_from_query)) {
        $token = trim($token_from_query);
    }

    if (empty($token)) {
        http_response_code(401);
        echo "Authentication required.";
        exit;
    }

    try {
        $conn = getDbConnection();
        $stmt = $conn->prepare("SELECT id, license_key, is_active FROM companies WHERE api_token = ?");
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $result = $stmt->get_result();
        $company = $result->fetch_assoc();
        $stmt->close();
        closeDbConnection($conn);

        if (!$company) {
            http_response_code(401);
            echo "Invalid token.";
            exit;
        }

        if (!$company['is_active'] || empty($company['license_key'])) {
            http_response_code(403);
            echo "License inactive.";
            exit;
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo "Authentication error.";
        exit;
    }
}

require_download_auth();

if (isset($_GET['getstructure'])) {
    $structureFile = $baseDir . '/structure.json';
    
    if (!file_exists($structureFile)) {
        http_response_code(404);
        echo "Structure file not found.";
        exit;
    }
    
    $structureContent = file_get_contents($structureFile);
    if ($structureContent === false) {
        http_response_code(500);
        echo "Error reading structure file.";
        exit;
    }

    // Apply service_versions overrides from database (if available)
    $structureData = json_decode($structureContent, true);
    if (is_array($structureData)) {
        try {
            $conn = getDbConnection();
            $conn->query("CREATE TABLE IF NOT EXISTS service_versions (
                id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
                service_name VARCHAR(255) NOT NULL,
                version_token VARCHAR(64) NOT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                updated_by_user_id INT(11) DEFAULT NULL,
                UNIQUE KEY uniq_service_name (service_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $result = $conn->query("SELECT service_name, version_token FROM service_versions");
            if ($result) {
                if (!isset($structureData['service_versions']) || !is_array($structureData['service_versions'])) {
                    $structureData['service_versions'] = [];
                }

                while ($row = $result->fetch_assoc()) {
                    $serviceName = trim((string)($row['service_name'] ?? ''));
                    $versionToken = trim((string)($row['version_token'] ?? ''));
                    if ($serviceName === '' || $versionToken === '') {
                        continue;
                    }
                    $structureData['service_versions'][$serviceName] = $versionToken;
                }
            }

            closeDbConnection($conn);
            $encoded = json_encode($structureData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($encoded) && $encoded !== '') {
                $structureContent = $encoded;
            }
        } catch (Exception $e) {
            // Fallback silently to file content if DB override load fails
        }
    }
    
    header('Content-Type: application/json');
    header('Content-Length: ' . strlen($structureContent));
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    
    echo $structureContent;
    exit;
}

if (isset($_GET['getfiles'])) {
    foreach (scandir($baseDir) as $file) {
        if ($file === '.' || $file === '..') continue;
        if (is_file($baseDir . '/' . $file)) {
            echo $file . ";" . filesize($baseDir . '/' . $file) . ";" . date('Y-m-d H:i:s', filemtime($baseDir . '/' . $file)) . "\n";
        }
    }
    exit;
}

if (isset($_GET['streamfile'])) {
    $file = basename($_GET['streamfile']);
    $filePath = $baseDir . '/' . $file;

    if (!file_exists($filePath)) {
        http_response_code(404);
        echo "File not found.";
        exit;
    }

    // Disable output buffering to prevent truncation
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    // Disable time limit for large files
    set_time_limit(0);
    
    $fileSize = filesize($filePath);
    if ($fileSize === false) {
        http_response_code(500);
        echo "Error getting file size: " . $file;
        exit;
    }
    
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Content-Length: ' . $fileSize);
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    
    // Stream file in chunks to prevent memory issues and ensure complete transfer
    $handle = fopen($filePath, 'rb');
    if ($handle === false) {
        http_response_code(500);
        echo "Error opening file: " . $file;
        exit;
    }
    
    $chunkSize = 8192; // 8KB chunks
    while (!feof($handle)) {
        $buffer = fread($handle, $chunkSize);
        if ($buffer === false) {
            fclose($handle);
            http_response_code(500);
            echo "Error reading file: " . $file;
            exit;
        }
        echo $buffer;
        flush(); // Force output to be sent immediately
    }
    
    fclose($handle);
    exit;
}

echo "Invalid request. Use ?getstructure, ?getfiles or ?streamfile=filename";
?>
