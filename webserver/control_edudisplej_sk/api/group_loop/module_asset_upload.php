<?php
session_start();
require_once __DIR__ . '/../../dbkonfiguracia.php';
require_once __DIR__ . '/../../auth_roles.php';
require_once __DIR__ . '/../../modules/module_asset_service.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

if (!edudisplej_can_edit_module_content()) {
    echo json_encode(['success' => false, 'message' => 'Hozzáférés megtagadva']);
    exit();
}

$group_id = (int)($_POST['group_id'] ?? 0);
$module_key = strtolower(trim((string)($_POST['module_key'] ?? '')));
$asset_kind = strtolower(trim((string)($_POST['asset_kind'] ?? 'file')));
$user_id = (int)($_SESSION['user_id'] ?? 0);
$company_id = (int)($_SESSION['company_id'] ?? 0);
$is_admin = !empty($_SESSION['isadmin']);

if ($group_id <= 0 || $module_key === '') {
    echo json_encode(['success' => false, 'message' => 'Hiányzó paraméter']);
    exit();
}

if (!isset($_FILES['asset']) || !is_array($_FILES['asset'])) {
    echo json_encode(['success' => false, 'message' => 'Nincs feltöltött fájl']);
    exit();
}

$file = $_FILES['asset'];
if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'Feltöltési hiba']);
    exit();
}

$tmp_path = (string)($file['tmp_name'] ?? '');
$original_name = (string)($file['name'] ?? 'asset.bin');
$file_size = (int)($file['size'] ?? 0);
$client_processing = strtolower(trim((string)($_POST['client_processing'] ?? '')));

function edudisplej_ffprobe_available(): bool {
    if (!function_exists('shell_exec')) {
        return false;
    }

    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (in_array('shell_exec', $disabled, true)) {
        return false;
    }

    return true;
}

function edudisplej_probe_video_file(string $file_path): array {
    if (!is_file($file_path)) {
        return ['success' => false, 'message' => 'Hiányzó feltöltött videó'];
    }

    if (!edudisplej_ffprobe_available()) {
        return ['success' => false, 'message' => 'A szerver oldali videóellenőrzés nem érhető el (ffprobe szükséges)'];
    }

    $ffprobe_binary = PHP_OS_FAMILY === 'Windows' ? 'ffprobe.exe' : 'ffprobe';
    $cmd = escapeshellcmd($ffprobe_binary)
        . ' -v error -print_format json -show_format -show_streams '
        . escapeshellarg($file_path) . ' 2>&1';

    $raw = (string)@shell_exec($cmd);
    if (trim($raw) === '') {
        return ['success' => false, 'message' => 'A videó technikai ellenőrzése sikertelen (üres ffprobe válasz)'];
    }

    $parsed = json_decode($raw, true);
    if (!is_array($parsed)) {
        return ['success' => false, 'message' => 'A videó technikai ellenőrzése sikertelen (érvénytelen ffprobe kimenet)'];
    }

    $format = is_array($parsed['format'] ?? null) ? $parsed['format'] : [];
    $streams = is_array($parsed['streams'] ?? null) ? $parsed['streams'] : [];

    $video_stream = null;
    $audio_stream = null;
    foreach ($streams as $stream) {
        if (!is_array($stream)) {
            continue;
        }
        $type = strtolower((string)($stream['codec_type'] ?? ''));
        if ($type === 'video' && $video_stream === null) {
            $video_stream = $stream;
            continue;
        }
        if ($type === 'audio' && $audio_stream === null) {
            $audio_stream = $stream;
        }
    }

    if (!is_array($video_stream)) {
        return ['success' => false, 'message' => 'A feltöltött fájl nem tartalmaz videó streamet'];
    }

    $duration = 0.0;
    if (isset($video_stream['duration']) && is_numeric($video_stream['duration'])) {
        $duration = (float)$video_stream['duration'];
    } elseif (isset($format['duration']) && is_numeric($format['duration'])) {
        $duration = (float)$format['duration'];
    }

    return [
        'success' => true,
        'format_name' => strtolower((string)($format['format_name'] ?? '')),
        'video_codec' => strtolower((string)($video_stream['codec_name'] ?? '')),
        'audio_codec' => is_array($audio_stream) ? strtolower((string)($audio_stream['codec_name'] ?? '')) : '',
        'duration' => $duration,
        'width' => (int)($video_stream['width'] ?? 0),
        'height' => (int)($video_stream['height'] ?? 0),
    ];
}

function edudisplej_compress_uploaded_image(string $source_path, string $target_dir_abs): array {
    if (!is_file($source_path)) {
        throw new RuntimeException('Forrásfájl nem található');
    }

    $raw = @file_get_contents($source_path);
    if ($raw === false) {
        throw new RuntimeException('Kép olvasási hiba');
    }

    $img = @imagecreatefromstring($raw);
    if (!$img) {
        throw new RuntimeException('A kép formátuma nem támogatott');
    }

    $src_w = imagesx($img);
    $src_h = imagesy($img);
    if ($src_w <= 0 || $src_h <= 0) {
        imagedestroy($img);
        throw new RuntimeException('Érvénytelen képméret');
    }

    $max_dim = 1920;
    $ratio = min($max_dim / $src_w, $max_dim / $src_h, 1);
    $dst_w = max(1, (int)round($src_w * $ratio));
    $dst_h = max(1, (int)round($src_h * $ratio));

    $canvas = imagecreatetruecolor($dst_w, $dst_h);
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
    imagefilledrectangle($canvas, 0, 0, $dst_w, $dst_h, $transparent);
    imagecopyresampled($canvas, $img, 0, 0, 0, 0, $dst_w, $dst_h, $src_w, $src_h);

    $random_part = bin2hex(random_bytes(8));
    $filename_base = date('Ymd_His') . '_' . $random_part;

    $saved = false;
    $target_filename = $filename_base . '.jpg';
    $target_abs = rtrim($target_dir_abs, '/\\') . '/' . $target_filename;
    if (function_exists('imagewebp')) {
        $target_filename = $filename_base . '.webp';
        $target_abs = rtrim($target_dir_abs, '/\\') . '/' . $target_filename;
        $saved = @imagewebp($canvas, $target_abs, 82);
    }

    if (!$saved) {
        $target_filename = $filename_base . '.jpg';
        $target_abs = rtrim($target_dir_abs, '/\\') . '/' . $target_filename;
        $saved = @imagejpeg($canvas, $target_abs, 84);
    }

    imagedestroy($canvas);
    imagedestroy($img);

    if (!$saved) {
        throw new RuntimeException('A kép tömörítése sikertelen');
    }

    return [
        'target_filename' => $target_filename,
        'target_abs' => $target_abs,
    ];
}

if ($module_key === 'pdf') {
    if ($file_size <= 0 || $file_size > 50 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'A fájl mérete érvénytelen (max. 50 MB)']);
        exit();
    }

    $mime = strtolower((string)mime_content_type($tmp_path));
    $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
    if ($ext !== 'pdf' || !in_array($mime, ['application/pdf', 'application/octet-stream'], true)) {
        echo json_encode(['success' => false, 'message' => 'Csak PDF fájl tölthető fel']);
        exit();
    }
} elseif ($module_key === 'image-gallery' || $module_key === 'gallery') {
    if ($file_size <= 0 || $file_size > 15 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'A kép mérete érvénytelen (max. 15 MB)']);
        exit();
    }

    $mime = strtolower((string)mime_content_type($tmp_path));
    $allowed_mimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    if (!in_array($mime, $allowed_mimes, true)) {
        echo json_encode(['success' => false, 'message' => 'Csak képformátum tölthető fel (jpg/png/webp/gif)']);
        exit();
    }
} elseif ($module_key === 'video') {
    if ($client_processing !== 'ffmpeg_wasm_v1') {
        echo json_encode(['success' => false, 'message' => 'A videó csak kliens oldali optimalizálás után tölthető fel']);
        exit();
    }

    if ($file_size <= 0 || $file_size > 25 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'A videó mérete érvénytelen (max. 25 MB)']);
        exit();
    }

    $mime = strtolower((string)mime_content_type($tmp_path));
    $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
    if ($ext !== 'mp4' || !in_array($mime, ['video/mp4', 'application/octet-stream'], true)) {
        echo json_encode(['success' => false, 'message' => 'Csak MP4 videó tölthető fel']);
        exit();
    }

    $probe = edudisplej_probe_video_file($tmp_path);
    if (empty($probe['success'])) {
        echo json_encode(['success' => false, 'message' => (string)($probe['message'] ?? 'A videó ellenőrzése sikertelen')]);
        exit();
    }

    $format_name = (string)($probe['format_name'] ?? '');
    if ($format_name === '' || strpos($format_name, 'mp4') === false) {
        echo json_encode(['success' => false, 'message' => 'A videó konténere nem támogatott (csak MP4)']);
        exit();
    }

    $duration = (float)($probe['duration'] ?? 0);
    if ($duration <= 0 || $duration > 120.0) {
        echo json_encode(['success' => false, 'message' => 'A videó hossza érvénytelen (max. 120 mp)']);
        exit();
    }

    $width = (int)($probe['width'] ?? 0);
    $height = (int)($probe['height'] ?? 0);
    if ($width <= 0 || $height <= 0 || $width > 1280 || $height > 720) {
        echo json_encode(['success' => false, 'message' => 'A videó felbontása érvénytelen (max. 1280×720)']);
        exit();
    }

    $video_codec = (string)($probe['video_codec'] ?? '');
    if ($video_codec !== 'h264') {
        echo json_encode(['success' => false, 'message' => 'A videó kodekje nem támogatott (csak H.264)']);
        exit();
    }

    $audio_codec = (string)($probe['audio_codec'] ?? '');
    if ($audio_codec !== '' && $audio_codec !== 'aac') {
        echo json_encode(['success' => false, 'message' => 'A videó audió kodekje nem támogatott (csak AAC)']);
        exit();
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Nem támogatott modul-tárhely típus']);
    exit();
}

try {
    $conn = getDbConnection();
    edudisplej_ensure_user_role_column($conn);

    $stmt = $conn->prepare("SELECT company_id FROM kiosk_groups WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $group_id);
    $stmt->execute();
    $group = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$group || (!$is_admin && (int)$group['company_id'] !== $company_id)) {
        echo json_encode(['success' => false, 'message' => 'Hozzáférés megtagadva']);
        exit();
    }

    edudisplej_module_asset_ensure_schema($conn);

    $paths = edudisplej_module_asset_storage_paths((int)$group['company_id'], $module_key);
    $storage_dir_abs = $paths['abs_dir'];
    if (!is_dir($storage_dir_abs)) {
        @mkdir($storage_dir_abs, 0775, true);
    }

    if (!is_dir($storage_dir_abs) || !is_writable($storage_dir_abs)) {
        echo json_encode(['success' => false, 'message' => 'A modul tárhely nem írható']);
        exit();
    }

    $target_filename = '';
    $target_abs = '';
    $target_rel = '';

    if ($module_key === 'pdf' || $module_key === 'video') {
        $random_part = bin2hex(random_bytes(8));
        $safe_name = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($original_name));
        if ($safe_name === '' || $safe_name === '.' || $safe_name === '..') {
            $safe_name = $module_key === 'video' ? 'video.mp4' : 'document.pdf';
        }

        $target_filename = date('Ymd_His') . '_' . $random_part . '_' . $safe_name;
        $target_abs = $storage_dir_abs . '/' . $target_filename;
        $target_rel = rtrim($paths['rel_dir'], '/\\') . '/' . $target_filename;

        if (!move_uploaded_file($tmp_path, $target_abs)) {
            echo json_encode(['success' => false, 'message' => 'A fájl mentése sikertelen']);
            exit();
        }
    } else {
        $compressed = edudisplej_compress_uploaded_image($tmp_path, $storage_dir_abs);
        $target_filename = $compressed['target_filename'];
        $target_abs = $compressed['target_abs'];
        $target_rel = rtrim($paths['rel_dir'], '/\\') . '/' . $target_filename;
    }

    $hash = hash_file('sha256', $target_abs) ?: '';
    $mime_type = strtolower((string)mime_content_type($target_abs));

    $group_company_id = (int)$group['company_id'];
    $insert = $conn->prepare("INSERT INTO module_asset_store (company_id, group_id, module_key, asset_kind, original_name, mime_type, file_size, storage_rel_path, sha256, created_by, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
    $insert->bind_param(
        "iissssissi",
        $group_company_id,
        $group_id,
        $module_key,
        $asset_kind,
        $original_name,
        $mime_type,
        $file_size,
        $target_rel,
        $hash,
        $user_id
    );
    $insert->execute();
    $asset_id = (int)$insert->insert_id;
    $insert->close();

    echo json_encode([
        'success' => true,
        'asset_id' => $asset_id,
        'asset_url' => edudisplej_module_asset_api_url_by_id($asset_id),
        'asset_kind' => $asset_kind,
        'module_key' => $module_key,
    ]);
} catch (Throwable $e) {
    error_log('module_asset_upload error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Szerver hiba történt a feltöltés során']);
}
