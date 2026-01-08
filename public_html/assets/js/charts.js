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

  // Visitors: users vs sessions
  (function () {
    var c = ctx('chartVisitors');
    if (!c) return;
    var v = snap.analytics.visitors_overview || {};
    var totals = v.totals || v;
    var daily = Array.isArray(v.daily) ? v.daily : [];

    if (daily.length) {
      new Chart(c, {
        type: 'line',
        data: {
          labels: daily.map(function (x) { return x.date; }),
          datasets: [{
            label: 'Users',
            data: daily.map(function (x) { return x.users || 0; }),
            borderColor: COLORS[0],
            backgroundColor: rgba(COLORS[0], 0.15),
            tension: 0.25,
            fill: true
          }, {
            label: 'Sessions',
            data: daily.map(function (x) { return x.sessions || 0; }),
            borderColor: COLORS[1],
            backgroundColor: rgba(COLORS[1], 0.12),
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
      return;
    }

    new Chart(c, {
      type: 'bar',
      data: {
        labels: ['Users', 'Sessions', 'New users'],
        datasets: [{
          label: 'Count',
          data: [totals.users || 0, totals.sessions || 0, totals.new_users || 0],
          backgroundColor: [rgba(COLORS[0], 0.5), rgba(COLORS[1], 0.5), rgba(COLORS[2], 0.5)],
          borderColor: [COLORS[0], COLORS[1], COLORS[2]],
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

  // Channels: users per channel
  (function () {
    var c = ctx('chartChannels');
    if (!c) return;
    var ch = snap.analytics.traffic_channels || [];
    var labels = ch.map(function (x) { return x.channel; });
    var data = ch.map(function (x) { return x.users; });
    var bg = labels.map(function (_, i) { return rgba(COLORS[i % COLORS.length], 0.55); });
    var br = labels.map(function (_, i) { return COLORS[i % COLORS.length]; });

    new Chart(c, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [{
          label: 'Users',
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

  // Sales: revenue/transactions
  (function () {
    var c = ctx('chartSales');
    if (!c) return;
    var s = snap.analytics.sales;
    if (!s) return;
    new Chart(c, {
      type: 'doughnut',
      data: {
        labels: ['Revenue ($)', 'Transactions'],
        datasets: [{
          data: [s.revenue || 0, s.transactions || 0],
          backgroundColor: [rgba(COLORS[0], 0.55), rgba(COLORS[4], 0.55)],
          borderColor: [COLORS[0], COLORS[4]],
          borderWidth: 1
        }]
      },
      options: {
        responsive: true,
        plugins: {
          legend: { labels: { color: 'rgba(232,238,252,0.9)' } }
        }
      }
    });
  })();

  // SEO: clicks vs impressions
  (function () {
    var c = ctx('chartSeo');
    if (!c) return;
    var seo = snap.analytics.seo_summary || {};
    new Chart(c, {
      type: 'bar',
      data: {
        labels: ['Clicks', 'Impressions'],
        datasets: [{
          label: 'SEO',
          data: [seo.clicks || 0, seo.impressions || 0],
          backgroundColor: [rgba(COLORS[2], 0.55), rgba(COLORS[1], 0.55)],
          borderColor: [COLORS[2], COLORS[1]],
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

