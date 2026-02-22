/**
 * PDF Module
 * Handles PDF upload, configuration, preview, and UI interactions
 */

const GroupLoopPdfModule = (() => {
    'use strict';

    const initializeUI = () => {
        document.querySelectorAll('.pdf-tab-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const tab = this.dataset.tab;
                document.querySelectorAll('.pdf-tab-btn').forEach(b => {
                    b.style.borderBottomColor = b.dataset.tab === tab ? '#1e40af' : 'transparent';
                    b.style.color = b.dataset.tab === tab ? '#1e40af' : '#607083';
                });
                document.querySelectorAll('.pdf-tab-content').forEach(content => {
                    content.style.display = content.dataset.tab === tab ? 'grid' : 'none';
                });
            });
        });
        
        const uploadArea = document.getElementById('pdf-upload-area');
        const fileInput = document.getElementById('pdf-file-input');
        
        if (uploadArea && fileInput) {
            uploadArea.addEventListener('click', () => fileInput.click());
            fileInput.addEventListener('change', handlePdfFileSelect);
        }
        
        const navMode = document.getElementById('pdf-navigationMode');
        if (navMode) {
            navMode.addEventListener('change', function() {
                const autoSettings = document.querySelector('.auto-scroll-settings');
                if (autoSettings) {
                    autoSettings.style.display = this.value === 'auto' ? 'grid' : 'none';
                }
            });
        }
        
        const fixedMode = document.getElementById('pdf-fixedViewMode');
        if (fixedMode) {
            fixedMode.addEventListener('change', function() {
                const settings = document.querySelector('.fixed-page-settings');
                if (settings) {
                    settings.style.display = this.checked ? 'grid' : 'none';
                }
            });
        }
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

    const handlePdfFile = (file) => {
        if (file.type !== 'application/pdf') {
            alert('Csak PDF formatumot tamogat');
            return;
        }
        
        if (file.size > 50 * 1024 * 1024) {
            alert('A fajl tul nagy (max 50 MB)');
            return;
        }
        
        const reader = new FileReader();
        reader.onload = function(e) {
            window.pdfModuleSettings = window.pdfModuleSettings || {};
            window.pdfModuleSettings.pdfDataBase64 = String(e.target.result || '');
            const uploadArea = document.getElementById('pdf-upload-area');
            if (uploadArea) {
                const sizeKB = Math.round(window.pdfModuleSettings.pdfDataBase64.length / 1024);
                const oldMsg = uploadArea.innerHTML.match(/>✓ PDF betöltve.*?<\/div>/);
                if (oldMsg) {
                    uploadArea.innerHTML = uploadArea.innerHTML.replace(/>✓ PDF betöltve.*?<\/div>/, '>');
                }
                const msg = '<div style="color: #28a745; margin-top: 8px; font-size: 13px;">OK PDF: ' + sizeKB + ' KB</div>';
                uploadArea.innerHTML += msg;
            }
        };
        reader.readAsDataURL(file);
    };

    const openPdfPreview = () => {
        const base64 = window.pdfModuleSettings?.pdfDataBase64 || (window.currentPdfBase64 || '');
        if (!base64) {
            alert('Elobt tolts fel PDF-et');
            return;
        }
        const w = window.open('', 'pdfPreview', 'width=1000,height=700');
        w.document.write('<embed src="' + base64 + '" type="application/pdf" width="100%" height="100%">');
    };

    return {
        init: initializeUI,
        handlePdfDrop: handlePdfDrop,
        handlePdfFileSelect: handlePdfFileSelect,
        handlePdfFile: handlePdfFile,
        openPdfPreview: openPdfPreview
    };
})();
