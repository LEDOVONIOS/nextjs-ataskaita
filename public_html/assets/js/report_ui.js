/* global Chart */
(function () {
  'use strict';

  var data = window.REPORT_DATA;
  if (!data || !data.sections || !data.sections.traffic) return;

  var presetNav = document.getElementById('presetNav');
  var quickTabs = document.getElementById('quickTabs');
  var sectionsRoot = document.getElementById('sectionsRoot');
  if (!presetNav || !quickTabs || !sectionsRoot) return;

  var project = data.project || {};
  var showSales = !!project.show_sales_section;
  var ranges = (data.period && data.period.date_ranges) ? data.period.date_ranges : {};

  var PRESETS = [
    { key: 'all', label: 'Visų tinklapio lankytojų ataskaita' },
    { key: 'organic_search', label: 'SEO / Organic Search' },
    { key: 'paid_search', label: 'Paid Search (PPC)' },
    { key: 'direct', label: 'Direct' },
    { key: 'display', label: 'Display' },
    { key: 'social', label: 'Organic Social / Paid Social' },
    { key: 'referral', label: 'Referral' },
    { key: 'email', label: 'Email' },
    { key: 'affiliate', label: 'Affiliate' }
  ];

  var TABS = [
    { key: 'visits', label: 'Apsilankymų duomenys', enabled: true },
    { key: 'behavior', label: 'Lankytojų elgesys', enabled: true },
    { key: 'sales', label: 'Pardavimų duomenys', enabled: showSales },
    { key: 'goals', label: 'Įgyvendinti tikslai', enabled: true }
  ];

  // Filter to only presets present in JSON (but keep "all" if possible).
  var availablePresets = PRESETS.filter(function (p) {
    return !!data.sections.traffic[p.key];
  });
  if (!availablePresets.length && data.sections.traffic.all) {
    availablePresets = [{ key: 'all', label: 'Visų tinklapio lankytojų ataskaita' }];
  }

  var activePreset = (data.sections.traffic.all ? 'all' : (availablePresets[0] ? availablePresets[0].key : 'all'));
  var charts = {};

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

  function fmtSeconds(sec) {
    if (sec == null || sec === '' || (typeof sec === 'number' && !isFinite(sec))) return '—';
    var s = Math.max(0, Math.round(Number(sec)));
    var m = Math.floor(s / 60);
    var r = s % 60;
    if (m <= 0) return String(r) + ' s';
    return String(m) + ' min ' + String(r) + ' s';
  }

  function fmtMoneyEUR(v) {
    if (v == null || v === '' || (typeof v === 'number' && !isFinite(v))) return '—';
    var num = Number(v);
    if (!isFinite(num)) return '—';
    // Keep Phase 3 simple: integer EUR.
    return '€' + Math.round(num).toLocaleString('lt-LT');
  }

  function fmtPct(v) {
    if (v == null || v === '' || (typeof v === 'number' && !isFinite(v))) return '—';
    var num = Number(v);
    if (!isFinite(num)) return '—';
    return (num * 100).toFixed(1).replace(/\.0$/, '') + '%';
  }

  function fmtValueByFormat(v, format) {
    if (format === 'money') return fmtMoneyEUR(v);
    if (format === 'pct') return fmtPct(v);
    if (format === 'float1') return fmtNumber(v, 1);
    if (format === 'seconds') return fmtSeconds(v);
    return fmtNumber(v, 0);
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
    return '<span class="report3__delta ' + cls + '">' + arrow + ' ' + esc(fmtPct(ch)) + '</span>';
  }

  function destroyChart(id) {
    if (charts[id]) {
      try { charts[id].destroy(); } catch (e) {}
      delete charts[id];
    }
  }

  function makeSectionHTML(tabKey, tabLabel) {
    return '' +
      '<section class="report3__section report-section" id="sec-' + esc(tabKey) + '">' +
        '<div class="card">' +
          '<div class="report3__sectionHead">' +
            '<div class="report3__sectionTitle">' + esc(tabLabel) + '</div>' +
            '<div class="report3__sectionRange">' +
              esc((ranges.this_start || '') + ' – ' + (ranges.this_end || '')) +
              ' · ' +
              esc((ranges.last_start || '') + ' – ' + (ranges.last_end || '')) +
            '</div>' +
          '</div>' +
          '<div class="report3__chartWrap">' +
            '<canvas id="chart-' + esc(tabKey) + '" height="160"></canvas>' +
            '<div class="report3__legendHint">This month vs last year same month</div>' +
          '</div>' +
          '<div class="report3__compare">' +
            '<div class="table-wrap">' +
              '<table class="table table--compact" id="table-' + esc(tabKey) + '"></table>' +
            '</div>' +
          '</div>' +
        '</div>' +
      '</section>';
  }

  function renderPresetNav() {
    presetNav.innerHTML = availablePresets.map(function (p) {
      var active = (p.key === activePreset) ? ' is-active' : '';
      return '' +
        '<a class="report3__presetLink' + active + '" href="#" data-preset="' + esc(p.key) + '">' +
          '<span class="report3__presetLabel">' + esc(p.label) + '</span>' +
          '<span class="report3__presetChevron">›</span>' +
        '</a>';
    }).join('');
  }

  function renderQuickTabs() {
    var tabsHtml = TABS.filter(function (t) { return t.enabled; }).map(function (t) {
      return '<a class="report3__tab" href="#sec-' + esc(t.key) + '" data-tab="' + esc(t.key) + '">' + esc(t.label) + '</a>';
    }).join('');
    quickTabs.innerHTML = tabsHtml;
  }

  function buildComparisonTable(tabData) {
    var totals = (tabData && tabData.totals) ? tabData.totals : {};
    var keys = Object.keys(totals);
    if (!keys.length) {
      return '<thead><tr><th class="muted">No data</th></tr></thead><tbody><tr><td class="muted">—</td></tr></tbody>';
    }

    var head = '<thead><tr><th></th>' + keys.map(function (k) {
      return '<th class="right">' + esc(totals[k].label || k) + '</th>';
    }).join('') + '</tr></thead>';

    function row(label, kind) {
      return '<tr><td><strong>' + esc(label) + '</strong></td>' + keys.map(function (k) {
        var m = totals[k] || {};
        if (kind === 'change') return '<td class="right">' + deltaBadge(m.this, m.last) + '</td>';
        if (kind === 'this') return '<td class="right">' + esc(fmtValueByFormat(m.this, m.format)) + '</td>';
        return '<td class="right">' + esc(fmtValueByFormat(m.last, m.format)) + '</td>';
      }).join('') + '</tr>';
    }

    var body = '<tbody>' +
      row('Pokytis', 'change') +
      row('Šis laikotarpis', 'this') +
      row('Praeitų metų tas pats mėn.', 'last') +
    '</tbody>';

    return head + body;
  }

  function renderChart(tabKey, tabData) {
    destroyChart(tabKey);
    if (!window.Chart) return;

    var ts = tabData && tabData.timeseries ? tabData.timeseries : null;
    if (!ts || !Array.isArray(ts.labels)) return;

    var canvas = document.getElementById('chart-' + tabKey);
    if (!canvas) return;
    var c = canvas.getContext('2d');
    if (!c) return;

    var labels = ts.labels || [];
    var a = Array.isArray(ts.this) ? ts.this : [];
    var b = Array.isArray(ts.last) ? ts.last : [];

    charts[tabKey] = new Chart(c, {
      type: 'line',
      data: {
        labels: labels,
        datasets: [
          {
            label: (data.period && data.period.label) ? data.period.label : 'This month',
            data: a,
            borderColor: '#4f8cff',
            backgroundColor: 'rgba(79,140,255,.10)',
            tension: 0.25,
            fill: false
          },
          {
            label: (data.period && data.period.compare_to && data.period.compare_to.label) ? data.period.compare_to.label : 'Last year',
            data: b,
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

  function renderSections() {
    var presetData = data.sections.traffic[activePreset] || data.sections.traffic.all || {};
    var tabsToRender = TABS.filter(function (t) { return t.enabled; });
    sectionsRoot.innerHTML = tabsToRender.map(function (t) {
      return makeSectionHTML(t.key, t.label);
    }).join('');

    tabsToRender.forEach(function (t) {
      var tabData = presetData[t.key] || null;
      var table = document.getElementById('table-' + t.key);
      if (table) table.innerHTML = buildComparisonTable(tabData);
      renderChart(t.key, tabData);
    });
  }

  function scrollToHash(hash) {
    var el = document.querySelector(hash);
    if (!el) return;
    el.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function setActiveTab(tabKey) {
    var tabs = quickTabs.querySelectorAll('.report3__tab');
    for (var i = 0; i < tabs.length; i++) {
      tabs[i].classList.toggle('is-active', tabs[i].getAttribute('data-tab') === tabKey);
    }
  }

  function setupActiveTabObserver() {
    var sections = Array.prototype.slice.call(document.querySelectorAll('.report3__section[id]'));
    if (!sections.length) return;

    if ('IntersectionObserver' in window) {
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) {
          if (en.isIntersecting) {
            var id = en.target.id || '';
            var key = id.replace(/^sec-/, '');
            setActiveTab(key);
          }
        });
      }, { root: null, rootMargin: '-25% 0px -65% 0px', threshold: 0.01 });
      sections.forEach(function (s) { io.observe(s); });
      return;
    }

    window.addEventListener('scroll', function () {
      var best = null;
      var bestTop = -Infinity;
      for (var i = 0; i < sections.length; i++) {
        var r = sections[i].getBoundingClientRect();
        if (r.top < 160 && r.top > bestTop) {
          bestTop = r.top;
          best = sections[i];
        }
      }
      if (best) setActiveTab(best.id.replace(/^sec-/, ''));
    }, { passive: true });
  }

  // Clicks
  document.addEventListener('click', function (e) {
    var presetLink = e.target && e.target.closest ? e.target.closest('a[data-preset]') : null;
    if (presetLink) {
      e.preventDefault();
      var k = presetLink.getAttribute('data-preset');
      if (k && k !== activePreset) {
        activePreset = k;
        renderPresetNav();
        renderSections();
        setupActiveTabObserver();
      }
      return;
    }

    var tabLink = e.target && e.target.closest ? e.target.closest('.report3__tab[href^="#"]') : null;
    if (tabLink) {
      var href = tabLink.getAttribute('href');
      if (!href) return;
      var target = document.querySelector(href);
      if (!target) return;
      e.preventDefault();
      history.replaceState(null, '', href);
      scrollToHash(href);
    }
  });

  // Initial render
  renderPresetNav();
  renderQuickTabs();
  renderSections();
  setupActiveTabObserver();

  // If loaded with a hash, scroll after render.
  if (window.location.hash) {
    setTimeout(function () { scrollToHash(window.location.hash); }, 50);
  }
})();

