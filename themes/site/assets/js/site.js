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

    // Mirrors Units::snow(): tenths below 1″ (or 2 cm), whole numbers above.
    function formatSnow(inches) {
        if (units() === 'c') {
            var cm = inches * 2.54;
            return cm.toFixed(cm > 0 && cm < 2 ? 1 : 0) + ' cm';
        }
        return inches.toFixed(inches > 0 && inches < 1 ? 1 : 0) + '″';
    }

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
        ['address', 'lat', 'lon', 'resort'].forEach(function (key) {
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
        document.querySelectorAll('.forecast__chart').forEach(initChart);
    }

    // Hover/tap/arrow-key tooltip for a server-rendered chart. Hit rects carry
    // data-c (wet-bulb °C) or data-in (snowfall inches) plus a label.
    function initChart(chart) {
        if (chart.dataset.ready) {
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
            var snow = hit.hasAttribute('data-in');
            value.textContent = snow
                ? formatSnow(parseFloat(hit.getAttribute('data-in'))) + ' new snow'
                : formatTemp(parseFloat(hit.getAttribute('data-c')));
            var meta = document.createElement('span');
            meta.textContent = hit.getAttribute('data-label') + (snow ? '' : ' · ' + hit.getAttribute('data-tier-name'));
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

    // ---- Google Maps loader -------------------------------------------------

    var mapsPromise = null;

    function meta(name) {
        var el = document.querySelector('meta[name="' + name + '"]');
        return el ? el.getAttribute('content') : '';
    }

    /**
     * Load the Maps JS API once (async, with importLibrary support).
     * Resolves with google.maps; rejects if there is no key or it fails.
     */
    function loadMaps() {
        if (mapsPromise) {
            return mapsPromise;
        }
        mapsPromise = new Promise(function (resolve, reject) {
            var key = meta('ctms-maps-key');
            if (!key) {
                reject(new Error('No Google Maps browser key configured'));
                return;
            }
            window.__ctmsMapsReady = function () { resolve(window.google.maps); };
            var script = document.createElement('script');
            script.src = 'https://maps.googleapis.com/maps/api/js?' + new URLSearchParams({
                key: key,
                v: 'weekly',
                loading: 'async',
                callback: '__ctmsMapsReady'
            });
            script.async = true;
            script.onerror = function () { reject(new Error('Google Maps failed to load')); };
            document.head.appendChild(script);
        });
        return mapsPromise;
    }
    CTMS.loadMaps = loadMaps;
    CTMS.meta = meta;

    function readJson(id) {
        var el = document.getElementById(id);
        if (!el) {
            return null;
        }
        try {
            return JSON.parse(el.textContent);
        } catch (e) {
            return null;
        }
    }
    CTMS.readJson = readJson;

    // ---- Search suggestions (resorts first, then Google places) -------------

    function initSearch() {
        var input = document.getElementById('address');
        var box = document.querySelector('.suggest');
        var list = document.getElementById('suggestions');
        if (!input || !box || !list) {
            return;
        }

        var attribution = box.querySelector('.suggest__attribution');
        var resorts = (readJson('resortData') || []).map(function (r) {
            return { type: 'resort', slug: r.slug, label: r.name, sub: r.state, hay: (r.name + ' ' + r.state).toLowerCase() };
        });
        var items = [];
        var active = -1;
        var seq = 0;
        var timer = null;
        var places = null;
        var placesFailed = false;
        var sessionToken = null;

        function ensurePlaces() {
            if (places || placesFailed) {
                return Promise.resolve(places);
            }
            return loadMaps()
                .then(function (maps) { return maps.importLibrary('places'); })
                .then(function (lib) { places = lib; return lib; })
                .catch(function (e) {
                    placesFailed = true;
                    console.info('Place suggestions unavailable:', e.message);
                    return null;
                });
        }

        function matchResorts(query) {
            var tokens = query.toLowerCase().split(/[\s,]+/).filter(Boolean);
            var q = query.toLowerCase();
            return resorts
                .filter(function (r) {
                    return tokens.every(function (t) { return r.hay.indexOf(t) !== -1; });
                })
                .sort(function (a, b) {
                    var aStarts = a.hay.indexOf(q) === 0 ? 0 : 1;
                    var bStarts = b.hay.indexOf(q) === 0 ? 0 : 1;
                    return aStarts - bStarts || a.label.localeCompare(b.label);
                })
                .slice(0, 5);
        }

        function render(fromPlaces) {
            list.textContent = '';
            items.forEach(function (item, i) {
                var li = document.createElement('li');
                li.id = 'suggestion-' + i;
                li.className = 'suggest__item';
                li.setAttribute('role', 'option');
                li.setAttribute('aria-selected', i === active ? 'true' : 'false');
                li.dataset.index = i;

                var icon = document.createElement('span');
                icon.className = 'suggest__icon';
                icon.setAttribute('aria-hidden', 'true');
                icon.textContent = item.type === 'resort' ? '⛷️' : '📍';
                var main = document.createElement('span');
                main.className = 'suggest__main';
                main.textContent = item.label;
                var sub = document.createElement('span');
                sub.className = 'suggest__sub';
                sub.textContent = item.type === 'resort' ? 'Ski resort · ' + item.sub : (item.sub || '');

                li.appendChild(icon);
                li.appendChild(main);
                li.appendChild(sub);
                list.appendChild(li);
            });

            var open = items.length > 0;
            box.hidden = !open;
            input.setAttribute('aria-expanded', open ? 'true' : 'false');
            attribution.hidden = !fromPlaces;
            if (active >= 0) {
                input.setAttribute('aria-activedescendant', 'suggestion-' + active);
            } else {
                input.removeAttribute('aria-activedescendant');
            }
        }

        function close() {
            items = [];
            active = -1;
            render(false);
        }

        function update() {
            var query = input.value.trim();
            var mine = ++seq;
            active = -1;
            if (!query) {
                close();
                return;
            }

            items = matchResorts(query);
            render(false);

            if (query.length < 3) {
                return;
            }
            ensurePlaces().then(function (lib) {
                if (!lib || mine !== seq) {
                    return null;
                }
                sessionToken = sessionToken || new lib.AutocompleteSessionToken();
                return lib.AutocompleteSuggestion.fetchAutocompleteSuggestions({
                    input: query,
                    sessionToken: sessionToken,
                    includedRegionCodes: ['us']
                });
            }).then(function (response) {
                if (!response || mine !== seq) {
                    return;
                }
                var placeItems = (response.suggestions || [])
                    .map(function (s) { return s.placePrediction; })
                    .filter(Boolean)
                    .slice(0, 4)
                    .map(function (p) {
                        return {
                            type: 'place',
                            label: p.mainText ? p.mainText.toString() : p.text.toString(),
                            sub: p.secondaryText ? p.secondaryText.toString() : '',
                            query: p.text.toString()
                        };
                    });
                items = items.concat(placeItems);
                render(placeItems.length > 0);
            }).catch(function (e) {
                console.info('Place suggestions failed:', e.message);
            });
        }

        function choose(item) {
            close();
            sessionToken = null;
            if (item.type === 'resort') {
                input.value = item.label + ', ' + item.sub;
                lookup({ resort: item.slug });
            } else {
                input.value = item.query || item.label;
                lookup({ address: item.query || item.label });
            }
        }

        input.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(update, 150);
        });
        input.addEventListener('focus', ensurePlaces);
        input.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                if (!items.length) {
                    return;
                }
                event.preventDefault();
                // Cycle through the items and back to the typed text (-1).
                var next = active + (event.key === 'ArrowDown' ? 1 : -1);
                if (next >= items.length) {
                    next = -1;
                } else if (next < -1) {
                    next = items.length - 1;
                }
                active = next;
                render(!attribution.hidden);
            } else if (event.key === 'Enter' && active >= 0 && items[active]) {
                event.preventDefault();
                choose(items[active]);
            } else if (event.key === 'Escape') {
                close();
            }
        });
        input.addEventListener('blur', function () {
            setTimeout(close, 120);
        });
        list.addEventListener('mousedown', function (event) {
            event.preventDefault(); // Keep focus in the input.
        });
        list.addEventListener('click', function (event) {
            var li = event.target.closest('[data-index]');
            if (li && items[li.dataset.index]) {
                choose(items[li.dataset.index]);
            }
        });
    }

    // ---- Saved places, favourite and share -------------------------------------

    var MAX_RECENTS = 5;
    var MAX_FAVORITES = 12;

    /** Saved places are {label, search} where search is the share URL's query string. */
    function searchOf(shareUrl) {
        try {
            return new URL(shareUrl, window.location.href).search;
        } catch (e) {
            return '';
        }
    }

    function currentAnswer() {
        var answer = document.querySelector('#result .hero-answer');
        return answer ? { label: answer.dataset.place, search: searchOf(answer.dataset.shareUrl), url: answer.dataset.shareUrl } : null;
    }

    function initSaved() {
        var container = document.getElementById('savedPlaces');
        var resultEl = document.getElementById('result');
        if (!container || !resultEl) {
            return;
        }

        function favorites() { return store.get('ctms.favorites', []); }
        function recents() { return store.get('ctms.recents', []); }
        function isFavorite(search) {
            return favorites().some(function (f) { return f.search === search; });
        }

        function chip(place, starred) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'saved__chip';
            button.textContent = (starred ? '★ ' : '') + place.label;
            button.addEventListener('click', function () {
                document.getElementById('address').value = place.label;
                lookup(paramsFromSearch(place.search));
            });
            return button;
        }

        function renderChips() {
            var favs = favorites();
            var recent = recents().filter(function (r) { return !isFavorite(r.search); });
            container.textContent = '';
            favs.forEach(function (f) { container.appendChild(chip(f, true)); });
            recent.forEach(function (r) { container.appendChild(chip(r, false)); });
            if (recent.length) {
                var clear = document.createElement('button');
                clear.type = 'button';
                clear.className = 'saved__clear';
                clear.textContent = 'Clear recent';
                clear.addEventListener('click', function () {
                    store.set('ctms.recents', []);
                    renderChips();
                });
                container.appendChild(clear);
            }
            container.hidden = !(favs.length || recent.length);
        }

        function syncFavoriteButton() {
            var button = resultEl.querySelector('[data-action="favorite"]');
            var place = currentAnswer();
            if (!button || !place) {
                return;
            }
            var saved = isFavorite(place.search);
            button.setAttribute('aria-pressed', saved ? 'true' : 'false');
            button.textContent = saved ? '★ Saved' : '☆ Save';
        }

        function rememberRecent() {
            var place = currentAnswer();
            if (!place || !place.search) {
                return;
            }
            var list = recents().filter(function (r) { return r.search !== place.search; });
            list.unshift({ label: place.label, search: place.search });
            store.set('ctms.recents', list.slice(0, MAX_RECENTS));
        }

        resultEl.addEventListener('click', function (event) {
            var button = event.target.closest('[data-action]');
            var place = currentAnswer();
            if (!button || !place) {
                return;
            }

            if (button.dataset.action === 'favorite') {
                var list = favorites();
                if (isFavorite(place.search)) {
                    list = list.filter(function (f) { return f.search !== place.search; });
                } else {
                    list.unshift({ label: place.label, search: place.search });
                }
                if (!store.set('ctms.favorites', list.slice(0, MAX_FAVORITES))) {
                    notice("Your browser isn't letting this site save places.");
                }
                syncFavoriteButton();
                renderChips();
            } else if (button.dataset.action === 'share') {
                share(place, button);
            }
        });

        document.addEventListener('ctms:result', function (event) {
            if (event.detail && event.detail.ok) {
                rememberRecent();
            }
            syncFavoriteButton();
            renderChips();
        });

        if (currentAnswer()) {
            rememberRecent();
        }
        syncFavoriteButton();
        renderChips();
    }

    function share(place, button) {
        var title = document.title;
        if (navigator.share) {
            navigator.share({ title: title, url: place.url }).catch(function () {});
            return;
        }
        var done = function () {
            var original = button.textContent;
            button.textContent = 'Link copied';
            setTimeout(function () { button.textContent = original; }, 2000);
        };
        var fallback = function () { notice('Copy this link to share: ' + place.url); };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(place.url).then(done, fallback);
        } else {
            fallback();
        }
    }

    // ---- Map ------------------------------------------------------------------

    var TIER_NAMES = {
        ideal: 'Ideal snowmaking', great: 'Great snowmaking', marginal: 'Marginal snowmaking',
        too_warm: 'Too warm', no_chance: 'Too warm'
    };
    var STALE_MS = 3 * 3600 * 1000;

    function initMap() {
        var el = document.getElementById('map');
        if (!el) {
            return;
        }

        var points = readJson('mapData') || [];
        var started = false;
        var map = null;
        var libs = null;
        var markers = [];
        var current = null;
        var pendingPin = null;

        function round3(n) {
            return Math.round(n * 1000) / 1000;
        }

        function pinElement(point, isCurrent) {
            var pin = document.createElement('div');
            pin.className = 'map-pin';
            if (point.type === 'resort') {
                pin.classList.add('map-pin--resort');
            }
            if (isCurrent) {
                pin.classList.add('map-pin--current');
            }
            if (point.tier) {
                pin.setAttribute('data-tier', point.tier);
            } else {
                pin.classList.add('map-pin--unknown');
            }
            if (!isCurrent && (!point.checkedAt || Date.now() - Date.parse(point.checkedAt) > STALE_MS)) {
                pin.classList.add('map-pin--stale');
            }
            return pin;
        }

        function title(point) {
            var fresh = point.checkedAt && Date.now() - Date.parse(point.checkedAt) <= STALE_MS;
            return point.label + (point.tier ? ' — ' + TIER_NAMES[point.tier] + (fresh ? '' : ' when last checked') : '');
        }

        function addPoint(point) {
            var marker = new libs.marker.AdvancedMarkerElement({
                map: map,
                position: { lat: point.lat, lng: point.lng },
                content: pinElement(point, false),
                title: title(point),
                gmpClickable: true
            });
            marker.addEventListener('gmp-click', function () {
                if (point.type === 'resort') {
                    lookup({ resort: point.slug });
                } else {
                    lookup({ lat: point.lat, lon: point.lng });
                }
            });
            markers.push({ marker: marker, point: point });
        }

        /** Highlight the place just checked and refresh any matching pin's tier. */
        function showResult(pin) {
            if (!map) {
                pendingPin = pin;
                return;
            }
            var position = { lat: pin.lat, lng: pin.lng };
            var point = { type: 'current', label: 'Checked here', tier: pin.tier, lat: pin.lat, lng: pin.lng };
            if (current) {
                current.position = position;
                current.content = pinElement(point, true);
            } else {
                current = new libs.marker.AdvancedMarkerElement({
                    map: map, position: position, content: pinElement(point, true), title: 'Checked here', zIndex: 1000
                });
            }

            markers.forEach(function (m) {
                if (Math.abs(m.point.lat - pin.lat) < 0.01 && Math.abs(m.point.lng - pin.lng) < 0.01) {
                    m.point.tier = pin.tier;
                    m.point.checkedAt = new Date().toISOString();
                    m.marker.content = pinElement(m.point, false);
                    m.marker.title = title(m.point);
                }
            });

            map.panTo(position);
            if (map.getZoom() < 7) {
                map.setZoom(7);
            }
        }

        function build() {
            var wide = window.matchMedia('(min-width: 992px)').matches;
            map = new libs.maps.Map(el, {
                center: { lat: 39.5, lng: -98.35 },
                zoom: wide ? 4 : 3,
                mapId: meta('ctms-map-id') || 'DEMO_MAP_ID',
                colorScheme: 'FOLLOW_SYSTEM',
                clickableIcons: false,
                streetViewControl: false,
                mapTypeControl: false,
                fullscreenControl: true
            });

            CTMS.map = map;
            points.forEach(addPoint);

            map.addListener('click', function (event) {
                if (event.latLng) {
                    lookup({ lat: round3(event.latLng.lat()), lon: round3(event.latLng.lng()) });
                }
            });

            var answer = document.querySelector('#result .hero-answer');
            if (pendingPin) {
                showResult(pendingPin);
            } else if (answer && answer.dataset.pinLat) {
                showResult({ lat: parseFloat(answer.dataset.pinLat), lng: parseFloat(answer.dataset.pinLng), tier: answer.dataset.tier });
            }
        }

        function start() {
            if (started) {
                return;
            }
            started = true;
            loadMaps()
                .then(function (maps) { return Promise.all([maps.importLibrary('maps'), maps.importLibrary('marker')]); })
                .then(function (loaded) {
                    libs = { maps: loaded[0], marker: loaded[1] };
                    build();
                })
                .catch(function (e) {
                    el.classList.add('is-unavailable');
                    el.textContent = 'The map is unavailable right now.';
                    console.info(e.message);
                });
        }

        // Load only once the map is on screen, the page has painted and the browser
        // is idle, so the map never delays the answer.
        var idle = window.requestIdleCallback || function (cb) { return setTimeout(cb, 1200); };
        function afterPaint(cb) {
            function go() {
                requestAnimationFrame(function () { requestAnimationFrame(function () { idle(cb, { timeout: 3000 }); }); });
            }
            if (document.readyState === 'complete') {
                go();
            } else {
                window.addEventListener('load', go, { once: true });
            }
        }
        if ('IntersectionObserver' in window) {
            var observer = new IntersectionObserver(function (entries) {
                if (entries.some(function (e) { return e.isIntersecting; })) {
                    observer.disconnect();
                    afterPaint(start);
                }
            });
            observer.observe(el);
        } else {
            afterPaint(start);
        }

        document.addEventListener('ctms:result', function (event) {
            if (event.detail && event.detail.pin) {
                showResult(event.detail.pin);
            }
        });
    }

    // ---- Boot ---------------------------------------------------------------

    // ---- Email alerts signup ---------------------------------------------------
    // Delegated, because the result (and its form) is re-rendered by lookups.

    function initAlerts() {
        document.addEventListener('click', function (event) {
            var button = event.target.closest && event.target.closest('[data-action="alerts"]');
            if (!button) {
                return;
            }
            var form = document.getElementById('alertsForm');
            if (!form) {
                return;
            }
            form.hidden = !form.hidden;
            button.setAttribute('aria-expanded', form.hidden ? 'false' : 'true');
            if (!form.hidden) {
                form.querySelector('input[type="email"]').focus();
            }
        });

        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (!form.classList || !form.classList.contains('alerts-form')) {
                return;
            }
            event.preventDefault();
            if (!window.oc) {
                return;
            }

            var message = form.querySelector('.alerts-form__message');
            var submit = form.querySelector('[type="submit"]');
            var show = function (text, ok) {
                message.textContent = text;
                message.hidden = false;
                message.classList.toggle('is-error', !ok);
            };

            var email = form.elements.email.value.trim();
            if (!form.elements.email.checkValidity() || !email) {
                show('Please enter a valid email address.', false);
                form.elements.email.focus();
                return;
            }

            submit.disabled = true;
            oc.ajax('Alerts::onSubscribe', {
                data: {
                    resort: form.getAttribute('data-resort'),
                    email: email,
                    snowmaking: form.elements.snowmaking.checked ? 1 : 0,
                    snowmaking_min_tier: form.elements.snowmaking_min_tier.value,
                    powder: form.elements.powder.checked ? 1 : 0,
                    powder_threshold_in: form.elements.powder_threshold_in.value,
                    units: units()
                },
                successFunc: function (el, data) {
                    data = data || {};
                    show(data.message || 'Check your inbox to confirm.', !!data.ok);
                    if (data.ok) {
                        form.elements.email.value = '';
                    }
                },
                errorFunc: function () {
                    show('Something went wrong. Please try again.', false);
                    return false;
                },
                completeFunc: function () {
                    submit.disabled = false;
                }
            });
        });
    }

    function boot() {
        syncUnitButtons();
        document.querySelectorAll('.units-toggle [data-units]').forEach(function (button) {
            button.addEventListener('click', function () { setUnits(button.getAttribute('data-units')); });
        });
        initLookupForm();
        initSearch();
        initForecast();
        initSaved();
        initAlerts();
        initMap();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
