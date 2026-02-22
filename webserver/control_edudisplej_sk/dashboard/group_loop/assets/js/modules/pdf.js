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
        return {
            ...source,
            pdfDataBase64: source.pdfDataBase64 || '',
            pdfAssetUrl: source.pdfAssetUrl || '',
            pdfAssetId: source.pdfAssetId || '',
            zoomLevel: Math.max(50, Math.min(250, parseIntSafe(document.getElementById('pdf-zoomLevel')?.value, parseIntSafe(source.zoomLevel, 100)))),
            autoScrollEnabled: document.getElementById('pdf-autoScrollEnabled')?.checked === true,
            autoScrollSpeedPxPerSec: Math.max(5, Math.min(300, parseIntSafe(document.getElementById('pdf-scrollSpeed')?.value, parseIntSafe(source.autoScrollSpeedPxPerSec, 30)))),
            autoScrollStartPauseMs: Math.max(0, parseIntSafe(document.getElementById('pdf-startPause')?.value, parseIntSafe(source.autoScrollStartPauseMs, 2000))),
            autoScrollEndPauseMs: Math.max(0, parseIntSafe(document.getElementById('pdf-endPause')?.value, parseIntSafe(source.autoScrollEndPauseMs, 2000))),
            pauseAtPercent: Math.max(-1, Math.min(100, parseIntSafe(document.getElementById('pdf-pauseAtPercent')?.value, parseIntSafe(source.pauseAtPercent, -1)))),
            pauseDurationMs: Math.max(0, parseIntSafe(document.getElementById('pdf-pauseDurationMs')?.value, parseIntSafe(source.pauseDurationMs, 2000)))
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
        params.append('autoScrollEnabled', settings.autoScrollEnabled ? 'true' : 'false');
        params.append('autoScrollSpeedPxPerSec', String(settings.autoScrollSpeedPxPerSec || 30));
        params.append('autoScrollStartPauseMs', String(settings.autoScrollStartPauseMs || 2000));
        params.append('autoScrollEndPauseMs', String(settings.autoScrollEndPauseMs || 2000));
        params.append('pauseAtPercent', String(settings.pauseAtPercent ?? -1));
        params.append('pauseDurationMs', String(settings.pauseDurationMs || 2000));

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

        bindInputEvents();
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
