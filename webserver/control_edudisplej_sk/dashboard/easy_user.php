<?php
session_start();
require_once '../dbkonfiguracia.php';
require_once '../i18n.php';
require_once '../kiosk_status.php';
require_once '../auth_roles.php';
require_once __DIR__ . '/dashboard_helpers.php';

$current_lang = edudisplej_apply_language_preferences();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

if (!empty($_SESSION['isadmin'])) {
    header('Location: ../admin/dashboard.php');
    exit();
}

$session_role = edudisplej_get_session_role();
if ($session_role !== 'easy_user') {
    header('Location: index.php');
    exit();
}

$company_id = (int)($_SESSION['company_id'] ?? 0);
if ($company_id <= 0) {
    header('Location: ../login.php');
    exit();
}

$groups = [];
$kiosks_by_group = [];
$module_catalog = [
    'text' => ['id' => 0, 'name' => 'Text'],
    'image-gallery' => ['id' => 0, 'name' => 'Képgaléria'],
    'pdf' => ['id' => 0, 'name' => 'PDF'],
];
$error = '';

try {
    $conn = getDbConnection();
    edudisplej_ensure_user_role_column($conn);

    $stmt = $conn->prepare("SELECT id, name FROM kiosk_groups WHERE company_id = ? ORDER BY priority DESC, name ASC");
    $stmt->bind_param('i', $company_id);
    $stmt->execute();
    $group_result = $stmt->get_result();
    while ($row = $group_result->fetch_assoc()) {
        $gid = (int)$row['id'];
        $groups[] = [
            'id' => $gid,
            'name' => (string)$row['name'],
        ];
        $kiosks_by_group[$gid] = [];
    }
    $stmt->close();

    if (!empty($groups)) {
        $stmt = $conn->prepare("SELECT k.id, k.hostname, k.friendly_name, k.status, k.location, kga.group_id
                                FROM kiosks k
                                INNER JOIN kiosk_group_assignments kga ON kga.kiosk_id = k.id
                                WHERE k.company_id = ?
                                ORDER BY COALESCE(NULLIF(k.friendly_name, ''), k.hostname)");
        $stmt->bind_param('i', $company_id);
        $stmt->execute();
        $kiosk_result = $stmt->get_result();
        while ($row = $kiosk_result->fetch_assoc()) {
            $gid = (int)($row['group_id'] ?? 0);
            if ($gid <= 0 || !array_key_exists($gid, $kiosks_by_group)) {
                continue;
            }

            kiosk_apply_effective_status($row);
            $display_name = trim((string)($row['friendly_name'] ?? ''));
            if ($display_name === '') {
                $display_name = (string)($row['hostname'] ?? ('Kiosk #' . (int)$row['id']));
            }

            $kiosks_by_group[$gid][] = [
                'id' => (int)$row['id'],
                'name' => $display_name,
                'status' => (string)($row['status'] ?? 'offline'),
                'location' => (string)($row['location'] ?? ''),
            ];
        }
        $stmt->close();
    }

    $mod_stmt = $conn->prepare("SELECT id, module_key, name FROM modules WHERE module_key IN ('text', 'image-gallery', 'pdf') AND is_active = 1");
    $mod_stmt->execute();
    $mod_result = $mod_stmt->get_result();
    while ($mod = $mod_result->fetch_assoc()) {
        $key = strtolower(trim((string)($mod['module_key'] ?? '')));
        if (!isset($module_catalog[$key])) {
            continue;
        }
        $module_catalog[$key] = [
            'id' => (int)$mod['id'],
            'name' => (string)($mod['name'] ?? strtoupper($key)),
        ];
    }
    $mod_stmt->close();

    closeDbConnection($conn);
} catch (Throwable $e) {
    $error = 'Nem sikerült betölteni az egyszerű dashboard adatokat.';
    error_log('easy_user.php: ' . $e->getMessage());
}

include '../admin/header.php';
?>

<div class="panel" style="margin-bottom:12px;">
    <div class="panel-title">Egyszerű kezelő</div>
    <div class="muted">Csoportonként látod a kijelzőket, az aktív tervet és az alap tartalom szerkesztést.</div>
</div>

<?php if ($error !== ''): ?>
    <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<?php if (empty($groups)): ?>
    <div class="panel">
        <div class="muted">Nincs csoport ehhez az intézményhez.</div>
    </div>
<?php else: ?>
    <?php foreach ($groups as $group): ?>
        <?php $gid = (int)$group['id']; ?>
        <div class="panel easy-group-card" data-group-id="<?php echo $gid; ?>" style="margin-bottom:14px;">
            <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
                <div>
                    <div class="panel-title" style="margin:0;"><?php echo htmlspecialchars($group['name']); ?></div>
                    <div class="muted" id="easy-plan-summary-<?php echo $gid; ?>">Terv betöltése…</div>
                </div>
                <div class="muted" id="easy-save-state-<?php echo $gid; ?>"></div>
            </div>

            <div style="margin-top:10px;">
                <strong>Kijelzők</strong>
                <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:6px;">
                    <?php if (empty($kiosks_by_group[$gid])): ?>
                        <span class="muted">Nincs hozzárendelt kijelző.</span>
                    <?php else: ?>
                        <?php foreach ($kiosks_by_group[$gid] as $kiosk): ?>
                            <?php $online = in_array($kiosk['status'], ['online', 'online_error'], true); ?>
                            <span class="status-badge status-<?php echo htmlspecialchars($kiosk['status']); ?>" style="display:inline-flex; gap:6px; align-items:center;">
                                <span><?php echo $online ? '🟢' : '🔴'; ?></span>
                                <span><?php echo htmlspecialchars($kiosk['name']); ?></span>
                            </span>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div style="margin-top:12px;">
                <strong>Ütemezés (mi lesz megjelenítve mikor)</strong>
                <div id="easy-schedule-<?php echo $gid; ?>" class="muted" style="margin-top:6px;">Betöltés…</div>
            </div>

            <div style="margin-top:12px; border-top:1px solid #e5e7eb; padding-top:12px;">
                <strong>Gyors szerkesztés</strong>
                <div class="muted" style="margin-top:2px;">Csak azok a modulok látszanak, amik ütemezve vannak.</div>

                <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:8px;">
                    <button type="button" class="btn" data-action="open-text" data-group-id="<?php echo $gid; ?>" style="display:none;">Szöveg szerkesztése</button>
                    <button type="button" class="btn" data-action="open-gallery" data-group-id="<?php echo $gid; ?>" style="display:none;">Képgaléria szerkesztése</button>
                    <button type="button" class="btn" data-action="open-pdf" data-group-id="<?php echo $gid; ?>" style="display:none;">PDF szerkesztése</button>
                </div>

                <div id="easy-text-editor-<?php echo $gid; ?>" style="display:none; margin-top:10px;">
                    <label for="easy-text-<?php echo $gid; ?>" style="display:block; font-weight:600; margin-bottom:4px;">Szöveg tartalom</label>
                    <textarea id="easy-text-<?php echo $gid; ?>" rows="6" style="width:100%;"></textarea>
                    <div style="margin-top:8px;">
                        <button type="button" class="btn btn-primary" data-action="save-text" data-group-id="<?php echo $gid; ?>">Mentés</button>
                    </div>
                </div>

                <div id="easy-gallery-editor-<?php echo $gid; ?>" style="display:none; margin-top:10px;">
                    <label for="easy-gallery-urls-<?php echo $gid; ?>" style="display:block; font-weight:600; margin-bottom:4px;">Kép URL-ek (soronként 1)</label>
                    <textarea id="easy-gallery-urls-<?php echo $gid; ?>" rows="6" style="width:100%;"></textarea>
                    <div style="margin-top:8px; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                        <input type="file" id="easy-gallery-upload-<?php echo $gid; ?>" accept="image/*" multiple>
                        <button type="button" class="btn" data-action="upload-gallery" data-group-id="<?php echo $gid; ?>">Feltöltés és hozzáadás</button>
                        <button type="button" class="btn btn-primary" data-action="save-gallery" data-group-id="<?php echo $gid; ?>">Mentés</button>
                    </div>
                </div>

                <div id="easy-pdf-editor-<?php echo $gid; ?>" style="display:none; margin-top:10px;">
                    <div class="muted" id="easy-pdf-current-<?php echo $gid; ?>">Nincs PDF kiválasztva.</div>
                    <div style="margin-top:8px; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                        <input type="file" id="easy-pdf-upload-<?php echo $gid; ?>" accept="application/pdf">
                        <button type="button" class="btn btn-primary" data-action="save-pdf" data-group-id="<?php echo $gid; ?>">PDF feltöltés és mentés</button>
                    </div>
                </div>
            </div>

            <div style="margin-top:12px; border-top:1px solid #e5e7eb; padding-top:12px;">
                <strong>Azonnali szöveg kiírás (SOS)</strong>
                <div class="muted" style="margin-top:2px;">Speciális loopot hoz létre, ami az adott időablakban felülírja az aktuális tartalmat.</div>

                <label for="easy-sos-text-<?php echo $gid; ?>" style="display:block; margin-top:8px;">Szöveg</label>
                <textarea id="easy-sos-text-<?php echo $gid; ?>" rows="4" style="width:100%;"></textarea>

                <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:8px; margin-top:8px;">
                    <div>
                        <label for="easy-sos-start-<?php echo $gid; ?>">Kezdés</label>
                        <input type="datetime-local" id="easy-sos-start-<?php echo $gid; ?>" style="width:100%;">
                    </div>
                    <div>
                        <label for="easy-sos-end-<?php echo $gid; ?>">Vége</label>
                        <input type="datetime-local" id="easy-sos-end-<?php echo $gid; ?>" style="width:100%;">
                    </div>
                </div>

                <label style="display:flex; align-items:center; gap:8px; margin-top:8px;">
                    <input type="checkbox" id="easy-sos-show-datetime-<?php echo $gid; ?>" checked>
                    <span>Dátum/óra megjelenítése a szövegben</span>
                </label>

                <div style="margin-top:8px;">
                    <button type="button" class="btn btn-primary" data-action="save-sos" data-group-id="<?php echo $gid; ?>">SOS ütemezés mentése</button>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<script>
(() => {
    'use strict';

    const bootstrap = {
        moduleCatalog: <?php echo json_encode($module_catalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        groups: <?php echo json_encode(array_map(static fn($g) => ['id' => (int)$g['id'], 'name' => (string)$g['name']], $groups), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
    };

    const planState = new Map();

    const normalizeKey = (value) => String(value || '').trim().toLowerCase();

    const escapeHtml = (value) => String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/\"/g, '&quot;')
        .replace(/'/g, '&#39;');

    const toLocalDateTimeInputValue = (dateObj) => {
        const pad = (n) => String(n).padStart(2, '0');
        return `${dateObj.getFullYear()}-${pad(dateObj.getMonth() + 1)}-${pad(dateObj.getDate())}T${pad(dateObj.getHours())}:${pad(dateObj.getMinutes())}`;
    };

    const parseLocalDateTime = (raw) => {
        const value = String(raw || '').trim();
        if (!value) {
            return null;
        }
        const dt = new Date(value);
        return Number.isNaN(dt.getTime()) ? null : dt;
    };

    const getPayloadFromPlan = (plan) => ({
        base_loop: Array.isArray(plan.base_loop) ? plan.base_loop : [],
        time_blocks: Array.isArray(plan.time_blocks) ? plan.time_blocks : [],
        loop_styles: Array.isArray(plan.loop_styles) ? plan.loop_styles : [],
        default_loop_style_id: parseInt(plan.default_loop_style_id || 0, 10) || 0,
        schedule_blocks: Array.isArray(plan.schedule_blocks) ? plan.schedule_blocks : []
    });

    const getAllLoopItems = (plan) => {
        const items = [];
        if (Array.isArray(plan.loop_styles) && plan.loop_styles.length > 0) {
            plan.loop_styles.forEach((style) => {
                (Array.isArray(style.items) ? style.items : []).forEach((item) => items.push(item));
            });
            return items;
        }

        (Array.isArray(plan.base_loop) ? plan.base_loop : []).forEach((item) => items.push(item));
        (Array.isArray(plan.time_blocks) ? plan.time_blocks : []).forEach((block) => {
            (Array.isArray(block.loops) ? block.loops : []).forEach((item) => items.push(item));
        });
        return items;
    };

    const forEachModuleItem = (plan, moduleKey, handler) => {
        let touched = 0;
        const wanted = normalizeKey(moduleKey);

        const process = (item) => {
            if (!item || normalizeKey(item.module_key) !== wanted) {
                return;
            }
            item.settings = (item.settings && typeof item.settings === 'object') ? item.settings : {};
            handler(item);
            touched += 1;
        };

        if (Array.isArray(plan.loop_styles) && plan.loop_styles.length > 0) {
            plan.loop_styles.forEach((style) => {
                (Array.isArray(style.items) ? style.items : []).forEach(process);
            });
            return touched;
        }

        (Array.isArray(plan.base_loop) ? plan.base_loop : []).forEach(process);
        (Array.isArray(plan.time_blocks) ? plan.time_blocks : []).forEach((block) => {
            (Array.isArray(block.loops) ? block.loops : []).forEach(process);
        });

        return touched;
    };

    const styleNameMap = (plan) => {
        const map = new Map();
        (Array.isArray(plan.loop_styles) ? plan.loop_styles : []).forEach((style) => {
            const id = parseInt(style.id || 0, 10);
            if (id > 0) {
                map.set(id, String(style.name || `Loop #${id}`));
            }
        });
        return map;
    };

    const dayShort = {
        1: 'H', 2: 'K', 3: 'Sze', 4: 'Cs', 5: 'P', 6: 'Szo', 7: 'V'
    };

    const formatScheduleHtml = (plan) => {
        const blocks = Array.isArray(plan.schedule_blocks) ? plan.schedule_blocks : [];
        if (blocks.length === 0) {
            return '<span class="muted">Nincs időzített blokk. A default loop megy egész nap.</span>';
        }

        const styleMap = styleNameMap(plan);
        const rows = blocks.slice().sort((a, b) => {
            const pa = parseInt(a.priority || 0, 10);
            const pb = parseInt(b.priority || 0, 10);
            if (pa !== pb) return pb - pa;
            return parseInt(a.id || 0, 10) - parseInt(b.id || 0, 10);
        }).map((block) => {
            const type = normalizeKey(block.block_type) === 'date' ? 'date' : 'weekly';
            const start = String(block.start_time || '00:00:00').slice(0, 5);
            const end = String(block.end_time || '00:00:00').slice(0, 5);
            const styleId = parseInt(block.loop_style_id || 0, 10);
            const styleLabel = escapeHtml(styleMap.get(styleId) || `Loop #${styleId || 0}`);
            let when = '';
            if (type === 'date') {
                when = escapeHtml(String(block.specific_date || '—'));
            } else {
                const labels = String(block.days_mask || '1,2,3,4,5,6,7')
                    .split(',')
                    .map((part) => parseInt(part.trim(), 10))
                    .filter((n) => n >= 1 && n <= 7)
                    .map((n) => dayShort[n] || '?');
                when = escapeHtml(labels.join(','));
            }

            return `<tr><td>${when}</td><td>${escapeHtml(start)}-${escapeHtml(end)}</td><td>${styleLabel}</td></tr>`;
        });

        return `
            <table style="width:100%; border-collapse:collapse; margin-top:6px;">
                <thead><tr><th style="text-align:left;">Mikor</th><th style="text-align:left;">Idő</th><th style="text-align:left;">Loop</th></tr></thead>
                <tbody>${rows.join('')}</tbody>
            </table>
        `;
    };

    const setSaveState = (groupId, message, isError = false) => {
        const el = document.getElementById(`easy-save-state-${groupId}`);
        if (!el) return;
        el.textContent = message;
        el.style.color = isError ? 'var(--danger, #b91c1c)' : '#6b7280';
    };

    const apiGetPlan = async (groupId) => {
        const response = await fetch(`../api/group_loop/config.php?group_id=${encodeURIComponent(String(groupId))}`, {
            credentials: 'same-origin'
        });
        const data = await response.json();
        if (!response.ok || !data?.success) {
            throw new Error(data?.message || `HTTP ${response.status}`);
        }
        return data;
    };

    const apiSavePlan = async (groupId, plan) => {
        const payload = getPayloadFromPlan(plan);
        const response = await fetch(`../api/group_loop/config.php?group_id=${encodeURIComponent(String(groupId))}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(payload)
        });
        const data = await response.json();
        if (!response.ok || !data?.success) {
            throw new Error(data?.message || `HTTP ${response.status}`);
        }
        return data;
    };

    const detectScheduledModules = (plan) => {
        const found = new Set();
        getAllLoopItems(plan).forEach((item) => found.add(normalizeKey(item.module_key)));
        return {
            text: found.has('text'),
            gallery: found.has('image-gallery'),
            pdf: found.has('pdf')
        };
    };

    const refreshEditorButtons = (groupId, plan) => {
        const found = detectScheduledModules(plan);
        const card = document.querySelector(`.easy-group-card[data-group-id="${groupId}"]`);
        if (!card) return;

        const textBtn = card.querySelector('[data-action="open-text"]');
        const galleryBtn = card.querySelector('[data-action="open-gallery"]');
        const pdfBtn = card.querySelector('[data-action="open-pdf"]');
        if (textBtn) textBtn.style.display = found.text ? 'inline-block' : 'none';
        if (galleryBtn) galleryBtn.style.display = found.gallery ? 'inline-block' : 'none';
        if (pdfBtn) pdfBtn.style.display = found.pdf ? 'inline-block' : 'none';

        const textEditor = document.getElementById(`easy-text-editor-${groupId}`);
        const galleryEditor = document.getElementById(`easy-gallery-editor-${groupId}`);
        const pdfEditor = document.getElementById(`easy-pdf-editor-${groupId}`);
        if (!found.text && textEditor) textEditor.style.display = 'none';
        if (!found.gallery && galleryEditor) galleryEditor.style.display = 'none';
        if (!found.pdf && pdfEditor) pdfEditor.style.display = 'none';
    };

    const fillEditorValues = (groupId, plan) => {
        const textArea = document.getElementById(`easy-text-${groupId}`);
        const galleryArea = document.getElementById(`easy-gallery-urls-${groupId}`);
        const pdfCurrent = document.getElementById(`easy-pdf-current-${groupId}`);

        let firstText = '';
        let firstGalleryUrls = [];
        let firstPdfUrl = '';

        getAllLoopItems(plan).forEach((item) => {
            const key = normalizeKey(item.module_key);
            const settings = (item.settings && typeof item.settings === 'object') ? item.settings : {};
            if (!firstText && key === 'text') {
                firstText = String(settings.text || '')
                    .replace(/<br\s*\/?\s*>/gi, '\n')
                    .replace(/<[^>]*>/g, '');
            }
            if (firstGalleryUrls.length === 0 && key === 'image-gallery') {
                try {
                    const parsed = JSON.parse(String(settings.imageUrlsJson || '[]'));
                    if (Array.isArray(parsed)) {
                        firstGalleryUrls = parsed.map((u) => String(u || '').trim()).filter(Boolean);
                    }
                } catch (_) {
                    firstGalleryUrls = [];
                }
            }
            if (!firstPdfUrl && key === 'pdf') {
                firstPdfUrl = String(settings.pdfAssetUrl || '');
            }
        });

        if (textArea) textArea.value = firstText;
        if (galleryArea) galleryArea.value = firstGalleryUrls.join('\n');
        if (pdfCurrent) pdfCurrent.textContent = firstPdfUrl ? `Aktuális PDF: ${firstPdfUrl}` : 'Nincs PDF kiválasztva.';
    };

    const loadGroup = async (groupId) => {
        try {
            setSaveState(groupId, 'Betöltés…');
            const plan = await apiGetPlan(groupId);
            planState.set(groupId, plan);

            const scheduleEl = document.getElementById(`easy-schedule-${groupId}`);
            if (scheduleEl) {
                scheduleEl.innerHTML = formatScheduleHtml(plan);
            }

            const summaryEl = document.getElementById(`easy-plan-summary-${groupId}`);
            if (summaryEl) {
                const mods = detectScheduledModules(plan);
                const labels = [];
                if (mods.text) labels.push('szöveg');
                if (mods.gallery) labels.push('képgaléria');
                if (mods.pdf) labels.push('pdf');
                summaryEl.textContent = labels.length > 0
                    ? `Ütemezett szerkeszthető modulok: ${labels.join(', ')}`
                    : 'Nincs ütemezett text/képgaléria/pdf modul.';
            }

            refreshEditorButtons(groupId, plan);
            fillEditorValues(groupId, plan);
            setSaveState(groupId, '');

            const startInput = document.getElementById(`easy-sos-start-${groupId}`);
            const endInput = document.getElementById(`easy-sos-end-${groupId}`);
            if (startInput && endInput && !startInput.value && !endInput.value) {
                const now = new Date();
                const end = new Date(now.getTime() + (60 * 60 * 1000));
                startInput.value = toLocalDateTimeInputValue(now);
                endInput.value = toLocalDateTimeInputValue(end);
            }
        } catch (error) {
            setSaveState(groupId, `Hiba: ${error?.message || 'ismeretlen hiba'}`, true);
        }
    };

    const saveText = async (groupId) => {
        const plan = planState.get(groupId);
        if (!plan) {
            throw new Error('Nincs betöltött terv ehhez a csoporthoz.');
        }

        const source = document.getElementById(`easy-text-${groupId}`)?.value || '';
        const html = escapeHtml(source).replace(/\n/g, '<br>');

        const touched = forEachModuleItem(plan, 'text', (item) => {
            item.settings.text = html;
        });

        if (touched === 0) {
            throw new Error('Nincs ütemezett szöveg modul ebben a csoportban.');
        }

        await apiSavePlan(groupId, plan);
        await loadGroup(groupId);
    };

    const saveGallery = async (groupId) => {
        const plan = planState.get(groupId);
        if (!plan) {
            throw new Error('Nincs betöltött terv ehhez a csoporthoz.');
        }

        const lines = String(document.getElementById(`easy-gallery-urls-${groupId}`)?.value || '')
            .split(/\r?\n/)
            .map((line) => line.trim())
            .filter(Boolean)
            .slice(0, 10);

        const touched = forEachModuleItem(plan, 'image-gallery', (item) => {
            item.settings.imageUrlsJson = JSON.stringify(lines);
        });

        if (touched === 0) {
            throw new Error('Nincs ütemezett képgaléria modul ebben a csoportban.');
        }

        await apiSavePlan(groupId, plan);
        await loadGroup(groupId);
    };

    const uploadGalleryImages = async (groupId) => {
        const fileInput = document.getElementById(`easy-gallery-upload-${groupId}`);
        const textArea = document.getElementById(`easy-gallery-urls-${groupId}`);
        if (!fileInput || !textArea || !fileInput.files || fileInput.files.length === 0) {
            throw new Error('Válassz legalább 1 képet.');
        }

        const urls = String(textArea.value || '')
            .split(/\r?\n/)
            .map((line) => line.trim())
            .filter(Boolean);

        for (const file of Array.from(fileInput.files)) {
            const formData = new FormData();
            formData.append('group_id', String(groupId));
            formData.append('module_key', 'image-gallery');
            formData.append('asset_kind', 'image');
            formData.append('asset', file, file.name || 'image.jpg');

            const response = await fetch('../api/group_loop/module_asset_upload.php', {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            });
            const data = await response.json();
            if (!response.ok || !data?.success || !data?.asset_url) {
                throw new Error(data?.message || `Kép feltöltési hiba (${response.status})`);
            }

            urls.push(String(data.asset_url));
            if (urls.length >= 10) {
                break;
            }
        }

        textArea.value = Array.from(new Set(urls)).slice(0, 10).join('\n');
        fileInput.value = '';
    };

    const savePdf = async (groupId) => {
        const plan = planState.get(groupId);
        if (!plan) {
            throw new Error('Nincs betöltött terv ehhez a csoporthoz.');
        }

        const fileInput = document.getElementById(`easy-pdf-upload-${groupId}`);
        const file = fileInput?.files?.[0];
        if (!file) {
            throw new Error('Válassz egy PDF fájlt.');
        }

        const formData = new FormData();
        formData.append('group_id', String(groupId));
        formData.append('module_key', 'pdf');
        formData.append('asset_kind', 'pdf');
        formData.append('asset', file, file.name || 'document.pdf');

        const response = await fetch('../api/group_loop/module_asset_upload.php', {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        });
        const data = await response.json();
        if (!response.ok || !data?.success || !data?.asset_url) {
            throw new Error(data?.message || `PDF feltöltési hiba (${response.status})`);
        }

        const touched = forEachModuleItem(plan, 'pdf', (item) => {
            item.settings.pdfAssetUrl = String(data.asset_url || '');
            item.settings.pdfAssetId = data.asset_id || '';
            item.settings.pdfDataBase64 = '';
        });

        if (touched === 0) {
            throw new Error('Nincs ütemezett PDF modul ebben a csoportban.');
        }

        await apiSavePlan(groupId, plan);
        await loadGroup(groupId);
        if (fileInput) fileInput.value = '';
    };

    const saveSos = async (groupId) => {
        const plan = planState.get(groupId);
        if (!plan) {
            throw new Error('Nincs betöltött terv ehhez a csoporthoz.');
        }

        const startInput = document.getElementById(`easy-sos-start-${groupId}`);
        const endInput = document.getElementById(`easy-sos-end-${groupId}`);
        const textInput = document.getElementById(`easy-sos-text-${groupId}`);
        const showDateTime = document.getElementById(`easy-sos-show-datetime-${groupId}`)?.checked === true;

        const start = parseLocalDateTime(startInput?.value || '');
        const end = parseLocalDateTime(endInput?.value || '');
        if (!start || !end) {
            throw new Error('Add meg a kezdés és vége időpontját.');
        }
        if (end <= start) {
            throw new Error('A vége időpont legyen később, mint a kezdés.');
        }

        const now = new Date();
        const maxStart = new Date(now.getTime() + (7 * 24 * 60 * 60 * 1000));
        if (start > maxStart) {
            throw new Error('Legfeljebb 1 hétre lehet előre ütemezni.');
        }

        const maxDurationMs = 24 * 60 * 60 * 1000;
        if ((end.getTime() - start.getTime()) > maxDurationMs) {
            throw new Error('Legfeljebb 24 órás SOS kiírás adható meg.');
        }

        const rawText = String(textInput?.value || '').trim();
        if (rawText === '') {
            throw new Error('SOS szöveg megadása kötelező.');
        }

        const textModuleMeta = bootstrap.moduleCatalog.text || { id: 0, name: 'Text' };
        if (!textModuleMeta.id) {
            throw new Error('A text modul nincs engedélyezve ehhez az intézményhez.');
        }

        const pad = (n) => String(n).padStart(2, '0');
        const dateLabel = `${start.getFullYear()}-${pad(start.getMonth() + 1)}-${pad(start.getDate())} ${pad(start.getHours())}:${pad(start.getMinutes())}`;
        const finalText = showDateTime
            ? `<strong>${escapeHtml(dateLabel)}</strong><br>${escapeHtml(rawText).replace(/\n/g, '<br>')}`
            : escapeHtml(rawText).replace(/\n/g, '<br>');

        plan.loop_styles = Array.isArray(plan.loop_styles) ? plan.loop_styles : [];
        plan.schedule_blocks = Array.isArray(plan.schedule_blocks) ? plan.schedule_blocks : [];

        const styleIds = plan.loop_styles.map((style) => parseInt(style.id || 0, 10)).filter((id) => id > 0);
        const nextStyleId = (styleIds.length ? Math.max(...styleIds) : 0) + 1;

        const sosStyle = {
            id: nextStyleId,
            name: `SOS ${dateLabel}`,
            items: [{
                module_id: parseInt(textModuleMeta.id || 0, 10),
                module_name: String(textModuleMeta.name || 'Text'),
                module_key: 'text',
                description: 'SOS üzenet',
                duration_seconds: Math.max(10, Math.min(60, Math.floor((end.getTime() - start.getTime()) / 1000))),
                settings: {
                    text: finalText,
                    bgColor: '#8b0000',
                    textColor: '#ffffff',
                    textAnimationEntry: 'none',
                    scrollMode: false
                }
            }]
        };

        const nextBlockId = Math.min(-1, ...plan.schedule_blocks.map((b) => parseInt(b.id || 0, 10)).filter((n) => Number.isFinite(n) && n < 0)) - 1;
        const sosBlock = {
            id: nextBlockId,
            block_name: 'SOS üzenet',
            block_type: 'date',
            specific_date: `${start.getFullYear()}-${pad(start.getMonth() + 1)}-${pad(start.getDate())}`,
            start_time: `${pad(start.getHours())}:${pad(start.getMinutes())}:00`,
            end_time: `${pad(end.getHours())}:${pad(end.getMinutes())}:00`,
            days_mask: '1,2,3,4,5,6,7',
            is_active: 1,
            priority: 10000,
            display_order: 0,
            loop_style_id: nextStyleId
        };

        plan.loop_styles.push(sosStyle);
        plan.schedule_blocks.push(sosBlock);

        await apiSavePlan(groupId, plan);
        await loadGroup(groupId);
    };

    const toggleEditor = (groupId, editorKey) => {
        ['text', 'gallery', 'pdf'].forEach((key) => {
            const node = document.getElementById(`easy-${key}-editor-${groupId}`);
            if (!node) return;
            node.style.display = key === editorKey && node.style.display !== 'block' ? 'block' : 'none';
        });
    };

    document.addEventListener('click', async (event) => {
        const target = event.target.closest('[data-action][data-group-id]');
        if (!target) return;

        const action = String(target.getAttribute('data-action') || '');
        const groupId = parseInt(target.getAttribute('data-group-id') || '0', 10);
        if (!groupId) return;

        try {
            setSaveState(groupId, 'Mentés…');

            if (action === 'open-text') {
                toggleEditor(groupId, 'text');
                setSaveState(groupId, '');
                return;
            }
            if (action === 'open-gallery') {
                toggleEditor(groupId, 'gallery');
                setSaveState(groupId, '');
                return;
            }
            if (action === 'open-pdf') {
                toggleEditor(groupId, 'pdf');
                setSaveState(groupId, '');
                return;
            }

            if (action === 'save-text') {
                await saveText(groupId);
                setSaveState(groupId, 'Szöveg mentve.');
                return;
            }

            if (action === 'upload-gallery') {
                await uploadGalleryImages(groupId);
                setSaveState(groupId, 'Képek feltöltve, még mentsd a modult.');
                return;
            }

            if (action === 'save-gallery') {
                await saveGallery(groupId);
                setSaveState(groupId, 'Képgaléria mentve.');
                return;
            }

            if (action === 'save-pdf') {
                await savePdf(groupId);
                setSaveState(groupId, 'PDF mentve.');
                return;
            }

            if (action === 'save-sos') {
                await saveSos(groupId);
                setSaveState(groupId, 'SOS ütemezés mentve.');
                return;
            }

            setSaveState(groupId, '');
        } catch (error) {
            setSaveState(groupId, error?.message || 'Ismeretlen hiba.', true);
        }
    });

    bootstrap.groups.forEach((group) => {
        loadGroup(parseInt(group.id, 10));
    });
})();
</script>
