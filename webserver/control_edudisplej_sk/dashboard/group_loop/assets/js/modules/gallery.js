const GroupLoopGalleryModule = (() => {
    'use strict';

    const MAX_IMAGES = 10;
    const MAX_LIBRARY_ITEMS = 200;
    let globalDropGuardsCleanup = null;
    let libraryItems = [];

    const isFileDragEvent = (event) => {
        const types = Array.from(event?.dataTransfer?.types || []);
        return types.includes('Files');
    };

    const setupGlobalDropGuards = (uploadArea) => {
        if (typeof globalDropGuardsCleanup === 'function') {
            globalDropGuardsCleanup();
            globalDropGuardsCleanup = null;
        }

        if (!uploadArea) {
            return;
        }

        const preventWindowFileDrop = (event) => {
            if (!document.body.contains(uploadArea)) {
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

    const parseImageUrls = (raw) => {
        try {
            const parsed = JSON.parse(String(raw || '[]'));
            if (!Array.isArray(parsed)) {
                return [];
            }
            return parsed.map((item) => String(item || '').trim()).filter(Boolean).slice(0, MAX_IMAGES);
        } catch (_) {
            return [];
        }
    };

    const writeImageUrls = (urls) => {
        const normalized = Array.isArray(urls)
            ? urls.map((item) => String(item || '').trim()).filter(Boolean).slice(0, MAX_IMAGES)
            : [];

        const hidden = document.getElementById('gallery-image-urls-json');
        if (hidden) {
            hidden.value = JSON.stringify(normalized);
        }

        window.galleryModuleSettings = {
            ...(window.galleryModuleSettings || {}),
            imageUrlsJson: JSON.stringify(normalized)
        };

        return normalized;
    };

    const getImageUrls = () => {
        const hidden = document.getElementById('gallery-image-urls-json');
        if (hidden) {
            return parseImageUrls(hidden.value);
        }
        return parseImageUrls(window.galleryModuleSettings?.imageUrlsJson || '[]');
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

    const renderLibrary = () => {
        const list = document.getElementById('gallery-library-list');
        const status = document.getElementById('gallery-library-status');
        if (!list || !status) {
            return;
        }

        if (!libraryItems.length) {
            list.innerHTML = '';
            status.textContent = 'Nincs korábban feltöltött kép.';
            return;
        }

        status.textContent = `Talált elemek: ${libraryItems.length}`;
        list.innerHTML = libraryItems.map((item) => {
            const id = parseInt(item.asset_id || 0, 10);
            const url = String(item.asset_url || '').replace(/"/g, '&quot;');
            const name = String(item.original_name || `asset-${id}`).replace(/</g, '&lt;').replace(/>/g, '&gt;');
            const sizeText = formatBytes(item.file_size || 0);
            return `
                <label style="display:flex; flex-direction:column; gap:4px; border:1px solid #d9e0ea; border-radius:6px; padding:6px; background:#fff; cursor:pointer;">
                    <input type="checkbox" data-library-asset-id="${id}" data-library-asset-url="${url}" style="margin:0;">
                    <img src="${url}" alt="${name}" loading="lazy" style="width:100%; height:60px; object-fit:cover; border-radius:4px; border:1px solid #e3e8ef;">
                    <span title="${name}" style="font-size:11px; color:#374151; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${name}</span>
                    <span style="font-size:10px; color:#667085;">${sizeText}</span>
                </label>
            `;
        }).join('');
    };

    const loadLibrary = async () => {
        const status = document.getElementById('gallery-library-status');
        if (status) {
            status.textContent = 'Betöltés...';
        }

        try {
            const response = await fetch(`../../api/group_loop/module_asset_library.php?module_key=image-gallery&asset_kind=image&limit=${MAX_LIBRARY_ITEMS}`, {
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

    const importSelectedFromLibrary = () => {
        const checked = Array.from(document.querySelectorAll('[data-library-asset-id]:checked'));
        if (!checked.length) {
            alert('Jelölj ki legalább egy képet az importhoz.');
            return;
        }

        const current = getImageUrls();
        const freeSlots = Math.max(0, MAX_IMAGES - current.length);
        if (freeSlots <= 0) {
            alert(`Maximum ${MAX_IMAGES} kép használható.`);
            return;
        }

        const selectedUrls = checked
            .map((el) => String(el.getAttribute('data-library-asset-url') || '').trim())
            .filter(Boolean)
            .slice(0, freeSlots);

        const merged = Array.from(new Set([...current, ...selectedUrls])).slice(0, MAX_IMAGES);
        writeImageUrls(merged);
        renderImageList();

        const status = document.getElementById('gallery-upload-status');
        if (status) {
            status.textContent = `Import kész. Galéria: ${merged.length}/${MAX_IMAGES} kép`;
        }

        checked.forEach((el) => {
            el.checked = false;
        });
    };

    const setUploadProgress = (percent, text) => {
        const wrap = document.getElementById('gallery-upload-progress-wrap');
        const bar = document.getElementById('gallery-upload-progress-bar');
        const label = document.getElementById('gallery-upload-progress-text');

        if (!wrap || !bar || !label) {
            return;
        }

        const safePercent = Math.max(0, Math.min(100, parseInt(percent || 0, 10)));
        wrap.setAttribute('data-keep-visible', '0');
        wrap.style.display = 'block';
        bar.style.width = `${safePercent}%`;
        label.textContent = text || `${safePercent}%`;
    };

    const hideUploadProgress = () => {
        const wrap = document.getElementById('gallery-upload-progress-wrap');
        const bar = document.getElementById('gallery-upload-progress-bar');
        const label = document.getElementById('gallery-upload-progress-text');

        if (!wrap || !bar || !label) {
            return;
        }

        if (wrap.getAttribute('data-keep-visible') === '1') {
            return;
        }

        wrap.style.display = 'none';
        bar.style.width = '0%';
        label.textContent = '0%';
    };

    const uploadImageAsset = (file, onProgress) => {
        const groupId = parseInt(window.GroupLoopBootstrap?.groupId || 0, 10);
        if (!groupId) {
            throw new Error('Hiányzó group_id');
        }

        const formData = new FormData();
        formData.append('group_id', String(groupId));
        formData.append('module_key', 'image-gallery');
        formData.append('asset_kind', 'image');
        formData.append('asset', file, file.name || 'image.jpg');

        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', '../../api/group_loop/module_asset_upload.php', true);
            xhr.responseType = 'json';

            xhr.upload.onprogress = (event) => {
                if (typeof onProgress !== 'function') {
                    return;
                }
                if (event.lengthComputable) {
                    const percent = Math.round((event.loaded / event.total) * 100);
                    onProgress(percent);
                } else {
                    onProgress(0);
                }
            };

            xhr.onload = () => {
                const data = xhr.response || (() => {
                    try {
                        return JSON.parse(xhr.responseText || '{}');
                    } catch (_) {
                        return null;
                    }
                })();

                if (xhr.status >= 200 && xhr.status < 300 && data && data.success && data.asset_url) {
                    resolve(data);
                    return;
                }

                reject(new Error(data?.message || `Feltöltési hiba (HTTP ${xhr.status})`));
            };

            xhr.onerror = () => reject(new Error('Hálózati hiba a feltöltés során'));
            xhr.send(formData);
        });
    };

    const updateLivePreview = () => {
        const iframe = document.getElementById('gallery-live-preview-iframe');
        const empty = document.getElementById('gallery-preview-empty');
        if (!iframe || !empty) {
            return;
        }

        const imageUrls = getImageUrls();

        const displayMode = document.getElementById('gallery-display-mode')?.value || (window.galleryModuleSettings?.displayMode || 'slideshow');
        const fitMode = document.getElementById('gallery-fit-mode')?.value || (window.galleryModuleSettings?.fitMode || 'cover');
        const slideIntervalSec = parseInt(document.getElementById('gallery-slide-interval')?.value, 10) || 5;
        const transitionMs = parseInt(document.getElementById('gallery-transition-ms')?.value, 10) || 450;
        const collageColumns = parseInt(document.getElementById('gallery-collage-columns')?.value, 10) || 3;
        const bgColor = document.getElementById('gallery-bg-color')?.value || '#000000';

        window.galleryModuleSettings = {
            ...(window.galleryModuleSettings || {}),
            imageUrlsJson: JSON.stringify(imageUrls),
            displayMode,
            fitMode,
            slideIntervalSec,
            transitionMs,
            collageColumns,
            bgColor
        };

        if (!imageUrls.length) {
            empty.style.display = 'block';
            iframe.removeAttribute('src');
            return;
        }

        empty.style.display = 'none';
        const params = new URLSearchParams();
        params.append('imageUrlsJson', JSON.stringify(imageUrls));
        params.append('displayMode', displayMode);
        params.append('fitMode', fitMode);
        params.append('slideIntervalSec', String(slideIntervalSec));
        params.append('transitionMs', String(transitionMs));
        params.append('collageColumns', String(collageColumns));
        params.append('bgColor', String(bgColor));
        params.append('preview', String(Date.now()));

        iframe.src = `../../modules/gallery/m_gallery.html?${params.toString()}`;
    };

    const renderImageList = () => {
        const list = document.getElementById('gallery-image-list');
        const status = document.getElementById('gallery-upload-status');
        if (!list || !status) {
            return;
        }

        const imageUrls = getImageUrls();
        status.textContent = `Feltöltve: ${imageUrls.length}/${MAX_IMAGES} kép`;

        if (!imageUrls.length) {
            list.innerHTML = '<div style="font-size:12px; color:#7b8794;">Még nincs feltöltött kép.</div>';
            updateLivePreview();
            return;
        }

        list.innerHTML = imageUrls.map((url, idx) => {
            const safeUrl = String(url).replace(/"/g, '&quot;');
            return `<div style="display:flex; align-items:center; gap:8px; border:1px solid #dde3eb; border-radius:6px; padding:6px 8px; background:#fff;">
                        <img src="${safeUrl}" data-gallery-thumb="${idx}" alt="img-${idx + 1}" style="width:54px; height:40px; object-fit:cover; border-radius:4px; border:1px solid #d0d7df;">
                        <span style="font-size:12px; color:#344054; flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${idx + 1}. kép</span>
                        <button type="button" data-gallery-remove="${idx}" style="padding:4px 7px; border:1px solid #c43b2f; color:#c43b2f; background:#fff; border-radius:4px; cursor:pointer;">Törlés</button>
                    </div>`;
        }).join('');

        list.querySelectorAll('[data-gallery-thumb]').forEach((imgEl) => {
            imgEl.addEventListener('error', () => {
                imgEl.style.display = 'none';
                const errorNote = document.createElement('span');
                errorNote.textContent = 'Kép betöltési hiba';
                errorNote.style.fontSize = '11px';
                errorNote.style.color = '#b42318';
                const parent = imgEl.parentElement;
                if (parent && !parent.querySelector('[data-gallery-thumb-error]')) {
                    errorNote.setAttribute('data-gallery-thumb-error', '1');
                    parent.insertBefore(errorNote, parent.firstChild);
                }
            });
        });

        list.querySelectorAll('[data-gallery-remove]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const removeIndex = parseInt(btn.getAttribute('data-gallery-remove') || '-1', 10);
                const current = getImageUrls();
                if (removeIndex >= 0 && removeIndex < current.length) {
                    current.splice(removeIndex, 1);
                    writeImageUrls(current);
                    renderImageList();
                }
            });
        });

        updateLivePreview();
    };

    const handleFiles = async (files) => {
        const status = document.getElementById('gallery-upload-status');
        if (!files || !files.length) {
            return;
        }

        let imageUrls = getImageUrls();
        const freeSlots = Math.max(0, MAX_IMAGES - imageUrls.length);
        if (freeSlots <= 0) {
            alert(`Maximum ${MAX_IMAGES} kép tölthető fel.`);
            return;
        }

        const selected = Array.from(files)
            .filter((file) => String(file?.type || '').startsWith('image/'))
            .slice(0, freeSlots);

        if (!selected.length) {
            if (status) {
                status.textContent = 'Nincs érvényes kép a kiválasztott fájlok között.';
            }
            return;
        }

        let uploaded = 0;

        setUploadProgress(0, `Feltöltés: 0/${selected.length}`);

        for (const file of selected) {
            try {
                if (status) {
                    status.textContent = `Feltöltés: ${uploaded + 1}/${selected.length} (${file.name})`;
                }

                const itemBasePercent = Math.round((uploaded / selected.length) * 100);
                setUploadProgress(itemBasePercent, `Feltöltés: ${uploaded}/${selected.length}`);

                const result = await uploadImageAsset(file, (filePercent) => {
                    const totalPercent = Math.round(((uploaded + (filePercent / 100)) / selected.length) * 100);
                    setUploadProgress(totalPercent, `Feltöltés: ${uploaded + 1}/${selected.length} • ${filePercent}%`);
                });

                imageUrls.push(String(result.asset_url || '').trim());
                imageUrls = imageUrls.filter(Boolean).slice(0, MAX_IMAGES);
                writeImageUrls(imageUrls);
                uploaded += 1;
                renderImageList();
            } catch (error) {
                alert(`Kép feltöltési hiba: ${error?.message || 'ismeretlen hiba'}`);
            }
        }

        setUploadProgress(100, `Kész: ${uploaded}/${selected.length} kép`);
        const wrap = document.getElementById('gallery-upload-progress-wrap');
        if (wrap) {
            wrap.setAttribute('data-keep-visible', '1');
        }
        if (status) {
            status.textContent = `Feltöltés kész. Galéria: ${imageUrls.length}/${MAX_IMAGES} kép`;
        }

        renderImageList();
    };

    const bindInputEvents = () => {
        ['gallery-display-mode', 'gallery-fit-mode', 'gallery-slide-interval', 'gallery-transition-ms', 'gallery-collage-columns', 'gallery-bg-color']
            .forEach((id) => {
                const el = document.getElementById(id);
                if (!el) {
                    return;
                }
                const eventName = el.tagName === 'SELECT' || el.type === 'color' ? 'change' : 'input';
                el.addEventListener(eventName, updateLivePreview);
            });
    };

    const initializeUI = () => {
        const uploadArea = document.getElementById('gallery-upload-area');
        const fileInput = document.getElementById('gallery-file-input');
        const libraryRefreshBtn = document.getElementById('gallery-library-refresh');
        const libraryImportBtn = document.getElementById('gallery-library-import');

        if (uploadArea && fileInput) {
            setupGlobalDropGuards(uploadArea);

            uploadArea.addEventListener('click', (event) => {
                if (event.target === fileInput) {
                    return;
                }
                fileInput.click();
            });
            fileInput.addEventListener('click', (event) => {
                event.stopPropagation();
            });
            uploadArea.addEventListener('dragenter', (event) => {
                event.preventDefault();
                uploadArea.style.backgroundColor = '#e3f2fd';
            });
            uploadArea.addEventListener('dragover', (event) => {
                event.preventDefault();
                uploadArea.style.backgroundColor = '#e3f2fd';
            });
            uploadArea.addEventListener('dragleave', () => {
                uploadArea.style.backgroundColor = '#f8f9fa';
            });
            uploadArea.addEventListener('drop', async (event) => {
                event.preventDefault();
                event.stopPropagation();
                uploadArea.style.backgroundColor = '#f8f9fa';
                await handleFiles(event.dataTransfer?.files || []);
            });
            fileInput.addEventListener('change', async (event) => {
                await handleFiles(event.target.files || []);
                fileInput.value = '';
            });
        }

        if (libraryRefreshBtn) {
            libraryRefreshBtn.addEventListener('click', () => {
                loadLibrary();
            });
        }

        if (libraryImportBtn) {
            libraryImportBtn.addEventListener('click', () => {
                importSelectedFromLibrary();
            });
        }

        bindInputEvents();
        renderImageList();
        loadLibrary();
    };

    return {
        init: initializeUI
    };
})();

if (typeof window !== 'undefined') {
    window.GroupLoopGalleryModule = GroupLoopGalleryModule;
}
