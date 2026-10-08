// Scale absolute interface font sizes while retaining the existing title/body hierarchy.
// Relative sizes inherit the scale; document/editor content with explicit inline sizes is preserved.
export function normalizeFontSize(value) {
    const size = Number(value);
    return Number.isInteger(size) && size >= 12 && size <= 20 ? size : 14;
}

// 无个人设置或账号切换期间回退系统默认，避免沿用上一账号的字号。
export function resolveAccountFontSize(userId, appearance, systemSize) {
    const personal = appearance?.font_size;
    if (userId > 0 && appearance?.userid === userId && personal !== null && personal !== undefined) {
        const size = Number(personal);
        if (Number.isInteger(size) && size >= 12 && size <= 20) return size;
    }
    return normalizeFontSize(systemSize);
}

export function applyGlobalFontSize(value) {
    document.documentElement.style.setProperty('--global-font-scale', String(normalizeFontSize(value) / 14));
}

export function scaleFontRules(rules) {
    for (const rule of rules) {
        if (rule.style) {
            const value = rule.style.getPropertyValue('font-size');
            if (/^\d+(?:\.\d+)?px$/.test(value) && parseFloat(value) > 0) {
                rule.style.setProperty('font-size', `calc(${value} * var(--global-font-scale, 1))`, rule.style.getPropertyPriority('font-size'));
            }
        }
        if (rule.cssRules) {
            scaleFontRules(rule.cssRules);
        }
    }
}

export function startGlobalFontSize(value = window.systemInfo.fontSize) {
    const processed = new WeakSet();
    const updateSheets = () => {
        for (const sheet of document.styleSheets) {
            if (processed.has(sheet)) continue;
            try {
                scaleFontRules(sheet.cssRules);
                processed.add(sheet);
            } catch {
                // Cross-origin stylesheets and unloaded links may not expose CSS rules.
            }
        }
    };
    applyGlobalFontSize(value);
    updateSheets();
    const observer = new MutationObserver(updateSheets);
    observer.observe(document.head, {childList: true, subtree: true, characterData: true});
    document.addEventListener('load', updateSheets, true);
    return () => {
        observer.disconnect();
        document.removeEventListener('load', updateSheets, true);
    };
}
