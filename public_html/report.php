<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

require_login();
$pdo = db();
$u = current_user();
$userId = (int)$u['id'];
$role = (string)$u['role'];

$reportId = safe_int($_GET['id'] ?? 0, 0);
if ($reportId <= 0) {
    http_response_code(400);
    echo 'Bad Request';
    exit;
}

$stmt = $pdo->prepare('
    SELECT r.id, r.project_id, r.year, r.month, r.status, r.generated_at, r.data_json,
           p.name AS project_name, p.show_sales_section, p.ga4_property_id, p.gsc_site_url
    FROM monthly_reports r
    INNER JOIN projects p ON p.id = r.project_id
    WHERE r.id = ?
    LIMIT 1
');
$stmt->execute([$reportId]);
$row = $stmt->fetch();
if (!$row) {
    http_response_code(404);
    echo 'Not Found';
    exit;
}

$projectId = (int)$row['project_id'];
if (!user_can_access_project($pdo, $userId, $role, $projectId)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$status = (string)$row['status'];
$year = (int)$row['year'];
$month = (int)$row['month'];
$title = 'Report: ' . (string)$row['project_name'] . ' — ' . $year . '-' . str_pad((string)$month, 2, '0', STR_PAD_LEFT);

render_header($title);

if ($status !== 'READY' && $status !== 'PARTIAL') {
    echo '<div class="card"><p>Report status: <strong>' . e($status) . '</strong></p><p><a class="btn" href="' . e(url('/dashboard.php')) . '">Back</a></p></div>';
    render_footer();
    exit;
}

$dataJson = $row['data_json'];
$snapshot = null;
if (is_string($dataJson) && $dataJson !== '') {
    $snapshot = json_decode($dataJson, true);
}
if (!is_array($snapshot)) {
    echo '<div class="card"><p>Report data is missing or invalid.</p><p><a class="btn" href="' . e(url('/dashboard.php')) . '">Back</a></p></div>';
    render_footer();
    exit;
}

$analytics = (array)($snapshot['analytics'] ?? []);
$vis = (array)($analytics['visitors_overview'] ?? []);
$visTotals = isset($vis['totals']) && is_array($vis['totals']) ? (array)$vis['totals'] : $vis;
$channels = (array)($analytics['traffic_channels'] ?? []);
$behavior = (array)($analytics['visitor_behavior'] ?? []);
$sales = $analytics['sales'] ?? null;
$seo = (array)($analytics['seo_summary'] ?? []);
$notes = (array)($snapshot['notes'] ?? []);
$workSummary = (string)($notes['work_summary'] ?? '');
$errors = (array)($snapshot['errors'] ?? []);

// ---- Snapshot transparency helpers (support old snapshots w/o meta.sections) ----
function normalize_snapshot_sections_meta(array $snapshot): array
{
    $meta = isset($snapshot['meta']) && is_array($snapshot['meta']) ? (array)$snapshot['meta'] : [];
    $sections = isset($meta['sections']) && is_array($meta['sections']) ? (array)$meta['sections'] : [];
    if ($sections) {
        return $sections;
    }

    $errors = isset($snapshot['errors']) && is_array($snapshot['errors']) ? (array)$snapshot['errors'] : [];
    $ga4Used = (bool)($meta['ga4Used'] ?? false);

    $base = [
        'visitors_overview' => ['source' => 'MOCK', 'ok' => true],
        'traffic_channels' => ['source' => 'MOCK', 'ok' => true],
        'visitor_behavior' => ['source' => 'MOCK', 'ok' => true],
        'sales' => ['source' => 'MOCK', 'ok' => true],
        'seo_summary' => ['source' => 'MOCK', 'ok' => true],
        'email_marketing' => ['source' => 'MOCK', 'ok' => true],
        'affiliate' => ['source' => 'MOCK', 'ok' => true],
    ];

    if ($ga4Used) {
        $base['visitors_overview'] = ['source' => 'GA4', 'ok' => true];
        return $base;
    }

    $ga4Err = isset($errors['ga4']) && is_array($errors['ga4']) ? (array)$errors['ga4'] : [];
    $msg = (string)($ga4Err['message'] ?? '');
    if ($msg !== '') {
        $base['visitors_overview'] = ['source' => 'MOCK', 'ok' => false, 'error' => $msg];
    }

    return $base;
}

function snapshot_section_is_real(array $sectionsMeta, string $key): bool
{
    $m = isset($sectionsMeta[$key]) && is_array($sectionsMeta[$key]) ? (array)$sectionsMeta[$key] : [];
    $source = strtoupper(trim((string)($m['source'] ?? 'MOCK')));
    $ok = (bool)($m['ok'] ?? false);
    return $source !== 'MOCK' && $ok;
}

function snapshot_section_badge(array $sectionsMeta, string $key): string
{
    $m = isset($sectionsMeta[$key]) && is_array($sectionsMeta[$key]) ? (array)$sectionsMeta[$key] : [];
    $source = strtoupper(trim((string)($m['source'] ?? 'MOCK')));
    $ok = (bool)($m['ok'] ?? false);
    $isReal = $source !== 'MOCK' && $ok;
    $label = $isReal ? 'REAL' : 'MOCK';
    $title = $isReal ? ('Source: ' . $source) : 'Source: MOCK';
    $cls = $isReal ? 'source-badge source-badge--real' : 'source-badge source-badge--mock';
    return '<span class="' . e($cls) . '" title="' . e($title) . '">' . e($label) . '</span>';
}

$sectionsMeta = normalize_snapshot_sections_meta($snapshot);

// Project config from DB (more reliable for old snapshots).
$projectCfg = [
    'show_sales_section' => ((int)($row['show_sales_section'] ?? 1)) === 1,
    'gsc_site_url' => trim((string)($row['gsc_site_url'] ?? '')),
];

$salesMetaIsReal = snapshot_section_is_real($sectionsMeta, 'sales');
$showSales = $projectCfg['show_sales_section'] && $salesMetaIsReal && is_array($sales);

// Sidebar visibility rules (Phase 2.1): based on snapshot.meta.sections and/or project fields.
$showPpc = isset($sectionsMeta['traffic_channels']);
$showSeo = isset($sectionsMeta['seo_summary']) || $projectCfg['gsc_site_url'] !== '';
$showEmail = isset($sectionsMeta['email_marketing']);
$showAffiliate = isset($sectionsMeta['affiliate']);

// Provide meta.sections to JS even for old snapshots (read-only in-memory augmentation).
$snapshotForJs = $snapshot;
if (!isset($snapshotForJs['meta']) || !is_array($snapshotForJs['meta'])) {
    $snapshotForJs['meta'] = [];
}
$snapshotForJs['meta']['sections'] = $sectionsMeta;
?>

<?php if ($status === 'PARTIAL'): ?>
  <div class="alert alert--warn">
    <?php
      $ga4Err = (array)($errors['ga4'] ?? []);
      $msg = (string)($ga4Err['message'] ?? 'Report is PARTIAL.');
      echo e($msg);
    ?>
  </div>
<?php endif; ?>

<div class="report-layout">
  <aside class="report-sidebar" aria-label="Report navigation">
    <div class="card sidebar-card">
      <div class="sidebar-title">On this report</div>
      <div class="muted sidebar-meta">
        <?php
          $genAt = (string)($snapshot['meta']['generatedAt'] ?? ($row['generated_at'] ?? ''));
          $mode = (string)($snapshot['meta']['mode'] ?? '');
        ?>
        <div>Generated: <?php echo e($genAt !== '' ? $genAt : '—'); ?></div>
        <div>Mode: <?php echo e($mode !== '' ? $mode : '—'); ?></div>
      </div>
      <nav class="sidebar-nav">
        <a class="sidebar-link" href="#overview">Overview</a>
        <?php if ($showPpc): ?><a class="sidebar-link" href="#ppc">PPC</a><?php endif; ?>
        <?php if ($showSeo): ?><a class="sidebar-link" href="#seo">SEO</a><?php endif; ?>
        <?php if ($showEmail): ?><a class="sidebar-link" href="#email">Email marketing</a><?php endif; ?>
        <?php if ($showAffiliate): ?><a class="sidebar-link" href="#affiliate">Affiliate</a><?php endif; ?>
        <a class="sidebar-link" href="#notes">Notes</a>
      </nav>
    </div>
  </aside>

  <div class="report-content">
    <section id="overview" class="report-section">
      <div class="report-section__title">Overview</div>

      <div class="grid-2">
        <div class="card">
          <div class="card__header">
            <div class="card__title">Visitors overview <?php echo snapshot_section_badge($sectionsMeta, 'visitors_overview'); ?></div>
          </div>

          <?php if (snapshot_section_is_real($sectionsMeta, 'visitors_overview')): ?>
            <div class="kpis">
              <div class="kpi"><div class="kpi__label">Users</div><div class="kpi__value"><?php echo e((string)($visTotals['users'] ?? '—')); ?></div></div>
              <div class="kpi"><div class="kpi__label">New users</div><div class="kpi__value"><?php echo e((string)($visTotals['new_users'] ?? '—')); ?></div></div>
              <div class="kpi"><div class="kpi__label">Sessions</div><div class="kpi__value"><?php echo e((string)($visTotals['sessions'] ?? '—')); ?></div></div>
              <div class="kpi"><div class="kpi__label">Engagement rate</div><div class="kpi__value"><?php echo e(isset($visTotals['engagement_rate']) ? (string)(round(((float)$visTotals['engagement_rate']) * 100, 1)) . '%' : '—'); ?></div></div>
              <div class="kpi"><div class="kpi__label">Avg session duration</div><div class="kpi__value"><?php echo e(isset($visTotals['avg_session_duration_sec']) ? (string)$visTotals['avg_session_duration_sec'] . 's' : (isset($visTotals['avg_engagement_time_sec']) ? (string)$visTotals['avg_engagement_time_sec'] . 's' : '—')); ?></div></div>
            </div>
            <canvas id="chartVisitors" height="140"></canvas>
          <?php else: ?>
            <p class="muted">Not configured yet. Will be enabled in the next phase.</p>
            <?php
              $m = (array)($sectionsMeta['visitors_overview'] ?? []);
              $err = (string)($m['error'] ?? '');
            ?>
            <?php if ($err !== ''): ?>
              <details class="muted" style="margin-top:8px">
                <summary>Why this is MOCK</summary>
                <div style="margin-top:8px"><?php echo e($err); ?></div>
              </details>
            <?php endif; ?>
          <?php endif; ?>
        </div>

        <div class="card">
          <div class="card__header">
            <div class="card__title">Visitor behavior <?php echo snapshot_section_badge($sectionsMeta, 'visitor_behavior'); ?></div>
          </div>

          <?php if (snapshot_section_is_real($sectionsMeta, 'visitor_behavior')): ?>
            <div class="kpis">
              <div class="kpi"><div class="kpi__label">Pages / session</div><div class="kpi__value"><?php echo e((string)($behavior['pages_per_session'] ?? '—')); ?></div></div>
              <div class="kpi"><div class="kpi__label">Avg session duration</div><div class="kpi__value"><?php echo e(isset($behavior['avg_session_duration_sec']) ? (string)$behavior['avg_session_duration_sec'] . 's' : '—'); ?></div></div>
            </div>
            <div class="card__subtitle">Top pages</div>
            <div class="table-wrap">
              <table class="table table--compact">
                <thead><tr><th>Path</th><th class="right">Views</th></tr></thead>
                <tbody>
                  <?php foreach ((array)($behavior['top_pages'] ?? []) as $p): ?>
                    <tr>
                      <td><code><?php echo e((string)($p['path'] ?? '')); ?></code></td>
                      <td class="right"><?php echo e((string)($p['views'] ?? 0)); ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <p class="muted">Not configured yet. Will be enabled in the next phase.</p>
          <?php endif; ?>
        </div>
      </div>

      <div class="card">
        <div class="card__header">
          <div class="card__title">Sales <?php echo snapshot_section_badge($sectionsMeta, 'sales'); ?></div>
        </div>

        <?php if (!$projectCfg['show_sales_section']): ?>
          <p class="muted">Sales section is disabled for this project.</p>
        <?php elseif ($showSales): ?>
          <div class="kpis">
            <div class="kpi"><div class="kpi__label">Revenue</div><div class="kpi__value">$<?php echo e(number_format((float)($sales['revenue'] ?? 0), 2)); ?></div></div>
            <div class="kpi"><div class="kpi__label">Transactions</div><div class="kpi__value"><?php echo e((string)($sales['transactions'] ?? 0)); ?></div></div>
            <div class="kpi"><div class="kpi__label">AOV</div><div class="kpi__value">$<?php echo e(number_format((float)($sales['aov'] ?? 0), 2)); ?></div></div>
            <div class="kpi"><div class="kpi__label">Conversion rate</div><div class="kpi__value"><?php echo e(isset($sales['conversion_rate']) ? (string)(round(((float)$sales['conversion_rate']) * 100, 2)) . '%' : '—'); ?></div></div>
          </div>
          <canvas id="chartSales" height="220"></canvas>
        <?php else: ?>
          <p class="muted">Sales requires GA4 Ecommerce events (Phase 4). This section will remain unavailable until Ecommerce tracking is implemented.</p>
        <?php endif; ?>
      </div>
    </section>

    <?php if ($showPpc): ?>
      <section id="ppc" class="report-section">
        <div class="report-section__title">PPC</div>
        <div class="card">
          <div class="card__header">
            <div class="card__title">Traffic channels <?php echo snapshot_section_badge($sectionsMeta, 'traffic_channels'); ?></div>
          </div>
          <?php if (snapshot_section_is_real($sectionsMeta, 'traffic_channels')): ?>
            <canvas id="chartChannels" height="220"></canvas>
            <div class="table-wrap">
              <table class="table table--compact">
                <thead>
                  <tr><th>Channel</th><th class="right">Users</th><th class="right">Sessions</th></tr>
                </thead>
                <tbody>
                  <?php foreach ($channels as $c): ?>
                    <tr>
                      <td><?php echo e((string)($c['channel'] ?? '')); ?></td>
                      <td class="right"><?php echo e((string)($c['users'] ?? 0)); ?></td>
                      <td class="right"><?php echo e((string)($c['sessions'] ?? 0)); ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <p class="muted">Not configured yet. Will be enabled in the next phase.</p>
          <?php endif; ?>
        </div>
      </section>
    <?php endif; ?>

    <?php if ($showSeo): ?>
      <section id="seo" class="report-section">
        <div class="report-section__title">SEO</div>
        <div class="card">
          <div class="card__header">
            <div class="card__title">SEO summary <?php echo snapshot_section_badge($sectionsMeta, 'seo_summary'); ?></div>
          </div>
          <?php if (snapshot_section_is_real($sectionsMeta, 'seo_summary')): ?>
            <div class="kpis">
              <div class="kpi"><div class="kpi__label">Clicks</div><div class="kpi__value"><?php echo e((string)($seo['clicks'] ?? '—')); ?></div></div>
              <div class="kpi"><div class="kpi__label">Impressions</div><div class="kpi__value"><?php echo e((string)($seo['impressions'] ?? '—')); ?></div></div>
              <div class="kpi"><div class="kpi__label">CTR</div><div class="kpi__value"><?php echo e(isset($seo['ctr']) ? (string)(round(((float)$seo['ctr']) * 100, 2)) . '%' : '—'); ?></div></div>
              <div class="kpi"><div class="kpi__label">Avg position</div><div class="kpi__value"><?php echo e((string)($seo['avg_position'] ?? '—')); ?></div></div>
            </div>
            <canvas id="chartSeo" height="220"></canvas>
          <?php else: ?>
            <p class="muted">Not configured yet. Will be enabled in the next phase.</p>
            <?php if ($projectCfg['gsc_site_url'] === ''): ?>
              <p class="muted">Tip: add a GSC Site URL in Admin → Projects to enable SEO in a future phase.</p>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </section>
    <?php endif; ?>

    <?php if ($showEmail): ?>
      <section id="email" class="report-section">
        <div class="report-section__title">Email marketing</div>
        <div class="card">
          <div class="card__header">
            <div class="card__title">Email marketing <?php echo snapshot_section_badge($sectionsMeta, 'email_marketing'); ?></div>
          </div>
          <p class="muted">Not configured yet. Will be enabled in the next phase.</p>
        </div>
      </section>
    <?php endif; ?>

    <?php if ($showAffiliate): ?>
      <section id="affiliate" class="report-section">
        <div class="report-section__title">Affiliate</div>
        <div class="card">
          <div class="card__header">
            <div class="card__title">Affiliate <?php echo snapshot_section_badge($sectionsMeta, 'affiliate'); ?></div>
          </div>
          <p class="muted">Not configured yet. Will be enabled in the next phase.</p>
        </div>
      </section>
    <?php endif; ?>

    <section id="notes" class="report-section">
      <div class="report-section__title">Notes</div>
      <div class="card">
        <div class="card__title">Monthly work summary (snapshot)</div>
        <?php if (trim($workSummary) === ''): ?>
          <p class="muted">No work summary was included at generation time.</p>
        <?php else: ?>
          <div class="prose"><?php echo nl2br(e($workSummary)); ?></div>
        <?php endif; ?>
        <div class="card__actions">
          <a class="btn" href="<?php echo e(url('/dashboard.php')); ?>">Back to dashboard</a>
        </div>
      </div>
    </section>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
window.REPORT_SNAPSHOT = <?php
    echo json_encode(
        $snapshotForJs,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
?>;
</script>
<script src="<?php echo e(url('/assets/js/charts.js')); ?>"></script>
<script>
(function () {
  'use strict';
  var links = document.querySelectorAll('.sidebar-link');
  function setActive(hash) {
    for (var i = 0; i < links.length; i++) {
      var a = links[i];
      if (a.getAttribute('href') === hash) a.classList.add('is-active');
      else a.classList.remove('is-active');
    }
  }
  function onHash() {
    var h = window.location.hash || '#overview';
    setActive(h);
  }
  window.addEventListener('hashchange', onHash);
  onHash();
})();
</script>

<?php
render_footer();

