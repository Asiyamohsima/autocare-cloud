// AutoCare Cloud - Chart.js drawing code. Reads the CHART_DATA object set by the page.
(function () {
    if (typeof Chart === 'undefined' || typeof CHART_DATA === 'undefined') { return; }

    var rupee   = function (v) { return '\u20B9' + Number(v).toLocaleString('en-IN'); };
    var palette = ['#2563eb', '#ef4444', '#06b6d4', '#16a34a', '#f59e0b', '#8b5cf6', '#ec4899', '#64748b', '#14b8a6'];
    Chart.defaults.font.family = '"Segoe UI", system-ui, sans-serif';

    function bar(id, labels, values, color) {
        var el = document.getElementById(id);
        if (!el || !labels) { return; }
        new Chart(el, {
            type: 'bar',
            data: { labels: labels, datasets: [{ data: values, backgroundColor: color, borderRadius: 4 }] },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return rupee(c.parsed.y); } } } },
                scales: { y: { beginAtZero: true, ticks: { callback: rupee } } }
            }
        });
    }

    function doughnut(id, labels, values) {
        var el = document.getElementById(id);
        if (!el || !labels || !labels.length) { return; }
        new Chart(el, {
            type: 'doughnut',
            data: { labels: labels, datasets: [{ data: values, backgroundColor: palette }] },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' },
                           tooltip: { callbacks: { label: function (c) { return c.label + ': ' + rupee(c.parsed); } } } }
            }
        });
    }

    function stacked(id, labels, sets) {
        var el = document.getElementById(id);
        if (!el || !labels || !labels.length) { return; }
        var colors = { Maintenance: '#2563eb', Repairs: '#ef4444', Fuel: '#06b6d4', Insurance: '#16a34a', Other: '#94a3b8' };
        new Chart(el, {
            type: 'bar',
            data: { labels: labels, datasets: sets.map(function (s) {
                return { label: s.label, data: s.data, backgroundColor: colors[s.label] || '#64748b' };
            }) },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' },
                           tooltip: { callbacks: { label: function (c) { return c.dataset.label + ': ' + rupee(c.parsed.y); } } } },
                scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true, ticks: { callback: rupee } } }
            }
        });
    }

    var d = CHART_DATA;
    bar('chartMaintenance', d.months, d.maintenance, '#2563eb');
    bar('chartFuel', d.months, d.fuel, '#06b6d4');
    bar('chartMonthly', d.monthLabels, d.monthValues, '#2563eb');
    doughnut('chartCategory', d.catLabels, d.catValues);
    stacked('chartVehicles', d.vehLabels, d.vehSets || []);
})();
