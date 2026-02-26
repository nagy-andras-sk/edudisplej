const GroupLoopVideoModule = (() => {
    'use strict';

    const MAX_LIBRARY_ITEMS = 200;
    let globalDropGuardsCleanup = null;

    const parseIntSafe = (value, fallback) => {
        const parsed = parseInt(value, 10);
        return Number.isFinite(parsed) ? parsed : fallback;
    };

    const formatBytes = (bytes) => {
        const value = Math.max(0, parseInt(bytes || 0, 10));
        if (value < 1024) {
            return `${value} B`;
        }
        const units = ['KB', 'MB', 'GB'];
        let size = value / 1024;
        let unitIndex = 0;
        while (size >= 1024 && unitIndex < units.length - 1) {
            size /= 1024;
            unitIndex += 1;
        }
        return `${size.toFixed(1)} ${units[unitIndex]}`;
    };

    const getDurationFromFile = (file) => {
        return new Promise((resolve, reject) => {
            const video = document.createElement('video');
            video.preload = 'metadata';
            video.muted = true;
            video.playsInline = true;

            const objectUrl = URL.createObjectURL(file);
            const cleanup = () => {
                URL.revokeObjectURL(objectUrl);
                video.removeAttribute('src');
            };

            video.onloadedmetadata = () => {
                const duration = Math.max(1, Math.ceil(Number(video.duration) || 0));
                cleanup();
                resolve(duration);
            };
            video.onerror = () => {
                cleanup();
                reject(new Error('A videó metaadat nem olvasható'));
            };

            video.src = objectUrl;
        });
    };

    const getDurationFromUrl = (url) => {
        return new Promise((resolve) => {
            const video = document.createElement('video');
            video.preload = 'metadata';
            video.muted = true;
            video.playsInline = true;

            const done = (duration) => {
                video.removeAttribute('src');
                resolve(duration);
            };

            video.onloadedmetadata = () => {
                const duration = Math.max(1, Math.ceil(Number(video.duration) || 0));
                done(duration);
            };

            video.onerror = () => done(null);
            video.src = String(url || '');
        });
    };

    const isFileDragEvent = (event) => {
        const types = event?.dataTransfer?.types;
        if (!types) {
            return false;
        }
        return Array.from(types).includes('Files');
    };

    const setupGlobalDropGuards = (uploadArea) => {
        if (typeof globalDropGuardsCleanup === 'function') {
            globalDropGuardsCleanup();
            globalDropGuardsCleanup = null;
        }

        const preventWindowFileDrop = (event) => {
            if (!uploadArea || !uploadArea.isConnected) {
                if (typeof globalDropGuardsCleanup === 'function') {
                    globalDropGuardsCleanup();
                    globalDropGuardsCleanup = null;
                }
                return;
            }

            if (!isFileDragEvent(event)) {
                return;
            }

            if (uploadArea.contains(event.target)) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
        };

        window.addEventListener('dragover', preventWindowFileDrop);
        window.addEventListener('drop', preventWindowFileDrop);

        globalDropGuardsCleanup = () => {
            window.removeEventListener('dragover', preventWindowFileDrop);
            window.removeEventListener('drop', preventWindowFileDrop);
        };
    };

    const updateDurationBadge = () => {
        const badge = document.getElementById('video-duration-badge');
        const durationSec = parseIntSafe(window.videoModuleSettings?.videoDurationSec, 0);
        if (!badge) {
            return;
        }

        if (durationSec > 0) {
            badge.textContent = `Loop időtartam: ${durationSec} s (fix, videó hossza)`;
            badge.style.color = '#0f5132';
        } else {
            badge.textContent = 'Loop időtartam: még nincs videó';
            badge.style.color = '#6b7280';
        }
    };

    const updatePreview = () => {
        const iframe = document.getElementById('video-live-preview-iframe');
        const empty = document.getElementById('video-preview-empty');
        if (!iframe || !empty) {
            return;
        }

        const settings = window.videoModuleSettings || {};
        const assetUrl = String(settings.videoAssetUrl || '').trim();
        if (!assetUrl) {
            iframe.removeAttribute('src');
            empty.style.display = 'block';
            updateDurationBadge();
            return;
        }

        const params = new URLSearchParams();
        params.append('videoAssetUrl', assetUrl);
        params.append('muted', settings.muted === false ? 'false' : 'true');
        params.append('fitMode', settings.fitMode || 'contain');
        params.append('bgColor', settings.bgColor || '#000000');
        params.append('preview', String(Date.now()));

        iframe.src = `../../modules/video/m_video.html?${params.toString()}`;
        empty.style.display = 'none';
        updateDurationBadge();
    };

    const uploadVideoAsset = async (file) => {
        const groupId = parseInt(window.GroupLoopBootstrap?.groupId || 0, 10);
        if (!groupId) {
            throw new Error('Hiányzó group_id');
        }

        const formData = new FormData();
        formData.append('group_id', String(groupId));
        formData.append('module_key', 'video');
        formData.append('asset_kind', 'video');
        formData.append('asset', file, file.name || 'video.mp4');

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

    const handleVideoFile = async (file) => {
        const status = document.getElementById('video-upload-status');
        const ext = (String(file?.name || '').split('.').pop() || '').toLowerCase();
        if (ext !== 'mp4') {
            alert('Csak MP4 fájl tölthető fel.');
            return;
        }

        if ((file.size || 0) > 80 * 1024 * 1024) {
            alert('A videó túl nagy (max 80 MB).');
            return;
        }

        try {
            if (status) status.textContent = 'Metaadat olvasás...';
            const durationSec = await getDurationFromFile(file);
            if (status) status.textContent = 'Feltöltés...';

            const uploaded = await uploadVideoAsset(file);
            window.videoModuleSettings = {
                ...(window.videoModuleSettings || {}),
                videoAssetUrl: String(uploaded.asset_url || ''),
                videoAssetId: uploaded.asset_id || '',
                videoDurationSec: durationSec
            };

            if (status) {
                status.textContent = `Feltöltve: ${file.name} (${formatBytes(file.size || 0)}, ${durationSec}s)`;
            }
            updatePreview();
            renderLibrary();
        } catch (error) {
            if (status) {
                status.textContent = `Hiba: ${error?.message || 'ismeretlen hiba'}`;
            }
        }
    };

    let libraryItems = [];

    const renderLibrary = () => {
        const list = document.getElementById('video-library-list');
        const status = document.getElementById('video-library-status');
        if (!list || !status) {
            return;
        }

        if (!libraryItems.length) {
            list.innerHTML = '';
            status.textContent = 'Nincs korábban feltöltött videó.';
            return;
        }

        status.textContent = `Talált elemek: ${libraryItems.length}`;
        list.innerHTML = libraryItems.map((item) => {
            const id = parseInt(item.asset_id || 0, 10);
            const name = String(item.original_name || `video-${id}`)
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');
            const url = String(item.asset_url || '').replace(/"/g, '&quot;');
            return `
                <label style="display:flex; align-items:center; gap:8px; border:1px solid #d9e0ea; border-radius:6px; padding:8px; background:#fff; cursor:pointer;">
                    <input type="radio" name="video-library-choice" data-video-library-url="${url}" data-video-library-id="${id}" style="margin:0;">
                    <div style="min-width:0; flex:1;">
                        <div style="font-size:12px; color:#111827; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${name}</div>
                        <div style="font-size:11px; color:#6b7280;">${formatBytes(item.file_size || 0)}</div>
                    </div>
                </label>
            `;
        }).join('');
    };

    const loadLibrary = async () => {
        const status = document.getElementById('video-library-status');
        if (status) {
            status.textContent = 'Betöltés...';
        }

        try {
            const response = await fetch(`../../api/group_loop/module_asset_library.php?module_key=video&asset_kind=video&limit=${MAX_LIBRARY_ITEMS}`, {
                credentials: 'same-origin'
            });
            const data = await response.json();
            if (!response.ok || !data?.success || !Array.isArray(data.assets)) {
                throw new Error(data?.message || `HTTP ${response.status}`);
            }

            libraryItems = data.assets
                .map((item) => ({
                    asset_id: parseInt(item.asset_id || 0, 10),
                    asset_url: String(item.asset_url || '').trim(),
                    original_name: String(item.original_name || '').trim(),
                    file_size: parseInt(item.file_size || 0, 10)
                }))
                .filter((item) => item.asset_id > 0 && item.asset_url !== '');

            renderLibrary();
        } catch (error) {
            libraryItems = [];
            renderLibrary();
            if (status) {
                status.textContent = `Library betöltési hiba: ${error?.message || 'ismeretlen hiba'}`;
            }
        }
    };

    const importFromLibrary = async () => {
        const selected = document.querySelector('input[name="video-library-choice"]:checked');
        if (!selected) {
            alert('Válassz ki egy videót a listából.');
            return;
        }

        const url = String(selected.getAttribute('data-video-library-url') || '').trim();
        const id = parseInt(selected.getAttribute('data-video-library-id') || '0', 10);
        if (!url) {
            alert('Érvénytelen videó URL.');
            return;
        }

        const status = document.getElementById('video-upload-status');
        if (status) {
            status.textContent = 'Videó metaadat lekérés...';
        }

        const durationSec = await getDurationFromUrl(url);
        window.videoModuleSettings = {
            ...(window.videoModuleSettings || {}),
            videoAssetUrl: url,
            videoAssetId: id > 0 ? id : '',
            videoDurationSec: durationSec || parseIntSafe(window.videoModuleSettings?.videoDurationSec, 10)
        };

        if (status) {
            status.textContent = durationSec
                ? `Library videó kiválasztva (${durationSec}s)`
                : 'Library videó kiválasztva';
        }

        updatePreview();
    };

    const bindEvents = () => {
        const uploadArea = document.getElementById('video-upload-area');
        const fileInput = document.getElementById('video-file-input');
        const fitMode = document.getElementById('video-fit-mode');
        const muted = document.getElementById('video-muted');
        const bgColor = document.getElementById('video-bg-color');
        const refreshLibrary = document.getElementById('video-library-refresh');
        const importLibrary = document.getElementById('video-library-import');

        if (uploadArea && fileInput) {
            setupGlobalDropGuards(uploadArea);

            const openPicker = () => {
                fileInput.value = '';
                try {
                    if (typeof fileInput.showPicker === 'function') {
                        fileInput.showPicker();
                    } else {
                        fileInput.click();
                    }
                } catch (_) {
                    fileInput.click();
                }
            };

            uploadArea.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                openPicker();
            });

            uploadArea.addEventListener('keydown', (event) => {
                if (event.key !== 'Enter' && event.key !== ' ') {
                    return;
                }
                event.preventDefault();
                event.stopPropagation();
                openPicker();
            });

            uploadArea.setAttribute('tabindex', '0');
            uploadArea.addEventListener('dragenter', (event) => {
                if (!isFileDragEvent(event)) {
                    return;
                }
                event.preventDefault();
                event.stopPropagation();
                uploadArea.style.backgroundColor = '#e6f0ff';
            });
            uploadArea.addEventListener('dragover', (event) => {
                if (!isFileDragEvent(event)) {
                    return;
                }
                event.preventDefault();
                event.stopPropagation();
                uploadArea.style.backgroundColor = '#e6f0ff';
            });
            uploadArea.addEventListener('dragleave', (event) => {
                if (!isFileDragEvent(event)) {
                    return;
                }
                event.preventDefault();
                event.stopPropagation();
                uploadArea.style.backgroundColor = '#f8f9fa';
            });
            uploadArea.addEventListener('drop', (event) => {
                if (!isFileDragEvent(event)) {
                    return;
                }
                event.preventDefault();
                event.stopPropagation();
                uploadArea.style.backgroundColor = '#f8f9fa';
                const files = event.dataTransfer?.files;
                if (files && files[0]) {
                    handleVideoFile(files[0]);
                }
            });
            fileInput.addEventListener('change', (event) => {
                const files = event.target?.files;
                if (files && files[0]) {
                    handleVideoFile(files[0]);
                }
            });
        }

        const syncPreview = () => {
            window.videoModuleSettings = {
                ...(window.videoModuleSettings || {}),
                fitMode: fitMode?.value || 'contain',
                muted: muted?.checked !== false,
                bgColor: bgColor?.value || '#000000'
            };
            updatePreview();
        };

        fitMode?.addEventListener('change', syncPreview);
        muted?.addEventListener('change', syncPreview);
        bgColor?.addEventListener('input', syncPreview);
        refreshLibrary?.addEventListener('click', loadLibrary);
        importLibrary?.addEventListener('click', importFromLibrary);
    };

    const init = () => {
        window.videoModuleSettings = {
            videoAssetUrl: '',
            videoAssetId: '',
            videoDurationSec: 10,
            muted: true,
            fitMode: 'contain',
            bgColor: '#000000',
            ...(window.videoModuleSettings || {})
        };

        const fitMode = document.getElementById('video-fit-mode');
        const muted = document.getElementById('video-muted');
        const bgColor = document.getElementById('video-bg-color');

        if (fitMode) fitMode.value = String(window.videoModuleSettings.fitMode || 'contain');
        if (muted) muted.checked = window.videoModuleSettings.muted !== false;
        if (bgColor) bgColor.value = String(window.videoModuleSettings.bgColor || '#000000');

        bindEvents();
        loadLibrary();
        updatePreview();
    };

    return {
        init
    };
})();

if (typeof window !== 'undefined') {
    window.GroupLoopVideoModule = GroupLoopVideoModule;
}
