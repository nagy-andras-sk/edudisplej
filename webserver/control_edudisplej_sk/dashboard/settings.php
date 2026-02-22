<?php
/**
 * User Settings
 * Currently: personal language preference.
 */

session_start();
require_once '../dbkonfiguracia.php';
require_once '../i18n.php';
require_once '../auth_roles.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

$current_lang = edudisplej_apply_language_preferences();
$supported_langs = edudisplej_get_supported_langs();
$user_lang = edudisplej_get_lang();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_language'])) {
    $new_lang = edudisplej_normalize_lang($_POST['lang'] ?? '');
    if (!$new_lang) {
        $error = t_def('settings.language.invalid', 'Érvénytelen nyelv.');
    } else {
        edudisplej_set_lang($new_lang, true);
        $user_lang = $new_lang;
        $success = t_def('settings.language.saved', 'Nyelv sikeresen mentve.');
    }
}

$logout_url = '../login.php?logout=1';
$breadcrumb_items = [
    ['label' => '⚙️ ' . t('nav.settings'), 'current' => true],
];

include '../admin/header.php';
?>

<?php if ($error): ?>
    <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<div class="panel" style="max-width:680px;">
    <div class="panel-title"><?php echo htmlspecialchars(t('nav.settings')); ?></div>

    <form method="post" class="form-row">
        <div class="form-field" style="min-width:260px;">
            <label for="lang"><?php echo htmlspecialchars(t_def('settings.language.label', 'Nyelv / Language / Jazyk')); ?></label>
            <select id="lang" name="lang">
                <?php foreach ($supported_langs as $lang_code): ?>
                    <option value="<?php echo htmlspecialchars($lang_code); ?>" <?php echo $user_lang === $lang_code ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars(t('lang.' . $lang_code)); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-field">
            <button type="submit" name="save_language" class="btn btn-primary"><?php echo htmlspecialchars(t_def('common.save', 'Mentés')); ?></button>
        </div>
    </form>
</div>

<?php include '../admin/footer.php'; ?>
