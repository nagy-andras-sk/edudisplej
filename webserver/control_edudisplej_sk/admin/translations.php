<?php
/**
 * Admin - Translation overrides editor
 */

session_start();
require_once '../dbkonfiguracia.php';
require_once '../i18n.php';

if (!isset($_SESSION['user_id']) || empty($_SESSION['isadmin'])) {
    header('Location: ../login.php');
    exit();
}

$current_lang = edudisplej_apply_language_preferences();
$supported_langs = edudisplej_get_supported_langs();
$selected_lang = edudisplej_normalize_lang($_GET['lang'] ?? '') ?: ($supported_langs[0] ?? 'hu');

$error = '';
$success = '';

function edudisplej_detect_csv_delimiter(string $headerLine): string {
    $candidates = [',', ';', "\t"];
    $best = ',';
    $bestScore = -1;
    foreach ($candidates as $delimiter) {
        $score = substr_count($headerLine, $delimiter);
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $delimiter;
        }
    }
    return $best;
}

function edudisplej_normalize_csv_header(string $value): string {
    $value = trim($value);
    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);
    return strtolower((string)$value);
}

if (($_GET['action'] ?? '') === 'export_csv') {
    $selected_lang = edudisplej_normalize_lang($_GET['lang'] ?? '') ?: $selected_lang;
    $base_catalog_export = edudisplej_get_translation_catalog($selected_lang, false);
    $overrides_export = edudisplej_load_lang_overrides($selected_lang);
    $merged_catalog_export = edudisplej_get_translation_catalog($selected_lang, true);
    ksort($base_catalog_export);

    $filename = 'translations_' . $selected_lang . '_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    if ($output !== false) {
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, ['key', 'base_text', 'override', 'effective']);
        foreach ($base_catalog_export as $key => $base_text) {
            $override_value = $overrides_export[$key] ?? '';
            $effective_value = $merged_catalog_export[$key] ?? (string)$base_text;
            fputcsv($output, [$key, (string)$base_text, (string)$override_value, (string)$effective_value]);
        }
        fclose($output);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_translations'])) {
    $selected_lang = edudisplej_normalize_lang($_POST['lang'] ?? '') ?: $selected_lang;
    $incoming = $_POST['translations'] ?? [];

    if (!is_array($incoming)) {
        $incoming = [];
    }

    if (!edudisplej_save_translation_overrides($selected_lang, $incoming)) {
        $error = 'Fordítások mentése sikertelen.';
    } else {
        $success = 'Fordítások mentve.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_csv'])) {
    $selected_lang = edudisplej_normalize_lang($_POST['lang'] ?? '') ?: $selected_lang;
    $upload = $_FILES['translations_csv'] ?? null;

    if (!$upload || !is_array($upload) || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $error = 'CSV import sikertelen: nincs feltöltött fájl vagy hibás feltöltés.';
    } else {
        $tmpPath = (string)($upload['tmp_name'] ?? '');
        $handle = @fopen($tmpPath, 'r');
        if ($tmpPath === '' || $handle === false) {
            $error = 'CSV import sikertelen: a fájl nem olvasható.';
        } else {
            $firstLine = fgets($handle);
            if ($firstLine === false) {
                fclose($handle);
                $error = 'CSV import sikertelen: üres fájl.';
            } else {
                $delimiter = edudisplej_detect_csv_delimiter($firstLine);
                rewind($handle);

                $header = fgetcsv($handle, 0, $delimiter);
                if (!is_array($header) || empty($header)) {
                    fclose($handle);
                    $error = 'CSV import sikertelen: hiányzó fejléc.';
                } else {
                    $headerMap = [];
                    foreach ($header as $index => $name) {
                        $headerMap[edudisplej_normalize_csv_header((string)$name)] = (int)$index;
                    }

                    $keyColumnCandidates = ['key', 'kulcs'];
                    $valueColumnCandidates = ['value', 'translation', 'override', 'text', 'forditas', 'feluliras'];

                    $keyIndex = null;
                    foreach ($keyColumnCandidates as $candidate) {
                        if (array_key_exists($candidate, $headerMap)) {
                            $keyIndex = $headerMap[$candidate];
                            break;
                        }
                    }

                    $valueIndex = null;
                    foreach ($valueColumnCandidates as $candidate) {
                        if (array_key_exists($candidate, $headerMap)) {
                            $valueIndex = $headerMap[$candidate];
                            break;
                        }
                    }

                    if ($keyIndex === null || $valueIndex === null) {
                        fclose($handle);
                        $error = 'CSV import sikertelen: kötelező oszlopok: key + value.';
                    } else {
                        $base_catalog_import = edudisplej_get_translation_catalog($selected_lang, false);
                        $next_values = isset($_POST['replace_missing']) ? [] : edudisplej_load_lang_overrides($selected_lang);

                        $imported = 0;
                        $skippedEmpty = 0;
                        $skippedUnknown = 0;

                        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                            $key = trim((string)($row[$keyIndex] ?? ''));
                            if ($key === '') {
                                $skippedEmpty++;
                                continue;
                            }
                            if (!array_key_exists($key, $base_catalog_import)) {
                                $skippedUnknown++;
                                continue;
                            }

                            $value = trim((string)($row[$valueIndex] ?? ''));
                            $next_values[$key] = $value;
                            $imported++;
                        }
                        fclose($handle);

                        if (!edudisplej_save_translation_overrides($selected_lang, $next_values)) {
                            $error = 'CSV import után a mentés sikertelen.';
                        } else {
                            $success = 'CSV import kész. Importált: ' . $imported
                                . ', üres kulcs kihagyva: ' . $skippedEmpty
                                . ', ismeretlen kulcs kihagyva: ' . $skippedUnknown . '.';
                        }
                    }
                }
            }
        }
    }
}

$base_catalog = edudisplej_get_translation_catalog($selected_lang, false);
$overrides = edudisplej_load_lang_overrides($selected_lang);
$merged_catalog = edudisplej_get_translation_catalog($selected_lang, true);
ksort($base_catalog);

$title = 'Translations';
require_once 'header.php';
?>

<h2 class="page-title">Fordítások kezelése</h2>

<?php if ($error): ?>
    <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="alert success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<div class="panel" style="margin-bottom:16px;">
    <form method="get" class="form-row">
        <div class="form-field" style="min-width:240px;">
            <label for="lang">Nyelv</label>
            <select id="lang" name="lang" onchange="this.form.submit()">
                <?php foreach ($supported_langs as $lang_code): ?>
                    <option value="<?php echo htmlspecialchars($lang_code); ?>" <?php echo $selected_lang === $lang_code ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars(strtoupper($lang_code) . ' - ' . t('lang.' . $lang_code)); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-field" style="align-self:flex-end;">
            <a class="btn btn-secondary" href="translations.php?action=export_csv&amp;lang=<?php echo urlencode($selected_lang); ?>">CSV export</a>
        </div>
    </form>

    <form method="post" enctype="multipart/form-data" class="form-row" style="margin-top:12px; align-items:flex-end;">
        <input type="hidden" name="lang" value="<?php echo htmlspecialchars($selected_lang); ?>">
        <div class="form-field" style="min-width:320px;">
            <label for="translations_csv">CSV import</label>
            <input id="translations_csv" type="file" name="translations_csv" accept=".csv,text/csv" required>
        </div>
        <div class="form-field" style="min-width:220px;">
            <label style="display:flex; align-items:center; gap:8px; margin-top:26px;">
                <input type="checkbox" name="replace_missing" value="1">
                Csak CSV maradjon (hiányzó kulcsok alapértékre)
            </label>
        </div>
        <div class="form-field">
            <button type="submit" name="import_csv" class="btn btn-primary">CSV import</button>
        </div>
    </form>
    <div class="muted" style="margin-top:8px;">Elvárt oszlopok: <span class="mono">key,value</span> (a fejléc neve lehet <span class="mono">translation/override/text</span> is).</div>
</div>

<div class="panel">
    <div class="panel-title">Kulcsok (<?php echo (int)count($base_catalog); ?>)</div>

    <form method="post">
        <input type="hidden" name="lang" value="<?php echo htmlspecialchars($selected_lang); ?>">

        <div class="table-wrap" style="max-height:70vh; overflow:auto;">
            <table>
                <thead>
                    <tr>
                        <th style="width:28%;">Kulcs</th>
                        <th style="width:32%;">Alap szöveg</th>
                        <th style="width:32%;">Felülírás</th>
                        <th style="width:8%;">Aktív</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($base_catalog as $key => $base_text): ?>
                    <?php
                    $override_value = $overrides[$key] ?? '';
                    $effective_value = $merged_catalog[$key] ?? (string)$base_text;
                    $is_overridden = $override_value !== '';
                    ?>
                    <tr>
                        <td class="mono"><?php echo htmlspecialchars($key); ?></td>
                        <td><?php echo htmlspecialchars((string)$base_text); ?></td>
                        <td>
                            <input
                                type="text"
                                name="translations[<?php echo htmlspecialchars($key); ?>]"
                                value="<?php echo htmlspecialchars($override_value !== '' ? $override_value : $effective_value); ?>"
                                style="width:100%;"
                            >
                        </td>
                        <td class="nowrap"><?php echo $is_overridden ? 'Igen' : 'Nem'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div style="margin-top:14px; display:flex; gap:10px;">
            <button type="submit" name="save_translations" class="btn btn-primary">Mentés</button>
            <a href="translations.php?lang=<?php echo urlencode($selected_lang); ?>" class="btn btn-secondary">Visszaállítás (űrlap)</a>
        </div>
    </form>
</div>

<?php require_once 'footer.php'; ?>
