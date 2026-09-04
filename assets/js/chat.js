/* global mcpChat */
(function (wp, settings) {
    'use strict';
    if (!wp || !wp.apiFetch) return;

    const log = document.getElementById('mcp-chat-log');
    const form = document.getElementById('mcp-chat-form');
    const input = document.getElementById('mcp-chat-input');
    const sendBtn = document.getElementById('mcp-chat-send');
    const status = document.getElementById('mcp-chat-status');
    const json = document.getElementById('mcp-chat-json');
    const diffBox = document.getElementById('mcp-chat-diff');
    const stats = document.getElementById('mcp-chat-result-stats');
    const copyBtn = document.getElementById('mcp-chat-copy');
    const applyBtn = document.getElementById('mcp-chat-apply');
    const clearBtn = document.querySelector('.mcp-chat-clear');
    const pageSelect = document.getElementById('mcp-page-select');
    const cloneBtn = document.getElementById('mcp-chat-clone-site');
    const templateBtn = document.getElementById('mcp-chat-template');
    const historyBtn = document.getElementById('mcp-chat-history');
    const promptsBtn = document.getElementById('mcp-chat-prompts');

    const cloneModal = document.getElementById('mcp-clone-modal');
    const templateModal = document.getElementById('mcp-template-modal');
    const historyPanel = document.getElementById('mcp-history-panel');
    const promptsPanel = document.getElementById('mcp-prompts-panel');

    const STORAGE_KEY = 'mcp_chat_history_v1';
    let lastSections = null;
    let conversation = [];

    /* ---------- helpers ---------- */

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[c]));
    }

    function appendMessage(role, text) {
        const div = document.createElement('div');
        div.className = 'mcp-chat-msg mcp-chat-msg--' + role;
        div.textContent = text;
        log.appendChild(div);
        log.scrollTop = log.scrollHeight;
        return div;
    }

    function setStatus(text, isError) {
        status.textContent = text || '';
        status.classList.toggle('mcp-chat-status--error', !!isError);
    }

    function persistHistory() {
        try {
            const slim = conversation.slice(-50);
            localStorage.setItem(STORAGE_KEY, JSON.stringify(slim));
        } catch (e) { /* ignore */ }
    }

    function loadHistory() {
        try {
            const raw = localStorage.getItem(STORAGE_KEY);
            if (!raw) return [];
            const parsed = JSON.parse(raw);
            return Array.isArray(parsed) ? parsed : [];
        } catch (e) {
            return [];
        }
    }

    function populatePages() {
        if (!pageSelect) return;
        (settings.pages || []).forEach((p) => {
            const opt = document.createElement('option');
            opt.value = String(p.id);
            opt.textContent = '#' + p.id + ' — ' + p.title + ' (' + p.status + ')';
            pageSelect.appendChild(opt);
        });
    }

    function renderResult(payload) {
        lastSections = payload.sections || [];
        json.textContent = JSON.stringify(lastSections, null, 2);
        const s = payload.stats || {};
        stats.textContent =
            s.sections + ' sec · ' +
            s.columns + ' col · ' +
            s.widgets + ' wgt · ' +
            Math.round((s.bytes || 0) / 1024) + ' KB';

        diffBox.innerHTML = '';
        if (payload.diff && payload.diff.ops && payload.diff.ops.length) {
            const summary = payload.diff.summary || {};
            const header = document.createElement('div');
            header.style.marginBottom = '4px';
            header.innerHTML =
                '<strong>Diff vs current page:</strong> +' + (summary.added || 0) +
                ' / ~' + (summary.modified || 0) +
                ' / -' + (summary.removed || 0) +
                ' / ↔' + (summary.moved || 0);
            diffBox.appendChild(header);

            payload.diff.ops.slice(0, 40).forEach((op) => {
                const row = document.createElement('div');
                row.className = 'mcp-chat-diff-op mcp-chat-diff-op--' + op.op;
                row.textContent = '[' + op.op + '] ' + op.path + ' (' + op.elType + ')';
                diffBox.appendChild(row);
            });
            if (payload.diff.ops.length > 40) {
                const more = document.createElement('div');
                more.style.opacity = '0.7';
                more.textContent = '+ ' + (payload.diff.ops.length - 40) + ' more ops hidden';
                diffBox.appendChild(more);
            }
        } else if (payload.diff) {
            diffBox.innerHTML = '<em>No changes — the AI output matches the current page.</em>';
        }
    }

    function openModal(m) { if (m) m.style.display = 'flex'; }
    function closeModal(m) { if (m) m.style.display = 'none'; }
    function openPanel(p) { if (p) p.style.display = 'block'; }
    function closePanel(p) { if (p) p.style.display = 'none'; }

    /* ---------- existing chat actions ---------- */

    async function sendPrompt(ev) {
        ev.preventDefault();
        const prompt = input.value.trim();
        if (!prompt) return;

        if (!settings.hasKey) {
            appendMessage('error', settings.i18n.noKey);
            return;
        }

        appendMessage('user', prompt);
        conversation.push({ role: 'user', content: prompt });
        input.value = '';
        sendBtn.disabled = true;
        setStatus(settings.i18n.sending);

        try {
            const body = {
                prompt: prompt,
                post_id: parseInt(pageSelect.value || '0', 10),
                history: conversation.slice(0, -1),
            };
            const response = await wp.apiFetch({
                path: '/mcp/v1/chat',
                method: 'POST',
                data: body,
            });

            if (!response || !response.ok) {
                appendMessage('error', settings.i18n.error + ': ' + ((response && response.error) || 'unknown'));
                setStatus('', true);
            } else {
                appendMessage('assistant', 'Generated ' + (response.stats.sections || 0) + ' sections');
                conversation.push({ role: 'assistant', content: 'sections=' + JSON.stringify(response.sections) });
                renderResult(response);
                setStatus('Done · model=' + response.model);
            }
        } catch (err) {
            appendMessage('error', settings.i18n.error + ': ' + ((err && err.message) || err));
            setStatus('', true);
        } finally {
            sendBtn.disabled = false;
            persistHistory();
            input.focus();
        }
    }

    async function applyToPage() {
        if (!lastSections) {
            appendMessage('system', 'Nothing to apply — run a prompt first.');
            return;
        }
        const postId = parseInt(pageSelect.value || '0', 10);
        applyBtn.disabled = true;
        setStatus('Applying…');
        try {
            const data = await wp.apiFetch({
                path: '/mcp/v1/chat/apply',
                method: 'POST',
                data: {
                    sections: lastSections,
                    post_id: postId,
                    title: postId ? '' : 'AI-generated page',
                    status: 'draft',
                },
            });
            if (!data || !data.ok) {
                appendMessage('error', 'Apply failed: ' + ((data && (data.error || data.message)) || 'unknown'));
                setStatus('', true);
                return;
            }
            const link = data.edit_url ? ('Edit: ' + data.edit_url) : ('Page ID: ' + data.post_id);
            appendMessage('system', 'Applied! ' + link);
            setStatus('Applied to page ' + data.post_id);
            if (data.post_id && pageSelect && !pageSelect.querySelector('option[value="' + data.post_id + '"]')) {
                const opt = document.createElement('option');
                opt.value = String(data.post_id);
                opt.textContent = '#' + data.post_id + ' — ' + (data.title || 'AI page') + ' (draft)';
                pageSelect.appendChild(opt);
            }
        } catch (err) {
            appendMessage('error', 'Apply error: ' + ((err && err.message) || err));
            setStatus('', true);
        } finally {
            applyBtn.disabled = false;
        }
    }

    function copyJson() {
        if (!lastSections) return;
        const text = JSON.stringify(lastSections, null, 2);
        if (navigator.clipboard) {
            navigator.clipboard.writeText(text).then(
                () => setStatus(settings.i18n.copied),
                () => fallbackCopy(text)
            );
        } else {
            fallbackCopy(text);
        }
    }

    function fallbackCopy(text) {
        const ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); setStatus(settings.i18n.copied); }
        catch (e) { setStatus('Copy failed', true); }
        document.body.removeChild(ta);
    }

    function clearAll() {
        conversation = [];
        lastSections = null;
        log.innerHTML = '';
        json.textContent = '';
        diffBox.innerHTML = '';
        stats.textContent = '';
        try { localStorage.removeItem(STORAGE_KEY); } catch (e) { /* ignore */ }
        setStatus('');
    }

    /* ---------- new: Clone site modal ---------- */

    async function openCloneSite() {
        openModal(cloneModal);
        const input = document.getElementById('mcp-clone-url');
        if (input) input.focus();
    }

    async function runCloneSite() {
        const urlInput = document.getElementById('mcp-clone-url');
        const pagesInput = document.getElementById('mcp-clone-pages');
        const status = document.getElementById('mcp-clone-status');
        const result = document.getElementById('mcp-clone-result');
        const url = (urlInput.value || '').trim();
        const pages = parseInt(pagesInput.value || '4', 10);
        if (!url) { status.textContent = 'Enter a URL.'; return; }
        status.textContent = 'Crawling and cloning (this may take a minute)…';
        result.innerHTML = '';
        try {
            const data = await wp.apiFetch({
                path: '/mcp/v1/clone',
                method: 'POST',
                data: { url: url, max_pages: pages, status: 'draft' },
            });
            if (!data || data.code) {
                status.textContent = 'Error: ' + ((data && (data.message || data.code)) || 'unknown');
                return;
            }
            const pageResults = data.pages || [];
            status.textContent = 'Done. ' + pageResults.length + ' page(s) created.';
            const list = pageResults.map(p => {
                const errBlock = p.error
                    ? '<strong style="color:#d63638">FAIL: ' + escapeHtml(p.error) + '</strong>'
                    : 'post_id=' + (p.post_id || '?') +
                      ' · ' + ((p.stats && p.stats.sections) || 0) + ' sections · ' +
                      (p.view_url ? '<a href="' + p.view_url + '" target="_blank">view</a> · ' : '') +
                      (p.edit_url ? '<a href="' + p.edit_url + '" target="_blank">edit</a>' : '');
                return '<li><strong>' + escapeHtml(p.role || 'page') + '</strong>: ' + errBlock + '</li>';
            }).join('');
            result.innerHTML = '<ul style="margin-top:8px">' + list + '</ul>';
        } catch (err) {
            status.textContent = 'Error: ' + ((err && err.message) || JSON.stringify(err));
        }
    }

    /* ---------- new: Template Builder modal ---------- */

    function openTemplateBuilder() {
        openModal(templateModal);
    }

    async function runTemplateBuild() {
        const f = templateModal.querySelector('form');
        const fd = new FormData(f);
        const brief = {
            industry: fd.get('industry') || 'general',
            brand_name: fd.get('brand_name') || 'Brand',
            tagline: fd.get('tagline') || '',
            description: fd.get('description') || '',
            language: fd.get('language') || 'en',
            design_system: fd.get('design_system') || 'modern_saas',
            sections: (fd.get('sections') || 'header,hero,features,about,testimonials,cta,footer').split(',').map(s => s.trim()).filter(Boolean),
            model: fd.get('model') || '',
        };
        const status = document.getElementById('mcp-template-status');
        const result = document.getElementById('mcp-template-result');
        status.textContent = 'Building template (one section at a time, may take 1–2 minutes)…';
        result.innerHTML = '';
        try {
            const data = await wp.apiFetch({
                path: '/mcp/v1/template',
                method: 'POST',
                data: { brief: brief, status: 'draft' },
            });
            if (!data || data.code) {
                status.textContent = 'Error: ' + ((data && (data.message || data.code)) || 'unknown');
                return;
            }
            const s = (data.stats || {});
            status.textContent = 'Done. ' + (s.sections || 0) + ' sections, ' + (s.widgets || 0) + ' widgets.';
            const viewLink = data.view_url
                ? '<a href="' + data.view_url + '" target="_blank" class="button">View</a> ' : '';
            const editLink = data.edit_url
                ? '<a href="' + data.edit_url + '" target="_blank" class="button">Edit in Elementor</a>' : '';
            result.innerHTML = '<p>Page created: <strong>' + escapeHtml(brief.brand_name) + '</strong></p>' +
                '<p>' + viewLink + editLink + '</p>';
        } catch (err) {
            status.textContent = 'Error: ' + ((err && err.message) || JSON.stringify(err));
        }
    }

    /* ---------- new: History panel ---------- */

    async function openHistory() {
        const postId = parseInt(pageSelect.value || '0', 10);
        if (!postId) {
            appendMessage('system', 'Select a target page first to view its snapshots.');
            return;
        }
        openPanel(historyPanel);
        const list = document.getElementById('mcp-history-list');
        list.innerHTML = 'Loading…';
        try {
            const data = await wp.apiFetch({
                path: '/mcp/v1/agent/snapshot/list?post_id=' + postId,
                method: 'GET',
            });
            const items = data.items || [];
            if (!items.length) {
                list.innerHTML = '<em>No snapshots yet for this page.</em>';
                return;
            }
            list.innerHTML = items.map(s =>
                '<li><strong>' + escapeHtml(s.label) + '</strong> · ' +
                escapeHtml(s.taken_at) + ' · ' +
                '<button class="button" data-id="' + s.id + '">Restore</button></li>'
            ).join('');
            list.querySelectorAll('button[data-id]').forEach(btn => {
                btn.addEventListener('click', () => restoreSnapshot(parseInt(btn.dataset.id, 10)));
            });
        } catch (err) {
            list.innerHTML = 'Error: ' + err.message;
        }
    }

    async function restoreSnapshot(snapId) {
        if (!confirm('Restore page from snapshot #' + snapId + '? Current data will be backed up first.')) return;
        try {
            const data = await wp.apiFetch({
                path: '/mcp/v1/agent/snapshot/restore',
                method: 'POST',
                data: { snapshot_id: snapId },
            });
            if (data.restored_to) {
                appendMessage('system', 'Restored from snapshot #' + snapId);
                closePanel(historyPanel);
            } else {
                alert('Restore failed');
            }
        } catch (err) {
            alert('Error: ' + err.message);
        }
    }

    /* ---------- new: Prompts library ---------- */

    const PROMPTS_LIBRARY = {
        'local_business': {
            label: '🏪 Local Business',
            brief: {
                industry: 'local business', brand_name: '', tagline: '', description: 'A trusted local business serving the community for years. Family-friendly, high-quality service, personal touch.',
                language: 'en', design_system: 'warm_editorial', sections: ['header','hero','features','about','testimonials','cta','footer'],
            }
        },
        'dental_clinic': {
            label: '🦷 Dental Clinic',
            brief: {
                industry: 'dental clinic', brand_name: '', tagline: 'Your smile, our passion',
                description: 'A modern dental clinic offering general, cosmetic, and emergency services. Family-friendly with the latest equipment.',
                language: 'en', design_system: 'calm_spa', sections: ['header','hero','features','about','testimonials','cta','footer'],
            }
        },
        'developer_portfolio': {
            label: '💻 Developer Portfolio',
            brief: {
                industry: 'technology', brand_name: '', tagline: 'Building software that matters',
                description: 'A full-stack developer with 8+ years of experience in scalable web apps, cloud architecture, and developer tools.',
                language: 'en', design_system: 'modern_saas', sections: ['header','hero','features','about','cta','footer'],
            }
        },
        'hair_salon': {
            label: '💇 Hair Salon',
            brief: {
                industry: 'beauty salon', brand_name: '', tagline: 'Where style meets soul',
                description: 'Boutique hair salon offering cuts, color, styling, and treatments. Welcoming atmosphere, expert stylists, premium products.',
                language: 'en', design_system: 'warm_editorial', sections: ['header','hero','features','testimonials','cta','footer'],
            }
        },
        'car_wash': {
            label: '🚗 Car Wash',
            brief: {
                industry: 'car wash', brand_name: '', tagline: 'Shine that lasts',
                description: 'Premium car wash and detailing service. Interior and exterior packages, ceramic coating, free pickup and delivery.',
                language: 'en', design_system: 'bold_studio', sections: ['header','hero','features','testimonials','cta','footer'],
            }
        },
        'tourism_fa': {
            label: '✈️ آژانس مسافرتی',
            brief: {
                industry: 'tourism', brand_name: '', tagline: 'سفرهای به یاد ماندنی',
                description: 'آژانس مسافرتی با ۱۵ سال تجربه، فروش تورهای داخلی و خارجی، پرواز، هتل و بیمه مسافرتی.',
                language: 'fa', design_system: 'persian_traditional', sections: ['header','hero','features','about','testimonials','cta','footer'],
            }
        },
        'restaurant_fa': {
            label: '🍽️ رستوران',
            brief: {
                industry: 'restaurant', brand_name: '', tagline: 'طعم واقعی',
                description: 'رستوران سنتی ایرانی با بیش از ۲۰ سال سابقه، سرو انواع کباب، خورشت و پیش‌غذا در محیطی دنج و خانوادگی.',
                language: 'fa', design_system: 'restaurant_warm', sections: ['header','hero','features','about','cta','footer'],
            }
        },
    };

    function openPrompts() {
        openPanel(promptsPanel);
        const grid = document.getElementById('mcp-prompts-grid');
        if (grid.children.length) return;
        Object.entries(PROMPTS_LIBRARY).forEach(([key, p]) => {
            const card = document.createElement('div');
            card.className = 'mcp-prompt-card';
            card.innerHTML = '<h3>' + escapeHtml(p.label) + '</h3><p>' + escapeHtml(p.brief.industry) + ' · ' + p.brief.design_system + '</p>' +
                '<button class="button button-primary" data-key="' + key + '">Use this template</button>';
            grid.appendChild(card);
        });
        grid.querySelectorAll('button[data-key]').forEach(btn => {
            btn.addEventListener('click', () => usePrompt(btn.dataset.key));
        });
    }

    function usePrompt(key) {
        const tpl = PROMPTS_LIBRARY[key];
        if (!tpl) return;
        const f = templateModal.querySelector('form');
        f.industry.value = tpl.brief.industry;
        f.language.value = tpl.brief.language;
        f.design_system.value = tpl.brief.design_system;
        f.description.value = tpl.brief.description;
        // Don't overwrite tagline/brand if user already typed something
        if (!f.brand_name.value) f.brand_name.placeholder = tpl.brief.industry;
        if (!f.tagline.value) f.tagline.placeholder = tpl.brief.tagline;
        closePanel(promptsPanel);
        openModal(templateModal);
    }

    /* ---------- restore + bind ---------- */

    function restore() {
        conversation = loadHistory();
        conversation.forEach((m) => {
            if (m.role === 'user' || m.role === 'assistant') {
                appendMessage(m.role, m.role === 'assistant' && m.content.startsWith('sections=')
                    ? '(previous result)'
                    : m.content);
            }
        });
    }

    if (form) form.addEventListener('submit', sendPrompt);
    if (applyBtn) applyBtn.addEventListener('click', applyToPage);
    if (copyBtn) copyBtn.addEventListener('click', copyJson);
    if (clearBtn) clearBtn.addEventListener('click', clearAll);
    if (cloneBtn) cloneBtn.addEventListener('click', openCloneSite);
    if (templateBtn) templateBtn.addEventListener('click', openTemplateBuilder);
    if (historyBtn) historyBtn.addEventListener('click', openHistory);
    if (promptsBtn) promptsBtn.addEventListener('click', openPrompts);

    // Modal close handlers
    document.querySelectorAll('.mcp-modal-close').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            const m = btn.closest('.mcp-modal');
            closeModal(m);
        });
    });
    // Click outside closes modal
    [cloneModal, templateModal].forEach(m => {
        if (m) m.addEventListener('click', (e) => { if (e.target === m) closeModal(m); });
    });

    // Run buttons
    const runCloneBtn = document.getElementById('mcp-clone-run');
    if (runCloneBtn) runCloneBtn.addEventListener('click', runCloneSite);
    const runTplBtn = document.getElementById('mcp-template-run');
    if (runTplBtn) runTplBtn.addEventListener('click', runTemplateBuild);

    populatePages();
    if (input) input.placeholder = settings.i18n.placeholder;
    restore();
})(window.wp, window.mcpChat || {});