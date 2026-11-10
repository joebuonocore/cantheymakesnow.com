/**
 * Can They Make Snow? widget.
 *
 * Paste on any site:
 *   <a class="ctms-widget" href="https://cantheymakesnow.com/resorts/jay-peak-resort">Can they make snow at Jay Peak Resort?</a>
 *   <script async src="https://cantheymakesnow.com/embed.js"></script>
 *
 * Each link becomes a small forecast card. The link itself stays in the
 * page (shown under the card), so without JavaScript it's still a link.
 * Options on the link: data-theme="auto|light|dark", data-units="f|c".
 * No cookies, no tracking: it fetches /embed/{slug}.json and nothing else.
 */
(function () {
    'use strict';

    var script = document.currentScript;
    var origin = 'https://cantheymakesnow.com';
    try {
        if (script && script.src) {
            origin = new URL(script.src).origin;
        }
    } catch (e) {}

    var CSS = [
        '.card { font: 14px/1.4 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; text-align: left; --bg: #fff; --text: #1d2125; --muted: #5f6368; --line: rgba(0,0,0,.12); --yes: #1c5cab; --no: #6b6a64;',
        '  background: var(--bg); color: var(--text); border: 1px solid var(--line); padding: 12px 14px; box-sizing: border-box; }',
        '.card.dark { --bg: #212529; --text: #f1f3f5; --muted: rgba(255,255,255,.68); --line: rgba(255,255,255,.16); --yes: #86b6ef; --no: #a9a8a1; }',
        '@media (prefers-color-scheme: dark) { .card.auto { --bg: #212529; --text: #f1f3f5; --muted: rgba(255,255,255,.68); --line: rgba(255,255,255,.16); --yes: #86b6ef; --no: #a9a8a1; } }',
        '.brand { display: flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: var(--muted); }',
        '.brand svg { width: 16px; height: 16px; flex: none; }',
        '.name { margin: 6px 0 0; font-size: 16px; font-weight: 700; }',
        '.answer { display: flex; align-items: baseline; gap: 10px; margin: 2px 0 8px; }',
        '.yesno { font-size: 34px; font-weight: 800; line-height: 1.1; letter-spacing: -.01em; }',
        '.yesno.yes { color: var(--yes); } .yesno.no { color: var(--no); }',
        '.wb { color: var(--muted); }',
        '.stats { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin: 0; padding: 8px 0 0; border-top: 1px solid var(--line); }',
        '.stats div { margin: 0; } .stats dt { font-size: 11px; color: var(--muted); } .stats dd { margin: 0; font-weight: 600; }',
        '.stats dd.note { font-size: 11px; font-weight: 400; color: var(--muted); }',
        '.foot { display: flex; justify-content: space-between; gap: 8px; margin-top: 8px; font-size: 12px; color: var(--muted); }',
        '::slotted(a) { color: inherit !important; font-size: 12px !important; }'
    ].join('\n');

    var MARK = '<svg viewBox="0 0 100 100" aria-hidden="true"><rect width="100" height="100" rx="22" fill="#1c5cab"/>' +
        '<g stroke="#fff" stroke-width="7.5" stroke-linecap="round" fill="none"><g id="m"><path d="M50 50V16M50 30.3l-8.7-8.7M50 30.3l8.7-8.7"/></g>' +
        '<use href="#m" transform="rotate(60 50 50)"/><use href="#m" transform="rotate(120 50 50)"/><use href="#m" transform="rotate(180 50 50)"/>' +
        '<use href="#m" transform="rotate(240 50 50)"/><use href="#m" transform="rotate(300 50 50)"/></g></svg>';

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined && text !== null) {
            node.textContent = text;
        }
        return node;
    }

    function ago(iso) {
        var minutes = Math.round((Date.now() - Date.parse(iso)) / 60000);
        if (!(minutes >= 0)) {
            return '';
        }
        if (minutes < 60) {
            return minutes <= 1 ? 'just now' : minutes + ' min ago';
        }
        var hours = Math.round(minutes / 60);
        return hours + (hours === 1 ? ' hour ago' : ' hours ago');
    }

    function render(link, data) {
        var units = link.getAttribute('data-units') === 'c' ? 'c' : 'f';
        var theme = link.getAttribute('data-theme');
        theme = theme === 'light' || theme === 'dark' ? theme : 'auto';

        var host = el('div', 'ctms-widget-card');
        // Inline, so the host page's own CSS (borders, padding on every div) can't reach the box.
        host.style.cssText = 'all: initial; display: block; max-width: 340px; margin: 0 0 1em;';
        link.parentNode.insertBefore(host, link);
        host.appendChild(link);
        var root = host.attachShadow({ mode: 'open' });
        var style = el('style');
        style.textContent = CSS;
        root.appendChild(style);

        var card = el('div', 'card ' + theme);
        var brand = el('div', 'brand');
        brand.innerHTML = MARK;
        brand.appendChild(el('span', '', 'Can they make snow?'));
        card.appendChild(brand);
        card.appendChild(el('p', 'name', data.name + ', ' + data.state));

        var answer = el('div', 'answer');
        if (data.fresh) {
            answer.appendChild(el('span', 'yesno ' + data.answer, data.answer.toUpperCase()));
            if (data.wetBulb) {
                answer.appendChild(el('span', 'wb', data.wetBulb[units] + ' wet-bulb'));
            }
        } else {
            answer.appendChild(el('span', 'wb', 'Forecast updating, check back soon'));
        }
        card.appendChild(answer);

        var stats = el('dl', 'stats');
        function stat(label, value, note) {
            var div = el('div');
            div.appendChild(el('dt', '', label));
            div.appendChild(el('dd', '', value));
            if (note) {
                div.appendChild(el('dd', 'note', note));
            }
            stats.appendChild(div);
        }
        var hours = data.snowmakingHours48;
        var starts = data.snowmakingStarts;
        stat('Snowmaking, next 48 h', hours === null ? 'n/a' : hours + ' h', starts ? (starts === 'now' ? 'running now' : 'from ' + starts) : null);
        stat('New snow, next 48 h', data.snow48 ? data.snow48[units] : 'n/a');
        card.appendChild(stats);

        var foot = el('div', 'foot');
        foot.appendChild(el('slot'));
        if (data.checkedAt) {
            foot.appendChild(el('span', '', ago(data.checkedAt)));
        }
        card.appendChild(foot);
        root.appendChild(card);
    }

    function upgrade(link) {
        link.setAttribute('data-ctms-ready', '1');
        var match = (link.getAttribute('href') || '').match(/\/resorts\/([a-z0-9-]+)\/?(?:[?#].*)?$/);
        if (!match || !window.fetch || !link.attachShadow) {
            return;
        }
        fetch(origin + '/embed/' + match[1] + '.json', { credentials: 'omit' })
            .then(function (response) { return response.ok ? response.json() : null; })
            .then(function (data) {
                if (data && data.name) {
                    render(link, data);
                }
            })
            .catch(function () {});
    }

    function init() {
        var links = document.querySelectorAll('a.ctms-widget:not([data-ctms-ready])');
        for (var i = 0; i < links.length; i++) {
            upgrade(links[i]);
        }
    }

    // Sites that add links later (and the /embed preview) can call this again.
    window.ctmsWidget = { refresh: init };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
