/* A screen change must be a browser history entry, not just an innerHTML change. */
(function (global) {
    'use strict';
    function create(options) {
        var current = null, sequence = 0;
        function save() {
            if (!current) return;
            current.snapshot = options.capture ? options.capture() : null;
            global.history.replaceState(Object.assign({}, global.history.state, { erpNavigation: current }), '', global.location.href);
        }
        function navigate(route, settings) {
            settings = settings || {};
            var same = current && JSON.stringify(current.route) === JSON.stringify(route);
            if (same && !settings.refresh) return false;
            save();
            var previous = current && (settings.replace || same ? current.previous : current.id);
            current = { id: Date.now() + '-' + (++sequence), previous: previous || null, route: route, snapshot: null };
            var method = settings.replace || same ? 'replaceState' : 'pushState';
            global.history[method](Object.assign({}, global.history.state, { erpNavigation: current }), '', options.url(route));
            if (!settings.silent) options.render(route, null);
            return true;
        }
        global.addEventListener('popstate', function (event) {
            // The destination URL is authoritative, including after a reload/deep link.
            current = event.state && event.state.erpNavigation;
            if (!current) current = { id: Date.now() + '-' + (++sequence), route: options.read(), snapshot: null };
            options.render(current.route, current.snapshot);
        });
        global.addEventListener('pagehide', save);
        return {
            start: function () {
                var stored = global.history.state && global.history.state.erpNavigation;
                var fromUrl = options.read();
                current = stored && Object.keys(fromUrl).every(function(key){return (stored.route[key] ?? null)===(fromUrl[key] ?? null);})
                    ? stored : { id: Date.now() + '-' + (++sequence), route: options.read(), snapshot: null };
                global.history.replaceState(Object.assign({}, global.history.state, { erpNavigation: current }), '', options.url(current.route));
                options.render(current.route, current.snapshot);
            },
            navigate: navigate,
            save: save,
            back: function (fallback) {
                if (current && current.previous) global.history.back();
                else navigate(fallback, { replace: true });
            },
            route: function () { return current && current.route; }
        };
    }
    global.ERPHistory = { create: create };
    if (typeof module !== 'undefined') module.exports = { create: create };
})(typeof window !== 'undefined' ? window : globalThis);
