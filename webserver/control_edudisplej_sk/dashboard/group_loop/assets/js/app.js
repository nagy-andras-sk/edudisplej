        const groupLoopBootstrap = window.GroupLoopBootstrap || {};
        let loopItems = [];
        let loopStyles = [];
        let timeBlocks = [];
        let activeLoopStyleId = null;
        let defaultLoopStyleId = null;
        let activeScope = 'base';
        let scheduleWeekOffset = 0;
        let nextTempTimeBlockId = -1;
        const groupId = parseInt(groupLoopBootstrap.groupId || 0, 10);
        const companyId = parseInt(groupLoopBootstrap.companyId || 0, 10);
        const isDefaultGroup = !!groupLoopBootstrap.isDefaultGroup;
        const isContentOnlyMode = !!groupLoopBootstrap.isContentOnlyMode;
        const technicalModule = groupLoopBootstrap.technicalModule || null;
        const turnedOffLoopAction = groupLoopBootstrap.turnedOffLoopAction || null;
        const modulesCatalog = Array.isArray(groupLoopBootstrap.modulesCatalog) ? groupLoopBootstrap.modulesCatalog : [];

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
        let pendingBarPromptState = null;
        let scheduleRangeSelection = null;
        let scheduleBlockResize = null;
        let scheduleGridStepMinutes = 60;
        let groupResolutionChoices = [];
        let groupDefaultResolution = '1920x1080';
        let textCollectionsCacheDetailed = [];
        let textCollectionsCacheLoadedAt = 0;

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
            const actions = bar?.querySelector('.pending-save-actions');
            if (!bar || !label) {
                return;
            }

            if (!actions) {
                return;
            }

            if (isDefaultGroup || (!hasLoadedInitialLoop && !pendingBarPromptState)) {
                bar.style.display = 'none';
                return;
            }

            if (pendingBarPromptState) {
                bar.style.display = 'flex';
                label.textContent = pendingBarPromptState.message;
                actions.innerHTML = `
                    <button type="button" class="btn pending-save-btn" id="pending-bar-confirm">${pendingBarPromptState.confirmLabel}</button>
                    <button type="button" class="btn pending-discard-btn" id="pending-bar-cancel">${pendingBarPromptState.cancelLabel}</button>
                `;
                const confirmBtn = document.getElementById('pending-bar-confirm');
                const cancelBtn = document.getElementById('pending-bar-cancel');
                confirmBtn?.addEventListener('click', () => {
                    const handler = pendingBarPromptState?.onConfirm;
                    pendingBarPromptState = null;
                    updatePendingSaveBar();
                    if (typeof handler === 'function') {
                        handler();
                    }
                });
                cancelBtn?.addEventListener('click', () => {
                    const handler = pendingBarPromptState?.onCancel;
                    pendingBarPromptState = null;
                    updatePendingSaveBar();
                    if (typeof handler === 'function') {
                        handler();
                    }
                });
                return;
            }

            bar.style.display = isDraftDirty ? 'flex' : 'none';
            const versionText = planVersionToken ? ` • Verzió: ${planVersionToken}` : '';
            label.textContent = isDraftDirty
                ? `Nem mentett változtatások${versionText}`
                : `Minden változtatás mentve${versionText}`;
            actions.innerHTML = `
                <button type="button" class="btn pending-save-btn" onclick="publishLoopPlan()">💾 Mentés</button>
                <button type="button" class="btn pending-discard-btn" onclick="discardLocalDraft()" title="Elvetés">✕</button>
            `;
        }

        function showPendingBarPrompt({ message, confirmLabel = 'Igen', cancelLabel = 'Mégse', onConfirm = null, onCancel = null }) {
            pendingBarPromptState = {
                message: String(message || ''),
                confirmLabel: String(confirmLabel || 'Igen'),
                cancelLabel: String(cancelLabel || 'Mégse'),
                onConfirm,
                onCancel
            };
            updatePendingSaveBar();
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
            const parsedStyles = normalizeLoopStyles(styles, Array.isArray(payload.base_loop) ? payload.base_loop : []);

            loopStyles = parsedStyles;
            defaultLoopStyleId = parseInt(payload.default_loop_style_id ?? loopStyles[0]?.id ?? 0, 10) || loopStyles[0]?.id || null;
            timeBlocks = normalizeTimeBlocks(payload.schedule_blocks || payload.time_blocks || []);
            syncNextTempIdCursor();

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

            showPendingBarPrompt({
                message: 'Találtam nem mentett helyi piszkozatot. Betöltsem?',
                confirmLabel: 'Betöltés',
                cancelLabel: 'Mégse',
                onConfirm: () => {
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
                },
                onCancel: () => {
                    setDraftDirty(true);
                    updatePendingSaveBar();
                }
            });
        }

        function publishLoopPlan() {
            saveLoop({ silent: false, source: 'publish' });
        }

        function discardLocalDraft() {
            if (!isDraftDirty) {
                return;
            }
            showPendingBarPrompt({
                message: 'Biztosan elveted a helyi módosításokat?',
                confirmLabel: 'Elvetés',
                cancelLabel: 'Mégse',
                onConfirm: () => {
                    if (lastPublishedPayload && applyPlanPayload(lastPublishedPayload)) {
                        clearDraftCache();
                        setDraftDirty(false);
                        showAutosaveToast('✓ Helyi módosítások elvetve');
                    }
                }
            });
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
                id: (() => {
                    const parsedId = parseInt(block?.id, 10);
                    return Number.isFinite(parsedId) && parsedId !== 0 ? parsedId : nextTempTimeBlockId--;
                })(),
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

        function normalizeLoopStyles(rawStyles, fallbackItems = []) {
            const styles = Array.isArray(rawStyles) ? rawStyles : [];
            const nowTs = Date.now();
            const usedIds = new Set();
            let nextGeneratedId = -1;

            if (styles.length === 0) {
                return [createFallbackLoopStyle('DEFAULT', Array.isArray(fallbackItems) ? fallbackItems : [])];
            }

            return styles.map((style, idx) => {
                const parsedId = parseInt(style?.id, 10);
                let resolvedId = Number.isFinite(parsedId) ? parsedId : 0;

                if (!resolvedId || usedIds.has(resolvedId)) {
                    while (usedIds.has(nextGeneratedId) || nextGeneratedId === 0) {
                        nextGeneratedId -= 1;
                    }
                    resolvedId = nextGeneratedId;
                    nextGeneratedId -= 1;
                }

                usedIds.add(resolvedId);

                return {
                    id: resolvedId,
                    name: String(style?.name || `Loop ${idx + 1}`),
                    items: Array.isArray(style?.items) ? style.items : [],
                    last_modified_ms: Number.isFinite(Number(style?.last_modified_ms))
                        ? Number(style.last_modified_ms)
                        : (Date.parse(String(style?.updated_at || style?.modified_at || '')) || (nowTs - idx))
                };
            });
        }

        function syncNextTempIdCursor() {
            const usedIds = [];

            (Array.isArray(loopStyles) ? loopStyles : []).forEach((style) => {
                const styleId = parseInt(style?.id, 10);
                if (Number.isFinite(styleId) && styleId !== 0) {
                    usedIds.push(styleId);
                }
            });

            (Array.isArray(timeBlocks) ? timeBlocks : []).forEach((block) => {
                const blockId = parseInt(block?.id, 10);
                if (Number.isFinite(blockId) && blockId !== 0) {
                    usedIds.push(blockId);
                }
            });

            const minUsedId = usedIds.length > 0 ? Math.min(...usedIds) : 0;
            nextTempTimeBlockId = minUsedId <= -1 ? (minUsedId - 1) : -1;
        }

        function createFallbackLoopStyle(name, items) {
            const styleId = nextTempTimeBlockId--;
            return {
                id: styleId,
                name: name || `Loop ${Math.abs(styleId)}`,
                items: Array.isArray(items) ? deepClone(items) : [],
                last_modified_ms: Date.now()
            };
        }

        function getLoopStyleLastModifiedMs(style, fallbackIndex = 0) {
            if (!style || typeof style !== 'object') {
                return 0;
            }

            const direct = Number(style.last_modified_ms);
            if (Number.isFinite(direct) && direct > 0) {
                return direct;
            }

            const parsed = Date.parse(String(style.updated_at || style.modified_at || ''));
            if (Number.isFinite(parsed) && parsed > 0) {
                return parsed;
            }

            return Math.max(0, Date.now() - fallbackIndex);
        }

        function getLoopStylesSortedByLastModified(styles) {
            if (!Array.isArray(styles)) {
                return [];
            }

            return styles
                .map((style, index) => ({ style, index }))
                .sort((left, right) => {
                    const leftTs = getLoopStyleLastModifiedMs(left.style, left.index);
                    const rightTs = getLoopStyleLastModifiedMs(right.style, right.index);

                    if (rightTs !== leftTs) {
                        return rightTs - leftTs;
                    }

                    return left.index - right.index;
                })
                .map((entry) => entry.style);
        }

        function normalizeDefaultLoopStyleName() {
            const defaultStyle = getLoopStyleById(defaultLoopStyleId);
            if (!defaultStyle) {
                return;
            }
            if (String(defaultStyle.name || '') !== 'DEFAULT') {
                defaultStyle.name = 'DEFAULT';
            }
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

            const nextItems = deepClone(loopItems || []);
            const currentSerialized = JSON.stringify(Array.isArray(style.items) ? style.items : []);
            const nextSerialized = JSON.stringify(nextItems);

            if (currentSerialized === nextSerialized) {
                return;
            }

            style.items = nextItems;
            style.last_modified_ms = Date.now();
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

            const inlineName = document.getElementById('active-loop-inline-name');
            if (inlineName) {
                inlineName.textContent = styleName;
            }

            const inlineSchedule = document.getElementById('active-loop-inline-schedule');
            if (inlineSchedule) {
                inlineSchedule.textContent = getActiveLoopWeeklyScheduleSummary();
            }
        }

        function scheduleDayNameSk(day) {
            const names = {
                1: 'po',
                2: 'ut',
                3: 'st',
                4: 'št',
                5: 'pia',
                6: 'so',
                7: 'ne'
            };
            return names[day] || '?';
        }

        function formatSummaryTimeToken(hhmmss) {
            const raw = String(hhmmss || '00:00:00').slice(0, 5);
            const [hourPart, minutePart] = raw.split(':');
            const hour = parseInt(hourPart || '0', 10);
            if (String(minutePart || '00') === '00') {
                return String(hour);
            }
            return `${String(hour).padStart(2, '0')}:${String(minutePart || '00').padStart(2, '0')}`;
        }

        function compactDaysLabel(daysList) {
            const values = Array.from(new Set((Array.isArray(daysList) ? daysList : [])
                .map((v) => parseInt(v, 10))
                .filter((v) => v >= 1 && v <= 7)))
                .sort((a, b) => a - b);

            if (values.length === 0) {
                return '';
            }

            const groups = [];
            let start = values[0];
            let prev = values[0];

            for (let i = 1; i < values.length; i += 1) {
                const current = values[i];
                if (current === prev + 1) {
                    prev = current;
                    continue;
                }
                groups.push([start, prev]);
                start = current;
                prev = current;
            }
            groups.push([start, prev]);

            return groups.map(([groupStart, groupEnd]) => {
                if (groupStart === groupEnd) {
                    return scheduleDayNameSk(groupStart);
                }
                if (groupEnd === groupStart + 1) {
                    return `${scheduleDayNameSk(groupStart)},${scheduleDayNameSk(groupEnd)}`;
                }
                return `${scheduleDayNameSk(groupStart)}-${scheduleDayNameSk(groupEnd)}`;
            }).join(',');
        }

        function getActiveLoopWeeklyScheduleSummary() {
            const styleId = parseInt(activeLoopStyleId || 0, 10);
            if (!styleId || parseInt(defaultLoopStyleId || 0, 10) === styleId) {
                return '';
            }

            const weeklyBlocks = timeBlocks
                .filter((block) => String(block.block_type || 'weekly') === 'weekly')
                .filter((block) => parseInt(block.loop_style_id || 0, 10) === styleId);

            if (weeklyBlocks.length === 0) {
                return '';
            }

            const rangeMap = new Map();
            weeklyBlocks.forEach((block) => {
                const start = String(block.start_time || '00:00:00');
                const end = String(block.end_time || '00:00:00');
                const key = `${start}|${end}`;
                if (!rangeMap.has(key)) {
                    rangeMap.set(key, new Set());
                }
                const daySet = rangeMap.get(key);
                String(block.days_mask || '')
                    .split(',')
                    .map((v) => parseInt(v, 10))
                    .filter((v) => v >= 1 && v <= 7)
                    .forEach((day) => daySet.add(day));
            });

            const segments = Array.from(rangeMap.entries())
                .map(([key, daySet]) => {
                    const [start, end] = key.split('|');
                    const dayLabel = compactDaysLabel(Array.from(daySet.values()));
                    const timeLabel = `${formatSummaryTimeToken(start)}-${formatSummaryTimeToken(end)}`;
                    if (!dayLabel) {
                        return timeLabel;
                    }
                    return `${dayLabel} ${timeLabel}`;
                })
                .filter((label) => !!label)
                .sort((a, b) => a.localeCompare(b));

            if (segments.length === 0) {
                return '';
            }

            return `(${segments.join(' • ')})`;
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
                normalizeDefaultLoopStyleName();
                return;
            }

            const namedBase = loopStyles.find((style) => /^(alap|default)\b/i.test(String(style.name || '').trim()));
            defaultLoopStyleId = parseInt((namedBase || loopStyles[0]).id, 10);
            normalizeDefaultLoopStyleName();
        }

        function openLoopStyleDetail(styleId) {
            setActiveLoopStyle(styleId);
        }

        function bindModuleCatalogInteractions() {
            const moduleItems = document.querySelectorAll('.modules-panel .module-item[data-module-id]');
            moduleItems.forEach((item) => {
                if (item.dataset.boundCatalogHandlers === '1') {
                    return;
                }

                item.dataset.boundCatalogHandlers = '1';

                const toggleBtn = item.querySelector('.module-toggle-btn');
                if (toggleBtn) {
                    toggleBtn.addEventListener('click', (event) => {
                        event.preventDefault();
                        event.stopPropagation();
                        item.classList.toggle('is-collapsed');
                        const expanded = !item.classList.contains('is-collapsed');
                        toggleBtn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                    });
                }

                if (!isContentOnlyMode) {
                    item.addEventListener('click', (event) => {
                        if (event.target && event.target.closest('.module-toggle-btn')) {
                            return;
                        }
                        addModuleToLoopFromDataset(item);
                    });
                }

                if (!isContentOnlyMode && item.draggable) {
                    item.addEventListener('dragstart', (event) => {
                        handleModuleCatalogDragStartFromDataset(event, item);
                    });
                }
            });
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
            const sortedStyles = getLoopStylesSortedByLastModified(loopStyles);

            sortedStyles.forEach((style) => {
                const styleId = parseInt(style.id, 10);
                const isDefaultStyle = styleId === defaultId;
                const realModuleCount = Array.isArray(style.items)
                    ? style.items.filter((item) => !isTechnicalLoopItem(item)).length
                    : 0;
                const displayName = isDefaultStyle ? 'DEFAULT' : style.name;

                const option = document.createElement('option');
                option.value = String(style.id);
                option.textContent = `${isDefaultStyle ? '● ' : ''}${displayName} (${realModuleCount} modul)`;
                option.dataset.isDefault = isDefaultStyle ? '1' : '0';
                option.selected = styleId === activeId;
                select.appendChild(option);
            });

            const activeStyle = getLoopStyleById(activeLoopStyleId);
            if (activeStyle) {
                select.value = String(activeStyle.id);
            }

            updateLoopStyleSelectAppearance();

            const deleteBtn = document.getElementById('loop-style-delete-btn');
            if (deleteBtn) {
                const activeIdValue = parseInt(activeLoopStyleId || 0, 10);
                const hasActiveStyle = !!getLoopStyleById(activeIdValue);
                const canDelete = !isDefaultGroup && !isContentOnlyMode && hasActiveStyle && activeIdValue !== defaultId;
                deleteBtn.disabled = !canDelete;
                deleteBtn.style.opacity = canDelete ? '1' : '0.5';
                deleteBtn.style.cursor = canDelete ? 'pointer' : 'not-allowed';
            }
        }

        function updateLoopStyleSelectAppearance() {
            const select = document.getElementById('loop-style-select');
            if (!select) {
                return;
            }

            const selectedOption = select.options[select.selectedIndex] || null;
            const isDefaultSelected = selectedOption && selectedOption.dataset && selectedOption.dataset.isDefault === '1';
            select.classList.toggle('loop-select-default', !!isDefaultSelected);
        }

        function renderLoopStyleSelector() {
            const dragList = document.getElementById('loop-style-drag-list');
            const fixedStyleInput = document.getElementById('fixed-plan-loop-style');
            const fixedStyleLabel = document.getElementById('fixed-plan-loop-label');
            const schedulableStyles = getLoopStylesSortedByLastModified(loopStyles)
                .filter((style) => parseInt(style.id, 10) !== parseInt(defaultLoopStyleId || 0, 10));

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
                        if (!isDefaultGroup && !isContentOnlyMode) {
                            const createBtn = document.createElement('button');
                            createBtn.type = 'button';
                            createBtn.className = 'btn';
                            createBtn.textContent = 'Létrehozás';
                            createBtn.style.marginTop = '8px';
                            createBtn.addEventListener('click', () => {
                                scrollToLoopBuilderAndCreate();
                            });
                            listInner.appendChild(createBtn);
                        }
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

                if (listInner && !isDefaultGroup && !isContentOnlyMode && turnedOffLoopAction) {
                    const actionRow = document.createElement('div');
                    actionRow.className = 'loop-schedule-row';

                    const left = document.createElement('div');
                    const name = document.createElement('div');
                    name.className = 'loop-schedule-row-name';
                    name.textContent = '⏻ Kikapcsolási esemény';
                    const meta = document.createElement('div');
                    meta.className = 'loop-schedule-row-meta';
                    meta.textContent = 'Ütemezhető kijelző kikapcsolás: service leállítás + HDMI off';
                    left.appendChild(name);
                    left.appendChild(meta);

                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'btn';
                    button.textContent = 'Loophoz adás';
                    button.addEventListener('click', () => {
                        let targetStyleId = parseInt(fixedStyleInput?.value || activeLoopStyleId || 0, 10);
                        const isDefaultTarget = targetStyleId === parseInt(defaultLoopStyleId || 0, 10);
                        const targetExists = schedulableStyles.some((style) => parseInt(style.id, 10) === targetStyleId);

                        if (!targetExists || isDefaultTarget) {
                            targetStyleId = parseInt(schedulableStyles[0]?.id || 0, 10);
                        }

                        if (!targetStyleId) {
                            showAutosaveToast('⚠️ Előbb hozz létre egy ütemezhető loopot', true);
                            scrollToLoopBuilderAndCreate();
                            return;
                        }

                        if (fixedStyleInput) {
                            fixedStyleInput.value = String(targetStyleId);
                        }

                        const targetStyle = getLoopStyleById(targetStyleId);
                        if (fixedStyleLabel && targetStyle) {
                            fixedStyleLabel.textContent = `Kiválasztott loop: ${targetStyle.name}`;
                        }

                        openLoopStyleDetail(targetStyleId);
                        addTurnedOffLoopItem();
                        renderLoopStyleSelector();
                    });

                    actionRow.appendChild(left);
                    actionRow.appendChild(button);
                    listInner.appendChild(actionRow);
                }
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
                addBtn.textContent = 'Frissítés';
            }
            closeFixedWeeklyPlannerModal();
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

        function scrollToLoopBuilderAndCreate() {
            const anchor = document.getElementById('loop-builder') || document.getElementById('loop-config-title');
            if (anchor) {
                anchor.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }

        function openFixedWeeklyPlannerModal(blockId = null) {
            const modal = document.getElementById('fixed-weekly-planner');
            if (!modal) {
                return;
            }

            const resolvedId = parseInt(blockId || document.getElementById('fixed-plan-block-id')?.value || 0, 10);
            if (resolvedId !== 0) {
                const block = getWeeklyBlockById(resolvedId);
                if (!block) {
                    showAutosaveToast('⚠️ A kiválasztott heti idősáv nem található', true);
                    return;
                }
                fillWeeklyPlanFormFromBlock(block);
            }

            modal.style.display = 'flex';
            modal.classList.add('open');
        }

        function closeFixedWeeklyPlannerModal() {
            const modal = document.getElementById('fixed-weekly-planner');
            if (!modal) {
                return;
            }
            modal.classList.remove('open');
            modal.style.display = 'none';
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
                showAutosaveToast('⚠️ A DEFAULT loop nem tervezhető', true);
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
                            <select id="quick-weekly-start" aria-label="Heti kezdés (24 órás)"></select>
                            <select id="quick-weekly-end" aria-label="Heti befejezés (24 órás)"></select>
                        </div>
                        <div style="display:flex; justify-content:flex-end; gap:8px;">
                            <button type="button" class="btn" onclick="closeTimeBlockModal()">Mégse</button>
                            <button type="button" class="btn" onclick="saveQuickScheduleDialog(${normalizedStyleId})">Hozzáadás</button>
                        </div>
                    </div>
                </div>
            `;

            set24HourTimeSelectValue('quick-weekly-start', '08:00');
            set24HourTimeSelectValue('quick-weekly-end', '10:00');
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
                showAutosaveToast('⚠️ A DEFAULT loop nem tervezhető, az üres időket automatikusan kitölti', true);
                return;
            }

            const startMinute = parseMinuteFromTime(`${startRaw}:00`, 0);
            const endMinute = parseMinuteFromTime(`${endRaw}:00`, 0);
            if (startMinute === endMinute) {
                showAutosaveToast('⚠️ A kezdés és befejezés nem lehet azonos', true);
                return;
            }

            const payload = {
                id: editBlockId !== 0 ? editBlockId : nextTempTimeBlockId--,
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

            if (!resolveScheduleConflicts(payload, editBlockId !== 0 ? editBlockId : null)) {
                showAutosaveToast('ℹ️ Ütközés miatt megszakítva', true);
                return;
            }

            if (editBlockId !== 0) {
                timeBlocks = timeBlocks.map((entry) => parseInt(entry.id, 10) === editBlockId ? { ...entry, ...payload } : entry);
            } else {
                timeBlocks.push(payload);
            }
            activeScope = `block:${payload.id}`;
            setActiveScope(activeScope, true);
            openFixedWeeklyPlannerModal(payload.id);
            scheduleAutoSave(250);
            showAutosaveToast(editBlockId !== 0 ? '✓ Heti idősáv frissítve' : '✓ Heti idősáv létrehozva');
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
            style.last_modified_ms = Date.now();
            renderLoopStyleSelector();
            renderWeeklyScheduleGrid();
            renderSpecialBlocksList();
            scheduleAutoSave(250);
        }

        function reorderPrimaryPanels() {
            const schedulePanel = document.querySelector('.planner-panel');
            const loopLayout = document.querySelector('.loop-main-layout');

            if (!schedulePanel || !loopLayout) {
                return;
            }

            const parent = loopLayout.parentElement;
            if (!parent || schedulePanel.parentElement !== parent) {
                return;
            }

            if (parent.firstElementChild !== schedulePanel) {
                parent.insertBefore(schedulePanel, loopLayout);
            }
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
                showAutosaveToast('⚠️ A DEFAULT loop nem törölhető', true);
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
            showAutosaveToast('ℹ️ A DEFAULT loop fix, másik loop nem állítható alapnak', true);
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
                baseOption.textContent = 'DEFAULT loop (időblokkon kívül)';
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

            updateActiveLoopVisualState();

            scheduleWeekOffset = Number.isFinite(scheduleWeekOffset) ? parseInt(scheduleWeekOffset, 10) : 0;

            const weekLabel = document.getElementById('schedule-week-label');
            const weekStart = getWeekStartDate(scheduleWeekOffset);
            const weekEnd = new Date(weekStart);
            weekEnd.setDate(weekStart.getDate() + 6);
            if (weekLabel) {
                weekLabel.textContent = `${toDateKey(weekStart)} → ${toDateKey(weekEnd)}`;
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
                        : 'DEFAULT loop (idősávon kívül)';
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
                showAutosaveToast('⚠️ A DEFAULT loop nem tervezhető, az üres időket automatikusan kitölti', true);
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
            if (blockId !== 0 && event?.currentTarget) {
                setActiveScope(`block:${blockId}`, true);
                openFixedWeeklyPlannerModal(blockId);
                return;
            }
            showAutosaveToast('ℹ️ Szerkesztéshez kattints egy meglévő eseményre', true);
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
                            <select id="special-day-plan-start" aria-label="Speciális napi kezdés (24 órás)"></select>
                            <select id="special-day-plan-end" aria-label="Speciális napi befejezés (24 órás)"></select>
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

            set24HourTimeSelectValue('special-day-plan-start', '08:00');
            set24HourTimeSelectValue('special-day-plan-end', '10:00');
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
            const parsedDelta = parseInt(delta, 10);
            if (!Number.isFinite(parsedDelta) || parsedDelta === 0) {
                return;
            }
            scheduleWeekOffset += parsedDelta;
            renderWeeklyScheduleGrid();
        }

        function setScheduleWeekOffset(value) {
            const parsed = parseInt(value, 10);
            if (!Number.isFinite(parsed)) {
                return;
            }
            scheduleWeekOffset = parsed;
            renderWeeklyScheduleGrid();
        }

        function openScheduleDatePicker() {
            const picker = document.getElementById('schedule-date-picker');
            if (!picker) {
                return;
            }
            picker.value = toDateKey(getWeekStartDate(scheduleWeekOffset));
            if (typeof picker.showPicker === 'function') {
                picker.showPicker();
                return;
            }
            picker.focus();
            picker.click();
        }

        function setScheduleDateFromPicker(value) {
            const selected = new Date(String(value || '').trim());
            if (Number.isNaN(selected.getTime())) {
                return;
            }

            selected.setHours(0, 0, 0, 0);
            const selectedDay = selected.getDay() === 0 ? 7 : selected.getDay();
            const selectedMonday = new Date(selected);
            selectedMonday.setDate(selected.getDate() - (selectedDay - 1));
            selectedMonday.setHours(0, 0, 0, 0);

            const currentMonday = getWeekStartDate(0);
            const diffDays = Math.round((selectedMonday - currentMonday) / 86400000);
            scheduleWeekOffset = Math.round(diffDays / 7);
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

        function resolveLoopStylesAndBlocksFromConfig(data) {
            const plannerStyles = Array.isArray(data.loop_styles) ? data.loop_styles : [];
            if (plannerStyles.length > 0) {
                return {
                    styles: normalizeLoopStyles(plannerStyles),
                    defaultStyleId: parseInt(data.default_loop_style_id ?? plannerStyles[0]?.id ?? 0, 10) || plannerStyles[0]?.id || null,
                    blocks: normalizeTimeBlocks(data.schedule_blocks || data.time_blocks || [])
                };
            }

            const hasStructuredPayload = Array.isArray(data.base_loop) || Array.isArray(data.time_blocks);
            const baseItems = hasStructuredPayload
                ? (Array.isArray(data.base_loop) ? data.base_loop : [])
                : (Array.isArray(data.loops) ? data.loops : []);
            const styles = [createFallbackLoopStyle('DEFAULT', baseItems)];

            let blocks = normalizeTimeBlocks(data.time_blocks || []);
            blocks = blocks.map((block, index) => {
                const style = createFallbackLoopStyle(block.block_name || `Idősáv ${index + 1}`, Array.isArray(block.loops) ? block.loops : []);
                styles.push(style);
                return { ...block, loop_style_id: style.id };
            });

            return {
                styles,
                defaultStyleId: styles[0].id,
                blocks
            };
        }

        function applyLoadedLoopConfig(data) {
            const resolved = resolveLoopStylesAndBlocksFromConfig(data);
            loopStyles = resolved.styles;
            defaultLoopStyleId = resolved.defaultStyleId;
            timeBlocks = resolved.blocks;
            syncNextTempIdCursor();

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

            if (loopItems.length > 0) {
                setTimeout(() => startPreview(), 500);
            }
        }
        
        // Load existing loop configuration
        function loadLoop() {
            if (isDefaultGroup) {
                const defaultItem = getDefaultUnconfiguredItem();
                loopStyles = [{ id: -1, name: 'DEFAULT', items: defaultItem ? [defaultItem] : [] }];
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
            loopStyles = [{ id: -1, name: 'DEFAULT', items: baselineItem ? [baselineItem] : [] }];
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

            fetch(`../../api/group_loop/config.php?group_id=${groupId}`)
                .then(response => response.json())
                .then(data => {
                    if (!data || !data.success) {
                        showAutosaveToast('⚠️ A loop lista betöltése sikertelen, DEFAULT loop használatban', true);
                        return;
                    }

                    applyLoadedLoopConfig(data);
                })
                .catch(error => {
                    console.error('Error loading loop:', error);
                    showAutosaveToast('⚠️ Hálózati hiba, DEFAULT loop használatban', true);
                });
        }

        function isLoopEditingBlocked() {
            return isDefaultGroup || isContentOnlyMode;
        }

        function ensureActiveLoopStyleSelected() {
            if (!getLoopStyleById(activeLoopStyleId)) {
                showAutosaveToast('⚠️ Nincs aktív loop kiválasztva', true);
                return false;
            }
            return true;
        }

        function normalizeRenderAndAutosaveLoop() {
            normalizeLoopItems();
            renderLoop();
            scheduleAutoSave();
        }

        function refreshDurationAndRestartPreviewIfNeeded() {
            updateTotalDuration();
            scheduleAutoSave();
            if (loopItems.length > 0) {
                startPreview();
            }
        }

        function buildLoopItemForModule(moduleId, moduleName, moduleDesc, moduleKey) {
            const normalizedKey = String(moduleKey || '').toLowerCase();
            const defaultDuration = normalizedKey === 'unconfigured' || normalizedKey === 'meal-menu' || normalizedKey === 'turned-off' ? 60 : 10;
            return {
                module_id: moduleId,
                module_name: moduleName,
                description: moduleDesc,
                module_key: moduleKey || null,
                duration_seconds: defaultDuration,
                settings: getDefaultSettings(moduleKey || '')
            };
        }

        function getTurnedOffLoopTemplate() {
            const fallbackId = parseInt(technicalModule?.id || 0, 10);
            const actionId = parseInt(turnedOffLoopAction?.id || 0, 10);
            const moduleId = actionId > 0 ? actionId : (fallbackId > 0 ? fallbackId : 0);
            if (moduleId <= 0) {
                return null;
            }

            return buildLoopItemForModule(
                moduleId,
                String(turnedOffLoopAction?.name || 'Turned Off'),
                String(turnedOffLoopAction?.description || 'Kijelző kikapcsolása: tartalomszolgáltatás leáll, HDMI kimenet kikapcsol.'),
                'turned-off'
            );
        }

        function addTurnedOffLoopItem() {
            if (isLoopEditingBlocked()) {
                return;
            }

            if (!ensureActiveLoopStyleSelected()) {
                return;
            }

            const turnedOffItem = getTurnedOffLoopTemplate();
            if (!turnedOffItem) {
                showAutosaveToast('⚠️ A turned-off loop elem most nem elérhető', true);
                return;
            }

            loopItems = [turnedOffItem];
            normalizeRenderAndAutosaveLoop();
            showAutosaveToast('✓ Kikapcsolás loop beállítva');
        }

        function parseModuleCatalogPayload(rawPayload) {
            try {
                const data = JSON.parse(rawPayload || '{}');
                const moduleId = parseInt(data.id || 0, 10);
                const moduleName = String(data.name || '').trim();
                const moduleDesc = String(data.description || '');
                if (!moduleId || !moduleName) {
                    return null;
                }

                return {
                    id: moduleId,
                    name: moduleName,
                    description: moduleDesc
                };
            } catch (_) {
                return null;
            }
        }

        function getLoopTargetIndexFromDropEvent(event) {
            const targetLoopItem = event?.target?.closest ? event.target.closest('.loop-item') : null;
            const targetIndex = parseInt(targetLoopItem?.dataset?.index || '-1', 10);
            if (!Number.isFinite(targetIndex) || targetIndex < 0 || !loopItems[targetIndex]) {
                return -1;
            }
            return targetIndex;
        }

        function tryApplyOverlayPresetFromDrop(targetIndex, droppedModuleKey) {
            if (targetIndex < 0) {
                return false;
            }

            if (droppedModuleKey !== 'clock' && droppedModuleKey !== 'text') {
                return false;
            }

            const targetItem = loopItems[targetIndex];
            const targetModuleKey = targetItem?.module_key || getModuleKeyById(targetItem?.module_id);
            if (!isOverlayCarrierModule(targetModuleKey)) {
                return false;
            }

            targetItem.settings = applyOverlayPresetToSettings(targetItem.settings || {}, droppedModuleKey);
            renderLoop();
            scheduleAutoSave();
            showAutosaveToast(droppedModuleKey === 'clock'
                ? '✓ Óra overlay hozzáadva a modulhoz'
                : '✓ Szöveg overlay hozzáadva a modulhoz');
            return true;
        }

        function reorderLoopItems(draggedIndex, targetIndex) {
            if (!Number.isFinite(draggedIndex) || !Number.isFinite(targetIndex)) {
                return false;
            }
            if (draggedIndex < 0 || targetIndex < 0) {
                return false;
            }
            if (!loopItems[draggedIndex] || !loopItems[targetIndex]) {
                return false;
            }

            const item = loopItems.splice(draggedIndex, 1)[0];
            loopItems.splice(targetIndex, 0, item);
            return true;
        }

        function applyDurationUpdateForSpecialModule(index, moduleKey) {
            if (isTechnicalLoopItem(loopItems[index])) {
                loopItems[index].duration_seconds = 60;
                refreshDurationAndRestartPreviewIfNeeded();
                return true;
            }

            if (moduleKey === 'video') {
                const fixedDuration = parseInt(loopItems[index]?.settings?.videoDurationSec || 0, 10);
                if (fixedDuration > 0) {
                    loopItems[index].duration_seconds = fixedDuration;
                    refreshDurationAndRestartPreviewIfNeeded();
                }
                return true;
            }

            if (moduleKey === 'image-gallery' || moduleKey === 'gallery') {
                loopItems[index].duration_seconds = getGalleryLoopDurationSeconds(loopItems[index]?.settings || {}, loopItems[index]?.duration_seconds);
                refreshDurationAndRestartPreviewIfNeeded();
                return true;
            }

            return false;
        }
        
        function addModuleToLoop(moduleId, moduleName, moduleDesc) {
            if (isLoopEditingBlocked()) {
                return;
            }

            if (!ensureActiveLoopStyleSelected()) {
                return;
            }

            const moduleKey = getModuleKeyById(moduleId);

            if (moduleKey === 'turned-off') {
                const turnedOffItem = buildLoopItemForModule(moduleId, moduleName, moduleDesc, moduleKey);
                loopItems = [turnedOffItem];
                normalizeRenderAndAutosaveLoop();
                return;
            }

            loopItems = loopItems.filter((item) => getLoopItemModuleKey(item) !== 'turned-off');

            if (moduleKey !== 'unconfigured') {
                loopItems = loopItems.filter(item => !isTechnicalLoopItem(item));
            } else if (loopItems.some(item => isTechnicalLoopItem(item))) {
                return;
            }

            loopItems.push(buildLoopItemForModule(moduleId, moduleName, moduleDesc, moduleKey));
            normalizeRenderAndAutosaveLoop();
        }

        function getCatalogModuleDataFromElement(element) {
            if (!element || !element.dataset) {
                return null;
            }

            const moduleId = parseInt(element.dataset.moduleId || '0', 10);
            const moduleName = String(element.dataset.moduleName || '').trim();
            const moduleDesc = String(element.dataset.moduleDesc || '');

            if (!moduleId || !moduleName) {
                return null;
            }

            return {
                id: moduleId,
                name: moduleName,
                description: moduleDesc
            };
        }

        function addModuleToLoopFromDataset(element) {
            const moduleData = getCatalogModuleDataFromElement(element);
            if (!moduleData) {
                return;
            }

            addModuleToLoop(moduleData.id, moduleData.name, moduleData.description);
        }

        function handleModuleCatalogDragStartFromDataset(event, element) {
            const moduleData = getCatalogModuleDataFromElement(element);
            if (!moduleData) {
                return;
            }

            handleModuleCatalogDragStart(event, moduleData);
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

            if (isLoopEditingBlocked() || !event.dataTransfer) {
                return;
            }

            if (!ensureActiveLoopStyleSelected()) {
                return;
            }

            const raw = event.dataTransfer.getData('text/module-catalog-item');
            if (!raw) {
                return;
            }

            const payload = parseModuleCatalogPayload(raw);
            if (!payload) {
                console.error('Invalid module drop payload');
                return;
            }

            const droppedModuleKey = getModuleKeyById(payload.id);
            const targetIndex = getLoopTargetIndexFromDropEvent(event);
            if (tryApplyOverlayPresetFromDrop(targetIndex, droppedModuleKey)) {
                return;
            }

            addModuleToLoop(payload.id, payload.name, payload.description);
        }
        
        function removeFromLoop(index) {
            if (isLoopEditingBlocked()) {
                return;
            }

            loopItems.splice(index, 1);
            normalizeRenderAndAutosaveLoop();
        }

        function duplicateLoopItem(index) {
            if (isLoopEditingBlocked()) {
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
            normalizeRenderAndAutosaveLoop();
            showAutosaveToast('✓ Elem duplikálva');
        }
        
        function updateDuration(index, value) {
            if (isLoopEditingBlocked()) {
                return;
            }

            const moduleKey = loopItems[index]?.module_key || getModuleKeyById(loopItems[index]?.module_id);

            if (applyDurationUpdateForSpecialModule(index, moduleKey)) {
                return;
            }

            const durationBounds = getDurationBoundsForModule(moduleKey);
            const parsedValue = parseInt(value, 10);
            const safeValue = Number.isFinite(parsedValue) ? parsedValue : durationBounds.default;
            const clampedValue = Math.max(durationBounds.min, Math.min(durationBounds.max, safeValue || durationBounds.default));
            loopItems[index].duration_seconds = clampedValue;
            refreshDurationAndRestartPreviewIfNeeded();
        }

        function getDurationBoundsForModule(moduleKey) {
            const key = String(moduleKey || '').toLowerCase();
            if (key === 'meal-menu' || key === 'room-occupancy') {
                return { min: 5, max: 3600, step: 1, default: 10 };
            }
            return { min: 1, max: 3600, step: 1, default: 10 };
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
            if (!draggedElement) {
                return true;
            }

            if (e.stopPropagation) {
                e.stopPropagation();
            }
            
            if (draggedElement !== this) {
                const draggedIndex = parseInt(draggedElement.dataset.index);
                const targetIndex = parseInt(this.dataset.index);

                if (reorderLoopItems(draggedIndex, targetIndex)) {
                    renderLoop();
                    scheduleAutoSave();
                }
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
            if (isLoopEditingBlocked()) {
                return;
            }

            if (confirm('Biztosan törölni szeretnéd az összes elemet?')) {
                loopItems = [];
                normalizeRenderAndAutosaveLoop();
            }
        }

        function getLoopPayloadItemCount(payload) {
            return (payload.base_loop || []).length + (payload.time_blocks || []).reduce((sum, block) => {
                return sum + (Array.isArray(block.loops) ? block.loops.length : 0);
            }, 0);
        }

        function shouldAbortSaveLoop(opts, payload, currentSnapshot) {
            if (isDefaultGroup) {
                if (!opts.silent) {
                    alert('⚠️ A default csoport loopja nem szerkeszthető.');
                }
                return true;
            }

            if (getLoopPayloadItemCount(payload) === 0) {
                if (!opts.silent) {
                    alert('⚠️ A loop üres! Adj hozzá legalább egy modult.');
                }
                return true;
            }

            if (currentSnapshot === lastSavedSnapshot) {
                return true;
            }

            if (autoSaveInFlight) {
                autoSaveQueued = true;
                return true;
            }

            return false;
        }

        function handleSaveLoopSuccess(data, currentSnapshot) {
            lastSavedSnapshot = currentSnapshot;
            lastPublishedPayload = JSON.parse(currentSnapshot);
            planVersionToken = String(data.plan_version || data.plan_version_token || data.loop_version || planVersionToken || '');
            clearDraftCache();
            setDraftDirty(false);
            showAutosaveToast('✓ Mentés sikeres');
        }

        function handleSaveLoopFailure(data) {
            showAutosaveToast('⚠️ ' + (data.message || 'Mentési hiba'), true);
        }

        function finalizeSaveLoopRequest() {
            autoSaveInFlight = false;
            if (autoSaveQueued) {
                autoSaveQueued = false;
                queueDraftPersist(150);
            }
        }
        
        function saveLoop(options = {}) {
            const opts = {
                silent: false,
                source: 'publish',
                ...options
            };

            const payload = buildLoopPayload();
            const currentSnapshot = getLoopSnapshot();

            if (shouldAbortSaveLoop(opts, payload, currentSnapshot)) {
                return;
            }

            autoSaveInFlight = true;
            
            fetch(`../../api/group_loop/config.php?group_id=${groupId}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(payload)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    handleSaveLoopSuccess(data, currentSnapshot);
                } else {
                    handleSaveLoopFailure(data);
                }
            })
            .catch(error => {
                showAutosaveToast('⚠️ Hiba történt: ' + error, true);
            })
            .finally(() => {
                finalizeSaveLoopRequest();
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
                showAutosaveToast('⚠️ A DEFAULT loop nem tervezhető', true);
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
                                <select id="tb-start" style="width:100%;"></select>
                            </div>
                            <div>
                                <label style="display:block; font-size:12px; margin-bottom:4px;">Vége</label>
                                <select id="tb-end" style="width:100%;"></select>
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

            set24HourTimeSelectValue('tb-start', String(merged.start_time || '08:00:00').slice(0, 5));
            set24HourTimeSelectValue('tb-end', String(merged.end_time || '12:00:00').slice(0, 5));
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
                alert('⚠️ A DEFAULT loop nem tervezhető. Az üres sávokat automatikusan kitölti.');
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

        function isOverlayCarrierModule(moduleKey) {
            return moduleKey === 'image-gallery' || moduleKey === 'gallery' || moduleKey === 'meal-menu';
        }

        function getClockOverlayDefaults() {
            return {
                clockOverlayEnabled: false,
                clockOverlayPosition: 'top',
                clockOverlayHeightPercent: 40,
                clockOverlayTimeColor: '#ffffff',
                clockOverlayDateColor: '#ffffff'
            };
        }

        function getTextOverlayDefaults() {
            return {
                textOverlayEnabled: false,
                textOverlayPosition: 'bottom',
                textOverlayHeightPercent: 20,
                textOverlaySourceType: 'manual',
                textOverlayText: 'Sem vložte text...',
                textOverlayCollectionJson: '[]',
                textOverlayExternalUrl: '',
                textOverlayFontSize: 52,
                textOverlayColor: '#ffffff',
                textOverlaySpeedPxPerSec: 120
            };
        }

        function ensureOverlayDefaults(settings) {
            return {
                ...getClockOverlayDefaults(),
                ...getTextOverlayDefaults(),
                ...(settings || {})
            };
        }

        function applyOverlayPresetToSettings(settings, overlayType) {
            const merged = ensureOverlayDefaults(settings);
            if (overlayType === 'clock') {
                merged.clockOverlayEnabled = true;
            }
            if (overlayType === 'text') {
                merged.textOverlayEnabled = true;
            }
            return merged;
        }

        function appendOverlayParams(params, settings, moduleKey) {
            if (!isOverlayCarrierModule(moduleKey)) {
                return;
            }

            const merged = ensureOverlayDefaults(settings);
            if (merged.clockOverlayEnabled) {
                params.append('clockOverlayEnabled', 'true');
                params.append('clockOverlayPosition', merged.clockOverlayPosition || 'top');
                params.append('clockOverlayHeightPercent', String(merged.clockOverlayHeightPercent || 40));
                params.append('clockOverlayTimeColor', merged.clockOverlayTimeColor || '#ffffff');
                params.append('clockOverlayDateColor', merged.clockOverlayDateColor || '#ffffff');
            }
            if (merged.textOverlayEnabled) {
                params.append('textOverlayEnabled', 'true');
                params.append('textOverlayPosition', merged.textOverlayPosition || 'bottom');
                params.append('textOverlayHeightPercent', String(merged.textOverlayHeightPercent || 20));
                params.append('textOverlaySourceType', merged.textOverlaySourceType || 'manual');
                params.append('textOverlayText', merged.textOverlayText || '');
                params.append('textOverlayCollectionJson', merged.textOverlayCollectionJson || '[]');
                params.append('textOverlayExternalUrl', merged.textOverlayExternalUrl || '');
                params.append('textOverlayFontSize', String(merged.textOverlayFontSize || 52));
                params.append('textOverlayColor', merged.textOverlayColor || '#ffffff');
                params.append('textOverlaySpeedPxPerSec', String(merged.textOverlaySpeedPxPerSec || 120));
            }
        }

        function collectOverlaySettingsFromForm(baseSettings) {
            const merged = ensureOverlayDefaults(baseSettings);
            merged.clockOverlayEnabled = document.getElementById('setting-clockOverlayEnabled')?.checked === true;
            merged.clockOverlayPosition = document.getElementById('setting-clockOverlayPosition')?.value || 'top';
            merged.clockOverlayHeightPercent = Math.max(20, Math.min(40, parseInt(document.getElementById('setting-clockOverlayHeightPercent')?.value || '40', 10) || 40));
            merged.clockOverlayTimeColor = document.getElementById('setting-clockOverlayTimeColor')?.value || '#ffffff';
            merged.clockOverlayDateColor = document.getElementById('setting-clockOverlayDateColor')?.value || '#ffffff';

            merged.textOverlayEnabled = document.getElementById('setting-textOverlayEnabled')?.checked === true;
            merged.textOverlayPosition = document.getElementById('setting-textOverlayPosition')?.value || 'bottom';
            merged.textOverlayHeightPercent = Math.max(12, Math.min(40, parseInt(document.getElementById('setting-textOverlayHeightPercent')?.value || '20', 10) || 20));
            const sourceType = String(document.getElementById('setting-textOverlaySourceType')?.value || 'manual');
            merged.textOverlaySourceType = ['manual', 'collection', 'external'].includes(sourceType) ? sourceType : 'manual';
            merged.textOverlayText = document.getElementById('setting-textOverlayText')?.value || 'Sem vložte text...';
            const collectionRaw = String(document.getElementById('setting-textOverlayCollection')?.value || '');
            const collectionLines = collectionRaw
                .split(/\r?\n/)
                .map((line) => String(line || '').trim())
                .filter(Boolean)
                .slice(0, 400);
            merged.textOverlayCollectionJson = JSON.stringify(collectionLines);
            merged.textOverlayExternalUrl = String(document.getElementById('setting-textOverlayExternalUrl')?.value || '').trim();
            merged.textOverlayFontSize = Math.max(18, Math.min(120, parseInt(document.getElementById('setting-textOverlayFontSize')?.value || '52', 10) || 52));
            merged.textOverlayColor = document.getElementById('setting-textOverlayColor')?.value || '#ffffff';
            merged.textOverlaySpeedPxPerSec = Math.max(40, Math.min(320, parseInt(document.getElementById('setting-textOverlaySpeedPxPerSec')?.value || '120', 10) || 120));
            return merged;
        }
        
        function getDefaultSettings(moduleKey) {
            const defaults = {
                'clock': {
                    type: 'digital',
                    format: '24h',
                    dateFormat: 'dmy',
                    timeColor: '#ffffff',
                    dateColor: '#ffffff',
                    bgColor: '#000000',
                    fontSize: 120,
                    timeFontSize: 120,
                    dateFontSize: 36,
                    clockSize: 300,
                    showSeconds: true,
                    showDate: true,
                    language: 'sk'
                },
                'default-logo': {
                    text: 'edudisplej.sk',
                    fontSize: 120,
                    textColor: '#ffffff',
                    bgColor: '#000000',
                    showVersion: false,
                    version: ''
                },
                'text': {
                    text: 'Sem vložte text...',
                    fontFamily: 'Arial, sans-serif',
                    fontSize: 72,
                    fontWeight: '700',
                    fontStyle: 'normal',
                    lineHeight: 1.2,
                    textAlign: 'left',
                    textColor: '#ffffff',
                    bgColor: '#000000',
                    bgImageData: '',
                    scrollMode: false,
                    scrollStartPauseMs: 3000,
                    scrollEndPauseMs: 3000,
                    scrollSpeedPxPerSec: 35
                },
                'video': {
                    videoAssetUrl: '',
                    videoAssetId: '',
                    videoDurationSec: 10,
                    muted: true,
                    fitMode: 'contain',
                    bgColor: '#000000'
                },
                'turned-off': {},
                'image-gallery': {
                    imageUrlsJson: '[]',
                    displayMode: 'slideshow',
                    fitMode: 'contain',
                    slideIntervalSec: 5,
                    transitionEnabled: true,
                    transitionMs: 450,
                    collageColumns: 3,
                    bgColor: '#000000',
                    ...getClockOverlayDefaults(),
                    ...getTextOverlayDefaults()
                },
                'meal-menu': {
                    siteKey: 'jedalen.sk',
                    institutionId: 0,
                    sourceType: 'server',
                    language: 'hu',
                    showHeaderTitle: true,
                    customHeaderTitle: '',
                    showInstitutionName: true,
                    showBreakfast: true,
                    showSnackAm: true,
                    showLunch: true,
                    showSnackPm: false,
                    showDinner: false,
                    showMealTypeEmojis: false,
                    showMealTypeSvgIcons: true,
                    showAllergenEmojis: false,
                    centerAlign: false,
                    slowScrollOnOverflow: false,
                    slowScrollSpeedPxPerSec: 28,
                    showAppetiteMessage: false,
                    appetiteMessageText: 'Jó étvágyat kívánunk!',
                    showSourceUrl: false,
                    sourceUrl: '',
                    fontFamily: 'Segoe UI, Tahoma, sans-serif',
                    mealTitleFontSize: 2.1,
                    mealTextFontSize: 1.85,
                    textFontWeight: 600,
                    lineHeight: 1.4,
                    wrapText: true,
                    apiBaseUrl: '../../api/meal_plan.php',
                    ...getClockOverlayDefaults(),
                    ...getTextOverlayDefaults()
                },
                'room-occupancy': {
                    roomId: 0,
                    showOnlyCurrent: false,
                    showNextCount: 4,
                    apiBaseUrl: '../../api/room_occupancy.php'
                }
            };
            
            if (moduleKey === 'gallery') {
                return defaults['image-gallery'];
            }
            return defaults[moduleKey] || {};
        }

        function escapeHtml(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function sanitizeRichTextHtml(value) {
            const raw = String(value || '').trim();
            if (!raw) {
                return '';
            }

            const parser = new DOMParser();
            const doc = parser.parseFromString(`<div>${raw}</div>`, 'text/html');
            const root = doc.body.firstElementChild;
            if (!root) {
                return '';
            }

            const allowedTags = new Set([
                'p', 'div', 'span', 'br', 'strong', 'b', 'em', 'i', 'u',
                'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote'
            ]);
            const allowedStyles = new Set([
                'color', 'font-weight', 'font-style', 'text-decoration', 'text-decoration-line',
                'text-align', 'font-family', 'font-size', 'line-height', 'background-color'
            ]);

            const sanitizeNode = (node) => {
                const children = Array.from(node.childNodes);
                children.forEach((child) => {
                    if (child.nodeType === Node.ELEMENT_NODE) {
                        const tag = child.tagName.toLowerCase();
                        if (!allowedTags.has(tag)) {
                            const text = doc.createTextNode(child.textContent || '');
                            child.replaceWith(text);
                            return;
                        }

                        Array.from(child.attributes).forEach((attr) => {
                            const attrName = attr.name.toLowerCase();
                            if (attrName.startsWith('on')) {
                                child.removeAttribute(attr.name);
                                return;
                            }

                            if (attrName === 'style') {
                                const safeRules = [];
                                String(attr.value || '').split(';').forEach((rule) => {
                                    const parts = rule.split(':');
                                    if (parts.length < 2) {
                                        return;
                                    }
                                    const prop = parts[0].trim().toLowerCase();
                                    const val = parts.slice(1).join(':').trim();
                                    if (!allowedStyles.has(prop)) {
                                        return;
                                    }
                                    if (/url\s*\(|expression\s*\(|javascript:/i.test(val)) {
                                        return;
                                    }
                                    safeRules.push(`${prop}: ${val}`);
                                });

                                if (safeRules.length > 0) {
                                    child.setAttribute('style', safeRules.join('; '));
                                } else {
                                    child.removeAttribute('style');
                                }
                                return;
                            }

                            child.removeAttribute(attr.name);
                        });

                        sanitizeNode(child);
                    } else if (child.nodeType === Node.COMMENT_NODE) {
                        child.remove();
                    }
                });
            };

            sanitizeNode(root);
            return root.innerHTML;
        }

        function applyInlineStyleToSelection(property, value) {
            const editor = document.getElementById('text-editor-area');
            if (!editor) {
                return;
            }

            const selection = window.getSelection();
            if (!selection || selection.rangeCount === 0) {
                return;
            }

            const range = selection.getRangeAt(0);
            if (!editor.contains(range.commonAncestorContainer)) {
                return;
            }

            if (range.collapsed) {
                let node = range.startContainer;
                if (node && node.nodeType === Node.TEXT_NODE) {
                    node = node.parentElement;
                }
                const target = node && node.closest ? node.closest('span,p,div,li,h1,h2,h3,h4,h5,h6,blockquote') : null;
                if (target && editor.contains(target)) {
                    target.style.setProperty(property, value);
                }
                return;
            }

            const wrapper = document.createElement('span');
            wrapper.style.setProperty(property, value);

            try {
                range.surroundContents(wrapper);
            } catch (error) {
                const fragment = range.extractContents();
                wrapper.appendChild(fragment);
                range.insertNode(wrapper);
            }
        }

        function applyLineHeightToCurrentBlock(lineHeightValue) {
            const editor = document.getElementById('text-editor-area');
            if (!editor) {
                return;
            }

            const selection = window.getSelection();
            if (!selection || selection.rangeCount === 0) {
                return;
            }

            let node = selection.anchorNode;
            if (!node) {
                return;
            }

            if (node.nodeType === Node.TEXT_NODE) {
                node = node.parentElement;
            }

            const block = node && node.closest ? node.closest('p,div,li,h1,h2,h3,h4,h5,h6,blockquote') : null;
            if (!block || !editor.contains(block)) {
                return;
            }

            block.style.lineHeight = String(lineHeightValue);
        }

        function readImageAsCompressedDataUrl(file) {
            return new Promise((resolve, reject) => {
                const reader = new FileReader();
                reader.onload = () => {
                    const image = new Image();
                    image.onload = () => {
                        const maxWidth = 1600;
                        const maxHeight = 900;
                        let width = image.width;
                        let height = image.height;

                        if (width > maxWidth || height > maxHeight) {
                            const ratio = Math.min(maxWidth / width, maxHeight / height);
                            width = Math.max(1, Math.round(width * ratio));
                            height = Math.max(1, Math.round(height * ratio));
                        }

                        const canvas = document.createElement('canvas');
                        canvas.width = width;
                        canvas.height = height;
                        const context = canvas.getContext('2d');
                        if (!context) {
                            resolve(String(reader.result || ''));
                            return;
                        }

                        context.drawImage(image, 0, 0, width, height);
                        resolve(canvas.toDataURL('image/jpeg', 0.82));
                    };
                    image.onerror = () => reject(new Error('A kép nem olvasható'));
                    image.src = String(reader.result || '');
                };
                reader.onerror = () => reject(new Error('A fájl nem olvasható'));
                reader.readAsDataURL(file);
            });
        }

        async function loadTextCollectionsDetailed(forceReload = false) {
            const now = Date.now();
            if (!forceReload && Array.isArray(textCollectionsCacheDetailed) && textCollectionsCacheDetailed.length > 0 && (now - textCollectionsCacheLoadedAt) < 20000) {
                return textCollectionsCacheDetailed;
            }

            const response = await fetch('../../api/text_collections.php?action=list&include_content=1', {
                credentials: 'same-origin'
            });
            const payload = await response.json();
            if (!response.ok || !payload?.success) {
                throw new Error(payload?.message || 'Nem sikerült betölteni a slide gyűjteményt');
            }

            textCollectionsCacheDetailed = Array.isArray(payload.items) ? payload.items : [];
            textCollectionsCacheLoadedAt = now;
            return textCollectionsCacheDetailed;
        }

        function getTextCollectionById(collectionId) {
            const wantedId = parseInt(collectionId, 10) || 0;
            if (wantedId <= 0 || !Array.isArray(textCollectionsCacheDetailed)) {
                return null;
            }
            return textCollectionsCacheDetailed.find((entry) => (parseInt(entry?.id, 10) || 0) === wantedId) || null;
        }

        async function loadMealSitesForModal() {
            const query = new URLSearchParams({ action: 'sites' });
            if (companyId > 0) {
                query.append('company_id', String(companyId));
            }

            const response = await fetch(`../../api/meal_plan.php?${query.toString()}`, {
                credentials: 'same-origin',
                cache: 'no-store'
            });
            const payload = await response.json();
            if (!response.ok || !payload?.success) {
                throw new Error(payload?.message || 'Nem sikerült betölteni a forrás oldalak listáját.');
            }
            return Array.isArray(payload.items) ? payload.items : [];
        }

        async function loadMealInstitutionsForModal(siteKey) {
            const cleanedSiteKey = String(siteKey || '').trim();
            if (!cleanedSiteKey) {
                return [];
            }

            const query = new URLSearchParams({
                action: 'institutions',
                site_key: cleanedSiteKey
            });
            if (companyId > 0) {
                query.append('company_id', String(companyId));
            }

            const response = await fetch(`../../api/meal_plan.php?${query.toString()}`, {
                credentials: 'same-origin',
                cache: 'no-store'
            });
            const payload = await response.json();
            if (!response.ok || !payload?.success) {
                throw new Error(payload?.message || 'Nem sikerült betölteni az intézményeket.');
            }

            return Array.isArray(payload.items) ? payload.items : [];
        }

        function bindMealModuleModalEvents(initialSettings) {
            const siteSelect = document.getElementById('setting-mealSiteKey');
            const institutionSelect = document.getElementById('setting-mealInstitutionId');
            const siteRefreshBtn = document.getElementById('setting-mealReloadSites');
            const institutionRefreshBtn = document.getElementById('setting-mealReloadInstitutions');
            const statusEl = document.getElementById('setting-mealStatus');

            if (!siteSelect || !institutionSelect) {
                return;
            }

            const selectedSite = String(initialSettings.siteKey || 'jedalen.sk');
            const selectedInstitutionId = parseInt(initialSettings.institutionId || 0, 10) || 0;

            const setStatus = (message, isError = false) => {
                if (!statusEl) {
                    return;
                }
                statusEl.textContent = message;
                statusEl.style.color = isError ? '#b91c1c' : '#475569';
            };

            const renderSiteOptions = (sites) => {
                const options = ['<option value="">-- Válassz forrás oldalt --</option>'];
                sites.forEach((site) => {
                    const key = String(site?.site_key || '').trim();
                    if (!key) {
                        return;
                    }
                    const label = String(site?.site_name || key).trim();
                    options.push(`<option value="${escapeHtml(key)}">${escapeHtml(label)}</option>`);
                });
                siteSelect.innerHTML = options.join('');
            };

            const renderInstitutionOptions = (institutions, preferredInstitutionId = 0) => {
                const options = ['<option value="0">-- Válassz intézményt --</option>'];
                institutions.forEach((institution) => {
                    const id = parseInt(institution?.id || 0, 10) || 0;
                    if (id <= 0) {
                        return;
                    }
                    const name = String(institution?.institution_name || '').trim();
                    const city = String(institution?.city || '').trim();
                    const label = city ? `${name} (${city})` : name;
                    options.push(`<option value="${id}">${escapeHtml(label)}</option>`);
                });
                institutionSelect.innerHTML = options.join('');

                const wanted = parseInt(preferredInstitutionId || 0, 10) || 0;
                institutionSelect.value = wanted > 0 ? String(wanted) : '0';
                if (wanted > 0 && institutionSelect.value !== String(wanted)) {
                    institutionSelect.value = '0';
                }
            };

            const refreshInstitutions = async (preferredInstitutionId = 0) => {
                const siteKey = String(siteSelect.value || '').trim();
                if (!siteKey) {
                    renderInstitutionOptions([], 0);
                    setStatus('Válassz előbb forrás oldalt.');
                    return;
                }

                setStatus('Intézmények betöltése...');
                try {
                    const institutions = await loadMealInstitutionsForModal(siteKey);
                    renderInstitutionOptions(institutions, preferredInstitutionId);
                    setStatus(`Intézmények: ${institutions.length} db`);
                } catch (error) {
                    renderInstitutionOptions([], 0);
                    setStatus(error.message || 'Intézmény betöltési hiba.', true);
                }
            };

            const refreshSites = async () => {
                setStatus('Forrás oldalak betöltése...');
                try {
                    const sites = await loadMealSitesForModal();
                    renderSiteOptions(sites);

                    const availableSiteKeys = new Set(sites.map((site) => String(site?.site_key || '').trim()).filter(Boolean));
                    const effectiveSite = availableSiteKeys.has(selectedSite)
                        ? selectedSite
                        : (sites[0]?.site_key ? String(sites[0].site_key) : '');

                    siteSelect.value = effectiveSite;
                    if (effectiveSite) {
                        await refreshInstitutions(selectedInstitutionId);
                    } else {
                        renderInstitutionOptions([], 0);
                        setStatus('Nincs elérhető forrás oldal.', true);
                    }
                } catch (error) {
                    setStatus(error.message || 'Forrás oldal betöltési hiba.', true);
                }
            };

            siteSelect.addEventListener('change', () => {
                refreshInstitutions(0);
            });

            if (siteRefreshBtn) {
                siteRefreshBtn.addEventListener('click', () => {
                    refreshSites();
                });
            }

            if (institutionRefreshBtn) {
                institutionRefreshBtn.addEventListener('click', () => {
                    refreshInstitutions(parseInt(institutionSelect.value || '0', 10) || 0);
                });
            }

            refreshSites();
        }

        async function loadRoomOccupancyRoomsForModal() {
            const query = new URLSearchParams({ action: 'rooms' });
            if (companyId > 0) {
                query.append('company_id', String(companyId));
            }

            const response = await fetch(`../../api/room_occupancy.php?${query.toString()}`, {
                credentials: 'same-origin',
                cache: 'no-store'
            });
            const payload = await response.json();
            if (!response.ok || !payload?.success) {
                throw new Error(payload?.message || 'Nem sikerült betölteni a termek listáját.');
            }
            return Array.isArray(payload.items) ? payload.items : [];
        }

        function bindRoomOccupancyModuleModalEvents(initialSettings) {
            const roomSelect = document.getElementById('setting-roomOccRoomId');
            const roomRefreshBtn = document.getElementById('setting-roomOccReloadRooms');
            const statusEl = document.getElementById('setting-roomOccStatus');
            if (!roomSelect) {
                return;
            }

            const selectedRoomId = parseInt(initialSettings.roomId || 0, 10) || 0;

            const setStatus = (message, isError = false) => {
                if (!statusEl) {
                    return;
                }
                statusEl.textContent = message;
                statusEl.style.color = isError ? '#b91c1c' : '#475569';
            };

            const renderRoomOptions = (rooms) => {
                const options = ['<option value="0">-- Válassz termet --</option>'];
                rooms.forEach((room) => {
                    const id = parseInt(room?.id || 0, 10) || 0;
                    if (id <= 0) {
                        return;
                    }
                    const roomName = String(room?.room_name || '').trim();
                    const roomKey = String(room?.room_key || '').trim();
                    const label = roomKey ? `${roomName} (${roomKey})` : roomName;
                    options.push(`<option value="${id}">${escapeHtml(label)}</option>`);
                });
                roomSelect.innerHTML = options.join('');

                roomSelect.value = selectedRoomId > 0 ? String(selectedRoomId) : '0';
                if (selectedRoomId > 0 && roomSelect.value !== String(selectedRoomId)) {
                    roomSelect.value = '0';
                }
            };

            const refreshRooms = async () => {
                setStatus('Termek betöltése...');
                try {
                    const rooms = await loadRoomOccupancyRoomsForModal();
                    renderRoomOptions(rooms);
                    setStatus(`Termek: ${rooms.length} db`);
                } catch (error) {
                    setStatus(error.message || 'Terem betöltési hiba.', true);
                }
            };

            if (roomRefreshBtn) {
                roomRefreshBtn.addEventListener('click', () => {
                    refreshRooms();
                });
            }

            refreshRooms();
        }

        function applyTextCollectionToTextModuleForm(collection) {
            if (!collection) {
                return;
            }

            const editor = document.getElementById('text-editor-area');
            const hiddenHtml = document.getElementById('setting-text');
            const bgColorInput = document.getElementById('setting-bgColor');
            const bgImageDataInput = document.getElementById('setting-bgImageData');
            const bgStatus = document.getElementById('setting-bgImageStatus');

            const safeHtml = sanitizeRichTextHtml(collection.content_html || '');
            if (editor) {
                editor.innerHTML = safeHtml;
            }
            if (hiddenHtml) {
                hiddenHtml.value = safeHtml;
            }
            if (bgColorInput) {
                bgColorInput.value = collection.bg_color || '#000000';
            }
            if (bgImageDataInput) {
                bgImageDataInput.value = String(collection.bg_image_data || '');
            }
            if (bgStatus) {
                bgStatus.textContent = String(collection.bg_image_data || '').trim() ? 'Háttérkép gyűjteményből' : 'Nincs kiválasztott kép';
            }
        }

        function updateTextModuleMiniPreview() {
            const previewFrame = document.getElementById('text-preview-frame');
            const previewIframe = document.getElementById('text-live-preview-iframe');
            if (!previewFrame || !previewIframe) {
                return;
            }

            const editor = document.getElementById('text-editor-area');
            const hiddenHtml = document.getElementById('setting-text');
            const sourceType = String(document.getElementById('setting-textSourceType')?.value || 'manual');
            const selectedCollectionId = parseInt(document.getElementById('setting-textCollectionId')?.value || '0', 10) || 0;
            const sanitizedHtml = sanitizeRichTextHtml(editor ? editor.innerHTML : (hiddenHtml?.value || ''));
            if (hiddenHtml) {
                hiddenHtml.value = sanitizedHtml;
            }

            let previewTextHtml = sanitizedHtml;
            let bgColor = document.getElementById('setting-bgColor')?.value || '#000000';
            let bgImageData = document.getElementById('setting-bgImageData')?.value || '';

            if (sourceType === 'collection') {
                const selectedCollection = getTextCollectionById(selectedCollectionId);
                if (selectedCollection) {
                    previewTextHtml = sanitizeRichTextHtml(selectedCollection.content_html || '');
                    bgColor = selectedCollection.bg_color || bgColor;
                    bgImageData = selectedCollection.bg_image_data || '';
                    if (hiddenHtml) {
                        hiddenHtml.value = previewTextHtml;
                    }
                }
            }

            const fontFamily = document.getElementById('setting-richFontFamily')?.value || 'Arial, sans-serif';
            const fontSize = Math.max(8, parseInt(document.getElementById('setting-richFontSize')?.value || '72', 10) || 72);
            const lineHeight = Math.max(0.8, Math.min(2.5, parseFloat(document.getElementById('setting-richLineHeight')?.value || '1.2') || 1.2));
            const resolution = document.getElementById('setting-previewResolution')?.value || groupDefaultResolution || '1920x1080';
            const [deviceWidthRaw, deviceHeightRaw] = String(resolution).split('x').map((v) => parseInt(v, 10));
            const deviceWidth = Math.max(320, deviceWidthRaw || 1920);
            const deviceHeight = Math.max(180, deviceHeightRaw || 1080);

            const availableWidth = Math.max(280, previewFrame.clientWidth - 8);
            const availableHeight = Math.max(180, previewFrame.clientHeight - 8);
            const scale = Math.max(0.1, Math.min(availableWidth / deviceWidth, availableHeight / deviceHeight));
            previewIframe.style.width = `${deviceWidth}px`;
            previewIframe.style.height = `${deviceHeight}px`;
            previewIframe.style.transform = `translate(-50%, -50%) scale(${scale})`;
            previewIframe.style.transformOrigin = 'center center';
            previewIframe.style.position = 'absolute';
            previewIframe.style.left = '50%';
            previewIframe.style.top = '50%';

            const previewModule = {
                module_key: 'text',
                duration_seconds: parseInt(document.getElementById('setting-previewDurationSec')?.value || '10', 10) || 10,
                settings: {
                    textSourceType: sourceType,
                    textCollectionId: sourceType === 'collection' ? selectedCollectionId : 0,
                    textCollectionLabel: sourceType === 'collection' ? (getTextCollectionById(selectedCollectionId)?.title || '') : '',
                    text: previewTextHtml || 'Sem vložte text...',
                    fontFamily,
                    fontSize,
                    fontWeight: '700',
                    fontStyle: 'normal',
                    lineHeight,
                    textAlign: 'left',
                    textColor: '#ffffff',
                    bgColor,
                    bgImageData,
                    textAnimationEntry: document.getElementById('setting-textAnimationEntry')?.value || 'none',
                    scrollMode: document.getElementById('setting-scrollMode')?.checked === true,
                    scrollStartPauseMs: Math.round((parseFloat(document.getElementById('setting-scrollStartPauseSec')?.value || '3') || 3) * 1000),
                    scrollEndPauseMs: Math.round((parseFloat(document.getElementById('setting-scrollEndPauseSec')?.value || '3') || 3) * 1000),
                    scrollSpeedPxPerSec: parseInt(document.getElementById('setting-scrollSpeedPxPerSec')?.value || '35', 10) || 35
                }
            };

            const previewUrl = buildModuleUrl(previewModule);
            const cacheBuster = `t=${Date.now()}`;
            previewIframe.src = previewUrl.includes('?')
                ? `${previewUrl}&${cacheBuster}`
                : `${previewUrl}?${cacheBuster}`;
        }

        function bindTextModuleModalEvents(settings) {
            let textPreviewPlaybackInterval = null;
            let textPreviewPlaybackStartedAt = 0;

            const stopTextPreviewPlayback = (resetProgress = true) => {
                if (textPreviewPlaybackInterval) {
                    clearInterval(textPreviewPlaybackInterval);
                    textPreviewPlaybackInterval = null;
                }

                const progressBar = document.getElementById('text-preview-progress');
                const timeLabel = document.getElementById('text-preview-time-label');
                const duration = Math.max(1, parseFloat(document.getElementById('setting-previewDurationSec')?.value || '10'));
                if (resetProgress) {
                    if (progressBar) {
                        progressBar.style.width = '0%';
                    }
                    if (timeLabel) {
                        timeLabel.textContent = `0.0s / ${duration.toFixed(1)}s`;
                    }
                }
            };

            const startTextPreviewPlayback = () => {
                stopTextPreviewPlayback(false);
                updateTextModuleMiniPreview();

                const progressBar = document.getElementById('text-preview-progress');
                const timeLabel = document.getElementById('text-preview-time-label');
                const duration = Math.max(1, parseFloat(document.getElementById('setting-previewDurationSec')?.value || '10'));

                textPreviewPlaybackStartedAt = Date.now();
                textPreviewPlaybackInterval = setInterval(() => {
                    const elapsed = (Date.now() - textPreviewPlaybackStartedAt) / 1000;
                    const ratio = Math.max(0, Math.min(1, elapsed / duration));
                    const shownElapsed = Math.min(elapsed, duration);
                    if (progressBar) {
                        progressBar.style.width = `${Math.round(ratio * 100)}%`;
                    }
                    if (timeLabel) {
                        timeLabel.textContent = `${shownElapsed.toFixed(1)}s / ${duration.toFixed(1)}s`;
                    }

                    if (ratio >= 1) {
                        stopTextPreviewPlayback(false);
                    }
                }, 100);
            };

            const applyTextEditorBackground = () => {
                const textEditor = document.getElementById('text-editor-area');
                if (!textEditor) {
                    return;
                }

                const bgColor = document.getElementById('setting-bgColor')?.value || '#000000';
                const bgImageData = String(document.getElementById('setting-bgImageData')?.value || '').trim();

                textEditor.style.backgroundColor = bgColor;
                if (bgImageData) {
                    textEditor.style.backgroundImage = `url("${bgImageData}")`;
                    textEditor.style.backgroundRepeat = 'no-repeat';
                    textEditor.style.backgroundPosition = 'center center';
                    textEditor.style.backgroundSize = 'cover';
                } else {
                    textEditor.style.backgroundImage = 'none';
                    textEditor.style.backgroundRepeat = '';
                    textEditor.style.backgroundPosition = '';
                    textEditor.style.backgroundSize = '';
                }
            };

            const previewFields = [
                'setting-bgColor',
                'setting-previewResolution'
            ];

            const textSourceSelect = document.getElementById('setting-textSourceType');
            const textCollectionWrap = document.getElementById('textCollectionSelectorWrap');
            const textManualWrap = document.getElementById('textManualEditorWrap');
            const textCollectionSelect = document.getElementById('setting-textCollectionId');

            const updateTextSourceVisibility = () => {
                const source = String(textSourceSelect?.value || 'manual');
                if (textCollectionWrap) {
                    textCollectionWrap.style.display = source === 'collection' ? 'block' : 'none';
                }
                if (textManualWrap) {
                    textManualWrap.style.display = source === 'manual' ? 'block' : 'none';
                }
            };

            const renderTextCollectionOptions = (items) => {
                if (!textCollectionSelect) {
                    return;
                }

                const selectedBefore = parseInt(textCollectionSelect.value || String(parseInt(settings.textCollectionId, 10) || 0), 10) || 0;
                textCollectionSelect.innerHTML = '<option value="0">-- Válassz slide elemet --</option>';

                (Array.isArray(items) ? items : []).forEach((entry) => {
                    const option = document.createElement('option');
                    option.value = String(parseInt(entry.id, 10) || 0);
                    option.textContent = String(entry.title || `Elem #${entry.id}`);
                    textCollectionSelect.appendChild(option);
                });

                textCollectionSelect.value = String(selectedBefore);
                if (textCollectionSelect.value !== String(selectedBefore)) {
                    textCollectionSelect.value = '0';
                }
            };

            if (textSourceSelect) {
                textSourceSelect.addEventListener('change', () => {
                    updateTextSourceVisibility();
                    if (String(textSourceSelect.value) === 'collection') {
                        const selectedCollection = getTextCollectionById(textCollectionSelect?.value || 0);
                        if (selectedCollection) {
                            applyTextCollectionToTextModuleForm(selectedCollection);
                            applyTextEditorBackground();
                        }
                    }
                    updateTextModuleMiniPreview();
                });
            }

            if (textCollectionSelect) {
                textCollectionSelect.addEventListener('change', () => {
                    const selectedCollection = getTextCollectionById(textCollectionSelect.value || 0);
                    if (selectedCollection) {
                        applyTextCollectionToTextModuleForm(selectedCollection);
                        applyTextEditorBackground();
                    }
                    updateTextModuleMiniPreview();
                });
            }

            const textCollectionRefreshBtn = document.getElementById('setting-textCollectionRefresh');
            if (textCollectionRefreshBtn) {
                textCollectionRefreshBtn.addEventListener('click', async () => {
                    try {
                        const items = await loadTextCollectionsDetailed(true);
                        renderTextCollectionOptions(items);
                        showAutosaveToast('✓ Slide gyűjtemény frissítve');
                    } catch (error) {
                        showAutosaveToast('⚠️ A slide gyűjtemény frissítése sikertelen', true);
                    }
                });
            }

            loadTextCollectionsDetailed(false)
                .then((items) => {
                    renderTextCollectionOptions(items);
                    if ((String(textSourceSelect?.value || 'manual') === 'collection') && textCollectionSelect) {
                        const selectedCollection = getTextCollectionById(textCollectionSelect.value || 0);
                        if (selectedCollection) {
                            applyTextCollectionToTextModuleForm(selectedCollection);
                            applyTextEditorBackground();
                        }
                    }
                    updateTextModuleMiniPreview();
                })
                .catch(() => {
                    renderTextCollectionOptions([]);
                });

            updateTextSourceVisibility();

            const previewResolutionSelect = document.getElementById('setting-previewResolution');
            if (previewResolutionSelect) {
                previewResolutionSelect.innerHTML = '';
                const fallbackChoices = [
                    { value: '1920x1080', label: '1920x1080 (16:9)' },
                    { value: '1366x768', label: '1366x768 (16:9)' },
                    { value: '1280x720', label: '1280x720 (16:9)' },
                    { value: '1024x768', label: '1024x768 (4:3)' }
                ];
                const choices = Array.isArray(groupResolutionChoices) && groupResolutionChoices.length > 0
                    ? groupResolutionChoices
                    : fallbackChoices;

                choices.forEach((entry) => {
                    const option = document.createElement('option');
                    option.value = entry.value;
                    option.textContent = entry.label || entry.value;
                    previewResolutionSelect.appendChild(option);
                });

                const defaultValue = (Array.isArray(groupResolutionChoices) && groupResolutionChoices.length > 0)
                    ? groupDefaultResolution
                    : fallbackChoices[0].value;
                previewResolutionSelect.value = defaultValue;
            }

            previewFields.forEach((id) => {
                const field = document.getElementById(id);
                if (!field) {
                    return;
                }
                field.addEventListener('input', () => {
                    if (id === 'setting-bgColor') {
                        applyTextEditorBackground();
                    }
                    updateTextModuleMiniPreview();
                });
                field.addEventListener('change', () => {
                    if (id === 'setting-bgColor') {
                        applyTextEditorBackground();
                    }
                    updateTextModuleMiniPreview();
                });
            });

            const editor = document.getElementById('text-editor-area');
            let savedSelectionRange = null;

            const saveSelection = () => {
                const selection = window.getSelection();
                if (!selection || selection.rangeCount === 0) {
                    return;
                }
                const range = selection.getRangeAt(0);
                if (editor && editor.contains(range.commonAncestorContainer)) {
                    savedSelectionRange = range.cloneRange();
                }
            };

            const restoreSelection = () => {
                if (!savedSelectionRange) {
                    return;
                }
                const selection = window.getSelection();
                if (!selection) {
                    return;
                }
                selection.removeAllRanges();
                selection.addRange(savedSelectionRange);
            };

            if (editor) {
                const debouncedPreviewUpdate = () => updateTextModuleMiniPreview();
                editor.addEventListener('input', debouncedPreviewUpdate);
                editor.addEventListener('keyup', debouncedPreviewUpdate);
                editor.addEventListener('keyup', saveSelection);
                editor.addEventListener('mouseup', saveSelection);
                editor.addEventListener('focus', saveSelection);
                editor.addEventListener('paste', () => {
                    setTimeout(debouncedPreviewUpdate, 0);
                });
            }

            const textAnimationSelect = document.getElementById('setting-textAnimationEntry');
            if (textAnimationSelect) {
                textAnimationSelect.addEventListener('change', updateTextModuleMiniPreview);
            }

            const toolbarButtons = document.querySelectorAll('[data-richcmd]');
            toolbarButtons.forEach((button) => {
                button.addEventListener('click', () => {
                    const command = button.getAttribute('data-richcmd');
                    if (!command) {
                        return;
                    }
                    if (editor) {
                        editor.focus();
                    }
                    restoreSelection();
                    if (command === 'underline') {
                        applyInlineStyleToSelection('text-decoration-line', 'underline');
                    } else {
                        document.execCommand('styleWithCSS', false, true);
                        document.execCommand(command, false, null);
                    }
                    saveSelection();
                    updateTextModuleMiniPreview();
                });
            });

            const colorPicker = document.getElementById('setting-richColor');
            if (colorPicker) {
                colorPicker.addEventListener('input', () => {
                    if (editor) {
                        editor.focus();
                    }
                    restoreSelection();
                    document.execCommand('styleWithCSS', false, true);
                    document.execCommand('foreColor', false, colorPicker.value);
                    saveSelection();
                    updateTextModuleMiniPreview();
                });
            }

            const bgColorPicker = document.getElementById('setting-richBgColor');
            if (bgColorPicker) {
                bgColorPicker.addEventListener('input', () => {
                    if (editor) {
                        editor.focus();
                    }
                    restoreSelection();
                    applyInlineStyleToSelection('background-color', bgColorPicker.value);
                    saveSelection();
                    updateTextModuleMiniPreview();
                });
            }

            const richFontFamily = document.getElementById('setting-richFontFamily');
            if (richFontFamily) {
                richFontFamily.addEventListener('change', () => {
                    if (editor) {
                        editor.focus();
                    }
                    restoreSelection();
                    applyInlineStyleToSelection('font-family', richFontFamily.value);
                    if (editor) {
                        editor.style.fontFamily = richFontFamily.value;
                    }
                    saveSelection();
                    updateTextModuleMiniPreview();
                });
            }

            const richFontSize = document.getElementById('setting-richFontSize');
            if (richFontSize) {
                richFontSize.addEventListener('change', () => {
                    if (editor) {
                        editor.focus();
                    }
                    restoreSelection();
                    const px = Math.max(8, parseInt(richFontSize.value || '32', 10));
                    applyInlineStyleToSelection('font-size', `${px}px`);
                    if (editor) {
                        editor.style.fontSize = `${px}px`;
                    }
                    saveSelection();
                    updateTextModuleMiniPreview();
                });
            }

            const richLineHeight = document.getElementById('setting-richLineHeight');
            if (richLineHeight) {
                richLineHeight.addEventListener('change', () => {
                    if (editor) {
                        editor.focus();
                    }
                    restoreSelection();
                    const value = Math.max(0.8, parseFloat(richLineHeight.value || '1.2'));
                    applyLineHeightToCurrentBlock(value);
                    if (editor) {
                        editor.style.lineHeight = String(value);
                    }
                    saveSelection();
                    updateTextModuleMiniPreview();
                });
            }

            const scrollStartSec = document.getElementById('setting-scrollStartPauseSec');
            const scrollEndSec = document.getElementById('setting-scrollEndPauseSec');
            const scrollSpeed = document.getElementById('setting-scrollSpeedPxPerSec');
            const scrollStartSecValue = document.getElementById('setting-scrollStartPauseSecValue');
            const scrollEndSecValue = document.getElementById('setting-scrollEndPauseSecValue');
            const scrollSpeedValue = document.getElementById('setting-scrollSpeedPxPerSecValue');

            if (scrollStartSec && scrollStartSecValue) {
                const render = () => {
                    scrollStartSecValue.textContent = Number(scrollStartSec.value || 0).toFixed(1);
                };
                scrollStartSec.addEventListener('input', render);
                render();
            }
            if (scrollEndSec && scrollEndSecValue) {
                const render = () => {
                    scrollEndSecValue.textContent = Number(scrollEndSec.value || 0).toFixed(1);
                };
                scrollEndSec.addEventListener('input', render);
                render();
            }
            if (scrollSpeed && scrollSpeedValue) {
                const render = () => {
                    scrollSpeedValue.textContent = String(parseInt(scrollSpeed.value || '35', 10) || 35);
                };
                scrollSpeed.addEventListener('input', render);
                render();
            }

            const previewPlay = document.getElementById('text-preview-play');
            const previewStop = document.getElementById('text-preview-stop');
            if (previewPlay) {
                previewPlay.addEventListener('click', () => {
                    startTextPreviewPlayback();
                });
            }
            if (previewStop) {
                previewStop.addEventListener('click', () => {
                    stopTextPreviewPlayback(true);
                    const iframe = document.getElementById('text-live-preview-iframe');
                    if (iframe) {
                        iframe.src = 'about:blank';
                    }
                });
            }

            const scrollMode = document.getElementById('setting-scrollMode');
            const scrollSettings = document.getElementById('textScrollSettings');
            if (scrollMode && scrollSettings) {
                const applyScrollVisibility = () => {
                    scrollSettings.style.display = scrollMode.checked ? 'grid' : 'none';
                };
                scrollMode.addEventListener('change', applyScrollVisibility);
                applyScrollVisibility();
            }

            const removeBgButton = document.getElementById('setting-removeBgImage');
            const bgDataInput = document.getElementById('setting-bgImageData');
            const bgStatus = document.getElementById('setting-bgImageStatus');
            const bgFileInput = document.getElementById('setting-bgImageFile');

            if (removeBgButton && bgDataInput) {
                removeBgButton.addEventListener('click', () => {
                    bgDataInput.value = '';
                    if (bgStatus) {
                        bgStatus.textContent = 'Nincs kiválasztott kép';
                    }
                    if (bgFileInput) {
                        bgFileInput.value = '';
                    }
                    applyTextEditorBackground();
                    updateTextModuleMiniPreview();
                });
            }

            if (bgFileInput && bgDataInput) {
                bgFileInput.addEventListener('change', async () => {
                    const file = bgFileInput.files && bgFileInput.files[0];
                    if (!file) {
                        return;
                    }
                    if (!file.type.startsWith('image/')) {
                        showAutosaveToast('⚠️ Csak képfájl tölthető fel', true);
                        return;
                    }

                    if (bgStatus) {
                        bgStatus.textContent = 'Feldolgozás...';
                    }

                    try {
                        const dataUrl = await readImageAsCompressedDataUrl(file);
                        bgDataInput.value = dataUrl;
                        if (bgStatus) {
                            bgStatus.textContent = `${file.name} (${Math.round(dataUrl.length / 1024)} KB)`;
                        }
                        applyTextEditorBackground();
                        updateTextModuleMiniPreview();
                    } catch (error) {
                        if (bgStatus) {
                            bgStatus.textContent = 'Kép feldolgozási hiba';
                        }
                        showAutosaveToast('⚠️ Nem sikerült betölteni a képet', true);
                    }
                });
            }

            if (bgStatus && !String(settings.bgImageData || '').trim()) {
                bgStatus.textContent = 'Nincs kiválasztott kép';
            }

            if (editor) {
                const initialFontFamily = richFontFamily?.value || settings.fontFamily || 'Arial, sans-serif';
                const initialFontSize = Math.max(8, parseInt(String(richFontSize?.value || settings.fontSize || '72'), 10) || 72);
                const initialLineHeight = Math.max(0.8, Math.min(2.5, parseFloat(String(richLineHeight?.value || settings.lineHeight || '1.2')) || 1.2));
                editor.style.fontFamily = initialFontFamily;
                editor.style.fontSize = `${initialFontSize}px`;
                editor.style.lineHeight = String(initialLineHeight);
            }

            applyTextEditorBackground();
            updateTextModuleMiniPreview();
            startTextPreviewPlayback();
        }
        
        function showCustomizationModal(item, index) {
            const moduleKey = item.module_key || getModuleKeyById(item.module_id);
            const settings = item.settings || {};
            const overlaySettings = ensureOverlayDefaults(settings);
            
            let formHtml = '';
            
            // Generate form based on module type
            if (moduleKey === 'clock') {
                formHtml = buildClockCustomizationHtml(settings);
            } else if (moduleKey === 'default-logo') {
                formHtml = buildDefaultLogoCustomizationHtml();
            } else if (moduleKey === 'text') {
                formHtml = buildTextCustomizationHtml(item, settings);
            } else if (moduleKey === 'meal-menu') {
                formHtml = buildMealMenuCustomizationHtml(settings);
            } else if (moduleKey === 'room-occupancy') {
                formHtml = buildRoomOccupancyCustomizationHtml(settings);
            } else if (moduleKey === 'image-gallery' || moduleKey === 'gallery') {
                formHtml = buildGalleryCustomizationHtml(settings);
            } else if (moduleKey === 'pdf') {
                formHtml = buildPdfCustomizationHtml(item, settings);
            } else if (moduleKey === 'video') {
                formHtml = buildVideoCustomizationHtml(item, settings);
            } else {
                formHtml = '<p style="text-align: center; color: #999;">Ez a modul nem rendelkezik testreszabási lehetőségekkel.</p>';
            }

            if (isOverlayCarrierModule(moduleKey)) {
                formHtml += buildOverlayCustomizationHtml(overlaySettings);
            }

            const modal = createCustomizationModalElement(item, index, formHtml, moduleKey);
            document.body.appendChild(modal);
            initializeCustomizationModuleUi(moduleKey, item, settings);
            
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

            const showDateCheckbox = document.getElementById('setting-showDate');
            const dateFormatSelect = document.getElementById('setting-dateFormat');
            if (showDateCheckbox && dateFormatSelect) {
                const syncDateFormatState = () => {
                    dateFormatSelect.disabled = !showDateCheckbox.checked;
                    if (!showDateCheckbox.checked) {
                        dateFormatSelect.dataset.lastValue = dateFormatSelect.value;
                        dateFormatSelect.value = 'none';
                    } else if (dateFormatSelect.value === 'none') {
                        dateFormatSelect.value = dateFormatSelect.dataset.lastValue || 'dmy';
                    }
                };
                showDateCheckbox.addEventListener('change', syncDateFormatState);
                syncDateFormatState();
            }
            
            const showVersionCheckbox = document.getElementById('setting-showVersion');
            if (showVersionCheckbox) {
                showVersionCheckbox.addEventListener('change', function() {
                    const versionSettings = document.getElementById('versionSettings');
                    versionSettings.style.display = this.checked ? 'block' : 'none';
                });
            }

            const clockOverlayToggle = document.getElementById('setting-clockOverlayEnabled');
            if (clockOverlayToggle) {
                clockOverlayToggle.addEventListener('change', function() {
                    const section = document.getElementById('clockOverlaySettings');
                    if (section) {
                        section.style.display = this.checked ? 'grid' : 'none';
                    }
                });
            }

            const textOverlayToggle = document.getElementById('setting-textOverlayEnabled');
            if (textOverlayToggle) {
                textOverlayToggle.addEventListener('change', function() {
                    const section = document.getElementById('textOverlaySettings');
                    if (section) {
                        section.style.display = this.checked ? 'grid' : 'none';
                    }
                    updateTextOverlaySourceVisibility();
                });
            }

            const textOverlaySourceSelect = document.getElementById('setting-textOverlaySourceType');
            const updateTextOverlaySourceVisibility = () => {
                const section = document.getElementById('textOverlaySettings');
                const manualWrap = document.getElementById('textOverlayManualWrap');
                const collectionWrap = document.getElementById('textOverlayCollectionWrap');
                const externalWrap = document.getElementById('textOverlayExternalWrap');
                const enabled = document.getElementById('setting-textOverlayEnabled')?.checked === true;
                const sourceType = String(document.getElementById('setting-textOverlaySourceType')?.value || 'manual');

                if (section) {
                    section.style.display = enabled ? 'grid' : 'none';
                }

                if (manualWrap) {
                    manualWrap.style.display = enabled && sourceType === 'manual' ? 'block' : 'none';
                }
                if (collectionWrap) {
                    collectionWrap.style.display = enabled && sourceType === 'collection' ? 'block' : 'none';
                }
                if (externalWrap) {
                    externalWrap.style.display = enabled && sourceType === 'external' ? 'block' : 'none';
                }
            };

            if (textOverlaySourceSelect) {
                textOverlaySourceSelect.addEventListener('change', updateTextOverlaySourceVisibility);
            }
            updateTextOverlaySourceVisibility();

            if (moduleKey === 'text') {
                bindTextModuleModalEvents(settings);
            }
        }

        function isWideCustomizationModule(moduleKey) {
            return moduleKey === 'text'
                || moduleKey === 'image-gallery'
                || moduleKey === 'gallery'
                || moduleKey === 'video'
                || moduleKey === 'meal-menu'
                || moduleKey === 'room-occupancy';
        }

        function buildDefaultLogoCustomizationHtml() {
            return `
                <div style="display: grid; gap: 15px;">
                    <div>
                        <label style="display: block; margin-bottom: 5px; font-weight: bold;">Fix tartalom:</label>
                        <div style="padding:10px; border:1px solid #d1d5db; border-radius:6px; background:#f8fafc; color:#1f2937;">
                            <div style="font-weight:700;">EDUdisplej.sk</div>
                            <div style="margin-top:4px; opacity:0.85;">With heart for education.</div>
                        </div>
                    </div>
                    <div class="muted" style="font-size:12px;">
                        A default logo modul tartalma nem szerkeszthető.
                    </div>
                </div>
            `;
        }

        function buildClockCustomizationHtml(settings) {
            return `
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
                            <option value="24h" selected>24 órás</option>
                        </select>
                    </div>
                    
                    <div>
                        <label style="display: block; margin-bottom: 5px; font-weight: bold;">Dátum formátum:</label>
                        <select id="setting-dateFormat" style="width: 100%; padding: 8px; border-radius: 5px; border: 1px solid #ccc;">
                            <option value="full" ${settings.dateFormat === 'full' ? 'selected' : ''}>Teljes (év, hónap, nap, napnév)</option>
                            <option value="short" ${settings.dateFormat === 'short' ? 'selected' : ''}>Rövid (év, hónap, nap)</option>
                            <option value="dmy" ${settings.dateFormat === 'dmy' ? 'selected' : ''}>Nap.Hónap.Év (NN.HH.ÉÉÉÉ)</option>
                            <option value="numeric" ${settings.dateFormat === 'numeric' ? 'selected' : ''}>Numerikus (ÉÉÉÉ.HH.NN)</option>
                            <option value="none" ${settings.dateFormat === 'none' ? 'selected' : ''}>Nincs dátum</option>
                        </select>
                    </div>
                    
                    <div>
                        <label style="display: block; margin-bottom: 5px; font-weight: bold;">Nyelv:</label>
                        <select id="setting-language" style="width: 100%; padding: 8px; border-radius: 5px; border: 1px solid #ccc;">
                            <option value="hu" ${settings.language === 'hu' ? 'selected' : ''}>Magyar</option>
                            <option value="sk" ${settings.language === 'sk' ? 'selected' : ''}>Szlovák</option>
                            <option value="en" ${settings.language === 'en' ? 'selected' : ''}>Angol</option>
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
                        <input type="color" id="setting-bgColor" value="${settings.bgColor || '#000000'}" style="width: 100%; height: 40px; border-radius: 5px;">
                    </div>
                    
                    <div id="digitalSettings" style="${settings.type === 'analog' ? 'display: none;' : ''}">
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                            <div>
                                <label style="display: block; margin-bottom: 5px; font-weight: bold;">Óra betűméret (px):</label>
                                <input type="number" id="setting-timeFontSize" value="${settings.timeFontSize || settings.fontSize || 120}" min="40" max="320" style="width: 100%; padding: 8px; border-radius: 5px; border: 1px solid #ccc;">
                            </div>
                            <div>
                                <label style="display: block; margin-bottom: 5px; font-weight: bold;">Dátum betűméret (px):</label>
                                <input type="number" id="setting-dateFontSize" value="${settings.dateFontSize || Math.max(16, Math.round((settings.timeFontSize || settings.fontSize || 120) * 0.3))}" min="14" max="180" style="width: 100%; padding: 8px; border-radius: 5px; border: 1px solid #ccc;">
                            </div>
                        </div>
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

                    <div>
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                            <input type="checkbox" id="setting-showDate" ${(settings.showDate !== false && settings.dateFormat !== 'none') ? 'checked' : ''} style="width: 20px; height: 20px;">
                            <span style="font-weight: bold;">Dátum megjelenítése</span>
                        </label>
                    </div>
                </div>
            `;
        }

        function buildTextCustomizationHtml(item, settings) {
            const safeTextHtml = sanitizeRichTextHtml(settings.text || '');
            const safeTextInput = escapeHtml(safeTextHtml);
            const safeBgImageData = escapeHtml(settings.bgImageData || '');
            const textSourceType = String(settings.textSourceType || 'manual') === 'collection' ? 'collection' : 'manual';
            const textCollectionId = parseInt(settings.textCollectionId, 10) || 0;
            const resolvedFontFamily = String(settings.fontFamily || 'Arial, sans-serif');
            const resolvedFontSize = Math.max(8, parseInt(settings.fontSize, 10) || 72);
            const resolvedLineHeight = Math.max(0.8, Math.min(2.5, parseFloat(settings.lineHeight) || 1.2));
            const scrollStartSec = Math.max(0, Math.min(5, (parseInt(settings.scrollStartPauseMs, 10) || 3000) / 1000));
            const scrollEndSec = Math.max(0, Math.min(5, (parseInt(settings.scrollEndPauseMs, 10) || 3000) / 1000));
            const scrollSpeed = Math.max(5, Math.min(200, parseInt(settings.scrollSpeedPxPerSec, 10) || 35));
            const textAnimationEntry = settings.textAnimationEntry || 'none';

            return `
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; align-items:start;">
                    <div style="display: grid; gap: 10px; min-width:0;">
                        <div style="display:grid; gap:8px; border:1px solid #d9e2ec; border-radius:8px; padding:10px; background:#f8fafc;">
                            <div>
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">Szöveg forrása</label>
                                <select id="setting-textSourceType" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                                    <option value="manual" ${textSourceType === 'manual' ? 'selected' : ''}>Kézi szerkesztés</option>
                                    <option value="collection" ${textSourceType === 'collection' ? 'selected' : ''}>Slide gyűjtemény</option>
                                </select>
                            </div>
                            <div id="textCollectionSelectorWrap" style="display:${textSourceType === 'collection' ? 'block' : 'none'};">
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">Slide elem</label>
                                <div style="display:flex; gap:6px; align-items:center;">
                                    <select id="setting-textCollectionId" style="flex:1; padding:8px; border:1px solid #ccc; border-radius:5px;">
                                        <option value="${textCollectionId}">${textCollectionId > 0 ? 'Betöltés...' : '-- Válassz slide elemet --'}</option>
                                    </select>
                                    <button type="button" id="setting-textCollectionRefresh" style="padding:7px 10px; border:1px solid #1e40af; border-radius:5px; background:#fff; color:#1e40af; cursor:pointer;">Frissít</button>
                                </div>
                                <div style="margin-top:6px; font-size:12px;">
                                    <a href="../text_collections.php" target="_blank" rel="noopener">Slide gyűjtemény kezelése</a>
                                </div>
                            </div>
                        </div>

                        <div id="textManualEditorWrap" style="display:${textSourceType === 'manual' ? 'block' : 'none'};">
                        <div style="display: grid; gap: 6px;">
                            <label style="display: block; font-weight: bold; margin-bottom: 0;">Szerkesztő:</label>
                            <div style="display:flex; gap:6px; flex-wrap:wrap; margin-bottom:6px;">
                                <button type="button" data-richcmd="bold" style="padding:5px 9px;">B</button>
                                <button type="button" data-richcmd="italic" style="padding:5px 9px; font-style:italic;">I</button>
                                <button type="button" data-richcmd="underline" style="padding:5px 9px; text-decoration:underline;">U</button>
                                <button type="button" data-richcmd="insertUnorderedList" style="padding:5px 9px;">• Lista</button>
                                <button type="button" data-richcmd="justifyLeft" style="padding:5px 9px;">Bal</button>
                                <button type="button" data-richcmd="justifyCenter" style="padding:5px 9px;">Közép</button>
                                <button type="button" data-richcmd="justifyRight" style="padding:5px 9px;">Jobb</button>
                                <label style="display:flex; align-items:center; gap:4px; font-size:12px;">Szín <input type="color" id="setting-richColor" value="#ffffff"></label>
                                <label style="display:flex; align-items:center; gap:4px; font-size:12px;">Háttér <input type="color" id="setting-richBgColor" value="#ffd54f"></label>
                                <select id="setting-richFontFamily" style="padding:4px 6px; border:1px solid #ccc; border-radius:4px; max-width:180px;">
                                    <option value="Arial, sans-serif" ${resolvedFontFamily === 'Arial, sans-serif' ? 'selected' : ''}>Arial</option>
                                    <option value="Verdana, sans-serif" ${resolvedFontFamily === 'Verdana, sans-serif' ? 'selected' : ''}>Verdana</option>
                                    <option value="Tahoma, sans-serif" ${resolvedFontFamily === 'Tahoma, sans-serif' ? 'selected' : ''}>Tahoma</option>
                                    <option value="Trebuchet MS, sans-serif" ${resolvedFontFamily === 'Trebuchet MS, sans-serif' ? 'selected' : ''}>Trebuchet</option>
                                    <option value="Georgia, serif" ${resolvedFontFamily === 'Georgia, serif' ? 'selected' : ''}>Georgia</option>
                                    <option value="Times New Roman, serif" ${resolvedFontFamily === 'Times New Roman, serif' ? 'selected' : ''}>Times New Roman</option>
                                    <option value="Courier New, monospace" ${resolvedFontFamily === 'Courier New, monospace' ? 'selected' : ''}>Courier New</option>
                                </select>
                                <label style="display:flex; align-items:center; gap:4px; font-size:12px;">Méret
                                    <input type="number" id="setting-richFontSize" value="${resolvedFontSize}" min="8" max="260" style="width:72px; padding:4px 6px; border:1px solid #ccc; border-radius:4px;">
                                </label>
                                <label style="display:flex; align-items:center; gap:4px; font-size:12px;">Sorköz
                                    <input type="number" id="setting-richLineHeight" value="${resolvedLineHeight}" min="0.8" max="2.5" step="0.1" style="width:72px; padding:4px 6px; border:1px solid #ccc; border-radius:4px;">
                                </label>
                            </div>
                            <style>
                                #text-editor-area ul,#text-editor-area ol{margin:.25em 0 .25em 1.2em;padding-left:1em;list-style-position:outside;}
                                #text-editor-area li{margin:.1em 0;}
                            </style>
                            <div id="text-editor-area" contenteditable="true" style="min-height:360px; border:1px solid #ccc; border-radius:6px; padding:10px; background:#000; color:#fff; overflow:auto; white-space:pre-wrap; word-wrap:break-word; word-break:break-word; outline:none;">${safeTextHtml}</div>
                            <input type="hidden" id="setting-text" value="${safeTextInput}">
                            <div style="display:flex; gap:6px; align-items:center; margin-top:8px;">
                                <button type="button" id="text-preview-play" style="padding:5px 10px; border:1px solid #0d5f2e; background:#1f7a3f; color:#fff; border-radius:4px; cursor:pointer;">▶ Play</button>
                                <button type="button" id="text-preview-stop" style="padding:5px 10px; border:1px solid #8a1f1f; background:#b02a2a; color:#fff; border-radius:4px; cursor:pointer;">■ Stop</button>
                                <small id="text-preview-time-label" style="color:#444; font-weight:600;">0.0s / ${(parseInt(item.duration_seconds || 10, 10) || 10).toFixed(1)}s</small>
                            </div>
                            <div style="height:10px; border:1px solid #cdd3da; border-radius:4px; overflow:hidden; background:#edf1f5;">
                                <div id="text-preview-progress" style="height:100%; width:0%; background:#1f3e56;"></div>
                            </div>
                        </div>
                        </div>
                    </div>

                    <div style="display: grid; gap: 14px; min-width:0;">
                        <div style="display:flex; align-items:center; justify-content:space-between; gap:8px;">
                            <label style="display: block; margin-bottom: 0; font-weight: bold;">Élő előnézet:</label>
                            <select id="setting-previewResolution" style="padding:6px 8px; border-radius:5px; border:1px solid #ccc; max-width:220px;">
                            </select>
                        </div>
                        <div id="text-preview-frame" style="height:360px; border:1px solid #d0d0d0; border-radius:8px; background:#f4f4f4; overflow:hidden; position:relative;">
                            <iframe id="text-live-preview-iframe" style="width:100%; height:100%; border:0; background:#000;"></iframe>
                        </div>

                        <div>
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">Háttérszín:</label>
                            <input type="color" id="setting-bgColor" value="${settings.bgColor || '#000000'}" style="width: 100%; height: 40px; border-radius: 5px;">
                        </div>

                        <div style="padding: 10px; background: #f7f7f7; border-radius: 8px; border: 1px solid #e5e5e5;">
                            <label style="display: block; margin-bottom: 6px; font-weight: bold;">Háttérkép feltöltés:</label>
                            <input type="file" id="setting-bgImageFile" accept="image/*" style="width: 100%;">
                            <input type="hidden" id="setting-bgImageData" value="${safeBgImageData}">
                            <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-top: 6px;">
                                <small id="setting-bgImageStatus" style="color: #555;">${settings.bgImageData ? 'Kép beállítva' : 'Nincs kiválasztott kép'}</small>
                                <button type="button" id="setting-removeBgImage" style="padding: 5px 10px; border: none; border-radius: 4px; background: #dc3545; color: #fff; cursor: pointer;">Kép törlése</button>
                            </div>
                        </div>

                        <div>
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">Megjelenítési animáció:</label>
                            <select id="setting-textAnimationEntry" style="width: 100%; padding: 8px; border-radius: 5px; border: 1px solid #ccc;">
                                <option value="none" ${textAnimationEntry === 'none' ? 'selected' : ''}>Nincs animáció</option>
                                <option value="fadeIn" ${textAnimationEntry === 'fadeIn' ? 'selected' : ''}>Fade In (Halványulás)</option>
                                <option value="slideUp" ${textAnimationEntry === 'slideUp' ? 'selected' : ''}>Slide Up (Felsúslás)</option>
                                <option value="zoomIn" ${textAnimationEntry === 'zoomIn' ? 'selected' : ''}>Zoom In (Nagyítás)</option>
                            </select>
                        </div>

                        <div style="padding: 10px; border-radius: 8px; border: 1px solid #e5e5e5;">
                            <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; margin-bottom: 10px;">
                                <input type="checkbox" id="setting-scrollMode" ${(settings.scrollMode === true) ? 'checked' : ''} style="width: 20px; height: 20px;">
                                <span style="font-weight: bold;">Scroll mode (ha a szöveg nem fér ki)</span>
                            </label>
                            <div id="textScrollSettings" style="display: ${(settings.scrollMode === true) ? 'grid' : 'none'}; gap: 10px; grid-template-columns: 1fr;">
                                <div>
                                    <label style="display: block; margin-bottom: 5px; font-weight: bold;">Indulás előtti várakozás (s): <span id="setting-scrollStartPauseSecValue">${scrollStartSec.toFixed(1)}</span></label>
                                    <input type="range" id="setting-scrollStartPauseSec" value="${scrollStartSec}" min="0" max="5" step="0.1" style="width: 100%;">
                                </div>
                                <div>
                                    <label style="display: block; margin-bottom: 5px; font-weight: bold;">Végi várakozás (s): <span id="setting-scrollEndPauseSecValue">${scrollEndSec.toFixed(1)}</span></label>
                                    <input type="range" id="setting-scrollEndPauseSec" value="${scrollEndSec}" min="0" max="5" step="0.1" style="width: 100%;">
                                </div>
                                <div>
                                    <label style="display: block; margin-bottom: 5px; font-weight: bold;">Scroll sebesség (px/s): <span id="setting-scrollSpeedPxPerSecValue">${scrollSpeed}</span></label>
                                    <input type="range" id="setting-scrollSpeedPxPerSec" value="${scrollSpeed}" min="5" max="200" step="1" style="width: 100%;">
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
                <input type="hidden" id="setting-previewDurationSec" value="${parseInt(item.duration_seconds || 10, 10) || 10}">
            `;
        }

        function buildMealMenuCustomizationHtml(settings) {
            const siteKey = escapeHtml(String(settings.siteKey || 'jedalen.sk'));
            const institutionId = parseInt(settings.institutionId || 0, 10) || 0;
            const sourceType = String(settings.sourceType || 'manual').toLowerCase() === 'server' ? 'server' : 'manual';
            const language = ['hu', 'sk', 'en'].includes(String(settings.language || '').toLowerCase()) ? String(settings.language).toLowerCase() : 'hu';
            const fontFamily = escapeHtml(String(settings.fontFamily || 'Segoe UI, Tahoma, sans-serif'));
            const customHeaderTitle = escapeHtml(String(settings.customHeaderTitle || ''));
            const appetiteMessageText = escapeHtml(String(settings.appetiteMessageText || 'Jó étvágyat kívánunk!'));
            const sourceUrl = escapeHtml(String(settings.sourceUrl || ''));
            const mealTitleFontSize = Number(settings.mealTitleFontSize || 1.5);
            const mealTextFontSize = Number(settings.mealTextFontSize || 1.35);
            const textFontWeight = parseInt(settings.textFontWeight || 600, 10) || 600;
            const lineHeight = Number(settings.lineHeight || 1.35);

            return `
                <div style="display:grid; gap:14px;">
                    <div style="padding:12px; border:1px solid #dde3eb; border-radius:8px; background:#f8fafc; display:grid; gap:10px;">
                        <div>
                            <label style="display:block; margin-bottom:4px; font-weight:bold;">Adatforrás</label>
                            <select id="setting-mealSourceType" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                                <option value="manual" ${sourceType === 'manual' ? 'selected' : ''}>Manuális naptár</option>
                                <option value="server" ${sourceType === 'server' ? 'selected' : ''}>Szerver (szinkron)</option>
                            </select>
                        </div>

                        <div>
                            <label style="display:block; margin-bottom:4px; font-weight:bold;">Statikus nyelv</label>
                            <select id="setting-mealLanguage" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                                <option value="hu" ${language === 'hu' ? 'selected' : ''}>Magyar</option>
                                <option value="sk" ${language === 'sk' ? 'selected' : ''}>Slovenčina</option>
                                <option value="en" ${language === 'en' ? 'selected' : ''}>English</option>
                            </select>
                        </div>

                        <div style="display:grid; grid-template-columns:1fr auto; gap:8px; align-items:end;">
                            <div>
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">Forrás oldal</label>
                                <select id="setting-mealSiteKey" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                                    <option value="${siteKey}">${siteKey || '-- Válassz forrás oldalt --'}</option>
                                </select>
                            </div>
                            <button type="button" id="setting-mealReloadSites" style="padding:8px 10px; border:1px solid #1e40af; border-radius:5px; background:#fff; color:#1e40af; cursor:pointer;">Frissít</button>
                        </div>

                        <div style="display:grid; grid-template-columns:1fr auto; gap:8px; align-items:end;">
                            <div>
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">Intézmény</label>
                                <select id="setting-mealInstitutionId" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                                    <option value="${institutionId}">${institutionId > 0 ? 'Betöltés...' : '-- Válassz intézményt --'}</option>
                                </select>
                            </div>
                            <button type="button" id="setting-mealReloadInstitutions" style="padding:8px 10px; border:1px solid #1e40af; border-radius:5px; background:#fff; color:#1e40af; cursor:pointer;">Frissít</button>
                        </div>

                        <div id="setting-mealStatus" style="font-size:12px; color:#475569;">Források betöltése...</div>
                    </div>

                    <div style="padding:12px; border:1px solid #dde3eb; border-radius:8px; background:#fff; display:grid; gap:8px;">
                        <div style="font-weight:700; color:#1f2937;">Megjelenítendő étkezések</div>
                        <label style="display:flex; align-items:center; gap:8px;"><input type="checkbox" id="setting-showHeaderTitle" ${settings.showHeaderTitle !== false ? 'checked' : ''}> Főcím megjelenítése (Dnešné menu)</label>
                        <div>
                            <label style="display:block; margin-bottom:4px; font-weight:bold;">Egyedi főcím (opcionális)</label>
                            <input type="text" id="setting-customHeaderTitle" value="${customHeaderTitle}" placeholder="pl. Dnešné menu" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                        </div>
                        <label style="display:flex; align-items:center; gap:8px;"><input type="checkbox" id="setting-showInstitutionName" ${settings.showInstitutionName !== false ? 'checked' : ''}> Étkezde neve megjelenítése</label>
                        <label style="display:flex; align-items:center; gap:8px;"><input type="checkbox" id="setting-showBreakfast" ${settings.showBreakfast !== false ? 'checked' : ''}> Reggeli</label>
                        <label style="display:flex; align-items:center; gap:8px;"><input type="checkbox" id="setting-showSnackAm" ${settings.showSnackAm !== false ? 'checked' : ''}> Tízórai</label>
                        <label style="display:flex; align-items:center; gap:8px;"><input type="checkbox" id="setting-showLunch" ${settings.showLunch !== false ? 'checked' : ''}> Ebéd</label>
                        <label style="display:flex; align-items:center; gap:8px;"><input type="checkbox" id="setting-showSnackPm" ${settings.showSnackPm === true ? 'checked' : ''}> Uzsonna</label>
                        <label style="display:flex; align-items:center; gap:8px;"><input type="checkbox" id="setting-showDinner" ${settings.showDinner === true ? 'checked' : ''}> Vacsora</label>
                        <label style="display:flex; align-items:center; gap:8px; margin-top:6px; border-top:1px solid #eef2f7; padding-top:8px;"><input type="checkbox" id="setting-showMealTypeSvgIcons" ${settings.showMealTypeSvgIcons !== false ? 'checked' : ''}> Étkezés SVG ikonok megjelenítése</label>
                        <label style="display:flex; align-items:center; gap:8px; margin-top:6px; border-top:1px solid #eef2f7; padding-top:8px;"><input type="checkbox" id="setting-showAllergenEmojis" ${settings.showAllergenEmojis === true ? 'checked' : ''}> Allergén emoji-k megjelenítése</label>
                        <label style="display:flex; align-items:center; gap:8px; margin-top:6px; border-top:1px solid #eef2f7; padding-top:8px;"><input type="checkbox" id="setting-mealCenterAlign" ${settings.centerAlign === true ? 'checked' : ''}> Középre igazított elrendezés</label>
                        <label style="display:flex; align-items:center; gap:8px;"><input type="checkbox" id="setting-mealSlowScrollOnOverflow" ${settings.slowScrollOnOverflow === true ? 'checked' : ''}> Lassú auto-scroll, ha nem fér ki</label>
                        <div>
                            <label style="display:block; margin-bottom:4px; font-weight:bold;">Scroll sebesség (px/s)</label>
                            <input type="number" id="setting-mealSlowScrollSpeedPxPerSec" min="8" max="120" step="1" value="${Math.max(8, Math.min(120, parseInt(settings.slowScrollSpeedPxPerSec || 28, 10) || 28))}" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                        </div>
                    </div>

                    <div style="padding:12px; border:1px solid #dde3eb; border-radius:8px; background:#fff; display:grid; gap:10px;">
                        <div style="font-weight:700; color:#1f2937;">Szöveg megjelenés</div>
                        <div>
                            <label style="display:block; margin-bottom:4px; font-weight:bold;">Betűtípus</label>
                            <input type="text" id="setting-mealFontFamily" value="${fontFamily}" placeholder="Segoe UI, Tahoma, sans-serif" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                        </div>
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                            <div>
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">Címsor méret (vw)</label>
                                <input type="number" id="setting-mealTitleFontSize" min="0.8" max="4" step="0.05" value="${Number.isFinite(mealTitleFontSize) ? mealTitleFontSize : 1.5}" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                            </div>
                            <div>
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">Szöveg méret (vw)</label>
                                <input type="number" id="setting-mealTextFontSize" min="0.8" max="4" step="0.05" value="${Number.isFinite(mealTextFontSize) ? mealTextFontSize : 1.35}" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                            </div>
                        </div>
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                            <div>
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">Vastagság</label>
                                <select id="setting-mealTextFontWeight" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                                    <option value="400" ${textFontWeight <= 450 ? 'selected' : ''}>Normál (400)</option>
                                    <option value="500" ${textFontWeight > 450 && textFontWeight < 650 ? 'selected' : ''}>Közepes (500)</option>
                                    <option value="700" ${textFontWeight >= 650 ? 'selected' : ''}>Félkövér (700)</option>
                                </select>
                            </div>
                            <div>
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">Sorköz</label>
                                <input type="number" id="setting-mealLineHeight" min="1" max="2.2" step="0.05" value="${Number.isFinite(lineHeight) ? lineHeight : 1.35}" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                            </div>
                        </div>
                        <label style="display:flex; align-items:center; gap:8px;"><input type="checkbox" id="setting-mealWrapText" ${settings.wrapText !== false ? 'checked' : ''}> Szövegtördelés engedélyezése</label>
                        <label style="display:flex; align-items:center; gap:8px; margin-top:6px; border-top:1px solid #eef2f7; padding-top:8px;"><input type="checkbox" id="setting-showAppetiteMessage" ${settings.showAppetiteMessage === true ? 'checked' : ''}> „Jó étvágyat kívánunk” sor megjelenítése</label>
                        <div>
                            <label style="display:block; margin-bottom:4px; font-weight:bold;">Jó étvágyat szöveg</label>
                            <input type="text" id="setting-appetiteMessageText" value="${appetiteMessageText}" placeholder="Jó étvágyat kívánunk!" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                        </div>
                        <label style="display:flex; align-items:center; gap:8px;"><input type="checkbox" id="setting-showSourceUrl" ${settings.showSourceUrl === true ? 'checked' : ''}> Forrás URL megjelenítése alul</label>
                        <div>
                            <label style="display:block; margin-bottom:4px; font-weight:bold;">Forrás URL</label>
                            <input type="url" id="setting-sourceUrl" value="${sourceUrl}" placeholder="https://www.jedalen.sk/Pages/EatMenu?Ident=..." style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                        </div>
                    </div>
                </div>
            `;
        }

        function buildRoomOccupancyCustomizationHtml(settings) {
            const roomId = parseInt(settings.roomId || 0, 10) || 0;
            const showNextCount = parseInt(settings.showNextCount || 4, 10) || 4;

            return `
                <div style="display:grid; gap:14px;">
                    <div style="padding:12px; border:1px solid #dde3eb; border-radius:8px; background:#f8fafc; display:grid; gap:10px;">
                        <div style="display:grid; grid-template-columns:1fr auto; gap:8px; align-items:end;">
                            <div>
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">Terem</label>
                                <select id="setting-roomOccRoomId" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                                    <option value="${roomId}">${roomId > 0 ? 'Betöltés...' : '-- Válassz termet --'}</option>
                                </select>
                            </div>
                            <button type="button" id="setting-roomOccReloadRooms" style="padding:8px 10px; border:1px solid #1e40af; border-radius:5px; background:#fff; color:#1e40af; cursor:pointer;">Frissít</button>
                        </div>
                        <div id="setting-roomOccStatus" style="font-size:12px; color:#475569;">Termek betöltése...</div>
                    </div>

                    <div style="padding:12px; border:1px solid #dde3eb; border-radius:8px; background:#fff; display:grid; gap:8px;">
                        <div style="font-weight:700; color:#1f2937;">Megjelenítés</div>
                        <label style="display:flex; align-items:center; gap:8px;"><input type="checkbox" id="setting-roomOccShowOnlyCurrent" ${settings.showOnlyCurrent === true ? 'checked' : ''}> Csak aktuális foglaltság</label>
                        <div>
                            <label style="display:block; margin-bottom:4px; font-weight:bold;">Következő események száma</label>
                            <input type="number" id="setting-roomOccShowNextCount" min="1" max="12" value="${showNextCount}" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                        </div>
                    </div>
                </div>
            `;
        }

        function buildGalleryCustomizationHtml(settings) {
            let galleryImages = [];
            try {
                const parsed = JSON.parse(String(settings.imageUrlsJson || '[]'));
                galleryImages = Array.isArray(parsed)
                    ? parsed.map(v => String(v || '').trim()).filter(Boolean).slice(0, 10)
                    : [];
            } catch (_) {
                galleryImages = [];
            }
            const safeGalleryJson = escapeHtml(JSON.stringify(galleryImages));

            return `
                <div style="display:grid; gap:14px;">
                    <div>
                        <label style="display:block; margin-bottom:6px; font-weight:bold;">🖼️ Képek feltöltése</label>
                        <div id="gallery-upload-area" style="border:2px dashed #1e40af; border-radius:8px; padding:20px; text-align:center; cursor:pointer; background:#f8f9fa;">
                            <input type="file" id="gallery-file-input" accept="image/*" multiple style="display:none;">
                            <div style="font-size:14px; color:#425466;">Húzz ide képeket vagy <span style="color:#1e40af; font-weight:bold; text-decoration:underline;">kattints a kiválasztáshoz</span></div>
                            <div style="font-size:12px; color:#8a97a6; margin-top:6px;">Max 10 kép, képenként max 15 MB</div>
                        </div>
                        <div style="margin-top:10px; padding:10px; border:1px solid #dde3eb; border-radius:8px; background:#fcfdff;">
                            <div style="display:flex; justify-content:space-between; gap:8px; align-items:center; margin-bottom:8px;">
                                <strong style="font-size:13px; color:#1f2a37;">☁️ Korábban feltöltött képek (Company Cloud)</strong>
                                <button type="button" id="gallery-library-refresh" style="padding:5px 8px; border:1px solid #1e40af; background:#fff; color:#1e40af; border-radius:5px; cursor:pointer; font-size:12px;">Frissítés</button>
                            </div>
                            <div id="gallery-library-status" style="font-size:12px; color:#425466; margin-bottom:6px;">Betöltés...</div>
                            <div id="gallery-library-list" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(90px, 1fr)); gap:8px;"></div>
                            <button type="button" id="gallery-library-import" style="margin-top:8px; padding:6px 10px; border:1px solid #16a34a; background:#16a34a; color:#fff; border-radius:5px; cursor:pointer; font-size:12px;">Kijelöltek importálása</button>
                        </div>
                        <input type="hidden" id="gallery-image-urls-json" value='${safeGalleryJson}'>
                        <div id="gallery-upload-status" style="font-size:12px; color:#425466; margin-top:6px;"></div>
                        <div id="gallery-upload-progress-wrap" style="display:none; margin-top:6px;">
                            <div style="height:8px; background:#e2e8f0; border-radius:999px; overflow:hidden;">
                                <div id="gallery-upload-progress-bar" style="height:100%; width:0%; background:#1e40af; transition:width .2s ease;"></div>
                            </div>
                            <div id="gallery-upload-progress-text" style="font-size:11px; color:#475569; margin-top:4px;">0%</div>
                        </div>
                        <div id="gallery-image-list" style="display:grid; gap:6px; margin-top:8px;"></div>
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                        <div>
                            <label style="display:block; margin-bottom:4px; font-weight:bold;">Megjelenítési mód</label>
                            <select id="gallery-display-mode" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                                <option value="slideshow" ${(settings.displayMode || 'slideshow') === 'slideshow' ? 'selected' : ''}>Slideshow</option>
                                <option value="collage" ${settings.displayMode === 'collage' ? 'selected' : ''}>Kollázs</option>
                                <option value="single" ${settings.displayMode === 'single' ? 'selected' : ''}>Egy kép</option>
                            </select>
                        </div>
                        <div>
                            <label style="display:block; margin-bottom:4px; font-weight:bold;">Kép igazítás</label>
                            <select id="gallery-fit-mode" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                                <option value="cover" ${settings.fitMode === 'cover' ? 'selected' : ''}>Cover</option>
                                <option value="contain" ${(settings.fitMode || 'contain') === 'contain' ? 'selected' : ''}>Contain</option>
                                <option value="fill" ${settings.fitMode === 'fill' ? 'selected' : ''}>Fill</option>
                            </select>
                        </div>
                        <div id="gallery-slide-interval-wrap">
                            <label style="display:block; margin-bottom:4px; font-weight:bold;">Slideshow váltás (s)</label>
                            <input type="number" id="gallery-slide-interval" value="${settings.slideIntervalSec || 5}" min="1" max="30" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                        </div>
                        <div id="gallery-transition-toggle-wrap" style="display:flex; align-items:flex-end;">
                            <label style="display:flex; align-items:center; gap:8px; font-weight:bold; margin-bottom:4px; cursor:pointer;">
                                <input type="checkbox" id="gallery-transition-enabled" ${(settings.transitionEnabled !== false && Number(settings.transitionMs || 450) !== 0) ? 'checked' : ''}>
                                <span>Áttűnés bekapcsolva</span>
                            </label>
                        </div>
                        <div id="gallery-collage-columns-wrap">
                            <label style="display:block; margin-bottom:4px; font-weight:bold;">Kollázs oszlopok</label>
                            <input type="number" id="gallery-collage-columns" value="${settings.collageColumns || 3}" min="2" max="5" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                        </div>
                        <div>
                            <label style="display:block; margin-bottom:4px; font-weight:bold;">Háttérszín</label>
                            <input type="color" id="gallery-bg-color" value="${settings.bgColor || '#000000'}" style="width:100%; height:40px; border:1px solid #ccc; border-radius:5px;">
                        </div>
                    </div>

                    <div style="border:1px solid #d6dde8; border-radius:8px; padding:10px; background:#f8fafc;">
                        <div style="font-weight:700; color:#425466; margin-bottom:8px;">Előnézet</div>
                        <div style="height:320px; border:1px solid #e0e6ed; border-radius:6px; background:#fff; overflow:hidden;">
                            <iframe id="gallery-live-preview-iframe" style="width:100%; height:100%; border:0; background:#000;"></iframe>
                        </div>
                        <div id="gallery-preview-empty" style="font-size:12px; color:#8a97a6; margin-top:8px; display:${galleryImages.length ? 'none' : 'block'};">Tölts fel legalább 1 képet az előnézethez.</div>
                    </div>
                </div>
            `;
        }

        function buildPdfCustomizationHtml(item, settings) {
            const pdfDataBase64 = item.settings?.pdfDataBase64 || '';
            const pdfAssetUrl = item.settings?.pdfAssetUrl || '';
            const fileSizeKB = pdfDataBase64 ? Math.round(pdfDataBase64.length / 1024) : 0;
            const hasPdfSource = !!pdfAssetUrl || !!pdfDataBase64;
            const autoScrollEnabled = settings.autoScrollEnabled === true || settings.navigationMode === 'auto';
            const pauseAtPercent = Number.isFinite(parseInt(settings.pauseAtPercent, 10))
                ? parseInt(settings.pauseAtPercent, 10)
                : -1;

            return `
                <div style="display: grid; gap: 16px;">
                    <div>
                        <label style="display: block; margin-bottom: 10px; font-weight: bold;">📄 PDF Feltöltés</label>
                        <div id="pdf-upload-area" style="
                            border: 2px dashed #1e40af;
                            border-radius: 8px;
                            padding: 30px;
                            text-align: center;
                            cursor: pointer;
                            transition: background-color 0.2s;
                            background-color: #f8f9fa;
                        ">
                            <input type="file" id="pdf-file-input" accept=".pdf" style="display: none;">
                            <div style="font-size: 14px; color: #425466;">
                                Húzd ide a PDF-et vagy <span style="color: #1e40af; font-weight: bold; text-decoration: underline;">kattints a kiválasztáshoz</span>
                            </div>
                            <div style="font-size: 12px; color: #8a97a6; margin-top: 8px;">Max. 50 MB</div>
                            ${hasPdfSource ? `<div style="color: #28a745; margin-top: 8px; font-size: 13px;">✓ PDF betöltve${fileSizeKB > 0 ? ` (${fileSizeKB} KB)` : ''}</div>` : ''}
                        </div>
                    </div>
                    <div style="display:grid; gap:12px;">
                        <div>
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">Fix zoom (%):</label>
                            <input type="number" id="pdf-zoomLevel" value="${settings.zoomLevel || 100}" min="50" max="250" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 5px;">
                        </div>
                        <div>
                            <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                                <input type="checkbox" id="pdf-autoScrollEnabled" ${autoScrollEnabled ? 'checked' : ''} style="width: 20px; height: 20px;">
                                <span style="font-weight: bold;">Automatikus görgetés</span>
                            </label>
                        </div>
                        <div class="pdf-scroll-settings" style="display:${autoScrollEnabled ? 'grid' : 'none'}; gap:12px;">
                            <div>
                                <label style="display: block; margin-bottom: 5px; font-weight: bold;">Görgetési sebesség (px/s):</label>
                                <input type="number" id="pdf-scrollSpeed" value="${settings.autoScrollSpeedPxPerSec || 30}" min="5" max="300" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 5px;">
                            </div>
                            <div>
                                <label style="display: block; margin-bottom: 5px; font-weight: bold;">Indulás előtti várakozás (ms):</label>
                                <input type="number" id="pdf-startPause" value="${settings.autoScrollStartPauseMs || 2000}" min="0" max="60000" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 5px;">
                            </div>
                            <div>
                                <label style="display: block; margin-bottom: 5px; font-weight: bold;">Ciklus végi várakozás (ms):</label>
                                <input type="number" id="pdf-endPause" value="${settings.autoScrollEndPauseMs || 2000}" min="0" max="60000" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 5px;">
                            </div>
                            <div>
                                <label style="display: block; margin-bottom: 5px; font-weight: bold;">Megállás pozíció (%):</label>
                                <input type="number" id="pdf-pauseAtPercent" value="${pauseAtPercent}" min="-1" max="100" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 5px;">
                                <div style="font-size:12px; color:#8a97a6; margin-top:4px;">-1 = nincs köztes megállás</div>
                            </div>
                            <div>
                                <label style="display: block; margin-bottom: 5px; font-weight: bold;">Megállás hossza (ms):</label>
                                <input type="number" id="pdf-pauseDurationMs" value="${settings.pauseDurationMs || 2000}" min="0" max="60000" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 5px;">
                            </div>
                        </div>
                    </div>
                    <div id="pdf-preview-area" style="border:1px solid #d6dde8; border-radius:8px; padding:10px; background:#f8fafc;">
                        <div style="font-weight:700; color:#425466; margin-bottom:8px;">Előnézet</div>
                        <div style="height:360px; overflow:auto; border:1px solid #e0e6ed; border-radius:6px; background:#fff; padding:8px;">
                            <iframe id="pdf-live-preview-iframe" style="width:100%; height:100%; border:0; background:#fff;"></iframe>
                        </div>
                        <div id="pdf-preview-empty" style="font-size:12px; color:#8a97a6; margin-top:8px; display:${hasPdfSource ? 'none' : 'block'};">Tölts fel PDF-et az előnézethez.</div>
                    </div>
                </div>
            `;
        }

        function buildVideoCustomizationHtml(item, settings) {
            const durationSec = parseInt(settings.videoDurationSec || item.duration_seconds || 10, 10) || 10;

            return `
                <div style="display:grid; gap:14px;">
                    <div>
                        <label style="display:block; margin-bottom:6px; font-weight:bold;">🎬 Videó feltöltés (MP4)</label>
                        <div id="video-upload-area" style="border:2px dashed #1e40af; border-radius:8px; padding:20px; text-align:center; cursor:pointer; background:#f8f9fa;">
                            <input type="file" id="video-file-input" accept="video/mp4,.mp4" style="display:none;">
                            <div style="font-size:14px; color:#425466;">Húzz ide videót vagy <span style="color:#1e40af; font-weight:bold; text-decoration:underline;">kattints a kiválasztáshoz</span></div>
                            <div style="font-size:12px; color:#8a97a6; margin-top:6px;">Csak MP4 (H.264/AAC), max 80 MB</div>
                        </div>
                        <div style="margin-top:10px; padding:10px; border:1px solid #dde3eb; border-radius:8px; background:#fcfdff;">
                            <div style="display:flex; justify-content:space-between; gap:8px; align-items:center; margin-bottom:8px;">
                                <strong style="font-size:13px; color:#1f2a37;">☁️ Korábban feltöltött videók</strong>
                                <button type="button" id="video-library-refresh" style="padding:5px 8px; border:1px solid #1e40af; background:#fff; color:#1e40af; border-radius:5px; cursor:pointer; font-size:12px;">Frissítés</button>
                            </div>
                            <div id="video-library-status" style="font-size:12px; color:#425466; margin-bottom:6px;">Betöltés...</div>
                            <div id="video-library-list" style="display:grid; gap:6px;"></div>
                            <button type="button" id="video-library-import" style="margin-top:8px; padding:6px 10px; border:1px solid #16a34a; background:#16a34a; color:#fff; border-radius:5px; cursor:pointer; font-size:12px;">Kiválasztott importálása</button>
                        </div>
                        <div id="video-upload-status" style="font-size:12px; color:#425466; margin-top:8px;"></div>
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px;">
                        <div>
                            <label style="display:block; margin-bottom:4px; font-weight:bold;">Kitöltés</label>
                            <select id="video-fit-mode" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                                <option value="contain" ${(settings.fitMode || 'contain') === 'contain' ? 'selected' : ''}>Contain</option>
                                <option value="cover" ${settings.fitMode === 'cover' ? 'selected' : ''}>Cover</option>
                                <option value="fill" ${settings.fitMode === 'fill' ? 'selected' : ''}>Fill</option>
                            </select>
                        </div>
                        <div>
                            <label style="display:block; margin-bottom:4px; font-weight:bold;">Némítás</label>
                            <label style="display:flex; align-items:center; gap:8px; border:1px solid #d1d5db; border-radius:5px; padding:8px;">
                                <input type="checkbox" id="video-muted" ${(settings.muted !== false) ? 'checked' : ''}>
                                <span style="font-size:13px;">Lejátszás némítva</span>
                            </label>
                        </div>
                        <div>
                            <label style="display:block; margin-bottom:4px; font-weight:bold;">Háttérszín</label>
                            <input type="color" id="video-bg-color" value="${settings.bgColor || '#000000'}" style="width:100%; height:40px; border:1px solid #ccc; border-radius:5px;">
                        </div>
                    </div>

                    <div id="video-duration-badge" style="padding:10px; border:1px solid #c7e7d2; background:#ecfdf3; border-radius:8px; color:#0f5132; font-size:13px;">
                        Loop időtartam: ${durationSec} s (fix, videó hossza)
                    </div>

                    <div style="border:1px solid #d6dde8; border-radius:8px; padding:10px; background:#f8fafc;">
                        <div style="font-weight:700; color:#425466; margin-bottom:8px;">Előnézet</div>
                        <div style="height:320px; border:1px solid #e0e6ed; border-radius:6px; background:#fff; overflow:hidden;">
                            <iframe id="video-live-preview-iframe" style="width:100%; height:100%; border:0; background:#000;"></iframe>
                        </div>
                        <div id="video-preview-empty" style="font-size:12px; color:#8a97a6; margin-top:8px; display:${settings.videoAssetUrl ? 'none' : 'block'};">Tölts fel MP4 videót az előnézethez.</div>
                    </div>
                </div>
            `;
        }

        function buildOverlayCustomizationHtml(overlaySettings) {
            const overlayCollectionText = (() => {
                try {
                    const parsed = JSON.parse(String(overlaySettings.textOverlayCollectionJson || '[]'));
                    return Array.isArray(parsed)
                        ? parsed.map((entry) => String(entry || '').trim()).filter(Boolean).join('\n')
                        : '';
                } catch (_) {
                    return '';
                }
            })();

            return `
                <div style="display:grid; gap:12px; margin-top:16px; border:1px solid #d6dde8; border-radius:8px; padding:12px; background:#f8fafc;">
                    <div style="font-weight:700; color:#1f2a37;">🧩 Overlay modulok (ráhúzható óra/szöveg)</div>

                    <div style="padding:10px; border:1px solid #e2e8f0; border-radius:6px; background:#fff; display:grid; gap:10px;">
                        <label style="display:flex; align-items:center; gap:10px; cursor:pointer;">
                            <input type="checkbox" id="setting-clockOverlayEnabled" ${overlaySettings.clockOverlayEnabled ? 'checked' : ''} style="width:20px; height:20px;">
                            <span style="font-weight:600;">Óra overlay bekapcsolása</span>
                        </label>
                        <div id="clockOverlaySettings" style="display:${overlaySettings.clockOverlayEnabled ? 'grid' : 'none'}; gap:10px; grid-template-columns:1fr 1fr;">
                            <div>
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">Pozíció</label>
                                <select id="setting-clockOverlayPosition" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                                    <option value="top" ${overlaySettings.clockOverlayPosition === 'top' ? 'selected' : ''}>FENT</option>
                                    <option value="bottom" ${overlaySettings.clockOverlayPosition === 'bottom' ? 'selected' : ''}>LENT</option>
                                </select>
                            </div>
                            <div>
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">Sáv magasság (%)</label>
                                <input type="number" id="setting-clockOverlayHeightPercent" value="${overlaySettings.clockOverlayHeightPercent || 40}" min="20" max="40" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                            </div>
                            <div>
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">Óra szín</label>
                                <input type="color" id="setting-clockOverlayTimeColor" value="${overlaySettings.clockOverlayTimeColor || '#ffffff'}" style="width:100%; height:40px; border:1px solid #ccc; border-radius:5px;">
                            </div>
                            <div>
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">Dátum szín</label>
                                <input type="color" id="setting-clockOverlayDateColor" value="${overlaySettings.clockOverlayDateColor || '#ffffff'}" style="width:100%; height:40px; border:1px solid #ccc; border-radius:5px;">
                            </div>
                        </div>
                    </div>

                    <div style="padding:10px; border:1px solid #e2e8f0; border-radius:6px; background:#fff; display:grid; gap:10px;">
                        <label style="display:flex; align-items:center; gap:10px; cursor:pointer;">
                            <input type="checkbox" id="setting-textOverlayEnabled" ${overlaySettings.textOverlayEnabled ? 'checked' : ''} style="width:20px; height:20px;">
                            <span style="font-weight:600;">Szöveg overlay bekapcsolása</span>
                        </label>
                        <div id="textOverlaySettings" style="display:${overlaySettings.textOverlayEnabled ? 'grid' : 'none'}; gap:10px; grid-template-columns:1fr 1fr;">
                            <div>
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">Pozíció</label>
                                <select id="setting-textOverlayPosition" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                                    <option value="top" ${overlaySettings.textOverlayPosition === 'top' ? 'selected' : ''}>FENT</option>
                                    <option value="bottom" ${overlaySettings.textOverlayPosition === 'bottom' ? 'selected' : ''}>LENT</option>
                                </select>
                            </div>
                            <div>
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">Sáv magasság (%)</label>
                                <input type="number" id="setting-textOverlayHeightPercent" value="${overlaySettings.textOverlayHeightPercent || 20}" min="12" max="40" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                            </div>
                            <div style="grid-column:1 / span 2;">
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">Szöveg forrása</label>
                                <select id="setting-textOverlaySourceType" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                                    <option value="manual" ${(overlaySettings.textOverlaySourceType || 'manual') === 'manual' ? 'selected' : ''}>Kézi szöveg</option>
                                    <option value="collection" ${overlaySettings.textOverlaySourceType === 'collection' ? 'selected' : ''}>Szöveggyűjtemény</option>
                                    <option value="external" ${overlaySettings.textOverlaySourceType === 'external' ? 'selected' : ''}>Külső forrás (URL)</option>
                                </select>
                            </div>
                            <div>
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">Betűméret (px)</label>
                                <input type="number" id="setting-textOverlayFontSize" value="${overlaySettings.textOverlayFontSize || 52}" min="18" max="120" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                            </div>
                            <div>
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">Szín</label>
                                <input type="color" id="setting-textOverlayColor" value="${overlaySettings.textOverlayColor || '#ffffff'}" style="width:100%; height:40px; border:1px solid #ccc; border-radius:5px;">
                            </div>
                            <div style="grid-column:1 / span 2;">
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">Gördülési sebesség (px/s)</label>
                                <input type="number" id="setting-textOverlaySpeedPxPerSec" value="${overlaySettings.textOverlaySpeedPxPerSec || 120}" min="40" max="320" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                            </div>
                            <div id="textOverlayManualWrap" style="grid-column:1 / span 2;">
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">Szöveg</label>
                                <input type="text" id="setting-textOverlayText" value="${escapeHtml(overlaySettings.textOverlayText || 'Sem vložte text...')}" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                            </div>
                            <div id="textOverlayCollectionWrap" style="grid-column:1 / span 2;">
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">Szöveggyűjtemény (1 sor = 1 elem)</label>
                                <textarea id="setting-textOverlayCollection" rows="5" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px; resize:vertical;">${escapeHtml(overlayCollectionText)}</textarea>
                            </div>
                            <div id="textOverlayExternalWrap" style="grid-column:1 / span 2;">
                                <label style="display:block; margin-bottom:4px; font-weight:bold;">Külső forrás URL</label>
                                <input type="url" id="setting-textOverlayExternalUrl" value="${escapeHtml(overlaySettings.textOverlayExternalUrl || '')}" placeholder="https://..." style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;">
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }

        function getCustomizationModalWidthStyle(moduleKey) {
            return isWideCustomizationModule(moduleKey)
                ? 'max-width: 94vw; width: 94vw;'
                : 'max-width: 600px; width: 90%;';
        }

        function createCustomizationModalElement(item, index, formHtml, moduleKey) {
            const modal = document.createElement('div');
            modal.className = 'module-customization-modal';
            modal.style.cssText = `
                display: flex;
                position: fixed;
                z-index: 4000;
                left: 0;
                top: 0;
                width: 100%;
                height: 100%;
                background-color: rgba(0, 0, 0, 0.5);
                align-items: center;
                justify-content: center;
            `;

            const modalWidthStyle = getCustomizationModalWidthStyle(moduleKey);

            modal.innerHTML = `
                <div style="
                    background: white;
                    padding: 30px;
                    border-radius: 12px;
                    ${modalWidthStyle}
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

            return modal;
        }

        function initializeCustomizationModuleUi(moduleKey, item, settings) {
            if (moduleKey === 'pdf') {
                window.currentPdfBase64 = item.settings?.pdfDataBase64 || '';
                window.pdfModuleSettings = {
                    ...item.settings,
                    pdfDataBase64: window.currentPdfBase64
                };
                setTimeout(() => {
                    GroupLoopPdfModule.init();
                }, 0);
                return;
            }

            if (moduleKey === 'image-gallery' || moduleKey === 'gallery') {
                window.galleryModuleSettings = { ...item.settings };
                setTimeout(() => {
                    const galleryModuleApi =
                        window.GroupLoopGalleryModule ||
                        (typeof GroupLoopGalleryModule !== 'undefined' ? GroupLoopGalleryModule : null);

                    if (galleryModuleApi?.init) {
                        galleryModuleApi.init();
                    }
                }, 0);
                return;
            }

            if (moduleKey === 'video') {
                window.videoModuleSettings = {
                    videoAssetUrl: '',
                    videoAssetId: '',
                    videoDurationSec: parseInt(item.duration_seconds || 10, 10) || 10,
                    muted: true,
                    fitMode: 'contain',
                    bgColor: '#000000',
                    ...item.settings
                };
                setTimeout(() => {
                    const videoModuleApi =
                        window.GroupLoopVideoModule ||
                        (typeof GroupLoopVideoModule !== 'undefined' ? GroupLoopVideoModule : null);

                    if (videoModuleApi?.init) {
                        videoModuleApi.init();
                    }
                }, 0);
                return;
            }

            if (moduleKey === 'meal-menu') {
                bindMealModuleModalEvents(settings);
                return;
            }

            if (moduleKey === 'room-occupancy') {
                bindRoomOccupancyModuleModalEvents(settings);
            }
        }

        function collectClockSettingsFromForm() {
            const settings = {};
            settings.type = document.getElementById('setting-type')?.value || 'digital';
            settings.format = '24h';
            settings.dateFormat = document.getElementById('setting-dateFormat')?.value || 'full';
            settings.timeColor = document.getElementById('setting-timeColor')?.value || '#ffffff';
            settings.dateColor = document.getElementById('setting-dateColor')?.value || '#ffffff';
            settings.bgColor = document.getElementById('setting-bgColor')?.value || '#000000';
            settings.timeFontSize = parseInt(document.getElementById('setting-timeFontSize')?.value, 10) || parseInt(document.getElementById('setting-fontSize')?.value, 10) || 120;
            settings.dateFontSize = parseInt(document.getElementById('setting-dateFontSize')?.value, 10) || Math.max(16, Math.round(settings.timeFontSize * 0.3));
            settings.fontSize = settings.timeFontSize;
            settings.clockSize = parseInt(document.getElementById('setting-clockSize')?.value) || 300;
            settings.showSeconds = document.getElementById('setting-showSeconds')?.checked !== false;
            settings.showDate = document.getElementById('setting-showDate')?.checked !== false;
            if (!settings.showDate) {
                settings.dateFormat = 'none';
            }
            settings.language = document.getElementById('setting-language')?.value || 'sk';
            return settings;
        }

        function collectDefaultLogoSettings() {
            return {
                text: 'edudisplej.sk',
                fontSize: 120,
                textColor: '#ffffff',
                bgColor: '#000000',
                showVersion: false,
                version: ''
            };
        }

        function collectTextSettingsFromForm() {
            const settings = {};
            const richEditor = document.getElementById('text-editor-area');
            const sourceType = String(document.getElementById('setting-textSourceType')?.value || 'manual');
            const selectedCollectionId = parseInt(document.getElementById('setting-textCollectionId')?.value || '0', 10) || 0;
            const textHtml = sanitizeRichTextHtml(richEditor ? richEditor.innerHTML : (document.getElementById('setting-text')?.value || ''));
            const selectedCollection = sourceType === 'collection' ? getTextCollectionById(selectedCollectionId) : null;

            settings.textSourceType = sourceType === 'collection' ? 'collection' : 'manual';
            settings.textCollectionId = sourceType === 'collection' ? selectedCollectionId : 0;
            settings.textCollectionLabel = sourceType === 'collection' ? String(selectedCollection?.title || '') : '';
            settings.textCollectionVersionTs = sourceType === 'collection' ? Date.now() : 0;

            if (sourceType === 'collection' && selectedCollection) {
                settings.text = sanitizeRichTextHtml(selectedCollection.content_html || '') || 'Sem vložte text...';
            } else {
                settings.text = textHtml;
            }
            settings.fontFamily = document.getElementById('setting-richFontFamily')?.value || 'Arial, sans-serif';
            settings.fontSize = Math.max(8, parseInt(document.getElementById('setting-richFontSize')?.value || '72', 10) || 72);
            settings.fontWeight = '700';
            settings.fontStyle = 'normal';
            settings.lineHeight = Math.max(0.8, Math.min(2.5, parseFloat(document.getElementById('setting-richLineHeight')?.value || '1.2') || 1.2));
            settings.textAlign = 'left';
            settings.textColor = '#ffffff';
            settings.bgColor = sourceType === 'collection' && selectedCollection
                ? (selectedCollection.bg_color || '#000000')
                : (document.getElementById('setting-bgColor')?.value || '#000000');
            settings.bgImageData = sourceType === 'collection' && selectedCollection
                ? String(selectedCollection.bg_image_data || '')
                : (document.getElementById('setting-bgImageData')?.value || '');
            settings.textAnimationEntry = document.getElementById('setting-textAnimationEntry')?.value || 'none';
            settings.scrollMode = document.getElementById('setting-scrollMode')?.checked === true;
            settings.scrollStartPauseMs = Math.round((parseFloat(document.getElementById('setting-scrollStartPauseSec')?.value || '3') || 3) * 1000);
            settings.scrollEndPauseMs = Math.round((parseFloat(document.getElementById('setting-scrollEndPauseSec')?.value || '3') || 3) * 1000);
            settings.scrollSpeedPxPerSec = parseInt(document.getElementById('setting-scrollSpeedPxPerSec')?.value, 10) || 35;
            return settings;
        }

        function collectPdfSettingsFromForm(item) {
            const settings = {};
            const pdfBase64 = window.pdfModuleSettings?.pdfDataBase64 || (item.settings?.pdfDataBase64 || '');
            const pdfAssetUrl = window.pdfModuleSettings?.pdfAssetUrl || (item.settings?.pdfAssetUrl || '');
            const pdfAssetId = window.pdfModuleSettings?.pdfAssetId || (item.settings?.pdfAssetId || '');

            settings.pdfAssetUrl = pdfAssetUrl;
            if (pdfAssetId) {
                settings.pdfAssetId = parseInt(pdfAssetId, 10) || String(pdfAssetId);
            }
            settings.pdfDataBase64 = pdfAssetUrl ? '' : pdfBase64;
            settings.zoomLevel = parseInt(document.getElementById('pdf-zoomLevel')?.value, 10) || 100;
            settings.autoScrollEnabled = document.getElementById('pdf-autoScrollEnabled')?.checked === true;
            settings.autoScrollSpeedPxPerSec = parseInt(document.getElementById('pdf-scrollSpeed')?.value) || 30;
            settings.autoScrollStartPauseMs = parseInt(document.getElementById('pdf-startPause')?.value) || 2000;
            settings.autoScrollEndPauseMs = parseInt(document.getElementById('pdf-endPause')?.value) || 2000;
            settings.pauseAtPercent = parseInt(document.getElementById('pdf-pauseAtPercent')?.value, 10);
            if (!Number.isFinite(settings.pauseAtPercent)) {
                settings.pauseAtPercent = -1;
            }
            settings.pauseDurationMs = parseInt(document.getElementById('pdf-pauseDurationMs')?.value, 10) || 2000;
            return settings;
        }

        function collectGallerySettingsFromForm(item) {
            const rawJson = document.getElementById('gallery-image-urls-json')?.value || '[]';
            let normalizedImages = [];
            try {
                const parsed = JSON.parse(rawJson);
                normalizedImages = Array.isArray(parsed)
                    ? parsed.map(v => String(v || '').trim()).filter(Boolean).slice(0, 10)
                    : [];
            } catch (_) {
                normalizedImages = [];
            }

            const displayMode = document.getElementById('gallery-display-mode')?.value || 'slideshow';
            if (displayMode === 'single' && normalizedImages.length > 1) {
                normalizedImages = normalizedImages.slice(0, 1);
            }

            const rawSlideInterval = parseInt(document.getElementById('gallery-slide-interval')?.value, 10);
            const slideIntervalSec = Math.max(1, Math.min(30, Number.isFinite(rawSlideInterval) ? rawSlideInterval : 5));
            const transitionEnabled = document.getElementById('gallery-transition-enabled')?.checked !== false;
            const rawCollageColumns = parseInt(document.getElementById('gallery-collage-columns')?.value, 10);
            const collageColumns = Math.max(2, Math.min(5, Number.isFinite(rawCollageColumns) ? rawCollageColumns : 3));

            const settings = {
                imageUrlsJson: JSON.stringify(normalizedImages),
                displayMode,
                fitMode: document.getElementById('gallery-fit-mode')?.value || 'contain',
                slideIntervalSec,
                transitionEnabled,
                transitionMs: transitionEnabled ? 450 : 0,
                collageColumns,
                bgColor: document.getElementById('gallery-bg-color')?.value || '#000000'
            };

            return {
                settings,
                durationSeconds: getGalleryLoopDurationSeconds(settings, item.duration_seconds)
            };
        }

        function collectVideoSettingsFromForm(item) {
            const source = window.videoModuleSettings || item.settings || {};
            const videoDurationSec = Math.max(1, parseInt(source.videoDurationSec || item.duration_seconds || 10, 10) || 10);

            const settings = {
                videoAssetUrl: String(source.videoAssetUrl || ''),
                videoAssetId: source.videoAssetId ? parseInt(source.videoAssetId, 10) || String(source.videoAssetId) : '',
                videoDurationSec,
                muted: source.muted !== false,
                fitMode: ['contain', 'cover', 'fill'].includes(String(source.fitMode || 'contain'))
                    ? String(source.fitMode)
                    : 'contain',
                bgColor: String(source.bgColor || '#000000')
            };

            return {
                settings,
                durationSeconds: videoDurationSec
            };
        }

        function collectMealMenuSettingsFromForm() {
            return {
                siteKey: String(document.getElementById('setting-mealSiteKey')?.value || 'jedalen.sk').trim() || 'jedalen.sk',
                institutionId: parseInt(document.getElementById('setting-mealInstitutionId')?.value || '0', 10) || 0,
                sourceType: (String(document.getElementById('setting-mealSourceType')?.value || 'manual').toLowerCase() === 'server') ? 'server' : 'manual',
                language: (() => {
                    const raw = String(document.getElementById('setting-mealLanguage')?.value || 'hu').toLowerCase().trim();
                    return ['hu', 'sk', 'en'].includes(raw) ? raw : 'hu';
                })(),
                showHeaderTitle: document.getElementById('setting-showHeaderTitle')?.checked !== false,
                customHeaderTitle: String(document.getElementById('setting-customHeaderTitle')?.value || '').trim(),
                showInstitutionName: document.getElementById('setting-showInstitutionName')?.checked !== false,
                showBreakfast: document.getElementById('setting-showBreakfast')?.checked === true,
                showSnackAm: document.getElementById('setting-showSnackAm')?.checked === true,
                showLunch: document.getElementById('setting-showLunch')?.checked === true,
                showSnackPm: document.getElementById('setting-showSnackPm')?.checked === true,
                showDinner: document.getElementById('setting-showDinner')?.checked === true,
                showMealTypeEmojis: false,
                showMealTypeSvgIcons: document.getElementById('setting-showMealTypeSvgIcons')?.checked !== false,
                showAllergenEmojis: document.getElementById('setting-showAllergenEmojis')?.checked === true,
                centerAlign: document.getElementById('setting-mealCenterAlign')?.checked === true,
                slowScrollOnOverflow: document.getElementById('setting-mealSlowScrollOnOverflow')?.checked === true,
                slowScrollSpeedPxPerSec: Math.max(8, Math.min(120, parseInt(document.getElementById('setting-mealSlowScrollSpeedPxPerSec')?.value || '28', 10) || 28)),
                fontFamily: String(document.getElementById('setting-mealFontFamily')?.value || 'Segoe UI, Tahoma, sans-serif').trim() || 'Segoe UI, Tahoma, sans-serif',
                mealTitleFontSize: Math.max(0.8, Math.min(4, parseFloat(document.getElementById('setting-mealTitleFontSize')?.value || '1.5') || 1.5)),
                mealTextFontSize: Math.max(0.8, Math.min(4, parseFloat(document.getElementById('setting-mealTextFontSize')?.value || '1.35') || 1.35)),
                textFontWeight: parseInt(document.getElementById('setting-mealTextFontWeight')?.value || '600', 10) || 600,
                lineHeight: Math.max(1, Math.min(2.2, parseFloat(document.getElementById('setting-mealLineHeight')?.value || '1.35') || 1.35)),
                wrapText: document.getElementById('setting-mealWrapText')?.checked !== false,
                showAppetiteMessage: document.getElementById('setting-showAppetiteMessage')?.checked === true,
                appetiteMessageText: String(document.getElementById('setting-appetiteMessageText')?.value || 'Jó étvágyat kívánunk!').trim(),
                showSourceUrl: document.getElementById('setting-showSourceUrl')?.checked === true,
                sourceUrl: String(document.getElementById('setting-sourceUrl')?.value || '').trim(),
                apiBaseUrl: '../../api/meal_plan.php'
            };
        }

        function collectRoomOccupancySettingsFromForm() {
            return {
                roomId: parseInt(document.getElementById('setting-roomOccRoomId')?.value || '0', 10) || 0,
                showOnlyCurrent: document.getElementById('setting-roomOccShowOnlyCurrent')?.checked === true,
                showNextCount: Math.max(1, Math.min(12, parseInt(document.getElementById('setting-roomOccShowNextCount')?.value || '4', 10) || 4)),
                apiBaseUrl: '../../api/room_occupancy.php'
            };
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
                Object.assign(newSettings, collectClockSettingsFromForm());
            } else if (moduleKey === 'default-logo') {
                Object.assign(newSettings, collectDefaultLogoSettings());
            } else if (moduleKey === 'text') {
                Object.assign(newSettings, collectTextSettingsFromForm());
            } else if (moduleKey === 'pdf') {
                Object.assign(newSettings, collectPdfSettingsFromForm(item));
            } else if (moduleKey === 'image-gallery' || moduleKey === 'gallery') {
                const galleryResult = collectGallerySettingsFromForm(item);
                Object.assign(newSettings, galleryResult.settings);
                loopItems[index].duration_seconds = galleryResult.durationSeconds;
            } else if (moduleKey === 'video') {
                const videoResult = collectVideoSettingsFromForm(item);
                Object.assign(newSettings, videoResult.settings);
                loopItems[index].duration_seconds = videoResult.durationSeconds;
            } else if (moduleKey === 'meal-menu') {
                Object.assign(newSettings, collectMealMenuSettingsFromForm());
            } else if (moduleKey === 'room-occupancy') {
                Object.assign(newSettings, collectRoomOccupancySettingsFromForm());
            }

            if (isOverlayCarrierModule(moduleKey)) {
                const withOverlay = collectOverlaySettingsFromForm({
                    ...(item.settings || {}),
                    ...newSettings
                });
                Object.assign(newSettings, withOverlay);
            }
            
            loopItems[index].settings = newSettings;
            
            // Close customization modal immediately
            document.querySelectorAll('.module-customization-modal').forEach((el) => el.remove());
            
            showAutosaveToast('✓ Beállítások mentve');
            scheduleAutoSave(250);
            renderLoop();
        }
        
        // ===== LIVE PREVIEW FUNCTIONS =====

        function clearPreviewPlaybackTimers() {
            clearTimeout(previewTimeout);
            clearInterval(previewInterval);
        }

        function updatePreviewLoopCountDisplay() {
            document.getElementById('loopCount').textContent = loopCycleCount;
        }

        function updatePreviewModuleInfo(module) {
            document.getElementById('currentModule').textContent = `${currentPreviewIndex + 1}. ${module.module_name}`;
            document.getElementById('navInfo').textContent = `${currentPreviewIndex + 1} / ${loopItems.length}`;
        }

        function loadPreviewModuleIntoIframe(moduleUrl) {
            const iframe = document.getElementById('previewIframe');
            const emptyDiv = document.getElementById('previewEmpty');

            iframe.src = moduleUrl;
            iframe.style.display = 'block';
            emptyDiv.style.display = 'none';
            syncLoopPreviewIframeScale();
        }

        function advancePreviewIndexForward() {
            currentPreviewIndex++;
            if (currentPreviewIndex >= loopItems.length) {
                currentPreviewIndex = 0;
                loopCycleCount++;
                totalLoopStartTime = Date.now();
                updatePreviewLoopCountDisplay();
            }
        }

        function advancePreviewIndexBackward() {
            currentPreviewIndex--;
            if (currentPreviewIndex < 0) {
                currentPreviewIndex = loopItems.length - 1;
                if (loopCycleCount > 0) {
                    loopCycleCount--;
                }
            }
            updatePreviewLoopCountDisplay();
        }

        function scheduleNextPreviewModule(durationSeconds) {
            previewTimeout = setTimeout(() => {
                advancePreviewIndexForward();
                playCurrentModule();
            }, durationSeconds * 1000);
        }
        
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
                clearPreviewPlaybackTimers();
            }
        }
        
        function stopPreview() {
            isPaused = false;
            currentPreviewIndex = 0;
            loopCycleCount = 0;

            clearPreviewPlaybackTimers();
            
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

            clearPreviewPlaybackTimers();
            advancePreviewIndexBackward();
            playCurrentModule();
        }
        
        function nextModule() {
            if (loopItems.length === 0) return;

            clearPreviewPlaybackTimers();
            currentPreviewIndex++;
            if (currentPreviewIndex >= loopItems.length) {
                currentPreviewIndex = 0;
                loopCycleCount++;
            }
            updatePreviewLoopCountDisplay();
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

            updatePreviewModuleInfo(module);
            
            // Build module URL with settings
            const moduleUrl = buildModuleUrl(module);

            loadPreviewModuleIntoIframe(moduleUrl);
            
            // Start progress bar
            currentModuleStartTime = Date.now();
            updateProgressBar(duration);

            // MINDIG schedule-ölj következő modult (még 1 elem esetén is loop)
            scheduleNextPreviewModule(duration);
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
        
        function createPdfDataPreviewKey(pdfDataBase64) {
            const key = `pdf_preview_${Date.now()}_${Math.floor(Math.random() * 100000)}`;
            window.__edudisplejPdfPreviewStore = window.__edudisplejPdfPreviewStore || {};
            window.__edudisplejPdfPreviewStore[key] = String(pdfDataBase64 || '');

            const keys = Object.keys(window.__edudisplejPdfPreviewStore);
            if (keys.length > 30) {
                keys.slice(0, keys.length - 30).forEach((staleKey) => {
                    delete window.__edudisplejPdfPreviewStore[staleKey];
                });
            }

            return key;
        }

        function appendClockPreviewParams(params, settings) {
            if (settings.type) params.append('type', settings.type);
            if (settings.format) params.append('format', settings.format);
            if (settings.dateFormat) params.append('dateFormat', settings.dateFormat);
            if (settings.timeColor) params.append('timeColor', settings.timeColor);
            if (settings.dateColor) params.append('dateColor', settings.dateColor);
            if (settings.bgColor) params.append('bgColor', settings.bgColor);
            if (settings.fontSize) params.append('fontSize', settings.fontSize);
            if (settings.timeFontSize) params.append('timeFontSize', settings.timeFontSize);
            if (settings.dateFontSize) params.append('dateFontSize', settings.dateFontSize);
            if (settings.clockSize) params.append('clockSize', settings.clockSize);
            if (settings.showSeconds !== undefined) params.append('showSeconds', settings.showSeconds);
            if (settings.showDate !== undefined) params.append('showDate', settings.showDate);
            if (settings.language) params.append('language', settings.language);
        }

        function appendDefaultLogoPreviewParams(params) {
            params.append('text', 'edudisplej.sk');
            params.append('showVersion', 'false');
        }

        function appendTextPreviewParams(params, settings, module) {
            if (settings.textSourceType) params.append('textSourceType', settings.textSourceType);
            if (settings.textCollectionId !== undefined) params.append('textCollectionId', settings.textCollectionId);
            if (settings.textCollectionLabel) params.append('textCollectionLabel', settings.textCollectionLabel);
            if (settings.textCollectionVersionTs !== undefined) params.append('textCollectionVersionTs', settings.textCollectionVersionTs);
            params.append('text', settings.text || '');
            params.append('durationSeconds', String(parseInt(module.duration_seconds || 10, 10) || 10));
            if (settings.fontFamily) params.append('fontFamily', settings.fontFamily);
            if (settings.fontSize) params.append('fontSize', settings.fontSize);
            if (settings.fontWeight) params.append('fontWeight', settings.fontWeight);
            if (settings.fontStyle) params.append('fontStyle', settings.fontStyle);
            if (settings.lineHeight) params.append('lineHeight', settings.lineHeight);
            if (settings.textAlign) params.append('textAlign', settings.textAlign);
            if (settings.textColor) params.append('textColor', settings.textColor);
            if (settings.bgColor) params.append('bgColor', settings.bgColor);
            if (settings.textAnimationEntry) params.append('textAnimationEntry', settings.textAnimationEntry);
            if (settings.scrollMode !== undefined) params.append('scrollMode', settings.scrollMode);
            if (settings.scrollStartPauseMs !== undefined) params.append('scrollStartPauseMs', settings.scrollStartPauseMs);
            if (settings.scrollEndPauseMs !== undefined) params.append('scrollEndPauseMs', settings.scrollEndPauseMs);
            if (settings.scrollSpeedPxPerSec !== undefined) params.append('scrollSpeedPxPerSec', settings.scrollSpeedPxPerSec);
            if (settings.bgImageData) {
                try {
                    const storageKey = `text_bg_${Date.now()}_${Math.floor(Math.random() * 100000)}`;
                    localStorage.setItem(storageKey, settings.bgImageData);
                    params.append('bgImageStorageKey', storageKey);
                } catch (error) {
                    params.append('bgImageData', settings.bgImageData);
                }
            }
        }

        function appendPdfPreviewParams(params, settings) {
            if (settings.pdfAssetUrl) {
                params.append('pdfAssetUrl', settings.pdfAssetUrl);
            } else if (settings.pdfDataBase64) {
                const dataKey = createPdfDataPreviewKey(settings.pdfDataBase64);
                params.append('pdfDataKey', dataKey);
            }
            if (settings.zoomLevel !== undefined) params.append('zoomLevel', settings.zoomLevel);

            const autoScrollEnabled = settings.autoScrollEnabled === true || settings.navigationMode === 'auto';
            params.append('autoScrollEnabled', autoScrollEnabled ? 'true' : 'false');
            if (settings.autoScrollSpeedPxPerSec !== undefined) params.append('autoScrollSpeedPxPerSec', settings.autoScrollSpeedPxPerSec);
            if (settings.autoScrollStartPauseMs !== undefined) params.append('autoScrollStartPauseMs', settings.autoScrollStartPauseMs);
            if (settings.autoScrollEndPauseMs !== undefined) params.append('autoScrollEndPauseMs', settings.autoScrollEndPauseMs);

            if (settings.pauseAtPercent !== undefined) {
                params.append('pauseAtPercent', settings.pauseAtPercent);
            } else {
                params.append('pauseAtPercent', '-1');
            }

            if (settings.pauseDurationMs !== undefined) {
                params.append('pauseDurationMs', settings.pauseDurationMs);
            }
        }

        function appendGalleryPreviewParams(params, settings) {
            params.append('imageUrlsJson', settings.imageUrlsJson || '[]');
            params.append('displayMode', settings.displayMode || 'slideshow');
            params.append('fitMode', settings.fitMode || 'contain');
            params.append('slideIntervalSec', settings.slideIntervalSec || 5);
            params.append('transitionEnabled', settings.transitionEnabled === false ? 'false' : 'true');
            params.append('transitionMs', settings.transitionEnabled === false ? 0 : 450);
            params.append('collageColumns', settings.collageColumns || 3);
            params.append('bgColor', settings.bgColor || '#000000');
        }

        function appendVideoPreviewParams(params, settings) {
            if (settings.videoAssetUrl) params.append('videoAssetUrl', settings.videoAssetUrl);
            params.append('muted', settings.muted === false ? 'false' : 'true');
            params.append('fitMode', settings.fitMode || 'contain');
            params.append('bgColor', settings.bgColor || '#000000');
        }

        function appendMealMenuPreviewParams(params, settings) {
            params.append('siteKey', String(settings.siteKey || 'jedalen.sk'));
            params.append('institutionId', String(parseInt(settings.institutionId || 0, 10) || 0));
            params.append('language', ['hu', 'sk', 'en'].includes(String(settings.language || '').toLowerCase()) ? String(settings.language).toLowerCase() : 'hu');
            params.append('showHeaderTitle', settings.showHeaderTitle === false ? 'false' : 'true');
            params.append('customHeaderTitle', String(settings.customHeaderTitle || ''));
            params.append('showInstitutionName', settings.showInstitutionName === false ? 'false' : 'true');
            params.append('showBreakfast', settings.showBreakfast === false ? 'false' : 'true');
            params.append('showSnackAm', settings.showSnackAm === false ? 'false' : 'true');
            params.append('showLunch', settings.showLunch === false ? 'false' : 'true');
            params.append('showSnackPm', settings.showSnackPm === true ? 'true' : 'false');
            params.append('showDinner', settings.showDinner === true ? 'true' : 'false');
            params.append('showMealTypeSvgIcons', settings.showMealTypeSvgIcons === false ? 'false' : 'true');
            params.append('showAllergenEmojis', settings.showAllergenEmojis === true ? 'true' : 'false');
            params.append('centerAlign', settings.centerAlign === true ? 'true' : 'false');
            params.append('slowScrollOnOverflow', settings.slowScrollOnOverflow === true ? 'true' : 'false');
            params.append('slowScrollSpeedPxPerSec', String(Math.max(8, Math.min(120, parseInt(settings.slowScrollSpeedPxPerSec || 28, 10) || 28))));
            params.append('mealTitleFontSize', String(Math.max(0.8, Math.min(4, parseFloat(settings.mealTitleFontSize || 2.1) || 2.1))));
            params.append('mealTextFontSize', String(Math.max(0.8, Math.min(4, parseFloat(settings.mealTextFontSize || 1.85) || 1.85))));
            params.append('textFontWeight', String(parseInt(settings.textFontWeight || 600, 10) || 600));
            params.append('lineHeight', String(Math.max(1, Math.min(2.2, parseFloat(settings.lineHeight || 1.4) || 1.4))));
            params.append('fontFamily', String(settings.fontFamily || 'Segoe UI, Tahoma, sans-serif'));
            params.append('showAppetiteMessage', settings.showAppetiteMessage === true ? 'true' : 'false');
            params.append('appetiteMessageText', String(settings.appetiteMessageText || ''));
            params.append('showSourceUrl', settings.showSourceUrl === true ? 'true' : 'false');
            params.append('sourceUrl', String(settings.sourceUrl || ''));
            params.append('apiBaseUrl', settings.apiBaseUrl || '../../api/meal_plan.php');
            if (companyId > 0) {
                params.append('company_id', String(companyId));
            }
        }

        function appendRoomOccupancyPreviewParams(params, settings) {
            params.append('roomId', String(parseInt(settings.roomId || 0, 10) || 0));
            params.append('showOnlyCurrent', settings.showOnlyCurrent === true ? 'true' : 'false');
            params.append('showNextCount', String(Math.max(1, Math.min(12, parseInt(settings.showNextCount || 4, 10) || 4))));
            params.append('apiBaseUrl', settings.apiBaseUrl || '../../api/room_occupancy.php');
            if (companyId > 0) {
                params.append('company_id', String(companyId));
            }
        }

        function buildModuleUrl(module) {
            const moduleKey = module.module_key || getModuleKeyById(module.module_id);
            const settings = module.settings || {};

            let baseUrl = '';
            const params = new URLSearchParams();

            switch(moduleKey) {
                case 'clock':
                    baseUrl = '../../modules/clock/m_clock.html';
                    appendClockPreviewParams(params, settings);
                    break;

                case 'default-logo':
                    baseUrl = '../../modules/default/m_default.html';
                    appendDefaultLogoPreviewParams(params);
                    break;

                case 'text':
                    baseUrl = '../../modules/text/m_text.html';
                    appendTextPreviewParams(params, settings, module);
                    break;

                case 'pdf':
                    baseUrl = '../../modules/pdf/m_pdf.html';
                    appendPdfPreviewParams(params, settings);
                    break;

                case 'image-gallery':
                case 'gallery':
                    baseUrl = '../../modules/gallery/m_gallery.html';
                    appendGalleryPreviewParams(params, settings);
                    break;

                case 'video':
                    baseUrl = '../../modules/video/m_video.html';
                    appendVideoPreviewParams(params, settings);
                    break;

                case 'meal-menu':
                    baseUrl = '../../modules/meal-menu/m_meal_menu.html';
                    appendMealMenuPreviewParams(params, settings);
                    break;

                case 'room-occupancy':
                    baseUrl = '../../modules/room-occupancy/m_room_occupancy.html';
                    appendRoomOccupancyPreviewParams(params, settings);
                    break;

                case 'turned-off':
                    baseUrl = '../../modules/default/m_default.html';
                    params.append('text', '⏻ Turned Off');
                    params.append('bgColor', '#111111');
                    break;

                default:
                    baseUrl = '../../modules/default/m_default.html';
                    params.append('text', module.module_name);
                    params.append('bgColor', '#1a3a52');
            }

            appendOverlayParams(params, settings, moduleKey);

            const queryString = params.toString();
            return queryString ? `${baseUrl}?${queryString}` : baseUrl;
        }

        function formatLanguageCode(language) {
            const code = String(language || 'sk').toLowerCase();
            if (code === 'hu') return 'HU';
            if (code === 'sk') return 'SK';
            if (code === 'en') return 'EN';
            return code.toUpperCase();
        }

        function normalizeGalleryImageUrls(rawValue) {
            let source = rawValue;

            if (Array.isArray(source)) {
                return source.map(v => String(v || '').trim()).filter(Boolean).slice(0, 10);
            }

            if (source && typeof source === 'object' && Array.isArray(source.imageUrls)) {
                return source.imageUrls.map(v => String(v || '').trim()).filter(Boolean).slice(0, 10);
            }

            if (typeof source === 'string') {
                const text = String(source || '').trim();
                if (!text) {
                    return [];
                }

                try {
                    const parsed = JSON.parse(text);
                    if (Array.isArray(parsed)) {
                        return parsed.map(v => String(v || '').trim()).filter(Boolean).slice(0, 10);
                    }
                } catch (_) {
                    const split = text.split(',').map(v => String(v || '').trim()).filter(Boolean);
                    if (split.length > 1) {
                        return split.slice(0, 10);
                    }
                }
            }

            return [];
        }

        function getGalleryModeFromSettings(settings) {
            const mode = String(settings?.displayMode || 'slideshow');
            return ['slideshow', 'collage', 'single'].includes(mode) ? mode : 'slideshow';
        }

        function getGalleryLoopDurationSeconds(settings, fallbackDuration) {
            const slideRaw = parseInt(settings?.slideIntervalSec || 5, 10);
            const slideIntervalSec = Math.max(1, Math.min(30, Number.isFinite(slideRaw) ? slideRaw : 5));
            const images = normalizeGalleryImageUrls(settings?.imageUrlsJson ?? settings?.imageUrls ?? []);
            const mode = getGalleryModeFromSettings(settings);

            if (mode === 'slideshow') {
                return Math.max(1, slideIntervalSec * Math.max(1, images.length));
            }

            const fallback = parseInt(fallbackDuration || 0, 10);
            if (Number.isFinite(fallback) && fallback > 0) {
                return fallback;
            }
            return slideIntervalSec;
        }

        function getClockLoopItemSummary(settings) {
            const type = settings.type === 'analog' ? 'Analóg' : 'Digitális';
            const details = [type];

            if ((settings.type || 'digital') !== 'analog') {
                details.push('24h');
            }

            const language = formatLanguageCode(settings.language);
            return `${details.join(' • ')}<br>Nyelv: ${language}`;
        }

        function getTextLoopItemSummary(settings) {
            const snippet = String(settings.text || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
            const baseText = snippet ? snippet.slice(0, 48) : 'Szöveg modul';

            const details = [];
            if (settings.fontSize) {
                details.push(`${parseInt(settings.fontSize, 10) || 72}px`);
            }
            if (settings.textAlign) {
                const alignMap = { left: 'balra', center: 'középre', right: 'jobbra' };
                details.push(alignMap[String(settings.textAlign)] || String(settings.textAlign));
            }
            if (settings.fontWeight && String(settings.fontWeight) !== '400') {
                details.push('félkövér');
            }
            if (settings.scrollMode) {
                details.push('scroll');
            }
            if (String(settings.bgImageData || '').trim()) {
                details.push('háttérkép');
            }

            return details.length > 0
                ? `${baseText}<br>${details.join(' • ')}`
                : baseText;
        }

        function getGalleryLoopItemSummary(settings) {
            const galleryImages = normalizeGalleryImageUrls(settings.imageUrlsJson ?? settings.imageUrls ?? []);
            const imageCount = galleryImages.length;

            const modeMap = {
                slideshow: 'slideshow',
                collage: 'kollázs',
                single: 'egy kép'
            };
            const mode = modeMap[getGalleryModeFromSettings(settings)] || 'slideshow';
            const overlayFlags = [];
            if (settings.clockOverlayEnabled) {
                overlayFlags.push(`óra:${settings.clockOverlayPosition === 'bottom' ? 'lent' : 'fent'}`);
            }
            if (settings.textOverlayEnabled) {
                overlayFlags.push(`szöveg:${settings.textOverlayPosition === 'bottom' ? 'lent' : 'fent'}`);
            }
            const overlayLine = overlayFlags.length ? `<br>Overlay: ${overlayFlags.join(' • ')}` : '';
            return `${imageCount} kép<br>Mód: ${mode}${overlayLine}`;
        }

        function getVideoLoopItemSummary(item, settings) {
            const duration = parseInt(settings.videoDurationSec || item.duration_seconds || 0, 10);
            const fit = String(settings.fitMode || 'contain');
            const muted = settings.muted === false ? 'hanggal' : 'némítva';
            if (duration > 0) {
                return `${duration}s • ${fit}<br>${muted}`;
            }
            return `Videó • ${fit}<br>${muted}`;
        }

        function getMealMenuLoopItemSummary(settings) {
            const siteKey = String(settings.siteKey || 'jedalen.sk').trim() || 'jedalen.sk';
            const institutionId = parseInt(settings.institutionId || 0, 10) || 0;
            const language = ['hu', 'sk', 'en'].includes(String(settings.language || '').toLowerCase()) ? String(settings.language).toLowerCase() : 'hu';
            const visibleMeals = [];
            if (settings.showBreakfast !== false) visibleMeals.push('Reggeli');
            if (settings.showSnackAm !== false) visibleMeals.push('Tízórai');
            if (settings.showLunch !== false) visibleMeals.push('Ebéd');
            if (settings.showSnackPm === true) visibleMeals.push('Uzsonna');
            if (settings.showDinner === true) visibleMeals.push('Vacsora');

            const mealsText = visibleMeals.length > 0 ? visibleMeals.join(', ') : 'Nincs kijelölt étkezés';
            const allergenText = settings.showAllergenEmojis === true ? ' • allergének: be' : '';
            const iconText = settings.showMealTypeSvgIcons === false ? ' • SVG ikon: ki' : ' • SVG ikon: be';
            return `${siteKey} • intézmény #${institutionId} • nyelv: ${language.toUpperCase()}<br>${mealsText}${allergenText}${iconText}`;
        }

        function getRoomOccupancyLoopItemSummary(settings) {
            const roomId = parseInt(settings.roomId || 0, 10) || 0;
            const onlyCurrent = settings.showOnlyCurrent === true ? 'csak aktuális' : 'napi lista';
            const nextCount = Math.max(1, Math.min(12, parseInt(settings.showNextCount || 4, 10) || 4));
            return `terem #${roomId}<br>${onlyCurrent} • következő: ${nextCount}`;
        }

        function getLoopItemSummary(item) {
            if (!item) {
                return '';
            }

            const moduleKey = item.module_key || getModuleKeyById(item.module_id);
            const settings = item.settings || {};

            if (moduleKey === 'clock') {
                return getClockLoopItemSummary(settings);
            }

            if (moduleKey === 'default-logo') {
                return `EDUdisplej.sk`;
            }

            if (moduleKey === 'text') {
                return getTextLoopItemSummary(settings);
            }

            if (moduleKey === 'image-gallery' || moduleKey === 'gallery') {
                return getGalleryLoopItemSummary(settings);
            }

            if (moduleKey === 'video') {
                return getVideoLoopItemSummary(item, settings);
            }

            if (moduleKey === 'meal-menu') {
                return getMealMenuLoopItemSummary(settings);
            }

            if (moduleKey === 'room-occupancy') {
                return getRoomOccupancyLoopItemSummary(settings);
            }

            if (moduleKey === 'turned-off') {
                return 'Kijelző kikapcsolási idősáv';
            }

            return '';
        }

        function getLoopItemModuleKey(item) {
            return item?.module_key || getModuleKeyById(item?.module_id);
        }

        function getLoopItemDurationValue(item, moduleKey, isTechnicalItem, isGalleryItem) {
            if (isTechnicalItem) {
                return 60;
            }

            if (isGalleryItem) {
                return getGalleryLoopDurationSeconds(item?.settings || {}, item?.duration_seconds);
            }

            return parseInt(item?.duration_seconds || 10, 10);
        }

        function buildLoopItemDurationInputHtml(index, durationValue, durationBounds, flags) {
            const { isTechnicalItem, isVideoItem, isGalleryItem } = flags;
            const isReadOnly = isDefaultGroup || isTechnicalItem || isContentOnlyMode || isVideoItem || isGalleryItem;

            if (isReadOnly) {
                return `<input type="number" value="${durationValue}" min="${durationBounds.min}" max="${durationBounds.max}" step="${durationBounds.step}" disabled>`;
            }

            return `<input type="number" value="${durationValue}" min="${durationBounds.min}" max="${durationBounds.max}" step="${durationBounds.step}" onchange="updateDuration(${index}, this.value)" onkeydown="if (event.key === 'Enter') { event.preventDefault(); updateDuration(${index}, this.value); this.blur(); }" onclick="event.stopPropagation()">`;
        }

        function buildLoopItemActionButtonsHtml(index) {
            if (isDefaultGroup) {
                return `<button class="loop-btn" disabled title="A default csoport nem szerkeszthető">🔒</button>`;
            }

            if (isContentOnlyMode) {
                return `<button class="loop-btn" onclick="customizeModule(${index}); event.stopPropagation();" title="Testreszabás">⚙️</button>`;
            }

            return `<button class="loop-btn" onclick="customizeModule(${index}); event.stopPropagation();" title="Testreszabás">⚙️</button>
                        <button class="loop-btn" onclick="duplicateLoopItem(${index}); event.stopPropagation();" title="Duplikálás">📄</button>
                        <button class="loop-btn" onclick="removeFromLoop(${index}); event.stopPropagation();" title="Törlés">🗑️</button>`;
        }

        function createLoopItemElement(item, index) {
            const loopItem = document.createElement('div');
            loopItem.className = 'loop-item';
            loopItem.draggable = !isDefaultGroup && !isContentOnlyMode;
            loopItem.dataset.index = index;

            const isTechnicalItem = isTechnicalLoopItem(item);
            const moduleKey = getLoopItemModuleKey(item);
            const isVideoItem = moduleKey === 'video';
            const isGalleryItem = moduleKey === 'image-gallery' || moduleKey === 'gallery';
            const durationBounds = getDurationBoundsForModule(moduleKey);
            const durationValue = getLoopItemDurationValue(item, moduleKey, isTechnicalItem, isGalleryItem);

            if (isGalleryItem) {
                item.duration_seconds = durationValue;
            }

            const durationInputHtml = buildLoopItemDurationInputHtml(index, durationValue, durationBounds, {
                isTechnicalItem,
                isVideoItem,
                isGalleryItem,
            });

            const actionButtonsHtml = buildLoopItemActionButtonsHtml(index);

            loopItem.innerHTML = `
                    <div class="loop-order">${index + 1}</div>
                    <div class="loop-details">
                        <div class="loop-module-name">${item.module_name}</div>
                        <div class="loop-module-desc">${getLoopItemSummary(item)}</div>
                    </div>
                    <div class="loop-duration">
                        ${durationInputHtml}
                        <span class="loop-duration-suffix">s</span>
                    </div>
                    <div class="loop-actions">
                        ${actionButtonsHtml}
                    </div>
                `;

            if (!isDefaultGroup && !isContentOnlyMode) {
                loopItem.addEventListener('dragstart', handleDragStart);
                loopItem.addEventListener('dragover', handleDragOver);
                loopItem.addEventListener('drop', handleDrop);
                loopItem.addEventListener('dragend', handleDragEnd);
            }

            return loopItem;
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
                const loopItem = createLoopItemElement(item, index);
                container.appendChild(loopItem);
            });
            
            updateTotalDuration();
            if (loopItems.length > 0) {
                startPreview();
            }
        }

        function set24HourTimeSelectValue(selectId, preferred = '08:00') {
            const selectEl = document.getElementById(selectId);
            if (!selectEl) {
                return;
            }

            const values = [];
            for (let minute = 0; minute < 24 * 60; minute += 15) {
                values.push(minutesToTimeLabel(minute));
            }

            const normalized = String(preferred || '').slice(0, 5);
            selectEl.innerHTML = '';

            values.forEach((value) => {
                const option = document.createElement('option');
                option.value = value;
                option.textContent = value;
                selectEl.appendChild(option);
            });

            if (normalized && !values.includes(normalized)) {
                const extra = document.createElement('option');
                extra.value = normalized;
                extra.textContent = normalized;
                selectEl.appendChild(extra);
            }

            selectEl.value = normalized && selectEl.querySelector(`option[value="${normalized}"]`)
                ? normalized
                : values[0];
        }

        function buildFixedPlanTimeOptions() {
            set24HourTimeSelectValue('fixed-plan-start', document.getElementById('fixed-plan-start')?.value || '08:00');
            set24HourTimeSelectValue('fixed-plan-end', document.getElementById('fixed-plan-end')?.value || '10:00');
            set24HourTimeSelectValue('special-start-input', document.getElementById('special-start-input')?.value || '08:00');
            set24HourTimeSelectValue('special-end-input', document.getElementById('special-end-input')?.value || '10:00');
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

            fetch('../../api/rename_group.php', {
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
        
        function resolutionAspectLabel(resolution) {
            const [width, height] = String(resolution || '').split('x').map(Number);
            if (!width || !height) {
                return '';
            }
            const gcd = (a, b) => b ? gcd(b, a % b) : a;
            const divisor = gcd(width, height);
            return `${width / divisor}:${height / divisor}`;
        }

        function buildGroupResolutionChoices(kiosks) {
            const counts = new Map();
            (Array.isArray(kiosks) ? kiosks : []).forEach((kiosk) => {
                const value = String(kiosk?.screen_resolution || '').trim();
                if (!/^\d+x\d+$/i.test(value)) {
                    return;
                }
                counts.set(value, (counts.get(value) || 0) + 1);
            });

            const ranked = Array.from(counts.entries())
                .sort((a, b) => b[1] - a[1] || a[0].localeCompare(b[0]))
                .map(([value, count]) => ({
                    value,
                    count,
                    label: `${value} (${resolutionAspectLabel(value)}) • ${count} kijelző`
                }));

            return ranked;
        }

        // Load group display resolutions and populate the selector
        function loadGroupResolutions() {
            fetch(`../../api/get_group_kiosks.php?group_id=${groupId}`)
                .then(response => response.json())
                .then(data => {
                    const selector = document.getElementById('resolutionSelector');
                    if (!selector) {
                        return;
                    }

                    selector.innerHTML = '';
                    groupResolutionChoices = [];
                    groupDefaultResolution = '1920x1080';

                    if (data.success && data.kiosks && data.kiosks.length > 0) {
                        groupResolutionChoices = buildGroupResolutionChoices(data.kiosks);
                        if (groupResolutionChoices.length > 0) {
                            groupDefaultResolution = groupResolutionChoices[0].value;
                            groupResolutionChoices.forEach((entry) => {
                                const option = document.createElement('option');
                                option.value = entry.value;
                                option.textContent = entry.label;
                                selector.appendChild(option);
                            });

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
                    } else {
                        const fallbackResolutions = [
                            { value: '1920x1080', label: '1920x1080 (16:9 Full HD)' },
                            { value: '1366x768', label: '1366x768 (16:9 WXGA)' },
                            { value: '1280x720', label: '1280x720 (16:9 HD)' },
                            { value: '1024x768', label: '1024x768 (4:3 XGA)' }
                        ];
                        fallbackResolutions.forEach((res) => {
                            const option = document.createElement('option');
                            option.value = res.value;
                            option.textContent = res.label;
                            selector.appendChild(option);
                        });
                    }

                    if (!selector.querySelector(`option[value="${groupDefaultResolution}"]`)) {
                        groupDefaultResolution = selector.querySelector('option:not([disabled])')?.value || '1920x1080';
                    }
                    selector.value = groupDefaultResolution;

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

        function syncLoopPreviewIframeScale() {
            const selector = document.getElementById('resolutionSelector');
            const previewScreen = document.getElementById('previewScreen');
            const iframe = document.getElementById('previewIframe');
            if (!selector || !previewScreen || !iframe) {
                return;
            }

            const resolution = String(selector.value || groupDefaultResolution || '1920x1080');
            const [widthRaw, heightRaw] = resolution.split('x').map((v) => parseInt(v, 10));
            const deviceWidth = Math.max(320, widthRaw || 1920);
            const deviceHeight = Math.max(180, heightRaw || 1080);

            const availableWidth = Math.max(120, previewScreen.clientWidth);
            const availableHeight = Math.max(80, previewScreen.clientHeight);
            const scale = Math.max(0.05, Math.min(availableWidth / deviceWidth, availableHeight / deviceHeight));

            iframe.style.position = 'absolute';
            iframe.style.left = '50%';
            iframe.style.top = '50%';
            iframe.style.width = `${deviceWidth}px`;
            iframe.style.height = `${deviceHeight}px`;
            iframe.style.transform = `translate(-50%, -50%) scale(${scale})`;
            iframe.style.transformOrigin = 'center center';
        }
        
        // Update preview resolution
        function updatePreviewResolution() {
            const selector = document.getElementById('resolutionSelector');
            const resolution = selector.value;
            const [width, height] = resolution.split('x').map(Number);
            
            if (width && height) {
                fitPreviewScreen(width, height);
                requestAnimationFrame(syncLoopPreviewIframeScale);
            }
        }

        window.addEventListener('resize', () => {
            updatePreviewResolution();
        });
        
        reorderPrimaryPanels();
        bindModuleCatalogInteractions();

        // Load resolutions on page load
        loadGroupResolutions();
