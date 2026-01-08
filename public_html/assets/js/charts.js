/* global Chart */
(function () {
  'use strict';

  var snap = window.REPORT_SNAPSHOT;
  if (!snap || !snap.analytics) return;
  if (typeof Chart === 'undefined') return;

  function ctx(id) {
    var el = document.getElementById(id);
    return el ? el.getContext('2d') : null;
  }

  function rgba(hex, a) {
    // hex like #4f8cff
    var h = hex.replace('#', '');
    var r = parseInt(h.slice(0, 2), 16);
    var g = parseInt(h.slice(2, 4), 16);
    var b = parseInt(h.slice(4, 6), 16);
    return 'rgba(' + r + ',' + g + ',' + b + ',' + a + ')';
  }

  var COLORS = ['#4f8cff', '#6ee7ff', '#34d399', '#fbbf24', '#ff5a7a', '#a78bfa'];

  function getCurrentPageKey() {
    try {
      var sp = new URLSearchParams(window.location.search || '');
      var p = (sp.get('page') || '').toLowerCase();
      if (p) return p;
    } catch (_) {}
    var meta = snap.meta || {};
    var pagesMeta = meta.pages || {};
    return (pagesMeta.defaultPage || 'all');
  }

  function getPage() {
    var pages = (snap.analytics && snap.analytics.pages) ? snap.analytics.pages : null;
    if (!pages) return null;
    var key = getCurrentPageKey();
    return pages[key] || pages.all || null;
  }

  function normalizeDaily(arr) {
    if (!Array.isArray(arr)) return [];
    return arr.map(function (x) {
      return {
        date: x && x.date ? String(x.date) : '',
        users: x && typeof x.users !== 'undefined' ? Number(x.users) : 0,
        sessions: x && typeof x.sessions !== 'undefined' ? Number(x.sessions) : 0
      };
    }).filter(function (x) { return !!x.date; });
  }

  // Daily users comparison (this month vs last year).
  (function () {
    var c = ctx('chartPageDailyUsers');
    if (!c) return;
    var page = getPage();
    if (!page) return;
    var daily = page.daily || {};
    var dThis = normalizeDaily(daily.thisMonth);
    var dLast = normalizeDaily(daily.lastYear);
    if (!dThis.length || !dLast.length) return;

    // Map last year by day index (same month length assumption).
    var labels = dThis.map(function (x) { return x.date; });
    var thisUsers = dThis.map(function (x) { return x.users || 0; });
    var lastUsers = labels.map(function (_, i) { return (dLast[i] && dLast[i].users) ? dLast[i].users : 0; });

    new Chart(c, {
      type: 'line',
      data: {
        labels: labels,
        datasets: [{
          label: 'Šis mėnuo (vartotojai)',
          data: thisUsers,
          borderColor: COLORS[0],
          backgroundColor: rgba(COLORS[0], 0.15),
          tension: 0.25,
          fill: true
        }, {
          label: 'Praeitų metų tas pats mėnuo (vartotojai)',
          data: lastUsers,
          borderColor: COLORS[1],
          backgroundColor: rgba(COLORS[1], 0.10),
          tension: 0.25,
          fill: true
        }]
      },
      options: {
        responsive: true,
        plugins: { legend: { labels: { color: 'rgba(232,238,252,0.9)' } } },
        scales: {
          y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.06)' }, ticks: { color: 'rgba(232,238,252,0.85)' } },
          x: { grid: { display: false }, ticks: { color: 'rgba(232,238,252,0.65)' } }
        }
      }
    });
  })();

  // Channels bar (users per channel) — typically on "all" page.
  (function () {
    var c = ctx('chartChannelsUsers');
    if (!c) return;
    var page = getPage();
    if (!page) return;
    var rows = Array.isArray(page.tableRows) ? page.tableRows : [];
    if (!rows.length) return;
    var labels = rows.map(function (r) { return String((r && (r.labelLT || r.channel)) || ''); });
    var data = rows.map(function (r) { return Number((r && r.users) || 0); });
    var bg = labels.map(function (_, i) { return rgba(COLORS[i % COLORS.length], 0.55); });
    var br = labels.map(function (_, i) { return COLORS[i % COLORS.length]; });

    new Chart(c, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [{
          label: 'Vartotojai',
          data: data,
          backgroundColor: bg,
          borderColor: br,
          borderWidth: 1
        }]
      },
      options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
          y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.06)' }, ticks: { color: 'rgba(232,238,252,0.85)' } },
          x: { grid: { display: false }, ticks: { color: 'rgba(232,238,252,0.85)' } }
        }
      }
    });
  })();
})();

