/* global Chart */
(function () {
  'use strict';

  function onReady(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  onReady(function initReportUI() {
    var ROOT = window.REPORT_DATA || window.Report_DATA;
    if (!ROOT || !ROOT.sections) {
      // eslint-disable-next-line no-console
      console.warn('No REPORT_DATA');
      return;
    }

    // eslint-disable-next-line no-console
    console.log('report_ui.js loaded', Object.keys(ROOT.sections || {}));

    // TEMP debug (Phase 3.2)
    // eslint-disable-next-line no-console
    console.log(
      "SEO GA4 users:",
      ROOT.sections.traffic?.seo?.visits?.totals?.users
    );

    var data = ROOT;
    var meta = data.meta || {};
    var ranges = (data.period && data.period.date_ranges) ? data.period.date_ranges : {};
    var project = data.project || {};
    var showSales = !!project.show_sales_section;
    var charts = Object.create(null);
    var DEFAULT_EXCLUDED_EVENTS = ['scroll', 'first_visit', 'session_start', 'page_view', 'user_engagement'];

    function activeViewFromMetaOrUrl() {
      var view = (meta && meta.active_view) ? String(meta.active_view) : '';
      if (view) return view;
      try {
        return String((new URLSearchParams(window.location.search)).get('view') || '');
      } catch (e) {
        return '';
      }
    }

    function resolveActiveKey() {
      var view = activeViewFromMetaOrUrl();
      var key = 'all_visitors_report';
      if (view === 'seo') key = 'seo_report';
      else if (view === 'ppc') key = 'ppc_report';
      else if (!view || view === 'all') key = 'all_visitors_report';
      else key = view; // segment / custom view key
      return key;
    }

    // Always reconcile active key (report.php may set a default).
    window.ACTIVE_REPORT_KEY = resolveActiveKey();

    var isSegmentView = !!(window.ACTIVE_REPORT_KEY && window.ACTIVE_REPORT_KEY !== 'all_visitors_report' && window.ACTIVE_REPORT_KEY !== 'seo_report' && window.ACTIVE_REPORT_KEY !== 'ppc_report');

    // STEP 2 — Fix DOM selector mismatch (match report.php markup)
    var elVisitsTable = document.getElementById('table-visits');
    var elBehaviorTable = document.getElementById('table-behavior');
    var elSalesTable = document.getElementById('table-sales');
    var elGoalsTable = document.getElementById('table-goals');
    var elSeoGscTable = document.getElementById('table-seo-gsc');
    var elSeoKeywordsTable = document.getElementById('table-seo-keywords');
    var elSeoBehaviorTable = document.getElementById('table-seo-behavior');
    var elSeoSalesTable = document.getElementById('table-seo-sales');
    var elSeoGoalsTable = document.getElementById('table-seo-goals');
    var elPpcVisitsTable = document.getElementById('table-ppc-visits');
    var elPpcCampaignsTable = document.getElementById('table-ppc-campaigns');
    var elPpcKeywordsTable = document.getElementById('table-ppc-keywords');
    var elPpcCitiesTable = document.getElementById('table-ppc-cities');
    var elPpcBehaviorTable = document.getElementById('table-ppc-behavior');
    var elPpcSalesTable = document.getElementById('table-ppc-sales');
    var elPpcGoalsTable = document.getElementById('table-ppc-goals');

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

  function excludedEventsOrDefault(v) {
    var arr = safeArray(v);
    return arr.length ? arr : DEFAULT_EXCLUDED_EVENTS.slice();
  }

  function appendPlaceholderToSection(sectionId, message) {
    var sec = sectionId ? document.getElementById(sectionId) : null;
    try {
      // eslint-disable-next-line no-console
      console.warn('[report_ui] ' + String(message || 'UI placeholder'));
    } catch (e) {}
    if (!sec) return;
    // Avoid duplicating placeholders.
    if (sec.querySelector && sec.querySelector('[data-report-ui-placeholder="1"]')) return;
    var el = document.createElement('div');
    el.setAttribute('data-report-ui-placeholder', '1');
    el.className = 'muted';
    el.style.padding = '8px 0';
    el.textContent = String(message || 'UI placeholder');
    sec.appendChild(el);
  }

  var didLogMissingChartJs = false;
  function ensureChartJsOrWarn() {
    if (window.Chart) return true;
    if (!didLogMissingChartJs) {
      didLogMissingChartJs = true;
      // eslint-disable-next-line no-console
      console.error('[report_ui] Chart.js is not available (Chart is undefined). Charts will be skipped; tables should still render.');
    }
    return false;
  }

  var didWarnMissingActiveReport = false;
  function getSectionByKeyOrSegment(key) {
    var secs = (ROOT && ROOT.sections) ? ROOT.sections : {};
    if (!secs) return null;
    if (key && secs[key]) return secs[key];

    // Segment reports can be nested: sections.segment_reports[<segmentKey>]
    var segs = secs.segment_reports;
    if (segs && typeof segs === 'object') {
      if (key && segs[key]) return segs[key];
      var segKey = (meta && meta.active_segment_key) ? String(meta.active_segment_key) : '';
      if (segKey && segs[segKey]) return segs[segKey];

      var view = activeViewFromMetaOrUrl();
      if (view && segs[view]) return segs[view];
    }

    // Fallback: some snapshots may store segment report directly under a derived key.
    var view2 = activeViewFromMetaOrUrl();
    if (view2 && secs[view2 + '_report']) return secs[view2 + '_report'];

    return null;
  }

  function getActiveReport() {
    var key = window.ACTIVE_REPORT_KEY || 'all_visitors_report';
    // STEP 3 — Active report key wiring
    var out = getSectionByKeyOrSegment(key);
    if (!out && !didWarnMissingActiveReport) {
      didWarnMissingActiveReport = true;
      try {
        // eslint-disable-next-line no-console
        console.warn('[report_ui] Active report missing:', key, 'available:', Object.keys((ROOT && ROOT.sections) || {}));
      } catch (e) {}
    }
    return out;
  }

  // Minimal smoke render: prove wiring by showing Users total (e.g. 30329)
  function smokeRenderUsersTotal() {
    var R = getActiveReport();
    if (!R || !R.visits || !R.visits.totals) return;
    var users = R.visits.totals.users;
    if (users == null) return;

    if (!elVisitsTable) {
      appendPlaceholderToSection('visits', 'Missing container: #table-visits (smoke render users=' + String(users) + ')');
      return;
    }

    // Only write the minimal table if the container is currently empty.
    if (String(elVisitsTable.textContent || '').trim() !== '') return;

    elVisitsTable.innerHTML =
      '<thead><tr><th>Users</th><th class="right">Value</th></tr></thead>' +
      '<tbody><tr><td><strong>Users</strong></td><td class="right">' + esc(fmtNumber(users, 0)) + '</td></tr></tbody>';
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
    if (!elVisitsTable) {
      appendPlaceholderToSection('visits', 'Missing container: #table-visits');
      return;
    }
    var R = getActiveReport();
    if (!R) {
      appendPlaceholderToSection('visits', 'Missing report data for active key: ' + String(window.ACTIVE_REPORT_KEY || 'all_visitors_report'));
      return;
    }

    var srcs = safeArray(R.sources);
    var totals = (R.visits && R.visits.totals) ? R.visits.totals : {};
    var bySource = (R.visits && R.visits.by_source) ? R.visits.by_source : {};

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
    if (!elBehaviorTable) {
      appendPlaceholderToSection('behavior', 'Missing container: #table-behavior');
      return;
    }
    var R = getActiveReport();
    if (!R) {
      appendPlaceholderToSection('behavior', 'Missing report data for active key: ' + String(window.ACTIVE_REPORT_KEY || 'all_visitors_report'));
      return;
    }

    var srcs = safeArray(R.sources);
    var totals = (R.behavior && R.behavior.totals) ? R.behavior.totals : {};
    var bySource = (R.behavior && R.behavior.by_source) ? R.behavior.by_source : {};

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
    if (!elSalesTable) {
      appendPlaceholderToSection('sales', 'Missing container: #table-sales');
      return;
    }
    var R = getActiveReport();
    if (!R) {
      appendPlaceholderToSection('sales', 'Missing report data for active key: ' + String(window.ACTIVE_REPORT_KEY || 'all_visitors_report'));
      return;
    }

    var sales = R.sales || {};
    if (!sales.enabled) {
      elSalesTable.innerHTML = '<thead><tr><th class="muted">—</th></tr></thead><tbody><tr><td class="muted">Sales section disabled</td></tr></tbody>';
      return;
    }

    var srcs = safeArray(R.sources);
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
    if (!elGoalsTable) {
      appendPlaceholderToSection('goals', 'Missing container: #table-goals');
      return;
    }
    var R = getActiveReport();
    if (!R) {
      appendPlaceholderToSection('goals', 'Missing report data for active key: ' + String(window.ACTIVE_REPORT_KEY || 'all_visitors_report'));
      return;
    }

    var goals = R.goals || {};
    var excluded = excludedEventsOrDefault(goals.excluded_events);
    var goalNames = safeArray(goals.goal_names).filter(function (g) { return !isExcludedGoal(g, excluded); });

    if (!goalNames.length) {
      elGoalsTable.innerHTML = '<thead><tr><th class="muted">Apsilankymų keliai</th></tr></thead><tbody><tr><td class="muted">Tikslų duomenų nėra.</td></tr></tbody>';
      return;
    }

    var srcs = safeArray(R.sources);
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
    if (!ensureChartJsOrWarn()) {
      appendPlaceholderToSection('visits', 'Chart is unavailable (Chart.js not loaded).');
      return;
    }

    var R = getActiveReport();
    var ts = null;
    try {
      // New schema prefers report-local timeseries.
      ts = (R && R.visits && R.visits.timeseries) ? R.visits.timeseries : (R && R.timeseries ? R.timeseries : null);
    } catch (e) { ts = null; }

    if (!ts || !Array.isArray(ts.labels)) return;
    var canvas = document.getElementById('chart-visits-line');
    if (!canvas) {
      appendPlaceholderToSection('visits', 'Missing canvas: #chart-visits-line');
      return;
    }
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

  function ensureSeoVisitsCanvas() {
    var existing = document.getElementById('chart-seo-visits-line');
    if (existing) return existing;
    var sec = document.getElementById('seo-gsc');
    if (!sec) return null;
    var card = sec.querySelector ? (sec.querySelector('.card') || sec) : sec;
    if (!card) return null;

    var wrap = document.createElement('div');
    wrap.className = 'report3__chartWrap';
    var canvas = document.createElement('canvas');
    canvas.id = 'chart-seo-visits-line';
    canvas.height = 160;
    wrap.appendChild(canvas);

    var head = card.querySelector ? card.querySelector('.report3__sectionHead') : null;
    if (head && head.parentNode === card) {
      head.insertAdjacentElement('afterend', wrap);
    } else {
      card.insertBefore(wrap, card.firstChild);
    }
    return canvas;
  }

  function renderSeoVisitsLineChart() {
    destroyChart('seo_visits_line');
    if (!ensureChartJsOrWarn()) {
      appendPlaceholderToSection('seo-gsc', 'Chart is unavailable (Chart.js not loaded).');
      return;
    }

    var ts = null;
    try {
      ts = (ROOT.sections && ROOT.sections.traffic && ROOT.sections.traffic.seo && ROOT.sections.traffic.seo.visits)
        ? ROOT.sections.traffic.seo.visits.timeseries
        : null;
    } catch (e) { ts = null; }

    if (!ts || !Array.isArray(ts.labels)) return;
    var canvas = ensureSeoVisitsCanvas();
    if (!canvas) {
      appendPlaceholderToSection('seo-gsc', 'Missing container: #seo-gsc (for SEO visits line chart)');
      return;
    }
    var ctx = canvas.getContext('2d');
    if (!ctx) return;

    charts.seo_visits_line = new Chart(ctx, {
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

  function renderPpcLineChart() {
    destroyChart('ppc_visits_line');
    if (!ensureChartJsOrWarn()) {
      appendPlaceholderToSection('ppc-visits', 'Chart is unavailable (Chart.js not loaded).');
      return;
    }
    var R = getActiveReport();
    if (!R || !R.visits || !R.visits.timeseries) return;
    var ts = R.visits.timeseries;
    if (!ts || !Array.isArray(ts.labels)) return;
    var canvas = document.getElementById('chart-ppc-visits-line');
    if (!canvas) {
      appendPlaceholderToSection('ppc-visits', 'Missing canvas: #chart-ppc-visits-line');
      return;
    }
    var ctx = canvas.getContext('2d');
    if (!ctx) return;

    charts.ppc_visits_line = new Chart(ctx, {
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

  function ppcItems(path) {
    var R = getActiveReport();
    if (!R) return [];
    var obj = R[path];
    if (!obj || !obj.items) return [];
    return safeArray(obj.items);
  }

  function buildPpcVisitsTable() {
    if (!elPpcVisitsTable) return;
    var items = ppcItems('campaigns');

    var headers = [
      'Apsilankymų keliai',
      'Paspaudimai (vnt.)',
      'Parodymai (vnt.)',
      'Išlaidos (EUR)',
      'Vidutinė paspaudimo kaina (EUR)',
      'Lankytojų skaičius',
      'Apsilankymų kiekis',
      'Paspaudimų rodiklis (CTR %)'
    ];

    var rows = [];
    if (!items.length) {
      rows.push(lineRow('—', ['—', '—', '—', '—', '—', '—', '—'], false));
    } else {
      for (var i = 0; i < items.length; i++) {
        var it = items[i] || {};
        rows.push(lineRow(it.campaign != null ? String(it.campaign) : '—', [
          esc(fmtNumber(it.clicks, 0)),
          esc(fmtNumber(it.impressions, 0)),
          esc(fmtNumber(it.cost_eur, 2)),
          esc(fmtNumber(it.avg_cpc_eur, 2)),
          esc(fmtNumber(it.users, 0)),
          esc(fmtNumber(it.sessions, 0)),
          esc(fmtPctRate(it.ctr_rate))
        ], false));
      }
    }

    elPpcVisitsTable.innerHTML = makeTableHTML(headers, rows);
  }

  function buildPpcCampaignsTable() {
    if (!elPpcCampaignsTable) return;
    var items = ppcItems('campaigns');

    var headers = [
      'Kampanija',
      'Parodymai',
      'Paspaudimai',
      'Išlaidos',
      'Konversijos',
      'CTR (%)',
      'Įsitraukimas (%)'
    ];

    var rows = [];
    if (!items.length) {
      rows.push(lineRow('—', ['—', '—', '—', '—', '—', '—'], false));
    } else {
      for (var i = 0; i < items.length; i++) {
        var it = items[i] || {};
        rows.push(lineRow(it.campaign != null ? String(it.campaign) : '—', [
          esc(fmtNumber(it.impressions, 0)),
          esc(fmtNumber(it.clicks, 0)),
          esc(fmtNumber(it.cost_eur, 2)),
          esc(fmtNumber(it.conversions, 0)),
          esc(fmtPctRate(it.ctr_rate)),
          esc(fmtPctRate(it.engagement_rate))
        ], false));
      }
    }

    elPpcCampaignsTable.innerHTML = makeTableHTML(headers, rows);
  }

  function buildPpcKeywordsTable() {
    if (!elPpcKeywordsTable) return;
    var items = ppcItems('keywords');

    var headers = [
      'Raktažodis',
      'Parodymai',
      'Paspaudimai',
      'Išlaidos',
      'Konversijos',
      'CTR (%)',
      'Įsitraukimas (%)'
    ];

    var rows = [];
    if (!items.length) {
      rows.push(lineRow('—', ['—', '—', '—', '—', '—', '—'], false));
    } else {
      for (var i = 0; i < items.length; i++) {
        var it = items[i] || {};
        rows.push(lineRow(it.keyword != null ? String(it.keyword) : '—', [
          esc(fmtNumber(it.impressions, 0)),
          esc(fmtNumber(it.clicks, 0)),
          esc(fmtNumber(it.cost_eur, 2)),
          esc(fmtNumber(it.conversions, 0)),
          esc(fmtPctRate(it.ctr_rate)),
          esc(fmtPctRate(it.engagement_rate))
        ], false));
      }
    }

    elPpcKeywordsTable.innerHTML = makeTableHTML(headers, rows);
  }

  function buildPpcCitiesTable() {
    if (!elPpcCitiesTable) return;
    var items = ppcItems('cities');

    var headers = [
      'Miestas',
      'Parodymai',
      'Paspaudimai',
      'Išlaidos',
      'Konversijos',
      'CTR (%)',
      'Įsitraukimas (%)'
    ];

    var rows = [];
    if (!items.length) {
      rows.push(lineRow('—', ['—', '—', '—', '—', '—', '—'], false));
    } else {
      for (var i = 0; i < items.length; i++) {
        var it = items[i] || {};
        rows.push(lineRow(it.city != null ? String(it.city) : '—', [
          esc(fmtNumber(it.impressions, 0)),
          esc(fmtNumber(it.clicks, 0)),
          esc(fmtNumber(it.cost_eur, 2)),
          esc(fmtNumber(it.conversions, 0)),
          esc(fmtPctRate(it.ctr_rate)),
          esc(fmtPctRate(it.engagement_rate))
        ], false));
      }
    }

    elPpcCitiesTable.innerHTML = makeTableHTML(headers, rows);
  }

  function buildPpcBehaviorTable() {
    if (!elPpcBehaviorTable) return;
    var items = ppcItems('keywords');

    var headers = [
      'Apsilankymų keliai',
      'Įsitraukimo rodiklis (%)',
      'Puslapiai per apsilankymą',
      'Vidutinė apsilankymo trukmė'
    ];

    var rows = [];
    if (!items.length) {
      rows.push(lineRow('—', ['—', '—', '—'], false));
    } else {
      for (var i = 0; i < items.length; i++) {
        var it = items[i] || {};
        rows.push(lineRow(it.keyword != null ? String(it.keyword) : '—', [
          esc(fmtPctRate(it.engagement_rate)),
          esc(fmtNumber(it.pages_per_session, 1)),
          esc(fmtMinutesFromSeconds(it.avg_session_duration_sec))
        ], false));
      }
    }

    elPpcBehaviorTable.innerHTML = makeTableHTML(headers, rows);
  }

  function buildPpcSalesTable() {
    if (!elPpcSalesTable) return;
    if (!showSales) return;
    var items = ppcItems('keywords');

    var headers = [
      'Apsilankymų keliai',
      'Pardavimai (%)',
      'Pardavimų kiekis (vnt.)',
      'Gauti pinigai (EUR)'
    ];

    var rows = [];
    if (!items.length) {
      rows.push(lineRow('—', ['—', '—', '—'], false));
    } else {
      for (var i = 0; i < items.length; i++) {
        var it = items[i] || {};
        var sess = Number(it.sessions);
        var purchases = (it.purchases == null) ? null : Number(it.purchases);
        var revenue = (it.revenue_eur == null) ? null : Number(it.revenue_eur);
        if (!isFinite(sess)) sess = 0;
        if (purchases != null && !isFinite(purchases)) purchases = null;
        if (revenue != null && !isFinite(revenue)) revenue = null;
        var rate = (purchases == null || sess <= 0) ? null : (purchases / sess);

        rows.push(lineRow(it.keyword != null ? String(it.keyword) : '—', [
          esc(rate == null ? '—' : fmtPctRate(rate)),
          esc(purchases == null ? '—' : fmtNumber(purchases, 0)),
          esc(revenue == null ? '—' : fmtNumber(revenue, 2))
        ], false));
      }
    }

    elPpcSalesTable.innerHTML = makeTableHTML(headers, rows);
  }

  function buildPpcGoalsTable() {
    if (!elPpcGoalsTable) return;
    var R = getActiveReport();
    if (!R) return;
    var g = R.goals ? R.goals : {};
    var excluded = excludedEventsOrDefault(g.excluded_events);
    var items = safeArray(g.items || []).filter(function (it) {
      var name = it && it.goal != null ? String(it.goal) : '';
      return name !== '' && !isExcludedGoal(name, excluded);
    });

    var headers = [
      'Raktažodis',
      'Tikslas',
      'Įvykdymų skaičius',
      'Konversijos (%)'
    ];

    var rows = [];
    if (!items.length) {
      rows.push(lineRow('—', ['—', '—', '—'], false));
    } else {
      for (var i = 0; i < items.length; i++) {
        var it = items[i] || {};
        var kw = it.keyword != null ? String(it.keyword) : '—';
        var goal = it.goal != null ? String(it.goal) : '—';
        rows.push(lineRow(kw, [
          esc(goal),
          esc(fmtNumber(it.count, 0)),
          esc(fmtPctRate(it.conversion_rate))
        ], false));
      }
    }

    elPpcGoalsTable.innerHTML = makeTableHTML(headers, rows);
  }

  function renderDoughnut(id, items) {
    destroyChart(id);
    if (!ensureChartJsOrWarn()) return;

    var canvas = document.getElementById('chart-donut-' + id);
    var legend = document.getElementById('legend-donut-' + id);
    if (!canvas || !legend) {
      // Best-effort placeholder in the closest known section.
      if (String(id || '').indexOf('seo-') === 0) appendPlaceholderToSection('seo-charts', 'Missing donut container: ' + String(id));
      else appendPlaceholderToSection('bottom-charts', 'Missing donut container: ' + String(id));
      return;
    }

    function fmtPctOneDecimal(p) {
      if (p == null || (typeof p === 'number' && !isFinite(p))) return '—';
      var num = Number(p);
      if (!isFinite(num)) return '—';
      // Keep one decimal (e.g. 12,0%) for consistent readability.
      return num.toFixed(1).replace('.', ',') + '%';
    }

    function ensureDonutRowLayout(canvasEl, legendEl) {
      // Keep report.php markup intact; only wrap/move nodes for layout.
      try {
        legendEl.classList.add('donut-legend');
        canvasEl.classList.add('donut-chart-canvas');
      } catch (e) {}

      var existingRow = canvasEl.closest ? canvasEl.closest('.donut-row') : null;
      if (existingRow && legendEl.closest && legendEl.closest('.donut-row') === existingRow) {
        // Ensure the canvas is inside a donut-canvas container.
        var existingCanvasWrap = canvasEl.closest('.donut-canvas');
        if (!existingCanvasWrap) {
          var wrap = document.createElement('div');
          wrap.className = 'donut-canvas';
          existingRow.insertBefore(wrap, canvasEl);
          wrap.appendChild(canvasEl);
        }
        return;
      }

      var parent = canvasEl.parentNode;
      if (!parent) return;

      var row = document.createElement('div');
      row.className = 'donut-row';

      var canvasWrap2 = document.createElement('div');
      canvasWrap2.className = 'donut-canvas';

      // Insert row near original canvas position (usually before the legend).
      if (legendEl.parentNode === parent) parent.insertBefore(row, legendEl);
      else parent.appendChild(row);

      row.appendChild(canvasWrap2);
      canvasWrap2.appendChild(canvasEl);
      row.appendChild(legendEl);

      try {
        legendEl.classList.add('donut-legend');
      } catch (e2) {}
    }

    function renderDonutLegend(legendEl, rows, colors, total) {
      // Custom legend on the right side (accessible, dark-theme-friendly).
      while (legendEl.firstChild) legendEl.removeChild(legendEl.firstChild);

      var list = document.createElement('div');
      list.className = 'donut-legend-list';
      list.setAttribute('role', 'list');
      legendEl.appendChild(list);

      for (var i = 0; i < rows.length; i++) {
        var r = rows[i] || {};
        var v = Number(r.value || 0);
        if (!isFinite(v) || v < 0) v = 0;
        var p = (total > 0) ? (v / total * 100) : null;
        var pctText = (p == null) ? '—' : fmtPctOneDecimal(p);

        var item = document.createElement('div');
        item.className = 'donut-legend-item';
        item.setAttribute('role', 'listitem');

        var left = document.createElement('div');
        left.className = 'donut-legend-left';

        var label = document.createElement('div');
        label.className = 'donut-legend-label';
        label.textContent = String(r.label != null ? r.label : '—');

        var bar = document.createElement('div');
        bar.className = 'donut-legend-underline';
        bar.style.backgroundColor = colors[i] || 'rgba(255,255,255,.35)';

        left.appendChild(label);
        left.appendChild(bar);

        var right = document.createElement('div');
        right.className = 'donut-legend-metrics';

        var valueSpan = document.createElement('span');
        valueSpan.className = 'donut-legend-value';
        valueSpan.textContent = fmtNumber(v, 0);

        var pctSpan = document.createElement('span');
        pctSpan.className = 'donut-legend-pct muted';
        pctSpan.textContent = ' (' + pctText + ')';

        right.appendChild(valueSpan);
        right.appendChild(pctSpan);

        item.appendChild(left);
        item.appendChild(right);
        list.appendChild(item);
      }
    }

    // Ensure DOM structure matches the new layout contract.
    ensureDonutRowLayout(canvas, legend);

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
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: function (context) {
                var v = Number(context.parsed || 0);
                var p = (total > 0) ? (v / total * 100) : null;
                var pctText = (p == null) ? '—' : fmtPctOneDecimal(p);
                return String(context.label || '') + ': ' + fmtNumber(v, 0) + ' (' + pctText + ')';
              }
            }
          }
        },
        cutout: '70%'
      }
    });

    renderDonutLegend(legend, rows, colors, total);
  }

  function renderDonuts() {
    var R = getActiveReport();
    if (!R) return;
    var d = R.demographics || {};
    renderDoughnut('gender', d.gender);
    // Segment reports use the same "browsers" slot to display cities (optional).
    renderDoughnut('browsers', isSegmentView ? (d.cities || []) : d.browsers);
    renderDoughnut('devices', d.devices);
    renderDoughnut('age', d.age);
  }

  function seoMetric(obj, key) {
    if (!obj || !key) return {};
    if (obj && obj[key]) return obj[key];
    // Backward-compatible aliases (older snapshots used *_keywords keys).
    var alias = {
      top5: 'top5_keywords',
      top10: 'top10_keywords',
      top30: 'top30_keywords'
    };
    var k2 = alias[key];
    if (k2 && obj && obj[k2]) return obj[k2];
    return {};
  }

  function buildSeoGscTable() {
    if (!elSeoGscTable) {
      appendPlaceholderToSection('seo-gsc', 'Missing container: #table-seo-gsc');
      return;
    }
    var R = getActiveReport();
    if (!R) return;
    var gsc = R.gsc ? R.gsc : {};

    var thisRange = (ranges.this_start || '') + ' – ' + (ranges.this_end || '');
    var lastRange = (ranges.last_start || '') + ' – ' + (ranges.last_end || '');

    var rowsDef = [
      { key: 'clicks', label: 'Paspaudimai', digits: 0 },
      { key: 'impressions', label: 'Parodymai', digits: 0 },
      { key: 'top5', label: 'Raktažodžių kiekis TOP 5', digits: 0 },
      { key: 'top10', label: 'Raktažodžių kiekis TOP 10', digits: 0 },
      { key: 'top30', label: 'Raktažodžių kiekis TOP 30', digits: 0 },
      { key: 'indexed_pages', label: 'Indeksuotų puslapių kiekis (Google)', digits: 0 }
    ];

    var headers = [' ', thisRange, lastRange, 'Pokytis (%)'];
    var rows = [];

    for (var i = 0; i < rowsDef.length; i++) {
      var def = rowsDef[i];
      var m = seoMetric(gsc, def.key);
      var a = (m && m.this != null) ? m.this : null;
      var b = (m && m.last != null) ? m.last : null;
      rows.push(lineRow(def.label, [
        esc(fmtNumber(a, def.digits)),
        esc(fmtNumber(b, def.digits)),
        deltaBadge(a, b)
      ], false));
    }

    elSeoGscTable.innerHTML = makeTableHTML(headers, rows);
  }

  function posDeltaBadge(delta) {
    var n = Number(delta);
    if (!isFinite(n)) return '<span class="report3__posDelta report3__posDelta--flat">—</span>';
    if (Math.abs(n) < 0.0001) return '<span class="report3__posDelta report3__posDelta--flat">0</span>';
    var cls = n > 0 ? 'report3__posDelta--up' : 'report3__posDelta--down';
    var sign = n > 0 ? '+' : '−';
    return '<span class="report3__posDelta ' + cls + '">' + sign + ' ' + esc(fmtNumber(Math.abs(n), 0)) + '</span>';
  }

  function buildSeoKeywordsTable() {
    if (!elSeoKeywordsTable) {
      appendPlaceholderToSection('seo-keywords', 'Missing container: #table-seo-keywords');
      return;
    }
    var R = getActiveReport();
    if (!R) return;
    var kw = R.keywords ? R.keywords : {};
    var items = safeArray(kw.items);

    var headers = ['Raktažodis', 'Google pozicija', 'Pozicijos pokytis per laikotarpį', 'Domenas'];
    var thead = '<thead><tr>' +
      '<th data-sort-key="keyword" aria-sort="none">' + esc(headers[0]) + '</th>' +
      '<th class="right" data-sort-key="position" aria-sort="none">' + esc(headers[1]) + '</th>' +
      '<th class="right" data-sort-key="delta" aria-sort="none">' + esc(headers[2]) + '</th>' +
      '<th data-sort-key="domain" aria-sort="none">' + esc(headers[3]) + '</th>' +
    '</tr></thead>';

    var bodyRows = [];
    if (!items.length) {
      bodyRows.push('<tr><td class="muted">—</td><td class="right muted">—</td><td class="right muted">—</td><td class="muted">—</td></tr>');
    } else {
      for (var i = 0; i < items.length; i++) {
        var it = items[i] || {};
        var keyword = (it.keyword != null) ? String(it.keyword) : '—';
        var domain = (it.domain != null) ? String(it.domain) : '—';
        var pos = (it.position != null) ? it.position : null;
        var delta = (it.delta != null) ? it.delta : null;
        bodyRows.push(
          '<tr>' +
            '<td>' + esc(keyword) + '</td>' +
            '<td class="right">' + esc(fmtNumber(pos, 0)) + '</td>' +
            '<td class="right">' + posDeltaBadge(delta) + '</td>' +
            '<td class="muted">' + esc(domain) + '</td>' +
          '</tr>'
        );
      }
    }

    elSeoKeywordsTable.innerHTML = thead + '<tbody>' + bodyRows.join('') + '</tbody>';
  }

  function buildSeoBehaviorTable() {
    if (!elSeoBehaviorTable) {
      appendPlaceholderToSection('seo-behavior', 'Missing container: #table-seo-behavior');
      return;
    }
    var R = getActiveReport();
    if (!R) return;
    var b = R.behavior ? R.behavior : {};
    var t = b.this || {};
    var l = b.last || {};

    var thisRange = (ranges.this_start || '') + ' – ' + (ranges.this_end || '');
    var lastRange = (ranges.last_start || '') + ' – ' + (ranges.last_end || '');

    var headers = [' ', 'Įsitraukimo rodiklis (%)', 'Puslapiai per apsilankymą (vnt.)', 'Vidutinė apsilankymo trukmė (min.)'];
    var rows = [];
    rows.push(lineRow('Pokytis', [
      deltaBadge(t.engagement_rate, l.engagement_rate),
      deltaBadge(t.pages_per_session, l.pages_per_session),
      deltaBadge(t.avg_session_duration_sec, l.avg_session_duration_sec)
    ], true));

    rows.push(lineRow(thisRange, [
      esc(fmtPctRate(t.engagement_rate)),
      esc(fmtNumber(t.pages_per_session, 1)),
      esc(fmtMinutesFromSeconds(t.avg_session_duration_sec))
    ], false));

    rows.push(lineRow(lastRange, [
      esc(fmtPctRate(l.engagement_rate)),
      esc(fmtNumber(l.pages_per_session, 1)),
      esc(fmtMinutesFromSeconds(l.avg_session_duration_sec))
    ], false));

    elSeoBehaviorTable.innerHTML = makeTableHTML(headers, rows);
  }

  function buildSeoSalesTable() {
    if (!elSeoSalesTable) {
      appendPlaceholderToSection('seo-sales', 'Missing container: #table-seo-sales');
      return;
    }
    var R = getActiveReport();
    if (!R) return;
    var s = R.sales ? R.sales : {};
    var t = s.this || {};
    var l = s.last || {};

    var thisRange = (ranges.this_start || '') + ' – ' + (ranges.this_end || '');
    var lastRange = (ranges.last_start || '') + ' – ' + (ranges.last_end || '');

    var headers = [' ', 'Pardavimai (%)', 'Pardavimų kiekis (vnt.)', 'Gauti pinigai (EUR)'];
    var rows = [];
    rows.push(lineRow('Pokytis', [
      deltaBadge(t.conversion_rate, l.conversion_rate),
      deltaBadge(t.transactions, l.transactions),
      deltaBadge(t.revenue, l.revenue)
    ], true));

    rows.push(lineRow(thisRange, [
      esc(fmtPctRate(t.conversion_rate)),
      esc(fmtNumber(t.transactions, 0)),
      esc(fmtNumber(t.revenue, 0))
    ], false));

    rows.push(lineRow(lastRange, [
      esc(fmtPctRate(l.conversion_rate)),
      esc(fmtNumber(l.transactions, 0)),
      esc(fmtNumber(l.revenue, 0))
    ], false));

    elSeoSalesTable.innerHTML = makeTableHTML(headers, rows);
  }

  function goalCellSeo(count, sessions) {
    var c = Number(count);
    var s = Number(sessions);
    if (!isFinite(c)) c = 0;
    if (!isFinite(s)) s = 0;
    var pct = (s > 0) ? (c / s * 100) : null;
    var pctText = (pct == null) ? '—' : fmtPctNumber(pct);
    return esc(fmtNumber(c, 0)) + ' <span class="muted">(' + esc(pctText) + ')</span>';
  }

  function buildSeoGoalsTable() {
    if (!elSeoGoalsTable) {
      appendPlaceholderToSection('seo-goals', 'Missing container: #table-seo-goals');
      return;
    }
    var R = getActiveReport();
    if (!R) return;
    var g = R.goals ? R.goals : {};
    var excluded = excludedEventsOrDefault(g.excluded_events);
    var items = safeArray(g.items || []).filter(function (it) {
      var name = it && it.name != null ? String(it.name) : '';
      return name !== '' && !isExcludedGoal(name, excluded);
    });

    var thisRange = (ranges.this_start || '') + ' – ' + (ranges.this_end || '');
    var lastRange = (ranges.last_start || '') + ' – ' + (ranges.last_end || '');

    var sessThis = Number(g.seo_sessions_this);
    var sessLast = Number(g.seo_sessions_last);
    if (!isFinite(sessThis)) sessThis = 0;
    if (!isFinite(sessLast)) sessLast = 0;

    var headers = ['Tikslas', thisRange, lastRange, 'Pokytis'];
    var rows = [];

    if (!items.length) {
      rows.push('<tr><td class="muted">—</td><td class="right muted">—</td><td class="right muted">—</td><td class="right muted">—</td></tr>');
    } else {
      for (var i = 0; i < items.length; i++) {
        var it = items[i] || {};
        var name = it.name != null ? String(it.name) : '—';
        rows.push(lineRow(name, [
          goalCellSeo(it.this, sessThis),
          goalCellSeo(it.last, sessLast),
          deltaBadge(it.this, it.last)
        ], false));
      }
    }

    elSeoGoalsTable.innerHTML = makeTableHTML(headers, rows);
  }

  function renderSeoCharts() {
    var R = getActiveReport();
    if (!R) return;
    var c = R.charts ? R.charts : {};
    renderDoughnut('seo-devices', c.devices);
    renderDoughnut('seo-age', c.age);
    renderDoughnut('seo-gender', c.gender);
    renderDoughnut('seo-cities', c.cities);
  }

  function setupSeoSendButton() {
    var btn = document.getElementById('btn-send-client');
    if (!btn) return;
    var sel = btn.getAttribute('data-requires-nonempty') || '';
    var input = sel ? document.querySelector(sel) : null;
    if (!input) return;

    function update() {
      var v = String(input.value || '').trim();
      btn.disabled = v === '';
    }
    input.addEventListener('input', update);
    update();

    btn.addEventListener('click', function () {
      if (btn.disabled) return;
      // UI-only placeholder; no backend/email logic in this phase.
      window.alert('Ataskaitos siuntimas bus įgyvendintas vėliau (UI logika).');
    });
  }

  function qs(params) {
    var out = [];
    for (var k in params) {
      if (!Object.prototype.hasOwnProperty.call(params, k)) continue;
      out.push(encodeURIComponent(k) + '=' + encodeURIComponent(String(params[k] == null ? '' : params[k])));
    }
    return out.join('&');
  }

  var didInitSeoNote = false;
  var didLoadSeoNoteOnce = false;

  function seoNoteContext() {
    var pid = Number(project && project.id != null ? project.id : 0);
    var year = Number((data.period && data.period.year != null) ? data.period.year : 0);
    var month = Number((data.period && data.period.month != null) ? data.period.month : 0);
    return { project_id: pid, year: year, month: month, scope: 'seo_work_summary' };
  }

  function setSeoNoteStatus(msg, isError) {
    var el = document.getElementById('seo-work-save-status');
    if (!el) return;
    el.textContent = String(msg || '');
    try {
      el.style.color = isError ? '#fb7185' : '';
    } catch (e) {}
  }

  function loadSeoWorkSummary() {
    var ta = document.getElementById('seo_work_summary');
    if (!ta) return;
    var ctx = seoNoteContext();
    if (!ctx.project_id || !ctx.year || !ctx.month) return;

    setSeoNoteStatus('Kraunama…', false);
    fetch('/note.php?' + qs(ctx), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (json) {
        if (!json || !json.ok) throw new Error((json && json.error) ? String(json.error) : 'Load failed');
        ta.value = String(json.note_text != null ? json.note_text : '');
        setSeoNoteStatus('', false);
        didLoadSeoNoteOnce = true;
      })
      .catch(function () {
        setSeoNoteStatus('Nepavyko užkrauti pastabos.', true);
      });
  }

  function saveSeoWorkSummary() {
    var btn = document.getElementById('btn-seo-work-save');
    var ta = document.getElementById('seo_work_summary');
    if (!btn || !ta) return;
    var ctx = seoNoteContext();
    if (!ctx.project_id || !ctx.year || !ctx.month) return;

    var fd = new FormData();
    fd.append('csrf_token', String(window.CSRF_TOKEN || ''));
    fd.append('project_id', String(ctx.project_id));
    fd.append('year', String(ctx.year));
    fd.append('month', String(ctx.month));
    fd.append('scope', String(ctx.scope));
    fd.append('note_text', String(ta.value || ''));

    btn.disabled = true;
    setSeoNoteStatus('Saugoma…', false);

    fetch('/note.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (json) {
        if (!json || !json.ok) throw new Error((json && json.error) ? String(json.error) : 'Save failed');
        setSeoNoteStatus('Išsaugota.', false);
        setTimeout(function () { setSeoNoteStatus('', false); }, 1200);
      })
      .catch(function () {
        setSeoNoteStatus('Nepavyko išsaugoti.', true);
      })
      .finally(function () { btn.disabled = false; });
  }

  function setupSeoWorkSummaryUI() {
    if (didInitSeoNote) return;
    var btn = document.getElementById('btn-seo-work-save');
    var ta = document.getElementById('seo_work_summary');
    if (!btn || !ta) return;
    didInitSeoNote = true;
    btn.addEventListener('click', saveSeoWorkSummary);
  }

  function setSidebarActive(view) {
    var links = document.querySelectorAll('.report3__presetLink[data-report-view]');
    for (var i = 0; i < links.length; i++) {
      var a = links[i];
      var v = a.getAttribute('data-report-view');
      var isActive = String(v || '') === String(view || '');
      try {
        a.classList.toggle('is-active', isActive);
        if (isActive) a.setAttribute('aria-current', 'page');
        else a.removeAttribute('aria-current');
      } catch (e) {}
    }
  }

  function toggleViews(activeView) {
    var nodes = document.querySelectorAll('.report3__view[data-view]');
    for (var i = 0; i < nodes.length; i++) {
      var el = nodes[i];
      var v = el.getAttribute('data-view');
      if (String(v) === String(activeView)) el.style.display = '';
      else el.style.display = 'none';
    }
  }

  function updateQuickTabsForView(activeView) {
    var tabs = document.querySelectorAll('#reportQuickTabs .report3__tab[data-tab]');
    var map = null;
    if (activeView === 'seo') {
      map = { visits: '#seo-gsc', behavior: '#seo-behavior', sales: '#seo-sales', goals: '#seo-goals' };
    } else if (activeView === 'ppc') {
      map = { visits: '#ppc-visits', behavior: '#ppc-behavior', sales: '#ppc-sales', goals: '#ppc-goals' };
    } else {
      map = { visits: '#visits', behavior: '#behavior', sales: '#sales', goals: '#goals' };
    }
    for (var i = 0; i < tabs.length; i++) {
      var t = tabs[i];
      var key = t.getAttribute('data-tab');
      if (!key) continue;
      var href = map[key] || '#';
      t.setAttribute('href', href);
    }
  }

  function updateUrlViewParam(view) {
    try {
      var u = new URL(window.location.href);
      u.searchParams.set('view', String(view || 'all'));
      history.replaceState(null, '', u.toString());
    } catch (e) {}
  }

  function reportKeyForView(view) {
    if (view === 'seo') return 'seo_report';
    if (view === 'ppc') return 'ppc_report';
    return 'all_visitors_report';
  }

  function viewForReportKey(key) {
    if (key === 'seo_report') return 'seo';
    if (key === 'ppc_report') return 'ppc';
    return 'all';
  }

  function renderActiveView() {
    // Re-render tables/charts for whichever report key is active.
    var key = window.ACTIVE_REPORT_KEY || 'all_visitors_report';
    if (key === 'seo_report') {
      renderSeoVisitsLineChart();
      buildSeoGscTable();
      buildSeoKeywordsTable();
      buildSeoBehaviorTable();
      if (showSales) buildSeoSalesTable();
      buildSeoGoalsTable();
      renderSeoCharts();
      setupSeoWorkSummaryUI();
      if (!didLoadSeoNoteOnce) loadSeoWorkSummary();
      return;
    }
    if (key === 'ppc_report') {
      buildPpcVisitsTable();
      buildPpcCampaignsTable();
      buildPpcKeywordsTable();
      buildPpcCitiesTable();
      buildPpcBehaviorTable();
      buildPpcSalesTable();
      buildPpcGoalsTable();
      renderPpcLineChart();
      return;
    }
    // all_visitors_report or segment views
    buildVisitsTable();
    buildBehaviorTable();
    buildSalesTable();
    buildGoalsTable();
    renderLineChart();
    renderDonuts();
  }

  function setupSidebarRouting() {
    document.addEventListener('click', function (e) {
      var link = e.target && e.target.closest ? e.target.closest('.report3__presetLink[data-report-view]') : null;
      if (!link) return;
      var view = String(link.getAttribute('data-report-view') || '');
      if (view !== 'seo' && view !== 'all') return; // keep others as full reload for now

      // Only intercept if both view containers exist (no broken state).
      var hasAll = !!document.querySelector('.report3__view[data-view="all"]');
      var hasSeo = !!document.querySelector('.report3__view[data-view="seo"]');
      if (view === 'seo' && !hasSeo) return;
      if (view === 'all' && !hasAll) return;

      e.preventDefault();
      window.ACTIVE_REPORT_KEY = reportKeyForView(view);
      toggleViews(view);
      setSidebarActive(view);
      updateQuickTabsForView(view);
      updateUrlViewParam(view);
      renderActiveView();
      // Keep the scroll position sensible when switching.
      try { window.scrollTo({ top: 0, behavior: 'smooth' }); } catch (e2) { window.scrollTo(0, 0); }
    });
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
  smokeRenderUsersTotal();
  renderActiveView();
  setupQuickScroll();
  setupSidebarRouting();

  // Ensure the correct view is visible on initial load.
  var initialView = viewForReportKey(window.ACTIVE_REPORT_KEY || 'all_visitors_report');
  toggleViews(initialView);
  setSidebarActive(initialView);
  updateQuickTabsForView(initialView);
  if (initialView === 'seo') {
    setupSeoWorkSummaryUI();
    loadSeoWorkSummary();
  }

  buildPpcVisitsTable();
  buildPpcCampaignsTable();
  buildPpcKeywordsTable();
  buildPpcCitiesTable();
  buildPpcBehaviorTable();
  buildPpcSalesTable();
  buildPpcGoalsTable();
  renderPpcLineChart();

  if (window.location.hash) {
    setTimeout(function () { scrollToHash(window.location.hash); }, 50);
  }
  }); // DOMContentLoaded init
})();

