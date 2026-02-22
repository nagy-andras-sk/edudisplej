/**
 * Text Editor Module
 * Handles text module editing, preview, and UI interactions
 * Requires: GroupLoopUtils module
 */

const GroupLoopTextEditor = (() => {
    'use strict';

    /**
     * Apply inline style to selected text
     */
    const applyInlineStyleToSelection = (property, value) => {
        const editor = document.getElementById('text-editor-area');
        if (!editor) return;

        const selection = window.getSelection();
        if (!selection || selection.rangeCount === 0) return;

        const range = selection.getRangeAt(0);
        if (!editor.contains(range.commonAncestorContainer)) return;

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
    };

    /**
     * Apply line height to the current block
     */
    const applyLineHeightToCurrentBlock = (lineHeightValue) => {
        const editor = document.getElementById('text-editor-area');
        if (!editor) return;

        const selection = window.getSelection();
        if (!selection || selection.rangeCount === 0) return;

        let node = selection.anchorNode;
        if (!node) return;

        if (node.nodeType === Node.TEXT_NODE) {
            node = node.parentElement;
        }

        const block = node && node.closest ? node.closest('p,div,li,h1,h2,h3,h4,h5,h6,blockquote') : null;
        if (!block || !editor.contains(block)) return;

        block.style.lineHeight = String(lineHeightValue);
    };

    /**
     * Read image file and convert to compressed data URL
     */
    const readImageAsCompressedDataUrl = (file) => {
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
    };

    /**
     * Update text module mini preview in modal
     * Requires buildModuleUrl function from main app
     */
    const updateTextModuleMiniPreview = (buildModuleUrl, groupDefaultResolution, groupResolutionChoices) => {
        const previewFrame = document.getElementById('text-preview-frame');
        const previewIframe = document.getElementById('text-live-preview-iframe');
        if (!previewFrame || !previewIframe) return;

        const editor = document.getElementById('text-editor-area');
        const hiddenHtml = document.getElementById('setting-text');
        const sanitizedHtml = GroupLoopUtils.sanitizeRichTextHtml(editor ? editor.innerHTML : (hiddenHtml?.value || ''));
        if (hiddenHtml) {
            hiddenHtml.value = sanitizedHtml;
        }

        const bgColor = document.getElementById('setting-bgColor')?.value || '#000000';
        const bgImageData = document.getElementById('setting-bgImageData')?.value || '';
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
                text: sanitizedHtml || 'Sem vložte text...',
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
    };

    /**
     * Bind all text module modal events
     */
    const bindTextModuleModalEvents = (settings, buildModuleUrl, groupDefaultResolution, groupResolutionChoices, showAutosaveToast) => {
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
            updateTextModuleMiniPreview(buildModuleUrl, groupDefaultResolution, groupResolutionChoices);

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
            if (!textEditor) return;

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

        const previewFields = ['setting-bgColor', 'setting-previewResolution'];

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
            if (!field) return;
            field.addEventListener('input', () => {
                if (id === 'setting-bgColor') {
                    applyTextEditorBackground();
                }
                updateTextModuleMiniPreview(buildModuleUrl, groupDefaultResolution, groupResolutionChoices);
            });
            field.addEventListener('change', () => {
                if (id === 'setting-bgColor') {
                    applyTextEditorBackground();
                }
                updateTextModuleMiniPreview(buildModuleUrl, groupDefaultResolution, groupResolutionChoices);
            });
        });

        const editor = document.getElementById('text-editor-area');
        let savedSelectionRange = null;

        const saveSelection = () => {
            const selection = window.getSelection();
            if (!selection || selection.rangeCount === 0) return;
            const range = selection.getRangeAt(0);
            if (editor && editor.contains(range.commonAncestorContainer)) {
                savedSelectionRange = range.cloneRange();
            }
        };

        const restoreSelection = () => {
            if (!savedSelectionRange) return;
            const selection = window.getSelection();
            if (!selection) return;
            selection.removeAllRanges();
            selection.addRange(savedSelectionRange);
        };

        if (editor) {
            const debouncedPreviewUpdate = () => updateTextModuleMiniPreview(buildModuleUrl, groupDefaultResolution, groupResolutionChoices);
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
            textAnimationSelect.addEventListener('change', () => updateTextModuleMiniPreview(buildModuleUrl, groupDefaultResolution, groupResolutionChoices));
        }

        // Toolbar buttons
        const toolbarButtons = document.querySelectorAll('[data-richcmd]');
        toolbarButtons.forEach((button) => {
            button.addEventListener('click', () => {
                const command = button.getAttribute('data-richcmd');
                if (!command) return;
                if (editor) editor.focus();
                restoreSelection();
                if (command === 'underline') {
                    applyInlineStyleToSelection('text-decoration-line', 'underline');
                } else {
                    document.execCommand('styleWithCSS', false, true);
                    document.execCommand(command, false, null);
                }
                saveSelection();
                updateTextModuleMiniPreview(buildModuleUrl, groupDefaultResolution, groupResolutionChoices);
            });
        });

        // Color and font controls
        const colorPicker = document.getElementById('setting-richColor');
        if (colorPicker) {
            colorPicker.addEventListener('input', () => {
                if (editor) editor.focus();
                restoreSelection();
                document.execCommand('styleWithCSS', false, true);
                document.execCommand('foreColor', false, colorPicker.value);
                saveSelection();
                updateTextModuleMiniPreview(buildModuleUrl, groupDefaultResolution, groupResolutionChoices);
            });
        }

        const bgColorPicker = document.getElementById('setting-richBgColor');
        if (bgColorPicker) {
            bgColorPicker.addEventListener('input', () => {
                if (editor) editor.focus();
                restoreSelection();
                applyInlineStyleToSelection('background-color', bgColorPicker.value);
                saveSelection();
                updateTextModuleMiniPreview(buildModuleUrl, groupDefaultResolution, groupResolutionChoices);
            });
        }

        const richFontFamily = document.getElementById('setting-richFontFamily');
        if (richFontFamily) {
            richFontFamily.addEventListener('change', () => {
                if (editor) editor.focus();
                restoreSelection();
                applyInlineStyleToSelection('font-family', richFontFamily.value);
                saveSelection();
                updateTextModuleMiniPreview(buildModuleUrl, groupDefaultResolution, groupResolutionChoices);
            });
        }

        const richFontSize = document.getElementById('setting-richFontSize');
        if (richFontSize) {
            richFontSize.addEventListener('change', () => {
                if (editor) editor.focus();
                restoreSelection();
                const px = Math.max(8, parseInt(richFontSize.value || '32', 10));
                applyInlineStyleToSelection('font-size', `${px}px`);
                saveSelection();
                updateTextModuleMiniPreview(buildModuleUrl, groupDefaultResolution, groupResolutionChoices);
            });
        }

        const richLineHeight = document.getElementById('setting-richLineHeight');
        if (richLineHeight) {
            richLineHeight.addEventListener('change', () => {
                if (editor) editor.focus();
                restoreSelection();
                const value = Math.max(0.8, parseFloat(richLineHeight.value || '1.2'));
                applyLineHeightToCurrentBlock(value);
                saveSelection();
                updateTextModuleMiniPreview(buildModuleUrl, groupDefaultResolution, groupResolutionChoices);
            });
        }

        // Scroll settings
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

        // Preview controls
        const previewPlay = document.getElementById('text-preview-play');
        const previewStop = document.getElementById('text-preview-stop');
        if (previewPlay) {
            previewPlay.addEventListener('click', startTextPreviewPlayback);
        }
        if (previewStop) {
            previewStop.addEventListener('click', () => {
                stopTextPreviewPlayback(true);
                const iframe = document.getElementById('text-live-preview-iframe');
                if (iframe) iframe.src = 'about:blank';
            });
        }

        // Scroll mode toggle
        const scrollMode = document.getElementById('setting-scrollMode');
        const scrollSettings = document.getElementById('textScrollSettings');
        if (scrollMode && scrollSettings) {
            const applyScrollVisibility = () => {
                scrollSettings.style.display = scrollMode.checked ? 'grid' : 'none';
            };
            scrollMode.addEventListener('change', applyScrollVisibility);
            applyScrollVisibility();
        }

        // Background image handling
        const removeBgButton = document.getElementById('setting-removeBgImage');
        const bgDataInput = document.getElementById('setting-bgImageData');
        const bgStatus = document.getElementById('setting-bgImageStatus');
        const bgFileInput = document.getElementById('setting-bgImageFile');

        if (removeBgButton && bgDataInput) {
            removeBgButton.addEventListener('click', () => {
                bgDataInput.value = '';
                if (bgStatus) bgStatus.textContent = 'Nincs kiválasztott kép';
                if (bgFileInput) bgFileInput.value = '';
                applyTextEditorBackground();
                updateTextModuleMiniPreview(buildModuleUrl, groupDefaultResolution, groupResolutionChoices);
            });
        }

        if (bgFileInput && bgDataInput) {
            bgFileInput.addEventListener('change', async () => {
                const file = bgFileInput.files && bgFileInput.files[0];
                if (!file) return;
                if (!file.type.startsWith('image/')) {
                    showAutosaveToast('⚠️ Csak képfájl tölthető fel', true);
                    return;
                }

                if (bgStatus) bgStatus.textContent = 'Feldolgozás...';

                try {
                    const dataUrl = await readImageAsCompressedDataUrl(file);
                    bgDataInput.value = dataUrl;
                    if (bgStatus) {
                        bgStatus.textContent = `${file.name} (${Math.round(dataUrl.length / 1024)} KB)`;
                    }
                    applyTextEditorBackground();
                    updateTextModuleMiniPreview(buildModuleUrl, groupDefaultResolution, groupResolutionChoices);
                } catch (error) {
                    if (bgStatus) bgStatus.textContent = 'Kép feldolgozási hiba';
                    showAutosaveToast('⚠️ Nem sikerült betölteni a képet', true);
                }
            });
        }

        if (bgStatus && !String(settings.bgImageData || '').trim()) {
            bgStatus.textContent = 'Nincs kiválasztott kép';
        }

        applyTextEditorBackground();
        updateTextModuleMiniPreview(buildModuleUrl, groupDefaultResolution, groupResolutionChoices);
        startTextPreviewPlayback();
    };

    // Public API
    return {
        applyInlineStyleToSelection,
        applyLineHeightToCurrentBlock,
        readImageAsCompressedDataUrl,
        updateTextModuleMiniPreview,
        bindTextModuleModalEvents
    };
})();
