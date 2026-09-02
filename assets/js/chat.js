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
            // keep last 50 messages to bound localStorage
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

    /* ---------- actions ---------- */

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
                history: conversation.slice(0, -1), // server will add current user msg itself
            };
            const response = await wp.apiFetch({
                path: settings.restUrl.replace(rest_url('', '/').replace(/\/$/, ''), ''),
                method: 'POST',
                data: body,
            });
            // The above path-rewrite trick avoids double-prefixing when the
            // localized URL already includes the home URL; if apiFetch can't
            // resolve it, fall back to absolute URL:
            const fallback = await wp.apiFetch({
                url: settings.restUrl,
                method: 'POST',
                data: body,
            }).catch(() => response);

            const data = response || fallback;
            if (!data.ok) {
                appendMessage('error', settings.i18n.error + ': ' + (data.error || 'unknown'));
                setStatus('', true);
            } else {
                appendMessage('assistant', 'Generated ' + (data.stats.sections || 0) + ' sections');
                conversation.push({ role: 'assistant', content: 'sections=' + JSON.stringify(data.sections) });
                renderResult(data);
                setStatus('Done · model=' + data.model);
            }
        } catch (err) {
            appendMessage('error', settings.i18n.error + ': ' + (err.message || err));
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
                url: settings.restUrl + '/apply',
                method: 'POST',
                data: {
                    sections: lastSections,
                    post_id: postId,
                    title: postId ? '' : 'AI-generated page',
                    status: 'draft',
                },
            });
            if (!data.ok) {
                appendMessage('error', 'Apply failed: ' + (data.error || 'unknown'));
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
            appendMessage('error', 'Apply error: ' + (err.message || err));
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

    populatePages();
    if (input) input.placeholder = settings.i18n.placeholder;
    restore();
})(window.wp, window.mcpChat || {});