/* global Chart */
(function () {
  'use strict';

  var snap = window.REPORT_SNAPSHOT;
  if (!snap) return;
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

  function isPhase3() {
    return !!(snap && snap.meta && (snap.visitors || snap.behavior || snap.seo));
  }

  function renderPhase3LineChart(canvasId, sectionKey, valueFormat) {
    var c = ctx(canvasId);
    if (!c) return;
    if (!snap[sectionKey] || !snap[sectionKey].chart) return;
    var ch = snap[sectionKey].chart;
    var labels = Array.isArray(ch.labels) ? ch.labels : [];
    var a = Array.isArray(ch.this_month) ? ch.this_month : [];
    var b = Array.isArray(ch.last_year) ? ch.last_year : [];
    var la = ch.label_this_month || 'This month';
    var lb = ch.label_last_year || 'Last year';

    function tickFmt(v) {
      if (valueFormat === 'pct') return Math.round(v * 100) + '%';
      if (valueFormat === 'money') return '€' + Math.round(v);
      return String(Math.round(v));
    }

    new Chart(c, {
      type: 'line',
      data: {
        labels: labels,
        datasets: [{
          label: la,
          data: a,
          borderColor: COLORS[0],
          backgroundColor: rgba(COLORS[0], 0.10),
          tension: 0.25,
          fill: false
        }, {
          label: lb,
          data: b,
          borderColor: COLORS[1],
          backgroundColor: rgba(COLORS[1], 0.10),
          tension: 0.25,
          fill: false
        }]
      },
      options: {
        responsive: true,
        plugins: {
          legend: { labels: { color: 'rgba(232,238,252,0.9)' } },
          tooltip: {
            callbacks: {
              label: function (ctx) {
                return ctx.dataset.label + ': ' + tickFmt(ctx.parsed.y);
              }
            }
          }
        },
        scales: {
          y: {
            beginAtZero: true,
            grid: { color: 'rgba(255,255,255,0.06)' },
            ticks: { color: 'rgba(232,238,252,0.85)', callback: tickFmt }
          },
          x: { grid: { display: false }, ticks: { color: 'rgba(232,238,252,0.65)' } }
        }
      }
    });
  }

  if (isPhase3()) {
    renderPhase3LineChart('chartVisitorsLine', 'visitors', 'int');
    renderPhase3LineChart('chartBehaviorLine', 'behavior', 'pct');
    renderPhase3LineChart('chartSalesLine', 'sales', 'money');
    renderPhase3LineChart('chartGoalsLine', 'goals', 'int');
    renderPhase3LineChart('chartSeoLine', 'seo', 'int');
    return;
  }

  // Backward compatibility: older Phase 2 snapshots (kept minimal).
  if (!snap.analytics) return;
})();

