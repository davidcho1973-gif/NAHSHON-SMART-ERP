(function (root) {
    'use strict';
    // A saved punch is terminal for this page. Notices never trigger another punch.
    function create(options) {
        const { container, post, text, download } = options;
        let notices = [], loading = false, failure = false;
        function node(tag, value) {
            const el = document.createElement(tag);
            if (value) el.textContent = value;
            return el;
        }
        function finish() {
            container.replaceChildren(node('h2', text.closed), node('p', text.closeHint));
        }
        async function load() {
            loading = true;
            failure = false;
            draw();
            try { notices = (await post('notices', {})).notices || []; }
            catch (_) { failure = true; }
            loading = false;
            draw();
        }
        async function acknowledge(item, button) {
            button.disabled = true;
            try {
                await post('noticeAck', { message_id: item.id });
                item.confirmed = true;
                draw();
            } catch (_) {
                button.disabled = false;
                button.textContent = text.retry;
            }
        }
        function draw() {
            container.replaceChildren();
            if (loading) { container.append(node('p', text.loading)); return; }
            if (failure) {
                container.append(node('p', text.failed));
                const retry = node('button', text.retry); retry.onclick = load; container.append(retry);
                const close = node('button', text.close); close.onclick = finish; container.append(close);
                return;
            }
            if (!notices.length) { finish(); return; }
            container.append(node('h2', text.notices));
            notices.forEach(item => {
                const article = node('article'); article.className = 'note';
                article.append(node('h3', (item.required ? text.required + ' · ' : '') + item.title));
                article.append(node('p', String(item.body || '').slice(0, 100)));
                const details = node('details'); details.append(node('summary', text.details));
                const body = node('p', item.body); body.style.whiteSpace = 'pre-wrap'; details.append(body);
                (item.files || []).forEach(file => {
                    const button = node('button', file.name);
                    button.onclick = async () => {
                        button.disabled = true;
                        try { await download(file); } catch (_) { button.textContent = text.retry + ' · ' + file.name; }
                        finally { button.disabled = false; }
                    };
                    details.append(button);
                });
                article.append(details);
                if (item.required) {
                    const ack = node('button', item.confirmed ? text.confirmed : text.ack);
                    ack.disabled = !!item.confirmed;
                    ack.onclick = () => acknowledge(item, ack);
                    article.append(ack);
                }
                container.append(article);
            });
            const close = node('button', text.close);
            close.disabled = notices.some(n => n.required && !n.confirmed);
            close.onclick = async () => {
                close.disabled = true;
                try {
                    for (const item of notices.filter(n => !n.required && !n.confirmed)) {
                        await post('noticeAck', { message_id: item.id }); item.confirmed = true;
                    }
                    finish();
                } catch (_) {
                    // Receipt failure cannot undo attendance or trap the worker on the page.
                    failure = true; draw();
                }
            };
            container.append(close);
        }
        return { load };
    }
    root.GateCompletion = { create };
    if (typeof module !== 'undefined') module.exports = { create };
})(typeof window === 'undefined' ? globalThis : window);
