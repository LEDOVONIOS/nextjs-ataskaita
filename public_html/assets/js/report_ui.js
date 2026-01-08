/* global Chart */
(function () {
  'use strict';

  var data = window.REPORT_DATA || {};
  var ranges = (data.period && data.period.date_ranges) ? data.period.date_ranges : {};
  var project = data.project || {};
  var showSales = !!project.show_sales_section;
  var allReport = (data.sections && data.sections.all_visitors_report) ? data.sections.all_visitors_report : null;
  var charts = Object.create(null);

  if (!allReport) return;

  var elVisitsTable = document.getElementById('table-visits');
  var elBehaviorTable = document.getElementById('table-behavior');
  var elSalesTable = document.getElementById('table-sales');
  var elGoalsTable = document.getElementById('table-goals');

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function fmtNumber(n, digits) {
    if (n == null || n === '' || (typeof n === 'number' && !isFinite(n))) return '—';
    var num = Number(n);
    if (!isFinite(num)) return '—';
    var d = (typeof digits === 'number') ? digits : 0;
    return num.toLocaleString('lt-LT', { maximumFractionDigits: d, minimumFractionDigits: d });
  }

  function fmtPctRate(v) {
    if (v == null || v === '' || (typeof v === 'number' && !isFinite(v))) return '—';
    var num = Number(v);
    if (!isFinite(num)) return '—';
    return (num * 100).toFixed(1).replace(/\.0$/, '') + '%';
  }

  function fmtPctNumber(v) {
    if (v == null || v === '' || (typeof v === 'number' && !isFinite(v))) return '—';
    var num = Number(v);
    if (!isFinite(num)) return '—';
    return num.toFixed(1).replace(/\.0$/, '') + '%';
  }

  function fmtMinutesFromSeconds(sec) {
    if (sec == null || sec === '' || (typeof sec === 'number' && !isFinite(sec))) return '—';
    var s = Number(sec);
    if (!isFinite(s)) return '—';
    var m = s / 60;
    if (!isFinite(m)) return '—';
    // show as minutes (min.) with 1 decimal
    var out = m.toFixed(1).replace(/\.0$/, '');
    return out.replace('.', ',');
  }

  function pctChange(thisVal, lastVal) {
    var a = Number(thisVal);
    var b = Number(lastVal);
    if (!isFinite(a) || !isFinite(b)) return null;
    if (b === 0) return null;
    return (a - b) / b;
  }

  function deltaBadge(thisVal, lastVal) {
    var ch = pctChange(thisVal, lastVal);
    if (ch == null) {
      return '<span class="report3__delta report3__delta--flat">—</span>';
    }
    var cls = 'report3__delta--flat';
    var arrow = '→';
    if (ch > 0.0001) { cls = 'report3__delta--up'; arrow = '↑'; }
    else if (ch < -0.0001) { cls = 'report3__delta--down'; arrow = '↓'; }
    return '<span class="report3__delta ' + cls + '">' + arrow + ' ' + esc(fmtPctRate(ch)) + '</span>';
  }

  function destroyChart(id) {
    if (charts[id]) {
      try { charts[id].destroy(); } catch (e) {}
      delete charts[id];
    }
  }

  function scrollToHash(hash) {
    var el = document.querySelector(hash);
    if (!el) return;
    el.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function safeArray(v) {
    return Array.isArray(v) ? v : [];
  }

  function sourceLabel(key, fallback) {
    // Ensure required labels even if snapshot used older labels.
    var overrides = {
      direct_unknown: 'Tiesiogiai atėję / apsaugoti / neatpažinti'
    };
    return overrides[key] || fallback || key || '—';
  }

  function makeTableHTML(headers, rowsHtml) {
    var thead = '<thead><tr>' + headers.map(function (h, idx) {
      return '<th' + (idx === 0 ? '' : ' class="right"') + '>' + esc(h) + '</th>';
    }).join('') + '</tr></thead>';
    return thead + '<tbody>' + rowsHtml.join('') + '</tbody>';
  }

  function lineRow(label, cells, isStrong) {
    var first = isStrong ? ('<strong>' + esc(label) + '</strong>') : esc(label);
    var tds = ['<td>' + first + '</td>'].concat(cells.map(function (c) {
      return '<td class="right">' + c + '</td>';
    }));
    return '<tr>' + tds.join('') + '</tr>';
  }

  function buildVisitsTable() {
    if (!elVisitsTable) return;

    var srcs = safeArray(allReport.sources);
    var totals = (allReport.visits && allReport.visits.totals) ? allReport.visits.totals : {};
    var bySource = (allReport.visits && allReport.visits.by_source) ? allReport.visits.by_source : {};

    var thisRange = (ranges.this_start || '') + ' – ' + (ranges.this_end || '');
    var lastRange = (ranges.last_start || '') + ' – ' + (ranges.last_end || '');

    var headers = [
      'Apsilankymų keliai',
      'Lankytojų skaičius (vnt.)',
      'Naujų lankytojų skaičius (vnt.)',
      'Apsilankymų kiekis (vnt.)'
    ];

    var rows = [];
    rows.push(lineRow('Pokytis', [
      deltaBadge(totals.users, totals.last_users),
      deltaBadge(totals.new_users, totals.last_new_users),
      deltaBadge(totals.sessions, totals.last_sessions)
    ], true));

    rows.push(lineRow(thisRange, [
      esc(fmtNumber(totals.users, 0)),
      esc(fmtNumber(totals.new_users, 0)),
      esc(fmtNumber(totals.sessions, 0))
    ], false));

    rows.push(lineRow(lastRange, [
      esc(fmtNumber(totals.last_users, 0)),
      esc(fmtNumber(totals.last_new_users, 0)),
      esc(fmtNumber(totals.last_sessions, 0))
    ], false));

    for (var i = 0; i < srcs.length; i++) {
      var k = String(srcs[i] && srcs[i].key != null ? srcs[i].key : '');
      var label = sourceLabel(k, srcs[i] && srcs[i].label);
      var s = bySource && k ? (bySource[k] || {}) : {};
      rows.push(lineRow(label, [
        esc(fmtNumber(s.users, 0)),
        esc(fmtNumber(s.new_users, 0)),
        esc(fmtNumber(s.sessions, 0))
      ], false));
    }

    elVisitsTable.innerHTML = makeTableHTML(headers, rows);
  }

  function buildBehaviorTable() {
    if (!elBehaviorTable) return;

    var srcs = safeArray(allReport.sources);
    var totals = (allReport.behavior && allReport.behavior.totals) ? allReport.behavior.totals : {};
    var bySource = (allReport.behavior && allReport.behavior.by_source) ? allReport.behavior.by_source : {};

    var thisRange = (ranges.this_start || '') + ' – ' + (ranges.this_end || '');
    var lastRange = (ranges.last_start || '') + ' – ' + (ranges.last_end || '');

    var headers = [
      'Apsilankymų keliai',
      'Įsitraukimo rodiklis (%)',
      'Puslapiai per apsilankymą (vnt.)',
      'Vidutinė apsilankymo trukmė (min.)'
    ];

    var rows = [];
    rows.push(lineRow('Pokytis', [
      deltaBadge(totals.engagement_rate, totals.last_engagement_rate),
      deltaBadge(totals.pages_per_session, totals.last_pages_per_session),
      deltaBadge(totals.avg_session_duration_sec, totals.last_avg_session_duration_sec)
    ], true));

    rows.push(lineRow(thisRange, [
      esc(fmtPctRate(totals.engagement_rate)),
      esc(fmtNumber(totals.pages_per_session, 1)),
      esc(fmtMinutesFromSeconds(totals.avg_session_duration_sec))
    ], false));

    rows.push(lineRow(lastRange, [
      esc(fmtPctRate(totals.last_engagement_rate)),
      esc(fmtNumber(totals.last_pages_per_session, 1)),
      esc(fmtMinutesFromSeconds(totals.last_avg_session_duration_sec))
    ], false));

    for (var i = 0; i < srcs.length; i++) {
      var k = String(srcs[i] && srcs[i].key != null ? srcs[i].key : '');
      var label = sourceLabel(k, srcs[i] && srcs[i].label);
      var s = bySource && k ? (bySource[k] || {}) : {};
      rows.push(lineRow(label, [
        esc(fmtPctRate(s.engagement_rate)),
        esc(fmtNumber(s.pages_per_session, 1)),
        esc(fmtMinutesFromSeconds(s.avg_session_duration_sec))
      ], false));
    }

    elBehaviorTable.innerHTML = makeTableHTML(headers, rows);
  }

  function buildSalesTable() {
    if (!showSales) return;
    if (!elSalesTable) return;

    var sales = allReport.sales || {};
    if (!sales.enabled) {
      elSalesTable.innerHTML = '<thead><tr><th class="muted">—</th></tr></thead><tbody><tr><td class="muted">Sales section disabled</td></tr></tbody>';
      return;
    }

    var srcs = safeArray(allReport.sources);
    var totals = sales.totals || {};
    var bySource = sales.by_source || {};

    var thisRange = (ranges.this_start || '') + ' – ' + (ranges.this_end || '');
    var lastRange = (ranges.last_start || '') + ' – ' + (ranges.last_end || '');

    var headers = [
      'Apsilankymų keliai',
      'Pardavimai (%)',
      'Pardavimų kiekis (vnt.)',
      'Gauti pinigai (EUR)'
    ];

    var rows = [];
    rows.push(lineRow('Pokytis', [
      deltaBadge(totals.conversion_rate, totals.last_conversion_rate),
      deltaBadge(totals.transactions, totals.last_transactions),
      deltaBadge(totals.revenue, totals.last_revenue)
    ], true));

    rows.push(lineRow(thisRange, [
      esc(fmtPctRate(totals.conversion_rate)),
      esc(fmtNumber(totals.transactions, 0)),
      esc(fmtNumber(totals.revenue, 0))
    ], false));

    rows.push(lineRow(lastRange, [
      esc(fmtPctRate(totals.last_conversion_rate)),
      esc(fmtNumber(totals.last_transactions, 0)),
      esc(fmtNumber(totals.last_revenue, 0))
    ], false));

    for (var i = 0; i < srcs.length; i++) {
      var k = String(srcs[i] && srcs[i].key != null ? srcs[i].key : '');
      var label = sourceLabel(k, srcs[i] && srcs[i].label);
      var s = bySource && k ? (bySource[k] || {}) : {};
      rows.push(lineRow(label, [
        esc(fmtPctRate(s.conversion_rate)),
        esc(fmtNumber(s.transactions, 0)),
        esc(fmtNumber(s.revenue, 0))
      ], false));
    }

    elSalesTable.innerHTML = makeTableHTML(headers, rows);
  }

  function isExcludedGoal(name, excluded) {
    if (!name) return true;
    var n = String(name);
    for (var i = 0; i < excluded.length; i++) {
      if (n === String(excluded[i])) return true;
    }
    return false;
  }

  function goalCell(count, sessions) {
    var c = Number(count);
    var s = Number(sessions);
    if (!isFinite(c)) c = 0;
    if (!isFinite(s)) s = 0;
    var pct = (s > 0) ? (c / s * 100) : null;
    var pctText = (pct == null) ? '—' : fmtPctNumber(pct);
    return esc(fmtNumber(c, 0)) + ' <span class="muted">(' + esc(pctText) + ')</span>';
  }

  function buildGoalsTable() {
    if (!elGoalsTable) return;

    var goals = allReport.goals || {};
    var excluded = safeArray(goals.excluded_events);
    var goalNames = safeArray(goals.goal_names).filter(function (g) { return !isExcludedGoal(g, excluded); });

    if (!goalNames.length) {
      elGoalsTable.innerHTML = '<thead><tr><th class="muted">Apsilankymų keliai</th></tr></thead><tbody><tr><td class="muted">Tikslų duomenų nėra.</td></tr></tbody>';
      return;
    }

    var srcs = safeArray(allReport.sources);
    var totalsThis = goals.totals_this || { sessions: 0, goals: {} };
    var totalsLast = goals.totals_last || { sessions: 0, goals: {} };
    var bySource = goals.by_source || {};

    var thisRange = (ranges.this_start || '') + ' – ' + (ranges.this_end || '');
    var lastRange = (ranges.last_start || '') + ' – ' + (ranges.last_end || '');

    var headers = ['Apsilankymų keliai'].concat(goalNames.map(function (g) { return String(g); }));
    var rows = [];

    rows.push(lineRow(thisRange, goalNames.map(function (g) {
      var cnt = totalsThis.goals ? totalsThis.goals[g] : 0;
      return goalCell(cnt, totalsThis.sessions);
    }), false));

    rows.push(lineRow(lastRange, goalNames.map(function (g) {
      var cnt = totalsLast.goals ? totalsLast.goals[g] : 0;
      return goalCell(cnt, totalsLast.sessions);
    }), false));

    for (var i = 0; i < srcs.length; i++) {
      var k = String(srcs[i] && srcs[i].key != null ? srcs[i].key : '');
      var label = sourceLabel(k, srcs[i] && srcs[i].label);
      var s = bySource && k ? (bySource[k] || {}) : {};
      var sess = s.sessions || 0;
      var gMap = s.goals || {};
      rows.push(lineRow(label, goalNames.map(function (g) {
        return goalCell(gMap[g] || 0, sess);
      }), false));
    }

    elGoalsTable.innerHTML = makeTableHTML(headers, rows);
  }

  function renderLineChart() {
    destroyChart('visits_line');
    if (!window.Chart) return;

    // Prefer timeseries from traffic/all/visits since it's day-of-month aligned.
    var ts = null;
    try {
      ts = data.sections && data.sections.traffic && data.sections.traffic.all && data.sections.traffic.all.visits
        ? data.sections.traffic.all.visits.timeseries
        : null;
    } catch (e) {}

    if (!ts || !Array.isArray(ts.labels)) return;
    var canvas = document.getElementById('chart-visits-line');
    if (!canvas) return;
    var ctx = canvas.getContext('2d');
    if (!ctx) return;

    charts.visits_line = new Chart(ctx, {
      type: 'line',
      data: {
        labels: safeArray(ts.labels),
        datasets: [
          {
            label: (data.period && data.period.label) ? data.period.label : 'This month',
            data: safeArray(ts.this),
            borderColor: '#4f8cff',
            backgroundColor: 'rgba(79,140,255,.10)',
            tension: 0.25,
            fill: false
          },
          {
            label: (data.period && data.period.compare_to && data.period.compare_to.label) ? data.period.compare_to.label : 'Last year',
            data: safeArray(ts.last),
            borderColor: '#6ee7ff',
            backgroundColor: 'rgba(110,231,255,.10)',
            tension: 0.25,
            fill: false
          }
        ]
      },
      options: {
        responsive: true,
        plugins: {
          legend: { labels: { color: 'rgba(232,238,252,0.9)' } },
          tooltip: { enabled: true }
        },
        scales: {
          y: {
            beginAtZero: true,
            title: {
              display: true,
              text: 'Lankytojų skaičius (vnt.)',
              color: 'rgba(232,238,252,0.75)',
              font: { weight: '700' }
            },
            grid: { color: 'rgba(255,255,255,0.06)' },
            ticks: { color: 'rgba(232,238,252,0.85)' }
          },
          x: {
            grid: { display: false },
            ticks: { color: 'rgba(232,238,252,0.65)' }
          }
        }
      }
    });
  }

  function renderDoughnut(id, items) {
    destroyChart(id);
    if (!window.Chart) return;

    var canvas = document.getElementById('chart-donut-' + id);
    var legend = document.getElementById('legend-donut-' + id);
    if (!canvas || !legend) return;
    var ctx = canvas.getContext('2d');
    if (!ctx) return;

    var rows = safeArray(items).map(function (it) {
      return {
        label: String(it && it.label != null ? it.label : '—'),
        value: Number(it && it.value != null ? it.value : 0)
      };
    }).filter(function (it) { return isFinite(it.value) && it.value >= 0; });

    var total = rows.reduce(function (acc, r) { return acc + r.value; }, 0);
    if (!isFinite(total) || total <= 0) total = 0;

    var labels = rows.map(function (r) { return r.label; });
    var values = rows.map(function (r) { return r.value; });

    var palette = ['#4f8cff', '#6ee7ff', '#a78bfa', '#34d399', '#fbbf24', '#fb7185', '#94a3b8', '#22c55e', '#60a5fa'];
    var colors = values.map(function (_, idx) { return palette[idx % palette.length]; });

    charts[id] = new Chart(ctx, {
      type: 'doughnut',
      data: {
        labels: labels,
        datasets: [{
          data: values,
          backgroundColor: colors,
          borderColor: 'rgba(11,18,32,0.0)',
          borderWidth: 2,
          hoverOffset: 6
        }]
      },
      options: {
        responsive: true,
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: function (context) {
                var v = Number(context.parsed || 0);
                var p = (total > 0) ? (v / total * 100) : null;
                var pctText = (p == null) ? '—' : fmtPctNumber(p);
                return String(context.label || '') + ': ' + fmtNumber(v, 0) + ' (' + pctText + ')';
              }
            }
          }
        },
        cutout: '62%'
      }
    });

    legend.innerHTML = rows.map(function (r, idx) {
      var v = r.value;
      var p = (total > 0) ? (v / total * 100) : null;
      var pctText = (p == null) ? '—' : fmtPctNumber(p);
      return '' +
        '<div class="report3__legendItem">' +
          '<span class="report3__legendSwatch" style="background:' + esc(colors[idx]) + '"></span>' +
          '<span class="report3__legendLabel">' + esc(r.label) + '</span>' +
          '<span class="report3__legendValue">' + esc(fmtNumber(v, 0)) + ' <span class="muted">(' + esc(pctText) + ')</span></span>' +
        '</div>';
    }).join('');
  }

  function renderDonuts() {
    var d = allReport.demographics || {};
    renderDoughnut('gender', d.gender);
    renderDoughnut('browsers', d.browsers);
    renderDoughnut('devices', d.devices);
    renderDoughnut('age', d.age);
  }

  function setupQuickScroll() {
    document.addEventListener('click', function (e) {
      var link = e.target && e.target.closest ? e.target.closest('.report3__tab[href^="#"]') : null;
      if (!link) return;
      var href = link.getAttribute('href');
      if (!href) return;
      var target = document.querySelector(href);
      if (!target) return;
      e.preventDefault();
      history.replaceState(null, '', href);
      scrollToHash(href);
    });
  }

  // Render everything
  buildVisitsTable();
  buildBehaviorTable();
  buildSalesTable();
  buildGoalsTable();
  renderLineChart();
  renderDonuts();
  setupQuickScroll();

  if (window.location.hash) {
    setTimeout(function () { scrollToHash(window.location.hash); }, 50);
  }
})();

