<?php
session_start();
require_once '../auth_roles.php';
require_once '../i18n.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

if (!edudisplej_can_edit_module_content()) {
    header('Location: index.php');
    exit();
}

$breadcrumb_items = [
    ['label' => '📝 ' . t_def('slides.title', 'Slide-ok'), 'current' => true],
];
$logout_url = '../login.php?logout=1';

$slides_i18n = [
    'statusNewItem' => t_def('slides.status.new_item', 'Új elem létrehozása'),
    'statusLoaded' => t_def('slides.status.loaded_for_edit', 'Elem betöltve szerkesztésre'),
    'statusDeleted' => t_def('slides.status.deleted', 'Elem törölve. Kapcsolt loopok verziója frissítve.'),
    'statusSaved' => t_def('slides.status.saved', 'Mentve. A kapcsolt Text modulok és loop verziók frissítve.'),
    'errorLoad' => t_def('slides.error.load', 'Betöltési hiba'),
    'errorDelete' => t_def('slides.error.delete', 'Törlési hiba'),
    'errorSave' => t_def('slides.error.save', 'Mentési hiba'),
    'errorImageType' => t_def('slides.error.image_type', 'Csak képfájl tölthető fel.'),
    'errorImageRead' => t_def('slides.error.image_read', 'Kép beolvasási hiba.'),
    'errorNameRequired' => t_def('slides.error.name_required', 'A név kötelező.'),
    'confirmDelete' => t_def('slides.confirm.delete', 'Biztosan törlöd ezt az elemet?'),
    'emptyItems' => t_def('slides.empty', 'Nincs még elem'),
    'loading' => t_def('common.loading', 'Betöltés...'),
    'edit' => t_def('common.edit', 'Szerkesztés'),
    'delete' => t_def('common.delete', 'Törlés'),
    'externalSourceLabel' => t_def('slides.external.label', 'Külső forrás (JSON v1)'),
    'externalSourceHint' => t_def('slides.external.hint', 'Előre megszabott formátum: format_version, source_name, headline, body, note, published_at'),
    'externalSourceTemplate' => t_def('slides.external.template', 'Sablon betöltése'),
    'externalSourcePreview' => t_def('slides.external.preview', 'Külső forrás előnézet'),
    'errorExternalSourceJson' => t_def('slides.external.error_json', 'A külső forrás mezőben érvénytelen JSON van.'),
    'emptyExternalSource' => t_def('slides.external.empty', 'Nincs külső forrás adat.'),
];

include '../admin/header.php';
?>

<div class="panel" style="margin-bottom:12px;">
    <div class="panel-title"><?php echo htmlspecialchars(t_def('slides.collection.title', 'Slide gyűjtemény')); ?></div>
    <div class="muted"><?php echo htmlspecialchars(t_def('slides.collection.desc', 'Előre elkészített rich-text slide tartalmak (háttérszínnel/háttérképpel), amelyeket a Text modulban lehet kiválasztani.')); ?></div>
</div>

<div style="display:grid; grid-template-columns: 1.1fr 1fr; gap:12px; align-items:start;">
    <div class="panel">
        <div class="panel-title"><?php echo htmlspecialchars(t_def('slides.editor.title', 'Elem szerkesztése')); ?></div>

        <input type="hidden" id="tc-id" value="0">

        <label style="display:block; margin-bottom:6px; font-weight:700;"><?php echo htmlspecialchars(t_def('common.name', 'Név')); ?></label>
        <input id="tc-title" type="text" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px; margin-bottom:10px;" maxlength="180" placeholder="<?php echo htmlspecialchars(t_def('slides.name.placeholder', 'Pl.: Reggeli hirdetmény')); ?>">

        <div style="display:flex; gap:6px; flex-wrap:wrap; margin-bottom:8px;">
            <button type="button" data-richcmd="bold" class="btn btn-small">B</button>
            <button type="button" data-richcmd="italic" class="btn btn-small">I</button>
            <button type="button" data-richcmd="underline" class="btn btn-small">U</button>
            <button type="button" data-richcmd="insertUnorderedList" class="btn btn-small">• Lista</button>
            <button type="button" data-richcmd="justifyLeft" class="btn btn-small">Bal</button>
            <button type="button" data-richcmd="justifyCenter" class="btn btn-small">Közép</button>
            <button type="button" data-richcmd="justifyRight" class="btn btn-small">Jobb</button>
            <label style="display:flex; align-items:center; gap:4px; font-size:12px;">Betűtípus
                <select id="tc-font-family" style="height:30px; border:1px solid #d1d5db; border-radius:4px;">
                    <option value="Arial">Arial</option>
                    <option value="Verdana">Verdana</option>
                    <option value="Tahoma">Tahoma</option>
                    <option value="Trebuchet MS">Trebuchet MS</option>
                    <option value="Georgia">Georgia</option>
                    <option value="Times New Roman">Times New Roman</option>
                    <option value="Courier New">Courier New</option>
                </select>
            </label>
            <label style="display:flex; align-items:center; gap:4px; font-size:12px;">Méret
                <select id="tc-font-size" style="height:30px; border:1px solid #d1d5db; border-radius:4px;">
                    <option value="12">12 px</option>
                    <option value="14">14 px</option>
                    <option value="16" selected>16 px</option>
                    <option value="18">18 px</option>
                    <option value="24">24 px</option>
                    <option value="32">32 px</option>
                    <option value="48">48 px</option>
                </select>
            </label>
            <label style="display:flex; align-items:center; gap:4px; font-size:12px;">Szín <input type="color" id="tc-color" value="#ffffff"></label>
            <label style="display:flex; align-items:center; gap:4px; font-size:12px;">Háttér jelölés <input type="color" id="tc-mark" value="#ffd54f"></label>
        </div>

        <div id="tc-content" contenteditable="true" style="min-height:240px; border:1px solid #d1d5db; border-radius:6px; padding:10px; background:#000; color:#fff; overflow:auto; white-space:pre-wrap; word-break:break-word;"></div>

        <div style="margin-top:10px;">
            <label style="display:block; margin-bottom:6px; font-weight:700;"><?php echo htmlspecialchars(t_def('slides.external.label', 'Külső forrás (JSON v1)')); ?></label>
            <textarea id="tc-external-source" rows="8" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px; font-family:Consolas, 'Courier New', monospace; font-size:12px;"></textarea>
            <div class="muted" style="margin-top:6px;"><?php echo htmlspecialchars(t_def('slides.external.hint', 'Előre megszabott formátum: format_version, source_name, headline, body, note, published_at')); ?></div>
            <div style="margin-top:8px;"><button id="tc-external-template" type="button" class="btn btn-small"><?php echo htmlspecialchars(t_def('slides.external.template', 'Sablon betöltése')); ?></button></div>
        </div>

        <div style="margin-top:10px;">
            <label style="display:block; margin-bottom:6px; font-weight:700;"><?php echo htmlspecialchars(t_def('slides.external.preview', 'Külső forrás előnézet')); ?></label>
            <div id="tc-external-preview" style="border:1px solid #d1d5db; border-radius:6px; padding:10px; background:#f8fafc; color:#0f172a; min-height:90px;"></div>
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-top:10px;">
            <div>
                <label style="display:block; margin-bottom:6px; font-weight:700;">Háttérszín</label>
                <input id="tc-bg-color" type="color" value="#000000" style="width:100%; height:40px; border-radius:6px; border:1px solid #d1d5db;">
            </div>
            <div>
                <label style="display:block; margin-bottom:6px; font-weight:700;">Háttérkép</label>
                <input id="tc-bg-image-file" type="file" accept="image/*" style="width:100%;">
                <input id="tc-bg-image-data" type="hidden" value="">
            </div>
        </div>

        <div style="display:flex; gap:8px; margin-top:10px;">
            <button id="tc-save-btn" type="button" class="btn btn-primary">💾 <?php echo htmlspecialchars(t_def('common.save', 'Mentés')); ?></button>
            <button id="tc-new-btn" type="button" class="btn"><?php echo htmlspecialchars(t_def('common.new_item', 'Új elem')); ?></button>
            <button id="tc-remove-bg" type="button" class="btn btn-danger"><?php echo htmlspecialchars(t_def('slides.bg.remove', 'Háttérkép törlése')); ?></button>
        </div>
        <div id="tc-status" class="muted" style="margin-top:8px;"></div>
    </div>

    <div class="panel">
        <div class="panel-title"><?php echo htmlspecialchars(t_def('slides.items.title', 'Gyűjtemény elemek')); ?></div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t_def('common.name', 'Név')); ?></th>
                        <th><?php echo htmlspecialchars(t_def('common.updated', 'Frissítve')); ?></th>
                        <th><?php echo htmlspecialchars(t_def('common.action', 'Művelet')); ?></th>
                    </tr>
                </thead>
                <tbody id="tc-list-body">
                    <tr><td colspan="3" class="muted"><?php echo htmlspecialchars(t_def('common.loading', 'Betöltés...')); ?></td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
(function () {
    const apiBase = '../api/text_collections.php';
    const I18N = <?php echo json_encode($slides_i18n, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    let collections = [];

    const $ = (id) => document.getElementById(id);

    function setStatus(message, isError) {
        const el = $('tc-status');
        if (!el) return;
        el.textContent = message || '';
        el.style.color = isError ? '#b42318' : '#475467';
    }

    function sanitizeHtml(value) {
        return String(value || '')
            .replace(/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi, '')
            .replace(/ on\w+="[^"]*"/gi, '')
            .replace(/ on\w+='[^']*'/gi, '');
    }

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, (m) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        }[m]));
    }

    function externalSourceTemplate() {
        return {
            format_version: 'v1',
            source_name: '',
            headline: '',
            body: '',
            note: '',
            published_at: ''
        };
    }

    function normalizeExternalSource(raw) {
        const base = externalSourceTemplate();
        const safe = raw && typeof raw === 'object' ? raw : {};
        return {
            format_version: 'v1',
            source_name: String(safe.source_name || '').trim(),
            headline: String(safe.headline || '').trim(),
            body: String(safe.body || '').trim(),
            note: String(safe.note || '').trim(),
            published_at: String(safe.published_at || '').trim()
        };
    }

    function setExternalSourceEditor(value) {
        $('tc-external-source').value = JSON.stringify(normalizeExternalSource(value), null, 2);
        renderExternalSourcePreview();
    }

    function getExternalSourceFromEditor() {
        const raw = String($('tc-external-source').value || '').trim();
        if (!raw) {
            return normalizeExternalSource(null);
        }
        let parsed;
        try {
            parsed = JSON.parse(raw);
        } catch (_) {
            throw new Error(I18N.errorExternalSourceJson);
        }
        return normalizeExternalSource(parsed);
    }

    function renderExternalSourcePreview() {
        const preview = $('tc-external-preview');
        if (!preview) return;

        let source;
        try {
            source = getExternalSourceFromEditor();
        } catch (_) {
            preview.innerHTML = `<span class="muted">${escapeHtml(I18N.errorExternalSourceJson)}</span>`;
            return;
        }

        const blocks = [];
        if (source.source_name) blocks.push(`<div><strong>Forrás:</strong> ${escapeHtml(source.source_name)}</div>`);
        if (source.published_at) blocks.push(`<div><strong>Dátum:</strong> ${escapeHtml(source.published_at)}</div>`);
        if (source.headline) blocks.push(`<div style="margin-top:6px; font-weight:700;">${escapeHtml(source.headline)}</div>`);
        if (source.body) blocks.push(`<div style="margin-top:6px; white-space:pre-wrap;">${escapeHtml(source.body)}</div>`);
        if (source.note) blocks.push(`<div style="margin-top:6px; color:#475467;"><em>${escapeHtml(source.note)}</em></div>`);

        preview.innerHTML = blocks.length ? blocks.join('') : `<span class="muted">${escapeHtml(I18N.emptyExternalSource)}</span>`;
    }

    function resetForm() {
        $('tc-id').value = '0';
        $('tc-title').value = '';
        $('tc-content').innerHTML = '';
        $('tc-bg-color').value = '#000000';
        $('tc-bg-image-data').value = '';
        setExternalSourceEditor(null);
        if ($('tc-bg-image-file')) $('tc-bg-image-file').value = '';
        applyEditorBackground();
        setStatus(I18N.statusNewItem, false);
    }

    function applyEditorBackground() {
        const content = $('tc-content');
        const bgColor = $('tc-bg-color').value || '#000000';
        const bgImageData = $('tc-bg-image-data').value || '';
        content.style.backgroundColor = bgColor;
        if (bgImageData) {
            content.style.backgroundImage = `url("${bgImageData}")`;
            content.style.backgroundSize = 'cover';
            content.style.backgroundPosition = 'center center';
            content.style.backgroundRepeat = 'no-repeat';
        } else {
            content.style.backgroundImage = 'none';
        }
    }

    async function loadCollections() {
        const response = await fetch(`${apiBase}?action=list&include_content=1`, { credentials: 'same-origin' });
        const data = await response.json();
        if (!data.success) {
            throw new Error(data.message || I18N.errorLoad);
        }
        collections = Array.isArray(data.items) ? data.items : [];
        renderList();
    }

    function renderList() {
        const body = $('tc-list-body');
        if (!body) return;
        if (!collections.length) {
            body.innerHTML = `<tr><td colspan="3" class="muted">${I18N.emptyItems}</td></tr>`;
            return;
        }

        body.innerHTML = collections.map((item) => {
            const safeTitle = (item.title || '').replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m]));
            const safeDate = (item.updated_at || '').replace(/[&<>"']/g, '');
            return `
                <tr>
                    <td>${safeTitle}</td>
                    <td>${safeDate}</td>
                    <td class="nowrap">
                        <button type="button" class="btn btn-small" data-edit-id="${item.id}">${I18N.edit}</button>
                        <button type="button" class="btn btn-small btn-danger" data-delete-id="${item.id}">${I18N.delete}</button>
                    </td>
                </tr>
            `;
        }).join('');

        body.querySelectorAll('[data-edit-id]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const id = parseInt(btn.getAttribute('data-edit-id') || '0', 10);
                const found = collections.find((x) => Number(x.id) === id);
                if (!found) return;
                $('tc-id').value = String(found.id || 0);
                $('tc-title').value = found.title || '';
                $('tc-content').innerHTML = sanitizeHtml(found.content_html || '');
                setExternalSourceEditor(found.external_source || null);
                $('tc-bg-color').value = found.bg_color || '#000000';
                $('tc-bg-image-data').value = found.bg_image_data || '';
                applyEditorBackground();
                setStatus(I18N.statusLoaded, false);
            });
        });

        body.querySelectorAll('[data-delete-id]').forEach((btn) => {
            btn.addEventListener('click', async () => {
                const id = parseInt(btn.getAttribute('data-delete-id') || '0', 10);
                if (!id) return;
                if (!window.confirm(I18N.confirmDelete)) return;
                try {
                    const response = await fetch(`${apiBase}?action=delete`, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ id })
                    });
                    const data = await response.json();
                    if (!data.success) {
                        throw new Error(data.message || I18N.errorDelete);
                    }
                    await loadCollections();
                    resetForm();
                    setStatus(I18N.statusDeleted, false);
                } catch (error) {
                    setStatus(error.message || I18N.errorDelete, true);
                }
            });
        });
    }

    async function saveCollection() {
        let externalSource;
        try {
            externalSource = getExternalSourceFromEditor();
        } catch (error) {
            setStatus(error.message || I18N.errorExternalSourceJson, true);
            return;
        }

        const payload = {
            id: parseInt($('tc-id').value || '0', 10) || 0,
            title: ($('tc-title').value || '').trim(),
            content_html: sanitizeHtml($('tc-content').innerHTML || ''),
            external_source: externalSource,
            bg_color: $('tc-bg-color').value || '#000000',
            bg_image_data: $('tc-bg-image-data').value || ''
        };

        if (!payload.title) {
            setStatus(I18N.errorNameRequired, true);
            return;
        }

        try {
            const response = await fetch(`${apiBase}?action=save`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await response.json();
            if (!data.success) {
                throw new Error(data.message || I18N.errorSave);
            }
            await loadCollections();
            $('tc-id').value = String((data.item && data.item.id) || payload.id || 0);
            setStatus(I18N.statusSaved, false);
        } catch (error) {
            setStatus(error.message || I18N.errorSave, true);
        }
    }

    document.querySelectorAll('[data-richcmd]').forEach((button) => {
        button.addEventListener('click', () => {
            const command = button.getAttribute('data-richcmd');
            const editor = $('tc-content');
            editor.focus();
            if (command === 'underline') {
                document.execCommand('styleWithCSS', false, true);
                document.execCommand('underline', false, null);
            } else {
                document.execCommand('styleWithCSS', false, true);
                document.execCommand(command, false, null);
            }
        });
    });

    function getLegacyFontSize(sizePx) {
        const px = parseInt(sizePx || '16', 10) || 16;
        if (px <= 12) return '2';
        if (px <= 14) return '3';
        if (px <= 16) return '4';
        if (px <= 18) return '5';
        if (px <= 24) return '6';
        return '7';
    }

    $('tc-font-family').addEventListener('change', () => {
        $('tc-content').focus();
        document.execCommand('styleWithCSS', false, true);
        document.execCommand('fontName', false, $('tc-font-family').value || 'Arial');
    });

    $('tc-font-size').addEventListener('change', () => {
        $('tc-content').focus();
        document.execCommand('styleWithCSS', false, true);
        document.execCommand('fontSize', false, getLegacyFontSize($('tc-font-size').value));
    });

    $('tc-color').addEventListener('input', () => {
        $('tc-content').focus();
        document.execCommand('styleWithCSS', false, true);
        document.execCommand('foreColor', false, $('tc-color').value);
    });

    $('tc-mark').addEventListener('input', () => {
        $('tc-content').focus();
        document.execCommand('styleWithCSS', false, true);
        document.execCommand('hiliteColor', false, $('tc-mark').value);
    });

    $('tc-bg-color').addEventListener('input', applyEditorBackground);
    $('tc-external-source').addEventListener('input', renderExternalSourcePreview);
    $('tc-external-template').addEventListener('click', () => {
        setExternalSourceEditor(null);
    });
    $('tc-new-btn').addEventListener('click', resetForm);
    $('tc-save-btn').addEventListener('click', saveCollection);
    $('tc-remove-bg').addEventListener('click', () => {
        $('tc-bg-image-data').value = '';
        if ($('tc-bg-image-file')) $('tc-bg-image-file').value = '';
        applyEditorBackground();
    });

    $('tc-bg-image-file').addEventListener('change', async () => {
        const file = $('tc-bg-image-file').files && $('tc-bg-image-file').files[0];
        if (!file) return;
        if (!file.type.startsWith('image/')) {
            setStatus(I18N.errorImageType, true);
            return;
        }
        const reader = new FileReader();
        reader.onload = () => {
            $('tc-bg-image-data').value = String(reader.result || '');
            applyEditorBackground();
        };
        reader.onerror = () => setStatus(I18N.errorImageRead, true);
        reader.readAsDataURL(file);
    });

    resetForm();
    loadCollections().catch((error) => setStatus(error.message || I18N.errorLoad, true));
})();
</script>

<?php include '../admin/footer.php'; ?>
