/**
 * Utility functions for group loop editor
 * Contains helper functions used across the application
 */

const GroupLoopUtils = (() => {
    'use strict';

    /**
     * Deep clone an object using JSON serialization
     */
    const deepClone = (value) => JSON.parse(JSON.stringify(value));

    /**
     * Get localStorage key for draft storage
     */
    const getDraftStorageKey = (groupId) => `group_loop_draft_${groupId}`;

    /**
     * Escape HTML special characters
     */
    const escapeHtml = (value) => {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    };

    /**
     * Sanitize rich text HTML content
     * Removes dangerous tags and attributes, only allows safe formatting
     */
    const sanitizeRichTextHtml = (value) => {
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
    };

    /**
     * Get module key by module ID from catalog
     */
    const getModuleKeyById = (moduleId, modulesCatalog = []) => {
        const module = modulesCatalog.find(m => m.id == moduleId);
        return module ? module.module_key : null;
    };

    /**
     * Get default settings for a module type
     */
    const getDefaultSettings = (moduleKey) => {
        const defaults = {
            'clock': {
                type: 'digital',
                format: '24h',
                dateFormat: 'full',
                timeColor: '#ffffff',
                dateColor: '#ffffff',
                bgColor: '#000000',
                fontSize: 120,
                clockSize: 300,
                showSeconds: true,
                language: 'hu'
            },
            'datetime': {
                type: 'digital',
                format: '24h',
                dateFormat: 'full',
                timeColor: '#ffffff',
                dateColor: '#ffffff',
                bgColor: '#000000',
                fontSize: 120,
                clockSize: 300,
                showSeconds: true,
                language: 'hu'
            },
            'dateclock': {
                type: 'digital',
                format: '24h',
                dateFormat: 'full',
                timeColor: '#ffffff',
                dateColor: '#ffffff',
                bgColor: '#000000',
                fontSize: 120,
                clockSize: 300,
                showSeconds: true,
                language: 'hu'
            },
            'default-logo': {
                text: 'EDUDISPLEJ',
                fontSize: 120,
                textColor: '#ffffff',
                bgColor: '#000000',
                showVersion: true,
                version: 'v1.0'
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
                textAnimationEntry: 'none',
                scrollMode: false,
                scrollStartPauseMs: 3000,
                scrollEndPauseMs: 3000,
                scrollSpeedPxPerSec: 35
            }
        };

        return defaults[moduleKey] || {};
    };

    /**
     * Check if a value is empty/null/undefined
     */
    const isEmpty = (value) => !value || (Array.isArray(value) && value.length === 0);

    /**
     * Format bytes to human readable format
     */
    const formatBytes = (bytes) => {
        if (bytes === 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
    };

    /**
     * Debounce function execution
     */
    const debounce = (func, delay) => {
        let timeoutId;
        return (...args) => {
            clearTimeout(timeoutId);
            timeoutId = setTimeout(() => func(...args), delay);
        };
    };

    /**
     * Throttle function execution
     */
    const throttle = (func, limit) => {
        let inThrottle;
        return (...args) => {
            if (!inThrottle) {
                func(...args);
                inThrottle = true;
                setTimeout(() => inThrottle = false, limit);
            }
        };
    };

    /**
     * Pad number with leading zeros
     */
    const padZero = (num, len = 2) => String(num).padStart(len, '0');

    /**
     * Parse time string (HH:MM) to minutes
     */
    const timeToMinutes = (timeStr) => {
        if (!timeStr || typeof timeStr !== 'string') return 0;
        const [h, m] = timeStr.split(':').map(Number);
        return (h || 0) * 60 + (m || 0);
    };

    /**
     * Convert minutes to time string (HH:MM)
     */
    const minutesToTime = (minutes) => {
        const h = Math.floor(minutes / 60) % 24;
        const m = minutes % 60;
        return `${padZero(h)}:${padZero(m)}`;
    };

    // Public API
    return {
        deepClone,
        getDraftStorageKey,
        escapeHtml,
        sanitizeRichTextHtml,
        getModuleKeyById,
        getDefaultSettings,
        isEmpty,
        formatBytes,
        debounce,
        throttle,
        padZero,
        timeToMinutes,
        minutesToTime
    };
})();
