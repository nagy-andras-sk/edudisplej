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
    </form>
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
