        const groupLoopBootstrap = window.GroupLoopBootstrap || {};
        let loopItems = [];
        let loopStyles = [];
        let timeBlocks = [];
        let activeLoopStyleId = null;
        let defaultLoopStyleId = null;
        let activeScope = 'base';
        let scheduleWeekOffset = 0;
        let nextTempTimeBlockId = -1;
        let hasOpenedLoopDetail = false;
        const groupId = parseInt(groupLoopBootstrap.groupId || 0, 10);
        const isDefaultGroup = !!groupLoopBootstrap.isDefaultGroup;
        const isContentOnlyMode = !!groupLoopBootstrap.isContentOnlyMode;
        const technicalModule = groupLoopBootstrap.technicalModule || null;
        const modulesCatalog = Array.isArray(groupLoopBootstrap.modulesCatalog) ? groupLoopBootstrap.modulesCatalog : [];
        hasOpenedLoopDetail = true;

        function getDefaultUnconfiguredItem() {
            if (!technicalModule) {
                return null;
            }

            return {
                module_id: parseInt(technicalModule.id),
                module_name: technicalModule.name,
                description: technicalModule.description || 'Technikai modul – csak üres loop esetén.',
                module_key: 'unconfigured',
                duration_seconds: 60,
                settings: {}
            };
        }
        
        // Preview variables
        let previewInterval = null;
        let previewTimeout = null;
        let currentPreviewIndex = 0;
        let currentModuleStartTime = 0;
        let totalLoopStartTime = 0;
        let isPaused = false;
        let loopCycleCount = 0;
        let autoSaveTimer = null;
        let autoSaveInFlight = false;
        let autoSaveQueued = false;
        let autoSaveToastTimer = null;
        let hasLoadedInitialLoop = false;
        let lastSavedSnapshot = '';
        let lastPublishedPayload = null;
        let planVersionToken = '';
        let draftPersistTimer = null;
        let isDraftDirty = false;
        let scheduleRangeSelection = null;
        let scheduleBlockResize = null;
        let scheduleGridStepMinutes = 60;

        function showAutosaveToast(message, isError = false) {
            const toast = document.getElementById('autosave-toast');
            if (!toast) {
                return;
            }

            toast.textContent = message;
            toast.classList.toggle('error', !!isError);
            toast.classList.add('show');

            if (autoSaveToastTimer) {
                clearTimeout(autoSaveToastTimer);
            }

            autoSaveToastTimer = setTimeout(() => {
                toast.classList.remove('show');
            }, isError ? 2800 : 1400);
        }

        function deepClone(value) {
            return JSON.parse(JSON.stringify(value));
        }

        function getDraftStorageKey() {
            return `group_loop_draft_${groupId}`;
        }

        function updatePendingSaveBar() {
            const bar = document.getElementById('pending-save-bar');
            const label = document.getElementById('pending-save-text');
            if (!bar || !label) {
                return;
            }

            if (isDefaultGroup || !hasLoadedInitialLoop) {
                bar.style.display = 'none';
                return;
            }

            bar.style.display = isDraftDirty ? 'flex' : 'none';
            const versionText = planVersionToken ? ` • Verzió: ${planVersionToken}` : '';
            label.textContent = isDraftDirty
                ? `Nem mentett változtatások${versionText}`
                : `Minden változtatás mentve${versionText}`;
        }

        function setDraftDirty(flag) {
            isDraftDirty = !!flag;
            updatePendingSaveBar();
        }

        function persistDraftToCache() {
            if (!hasLoadedInitialLoop || isDefaultGroup) {
                return;
            }

            try {
                const snapshot = getLoopSnapshot();
                const payload = {
                    snapshot,
                    saved_at: new Date().toISOString()
                };
                localStorage.setItem(getDraftStorageKey(), JSON.stringify(payload));
                setDraftDirty(snapshot !== lastSavedSnapshot);
            } catch (error) {
                console.warn('Draft save failed', error);
            }
        }

        function clearDraftCache() {
            try {
                localStorage.removeItem(getDraftStorageKey());
            } catch (error) {
                console.warn('Draft cleanup failed', error);
            }
        }

        function queueDraftPersist(delayMs = 250) {
            if (draftPersistTimer) {
                clearTimeout(draftPersistTimer);
            }
            draftPersistTimer = setTimeout(() => {
                persistDraftToCache();
            }, delayMs);
        }

        function applyPlanPayload(payload) {
            if (!payload || typeof payload !== 'object') {
                return false;
            }

            const styles = Array.isArray(payload.loop_styles) ? payload.loop_styles : [];
            const parsedStyles = styles.length > 0
                ? styles.map((style, idx) => ({
                    id: parseInt(style.id ?? -(idx + 1), 10),
                    name: String(style.name || `Loop ${idx + 1}`),
                    items: Array.isArray(style.items) ? style.items : []
                }))
                : [createFallbackLoopStyle('Alap loop', Array.isArray(payload.base_loop) ? payload.base_loop : [])];

            loopStyles = parsedStyles;
            defaultLoopStyleId = parseInt(payload.default_loop_style_id ?? loopStyles[0]?.id ?? 0, 10) || loopStyles[0]?.id || null;
            timeBlocks = normalizeTimeBlocks(payload.schedule_blocks || payload.time_blocks || []);

            ensureSingleDefaultLoopStyle();
            activeLoopStyleId = parseInt(defaultLoopStyleId || loopStyles[0]?.id || 0, 10) || (loopStyles[0]?.id ?? null);
            loopItems = deepClone(getLoopStyleById(activeLoopStyleId)?.items || []);
            normalizeLoopItems();
            persistActiveLoopStyleItems();
            activeScope = 'base';
            setActiveScope('base', false);
            renderLoopStyleSelector();
            renderScopeSelector();
            renderLoop();
            return true;
        }

        function tryRestoreDraftFromCache() {
            if (isDefaultGroup) {
                return;
            }

            let parsed = null;
            try {
                parsed = JSON.parse(localStorage.getItem(getDraftStorageKey()) || 'null');
            } catch (error) {
                parsed = null;
            }

            if (!parsed || typeof parsed.snapshot !== 'string' || !parsed.snapshot.trim()) {
                setDraftDirty(false);
                return;
            }

            if (parsed.snapshot === lastSavedSnapshot) {
                clearDraftCache();
                setDraftDirty(false);
                return;
            }

            const restore = confirm('Találtam nem mentett helyi piszkozatot. Betöltsem?');
            if (!restore) {
                setDraftDirty(true);
                updatePendingSaveBar();
                return;
            }

            try {
                const payload = JSON.parse(parsed.snapshot);
                if (applyPlanPayload(payload)) {
                    showAutosaveToast('✓ Helyi piszkozat betöltve');
                    setDraftDirty(true);
                }
            } catch (error) {
                showAutosaveToast('⚠️ A helyi piszkozat sérült, törölve', true);
                clearDraftCache();
                setDraftDirty(false);
            }
        }

        function publishLoopPlan() {
            saveLoop({ silent: false, source: 'publish' });
        }

        function discardLocalDraft() {
            if (!isDraftDirty) {
                return;
            }
            if (!confirm('Biztosan elveted a helyi módosításokat?')) {
                return;
            }

            if (lastPublishedPayload && applyPlanPayload(lastPublishedPayload)) {
                clearDraftCache();
                setDraftDirty(false);
                showAutosaveToast('✓ Helyi módosítások elvetve');
            }
        }

        function normalizeDaysMask(daysMask) {
            if (Array.isArray(daysMask)) {
                return daysMask.map(v => String(parseInt(v, 10))).filter(v => /^[1-7]$/.test(v)).join(',');
            }

            const raw = String(daysMask || '').trim();
            if (!raw) {
                return '1,2,3,4,5,6,7';
            }

            const unique = new Set();
            raw.split(',').forEach((part) => {
                const value = String(parseInt(part, 10));
                if (/^[1-7]$/.test(value)) {
                    unique.add(value);
                }
            });
            return unique.size ? Array.from(unique).sort().join(',') : '1,2,3,4,5,6,7';
        }

        function normalizeTimeBlocks(rawBlocks) {
            if (!Array.isArray(rawBlocks)) {
                return [];
            }

            return rawBlocks.map((block) => ({
                id: block.id != null ? parseInt(block.id, 10) : nextTempTimeBlockId--,
                block_name: String(block.block_name || 'Időblokk'),
                block_type: String(block.block_type || 'weekly') === 'date' ? 'date' : 'weekly',
                specific_date: block.specific_date ? String(block.specific_date).slice(0, 10) : null,
                start_time: String(block.start_time || '08:00:00').slice(0, 8),
                end_time: String(block.end_time || '12:00:00').slice(0, 8),
                days_mask: normalizeDaysMask(block.days_mask),
                priority: Number.isFinite(parseInt(block.priority, 10)) ? parseInt(block.priority, 10) : 100,
                loop_style_id: parseInt(block.loop_style_id || 0, 10) || null,
                is_active: block.is_active === false ? 0 : 1,
                is_locked: parseInt(block.is_locked || block.is_fixed_plan || 0, 10) ? 1 : 0,
                loops: Array.isArray(block.loops) ? block.loops : []
            }));
        }

        function createFallbackLoopStyle(name, items) {
            const styleId = nextTempTimeBlockId--;
            return {
                id: styleId,
                name: name || `Loop ${Math.abs(styleId)}`,
                items: Array.isArray(items) ? deepClone(items) : []
            };
        }

        function getLoopStyleById(styleId) {
            const normalized = parseInt(styleId, 10);
            return loopStyles.find((style) => parseInt(style.id, 10) === normalized) || null;
        }

        function persistActiveLoopStyleItems() {
            const style = getLoopStyleById(activeLoopStyleId);
            if (!style) {
                return;
            }
            style.items = deepClone(loopItems || []);
        }

        function updateLoopStyleMeta() {
            const meta = document.getElementById('loop-style-meta');
            if (!meta) {
                return;
            }
            const style = getLoopStyleById(activeLoopStyleId);
            const defaultStyle = getLoopStyleById(defaultLoopStyleId);
            const activeName = style ? style.name : '—';
            const defaultName = defaultStyle ? defaultStyle.name : '—';
            meta.textContent = `Szerkesztett loop: ${activeName} • Alap fallback loop (üres idő): ${defaultName}`;
        }

        function updateActiveLoopVisualState() {
            const style = getLoopStyleById(activeLoopStyleId);
            const styleName = style ? String(style.name || 'Loop') : '—';

            const configTitle = document.getElementById('loop-config-title');
            if (configTitle) {
                configTitle.textContent = `🔄 Csoport loopok — ${styleName}`;
            }

            const previewTitle = document.getElementById('preview-title');
            if (previewTitle) {
                previewTitle.textContent = `📺 ${styleName} loop előnézete`;
            }

            const banner = document.getElementById('active-loop-banner');
            if (banner) {
                banner.textContent = `Szerkesztett loop: ${styleName}`;
            }

            const inlineName = document.getElementById('active-loop-inline-name');
            if (inlineName) {
                inlineName.textContent = styleName;
            }
        }

        function toggleLoopDetailVisibility() {
            const detailPanel = document.getElementById('loop-detail-panel');
            const placeholder = document.getElementById('loop-detail-placeholder');
            if (!detailPanel) {
                return;
            }
            const hasActive = !!getLoopStyleById(activeLoopStyleId);
            const showDetail = hasActive;
            detailPanel.style.display = showDetail ? 'block' : 'none';
            if (placeholder) {
                placeholder.style.display = showDetail ? 'none' : 'flex';
            }
        }

        function toggleModulesCatalogVisibility() {
            const wrapper = document.getElementById('modules-panel-wrapper');
            const placeholder = document.getElementById('modules-panel-placeholder');
            if (!wrapper) {
                return;
            }
            const hasActive = !!getLoopStyleById(activeLoopStyleId);
            const showModules = hasActive;
            wrapper.style.display = showModules ? 'block' : 'none';
            if (placeholder) {
                placeholder.style.display = showModules ? 'none' : 'flex';
            }
        }

        function togglePreviewPanelVisibility() {
            const wrapper = document.getElementById('preview-panel-wrapper');
            if (!wrapper) {
                return;
            }
            const hasActive = !!getLoopStyleById(activeLoopStyleId);
            const showPreview = hasActive;
            wrapper.style.display = showPreview ? 'block' : 'none';
        }

        function toggleLoopWorkspaceVisibility() {
            const workspace = document.getElementById('loop-edit-workspace');
            const placeholder = document.getElementById('loop-workspace-placeholder');
            if (!workspace || !placeholder) {
                return;
            }
            const hasActive = !!getLoopStyleById(activeLoopStyleId);
            const showWorkspace = hasActive;
            workspace.style.display = showWorkspace ? 'grid' : 'none';
            placeholder.style.display = showWorkspace ? 'none' : 'flex';
        }

        function ensureSingleDefaultLoopStyle() {
            if (!Array.isArray(loopStyles) || loopStyles.length === 0) {
                defaultLoopStyleId = null;
                return;
            }

            const currentDefault = getLoopStyleById(defaultLoopStyleId);
            if (currentDefault) {
                defaultLoopStyleId = parseInt(currentDefault.id, 10);
                return;
            }

            const namedBase = loopStyles.find((style) => /^alap\b/i.test(String(style.name || '').trim()));
            defaultLoopStyleId = parseInt((namedBase || loopStyles[0]).id, 10);
        }

        function openLoopStyleDetail(styleId) {
            hasOpenedLoopDetail = true;
            setActiveLoopStyle(styleId);
        }

        function renderLoopStyleCards() {
            const select = document.getElementById('loop-style-select');
            if (!select) {
                return;
            }

            select.innerHTML = '';

            if (!Array.isArray(loopStyles) || loopStyles.length === 0) {
                const emptyOption = document.createElement('option');
                emptyOption.value = '';
                emptyOption.textContent = 'Nincs elérhető loop';
                select.appendChild(emptyOption);
                select.disabled = true;
                return;
            }

            select.disabled = false;

            const defaultId = parseInt(defaultLoopStyleId || 0, 10);
            const activeId = parseInt(activeLoopStyleId || 0, 10);

            loopStyles.forEach((style) => {
                const styleId = parseInt(style.id, 10);
                const isDefaultStyle = styleId === defaultId;
                const realModuleCount = Array.isArray(style.items)
                    ? style.items.filter((item) => !isTechnicalLoopItem(item)).length
                    : 0;

                const option = document.createElement('option');
                option.value = String(style.id);
                option.textContent = `${isDefaultStyle ? '[Alap] ' : ''}${style.name} (${realModuleCount} modul)`;
                option.selected = styleId === activeId;
                select.appendChild(option);
            });

            const activeStyle = getLoopStyleById(activeLoopStyleId);
            if (activeStyle) {
                select.value = String(activeStyle.id);
            }

            const deleteBtn = document.getElementById('loop-style-delete-btn');
            if (deleteBtn) {
                const activeIdValue = parseInt(activeLoopStyleId || 0, 10);
                const canDelete = !isDefaultGroup && !isContentOnlyMode && activeIdValue > 0 && activeIdValue !== defaultId;
                deleteBtn.disabled = !canDelete;
                deleteBtn.style.opacity = canDelete ? '1' : '0.5';
                deleteBtn.style.cursor = canDelete ? 'pointer' : 'not-allowed';
            }
        }

        function renderLoopStyleSelector() {
            const dragList = document.getElementById('loop-style-drag-list');
            const fixedStyleInput = document.getElementById('fixed-plan-loop-style');
            const fixedStyleLabel = document.getElementById('fixed-plan-loop-label');
            const schedulableStyles = loopStyles.filter((style) => parseInt(style.id, 10) !== parseInt(defaultLoopStyleId || 0, 10));

            renderLoopStyleCards();

            if (dragList) {
                const selectedSchedulableId = parseInt(fixedStyleInput?.value || activeLoopStyleId || 0, 10);
                dragList.innerHTML = '<label style="font-size:12px; font-weight:600; color:#425466;">Loopok</label><div class="loop-schedule-list" id="loop-schedule-list-inner"></div>';
                const listInner = document.getElementById('loop-schedule-list-inner');
                if (schedulableStyles.length === 0) {
                    const info = document.createElement('div');
                    info.style.fontSize = '12px';
                    info.style.color = '#8a97a6';
                    info.textContent = 'Nincs időzíthető loop.';
                    if (listInner) {
                        listInner.appendChild(info);
                    }
                }
                schedulableStyles.forEach((style) => {
                    const row = document.createElement('div');
                    row.className = 'loop-schedule-row';

                    const left = document.createElement('div');
                    const name = document.createElement('div');
                    name.className = 'loop-schedule-row-name';
                    name.textContent = style.name;
                    const meta = document.createElement('div');
                    meta.className = 'loop-schedule-row-meta';
                    meta.textContent = parseInt(style.id, 10) === selectedSchedulableId ? 'Kijelölt loop' : 'Heti vagy speciális tervbe tehető';
                    left.appendChild(name);
                    left.appendChild(meta);

                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'btn';
                    button.textContent = 'Időzítés';
                    button.addEventListener('click', () => {
                        if (fixedStyleInput) {
                            fixedStyleInput.value = String(style.id);
                        }
                        if (fixedStyleLabel) {
                            fixedStyleLabel.textContent = `Kiválasztott loop: ${style.name}`;
                        }
                        openLoopStyleDetail(style.id);
                        openQuickScheduleDialog(style.id);
                        renderLoopStyleSelector();
                    });

                    row.appendChild(left);
                    row.appendChild(button);
                    if (listInner) {
                        listInner.appendChild(row);
                    }
                });
            }

            if (fixedStyleInput) {
                const previousValue = String(fixedStyleInput.value || '');
                const hasPrevious = previousValue && schedulableStyles.some((style) => String(style.id) === previousValue);
                let resolvedValue = hasPrevious
                    ? previousValue
                    : String((schedulableStyles.find((style) => parseInt(style.id, 10) === parseInt(activeLoopStyleId || 0, 10))?.id) || (schedulableStyles[0]?.id || ''));
                fixedStyleInput.value = resolvedValue;
                if (fixedStyleLabel) {
                    const selectedStyle = schedulableStyles.find((style) => String(style.id) === resolvedValue) || null;
                    fixedStyleLabel.textContent = selectedStyle
                        ? `Kiválasztott loop: ${selectedStyle.name}`
                        : 'Kiválasztott loop: —';
                }
            }

            syncWeeklyPlannerFromScope();

            updateLoopStyleMeta();
            updateActiveLoopVisualState();
            toggleLoopDetailVisibility();
            toggleModulesCatalogVisibility();
            togglePreviewPanelVisibility();
            toggleLoopWorkspaceVisibility();
        }

        function clearWeeklyPlanSelection(keepDayTime = true) {
            const idInput = document.getElementById('fixed-plan-block-id');
            const addBtn = document.getElementById('fixed-plan-add-btn');
            if (idInput) {
                idInput.value = '';
            }
            if (addBtn) {
                addBtn.textContent = 'Mentés';
            }
            if (!keepDayTime) {
                document.querySelectorAll('.fixed-plan-day-checkbox').forEach((el) => {
                    el.checked = false;
                });
                const startInput = document.getElementById('fixed-plan-start');
                const endInput = document.getElementById('fixed-plan-end');
                if (startInput) startInput.value = '08:00';
                if (endInput) endInput.value = '10:00';
            }
        }

        function fillWeeklyPlanFormFromBlock(block) {
            if (!block || String(block.block_type || 'weekly') !== 'weekly') {
                clearWeeklyPlanSelection(true);
                return;
            }

            const idInput = document.getElementById('fixed-plan-block-id');
            const styleInput = document.getElementById('fixed-plan-loop-style');
            const startInput = document.getElementById('fixed-plan-start');
            const endInput = document.getElementById('fixed-plan-end');
            const addBtn = document.getElementById('fixed-plan-add-btn');
            const days = new Set(String(block.days_mask || '').split(',').map((v) => String(parseInt(v, 10))).filter((v) => /^[1-7]$/.test(v)));

            if (idInput) idInput.value = String(block.id);
            if (styleInput) styleInput.value = String(block.loop_style_id || '');
            if (startInput) startInput.value = String(block.start_time || '08:00:00').slice(0, 5);
            if (endInput) endInput.value = String(block.end_time || '10:00:00').slice(0, 5);
            document.querySelectorAll('.fixed-plan-day-checkbox').forEach((el) => {
                el.checked = days.has(String(el.value));
            });
            if (addBtn) {
                addBtn.textContent = 'Frissítés';
            }
        }

        function syncWeeklyPlannerFromScope() {
            if (activeScope === 'base') {
                clearWeeklyPlanSelection(true);
                return;
            }
            const block = getActiveTimeBlock();
            if (!block || String(block.block_type || 'weekly') !== 'weekly') {
                clearWeeklyPlanSelection(true);
                return;
            }
            fillWeeklyPlanFormFromBlock(block);
        }

        function getLoopStyleName(styleId) {
            const style = getLoopStyleById(styleId);
            return style ? String(style.name || 'Loop') : `Loop #${styleId}`;
        }

        function findOverlappingBlocks(candidate, ignoredId = null) {
            return timeBlocks.filter((existing) => {
                if (!existing || (ignoredId !== null && parseInt(existing.id, 10) === parseInt(ignoredId, 10))) {
                    return false;
                }

                if (String(existing.block_type || 'weekly') !== String(candidate.block_type || 'weekly')) {
                    return false;
                }

                if (String(candidate.block_type || 'weekly') === 'date') {
                    if (String(existing.specific_date || '') !== String(candidate.specific_date || '')) {
                        return false;
                    }
                } else {
                    const cDays = new Set(String(candidate.days_mask || '').split(',').map(v => parseInt(v, 10)).filter(v => v >= 1 && v <= 7));
                    const eDays = new Set(String(existing.days_mask || '').split(',').map(v => parseInt(v, 10)).filter(v => v >= 1 && v <= 7));
                    const commonDay = Array.from(cDays).some((d) => eDays.has(d));
                    if (!commonDay) {
                        return false;
                    }
                }

                return hasPairOverlap(candidate, existing);
            });
        }

        function hasPairOverlap(a, b) {
            const toSegments = (startRaw, endRaw) => {
                const startMinute = parseMinuteFromTime(startRaw, 0);
                let endMinute = parseMinuteFromTime(endRaw, 0);

                if (endMinute === startMinute) {
                    return [[0, 1440]];
                }

                if (endMinute > startMinute) {
                    return [[startMinute, endMinute]];
                }

                if (endMinute === 0) {
                    return [[startMinute, 1440]];
                }

                return [[startMinute, 1440], [0, endMinute]];
            };

            const segA = toSegments(String(a.start_time || '00:00:00'), String(a.end_time || '00:00:00'));
            const segB = toSegments(String(b.start_time || '00:00:00'), String(b.end_time || '00:00:00'));
            return segA.some(([a0, a1]) => segB.some(([b0, b1]) => a0 < b1 && b0 < a1));
        }

        function resolveScheduleConflicts(candidate, ignoredId = null) {
            const overlaps = findOverlappingBlocks(candidate, ignoredId);
            if (overlaps.length === 0) {
                return true;
            }

            const names = overlaps.map((block) => {
                const styleName = getLoopStyleName(block.loop_style_id || 0);
                return `• ${styleName} (${String(block.start_time).slice(0, 5)}-${String(block.end_time).slice(0, 5)})`;
            }).join('\n');

            const choice = prompt(
                `Ebben az időben már tervben van loop:\n${names}\n\n1 = Ütköző blokkok lecsonkítása (hamarabb vége)\n2 = Ütköző blokkok törlése\n0 = Mégse`,
                '1'
            );

            if (choice === null || String(choice).trim() === '0') {
                return false;
            }

            if (String(choice).trim() === '2') {
                const overlapIds = new Set(overlaps.map((entry) => parseInt(entry.id, 10)));
                timeBlocks = timeBlocks.filter((entry) => !overlapIds.has(parseInt(entry.id, 10)));
                return true;
            }

            const candidateStart = String(candidate.start_time || '00:00:00');
            const candidateStartMinute = parseMinuteFromTime(candidateStart, 0);
            const updated = [];

            timeBlocks.forEach((entry) => {
                const isConflict = overlaps.some((block) => parseInt(block.id, 10) === parseInt(entry.id, 10));
                if (!isConflict) {
                    updated.push(entry);
                    return;
                }

                const entryStartMinute = parseMinuteFromTime(entry.start_time, 0);
                if (candidateStartMinute <= entryStartMinute) {
                    return;
                }

                const trimmed = {
                    ...entry,
                    end_time: candidateStart
                };

                if (parseMinuteFromTime(trimmed.start_time, 0) === parseMinuteFromTime(trimmed.end_time, 0)) {
                    return;
                }

                updated.push(trimmed);
            });

            timeBlocks = updated;
            return true;
        }

        function openQuickScheduleDialog(loopStyleId) {
            if (isDefaultGroup || isContentOnlyMode) {
                return;
            }

            const normalizedStyleId = parseInt(loopStyleId || 0, 10);
            if (!normalizedStyleId || normalizedStyleId === parseInt(defaultLoopStyleId || 0, 10)) {
                showAutosaveToast('⚠️ Az alap loop nem tervezhető', true);
                return;
            }

            const host = document.getElementById('time-block-modal-host');
            const styleName = getLoopStyleName(normalizedStyleId);
            if (!host) {
                return;
            }

            host.innerHTML = `
                <div style="position:fixed; inset:0; background:rgba(0,0,0,0.45); display:flex; align-items:center; justify-content:center; z-index:3200;">
                    <div style="background:#fff; width:min(500px,92vw); border:1px solid #cfd6dd; padding:16px;">
                        <h3 style="margin:0 0 10px 0;">Heti időzítés</h3>
                        <div style="font-size:12px; color:#425466; margin-bottom:10px;">Loop: <strong>${styleName}</strong></div>
                        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:10px;">
                            ${[1,2,3,4,5,6,7].map((d) => `<label style="display:flex; align-items:center; gap:4px; font-size:12px;"><input type="checkbox" class="quick-weekly-day" value="${d}">${getDayShortLabel(String(d))}</label>`).join('')}
                        </div>
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:12px;">
                            <input id="quick-weekly-start" type="time" value="08:00" step="60">
                            <input id="quick-weekly-end" type="time" value="10:00" step="60">
                        </div>
                        <div style="display:flex; justify-content:flex-end; gap:8px;">
                            <button type="button" class="btn" onclick="closeTimeBlockModal()">Mégse</button>
                            <button type="button" class="btn" onclick="saveQuickScheduleDialog(${normalizedStyleId})">Hozzáadás</button>
                        </div>
                    </div>
                </div>
            `;
        }

        function saveQuickScheduleDialog(loopStyleId) {
            const selectedDays = Array.from(document.querySelectorAll('.quick-weekly-day:checked')).map((el) => String(parseInt(el.value, 10))).filter((v) => /^[1-7]$/.test(v));
            const startRaw = String(document.getElementById('quick-weekly-start')?.value || '').trim();
            const endRaw = String(document.getElementById('quick-weekly-end')?.value || '').trim();
            const styleId = parseInt(loopStyleId || 0, 10);

            if (!styleId || selectedDays.length === 0 || !startRaw || !endRaw) {
                showAutosaveToast('⚠️ Add meg a napot és az időt', true);
                return;
            }

            const startMinute = parseMinuteFromTime(`${startRaw}:00`, 0);
            const endMinute = parseMinuteFromTime(`${endRaw}:00`, 0);
            if (startMinute === endMinute) {
                showAutosaveToast('⚠️ A kezdés és befejezés nem lehet azonos', true);
                return;
            }

            const payload = {
                id: nextTempTimeBlockId--,
                block_type: 'weekly',
                days_mask: normalizeDaysMask(selectedDays),
                start_time: minutesToTimeString(startMinute),
                end_time: minutesToTimeString(endMinute),
                block_name: `Heti ${startRaw}-${endRaw}`,
                priority: 200,
                loop_style_id: styleId,
                is_active: 1,
                is_locked: 0,
                loops: []
            };

            if (!resolveScheduleConflicts(payload, null)) {
                showAutosaveToast('ℹ️ Ütközés miatt megszakítva', true);
                return;
            }

            timeBlocks.push(payload);
            closeTimeBlockModal();
            activeScope = `block:${payload.id}`;
            setActiveScope(activeScope, true);
            scheduleAutoSave(120);
            showAutosaveToast('✓ Heti idősáv létrehozva');
        }

        function createFixedWeeklyBlockFromInputs() {
            if (isDefaultGroup || isContentOnlyMode) {
                return;
            }

            const blockIdInput = document.getElementById('fixed-plan-block-id');
            const styleInput = document.getElementById('fixed-plan-loop-style');
            const startInput = document.getElementById('fixed-plan-start');
            const endInput = document.getElementById('fixed-plan-end');

            const loopStyleId = parseInt(styleInput?.value || activeLoopStyleId || defaultLoopStyleId || 0, 10);
            const editBlockId = parseInt(blockIdInput?.value || '0', 10);
            const selectedDays = Array.from(document.querySelectorAll('.fixed-plan-day-checkbox:checked')).map((el) => String(parseInt(el.value, 10))).filter((v) => /^[1-7]$/.test(v));
            const startRaw = String(startInput?.value || '').trim();
            const endRaw = String(endInput?.value || '').trim();

            if (!loopStyleId || selectedDays.length === 0 || !startRaw || !endRaw) {
                showAutosaveToast('⚠️ Add meg a napot, időt és loop stílust', true);
                return;
            }

            if (parseInt(loopStyleId, 10) === parseInt(defaultLoopStyleId || 0, 10)) {
                showAutosaveToast('⚠️ Az alap loop nem tervezhető, az üres időket automatikusan kitölti', true);
                return;
            }

            const startMinute = parseMinuteFromTime(`${startRaw}:00`, 0);
            const endMinute = parseMinuteFromTime(`${endRaw}:00`, 0);
            if (startMinute === endMinute) {
                showAutosaveToast('⚠️ A kezdés és befejezés nem lehet azonos', true);
                return;
            }

            const payload = {
                id: editBlockId > 0 ? editBlockId : nextTempTimeBlockId--,
                block_type: 'weekly',
                days_mask: normalizeDaysMask(selectedDays),
                start_time: minutesToTimeString(startMinute),
                end_time: minutesToTimeString(endMinute),
                block_name: `Heti ${startRaw}-${endRaw}`,
                priority: 200,
                loop_style_id: loopStyleId,
                is_active: 1,
                is_locked: 0,
                loops: []
            };

            if (!resolveScheduleConflicts(payload, editBlockId > 0 ? editBlockId : null)) {
                showAutosaveToast('ℹ️ Ütközés miatt megszakítva', true);
                return;
            }

            if (editBlockId > 0) {
                timeBlocks = timeBlocks.map((entry) => parseInt(entry.id, 10) === editBlockId ? { ...entry, ...payload } : entry);
            } else {
                timeBlocks.push(payload);
            }
            activeScope = `block:${payload.id}`;
            setActiveScope(activeScope, true);
            scheduleAutoSave(250);
            showAutosaveToast(editBlockId > 0 ? '✓ Heti idősáv frissítve' : '✓ Heti idősáv létrehozva');
        }

        function deleteSelectedWeeklyPlanBlock() {
            const idInput = document.getElementById('fixed-plan-block-id');
            const blockId = parseInt(idInput?.value || '0', 10);
            if (!blockId) {
                showAutosaveToast('ℹ️ Törléshez válassz ki egy heti idősávot', true);
                return;
            }

            const block = getWeeklyBlockById(blockId);
            if (!block) {
                showAutosaveToast('⚠️ A kiválasztott heti idősáv nem található', true);
                clearWeeklyPlanSelection(true);
                return;
            }

            if (!confirm(`Törlöd a heti idősávot?\n${getScopeLabel(block)}`)) {
                return;
            }

            timeBlocks = timeBlocks.filter((entry) => parseInt(entry.id, 10) !== blockId);
            activeScope = 'base';
            clearWeeklyPlanSelection(true);
            setActiveScope('base', true);
            scheduleAutoSave(250);
            showAutosaveToast('✓ Heti idősáv törölve');
        }

        function clearEntireSchedulePlan() {
            const weeklyCount = timeBlocks.filter((entry) => String(entry.block_type || 'weekly') === 'weekly').length;
            if (weeklyCount === 0) {
                showAutosaveToast('ℹ️ Nincs törölhető heti terv', true);
                return;
            }

            if (!confirm(`Biztosan törlöd a teljes heti tervet?\n${weeklyCount} heti idősáv lesz törölve.`)) {
                return;
            }

            timeBlocks = timeBlocks.filter((entry) => String(entry.block_type || 'weekly') !== 'weekly');
            activeScope = 'base';
            clearWeeklyPlanSelection(false);
            setActiveScope('base', true);
            scheduleAutoSave(250);
            showAutosaveToast('✓ Teljes heti terv törölve');
        }

        function setActiveLoopStyle(styleId) {
            persistActiveLoopStyleItems();
            const parsed = parseInt(styleId, 10);
            const style = getLoopStyleById(parsed);
            if (!style) {
                return;
            }
            activeLoopStyleId = parsed;
            loopItems = deepClone(style.items || []);
            normalizeLoopItems();
            persistActiveLoopStyleItems();
            renderLoopStyleSelector();
            renderLoop();
            updateActiveLoopVisualState();
            showAutosaveToast(`✓ Aktív loop: ${style.name}`);
        }

        function createLoopStyle() {
            if (isDefaultGroup || isContentOnlyMode) {
                return;
            }
            persistActiveLoopStyleItems();
            const name = prompt('Új loop neve:');
            if (!name || !String(name).trim()) {
                return;
            }
            const style = createFallbackLoopStyle(String(name).trim(), []);
            loopStyles.push(style);
            ensureSingleDefaultLoopStyle();
            hasOpenedLoopDetail = true;
            setActiveLoopStyle(style.id);
            scheduleAutoSave(250);
        }

        function duplicateLoopStyleById(styleId) {
            if (isDefaultGroup || isContentOnlyMode) {
                return;
            }

            persistActiveLoopStyleItems();
            const source = getLoopStyleById(styleId);
            if (!source) {
                showAutosaveToast('⚠️ A duplikálandó loop nem található', true);
                return;
            }

            const existingNames = new Set(loopStyles.map((entry) => String(entry.name || '').trim().toLowerCase()));
            const baseName = `${String(source.name || 'Loop').trim()} másolat`;
            let candidate = baseName;
            let suffix = 2;
            while (existingNames.has(candidate.toLowerCase())) {
                candidate = `${baseName} ${suffix}`;
                suffix += 1;
            }

            const duplicated = createFallbackLoopStyle(candidate, Array.isArray(source.items) ? source.items : []);
            loopStyles.push(duplicated);
            ensureSingleDefaultLoopStyle();
            hasOpenedLoopDetail = true;
            setActiveLoopStyle(duplicated.id);
            scheduleAutoSave(250);
            showAutosaveToast(`✓ Loop duplikálva: ${duplicated.name}`);
        }

        function duplicateActiveLoopStyle() {
            duplicateLoopStyleById(activeLoopStyleId);
        }

        function renameActiveLoopStyle() {
            if (isDefaultGroup || isContentOnlyMode) {
                return;
            }
            const style = getLoopStyleById(activeLoopStyleId);
            if (!style) {
                return;
            }
            const name = prompt('Loop új neve:', style.name || '');
            if (!name || !String(name).trim()) {
                return;
            }
            style.name = String(name).trim();
            renderLoopStyleSelector();
            renderWeeklyScheduleGrid();
            renderSpecialBlocksList();
            scheduleAutoSave(250);
        }

        function deleteLoopStyleById(styleId) {
            if (isDefaultGroup || isContentOnlyMode) {
                return;
            }
            if (loopStyles.length <= 1) {
                showAutosaveToast('⚠️ Legalább egy loop stílusnak maradnia kell', true);
                return;
            }
            const style = getLoopStyleById(styleId);
            if (!style) {
                return;
            }

            const deletedId = parseInt(style.id, 10);
            if (deletedId === parseInt(defaultLoopStyleId || 0, 10)) {
                showAutosaveToast('⚠️ Az alap loop nem törölhető', true);
                return;
            }

            if (!confirm(`Törlöd ezt a loopot?\n${style.name}`)) {
                return;
            }

            loopStyles = loopStyles.filter((entry) => parseInt(entry.id, 10) !== deletedId);
            timeBlocks = timeBlocks.filter((entry) => parseInt(entry.loop_style_id || 0, 10) !== deletedId);
            ensureSingleDefaultLoopStyle();

            if (parseInt(activeLoopStyleId || 0, 10) === deletedId) {
                const fallbackActive = parseInt(defaultLoopStyleId || 0, 10) || parseInt(loopStyles[0]?.id || 0, 10);
                if (fallbackActive) {
                    setActiveLoopStyle(fallbackActive);
                }
            } else {
                renderLoopStyleSelector();
            }
            renderWeeklyScheduleGrid();
            renderSpecialBlocksList();
            scheduleAutoSave(250);
        }

        function deleteActiveLoopStyle() {
            deleteLoopStyleById(activeLoopStyleId);
        }

        function setActiveAsDefaultLoopStyle() {
            showAutosaveToast('ℹ️ Az alap loop fix, másik loop nem állítható alapnak', true);
        }

        function persistCurrentScopeItems() {
            persistActiveLoopStyleItems();
        }

        function getScopeLabel(block) {
            const start = String(block.start_time || '00:00:00').slice(0, 5);
            const end = String(block.end_time || '00:00:00').slice(0, 5);
            if (block.block_type === 'date') {
                return `${block.specific_date || '—'} ${start}-${end} • ${block.block_name || 'Speciális'}`;
            }
            return `${start}-${end} • ${block.block_name || 'Heti blokk'}`;
        }

        function renderScopeSelector() {
            const selector = document.getElementById('loop-scope-select');
            if (selector) {
                selector.innerHTML = '';

                const baseOption = document.createElement('option');
                baseOption.value = 'base';
                baseOption.textContent = 'Alap loop (időblokkon kívül)';
                selector.appendChild(baseOption);

                timeBlocks.forEach((block) => {
                    const option = document.createElement('option');
                    option.value = `block:${block.id}`;
                    option.textContent = getScopeLabel(block);
                    selector.appendChild(option);
                });

                selector.value = activeScope;
            }

            renderWeeklyScheduleGrid();
            renderSpecialBlocksList();
        }

        function dayName(day) {
            const names = {
                1: 'H',
                2: 'K',
                3: 'Sze',
                4: 'Cs',
                5: 'P',
                6: 'Szo',
                7: 'V'
            };
            return names[day] || '?';
        }

        function getWeekStartDate(offsetWeeks) {
            const now = new Date();
            const day = now.getDay() === 0 ? 7 : now.getDay();
            const monday = new Date(now);
            monday.setHours(0, 0, 0, 0);
            monday.setDate(now.getDate() - (day - 1) + (offsetWeeks * 7));
            return monday;
        }

        function getDateForDayInOffsetWeek(day) {
            const monday = getWeekStartDate(scheduleWeekOffset);
            const target = new Date(monday);
            target.setDate(monday.getDate() + (day - 1));
            return target;
        }

        function toDateKey(dateObj) {
            return `${dateObj.getFullYear()}-${String(dateObj.getMonth() + 1).padStart(2, '0')}-${String(dateObj.getDate()).padStart(2, '0')}`;
        }

        function parseMinuteFromTime(timeValue, fallback = 0) {
            const raw = String(timeValue || '').trim();
            if (!raw) {
                return fallback;
            }
            const parts = raw.split(':');
            const hour = parseInt(parts[0], 10);
            const minute = parseInt(parts[1] || '0', 10);
            if (Number.isNaN(hour) || Number.isNaN(minute)) {
                return fallback;
            }
            const normalized = (hour * 60) + minute;
            return Math.max(0, Math.min(1439, normalized));
        }

        function minutesToTimeLabel(totalMinutes) {
            const safe = Math.max(0, Math.min(1439, parseInt(totalMinutes, 10) || 0));
            const hour = Math.floor(safe / 60);
            const minute = safe % 60;
            return `${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`;
        }

        function minutesToTimeString(totalMinutes) {
            const normalized = ((parseInt(totalMinutes, 10) || 0) % 1440 + 1440) % 1440;
            const hour = Math.floor(normalized / 60);
            const minute = normalized % 60;
            return `${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}:00`;
        }

        function getScheduleSlotCount() {
            return Math.floor(1440 / scheduleGridStepMinutes);
        }

        function clampMinuteToGrid(minuteValue) {
            const raw = Math.max(0, Math.min(1439, parseInt(minuteValue, 10) || 0));
            return Math.floor(raw / scheduleGridStepMinutes) * scheduleGridStepMinutes;
        }

        function getCurrentIsoWeekValue() {
            const today = new Date();
            const date = new Date(Date.UTC(today.getFullYear(), today.getMonth(), today.getDate()));
            const day = date.getUTCDay() || 7;
            date.setUTCDate(date.getUTCDate() + 4 - day);
            const yearStart = new Date(Date.UTC(date.getUTCFullYear(), 0, 1));
            const weekNo = Math.ceil((((date - yearStart) / 86400000) + 1) / 7);
            return `${date.getUTCFullYear()}-W${String(weekNo).padStart(2, '0')}`;
        }

        function getDateFromIsoWeek(weekValue) {
            const match = String(weekValue || '').match(/^(\d{4})-W(\d{2})$/);
            if (!match) {
                return null;
            }
            const year = parseInt(match[1], 10);
            const week = parseInt(match[2], 10);
            if (!year || !week) {
                return null;
            }

            const jan4 = new Date(year, 0, 4);
            const jan4Day = jan4.getDay() === 0 ? 7 : jan4.getDay();
            const firstMonday = new Date(jan4);
            firstMonday.setHours(0, 0, 0, 0);
            firstMonday.setDate(jan4.getDate() - (jan4Day - 1));

            const monday = new Date(firstMonday);
            monday.setDate(firstMonday.getDate() + ((week - 1) * 7));
            return monday;
        }

        function formatScheduleWeekOffsetLabel(offset) {
            const weekStart = getWeekStartDate(offset);
            const weekEnd = new Date(weekStart);
            weekEnd.setDate(weekStart.getDate() + 6);
            if (offset === 0) {
                return `Aktuális hét (${toDateKey(weekStart)} → ${toDateKey(weekEnd)})`;
            }
            if (offset === 1) {
                return `Jövő hét (${toDateKey(weekStart)} → ${toDateKey(weekEnd)})`;
            }
            if (offset === -1) {
                return `Előző hét (${toDateKey(weekStart)} → ${toDateKey(weekEnd)})`;
            }
            return `${toDateKey(weekStart)} → ${toDateKey(weekEnd)}`;
        }

        function renderScheduleWeekOffsetOptions() {
            const select = document.getElementById('schedule-week-offset');
            if (!select) {
                return;
            }

            select.innerHTML = '';
            for (let offset = -52; offset <= 52; offset += 1) {
                const option = document.createElement('option');
                option.value = String(offset);
                option.textContent = formatScheduleWeekOffsetLabel(offset);
                select.appendChild(option);
            }
            select.value = String(scheduleWeekOffset);

            const picker = document.getElementById('schedule-week-picker');
            if (picker) {
                const monday = getWeekStartDate(scheduleWeekOffset);
                const diffDays = Math.floor((monday - getWeekStartDate(0)) / 86400000);
                const targetDate = new Date();
                targetDate.setDate(targetDate.getDate() + diffDays);
                picker.value = getCurrentIsoWeekValue();
                const computed = getDateFromIsoWeek(picker.value);
                if (!computed || toDateKey(computed) !== toDateKey(monday)) {
                    const utcDate = new Date(Date.UTC(monday.getFullYear(), monday.getMonth(), monday.getDate()));
                    const day = utcDate.getUTCDay() || 7;
                    utcDate.setUTCDate(utcDate.getUTCDate() + 4 - day);
                    const yearStart = new Date(Date.UTC(utcDate.getUTCFullYear(), 0, 1));
                    const weekNo = Math.ceil((((utcDate - yearStart) / 86400000) + 1) / 7);
                    picker.value = `${utcDate.getUTCFullYear()}-W${String(weekNo).padStart(2, '0')}`;
                }
            }
        }

        function overlapsWithSlot(block, day, slotStartMinute, slotEndMinuteExclusive) {
            if (!block || block.block_type !== 'weekly') {
                return false;
            }
            const days = String(block.days_mask || '').split(',').map((v) => parseInt(v, 10));
            if (!days.includes(day)) {
                return false;
            }
            const startMinute = parseMinuteFromTime(block.start_time, 0);
            const endMinuteRaw = parseMinuteFromTime(block.end_time, 0);
            const endMinute = endMinuteRaw === 0 && startMinute > 0 ? 1440 : endMinuteRaw;
            if (startMinute < endMinute) {
                return slotStartMinute < endMinute && startMinute < slotEndMinuteExclusive;
            }
            return slotStartMinute >= startMinute || slotEndMinuteExclusive <= endMinute;
        }

        function renderWeeklyScheduleGrid() {
            const table = document.getElementById('weekly-schedule-grid');
            if (!table) {
                return;
            }

            scheduleWeekOffset = 0;
            renderScheduleWeekOffsetOptions();

            const weekLabel = document.getElementById('schedule-week-label');
            const weekStart = getWeekStartDate(scheduleWeekOffset);
            const weekEnd = new Date(weekStart);
            weekEnd.setDate(weekStart.getDate() + 6);
            if (weekLabel) {
                weekLabel.textContent = `Megjelenített hét: ${formatScheduleWeekOffsetLabel(scheduleWeekOffset)}`;
            }

            const todayKey = toDateKey(new Date());
            const isCurrentWeek = scheduleWeekOffset === 0;
            const rows = [];
            rows.push('<thead><tr><th class="hour-col">Óra</th>' + [1,2,3,4,5,6,7].map((d) => {
                const dt = getDateForDayInOffsetWeek(d);
                const dateKey = toDateKey(dt);
                const isToday = isCurrentWeek && dateKey === todayKey;
                const thClass = isToday ? ' class="schedule-day-today"' : '';
                const marker = isToday ? '<br><span style="font-size:10px; color:#1f3e56; font-weight:700;">Ma</span>' : '';
                return `<th${thClass}>${dayName(d)}<br><span style="font-size:10px; color:#607083;">${String(dt.getDate()).padStart(2, '0')}.${String(dt.getMonth() + 1).padStart(2, '0')}</span>${marker}</th>`;
            }).join('') + '</tr></thead>');
            rows.push('<tbody>');
            const slotCount = getScheduleSlotCount();
            for (let slotIndex = 0; slotIndex < slotCount; slotIndex += 1) {
                const slotStartMinute = slotIndex * scheduleGridStepMinutes;
                const slotEndMinuteExclusive = Math.min(1440, slotStartMinute + scheduleGridStepMinutes);
                const timeLabel = minutesToTimeLabel(slotStartMinute);
                rows.push(`<tr><td class="hour-col">${timeLabel}</td>`);
                for (let day = 1; day <= 7; day += 1) {
                    const dateKey = toDateKey(getDateForDayInOffsetWeek(day));
                    const isTodayCell = isCurrentWeek && dateKey === todayKey;
                    const isRangeSelected = !!scheduleRangeSelection
                        && scheduleRangeSelection.day === day
                        && slotStartMinute >= Math.min(scheduleRangeSelection.startMinute, scheduleRangeSelection.endMinute)
                        && slotStartMinute <= Math.max(scheduleRangeSelection.startMinute, scheduleRangeSelection.endMinute);
                    const isResizePreview = isHourInResizePreview(day, slotStartMinute);
                    const weeklyBlocks = timeBlocks.filter((block) => block.block_type === 'weekly' && overlapsWithSlot(block, day, slotStartMinute, slotEndMinuteExclusive));
                    const hasWeekly = weeklyBlocks.length > 0;
                    const isActive = weeklyBlocks.some((block) => activeScope === `block:${block.id}`);
                    const primaryBlock = weeklyBlocks.find((block) => activeScope === `block:${block.id}`) || weeklyBlocks[0] || null;
                    const primaryBlockId = primaryBlock ? parseInt(primaryBlock.id, 10) : 0;
                    const hasLocked = weeklyBlocks.some((block) => parseInt(block.is_locked || 0, 10) === 1);
                    const className = `schedule-cell${hasWeekly ? ' has-weekly' : ''}${isActive ? ' active-scope' : ''}${isTodayCell ? ' today' : ''}${(isRangeSelected || isResizePreview) ? ' range-select' : ''}${hasLocked ? ' locked' : ''}`;
                    const styleName = hasWeekly
                        ? (() => {
                            const styleId = parseInt(weeklyBlocks[0].loop_style_id || 0, 10);
                            const style = getLoopStyleById(styleId);
                            return style ? style.name : '';
                        })()
                        : 'Alap loop (idősávon kívül)';
                    const cellLabel = hasWeekly
                        ? `${styleName}${weeklyBlocks.length > 1 ? ` +${weeklyBlocks.length - 1}` : ''}`
                        : '';
                    rows.push(`<td class="${className}" data-day="${day}" data-minute="${slotStartMinute}" ondragover="allowScheduleDrop(event)" ondrop="dropLoopStyleToGrid(event, ${day}, ${slotStartMinute})" onmousedown="handleScheduleCellMouseDown(event, ${day}, ${slotStartMinute}, ${primaryBlockId})" onmouseenter="handleScheduleCellMouseEnter(${day}, ${slotStartMinute})" onmouseup="handleScheduleCellMouseUp(${day}, ${slotStartMinute})" title="${styleName}">${cellLabel ? `<span class='schedule-cell-label'>${cellLabel}</span>` : ''}</td>`);
                }
                rows.push('</tr>');
            }
            rows.push('</tbody>');
            table.innerHTML = rows.join('');
            table.classList.remove('step-60', 'step-30', 'step-15');
            table.classList.add(`step-${scheduleGridStepMinutes}`);
            table.classList.toggle('selecting', !!scheduleRangeSelection || !!scheduleBlockResize);
        }

        function getWeeklyBlockById(blockId) {
            const normalized = parseInt(blockId, 10);
            if (!normalized) {
                return null;
            }
            return timeBlocks.find((block) => parseInt(block.id, 10) === normalized && String(block.block_type || 'weekly') === 'weekly') || null;
        }

        function getWeeklyBlockResizeBaseRange(block) {
            if (!block || String(block.block_type || 'weekly') !== 'weekly') {
                return null;
            }
            const startMinute = clampMinuteToGrid(parseMinuteFromTime(block.start_time, 0));
            const endMinuteRaw = clampMinuteToGrid(parseMinuteFromTime(block.end_time, 0));
            const endExclusive = endMinuteRaw === 0 && startMinute > 0 ? 1440 : endMinuteRaw;
            if (endExclusive <= startMinute) {
                return null;
            }
            return { startMinute, endExclusive };
        }

        function isHourInResizePreview(day, hour) {
            if (!scheduleBlockResize || parseInt(scheduleBlockResize.day, 10) !== parseInt(day, 10)) {
                return false;
            }
            const preview = getScheduleBlockResizePreview(scheduleBlockResize);
            if (!preview) {
                return false;
            }
            return hour >= preview.startMinute && hour <= preview.endMinuteInclusive;
        }

        function getScheduleBlockResizePreview(state) {
            if (!state) {
                return null;
            }

            const baseStart = parseInt(state.baseStartMinute, 10);
            const baseEndExclusive = parseInt(state.baseEndExclusive, 10);
            const currentMinute = clampMinuteToGrid(parseInt(state.currentMinute, 10));

            if (Number.isNaN(baseStart) || Number.isNaN(baseEndExclusive) || Number.isNaN(currentMinute)) {
                return null;
            }

            if (state.mode === 'start') {
                const newStart = Math.max(0, Math.min(currentMinute, baseEndExclusive - scheduleGridStepMinutes));
                return {
                    startMinute: newStart,
                    endMinuteInclusive: baseEndExclusive - scheduleGridStepMinutes
                };
            }

            const newEndInclusive = Math.min(1440 - scheduleGridStepMinutes, Math.max(currentMinute, baseStart));
            return {
                startMinute: baseStart,
                endMinuteInclusive: newEndInclusive
            };
        }

        function getSelectedLoopStyleForSchedule(forcedLoopStyleId = null) {
            const selectedStyleId = forcedLoopStyleId !== null
                ? parseInt(forcedLoopStyleId, 10)
                : parseInt(activeLoopStyleId || defaultLoopStyleId || 0, 10);
            if (!selectedStyleId) {
                showAutosaveToast('⚠️ Előbb válassz vagy hozz létre loop stílust', true);
                return null;
            }
            return selectedStyleId;
        }

        function createScheduleBlockFromRange(day, startMinute, endMinuteInclusive, loopStyleId = null) {
            if (isDefaultGroup || isContentOnlyMode) {
                return;
            }

            const selectedStyleId = getSelectedLoopStyleForSchedule(loopStyleId);
            if (!selectedStyleId) {
                return;
            }
            if (parseInt(selectedStyleId, 10) === parseInt(defaultLoopStyleId || 0, 10)) {
                showAutosaveToast('⚠️ Az alap loop nem tervezhető, az üres időket automatikusan kitölti', true);
                return;
            }

            const minMinute = clampMinuteToGrid(Math.min(startMinute, endMinuteInclusive));
            const maxMinute = clampMinuteToGrid(Math.max(startMinute, endMinuteInclusive));
            const start = minutesToTimeString(minMinute);
            const endExclusive = Math.min(1440, maxMinute + scheduleGridStepMinutes);
            const end = minutesToTimeString(endExclusive >= 1440 ? 0 : endExclusive);
            const targetDate = getDateForDayInOffsetWeek(day);
            const dateKey = toDateKey(targetDate);

            const payload = scheduleWeekOffset === 0
                ? {
                    id: nextTempTimeBlockId--,
                    block_type: 'weekly',
                    days_mask: String(day),
                    start_time: start,
                    end_time: end,
                    block_name: `${dayName(day)} ${minutesToTimeLabel(minMinute)}-${minutesToTimeLabel(endExclusive >= 1440 ? 0 : endExclusive)}`,
                    priority: 100,
                    loop_style_id: selectedStyleId,
                    is_active: 1,
                    loops: []
                }
                : {
                    id: nextTempTimeBlockId--,
                    block_type: 'date',
                    specific_date: dateKey,
                    start_time: start,
                    end_time: end,
                    days_mask: '',
                    block_name: `Speciális ${dateKey}`,
                    priority: 300,
                    loop_style_id: selectedStyleId,
                    is_active: 1,
                    loops: []
                };

            if (!resolveScheduleConflicts(payload, null)) {
                showAutosaveToast('ℹ️ Ütközés miatt megszakítva', true);
                return;
            }

            timeBlocks.push(payload);
            activeScope = `block:${payload.id}`;
            setActiveScope(activeScope, true);
            scheduleAutoSave(250);
            showAutosaveToast('✓ Idősáv létrehozva');
        }

        function startScheduleRangeSelection(event, day, hour) {
            if (isDefaultGroup || isContentOnlyMode) {
                return;
            }
            if (event && event.button !== 0) {
                return;
            }
            scheduleRangeSelection = {
                day: parseInt(day, 10),
                startMinute: clampMinuteToGrid(parseInt(hour, 10)),
                endMinute: clampMinuteToGrid(parseInt(hour, 10))
            };
            renderWeeklyScheduleGrid();
        }

        function startScheduleBlockResize(blockId, day, hour, mode) {
            if (isDefaultGroup || isContentOnlyMode) {
                return;
            }

            const block = getWeeklyBlockById(blockId);
            if (!block) {
                return;
            }

            const days = String(block.days_mask || '').split(',').map((v) => parseInt(v, 10)).filter((v) => v >= 1 && v <= 7);
            const dayInt = parseInt(day, 10);
            if (!days.includes(dayInt)) {
                return;
            }

            const baseRange = getWeeklyBlockResizeBaseRange(block);
            if (!baseRange) {
                showAutosaveToast('⚠️ Éjfélen átnyúló blokk közvetlen nyújtása itt nem támogatott', true);
                return;
            }

            scheduleBlockResize = {
                blockId: parseInt(block.id, 10),
                day: dayInt,
                mode: mode === 'start' ? 'start' : 'end',
                baseStartMinute: baseRange.startMinute,
                baseEndExclusive: baseRange.endExclusive,
                currentMinute: clampMinuteToGrid(parseInt(hour, 10))
            };
            activeScope = `block:${parseInt(block.id, 10)}`;
            renderWeeklyScheduleGrid();
        }

        function updateScheduleBlockResize(day, hour) {
            if (!scheduleBlockResize) {
                return;
            }
            const dayInt = parseInt(day, 10);
            if (scheduleBlockResize.day !== dayInt) {
                return;
            }
            scheduleBlockResize.currentMinute = clampMinuteToGrid(parseInt(hour, 10));
            renderWeeklyScheduleGrid();
        }

        function finishScheduleBlockResize(day = null, hour = null) {
            if (!scheduleBlockResize) {
                return;
            }

            const fallbackDay = scheduleBlockResize.day;
            const fallbackHour = scheduleBlockResize.currentMinute;
            const dayInt = day === null ? fallbackDay : parseInt(day, 10);
            const hourInt = hour === null ? fallbackHour : parseInt(hour, 10);
            if (dayInt !== fallbackDay || Number.isNaN(hourInt)) {
                scheduleBlockResize = null;
                renderWeeklyScheduleGrid();
                return;
            }

            scheduleBlockResize.currentMinute = clampMinuteToGrid(hourInt);
            const preview = getScheduleBlockResizePreview(scheduleBlockResize);
            const block = getWeeklyBlockById(scheduleBlockResize.blockId);
            const resizeState = { ...scheduleBlockResize };
            scheduleBlockResize = null;

            if (!preview || !block) {
                renderWeeklyScheduleGrid();
                return;
            }

            const newStart = minutesToTimeString(preview.startMinute);
            const endExclusive = preview.endMinuteInclusive + scheduleGridStepMinutes;
            const normalizedEnd = endExclusive >= 1440 ? 0 : endExclusive;
            const newEnd = minutesToTimeString(normalizedEnd);

            const candidate = {
                ...block,
                start_time: newStart,
                end_time: newEnd,
                days_mask: String(resizeState.day)
            };

            if (!resolveScheduleConflicts(candidate, parseInt(block.id, 10))) {
                renderWeeklyScheduleGrid();
                showAutosaveToast('ℹ️ Ütközés miatt a nyújtás megszakítva', true);
                return;
            }

            timeBlocks = timeBlocks.map((entry) => {
                if (parseInt(entry.id, 10) !== parseInt(block.id, 10)) {
                    return entry;
                }
                return {
                    ...entry,
                    start_time: newStart,
                    end_time: newEnd,
                    days_mask: String(resizeState.day)
                };
            });

            activeScope = `block:${parseInt(block.id, 10)}`;
            setActiveScope(activeScope, true);
            scheduleAutoSave(250);
            showAutosaveToast('✓ Időblokk frissítve');
        }

        function cancelScheduleBlockResize() {
            if (!scheduleBlockResize) {
                return;
            }
            scheduleBlockResize = null;
            renderWeeklyScheduleGrid();
        }

        function handleScheduleCellMouseDown(event, day, hour, primaryBlockId) {
            if (isDefaultGroup || isContentOnlyMode) {
                return;
            }
            if (event && event.button !== 0) {
                return;
            }

            const blockId = parseInt(primaryBlockId || 0, 10);
            if (blockId > 0 && event?.currentTarget) {
                setActiveScope(`block:${blockId}`, true);
                return;
            }

            const minute = clampMinuteToGrid(parseInt(hour, 10));
            clearWeeklyPlanSelection(true);
            document.querySelectorAll('.fixed-plan-day-checkbox').forEach((el) => {
                el.checked = String(el.value) === String(parseInt(day, 10));
            });
            const startInput = document.getElementById('fixed-plan-start');
            const endInput = document.getElementById('fixed-plan-end');
            if (startInput) {
                startInput.value = minutesToTimeLabel(minute);
            }
            if (endInput) {
                const endMinute = (minute + scheduleGridStepMinutes) % 1440;
                endInput.value = minutesToTimeLabel(endMinute);
            }
            showAutosaveToast('ℹ️ A heti terv szerkesztő mező kitöltve');
        }

        function handleScheduleCellMouseEnter(day, hour) {
            if (scheduleBlockResize) {
                updateScheduleBlockResize(day, hour);
                return;
            }
            updateScheduleRangeSelection(day, hour);
        }

        function handleScheduleCellMouseUp(day, hour) {
            if (scheduleBlockResize) {
                finishScheduleBlockResize(day, hour);
                return;
            }
            finishScheduleRangeSelection(day, hour);
        }

        function updateScheduleRangeSelection(day, hour) {
            if (!scheduleRangeSelection) {
                return;
            }
            const d = parseInt(day, 10);
            if (scheduleRangeSelection.day !== d) {
                return;
            }
            scheduleRangeSelection.endMinute = clampMinuteToGrid(parseInt(hour, 10));
            renderWeeklyScheduleGrid();
        }

        function finishScheduleRangeSelection(day = null, hour = null) {
            if (!scheduleRangeSelection) {
                return;
            }

            const fallbackDay = scheduleRangeSelection.day;
            const fallbackHour = scheduleRangeSelection.endMinute;
            const d = day === null ? fallbackDay : parseInt(day, 10);
            const resolvedHour = hour === null ? fallbackHour : parseInt(hour, 10);

            if (scheduleRangeSelection.day !== d || Number.isNaN(resolvedHour)) {
                scheduleRangeSelection = null;
                renderWeeklyScheduleGrid();
                return;
            }

            const startHour = scheduleRangeSelection.startMinute;
            const endHour = resolvedHour;
            scheduleRangeSelection = null;
            renderWeeklyScheduleGrid();
            createScheduleBlockFromRange(d, startHour, endHour, null);
        }

        function cancelScheduleRangeSelection() {
            if (!scheduleRangeSelection) {
                return;
            }
            scheduleRangeSelection = null;
            renderWeeklyScheduleGrid();
        }

        function allowScheduleDrop(event) {
            if (event) {
                event.preventDefault();
            }
        }

        function dropLoopStyleToGrid(event, day, hour) {
            if (event) {
                event.preventDefault();
            }
            showAutosaveToast('ℹ️ Drag/drop helyett használd a fix heti sáv panelt', true);
        }

        function renderSpecialBlocksList() {
            const wrap = document.getElementById('special-blocks-list');
            if (!wrap) {
                return;
            }

            const searchTerm = String(document.getElementById('special-date-search')?.value || '').trim().toLowerCase();
            const focusedDate = String(document.getElementById('special-day-focus')?.value || '').trim();

            const specialBlocks = timeBlocks
                .filter((block) => block.block_type === 'date')
                .filter((block) => {
                    if (focusedDate && String(block.specific_date || '') !== focusedDate) {
                        return false;
                    }
                    if (!searchTerm) {
                        return true;
                    }
                    const haystack = `${String(block.specific_date || '')} ${String(block.block_name || '')} ${String(block.start_time || '')} ${String(block.end_time || '')}`.toLowerCase();
                    return haystack.includes(searchTerm);
                })
                .sort((a, b) => String(a.specific_date || '').localeCompare(String(b.specific_date || '')) || String(a.start_time).localeCompare(String(b.start_time)));

            if (specialBlocks.length === 0) {
                wrap.innerHTML = `<div class="item"><span class="muted">${searchTerm ? 'Nincs találat a keresésre.' : 'Nincs speciális dátumos idősáv.'}</span></div>`;
                return;
            }

            wrap.innerHTML = specialBlocks.map((block) => {
                const active = activeScope === `block:${block.id}` ? ' style="font-weight:700;"' : '';
                const style = getLoopStyleById(block.loop_style_id || 0);
                return `<div class="item">
                    <span${active}>${block.specific_date} ${String(block.start_time).slice(0,5)}-${String(block.end_time).slice(0,5)} • ${block.block_name} • ${style ? style.name : 'N/A'}</span>
                    <button class="btn" type="button" onclick="setActiveScope('block:${block.id}', true)">Szerkesztés</button>
                </div>`;
            }).join('');
        }

        function openSpecialDayPlanner() {
            const dateVal = String(document.getElementById('special-day-focus')?.value || '').trim();
            if (!dateVal) {
                showAutosaveToast('⚠️ Előbb válassz napot', true);
                return;
            }

            const dayBlocks = timeBlocks
                .filter((block) => String(block.block_type || 'weekly') === 'date' && String(block.specific_date || '') === dateVal)
                .sort((a, b) => String(a.start_time || '').localeCompare(String(b.start_time || '')));

            const host = document.getElementById('time-block-modal-host');
            if (!host) {
                return;
            }

            host.innerHTML = `
                <div style="position:fixed; inset:0; background:rgba(0,0,0,0.45); display:flex; align-items:center; justify-content:center; z-index:3200;">
                    <div style="background:#fff; width:min(680px,94vw); border:1px solid #cfd6dd; padding:16px;">
                        <h3 style="margin:0 0 8px 0;">Speciális napi terv • ${dateVal}</h3>
                        <div style="font-size:12px; color:#425466; margin-bottom:10px;">Ez a napi terv felülírja az aznapi heti tervet az érintett idősávokban.</div>
                        <div style="max-height:220px; overflow:auto; border:1px solid #d9e0e7; margin-bottom:10px;">
                            ${dayBlocks.length === 0
                                ? '<div style="padding:8px; font-size:12px; color:#607080;">Nincs még speciális idősáv erre a napra.</div>'
                                : dayBlocks.map((block) => {
                                    const styleName = getLoopStyleName(block.loop_style_id || 0);
                                    return `<div style="display:grid; grid-template-columns:1fr auto auto; gap:8px; align-items:center; padding:8px; border-bottom:1px solid #edf1f4;">
                                        <span style="font-size:12px; color:#2b3f52;">${String(block.start_time).slice(0, 5)}-${String(block.end_time).slice(0, 5)} • ${styleName}</span>
                                        <button type="button" class="btn" onclick="setActiveScope('block:${block.id}', true); closeTimeBlockModal();">Szerkesztés</button>
                                        <button type="button" class="btn btn-danger" onclick="deleteSpecialDayBlock(${block.id}, '${dateVal}')">Törlés</button>
                                    </div>`;
                                }).join('')}
                        </div>
                        <div style="display:grid; grid-template-columns:120px 120px 1fr auto; gap:8px; align-items:center; margin-bottom:12px;">
                            <input id="special-day-plan-start" type="time" value="08:00" step="60">
                            <input id="special-day-plan-end" type="time" value="10:00" step="60">
                            <select id="special-day-plan-loop">
                                ${loopStyles
                                    .filter((style) => parseInt(style.id, 10) !== parseInt(defaultLoopStyleId || 0, 10))
                                    .map((style) => `<option value="${style.id}">${String(style.name || 'Loop')}</option>`)
                                    .join('')}
                            </select>
                            <button type="button" class="btn" onclick="addSpecialDayBlockFromPlanner('${dateVal}')">Hozzáadás</button>
                        </div>
                        <div style="display:flex; justify-content:flex-end;">
                            <button type="button" class="btn" onclick="closeTimeBlockModal()">Bezárás</button>
                        </div>
                    </div>
                </div>
            `;
        }

        function addSpecialDayBlockFromPlanner(dateVal) {
            const startVal = String(document.getElementById('special-day-plan-start')?.value || '').trim();
            const endVal = String(document.getElementById('special-day-plan-end')?.value || '').trim();
            const styleId = parseInt(document.getElementById('special-day-plan-loop')?.value || 0, 10);

            if (!dateVal || !startVal || !endVal || !styleId) {
                showAutosaveToast('⚠️ Hiányos speciális napi adat', true);
                return;
            }

            const payload = {
                id: nextTempTimeBlockId--,
                block_name: `Speciális ${dateVal}`,
                block_type: 'date',
                specific_date: dateVal,
                start_time: `${startVal}:00`,
                end_time: `${endVal}:00`,
                days_mask: '',
                priority: 300,
                loop_style_id: styleId,
                is_active: 1,
                loops: []
            };

            if (!resolveScheduleConflicts(payload, null)) {
                return;
            }

            timeBlocks.push(payload);
            activeScope = `block:${payload.id}`;
            setActiveScope(activeScope, true);
            scheduleAutoSave(120);
            showAutosaveToast('✓ Speciális napi idősáv létrehozva');
            openSpecialDayPlanner();
        }

        function deleteSpecialDayBlock(blockId, dateVal) {
            const normalized = parseInt(blockId, 10);
            if (!normalized) {
                return;
            }
            timeBlocks = timeBlocks.filter((entry) => parseInt(entry.id, 10) !== normalized);
            if (activeScope === `block:${normalized}`) {
                activeScope = 'base';
            }
            setActiveScope(activeScope, true);
            scheduleAutoSave(120);
            showAutosaveToast('✓ Speciális napi idősáv törölve');
            if (dateVal) {
                openSpecialDayPlanner();
            }
        }

        function setActiveScope(scope, shouldRender = true) {
            activeScope = scope;
            renderScopeSelector();
            syncWeeklyPlannerFromScope();
            if (shouldRender) renderSpecialBlocksList();
        }

        function handleScopeChange(scope) {
            setActiveScope(scope, true);
        }

        function blockMatchesDateTime(block, dt) {
            if (!block || !dt) {
                return false;
            }

            const hhmmss = `${String(dt.getHours()).padStart(2, '0')}:${String(dt.getMinutes()).padStart(2, '0')}:00`;
            const start = String(block.start_time || '00:00:00');
            const end = String(block.end_time || '00:00:00');
            const timeMatch = start <= end
                ? (hhmmss >= start && hhmmss <= end)
                : (hhmmss >= start || hhmmss <= end);
            if (!timeMatch) {
                return false;
            }

            if (String(block.block_type || 'weekly') === 'date') {
                const dateStr = `${dt.getFullYear()}-${String(dt.getMonth() + 1).padStart(2, '0')}-${String(dt.getDate()).padStart(2, '0')}`;
                return dateStr === String(block.specific_date || '');
            }

            const weekday = dt.getDay() === 0 ? 7 : dt.getDay();
            const days = String(block.days_mask || '').split(',').map((v) => parseInt(v, 10));
            return days.includes(weekday);
        }

        function resolveScopeByDateTime(dt) {
            const matching = timeBlocks.filter((block) => block.is_active !== 0 && blockMatchesDateTime(block, dt));
            if (matching.length === 0) {
                return 'base';
            }

            matching.sort((a, b) => {
                const typeWeightA = String(a.block_type || 'weekly') === 'date' ? 2 : 1;
                const typeWeightB = String(b.block_type || 'weekly') === 'date' ? 2 : 1;
                if (typeWeightA !== typeWeightB) {
                    return typeWeightB - typeWeightA;
                }
                const pa = parseInt(a.priority || 0, 10);
                const pb = parseInt(b.priority || 0, 10);
                if (pa !== pb) {
                    return pb - pa;
                }
                return parseInt(a.id, 10) - parseInt(b.id, 10);
            });

            return `block:${matching[0].id}`;
        }

        function changeScheduleWeek(delta) {
            scheduleWeekOffset = 0;
            showAutosaveToast('ℹ️ Fix heti terv módban nincs hétlapozás', true);
            renderWeeklyScheduleGrid();
        }

        function setScheduleWeekOffset(value) {
            scheduleWeekOffset = 0;
            showAutosaveToast('ℹ️ Fix heti terv módban mindig az aktuális heti minta látszik', true);
            renderWeeklyScheduleGrid();
        }

        function openScheduleWeekPicker() {
            const picker = document.getElementById('schedule-week-picker');
            if (!picker) {
                return;
            }
            if (typeof picker.showPicker === 'function') {
                picker.showPicker();
                return;
            }
            picker.focus();
            picker.click();
        }

        function setScheduleWeekFromPicker(value) {
            scheduleWeekOffset = 0;
            showAutosaveToast('ℹ️ Fix heti terv módban nincs dátum szerinti lapozás', true);
            renderWeeklyScheduleGrid();
        }

        function setScheduleGridStep(value) {
            const parsed = parseInt(value, 10);
            if (![15, 30, 60].includes(parsed)) {
                return;
            }
            scheduleGridStepMinutes = parsed;
            scheduleRangeSelection = null;
            scheduleBlockResize = null;
            renderWeeklyScheduleGrid();
        }

        function buildLoopPayload() {
            persistActiveLoopStyleItems();
            const defaultStyle = getLoopStyleById(defaultLoopStyleId) || getLoopStyleById(activeLoopStyleId) || { items: [] };
            const expandedTimeBlocks = deepClone(timeBlocks).map((block) => {
                const style = getLoopStyleById(block.loop_style_id || 0);
                return {
                    ...block,
                    loops: deepClone(style?.items || [])
                };
            });

            return {
                base_loop: deepClone(defaultStyle.items || []),
                time_blocks: expandedTimeBlocks,
                loop_styles: deepClone(loopStyles),
                default_loop_style_id: defaultLoopStyleId,
                schedule_blocks: deepClone(timeBlocks)
            };
        }

        function getLoopSnapshot() {
            return JSON.stringify(buildLoopPayload());
        }

        function scheduleAutoSave(delayMs = 700) {
            if (isDefaultGroup || !hasLoadedInitialLoop) {
                return;
            }
            queueDraftPersist(delayMs);
        }

        function isTechnicalLoopItem(item) {
            if (!item) {
                return false;
            }

            if ((item.module_key || '') === 'unconfigured') {
                return true;
            }

            if (!technicalModule) {
                return false;
            }

            return parseInt(item.module_id) === parseInt(technicalModule.id);
        }

        function hasRealModules(items = loopItems) {
            return items.some(item => !isTechnicalLoopItem(item));
        }

        function normalizeLoopItems() {
            if (!technicalModule) {
                return;
            }

            const realItems = loopItems.filter(item => !isTechnicalLoopItem(item));

            if (realItems.length > 0) {
                loopItems = realItems;
                return;
            }

            const existingTechnical = loopItems.find(item => isTechnicalLoopItem(item));

            if (existingTechnical) {
                loopItems = [{
                    ...existingTechnical,
                    module_key: 'unconfigured',
                    duration_seconds: 60
                }];
                return;
            }

            loopItems = [{
                module_id: parseInt(technicalModule.id),
                module_name: technicalModule.name,
                description: technicalModule.description || 'Technikai modul – csak üres loop esetén.',
                module_key: 'unconfigured',
                duration_seconds: 60,
                settings: {}
            }];
        }
        
        // Calculate total loop duration
        function getTotalLoopDuration() {
            return loopItems.reduce((sum, item) => sum + parseInt(item.duration_seconds || 10), 0);
        }
        
        // Get elapsed time in current loop cycle
        function getElapsedTimeInLoop() {
            let elapsed = 0;
            for (let i = 0; i < currentPreviewIndex; i++) {
                elapsed += parseInt(loopItems[i].duration_seconds || 10);
            }
            elapsed += (Date.now() - currentModuleStartTime) / 1000;
            return elapsed;
        }
        
        // Load existing loop configuration
        function loadLoop() {
            if (isDefaultGroup) {
                const defaultItem = getDefaultUnconfiguredItem();
                loopStyles = [{ id: -1, name: 'Alap loop', items: defaultItem ? [defaultItem] : [] }];
                activeLoopStyleId = -1;
                defaultLoopStyleId = -1;
                loopItems = deepClone(loopStyles[0].items || []);
                timeBlocks = [];
                setActiveScope('base', true);
                renderLoopStyleSelector();
                hasLoadedInitialLoop = true;
                lastSavedSnapshot = getLoopSnapshot();
                lastPublishedPayload = JSON.parse(lastSavedSnapshot);
                setDraftDirty(false);
                if (loopItems.length > 0) {
                    setTimeout(() => startPreview(), 500);
                }
                return;
            }

            const baselineItem = getDefaultUnconfiguredItem();
            loopStyles = [{ id: -1, name: 'Alap loop', items: baselineItem ? [baselineItem] : [] }];
            defaultLoopStyleId = -1;
            activeLoopStyleId = -1;
            loopItems = deepClone(loopStyles[0].items || []);
            timeBlocks = [];
            activeScope = 'base';
            setActiveScope('base', false);
            renderLoopStyleSelector();
            renderScopeSelector();
            renderLoop();
            hasLoadedInitialLoop = true;
            lastSavedSnapshot = getLoopSnapshot();

            fetch(`../api/group_loop_config.php?group_id=${groupId}`)
                .then(response => response.json())
                .then(data => {
                    if (!data || !data.success) {
                        showAutosaveToast('⚠️ A loop lista betöltése sikertelen, alap loop használatban', true);
                        return;
                    }

                    const plannerStyles = Array.isArray(data.loop_styles) ? data.loop_styles : [];
                    if (plannerStyles.length > 0) {
                        loopStyles = plannerStyles.map((style, idx) => ({
                            id: parseInt(style.id ?? -(idx + 1), 10),
                            name: String(style.name || `Loop ${idx + 1}`),
                            items: Array.isArray(style.items) ? style.items : []
                        }));
                        defaultLoopStyleId = parseInt(data.default_loop_style_id ?? loopStyles[0]?.id ?? 0, 10) || loopStyles[0]?.id || null;
                        timeBlocks = normalizeTimeBlocks(data.schedule_blocks || data.time_blocks || []);
                    } else {
                        const hasStructuredPayload = Array.isArray(data.base_loop) || Array.isArray(data.time_blocks);
                        const baseItems = hasStructuredPayload
                            ? (Array.isArray(data.base_loop) ? data.base_loop : [])
                            : (Array.isArray(data.loops) ? data.loops : []);
                        loopStyles = [createFallbackLoopStyle('Alap loop', baseItems)];
                        defaultLoopStyleId = loopStyles[0].id;

                        timeBlocks = normalizeTimeBlocks(data.time_blocks || []);
                        timeBlocks = timeBlocks.map((block, index) => {
                            const style = createFallbackLoopStyle(block.block_name || `Idősáv ${index + 1}`, Array.isArray(block.loops) ? block.loops : []);
                            loopStyles.push(style);
                            return { ...block, loop_style_id: style.id };
                        });
                    }

                    planVersionToken = String(data.plan_version || data.plan_version_token || data.loop_version || '');

                    ensureSingleDefaultLoopStyle();

                    activeLoopStyleId = parseInt(defaultLoopStyleId || loopStyles[0]?.id || 0, 10) || (loopStyles[0]?.id ?? null);
                    loopItems = deepClone(getLoopStyleById(activeLoopStyleId)?.items || []);
                    normalizeLoopItems();
                    persistActiveLoopStyleItems();
                    activeScope = 'base';
                    setActiveScope('base', false);
                    lastSavedSnapshot = getLoopSnapshot();
                    lastPublishedPayload = JSON.parse(lastSavedSnapshot);
                    hasLoadedInitialLoop = true;
                    tryRestoreDraftFromCache();
                    updatePendingSaveBar();
                    renderLoopStyleSelector();
                    renderScopeSelector();
                    renderLoop();
                    
                    // Automatikus preview indítás ha van loop
                    if (loopItems.length > 0) {
                        setTimeout(() => startPreview(), 500);
                    }
                })
                .catch(error => {
                    console.error('Error loading loop:', error);
                    showAutosaveToast('⚠️ Hálózati hiba, alap loop használatban', true);
                });
        }
        
        function addModuleToLoop(moduleId, moduleName, moduleDesc) {
            if (isDefaultGroup || isContentOnlyMode) {
                return;
            }

            if (!getLoopStyleById(activeLoopStyleId)) {
                showAutosaveToast('⚠️ Nincs aktív loop kiválasztva', true);
                return;
            }

            const moduleKey = getModuleKeyById(moduleId);

            if (moduleKey !== 'unconfigured') {
                loopItems = loopItems.filter(item => !isTechnicalLoopItem(item));
            } else if (loopItems.some(item => isTechnicalLoopItem(item))) {
                return;
            }

            loopItems.push({
                module_id: moduleId,
                module_name: moduleName,
                description: moduleDesc,
                module_key: moduleKey || null,
                duration_seconds: moduleKey === 'unconfigured' ? 60 : 10
            });

            normalizeLoopItems();
            renderLoop();
            scheduleAutoSave();
        }

        function handleModuleCatalogDragStart(event, payload) {
            if (isDefaultGroup || isContentOnlyMode || !event?.dataTransfer || !payload) {
                return;
            }

            const data = {
                id: parseInt(payload.id || 0, 10),
                name: String(payload.name || ''),
                description: String(payload.description || '')
            };

            if (!data.id || !data.name) {
                return;
            }

            event.dataTransfer.effectAllowed = 'copy';
            event.dataTransfer.setData('text/module-catalog-item', JSON.stringify(data));
        }

        function allowModuleCatalogDrop(event) {
            if (!event) {
                return;
            }
            event.preventDefault();
            const container = document.getElementById('loop-container');
            if (container) {
                container.classList.add('catalog-drop-active');
            }
        }

        function handleModuleCatalogDragLeave(event) {
            const container = document.getElementById('loop-container');
            if (!container) {
                return;
            }

            const related = event?.relatedTarget;
            if (related && container.contains(related)) {
                return;
            }
            container.classList.remove('catalog-drop-active');
        }

        function dropCatalogModuleToLoop(event) {
            if (!event) {
                return;
            }

            event.preventDefault();
            const container = document.getElementById('loop-container');
            if (container) {
                container.classList.remove('catalog-drop-active');
            }

            if (isDefaultGroup || isContentOnlyMode || !event.dataTransfer) {
                return;
            }

            if (!getLoopStyleById(activeLoopStyleId)) {
                showAutosaveToast('⚠️ Nincs aktív loop kiválasztva', true);
                return;
            }

            const raw = event.dataTransfer.getData('text/module-catalog-item');
            if (!raw) {
                return;
            }

            try {
                const data = JSON.parse(raw);
                const moduleId = parseInt(data.id || 0, 10);
                const moduleName = String(data.name || '').trim();
                const moduleDesc = String(data.description || '');
                if (!moduleId || !moduleName) {
                    return;
                }
                addModuleToLoop(moduleId, moduleName, moduleDesc);
            } catch (error) {
                console.error('Invalid module drop payload', error);
            }
        }
        
        function removeFromLoop(index) {
            if (isDefaultGroup || isContentOnlyMode) {
                return;
            }

            loopItems.splice(index, 1);
            normalizeLoopItems();
            renderLoop();
            scheduleAutoSave();
        }

        function duplicateLoopItem(index) {
            if (isDefaultGroup || isContentOnlyMode) {
                return;
            }

            const sourceItem = loopItems[index];
            if (!sourceItem || isTechnicalLoopItem(sourceItem)) {
                return;
            }

            const duplicatedItem = {
                module_id: sourceItem.module_id,
                module_name: sourceItem.module_name,
                description: sourceItem.description || '',
                module_key: sourceItem.module_key || null,
                duration_seconds: parseInt(sourceItem.duration_seconds || 10),
                settings: sourceItem.settings
                    ? JSON.parse(JSON.stringify(sourceItem.settings))
                    : {}
            };

            loopItems.splice(index + 1, 0, duplicatedItem);
            normalizeLoopItems();
            renderLoop();
            scheduleAutoSave();
            showAutosaveToast('✓ Elem duplikálva');
        }
        
        function updateDuration(index, value) {
            if (isDefaultGroup || isContentOnlyMode) {
                return;
            }

            if (isTechnicalLoopItem(loopItems[index])) {
                loopItems[index].duration_seconds = 60;
                updateTotalDuration();
                scheduleAutoSave();
                if (loopItems.length > 0) {
                    startPreview();
                }
                return;
            }

            loopItems[index].duration_seconds = parseInt(value) || 10;
            updateTotalDuration();
            scheduleAutoSave();
            if (loopItems.length > 0) {
                startPreview();
            }
        }
        
        let draggedElement = null;
        
        function handleDragStart(e) {
            draggedElement = this;
            this.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/html', this.innerHTML);
        }
        
        function handleDragOver(e) {
            if (e.preventDefault) {
                e.preventDefault();
            }
            e.dataTransfer.dropEffect = 'move';
            return false;
        }
        
        function handleDrop(e) {
            if (e.stopPropagation) {
                e.stopPropagation();
            }
            
            if (draggedElement !== this) {
                const draggedIndex = parseInt(draggedElement.dataset.index);
                const targetIndex = parseInt(this.dataset.index);
                
                const item = loopItems.splice(draggedIndex, 1)[0];
                loopItems.splice(targetIndex, 0, item);
                
                renderLoop();
                scheduleAutoSave();
            }
            
            return false;
        }
        
        function handleDragEnd(e) {
            this.classList.remove('dragging');
        }
        
        function updateTotalDuration() {
            const total = loopItems.reduce((sum, item) => sum + parseInt(item.duration_seconds), 0);
            const minutes = Math.floor(total / 60);
            const seconds = total % 60;
            document.getElementById('total-duration').textContent = `Össz: ${total} mp (${minutes}:${seconds.toString().padStart(2, '0')})`;
        }
        
        function clearLoop() {
            if (isDefaultGroup || isContentOnlyMode) {
                return;
            }

            if (confirm('Biztosan törölni szeretnéd az összes elemet?')) {
                loopItems = [];
                normalizeLoopItems();
                renderLoop();
                scheduleAutoSave();
            }
        }
        
        function saveLoop(options = {}) {
            const opts = {
                silent: false,
                source: 'publish',
                ...options
            };

            if (isDefaultGroup) {
                if (!opts.silent) {
                    alert('⚠️ A default csoport loopja nem szerkeszthető.');
                }
                return;
            }

            const payload = buildLoopPayload();
            const totalItemCount = (payload.base_loop || []).length + (payload.time_blocks || []).reduce((sum, block) => {
                return sum + (Array.isArray(block.loops) ? block.loops.length : 0);
            }, 0);

            if (totalItemCount === 0) {
                if (!opts.silent) {
                    alert('⚠️ A loop üres! Adj hozzá legalább egy modult.');
                }
                return;
            }

            const currentSnapshot = getLoopSnapshot();
            if (currentSnapshot === lastSavedSnapshot) {
                return;
            }

            if (autoSaveInFlight) {
                autoSaveQueued = true;
                return;
            }

            autoSaveInFlight = true;
            
            fetch(`../api/group_loop_config.php?group_id=${groupId}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(payload)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    lastSavedSnapshot = currentSnapshot;
                    lastPublishedPayload = JSON.parse(currentSnapshot);
                    planVersionToken = String(data.plan_version || data.plan_version_token || data.loop_version || planVersionToken || '');
                    clearDraftCache();
                    setDraftDirty(false);
                    showAutosaveToast('✓ Mentés sikeres');
                } else {
                    showAutosaveToast('⚠️ ' + (data.message || 'Mentési hiba'), true);
                }
            })
            .catch(error => {
                showAutosaveToast('⚠️ Hiba történt: ' + error, true);
            })
            .finally(() => {
                autoSaveInFlight = false;
                if (autoSaveQueued) {
                    autoSaveQueued = false;
                    queueDraftPersist(150);
                }
            });
        }

        function getActiveTimeBlock() {
            if (activeScope === 'base') {
                return null;
            }
            const blockId = parseInt(String(activeScope).replace('block:', ''), 10);
            return timeBlocks.find((entry) => parseInt(entry.id, 10) === blockId) || null;
        }

        function getDayShortLabel(day) {
            const map = {
                '1': 'H',
                '2': 'K',
                '3': 'Sze',
                '4': 'Cs',
                '5': 'P',
                '6': 'Szo',
                '7': 'V'
            };
            return map[String(day)] || '?';
        }

        function hasBlockOverlap(candidate, ignoredId = null) {
            const cStart = String(candidate.start_time || '00:00:00');
            const cEnd = String(candidate.end_time || '00:00:00');
            const cType = String(candidate.block_type || 'weekly');
            const cDays = new Set(String(candidate.days_mask || '').split(',').map(v => parseInt(v, 10)).filter(v => v >= 1 && v <= 7));
            const cDate = String(candidate.specific_date || '');

            const toSegments = (startRaw, endRaw) => {
                const startMinute = parseMinuteFromTime(startRaw, 0);
                let endMinute = parseMinuteFromTime(endRaw, 0);

                if (endMinute === startMinute) {
                    return [[0, 1440]];
                }

                if (endMinute > startMinute) {
                    return [[startMinute, endMinute]];
                }

                if (endMinute === 0) {
                    return [[startMinute, 1440]];
                }

                return [
                    [startMinute, 1440],
                    [0, endMinute]
                ];
            };

            const rangesOverlap = (aStart, aEnd, bStart, bEnd) => {
                const segA = toSegments(aStart, aEnd);
                const segB = toSegments(bStart, bEnd);
                return segA.some(([a0, a1]) => segB.some(([b0, b1]) => a0 < b1 && b0 < a1));
            };

            return timeBlocks.some((existing) => {
                if (!existing || (ignoredId !== null && parseInt(existing.id, 10) === parseInt(ignoredId, 10))) {
                    return false;
                }

                if (String(existing.block_type || 'weekly') !== cType) {
                    return false;
                }

                if (cType === 'date') {
                    if (String(existing.specific_date || '') !== cDate) {
                        return false;
                    }
                } else {
                    const eDays = new Set(String(existing.days_mask || '').split(',').map(v => parseInt(v, 10)).filter(v => v >= 1 && v <= 7));
                    const commonDay = Array.from(cDays).some((d) => eDays.has(d));
                    if (!commonDay) {
                        return false;
                    }
                }

                return rangesOverlap(
                    cStart,
                    cEnd,
                    String(existing.start_time || '00:00:00'),
                    String(existing.end_time || '00:00:00')
                );
            });
        }

        function createWeeklyBlockFromGrid(day, hour, forcedLoopStyleId = null) {
            createScheduleBlockFromRange(parseInt(day, 10), parseInt(hour, 10), parseInt(hour, 10), forcedLoopStyleId);
        }

        function createSpecialDateBlockFromInputs() {
            if (isDefaultGroup || isContentOnlyMode) {
                return;
            }
            const dateVal = String(document.getElementById('special-date-input')?.value || '').trim();
            const startVal = String(document.getElementById('special-start-input')?.value || '').trim();
            const endVal = String(document.getElementById('special-end-input')?.value || '').trim();
            const styleId = parseInt(activeLoopStyleId || defaultLoopStyleId || 0, 10);

            if (!dateVal || !startVal || !endVal || !styleId) {
                showAutosaveToast('⚠️ Add meg a dátumot és időt', true);
                return;
            }

            if (parseInt(styleId, 10) === parseInt(defaultLoopStyleId || 0, 10)) {
                showAutosaveToast('⚠️ Az alap loop nem tervezhető', true);
                return;
            }

            const payload = {
                id: nextTempTimeBlockId--,
                block_type: 'date',
                specific_date: dateVal,
                start_time: `${startVal}:00`,
                end_time: `${endVal}:00`,
                block_name: `Speciális ${dateVal}`,
                priority: 300,
                loop_style_id: styleId,
                is_active: 1,
                loops: []
            };

            if (!resolveScheduleConflicts(payload, null)) {
                return;
            }

            timeBlocks.push(payload);
            activeScope = `block:${payload.id}`;
            setActiveScope(activeScope, true);
            scheduleAutoSave(120);
            showAutosaveToast('✓ Speciális esemény hozzáadva');
        }

        function openTimeBlockModal(block = null, preset = null) {
            if (isDefaultGroup || isContentOnlyMode) {
                return;
            }

            const host = document.getElementById('time-block-modal-host');
            if (!host) {
                return;
            }

            const editing = !!block;
            const merged = { ...(preset || {}), ...(block || {}) };
            const selectedDays = new Set(String(merged.days_mask || '1,2,3,4,5,6,7').split(',').map(v => String(parseInt(v, 10))).filter(v => /^[1-7]$/.test(v)));
            const blockType = String(merged.block_type || 'weekly') === 'date' ? 'date' : 'weekly';
            const specificDate = merged.specific_date ? String(merged.specific_date).slice(0, 10) : '';
            const priority = Number.isFinite(parseInt(merged.priority, 10)) ? parseInt(merged.priority, 10) : (blockType === 'date' ? 300 : 100);
            const selectedLoopStyleId = parseInt(merged.loop_style_id || activeLoopStyleId || defaultLoopStyleId || 0, 10);
            const loopStyleOptions = loopStyles.map((style) => {
                const selected = parseInt(style.id, 10) === selectedLoopStyleId ? 'selected' : '';
                return `<option value="${style.id}" ${selected}>${String(style.name || 'Loop')}</option>`;
            }).join('');

            host.innerHTML = `
                <div style="position:fixed; inset:0; background:rgba(0,0,0,0.45); display:flex; align-items:center; justify-content:center; z-index:3200;">
                    <div style="background:#fff; width:min(560px,92vw); border:1px solid #cfd6dd; padding:16px;">
                        <h3 style="margin:0 0 12px 0;">${editing ? 'Időblokk szerkesztése' : 'Új időblokk'}</h3>
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                            <div>
                                <label style="display:block; font-size:12px; margin-bottom:4px;">Típus</label>
                                <select id="tb-type" style="width:100%;">
                                    <option value="weekly" ${blockType === 'weekly' ? 'selected' : ''}>Heti</option>
                                    <option value="date" ${blockType === 'date' ? 'selected' : ''}>Speciális dátum</option>
                                </select>
                            </div>
                            <div>
                                <label style="display:block; font-size:12px; margin-bottom:4px;">Prioritás</label>
                                <input id="tb-priority" type="number" min="1" max="999" value="${priority}" style="width:100%;">
                            </div>
                            <div style="grid-column:1 / span 2;">
                                <label style="display:block; font-size:12px; margin-bottom:4px;">Loop stílus</label>
                                <select id="tb-loop-style" style="width:100%;">${loopStyleOptions}</select>
                            </div>
                            <div style="grid-column:1 / span 2;">
                                <label style="display:block; font-size:12px; margin-bottom:4px;">Név</label>
                                <input id="tb-name" type="text" value="${(merged.block_name || '').replace(/"/g, '&quot;')}" style="width:100%;">
                            </div>
                            <div id="tb-date-wrap" style="grid-column:1 / span 2; ${blockType === 'date' ? '' : 'display:none;'}">
                                <label style="display:block; font-size:12px; margin-bottom:4px;">Dátum</label>
                                <input id="tb-date" type="date" value="${specificDate}" style="width:100%;">
                            </div>
                            <div>
                                <label style="display:block; font-size:12px; margin-bottom:4px;">Kezdés</label>
                                <input id="tb-start" type="time" value="${String(merged.start_time || '08:00:00').slice(0,5)}" style="width:100%;">
                            </div>
                            <div>
                                <label style="display:block; font-size:12px; margin-bottom:4px;">Vége</label>
                                <input id="tb-end" type="time" value="${String(merged.end_time || '12:00:00').slice(0,5)}" style="width:100%;">
                            </div>
                            <div id="tb-days-wrap" style="grid-column:1 / span 2; ${blockType === 'weekly' ? '' : 'display:none;'}">
                                <label style="display:block; font-size:12px; margin-bottom:6px;">Napok</label>
                                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                                    ${[1,2,3,4,5,6,7].map((day) => {
                                        const d = String(day);
                                        const checked = selectedDays.has(d) ? 'checked' : '';
                                        return `<label style=\"display:flex; align-items:center; gap:4px; font-size:12px;\"><input type=\"checkbox\" class=\"tb-day\" value=\"${d}\" ${checked}>${getDayShortLabel(d)}</label>`;
                                    }).join('')}
                                </div>
                            </div>
                        </div>
                        <div style="margin-top:14px; display:flex; justify-content:flex-end; gap:8px;">
                            <button type="button" class="btn" onclick="closeTimeBlockModal()">Mégse</button>
                            <button type="button" class="btn btn-primary" onclick="saveTimeBlockModal(${editing ? 'true' : 'false'}, ${editing ? parseInt(block.id, 10) : 'null'})">Mentés</button>
                        </div>
                    </div>
                </div>
            `;

            const typeEl = document.getElementById('tb-type');
            if (typeEl) {
                typeEl.addEventListener('change', function () {
                    const isDate = this.value === 'date';
                    const dateWrap = document.getElementById('tb-date-wrap');
                    const daysWrap = document.getElementById('tb-days-wrap');
                    if (dateWrap) dateWrap.style.display = isDate ? '' : 'none';
                    if (daysWrap) daysWrap.style.display = isDate ? 'none' : '';
                });
            }
        }

        function closeTimeBlockModal() {
            const host = document.getElementById('time-block-modal-host');
            if (host) {
                host.innerHTML = '';
            }
        }

        function saveTimeBlockModal(isEdit, editId) {
            const nameInput = document.getElementById('tb-name');
            const typeInput = document.getElementById('tb-type');
            const dateInput = document.getElementById('tb-date');
            const priorityInput = document.getElementById('tb-priority');
            const startInput = document.getElementById('tb-start');
            const endInput = document.getElementById('tb-end');
            const dayCheckboxes = Array.from(document.querySelectorAll('.tb-day:checked'));

            const name = String(nameInput?.value || '').trim();
            const blockType = String(typeInput?.value || 'weekly') === 'date' ? 'date' : 'weekly';
            const loopStyleInput = document.getElementById('tb-loop-style');
            const specificDate = String(dateInput?.value || '').trim();
            const priority = parseInt(priorityInput?.value, 10) || (blockType === 'date' ? 300 : 100);
            const loopStyleId = parseInt(loopStyleInput?.value || activeLoopStyleId || defaultLoopStyleId || 0, 10);
            const start = String(startInput?.value || '').trim();
            const end = String(endInput?.value || '').trim();
            const days = dayCheckboxes.map((el) => String(el.value));

            if (!name) {
                alert('⚠️ Adj meg blokk nevet.');
                return;
            }
            if (!start || !end) {
                alert('⚠️ Adj meg kezdési és zárási időt.');
                return;
            }
            if (blockType === 'weekly' && days.length === 0) {
                alert('⚠️ Jelölj ki legalább egy napot.');
                return;
            }
            if (blockType === 'date' && !specificDate) {
                alert('⚠️ Válassz dátumot.');
                return;
            }
            if (!loopStyleId) {
                alert('⚠️ Válassz loop stílust az idősávhoz.');
                return;
            }
            if (parseInt(loopStyleId, 10) === parseInt(defaultLoopStyleId || 0, 10)) {
                alert('⚠️ Az alap loop nem tervezhető. Az üres sávokat automatikusan kitölti.');
                return;
            }

            const blockId = isEdit ? parseInt(editId, 10) : nextTempTimeBlockId--;
            const payload = {
                id: blockId,
                block_name: name,
                block_type: blockType,
                specific_date: blockType === 'date' ? specificDate : null,
                start_time: `${start}:00`,
                end_time: `${end}:00`,
                days_mask: blockType === 'weekly' ? normalizeDaysMask(days) : '',
                priority: priority,
                loop_style_id: loopStyleId,
                is_active: 1,
                loops: isEdit
                    ? (timeBlocks.find((block) => parseInt(block.id, 10) === parseInt(editId, 10))?.loops || [])
                    : []
            };

            if (!resolveScheduleConflicts(payload, isEdit ? parseInt(editId, 10) : null)) {
                alert('Ütközés miatt a mentés megszakítva.');
                return;
            }

            if (isEdit) {
                timeBlocks = timeBlocks.map((block) => parseInt(block.id, 10) === parseInt(editId, 10) ? payload : block);
                if (activeScope === `block:${editId}`) {
                    activeScope = `block:${payload.id}`;
                }
            } else {
                timeBlocks.push(payload);
                activeScope = `block:${payload.id}`;
            }

            closeTimeBlockModal();
            setActiveScope(activeScope, true);
            scheduleAutoSave(250);
            showAutosaveToast('✓ Időblokk mentve');
        }

        function editCurrentTimeBlock() {
            const block = getActiveTimeBlock();
            if (!block) {
                showAutosaveToast('ℹ️ Válassz egy időblokkot szerkesztéshez', true);
                return;
            }
            openTimeBlockModal(block);
        }

        function deleteCurrentTimeBlock() {
            const block = getActiveTimeBlock();
            if (!block) {
                showAutosaveToast('ℹ️ Nincs kiválasztott időblokk', true);
                return;
            }

            if (String(block.block_type || 'weekly') === 'date') {
                showAutosaveToast('⚠️ Speciális dátum blokk itt nem törölhető', true);
                return;
            }

            if (!confirm(`Biztosan törlöd ezt az időblokkot?\n${getScopeLabel(block)}`)) {
                return;
            }

            timeBlocks = timeBlocks.filter((entry) => parseInt(entry.id, 10) !== parseInt(block.id, 10));
            activeScope = 'base';
            setActiveScope('base', true);
            scheduleAutoSave(250);
            showAutosaveToast('✓ Időblokk törölve');
        }
        
        // Module customization
        function customizeModule(index) {
            if (isDefaultGroup) {
                return;
            }

            const item = loopItems[index];
            const moduleKey = item.module_key || getModuleKeyById(item.module_id);
            
            // Initialize settings if not exists
            if (!item.settings) {
                item.settings = getDefaultSettings(moduleKey);
            }
            
            showCustomizationModal(item, index);
        }

        function updateTechnicalModuleVisibility() {
            const unconfiguredItem = document.getElementById('unconfiguredModuleItem');
            const noModulesMessage = document.getElementById('noModulesMessage');
            const normalModuleCount = document.querySelectorAll('.modules-panel .module-item:not(#unconfiguredModuleItem)').length;
            const realModulesExist = hasRealModules();

            if (isDefaultGroup) {
                if (unconfiguredItem) {
                    unconfiguredItem.style.display = 'block';
                }
                if (noModulesMessage) {
                    noModulesMessage.style.display = 'block';
                }
                return;
            }

            if (unconfiguredItem) {
                unconfiguredItem.style.display = realModulesExist ? 'none' : 'block';
            }

            if (noModulesMessage) {
                const hasVisibleTechnical = !!unconfiguredItem && !realModulesExist;
                noModulesMessage.style.display = (normalModuleCount === 0 && !hasVisibleTechnical) ? 'block' : 'none';
            }
        }
        
        function getModuleKeyById(moduleId) {
            // Try to find module key from available modules
            const modules = modulesCatalog;
            const module = modules.find(m => m.id == moduleId);
            return module ? module.module_key : null;
        }
        
        function getDefaultSettings(moduleKey) {
            const defaults = {
                'clock': {
                    type: 'digital',
                    format: '24h',
                    dateFormat: 'full',
                    timeColor: '#ffffff',
                    dateColor: '#ffffff',
                    bgColor: '#1e40af',
                    fontSize: 120,
                    clockSize: 300,
                    showSeconds: true,
                    language: 'hu'
                },
                'default-logo': {
                    text: 'EDUDISPLEJ',
                    fontSize: 120,
                    textColor: '#ffffff',
                    bgColor: '#1e40af',
                    showVersion: true,
                    version: 'v1.0'
                }
            };
            
            return defaults[moduleKey] || {};
        }
        
        function showCustomizationModal(item, index) {
            const moduleKey = item.module_key || getModuleKeyById(item.module_id);
            const settings = item.settings || {};
            
            let formHtml = '';
            
            // Generate form based on module type
            if (moduleKey === 'clock') {
                formHtml = `
                    <div style="display: grid; gap: 15px;">
                        <div>
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">Típus:</label>
                            <select id="setting-type" style="width: 100%; padding: 8px; border-radius: 5px; border: 1px solid #ccc;">
                                <option value="digital" ${settings.type === 'digital' ? 'selected' : ''}>Digitális</option>
                                <option value="analog" ${settings.type === 'analog' ? 'selected' : ''}>Analóg</option>
                            </select>
                        </div>
                        
                        <div>
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">Formátum:</label>
                            <select id="setting-format" style="width: 100%; padding: 8px; border-radius: 5px; border: 1px solid #ccc;">
                                <option value="24h" ${settings.format === '24h' ? 'selected' : ''}>24 órás</option>
                                <option value="12h" ${settings.format === '12h' ? 'selected' : ''}>12 órás (AM/PM)</option>
                            </select>
                        </div>
                        
                        <div>
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">Dátum formátum:</label>
                            <select id="setting-dateFormat" style="width: 100%; padding: 8px; border-radius: 5px; border: 1px solid #ccc;">
                                <option value="full" ${settings.dateFormat === 'full' ? 'selected' : ''}>Teljes (év, hónap, nap, napnév)</option>
                                <option value="short" ${settings.dateFormat === 'short' ? 'selected' : ''}>Rövid (év, hónap, nap)</option>
                                <option value="numeric" ${settings.dateFormat === 'numeric' ? 'selected' : ''}>Numerikus (ÉÉÉÉ.HH.NN)</option>
                                <option value="none" ${settings.dateFormat === 'none' ? 'selected' : ''}>Nincs dátum</option>
                            </select>
                        </div>
                        
                        <div>
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">Nyelv:</label>
                            <select id="setting-language" style="width: 100%; padding: 8px; border-radius: 5px; border: 1px solid #ccc;">
                                <option value="hu" ${settings.language === 'hu' ? 'selected' : ''}>Magyar</option>
                                <option value="sk" ${settings.language === 'sk' ? 'selected' : ''}>Szlovák</option>
                            </select>
                        </div>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                            <div>
                                <label style="display: block; margin-bottom: 5px; font-weight: bold;">Óra szín:</label>
                                <input type="color" id="setting-timeColor" value="${settings.timeColor || '#ffffff'}" style="width: 100%; height: 40px; border-radius: 5px;">
                            </div>
                            
                            <div>
                                <label style="display: block; margin-bottom: 5px; font-weight: bold;">Dátum szín:</label>
                                <input type="color" id="setting-dateColor" value="${settings.dateColor || '#ffffff'}" style="width: 100%; height: 40px; border-radius: 5px;">
                            </div>
                        </div>
                        
                        <div>
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">Háttérszín:</label>
                            <input type="color" id="setting-bgColor" value="${settings.bgColor || '#1e40af'}" style="width: 100%; height: 40px; border-radius: 5px;">
                        </div>
                        
                        <div id="digitalSettings" style="${settings.type === 'analog' ? 'display: none;' : ''}">
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">Betűméret (px):</label>
                            <input type="number" id="setting-fontSize" value="${settings.fontSize || 120}" min="50" max="300" style="width: 100%; padding: 8px; border-radius: 5px; border: 1px solid #ccc;">
                        </div>
                        
                        <div id="analogSettings" style="${settings.type === 'digital' ? 'display: none;' : ''}">
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">Óra mérete (px):</label>
                            <input type="number" id="setting-clockSize" value="${settings.clockSize || 300}" min="200" max="600" style="width: 100%; padding: 8px; border-radius: 5px; border: 1px solid #ccc;">
                        </div>
                        
                        <div>
                            <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                                <input type="checkbox" id="setting-showSeconds" ${settings.showSeconds !== false ? 'checked' : ''} style="width: 20px; height: 20px;">
                                <span style="font-weight: bold;">Másodpercek mutatása</span>
                            </label>
                        </div>
                    </div>
                `;
            } else if (moduleKey === 'default-logo') {
                formHtml = `
                    <div style="display: grid; gap: 15px;">
                        <div>
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">Szöveg:</label>
                            <input type="text" id="setting-text" value="${settings.text || 'EDUDISPLEJ'}" style="width: 100%; padding: 8px; border-radius: 5px; border: 1px solid #ccc;">
                        </div>
                        
                        <div>
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">Betűméret (px):</label>
                            <input type="number" id="setting-fontSize" value="${settings.fontSize || 120}" min="50" max="300" style="width: 100%; padding: 8px; border-radius: 5px; border: 1px solid #ccc;">
                        </div>
                        
                        <div>
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">Szöveg szín:</label>
                            <input type="color" id="setting-textColor" value="${settings.textColor || '#ffffff'}" style="width: 100%; height: 40px; border-radius: 5px;">
                        </div>
                        
                        <div>
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">Háttérszín:</label>
                            <input type="color" id="setting-bgColor" value="${settings.bgColor || '#1e40af'}" style="width: 100%; height: 40px; border-radius: 5px;">
                        </div>
                        
                        <div>
                            <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                                <input type="checkbox" id="setting-showVersion" ${settings.showVersion !== false ? 'checked' : ''} style="width: 20px; height: 20px;">
                                <span style="font-weight: bold;">Verzió mutatása</span>
                            </label>
                        </div>
                        
                        <div id="versionSettings" style="${settings.showVersion === false ? 'display: none;' : ''}">
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">Verzió szöveg:</label>
                            <input type="text" id="setting-version" value="${settings.version || 'v1.0'}" style="width: 100%; padding: 8px; border-radius: 5px; border: 1px solid #ccc;">
                        </div>
                    </div>
                `;
            } else {
                formHtml = '<p style="text-align: center; color: #999;">Ez a modul nem rendelkezik testreszabási lehetőségekkel.</p>';
            }
            
            const modal = document.createElement('div');
            modal.style.cssText = `
                display: flex;
                position: fixed;
                z-index: 2000;
                left: 0;
                top: 0;
                width: 100%;
                height: 100%;
                background-color: rgba(0, 0, 0, 0.5);
                align-items: center;
                justify-content: center;
            `;
            
            modal.innerHTML = `
                <div style="
                    background: white;
                    padding: 30px;
                    border-radius: 12px;
                    max-width: 600px;
                    width: 90%;
                    max-height: 80vh;
                    overflow-y: auto;
                    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
                ">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h2 style="margin: 0;">⚙️ ${item.module_name} - Testreszabás</h2>
                        <button onclick="this.closest('div').parentElement.parentElement.remove()" style="
                            background: #1e40af;
                            color: white;
                            border: none;
                            font-size: 16px;
                            cursor: pointer;
                            width: 36px;
                            height: 36px;
                            border-radius: 50%;
                        ">✕</button>
                    </div>
                    
                    ${formHtml}
                    
                    <div style="margin-top: 30px; display: flex; gap: 10px; justify-content: flex-end;">
                        <button onclick="this.closest('div').parentElement.parentElement.remove()" style="
                            padding: 10px 20px;
                            background: #6c757d;
                            color: white;
                            border: none;
                            border-radius: 5px;
                            cursor: pointer;
                        ">Mégse</button>
                        <button onclick="saveCustomization(${index})" style="
                            padding: 10px 20px;
                            background: #28a745;
                            color: white;
                            border: none;
                            border-radius: 5px;
                            cursor: pointer;
                        ">💾 Mentés</button>
                    </div>
                </div>
            `;
            
            document.body.appendChild(modal);
            
            // Add event listeners for dynamic form changes
            const typeSelect = document.getElementById('setting-type');
            if (typeSelect) {
                typeSelect.addEventListener('change', function() {
                    const digitalSettings = document.getElementById('digitalSettings');
                    const analogSettings = document.getElementById('analogSettings');
                    if (this.value === 'digital') {
                        digitalSettings.style.display = 'block';
                        analogSettings.style.display = 'none';
                    } else {
                        digitalSettings.style.display = 'none';
                        analogSettings.style.display = 'block';
                    }
                });
            }
            
            const showVersionCheckbox = document.getElementById('setting-showVersion');
            if (showVersionCheckbox) {
                showVersionCheckbox.addEventListener('change', function() {
                    const versionSettings = document.getElementById('versionSettings');
                    versionSettings.style.display = this.checked ? 'block' : 'none';
                });
            }
        }
        
        function saveCustomization(index) {
            if (isDefaultGroup) {
                return;
            }

            const item = loopItems[index];
            const moduleKey = item.module_key || getModuleKeyById(item.module_id);
            
            const newSettings = {};
            
            // Collect all settings from form
            if (moduleKey === 'clock') {
                newSettings.type = document.getElementById('setting-type')?.value || 'digital';
                newSettings.format = document.getElementById('setting-format')?.value || '24h';
                newSettings.dateFormat = document.getElementById('setting-dateFormat')?.value || 'full';
                newSettings.timeColor = document.getElementById('setting-timeColor')?.value || '#ffffff';
                newSettings.dateColor = document.getElementById('setting-dateColor')?.value || '#ffffff';
                newSettings.bgColor = document.getElementById('setting-bgColor')?.value || '#1e40af';
                newSettings.fontSize = parseInt(document.getElementById('setting-fontSize')?.value) || 120;
                newSettings.clockSize = parseInt(document.getElementById('setting-clockSize')?.value) || 300;
                newSettings.showSeconds = document.getElementById('setting-showSeconds')?.checked !== false;
                newSettings.language = document.getElementById('setting-language')?.value || 'hu';
            } else if (moduleKey === 'default-logo') {
                newSettings.text = document.getElementById('setting-text')?.value || 'EDUDISPLEJ';
                newSettings.fontSize = parseInt(document.getElementById('setting-fontSize')?.value) || 120;
                newSettings.textColor = document.getElementById('setting-textColor')?.value || '#ffffff';
                newSettings.bgColor = document.getElementById('setting-bgColor')?.value || '#1e40af';
                newSettings.showVersion = document.getElementById('setting-showVersion')?.checked !== false;
                newSettings.version = document.getElementById('setting-version')?.value || 'v1.0';
            }
            
            loopItems[index].settings = newSettings;
            
            // Close modal
            document.querySelectorAll('body > div').forEach(el => {
                if (el.style.position === 'fixed' && el.style.zIndex === '2000') {
                    el.remove();
                }
            });
            
            showAutosaveToast('✓ Beállítások mentve');
            scheduleAutoSave(250);
            if (loopItems.length > 0) {
                startPreview();
            }
        }
        
        // ===== LIVE PREVIEW FUNCTIONS =====
        
        function startPreview() {
            if (loopItems.length === 0) {
                alert('⚠️ Nincs modul a loop-ban!');
                return;
            }
            
            stopPreview(); // Clear any existing preview
            isPaused = false;
            currentPreviewIndex = 0;
            loopCycleCount = 0;
            totalLoopStartTime = Date.now();
            
            document.getElementById('btnPlay').style.display = 'none';
            document.getElementById('btnPause').style.display = 'inline-block';
            document.getElementById('loopStatus').textContent = 'Lejátszás...';
            
            playCurrentModule();
        }
        
        function pausePreview() {
            if (isPaused) {
                // Resume
                isPaused = false;
                document.getElementById('btnPause').innerHTML = '⏸️ Szünet';
                document.getElementById('loopStatus').textContent = 'Lejátszás...';
                playCurrentModule();
            } else {
                // Pause
                isPaused = true;
                document.getElementById('btnPause').innerHTML = '▶️ Folytatás';
                document.getElementById('loopStatus').textContent = 'Szüneteltetve';
                clearTimeout(previewTimeout);
                clearInterval(previewInterval);
            }
        }
        
        function stopPreview() {
            isPaused = false;
            currentPreviewIndex = 0;
            loopCycleCount = 0;
            
            clearTimeout(previewTimeout);
            clearInterval(previewInterval);
            
            document.getElementById('btnPlay').style.display = 'inline-block';
            document.getElementById('btnPause').style.display = 'none';
            document.getElementById('loopStatus').textContent = 'Leállítva';
            document.getElementById('currentModule').textContent = '—';
            document.getElementById('progressBar').style.width = '0%';
            document.getElementById('progressText').textContent = '0s / 0s';
            document.getElementById('loopCount').textContent = '0';
            document.getElementById('navInfo').textContent = '—';
            
            // Hide iframe, show empty message
            document.getElementById('previewIframe').style.display = 'none';
            document.getElementById('previewEmpty').style.display = 'block';
        }
        
        function previousModule() {
            if (loopItems.length === 0) return;
            
            // Stop current playback
            clearTimeout(previewTimeout);
            clearInterval(previewInterval);
            
            // Go to previous module
            currentPreviewIndex--;
            if (currentPreviewIndex < 0) {
                currentPreviewIndex = loopItems.length - 1;
                if (loopCycleCount > 0) loopCycleCount--;
            }
            
            // Update cycle count display
            document.getElementById('loopCount').textContent = loopCycleCount;
            
            // Play the module
            playCurrentModule();
        }
        
        function nextModule() {
            if (loopItems.length === 0) return;
            
            // Stop current playback
            clearTimeout(previewTimeout);
            clearInterval(previewInterval);
            
            // Go to next module
            currentPreviewIndex++;
            if (currentPreviewIndex >= loopItems.length) {
                currentPreviewIndex = 0;
                loopCycleCount++;
            }
            
            // Update cycle count display
            document.getElementById('loopCount').textContent = loopCycleCount;
            
            // Play the module
            playCurrentModule();
        }
        
        function playCurrentModule() {
            if (isPaused) return;
            
            // Ha nincs elem a loop-ban, ne fusson
            if (loopItems.length === 0) {
                stopPreview();
                return;
            }
            
            // Ha csak 1 elem van, akkor is loopoljon
            const module = loopItems[currentPreviewIndex];
            const duration = parseInt(module.duration_seconds) || 10;
            
            // Update info
            document.getElementById('currentModule').textContent = `${currentPreviewIndex + 1}. ${module.module_name}`;
            document.getElementById('navInfo').textContent = `${currentPreviewIndex + 1} / ${loopItems.length}`;
            
            // Build module URL with settings
            const moduleUrl = buildModuleUrl(module);
            
            // Load module in iframe
            const iframe = document.getElementById('previewIframe');
            const emptyDiv = document.getElementById('previewEmpty');
            
            iframe.src = moduleUrl;
            iframe.style.display = 'block';
            emptyDiv.style.display = 'none';
            
            // Start progress bar
            currentModuleStartTime = Date.now();
            updateProgressBar(duration);
            
            // MINDIG schedule-ölj következő modult (még 1 elem esetén is loop)
            previewTimeout = setTimeout(() => {
                currentPreviewIndex++;
                
                if (currentPreviewIndex >= loopItems.length) {
                    currentPreviewIndex = 0;
                    loopCycleCount++;
                    totalLoopStartTime = Date.now(); // Reset total loop timer
                    document.getElementById('loopCount').textContent = loopCycleCount;
                }
                
                // Rekurzív hívás - MINDIG fut tovább
                playCurrentModule();
            }, duration * 1000);
        }
        
        function updateProgressBar(duration) {
            clearInterval(previewInterval);
            
            const totalDuration = getTotalLoopDuration();
            
            previewInterval = setInterval(() => {
                if (isPaused) return;
                
                const elapsedInLoop = getElapsedTimeInLoop();
                const percentage = Math.min((elapsedInLoop / totalDuration) * 100, 100);
                
                document.getElementById('progressBar').style.width = percentage + '%';
                document.getElementById('progressText').textContent = `${Math.floor(elapsedInLoop)}s / ${totalDuration}s`;
                
                if (elapsedInLoop >= totalDuration) {
                    clearInterval(previewInterval);
                }
            }, 100);
        }
        
        function buildModuleUrl(module) {
            const moduleKey = module.module_key || getModuleKeyById(module.module_id);
            const settings = module.settings || {};
            
            let baseUrl = '';
            let params = new URLSearchParams();
            
            // Determine module path
            switch(moduleKey) {
                case 'clock':
                    baseUrl = '../modules/clock/m_clock.html';
                    // Add all clock settings as URL parameters
                    if (settings.type) params.append('type', settings.type);
                    if (settings.format) params.append('format', settings.format);
                    if (settings.dateFormat) params.append('dateFormat', settings.dateFormat);
                    if (settings.timeColor) params.append('timeColor', settings.timeColor);
                    if (settings.dateColor) params.append('dateColor', settings.dateColor);
                    if (settings.bgColor) params.append('bgColor', settings.bgColor);
                    if (settings.fontSize) params.append('fontSize', settings.fontSize);
                    if (settings.clockSize) params.append('clockSize', settings.clockSize);
                    if (settings.showSeconds !== undefined) params.append('showSeconds', settings.showSeconds);
                    if (settings.language) params.append('language', settings.language);
                    break;
                    
                case 'default-logo':
                    baseUrl = '../modules/default/m_default.html';
                    if (settings.text) params.append('text', settings.text);
                    if (settings.fontSize) params.append('fontSize', settings.fontSize);
                    if (settings.textColor) params.append('textColor', settings.textColor);
                    if (settings.bgColor) params.append('bgColor', settings.bgColor);
                    if (settings.showVersion !== undefined) params.append('showVersion', settings.showVersion);
                    if (settings.version) params.append('version', settings.version);
                    break;
                    
                default:
                    // Default fallback - show module name
                    baseUrl = '../modules/default/m_default.html';
                    params.append('text', module.module_name);
                    params.append('bgColor', '#1a3a52');
            }
            
            const queryString = params.toString();
            return queryString ? `${baseUrl}?${queryString}` : baseUrl;
        }

        function formatLanguageCode(language) {
            const code = String(language || 'hu').toLowerCase();
            if (code === 'hu') return 'HU';
            if (code === 'sk') return 'SK';
            if (code === 'en') return 'EN';
            return code.toUpperCase();
        }

        function getLoopItemSummary(item) {
            if (!item) {
                return '';
            }

            const moduleKey = item.module_key || getModuleKeyById(item.module_id);
            const settings = item.settings || {};

            if (moduleKey === 'clock') {
                const type = settings.type === 'analog' ? 'Analóg' : 'Digitális';
                const details = [type];

                if ((settings.type || 'digital') !== 'analog') {
                    details.push(settings.format === '12h' ? '12h' : '24h');
                }

                const language = formatLanguageCode(settings.language);
                return `${details.join(' • ')}<br>Nyelv: ${language}`;
            }

            if (moduleKey === 'default-logo' && settings.text) {
                return `${String(settings.text).slice(0, 24)}`;
            }

            return '';
        }
        
        // Update preview when loop changes
        function renderLoop() {
            const container = document.getElementById('loop-container');
            updateTechnicalModuleVisibility();
            
            if (loopItems.length === 0) {
                container.className = 'empty';
                container.innerHTML = '<p>Nincs elem a loop-ban. Húzz ide modult az „Elérhető Modulok” panelről.</p>';
                updateTotalDuration();
                stopPreview(); // Stop preview if loop is empty
                return;
            }
            
            container.className = '';
            container.innerHTML = '';
            
            loopItems.forEach((item, index) => {
                const loopItem = document.createElement('div');
                loopItem.className = 'loop-item';
                loopItem.draggable = !isDefaultGroup && !isContentOnlyMode;
                loopItem.dataset.index = index;

                const isTechnicalItem = isTechnicalLoopItem(item);
                const durationValue = isTechnicalItem ? 60 : parseInt(item.duration_seconds || 10);
                const durationInputHtml = (isDefaultGroup || isTechnicalItem || isContentOnlyMode)
                    ? `<input type="number" value="${durationValue}" min="1" max="300" disabled>`
                    : `<input type="number" value="${durationValue}" min="1" max="300" onchange="updateDuration(${index}, this.value)" onclick="event.stopPropagation()">`;

                const actionButtonsHtml = isDefaultGroup
                    ? `<button class="loop-btn" disabled title="A default csoport nem szerkeszthető">🔒</button>`
                    : isContentOnlyMode
                    ? `<button class="loop-btn" onclick="customizeModule(${index}); event.stopPropagation();" title="Testreszabás">⚙️</button>`
                    : `<button class="loop-btn" onclick="customizeModule(${index}); event.stopPropagation();" title="Testreszabás">⚙️</button>
                        <button class="loop-btn" onclick="duplicateLoopItem(${index}); event.stopPropagation();" title="Duplikálás">📄</button>
                        <button class="loop-btn" onclick="removeFromLoop(${index}); event.stopPropagation();" title="Törlés">🗑️</button>`;
                
                loopItem.innerHTML = `
                    <div class="loop-order">${index + 1}</div>
                    <div class="loop-details">
                        <div class="loop-module-name">${item.module_name}</div>
                        <div class="loop-module-desc">${getLoopItemSummary(item)}</div>
                    </div>
                    <div class="loop-duration">
                        <label>Időtartam</label>
                        ${durationInputHtml}
                        <span style="font-size: 11px; opacity: 0.9;">sec</span>
                    </div>
                    <div class="loop-actions">
                        ${actionButtonsHtml}
                    </div>
                `;
                
                // Drag and drop handlers
                if (!isDefaultGroup && !isContentOnlyMode) {
                    loopItem.addEventListener('dragstart', handleDragStart);
                    loopItem.addEventListener('dragover', handleDragOver);
                    loopItem.addEventListener('drop', handleDrop);
                    loopItem.addEventListener('dragend', handleDragEnd);
                }
                
                container.appendChild(loopItem);
            });
            
            updateTotalDuration();
            if (loopItems.length > 0) {
                startPreview();
            }
        }

        function buildFixedPlanTimeOptions() {
            const startSelect = document.getElementById('fixed-plan-start');
            const endSelect = document.getElementById('fixed-plan-end');
            if (!startSelect || !endSelect) {
                return;
            }

            startSelect.value = String(startSelect.value || '08:00').slice(0, 5);
            endSelect.value = String(endSelect.value || '10:00').slice(0, 5);
        }
        
        // Load loop on page load
        buildFixedPlanTimeOptions();
        loadLoop();

        function toggleGroupNameEdit(enable) {
            const display = document.getElementById('group-name-display');
            const editBtn = document.getElementById('group-name-edit-btn');
            const editWrap = document.getElementById('group-name-edit-wrap');
            const input = document.getElementById('rename-group-inline-input');

            if (!display || !editBtn || !editWrap || !input) {
                return;
            }

            if (enable) {
                display.style.display = 'none';
                editBtn.style.display = 'none';
                editWrap.style.display = 'inline';
                input.focus();
                input.select();
            } else {
                display.style.display = 'inline';
                editBtn.style.display = 'inline';
                editWrap.style.display = 'none';
                input.value = display.textContent || input.value;
            }
        }

        document.addEventListener('keydown', function (event) {
            const editWrap = document.getElementById('group-name-edit-wrap');
            const input = document.getElementById('rename-group-inline-input');
            if (!editWrap || !input || editWrap.style.display !== 'inline') {
                if (event.key === 'Escape') {
                    cancelScheduleBlockResize();
                    cancelScheduleRangeSelection();
                }
                return;
            }

            if (event.key === 'Enter') {
                event.preventDefault();
                renameCurrentGroup();
            } else if (event.key === 'Escape') {
                event.preventDefault();
                toggleGroupNameEdit(false);
            }
        });

        document.addEventListener('mouseup', function () {
            if (scheduleBlockResize) {
                finishScheduleBlockResize();
                return;
            }
            if (!scheduleRangeSelection) {
                return;
            }
            finishScheduleRangeSelection();
        });

        window.addEventListener('beforeunload', function (event) {
            if (!isDraftDirty) {
                return;
            }
            event.preventDefault();
            event.returnValue = '';
        });

        function renameCurrentGroup() {
            if (isDefaultGroup || isContentOnlyMode) {
                return;
            }

            const input = document.getElementById('rename-group-inline-input');
            if (!input) {
                return;
            }

            const newName = String(input.value || '').trim();
            if (!newName) {
                alert('⚠️ Adj meg egy csoportnevet.');
                return;
            }

            const formData = new FormData();
            formData.append('group_id', String(groupId));
            formData.append('new_name', newName);

            fetch('../api/rename_group.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (!data.success) {
                    alert('⚠️ ' + (data.message || 'Átnevezési hiba'));
                    return;
                }
                const display = document.getElementById('group-name-display');
                if (display) {
                    display.textContent = newName;
                }
                toggleGroupNameEdit(false);
            })
            .catch(() => {
                alert('⚠️ Átnevezési hiba.');
            });
        }
        
        // Load group display resolutions and populate the selector
        function loadGroupResolutions() {
            fetch(`../api/get_group_kiosks.php?group_id=${groupId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.kiosks && data.kiosks.length > 0) {
                        const selector = document.getElementById('resolutionSelector');
                        const resolutions = new Set();
                        
                        // Collect unique resolutions from kiosks
                        data.kiosks.forEach(kiosk => {
                            if (kiosk.screen_resolution) {
                                resolutions.add(kiosk.screen_resolution);
                            }
                        });
                        
                        // Add group-specific resolutions to the top if they exist
                        if (resolutions.size > 0) {
                            selector.innerHTML = ''; // Clear existing options
                            
                            // Add group-specific resolutions
                            Array.from(resolutions).forEach(res => {
                                const [width, height] = res.split('x').map(Number);
                                let aspectRatio = '';
                                if (width && height) {
                                    const gcd = (a, b) => b ? gcd(b, a % b) : a;
                                    const divisor = gcd(width, height);
                                    const ratioW = width / divisor;
                                    const ratioH = height / divisor;
                                    aspectRatio = ` (${ratioW}:${ratioH})`;
                                }
                                const option = document.createElement('option');
                                option.value = res;
                                option.textContent = `${res}${aspectRatio} - Csoport kijelző`;
                                selector.appendChild(option);
                            });
                            
                            // Add separator
                            const separator = document.createElement('option');
                            separator.disabled = true;
                            separator.textContent = '──────────────────';
                            selector.appendChild(separator);
                        }
                        
                        // Add standard resolutions
                        const standardResolutions = [
                            { value: '1920x1080', label: '1920x1080 (16:9 Full HD)' },
                            { value: '1280x720', label: '1280x720 (16:9 HD)' },
                            { value: '1024x768', label: '1024x768 (4:3 XGA)' },
                            { value: '1600x900', label: '1600x900 (16:9 HD+)' },
                            { value: '1366x768', label: '1366x768 (16:9 WXGA)' }
                        ];
                        
                        standardResolutions.forEach(res => {
                            const option = document.createElement('option');
                            option.value = res.value;
                            option.textContent = res.label;
                            selector.appendChild(option);
                        });
                    }

                    updatePreviewResolution();
                })
                .catch(err => {
                    console.error('Error loading group resolutions:', err);
                });
        }

        function fitPreviewScreen(width, height) {
            const previewScreen = document.getElementById('previewScreen');
            const previewPanel = document.querySelector('.preview-panel');
            const previewCol = document.querySelector('.loop-workspace-preview');

            if (!previewScreen || !previewPanel || !previewCol || !width || !height) {
                return;
            }

            const panelStyle = window.getComputedStyle(previewPanel);
            const panelPaddingX = (parseFloat(panelStyle.paddingLeft) || 0) + (parseFloat(panelStyle.paddingRight) || 0);

            const maxWidth = Math.max(180, previewPanel.clientWidth - panelPaddingX - 4);

            let occupiedHeight = 0;
            Array.from(previewPanel.children).forEach((child) => {
                if (child === previewScreen) {
                    return;
                }
                const childStyle = window.getComputedStyle(child);
                const marginTop = parseFloat(childStyle.marginTop) || 0;
                const marginBottom = parseFloat(childStyle.marginBottom) || 0;
                occupiedHeight += child.offsetHeight + marginTop + marginBottom;
            });

            const viewportMaxHeight = Math.max(260, window.innerHeight - 30);
            const panelPaddingY = (parseFloat(panelStyle.paddingTop) || 0) + (parseFloat(panelStyle.paddingBottom) || 0);
            const availableHeight = Math.max(120, viewportMaxHeight - panelPaddingY - occupiedHeight - 12);
            const ratio = width / height;

            let boxWidth = maxWidth;
            let boxHeight = boxWidth / ratio;

            if (boxHeight > availableHeight) {
                boxHeight = availableHeight;
                boxWidth = boxHeight * ratio;
            }

            previewScreen.style.width = `${Math.round(boxWidth)}px`;
            previewScreen.style.height = `${Math.round(boxHeight)}px`;
            previewScreen.style.aspectRatio = `${width} / ${height}`;
        }
        
        // Update preview resolution
        function updatePreviewResolution() {
            const selector = document.getElementById('resolutionSelector');
            const resolution = selector.value;
            const [width, height] = resolution.split('x').map(Number);
            
            if (width && height) {
                fitPreviewScreen(width, height);
            }
        }

        window.addEventListener('resize', () => {
            updatePreviewResolution();
        });
        
        // Load resolutions on page load
        loadGroupResolutions();
