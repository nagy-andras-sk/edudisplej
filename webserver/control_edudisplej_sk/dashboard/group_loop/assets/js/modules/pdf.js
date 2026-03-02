/**
 * PDF Module
 * Handles PDF upload and animated live preview via iframe
 */

const GroupLoopPdfModule = (() => {
    'use strict';

    const parseIntSafe = (value, fallback) => {
        const parsed = parseInt(value, 10);
        return Number.isFinite(parsed) ? parsed : fallback;
    };

    const normalizeSections = (source) => {
        let data = source;
        if (typeof data === 'string') {
            try {
                data = JSON.parse(data);
            } catch (_) {
                data = [];
            }
        }

        if (!Array.isArray(data)) {
            return [];
        }

        return data
            .map((entry) => {
                const startRaw = parseIntSafe(entry?.startPercent, 0);
                const endRaw = parseIntSafe(entry?.endPercent, 100);
                const pauseRaw = parseIntSafe(entry?.pauseMs, 2000);
                const horizontalRaw = parseIntSafe(entry?.horizontalPercent, 0);

                const startPercent = Math.max(0, Math.min(100, Math.min(startRaw, endRaw)));
                const endPercent = Math.max(0, Math.min(100, Math.max(startRaw, endRaw)));

                return {
                    startPercent,
                    endPercent,
                    pauseMs: Math.max(0, pauseRaw),
                    horizontalPercent: Math.max(0, Math.min(100, horizontalRaw))
                };
            })
            .filter((entry) => entry.endPercent >= entry.startPercent)
            .sort((left, right) => left.startPercent - right.startPercent)
            .slice(0, 24);
    };

    const readSectionsFromHiddenInput = () => {
        const hidden = document.getElementById('pdf-sections-json');
        return normalizeSections(hidden?.value || '[]');
    };

    const writeSectionsToHiddenInput = (sections) => {
        const normalized = normalizeSections(sections);
        const hidden = document.getElementById('pdf-sections-json');
        if (hidden) {
            hidden.value = JSON.stringify(normalized);
        }
        return normalized;
    };

    const syncHorizontalInputs = (sourceId) => {
        const range = document.getElementById('pdf-horizontalStartPercent');
        const number = document.getElementById('pdf-horizontalStartPercentNumber');
        if (!range || !number) {
            return;
        }

        const fromRange = parseIntSafe(range.value, 0);
        const fromNumber = parseIntSafe(number.value, 0);
        const nextValue = sourceId === 'pdf-horizontalStartPercent'
            ? fromRange
            : fromNumber;
        const clamped = Math.max(0, Math.min(100, nextValue));

        range.value = String(clamped);
        number.value = String(clamped);
    };

    const renderSectionPlanner = () => {
        const timeline = document.getElementById('pdf-section-timeline');
        const list = document.getElementById('pdf-sections-list');
        if (!timeline || !list) {
            return;
        }

        const sections = readSectionsFromHiddenInput();
        timeline.innerHTML = '';
        list.innerHTML = '';

        if (sections.length === 0) {
            const empty = document.createElement('div');
            empty.style.cssText = 'font-size:12px; color:#8a97a6; padding:8px;';
            empty.textContent = 'Nincs szakasz megadva. Add hozzá az elsőt.';
            list.appendChild(empty);
            return;
        }

        sections.forEach((section, index) => {
            const block = document.createElement('div');
            const top = section.startPercent;
            const height = Math.max(2, section.endPercent - section.startPercent);
            block.style.cssText = [
                'position:absolute',
                'left:12px',
                'right:12px',
                `top:${top}%`,
                `height:${height}%`,
                'background:rgba(30,64,175,0.22)',
                'border:1px solid rgba(30,64,175,0.65)',
                'border-radius:6px',
                'display:flex',
                'align-items:center',
                'justify-content:center',
                'font-size:11px',
                'font-weight:600',
                'color:#1e3a8a'
            ].join(';');
            block.textContent = `${index + 1}. ${section.startPercent}-${section.endPercent}%`;
            timeline.appendChild(block);

            const row = document.createElement('div');
            row.style.cssText = 'display:grid; grid-template-columns:1fr auto auto; gap:8px; align-items:center; border:1px solid #d6dde8; border-radius:6px; padding:6px 8px; background:#f8fafc;';
            row.innerHTML = `
                <div style="font-size:12px; color:#334155;">#${index + 1}: ${section.startPercent}% → ${section.endPercent}% · ${section.pauseMs} ms · X ${section.horizontalPercent}%</div>
                <button type="button" data-action="edit" data-index="${index}" style="padding:4px 8px; border:1px solid #2563eb; background:#fff; color:#2563eb; border-radius:4px; cursor:pointer; font-size:12px;">Szerkeszt</button>
                <button type="button" data-action="remove" data-index="${index}" style="padding:4px 8px; border:1px solid #dc2626; background:#fff; color:#dc2626; border-radius:4px; cursor:pointer; font-size:12px;">Törlés</button>
            `;
            list.appendChild(row);
        });
    };

    const bindSectionPlannerEvents = () => {
        const addBtn = document.getElementById('pdf-section-add');
        const list = document.getElementById('pdf-sections-list');
        if (!addBtn || !list) {
            return;
        }

        addBtn.addEventListener('click', () => {
            const startInput = document.getElementById('pdf-section-start');
            const endInput = document.getElementById('pdf-section-end');
            const pauseInput = document.getElementById('pdf-section-pause');
            const horizontalInput = document.getElementById('pdf-section-horizontal');

            const startPercent = Math.max(0, Math.min(100, parseIntSafe(startInput?.value, 0)));
            const endPercent = Math.max(0, Math.min(100, parseIntSafe(endInput?.value, 100)));
            const pauseMs = Math.max(0, parseIntSafe(pauseInput?.value, 2000));
            const horizontalPercent = Math.max(0, Math.min(100, parseIntSafe(horizontalInput?.value, 0)));

            const nextSections = readSectionsFromHiddenInput();
            nextSections.push({ startPercent, endPercent, pauseMs, horizontalPercent });
            writeSectionsToHiddenInput(nextSections);
            renderSectionPlanner();
            updateLivePreview();
        });

        list.addEventListener('click', (event) => {
            const target = event.target;
            if (!(target instanceof HTMLElement)) {
                return;
            }

            const action = target.getAttribute('data-action');
            const index = parseIntSafe(target.getAttribute('data-index'), -1);
            if (index < 0) {
                return;
            }

            const sections = readSectionsFromHiddenInput();
            const section = sections[index];
            if (!section) {
                return;
            }

            if (action === 'remove') {
                sections.splice(index, 1);
                writeSectionsToHiddenInput(sections);
                renderSectionPlanner();
                updateLivePreview();
                return;
            }

            if (action === 'edit') {
                const startInput = document.getElementById('pdf-section-start');
                const endInput = document.getElementById('pdf-section-end');
                const pauseInput = document.getElementById('pdf-section-pause');
                const horizontalInput = document.getElementById('pdf-section-horizontal');
                if (startInput) startInput.value = String(section.startPercent);
                if (endInput) endInput.value = String(section.endPercent);
                if (pauseInput) pauseInput.value = String(section.pauseMs);
                if (horizontalInput) horizontalInput.value = String(section.horizontalPercent);
                sections.splice(index, 1);
                writeSectionsToHiddenInput(sections);
                renderSectionPlanner();
                updateLivePreview();
            }
        });
    };

    const createPdfDataPreviewKey = (pdfDataBase64) => {
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
    };

    const readCurrentSettingsFromInputs = () => {
        const source = window.pdfModuleSettings || {};
        const horizontalStartPercent = Math.max(0, Math.min(100, parseIntSafe(document.getElementById('pdf-horizontalStartPercentNumber')?.value, parseIntSafe(source.horizontalStartPercent, 0))));
        const sectionsJson = document.getElementById('pdf-sections-json')?.value || source.autoScrollSectionsJson || '[]';
        return {
            ...source,
            pdfDataBase64: source.pdfDataBase64 || '',
            pdfAssetUrl: source.pdfAssetUrl || '',
            pdfAssetId: source.pdfAssetId || '',
            zoomLevel: Math.max(50, Math.min(250, parseIntSafe(document.getElementById('pdf-zoomLevel')?.value, parseIntSafe(source.zoomLevel, 100)))),
            horizontalStartPercent,
            autoScrollEnabled: document.getElementById('pdf-autoScrollEnabled')?.checked === true,
            autoScrollSpeedPxPerSec: Math.max(5, Math.min(300, parseIntSafe(document.getElementById('pdf-scrollSpeed')?.value, parseIntSafe(source.autoScrollSpeedPxPerSec, 30)))),
            autoScrollStartPauseMs: Math.max(0, parseIntSafe(document.getElementById('pdf-startPause')?.value, parseIntSafe(source.autoScrollStartPauseMs, 2000))),
            autoScrollEndPauseMs: Math.max(0, parseIntSafe(document.getElementById('pdf-endPause')?.value, parseIntSafe(source.autoScrollEndPauseMs, 2000))),
            pauseAtPercent: Math.max(-1, Math.min(100, parseIntSafe(document.getElementById('pdf-pauseAtPercent')?.value, parseIntSafe(source.pauseAtPercent, -1)))),
            pauseDurationMs: Math.max(0, parseIntSafe(document.getElementById('pdf-pauseDurationMs')?.value, parseIntSafe(source.pauseDurationMs, 2000))),
            autoScrollSectionsJson: JSON.stringify(normalizeSections(sectionsJson))
        };
    };

    const syncSettingsFromInputs = () => {
        window.pdfModuleSettings = readCurrentSettingsFromInputs();
    };

    const updateUploadStatus = () => {
        const uploadArea = document.getElementById('pdf-upload-area');
        const base64 = window.pdfModuleSettings?.pdfDataBase64 || '';
        const assetUrl = window.pdfModuleSettings?.pdfAssetUrl || '';
        if (!uploadArea) {
            return;
        }

        const oldMessage = uploadArea.querySelector('.pdf-upload-status');
        if (oldMessage) {
            oldMessage.remove();
        }

        if (!base64 && !assetUrl) {
            return;
        }

        const sizeKB = base64 ? Math.round(base64.length / 1024) : 0;
        const status = document.createElement('div');
        status.className = 'pdf-upload-status';
        status.style.cssText = 'color: #28a745; margin-top: 8px; font-size: 13px;';
        status.textContent = sizeKB > 0
            ? `✓ PDF betöltve (${sizeKB} KB)`
            : '✓ PDF betöltve';
        uploadArea.appendChild(status);
    };

    const buildPreviewUrl = (settings) => {
        const params = new URLSearchParams();
        params.append('zoomLevel', String(settings.zoomLevel || 100));
        params.append('horizontalStartPercent', String(settings.horizontalStartPercent || 0));
        params.append('autoScrollEnabled', settings.autoScrollEnabled ? 'true' : 'false');
        params.append('autoScrollSpeedPxPerSec', String(settings.autoScrollSpeedPxPerSec || 30));
        params.append('autoScrollStartPauseMs', String(settings.autoScrollStartPauseMs || 2000));
        params.append('autoScrollEndPauseMs', String(settings.autoScrollEndPauseMs || 2000));
        params.append('pauseAtPercent', String(settings.pauseAtPercent ?? -1));
        params.append('pauseDurationMs', String(settings.pauseDurationMs || 2000));
        params.append('autoScrollSectionsJson', String(settings.autoScrollSectionsJson || '[]'));

        if (settings.pdfAssetUrl) {
            params.append('pdfAssetUrl', String(settings.pdfAssetUrl));
        } else if (settings.pdfDataBase64) {
            const dataKey = createPdfDataPreviewKey(settings.pdfDataBase64);
            settings.pdfDataKey = dataKey;
            params.append('pdfDataKey', dataKey);
        }

        return `../../modules/pdf/m_pdf.html?${params.toString()}&preview=${Date.now()}`;
    };

    const updateLivePreview = () => {
        syncSettingsFromInputs();
        const settings = window.pdfModuleSettings || {};
        const iframe = document.getElementById('pdf-live-preview-iframe');
        const emptyState = document.getElementById('pdf-preview-empty');

        if (!iframe || !emptyState) {
            return;
        }

        if (!settings.pdfDataBase64 && !settings.pdfAssetUrl) {
            iframe.removeAttribute('src');
            emptyState.style.display = 'block';
            return;
        }

        emptyState.style.display = 'none';
        iframe.src = buildPreviewUrl(settings);
    };

    const bindInputEvents = () => {
        const controls = [
            'pdf-zoomLevel',
            'pdf-horizontalStartPercent',
            'pdf-horizontalStartPercentNumber',
            'pdf-autoScrollEnabled',
            'pdf-scrollSpeed',
            'pdf-startPause',
            'pdf-endPause',
            'pdf-pauseAtPercent',
            'pdf-pauseDurationMs'
        ];

        controls.forEach((id) => {
            const element = document.getElementById(id);
            if (!element) {
                return;
            }

            const eventName = element.type === 'checkbox' ? 'change' : 'input';
            element.addEventListener(eventName, () => {
                if (id === 'pdf-horizontalStartPercent' || id === 'pdf-horizontalStartPercentNumber') {
                    syncHorizontalInputs(id);
                }
                if (id === 'pdf-autoScrollEnabled') {
                    const wrapper = document.querySelector('.pdf-scroll-settings');
                    if (wrapper) {
                        wrapper.style.display = element.checked ? 'grid' : 'none';
                    }
                }
                updateLivePreview();
            });
        });
    };

    const initializeUI = () => {
        const uploadArea = document.getElementById('pdf-upload-area');
        const fileInput = document.getElementById('pdf-file-input');

        if (!window.pdfModuleSettings) {
            window.pdfModuleSettings = {};
        }
        if (typeof window.pdfModuleSettings.autoScrollSectionsJson !== 'string') {
            window.pdfModuleSettings.autoScrollSectionsJson = '[]';
        }
        writeSectionsToHiddenInput(window.pdfModuleSettings.autoScrollSectionsJson || '[]');
        syncHorizontalInputs('pdf-horizontalStartPercentNumber');

        if (uploadArea && fileInput) {
            uploadArea.addEventListener('click', () => fileInput.click());
            uploadArea.addEventListener('dragover', (event) => {
                event.preventDefault();
                uploadArea.style.backgroundColor = '#e3f2fd';
            });
            uploadArea.addEventListener('dragleave', () => {
                uploadArea.style.backgroundColor = '#f8f9fa';
            });
            uploadArea.addEventListener('drop', handlePdfDrop);
            fileInput.addEventListener('change', handlePdfFileSelect);
        }

        bindSectionPlannerEvents();
        bindInputEvents();
        renderSectionPlanner();
        syncSettingsFromInputs();
        updateUploadStatus();
        updateLivePreview();
    };

    const handlePdfDrop = (e) => {
        e.preventDefault();
        e.stopPropagation();
        const files = e.dataTransfer.files;
        if (files.length > 0) {
            handlePdfFile(files[0]);
        }
    };

    const handlePdfFileSelect = (e) => {
        const files = e.target.files;
        if (files.length > 0) {
            handlePdfFile(files[0]);
        }
    };

    const uploadPdfAsset = async (file) => {
        const groupId = parseInt(window.GroupLoopBootstrap?.groupId || 0, 10);
        if (!groupId) {
            throw new Error('Hiányzó group_id');
        }

        const formData = new FormData();
        formData.append('group_id', String(groupId));
        formData.append('module_key', 'pdf');
        formData.append('asset_kind', 'pdf');
        formData.append('asset', file, file.name || 'document.pdf');

        const response = await fetch('../../api/group_loop/module_asset_upload.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();

        if (!data || !data.success || !data.asset_url) {
            throw new Error(data?.message || 'Feltöltési hiba');
        }

        return data;
    };

    const handlePdfFile = async (file) => {
        if (file.type !== 'application/pdf') {
            alert('Csak PDF formátum támogatott');
            return;
        }

        if (file.size > 50 * 1024 * 1024) {
            alert('A fájl túl nagy (max. 50 MB)');
            return;
        }

        try {
            const uploadResult = await uploadPdfAsset(file);
            window.pdfModuleSettings = window.pdfModuleSettings || {};
            window.pdfModuleSettings.pdfAssetUrl = String(uploadResult.asset_url || '');
            window.pdfModuleSettings.pdfAssetId = uploadResult.asset_id || '';
            window.pdfModuleSettings.pdfDataBase64 = '';
            updateUploadStatus();
            updateLivePreview();
        } catch (error) {
            alert(`PDF feltöltési hiba: ${error?.message || 'ismeretlen hiba'}`);
        }
    };

    return {
        init: initializeUI,
        handlePdfDrop,
        handlePdfFileSelect,
        handlePdfFile
    };
})();
