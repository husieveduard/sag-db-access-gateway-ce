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


    function initNotifications() {
        const widget = document.getElementById('ce-notification');
        if (!widget) return;
        const trigger = document.getElementById('ce-notification-trigger');
        const panel = document.getElementById('ce-notification-panel');
        const list = document.getElementById('ce-notification-items');
        const count = document.getElementById('ce-notification-count');
        const all = document.getElementById('ce-notification-read-all');
        const labels = document.getElementById('ce-notification-labels').dataset;
        const csrf = document.querySelector('meta[name="csrf-token"]').content;
        const displayZone = document.querySelector('meta[name="ce-display-timezone"]')?.content || 'UTC';
        const language = document.documentElement.lang === 'uk' ? 'uk-UA' : 'en-GB';
        const timestampFormat = new Intl.DateTimeFormat(language, {
            timeZone: displayZone, day: '2-digit', month: '2-digit',
            year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit',
            hourCycle: 'h23'
        });
        let pending = false;

        const createNode = (tag, cls, text) => {
            const el = document.createElement(tag);
            if (cls) el.className = cls;
            if (text !== undefined) el.textContent = text;
            return el;
        };

        async function request(url, options = {}) {
            const response = await fetch(url, {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf, ...options.headers },
                ...options,
            });
            if (!response.ok) throw new Error('notification_request_failed');
            return response.json();
        }

        function draw(data) {
            const unread = Number(data.unread_count || 0);
            count.hidden = unread < 1;
            count.textContent = unread > 99 ? '99+' : String(unread);
            list.replaceChildren();
            const items = Array.isArray(data.items) ? data.items : [];
            if (!items.length) {
                list.append(createNode('p', 'ce-notification-empty', labels.empty));
                return;
            }
            for (const item of items) {
                const section = createNode('article', 'ce-notification-item' + (item.unread ? ' is-unread' : ''));
                const head = createNode('div', 'ce-notification-item-head');
                if (item.unread) head.append(createNode('span', 'ce-unread-dot'));
                head.append(createNode('strong', '', item.title));
                section.append(head, createNode('p', '', item.detail));
                const when = createNode('time', '', timestampFormat.format(new Date(item.occurred_at)));
                section.append(when);
                const actions = createNode('div', 'ce-notification-item-actions');
                const link = createNode('a', '', labels.open);
                link.href = item.url;
                actions.append(link);
                if (item.unread) {
                    const read = createNode('button', '', labels.read);
                    read.type = 'button';
                    read.addEventListener('click', async () => {
                        if (pending) return;
                        pending = true;
                        try {
                            const data = await request(widget.dataset.readUrl, {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ source_type: item.source_type, source_id: item.source_id }),
                            });
                            draw(data);
                        } catch (_) {
                            list.prepend(createNode('p', 'ce-notification-empty', labels.unavailable));
                        } finally { pending = false; }
                    });
                    actions.append(read);
                }
                section.append(actions);
                list.append(section);
            }
        }

        async function refresh() {
            try { draw(await request(widget.dataset.feedUrl)); }
            catch (_) {
                if (!list.children.length) list.append(createNode('p','ce-notification-empty', labels.unavailable));
            }
        }
        trigger.addEventListener('click', () => {
            panel.hidden = !panel.hidden;
            trigger.setAttribute('aria-expanded', String(!panel.hidden));
            if (!panel.hidden) refresh();
        });
        document.addEventListener('click', (event) => {
            if (!widget.contains(event.target)) {
                panel.hidden = true;
                trigger.setAttribute('aria-expanded', 'false');
            }
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !panel.hidden) {
                panel.hidden = true;
                trigger.setAttribute('aria-expanded', 'false');
                trigger.focus();
            }
        });
        all.addEventListener('click', async () => {
            if (pending) return;
            pending = true;
            try { draw(await request(widget.dataset.readAllUrl, { method: 'POST' })); }
            catch (_) { list.prepend(createNode('p', 'ce-notification-empty', labels.unavailable)); }
            finally { pending = false; }
        });
        refresh();
        window.setInterval(() => { if (!document.hidden) refresh(); }, 60000);
    }

    document.addEventListener('DOMContentLoaded', () => {
        initNotifications();
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
