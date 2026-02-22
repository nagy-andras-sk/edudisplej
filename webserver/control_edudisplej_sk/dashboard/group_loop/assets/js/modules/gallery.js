const GroupLoopGalleryModule = (() => {
    'use strict';

    const MAX_IMAGES = 10;
    const MAX_LIBRARY_ITEMS = 200;
    let globalDropGuardsCleanup = null;
    let libraryItems = [];
    const DEBUG_PREFIX = '[GroupLoopGallery]';

    const logDebug = (...args) => {
        if (typeof console !== 'undefined' && typeof console.debug === 'function') {
            console.debug(DEBUG_PREFIX, ...args);
        }
    };

    const logWarn = (...args) => {
        if (typeof console !== 'undefined' && typeof console.warn === 'function') {
            console.warn(DEBUG_PREFIX, ...args);
        }
    };

    const logError = (...args) => {
        if (typeof console !== 'undefined' && typeof console.error === 'function') {
            console.error(DEBUG_PREFIX, ...args);
        }
    };

    const probeImageUrlFailure = async (url) => {
        const probeUrl = String(url || '').trim();
        if (!probeUrl) {
            logWarn('probeImageUrlFailure: empty URL');
            return {
                ok: false,
                status: 0,
                message: 'Üres kép URL'
            };
        }

        try {
            const response = await fetch(probeUrl, {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store'
            });

            const contentType = response.headers.get('content-type') || '';
            let bodyPreview = '';
            let magicHex = '';
            let magicLooksValid = null;

            if (contentType.startsWith('image/')) {
                try {
                    const raw = await response.clone().arrayBuffer();
                    const bytes = new Uint8Array(raw).slice(0, 16);
                    magicHex = Array.from(bytes).map((b) => b.toString(16).padStart(2, '0')).join(' ');

                    const isPng = bytes.length >= 8
                        && bytes[0] === 0x89 && bytes[1] === 0x50 && bytes[2] === 0x4e && bytes[3] === 0x47
                        && bytes[4] === 0x0d && bytes[5] === 0x0a && bytes[6] === 0x1a && bytes[7] === 0x0a;
                    const isJpeg = bytes.length >= 3
                        && bytes[0] === 0xff && bytes[1] === 0xd8 && bytes[2] === 0xff;
                    const isGif = bytes.length >= 6
                        && bytes[0] === 0x47 && bytes[1] === 0x49 && bytes[2] === 0x46;
                    const isWebp = bytes.length >= 12
                        && bytes[0] === 0x52 && bytes[1] === 0x49 && bytes[2] === 0x46 && bytes[3] === 0x46
                        && bytes[8] === 0x57 && bytes[9] === 0x45 && bytes[10] === 0x42 && bytes[11] === 0x50;

                    magicLooksValid = isPng || isJpeg || isGif || isWebp;
                } catch (magicError) {
                    magicLooksValid = false;
                    magicHex = `[magic-read-failed: ${magicError?.message || String(magicError)}]`;
                }
            }

            if (!contentType.startsWith('image/')) {
                try {
                    const text = await response.text();
                    bodyPreview = String(text || '').slice(0, 500);
                } catch (bodyReadError) {
                    bodyPreview = `[body read failed: ${bodyReadError?.message || String(bodyReadError)}]`;
                }
            }

            const result = {
                ok: response.ok,
                status: response.status,
                statusText: response.statusText,
                contentType,
                bodyPreview,
                magicHex,
                magicLooksValid,
                finalUrl: response.url
            };

            logError('thumbnail probe result', {
                probeUrl,
                ok: result.ok,
                status: result.status,
                statusText: result.statusText,
                redirected: response.redirected,
                finalUrl: result.finalUrl,
                contentType: result.contentType,
                bodyPreview: result.bodyPreview
            });

            return result;
        } catch (probeError) {
            logError('thumbnail probe request failed', {
                probeUrl,
                error: probeError?.message || String(probeError)
            });

            return {
                ok: false,
                status: 0,
                message: probeError?.message || 'Hálózati hiba'
            };
        }
    };

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
                logWarn('parseImageUrls: parsed JSON is not array', { raw });
                return [];
            }
            const normalized = parsed.map((item) => String(item || '').trim()).filter(Boolean).slice(0, MAX_IMAGES);
            logDebug('parseImageUrls: normalized list', {
                inputType: typeof raw,
                parsedLength: parsed.length,
                normalizedLength: normalized.length
            });
            return normalized;
        } catch (error) {
            logError('parseImageUrls: JSON parse failed', {
                raw,
                error: error?.message || String(error)
            });
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

        logDebug('writeImageUrls: saved image url list', {
            count: normalized.length,
            firstUrl: normalized[0] || null
        });

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
            logDebug('renderLibrary: no items');
            return;
        }

        status.textContent = `Talált elemek: ${libraryItems.length}`;
        logDebug('renderLibrary: rendering items', {
            count: libraryItems.length,
            first: libraryItems[0] || null
        });
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
            const libraryUrl = `../../api/group_loop/module_asset_library.php?module_key=image-gallery&asset_kind=image&limit=${MAX_LIBRARY_ITEMS}`;
            logDebug('loadLibrary: requesting asset library', { libraryUrl });

            const response = await fetch(libraryUrl, {
                credentials: 'same-origin'
            });

            logDebug('loadLibrary: response received', {
                ok: response.ok,
                status: response.status,
                statusText: response.statusText,
                redirected: response.redirected,
                responseUrl: response.url
            });

            const data = await response.json();
            logDebug('loadLibrary: payload summary', {
                success: !!data?.success,
                hasAssetsArray: Array.isArray(data?.assets),
                assetCount: Array.isArray(data?.assets) ? data.assets.length : null,
                message: data?.message || null
            });

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

            logDebug('loadLibrary: normalized assets', {
                count: libraryItems.length,
                first: libraryItems[0] || null
            });

            renderLibrary();
        } catch (error) {
            logError('loadLibrary: failed', {
                error: error?.message || String(error)
            });
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

        logDebug('uploadImageAsset: preparing upload', {
            groupId,
            fileName: file?.name || null,
            fileType: file?.type || null,
            fileSize: file?.size || null
        });

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
                logDebug('uploadImageAsset: upload response', {
                    status: xhr.status,
                    responseType: xhr.responseType,
                    hasJsonResponse: !!xhr.response
                });

                const data = xhr.response || (() => {
                    try {
                        return JSON.parse(xhr.responseText || '{}');
                    } catch (_) {
                        return null;
                    }
                })();

                if (xhr.status >= 200 && xhr.status < 300 && data && data.success && data.asset_url) {
                    logDebug('uploadImageAsset: upload success', {
                        assetUrl: data.asset_url,
                        assetId: data.asset_id || null
                    });
                    resolve(data);
                    return;
                }

                logError('uploadImageAsset: upload rejected', {
                    status: xhr.status,
                    responseMessage: data?.message || null,
                    response: data || null,
                    responseText: xhr.responseText || null
                });
                reject(new Error(data?.message || `Feltöltési hiba (HTTP ${xhr.status})`));
            };

            xhr.onerror = () => {
                logError('uploadImageAsset: network error', {
                    status: xhr.status,
                    responseText: xhr.responseText || null
                });
                reject(new Error('Hálózati hiba a feltöltés során'));
            };
            xhr.send(formData);
        });
    };

    const updateLivePreview = () => {
        const iframe = document.getElementById('gallery-live-preview-iframe');
        const empty = document.getElementById('gallery-preview-empty');
        if (!iframe || !empty) {
            return;
        }

        const displayMode = document.getElementById('gallery-display-mode')?.value || (window.galleryModuleSettings?.displayMode || 'slideshow');

        let imageUrls = getImageUrls();
        if (displayMode === 'single' && imageUrls.length > 1) {
            imageUrls = imageUrls.slice(0, 1);
            writeImageUrls(imageUrls);
        }

        const fitMode = document.getElementById('gallery-fit-mode')?.value || (window.galleryModuleSettings?.fitMode || 'contain');
        const slideIntervalSec = parseInt(document.getElementById('gallery-slide-interval')?.value, 10) || 5;
        const transitionEnabled = document.getElementById('gallery-transition-enabled')?.checked !== false;
        const transitionMs = transitionEnabled ? 450 : 0;
        const collageColumns = parseInt(document.getElementById('gallery-collage-columns')?.value, 10) || 3;
        const bgColor = document.getElementById('gallery-bg-color')?.value || '#000000';

        window.galleryModuleSettings = {
            ...(window.galleryModuleSettings || {}),
            imageUrlsJson: JSON.stringify(imageUrls),
            displayMode,
            fitMode,
            slideIntervalSec,
            transitionEnabled,
            transitionMs,
            collageColumns,
            bgColor
        };

        if (!imageUrls.length) {
            empty.style.display = 'block';
            iframe.removeAttribute('src');
            logDebug('updateLivePreview: cleared preview (no images)');
            return;
        }

        empty.style.display = 'none';
        const params = new URLSearchParams();
        params.append('imageUrlsJson', JSON.stringify(imageUrls));
        params.append('displayMode', displayMode);
        params.append('fitMode', fitMode);
        params.append('slideIntervalSec', String(slideIntervalSec));
        params.append('transitionEnabled', transitionEnabled ? 'true' : 'false');
        params.append('transitionMs', String(transitionMs));
        params.append('collageColumns', String(collageColumns));
        params.append('bgColor', String(bgColor));
        params.append('preview', String(Date.now()));

        iframe.src = `../../modules/gallery/m_gallery.html?${params.toString()}`;
        logDebug('updateLivePreview: iframe src set', {
            imageCount: imageUrls.length,
            displayMode,
            fitMode,
            slideIntervalSec,
            transitionEnabled,
            transitionMs,
            collageColumns,
            bgColor,
            iframeSrc: iframe.src
        });
    };

    const updateModeDependentSettingsVisibility = () => {
        const mode = document.getElementById('gallery-display-mode')?.value || 'slideshow';
        const slideshowWrap = document.getElementById('gallery-slide-interval-wrap');
        const transitionWrap = document.getElementById('gallery-transition-toggle-wrap');
        const collageWrap = document.getElementById('gallery-collage-columns-wrap');

        if (slideshowWrap) {
            slideshowWrap.style.display = mode === 'slideshow' ? 'block' : 'none';
        }
        if (transitionWrap) {
            transitionWrap.style.display = mode === 'slideshow' ? 'flex' : 'none';
        }
        if (collageWrap) {
            collageWrap.style.display = mode === 'collage' ? 'block' : 'none';
        }
    };

    const renderImageList = () => {
        const list = document.getElementById('gallery-image-list');
        const status = document.getElementById('gallery-upload-status');
        if (!list || !status) {
            return;
        }

        const displayMode = document.getElementById('gallery-display-mode')?.value || (window.galleryModuleSettings?.displayMode || 'slideshow');
        let imageUrls = getImageUrls();
        if (displayMode === 'single' && imageUrls.length > 1) {
            imageUrls = imageUrls.slice(0, 1);
            writeImageUrls(imageUrls);
        }
        status.textContent = `Feltöltve: ${imageUrls.length}/${MAX_IMAGES} kép`;
        logDebug('renderImageList: rendering image list', {
            count: imageUrls.length,
            urls: imageUrls
        });

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
            imgEl.addEventListener('error', async () => {
                const imgSrc = imgEl.currentSrc || imgEl.src || imgEl.getAttribute('src') || '';
                logError('renderImageList: thumbnail image load error', {
                    imgSrc,
                    naturalWidth: imgEl.naturalWidth,
                    naturalHeight: imgEl.naturalHeight,
                    complete: imgEl.complete,
                    index: imgEl.getAttribute('data-gallery-thumb')
                });
                const probe = await probeImageUrlFailure(imgSrc);
                imgEl.style.display = 'none';
                const errorNote = document.createElement('span');
                const inlineDetail = (() => {
                    if (!probe) {
                        return 'ismeretlen hiba';
                    }

                    const apiMessage = (() => {
                        const raw = String(probe.bodyPreview || '').trim();
                        if (!raw) {
                            return '';
                        }

                        if (raw.startsWith('{')) {
                            try {
                                const parsed = JSON.parse(raw);
                                return String(parsed?.message || '').trim();
                            } catch (_) {
                                return '';
                            }
                        }

                        const compact = raw.replace(/\s+/g, ' ').trim();
                        return compact.slice(0, 120);
                    })();

                    if (probe.ok) {
                        const ct = String(probe.contentType || '').toLowerCase();
                        if (ct && !ct.startsWith('image/')) {
                            return apiMessage
                                ? `HTTP ${probe.status}: nem kép válasz (${probe.contentType}) - ${apiMessage}`
                                : `HTTP ${probe.status}: nem kép válasz (${probe.contentType})`;
                        }
                        if (probe.magicLooksValid === false) {
                            return `HTTP ${probe.status}: nem valós képtartalom (magic: ${probe.magicHex || 'n/a'})`;
                        }
                        return `HTTP ${probe.status}: kép dekódolási hiba vagy sérült fájl`;
                    }

                    if (probe.status > 0) {
                        return apiMessage ? `HTTP ${probe.status}: ${apiMessage}` : `HTTP ${probe.status}`;
                    }
                    return String(probe.message || 'hálózati hiba');
                })();

                errorNote.textContent = `Kép betöltési hiba: ${inlineDetail}`;
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
            logWarn('handleFiles: no valid image files selected', {
                incomingCount: Array.isArray(files) ? files.length : (files?.length || 0)
            });
            return;
        }

        logDebug('handleFiles: selected image files', {
            selectedCount: selected.length,
            selectedFiles: selected.map((file) => ({
                name: file?.name || null,
                type: file?.type || null,
                size: file?.size || null
            }))
        });

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
                logDebug('handleFiles: image uploaded and added to list', {
                    uploaded,
                    totalSelected: selected.length,
                    lastAssetUrl: result?.asset_url || null,
                    currentTotalImages: imageUrls.length
                });
                renderImageList();
            } catch (error) {
                logError('handleFiles: upload failed for file', {
                    fileName: file?.name || null,
                    error: error?.message || String(error)
                });
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
        ['gallery-display-mode', 'gallery-fit-mode', 'gallery-slide-interval', 'gallery-transition-enabled', 'gallery-collage-columns', 'gallery-bg-color']
            .forEach((id) => {
                const el = document.getElementById(id);
                if (!el) {
                    return;
                }
                const eventName = el.tagName === 'SELECT' || el.type === 'color' || el.type === 'checkbox' ? 'change' : 'input';
                el.addEventListener(eventName, () => {
                    if (id === 'gallery-display-mode') {
                        updateModeDependentSettingsVisibility();
                    }
                    updateLivePreview();
                    if (id === 'gallery-display-mode') {
                        renderImageList();
                    }
                });
            });
    };

    const initializeUI = () => {
        logDebug('initializeUI: init start', {
            groupId: parseInt(window.GroupLoopBootstrap?.groupId || 0, 10),
            bootstrapAvailable: !!window.GroupLoopBootstrap,
            settingsAvailable: !!window.galleryModuleSettings
        });

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

        updateModeDependentSettingsVisibility();
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
