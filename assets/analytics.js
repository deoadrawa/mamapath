(function () {
    'use strict';

    var preset = document.getElementById('anPreset');
    var from = document.getElementById('anFrom');
    var to = document.getElementById('anTo');
    if (preset && from && to) {
        var sync = function () {
            var custom = preset.value === 'custom';
            from.readOnly = !custom;
            to.readOnly = !custom;
            from.classList.toggle('an-locked', !custom);
            to.classList.toggle('an-locked', !custom);
        };
        preset.addEventListener('change', sync);
        [from, to].forEach(function (el) {
            el.addEventListener('change', function () { preset.value = 'custom'; sync(); });
        });
        sync();
    }

    var search = document.getElementById('anSearch');
    if (search) {
        search.addEventListener('input', function () {
            var q = search.value.trim().toLowerCase();
            var shown = 0;
            document.querySelectorAll('[data-cat]').forEach(function (cat) {
                var any = false;
                cat.querySelectorAll('.an-card').forEach(function (card) {
                    var match = q === '' || card.dataset.search.indexOf(q) !== -1;
                    card.hidden = !match;
                    any = any || match;
                });
                cat.hidden = !any;
                shown += any ? 1 : 0;
            });
            var empty = document.getElementById('anEmpty');
            if (empty) { empty.hidden = shown > 0; }
        });
    }

    var dataEl = document.getElementById('anData');
    if (!dataEl || typeof Chart === 'undefined') { return; }
    var charts = JSON.parse(dataEl.textContent || '{}');
    var palette = ['#1E40AF', '#10B981', '#F59E0B', '#EF4444', '#7C3AED', '#0D9488', '#DB2777', '#0EA5E9', '#64748B', '#84CC16', '#F97316', '#14B8A6'];
    var dark = document.body.classList.contains('dark');
    var ink = dark ? '#CBD5E1' : '#475569';
    var grid = dark ? '#334155' : '#E2E8F0';

    function fmt(value, kind) {
        if (value === null || value === undefined) { return '–'; }
        var n = Number(value);
        if (kind === 'pct') { return n.toFixed(1) + '%'; }
        if (kind === 'dec') { return n.toFixed(1); }
        if (kind === 'dec2') { return n.toFixed(2); }
        if (kind === 'days') { return n.toFixed(1) + ' d'; }
        return Math.abs(n) >= 1000 ? n.toLocaleString() : (Number.isInteger(n) ? String(n) : n.toFixed(1));
    }

    Object.keys(charts).forEach(function (id) {
        var spec = charts[id];
        var canvas = document.getElementById('chart-' + id);
        if (!canvas) { return; }
        var type = spec.type;
        var horizontal = type === 'hbar';
        var isDoughnut = type === 'doughnut';
        var jsType = isDoughnut ? 'doughnut' : (type === 'line' ? 'line' : 'bar');

        var datasets = spec.datasets.map(function (ds) {
            var base = { label: ds.label, data: ds.data };
            if (isDoughnut) {
                base.backgroundColor = spec.labels.map(function (_, i) { return palette[i % palette.length]; });
                base.borderColor = '#FFFFFF';
                base.borderWidth = 2;
                return base;
            }
            base.backgroundColor = ds.color;
            base.borderColor = ds.color;
            if (jsType === 'line') {
                base.borderWidth = 2;
                base.tension = 0;
                base.pointRadius = spec.labels.length > 40 ? 0 : 3;
                base.pointBackgroundColor = ds.color;
                base.fill = false;
                base.spanGaps = false;
                if (ds.cmp) { base.borderDash = [6, 4]; }
            } else {
                base.borderWidth = 0;
                base.maxBarThickness = 36;
            }
            return base;
        });

        var scales = {};
        if (!isDoughnut) {
            var valueAxis = { beginAtZero: true, grid: { color: grid }, ticks: { color: ink, callback: function (v) { return fmt(v, spec.fmt); } } };
            var labelAxis = { grid: { display: false }, ticks: { color: ink, autoSkip: true, maxRotation: horizontal ? 0 : 60 } };
            if (spec.stacked) { valueAxis.stacked = true; labelAxis.stacked = true; }
            scales = horizontal ? { x: valueAxis, y: labelAxis } : { x: labelAxis, y: valueAxis };
        }

        new Chart(canvas, {
            type: jsType,
            data: { labels: spec.labels, datasets: datasets },
            options: {
                indexAxis: horizontal ? 'y' : 'x',
                responsive: true,
                maintainAspectRatio: false,
                animation: false,
                interaction: { mode: isDoughnut ? 'nearest' : 'index', intersect: false },
                scales: scales,
                plugins: {
                    legend: { display: datasets.length > 1 || isDoughnut, position: 'bottom', labels: { color: ink, boxWidth: 12, boxHeight: 12 } },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                var v = isDoughnut ? ctx.parsed : (horizontal ? ctx.parsed.x : ctx.parsed.y);
                                return ctx.dataset.label + ': ' + fmt(v, spec.fmt);
                            }
                        }
                    }
                },
                cutout: isDoughnut ? '58%' : undefined
            }
        });
    });
}());
