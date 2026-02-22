<?php
session_start();
require_once '../auth_roles.php';
require_once '../i18n.php';
require_once '../dbkonfiguracia.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

if (!edudisplej_can_edit_module_content()) {
    header('Location: index.php');
    exit();
}

function edudisplej_dashboard_has_module_license(string $module_key): bool {
    $module_key = strtolower(trim($module_key));
    if ($module_key === '') {
        return false;
    }

    if (!empty($_SESSION['isadmin']) && empty($_SESSION['admin_acting_company_id'])) {
        return true;
    }

    $company_id = (int)($_SESSION['admin_acting_company_id'] ?? 0);
    if ($company_id <= 0) {
        $company_id = (int)($_SESSION['company_id'] ?? 0);
    }
    if ($company_id <= 0) {
        return false;
    }

    try {
        $conn = getDbConnection();
        $stmt = $conn->prepare("SELECT m.id
                                FROM modules m
                                INNER JOIN module_licenses ml ON ml.module_id = m.id
                                WHERE m.module_key = ? AND m.is_active = 1
                                  AND ml.company_id = ? AND ml.quantity > 0
                                LIMIT 1");
        $stmt->bind_param('si', $module_key, $company_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        closeDbConnection($conn);
        return !empty($row);
    } catch (Throwable $e) {
        error_log('text_collection_meal_calendar license check failed: ' . $e->getMessage());
        return false;
    }
}

if (!edudisplej_dashboard_has_module_license('meal-menu')) {
    header('Location: index.php');
    exit();
}

$breadcrumb_items = [
    ['label' => '🍽️ ' . t_def('meal.title', 'Étrend'), 'href' => 'text_collection_meal_calendar.php'],
    ['label' => '🍽️ ' . t_def('meal.calendar.title', 'Manuális étrend naptár'), 'current' => true],
];
$logout_url = '../login.php?logout=1';

$meal_calendar_i18n = [
    'statusSaved' => t_def('meal.calendar.status.saved', 'Mentve'),
    'statusDeleted' => t_def('meal.calendar.status.deleted', 'Törölve'),
    'errorDelete' => t_def('meal.calendar.error.delete', 'Törlési hiba'),
    'errorGeneral' => t_def('meal.calendar.error.general', 'Hiba'),
    'errorLoad' => t_def('meal.calendar.error.load', 'Betöltési hiba'),
    'emptyData' => t_def('meal.calendar.empty', 'Nincs adat'),
    'confirmDelete' => t_def('meal.calendar.confirm.delete', 'Biztosan törlöd?'),
    'actionEditShort' => t_def('meal.calendar.action.edit_short', 'Szerk.'),
    'actionDelete' => t_def('common.delete', 'Törlés'),
];
include '../admin/header.php';
?>

<div class="panel" style="margin-bottom:12px;">
    <div class="panel-title"><?php echo htmlspecialchars(t_def('meal.calendar.panel.title', 'Manuális étrend naptár (slide gyűjtemény)')); ?></div>
    <div class="muted"><?php echo htmlspecialchars(t_def('meal.calendar.panel.desc', 'Tartalomszerkesztő és végfelhasználó előre töltheti a napi menüket dátum szerint.')); ?></div>
</div>

<div style="display:grid; grid-template-columns: 1.1fr 1fr; gap:12px; align-items:start;">
    <div class="panel">
        <div class="panel-title"><?php echo htmlspecialchars(t_def('meal.calendar.entry.title', 'Napi bejegyzés')); ?></div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:10px;">
            <input type="date" id="mc-date" style="padding:8px; border:1px solid #d1d5db; border-radius:6px;">
            <input type="text" id="mc-institution" placeholder="<?php echo htmlspecialchars(t_def('meal.calendar.field.institution', 'Intézmény megnevezés')); ?>" style="padding:8px; border:1px solid #d1d5db; border-radius:6px;">
        </div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
            <textarea id="mc-breakfast" rows="3" placeholder="<?php echo htmlspecialchars(t_def('meal.type.breakfast', 'Reggeli')); ?>" style="padding:8px; border:1px solid #d1d5db; border-radius:6px;"></textarea>
            <textarea id="mc-snack-am" rows="3" placeholder="<?php echo htmlspecialchars(t_def('meal.type.snack_am', 'Tízórai')); ?>" style="padding:8px; border:1px solid #d1d5db; border-radius:6px;"></textarea>
            <textarea id="mc-lunch" rows="3" placeholder="<?php echo htmlspecialchars(t_def('meal.type.lunch', 'Ebéd')); ?>" style="padding:8px; border:1px solid #d1d5db; border-radius:6px;"></textarea>
            <textarea id="mc-snack-pm" rows="3" placeholder="<?php echo htmlspecialchars(t_def('meal.type.snack_pm', 'Uzsonna')); ?>" style="padding:8px; border:1px solid #d1d5db; border-radius:6px;"></textarea>
            <textarea id="mc-dinner" rows="3" placeholder="<?php echo htmlspecialchars(t_def('meal.type.dinner', 'Vacsora')); ?>" style="grid-column:1/-1; padding:8px; border:1px solid #d1d5db; border-radius:6px;"></textarea>
            <textarea id="mc-note" rows="2" placeholder="<?php echo htmlspecialchars(t_def('common.note_optional', 'Megjegyzés (opcionális)')); ?>" style="grid-column:1/-1; padding:8px; border:1px solid #d1d5db; border-radius:6px;"></textarea>
        </div>
        <div style="display:flex; gap:8px; margin-top:10px;"><button class="btn btn-primary" id="mc-save"><?php echo htmlspecialchars(t_def('common.save', 'Mentés')); ?></button><button class="btn" id="mc-new"><?php echo htmlspecialchars(t_def('common.new', 'Új')); ?></button></div>
        <div id="mc-status" class="muted" style="margin-top:8px;"></div>
    </div>

    <div class="panel">
        <div class="panel-title"><?php echo htmlspecialchars(t_def('meal.calendar.list.title', 'Naptár lista')); ?></div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:8px;">
            <input type="date" id="mc-from" style="padding:8px; border:1px solid #d1d5db; border-radius:6px;">
            <input type="date" id="mc-to" style="padding:8px; border:1px solid #d1d5db; border-radius:6px;">
        </div>
        <div style="margin-bottom:8px;"><button class="btn" id="mc-refresh"><?php echo htmlspecialchars(t_def('common.refresh', 'Frissítés')); ?></button></div>
        <div class="table-wrap"><table><thead><tr><th><?php echo htmlspecialchars(t_def('common.date', 'Dátum')); ?></th><th><?php echo htmlspecialchars(t_def('common.institution', 'Intézmény')); ?></th><th><?php echo htmlspecialchars(t_def('common.action', 'Művelet')); ?></th></tr></thead><tbody id="mc-body"></tbody></table></div>
    </div>
</div>

<script>
(function () {
    const api = '../api/text_collection_meal_calendar.php';
    const I18N = <?php echo json_encode($meal_calendar_i18n, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    let items = [];

    const $ = (id) => document.getElementById(id);
    const today = new Date();
    const first = new Date(today.getFullYear(), today.getMonth(), 1).toISOString().slice(0, 10);
    const last = new Date(today.getFullYear(), today.getMonth() + 1, 0).toISOString().slice(0, 10);
    $('mc-date').value = today.toISOString().slice(0, 10);
    $('mc-from').value = first;
    $('mc-to').value = last;

    function setStatus(text, isErr) {
        const el = $('mc-status');
        el.textContent = text || '';
        el.style.color = isErr ? '#b42318' : '#475467';
    }

    function clearForm() {
        $('mc-date').value = today.toISOString().slice(0, 10);
        $('mc-institution').value = '';
        $('mc-breakfast').value = '';
        $('mc-snack-am').value = '';
        $('mc-lunch').value = '';
        $('mc-snack-pm').value = '';
        $('mc-dinner').value = '';
        $('mc-note').value = '';
    }

    async function fetchJson(url, opts) {
        const r = await fetch(url, opts);
        const j = await r.json();
        if (!r.ok || !j.success) throw new Error(j.message || I18N.errorGeneral);
        return j;
    }

    async function loadItems() {
        const from = $('mc-from').value;
        const to = $('mc-to').value;
        const data = await fetchJson(`${api}?action=list&from_date=${encodeURIComponent(from)}&to_date=${encodeURIComponent(to)}`);
        items = Array.isArray(data.items) ? data.items : [];

        const body = $('mc-body');
        body.innerHTML = items.map((x) => `<tr><td>${x.menu_date}</td><td>${x.institution_label}</td><td><button class="btn btn-small" data-edit="${x.id}">${I18N.actionEditShort}</button> <button class="btn btn-small btn-danger" data-del="${x.id}">${I18N.actionDelete}</button></td></tr>`).join('') || `<tr><td colspan="3" class="muted">${I18N.emptyData}</td></tr>`;

        body.querySelectorAll('[data-edit]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const id = parseInt(btn.getAttribute('data-edit') || '0', 10);
                const found = items.find((x) => Number(x.id) === id);
                if (!found) return;
                $('mc-date').value = found.menu_date || '';
                $('mc-institution').value = found.institution_label || '';
                $('mc-breakfast').value = found.breakfast || '';
                $('mc-snack-am').value = found.snack_am || '';
                $('mc-lunch').value = found.lunch || '';
                $('mc-snack-pm').value = found.snack_pm || '';
                $('mc-dinner').value = found.dinner || '';
                $('mc-note').value = found.note_text || '';
            });
        });

        body.querySelectorAll('[data-del]').forEach((btn) => {
            btn.addEventListener('click', async () => {
                const id = parseInt(btn.getAttribute('data-del') || '0', 10);
                if (!id) return;
                if (!window.confirm(I18N.confirmDelete)) return;
                try {
                    await fetchJson(`${api}?action=delete`, {
                        method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ id })
                    });
                    await loadItems();
                    setStatus(I18N.statusDeleted, false);
                } catch (error) {
                    setStatus(error.message || I18N.errorDelete, true);
                }
            });
        });
    }

    async function saveItem() {
        await fetchJson(`${api}?action=save`, {
            method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                menu_date: $('mc-date').value,
                institution_label: $('mc-institution').value,
                breakfast: $('mc-breakfast').value,
                snack_am: $('mc-snack-am').value,
                lunch: $('mc-lunch').value,
                snack_pm: $('mc-snack-pm').value,
                dinner: $('mc-dinner').value,
                note_text: $('mc-note').value
            })
        });
        await loadItems();
        setStatus(I18N.statusSaved, false);
    }

    $('mc-save').addEventListener('click', () => saveItem().catch((e) => setStatus(e.message, true)));
    $('mc-new').addEventListener('click', clearForm);
    $('mc-refresh').addEventListener('click', () => loadItems().catch((e) => setStatus(e.message, true)));

    loadItems().catch((e) => setStatus(e.message || I18N.errorLoad, true));
})();
</script>

<?php include '../admin/footer.php'; ?>
