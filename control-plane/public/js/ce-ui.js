/* SAG CE visual-only controls: no gateway/session changes. */
(() => {
    'use strict';

    function safeRemember(value) {
        try { window.localStorage.setItem('ce-sidebar-collapsed-v1', value); } catch (_) {}
    }

    function getRemembered() {
        try { return window.localStorage.getItem('ce-sidebar-collapsed-v1') === '1'; }
        catch (_) { return false; }
    }

    function fallbackCopy(text) {
        const field = document.createElement('textarea');
        field.value = text;
        field.setAttribute('readonly', '');
        field.style.position = 'fixed';
        field.style.opacity = '0';
        field.style.pointerEvents = 'none';
        document.body.appendChild(field);
        field.focus();
        field.select();
        let copied = false;
        try { copied = document.execCommand('copy'); }
        catch (_) { copied = false; }
        field.remove();
        return copied;
    }

    async function copyIdentifier(value) {
        if (navigator.clipboard && window.isSecureContext) {
            try { await navigator.clipboard.writeText(value); return true; }
            catch (_) { /* Browser permission denied: use a local fallback. */ }
        }
        return fallbackCopy(value);
    }

    document.addEventListener('DOMContentLoaded', () => {
        const shell = document.querySelector('.ce-app-shell');
        const toggle = document.getElementById('ce-sidebar-toggle');
        const settings = document.querySelector('.ce-sidebar .ce-settings-group');

        if (shell && toggle) {
            const setCollapsed = (collapsed) => {
                shell.classList.toggle('ce-sidebar-collapsed', collapsed);
                toggle.setAttribute('aria-expanded', String(!collapsed));
                const label = toggle.dataset[collapsed ? 'ceLabelExpand' : 'ceLabelCollapse'];
                toggle.setAttribute('aria-label', label);
                toggle.setAttribute('title', label);
                const text = toggle.querySelector('.ce-nav-text');
                if (text) text.textContent = label;
                safeRemember(collapsed ? '1' : '0');
            };
            setCollapsed(getRemembered());
            toggle.addEventListener('click', () => {
                setCollapsed(!shell.classList.contains('ce-sidebar-collapsed'));
            });
            if (settings) {
                const summary = settings.querySelector('summary');
                if (summary) summary.addEventListener('click', (event) => {
                    if (shell.classList.contains('ce-sidebar-collapsed')) {
                        event.preventDefault();
                        setCollapsed(false);
                        settings.open = true;
                    }
                });
            }
        }

        document.addEventListener('click', async (event) => {
            const button = event.target.closest('button[data-ce-copy]');
            if (!button) return;
            const value = button.getAttribute('data-ce-copy');
            if (!value) return;
            const copied = await copyIdentifier(value);
            button.classList.toggle('is-copied', copied);
            button.classList.toggle('is-copy-error', !copied);
            button.title = copied ? button.dataset.ceLabelCopied : button.dataset.ceLabelFailed;
            button.setAttribute('aria-label', button.title);
        });
    });
})();
