/**
 * Can They Make Snow? front-end.
 *
 * No build step, no dependencies beyond October's AJAX framework (`oc`).
 * Modules share state through window.CTMS and talk via DOM events:
 *   ctms:result  (detail: onLookup response data) after a lookup renders
 */
(function () {
    'use strict';

    var CTMS = window.CTMS = window.CTMS || {};

    var store = {
        get: function (key, fallback) {
            try {
                var value = localStorage.getItem(key);
                return value === null ? fallback : JSON.parse(value);
            } catch (e) {
                return fallback;
            }
        },
        set: function (key, value) {
            try {
                localStorage.setItem(key, JSON.stringify(value));
                return true;
            } catch (e) {
                return false;
            }
        }
    };
    CTMS.store = store;

    // ---- Units ------------------------------------------------------------

    function units() {
        return document.documentElement.getAttribute('data-units') === 'c' ? 'c' : 'f';
    }

    function formatTemp(celsius, decimals) {
        var value = units() === 'f' ? celsius * 9 / 5 + 32 : celsius;
        var text = value.toFixed(decimals || 0);
        if (parseFloat(text) === 0) {
            text = text.replace('-', '');
        }
        return text.replace('-', '−') + (units() === 'f' ? '°F' : '°C');
    }
    CTMS.formatTemp = formatTemp;

    function setUnits(next) {
        if (next === 'c') {
            document.documentElement.setAttribute('data-units', 'c');
        } else {
            document.documentElement.removeAttribute('data-units');
        }
        // Stored raw (not JSON) so the inline <head> script can read it cheaply.
        try { localStorage.setItem('ctms.units', next); } catch (e) {}
        syncUnitButtons();
        document.dispatchEvent(new CustomEvent('ctms:units'));
    }

    function syncUnitButtons() {
        document.querySelectorAll('.units-toggle [data-units]').forEach(function (button) {
            button.setAttribute('aria-pressed', button.getAttribute('data-units') === units() ? 'true' : 'false');
        });
    }

    // ---- Notices ----------------------------------------------------------

    function notice(message) {
        var el = document.getElementById('formNotice');
        if (!el) {
            return;
        }
        el.textContent = message || '';
        el.hidden = !message;
    }
    CTMS.notice = notice;

    // ---- Lookup -----------------------------------------------------------

    var busy = false;

    function paramsFromSearch(search) {
        var query = new URLSearchParams(search);
        var params = {};
        ['address', 'lat', 'lon'].forEach(function (key) {
            if (query.get(key)) {
                params[key] = query.get(key);
            }
        });
        return params;
    }

    function setBusy(state) {
        busy = state;
        var result = document.getElementById('result');
        var submit = document.getElementById('lookupSubmit');
        if (result) {
            result.classList.toggle('is-loading', state);
            result.setAttribute('aria-busy', state ? 'true' : 'false');
        }
        if (submit) {
            submit.disabled = state;
            submit.innerHTML = state
                ? '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span><span class="visually-hidden">Checking</span>'
                : 'Check';
        }
    }

    /**
     * Run a lookup without reloading. params: {address} or {lat, lon}.
     * options.fromHistory skips pushing a new history entry.
     */
    function lookup(params, options) {
        options = options || {};
        if (busy || !window.oc) {
            return;
        }
        notice('');
        setBusy(true);

        oc.ajax('WeatherData::onLookup', {
            data: params,
            update: { result: '#result' },
            successFunc: function (el, data) {
                afterLookup(data || {}, options);
            },
            errorFunc: function () {
                notice("Something went wrong checking that location. Please try again.");
                return false;
            },
            completeFunc: function () {
                setBusy(false);
            }
        });
    }
    CTMS.lookup = lookup;

    function afterLookup(data, options) {
        var answer = document.querySelector('#result .hero-answer');
        var input = document.getElementById('address');

        if (data.ok && answer && input) {
            input.value = answer.getAttribute('data-place') || input.value;
        }
        if (data.pageTitle) {
            document.title = data.pageTitle;
        }
        if (!options.fromHistory && data.ok && data.shareUrl && data.shareUrl !== window.location.href) {
            history.pushState({ ctms: true }, '', data.shareUrl);
        }

        initForecast();
        document.dispatchEvent(new CustomEvent('ctms:result', { detail: data }));
    }

    function initLookupForm() {
        var form = document.getElementById('lookupForm');
        var input = document.getElementById('address');
        if (!form || !input) {
            return;
        }

        form.addEventListener('submit', function (event) {
            if (!window.oc) {
                return; // Fall back to the plain GET form.
            }
            event.preventDefault();
            var address = input.value.trim();
            if (address) {
                lookup({ address: address });
            }
        });

        var locBtn = document.getElementById('useMyLocation');
        if (locBtn) {
            locBtn.addEventListener('click', function () {
                if (!navigator.geolocation) {
                    notice('Your browser does not support location lookup. Try entering a city and state instead.');
                    return;
                }
                locBtn.disabled = true;
                locBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';
                navigator.geolocation.getCurrentPosition(
                    function (position) {
                        locBtn.disabled = false;
                        locBtn.textContent = '📍';
                        lookup({ lat: position.coords.latitude, lon: position.coords.longitude });
                    },
                    function () {
                        locBtn.disabled = false;
                        locBtn.textContent = '📍';
                        notice("We couldn't get your location. Try entering a city and state instead.");
                    },
                    { maximumAge: 600000, timeout: 15000 }
                );
            });
        }

        history.replaceState({ ctms: true }, '', window.location.href);
        window.addEventListener('popstate', function () {
            lookup(paramsFromSearch(window.location.search), { fromHistory: true });
        });
    }

    // ---- Forecast chart tooltip ---------------------------------------------

    function initForecast() {
        var chart = document.querySelector('.forecast__chart');
        if (!chart || chart.dataset.ready) {
            return;
        }
        chart.dataset.ready = '1';

        var svg = chart.querySelector('svg');
        var hits = Array.prototype.slice.call(chart.querySelectorAll('.forecast__hit'));
        var tooltip = chart.querySelector('.forecast__tooltip');
        var cursor = chart.querySelector('.forecast__cursor');
        var viewWidth = svg.viewBox.baseVal.width;
        var active = -1;

        function show(index) {
            var hit = hits[index];
            if (!hit) {
                return;
            }
            active = index;
            hits.forEach(function (h) { h.classList.toggle('is-active', h === hit); });

            var x = parseFloat(hit.getAttribute('x')) + parseFloat(hit.getAttribute('width')) / 2;
            cursor.setAttribute('x1', x);
            cursor.setAttribute('x2', x);
            cursor.hidden = false;

            tooltip.textContent = '';
            var value = document.createElement('strong');
            value.textContent = formatTemp(parseFloat(hit.getAttribute('data-c')));
            var meta = document.createElement('span');
            meta.textContent = hit.getAttribute('data-label') + ' · ' + hit.getAttribute('data-tier-name');
            tooltip.appendChild(value);
            tooltip.appendChild(meta);
            tooltip.hidden = false;

            var px = x / viewWidth * svg.clientWidth;
            var half = tooltip.offsetWidth / 2;
            tooltip.style.left = Math.max(half, Math.min(svg.clientWidth - half, px)) + 'px';
        }

        function hide() {
            active = -1;
            tooltip.hidden = true;
            cursor.hidden = true;
            hits.forEach(function (h) { h.classList.remove('is-active'); });
        }

        svg.addEventListener('pointermove', function (event) {
            var index = event.target.getAttribute && event.target.getAttribute('data-index');
            if (index !== null && index !== undefined) {
                show(parseInt(index, 10));
            }
        });
        svg.addEventListener('pointerdown', function (event) {
            var index = event.target.getAttribute && event.target.getAttribute('data-index');
            if (index !== null && index !== undefined) {
                show(parseInt(index, 10));
            }
        });
        svg.addEventListener('pointerleave', function (event) {
            if (event.pointerType === 'mouse') {
                hide();
            }
        });
        chart.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') {
                event.preventDefault();
                var step = event.key === 'ArrowRight' ? 1 : -1;
                show(Math.max(0, Math.min(hits.length - 1, (active < 0 ? 0 : active + step))));
            } else if (event.key === 'Escape') {
                hide();
            }
        });
        chart.addEventListener('focus', function () { if (active < 0) { show(0); } });
        chart.addEventListener('blur', hide);
        document.addEventListener('ctms:units', function () { if (active >= 0) { show(active); } });
    }

    // ---- Boot ---------------------------------------------------------------

    function boot() {
        syncUnitButtons();
        document.querySelectorAll('.units-toggle [data-units]').forEach(function (button) {
            button.addEventListener('click', function () { setUnits(button.getAttribute('data-units')); });
        });
        initLookupForm();
        initForecast();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
