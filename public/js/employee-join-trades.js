(function (root) {
    'use strict';

    // A visible native picker plus a text value works in mobile and Kakao browsers
    // where datalist suggestions may be hidden. Only the text value is submitted.
    root.createEmployeeTradePicker = function (config) {
        var choice = config.choice, input = config.input, status = config.status, inputLabel = config.inputLabel;
        var manualChosen = false;
        var requestId = 0, labels, names = [], activeSite = null;
        var cache = {};
        if (config.initialSite) cache[config.initialSite] = config.initialTrades;

        function matchInput() {
            var value = input.value.trim();
            choice.value = manualChosen ? '__other__' : (names.indexOf(value) !== -1 ? value : (value ? '__other__' : ''));
            showManualInput();
        }
        function showManualInput() {
            var manual = choice.value === '__other__';
            input.type = manual ? 'text' : 'hidden';
            input.required = manual;
            inputLabel.hidden = !manual;
        }
        function render(trades) {
            names = trades;
            choice.replaceChildren();
            function option(value, label) {
                var item = document.createElement('option');
                item.value = value; item.textContent = label; choice.appendChild(item);
            }
            option('', labels.tradePlaceholder);
            names.forEach(function (name) { option(name, name); });
            option('__other__', labels.tradeOther);
            matchInput();
        }
        choice.addEventListener('change', function () {
            manualChosen = choice.value === '__other__';
            input.value = choice.value === '__other__' ? '' : choice.value;
            showManualInput();
            if (choice.value === '__other__') input.focus();
        });
        input.addEventListener('input', matchInput);

        return {
            refresh: async function (site, translations) {
                labels = translations;
                var current = ++requestId;
                var previousSite = activeSite;
                activeSite = site;
                status.textContent = '';
                render(cache[site] || config.defaults);
                if (!config.urls[site] || (cache[site] && previousSite === site)) return;
                status.textContent = labels.tradeLoading;
                try {
                    var response = await fetch(config.urls[site], { headers: { Accept: 'application/json' } });
                    if (!response.ok) throw new Error('Trade list unavailable');
                    var data = await response.json();
                    if (!Array.isArray(data.trades) || !data.trades.every(function (t) { return typeof t === 'string'; })) {
                        throw new Error('Invalid trade list');
                    }
                    if (current !== requestId) return;
                    cache[site] = data.trades;
                    render(data.trades);
                    status.textContent = '';
                } catch (error) {
                    if (current !== requestId) return;
                    // Manual entry remains available; delayed responses never overwrite input.
                    status.textContent = labels.tradeLoadFailed;
                }
            }
        };
    };
})(window);
