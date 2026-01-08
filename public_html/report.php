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
           p.name AS project_name, p.show_sales_section
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

if ($status !== 'READY') {
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
$channels = (array)($analytics['traffic_channels'] ?? []);
$behavior = (array)($analytics['visitor_behavior'] ?? []);
$sales = $analytics['sales'] ?? null;
$seo = (array)($analytics['seo_summary'] ?? []);
$notes = (array)($snapshot['notes'] ?? []);
$workSummary = (string)($notes['work_summary'] ?? '');

$showSales = ((bool)($snapshot['project']['show_sales_section'] ?? true)) && is_array($sales);
?>

<div class="grid-2">
  <div class="card">
    <div class="card__title">Visitors overview</div>
    <div class="kpis">
      <div class="kpi"><div class="kpi__label">Users</div><div class="kpi__value"><?php echo e((string)($vis['users'] ?? '—')); ?></div></div>
      <div class="kpi"><div class="kpi__label">New users</div><div class="kpi__value"><?php echo e((string)($vis['new_users'] ?? '—')); ?></div></div>
      <div class="kpi"><div class="kpi__label">Sessions</div><div class="kpi__value"><?php echo e((string)($vis['sessions'] ?? '—')); ?></div></div>
      <div class="kpi"><div class="kpi__label">Engagement rate</div><div class="kpi__value"><?php echo e(isset($vis['engagement_rate']) ? (string)(round(((float)$vis['engagement_rate']) * 100, 1)) . '%' : '—'); ?></div></div>
      <div class="kpi"><div class="kpi__label">Avg engagement</div><div class="kpi__value"><?php echo e(isset($vis['avg_engagement_time_sec']) ? (string)$vis['avg_engagement_time_sec'] . 's' : '—'); ?></div></div>
    </div>
    <canvas id="chartVisitors" height="140"></canvas>
  </div>

  <div class="card">
    <div class="card__title">Traffic channels</div>
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
  </div>
</div>

<div class="grid-2">
  <div class="card">
    <div class="card__title">Visitor behavior</div>
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
  </div>

  <?php if ($showSales): ?>
    <div class="card">
      <div class="card__title">Sales</div>
      <div class="kpis">
        <div class="kpi"><div class="kpi__label">Revenue</div><div class="kpi__value">$<?php echo e(number_format((float)($sales['revenue'] ?? 0), 2)); ?></div></div>
        <div class="kpi"><div class="kpi__label">Transactions</div><div class="kpi__value"><?php echo e((string)($sales['transactions'] ?? 0)); ?></div></div>
        <div class="kpi"><div class="kpi__label">AOV</div><div class="kpi__value">$<?php echo e(number_format((float)($sales['aov'] ?? 0), 2)); ?></div></div>
        <div class="kpi"><div class="kpi__label">Conversion rate</div><div class="kpi__value"><?php echo e(isset($sales['conversion_rate']) ? (string)(round(((float)$sales['conversion_rate']) * 100, 2)) . '%' : '—'); ?></div></div>
      </div>
      <canvas id="chartSales" height="220"></canvas>
    </div>
  <?php else: ?>
    <div class="card">
      <div class="card__title">Sales</div>
      <p class="muted">Sales section is disabled for this project.</p>
    </div>
  <?php endif; ?>
</div>

<div class="grid-2">
  <div class="card">
    <div class="card__title">SEO summary</div>
    <div class="kpis">
      <div class="kpi"><div class="kpi__label">Clicks</div><div class="kpi__value"><?php echo e((string)($seo['clicks'] ?? '—')); ?></div></div>
      <div class="kpi"><div class="kpi__label">Impressions</div><div class="kpi__value"><?php echo e((string)($seo['impressions'] ?? '—')); ?></div></div>
      <div class="kpi"><div class="kpi__label">CTR</div><div class="kpi__value"><?php echo e(isset($seo['ctr']) ? (string)(round(((float)$seo['ctr']) * 100, 2)) . '%' : '—'); ?></div></div>
      <div class="kpi"><div class="kpi__label">Avg position</div><div class="kpi__value"><?php echo e((string)($seo['avg_position'] ?? '—')); ?></div></div>
    </div>
    <canvas id="chartSeo" height="220"></canvas>
  </div>

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
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
window.REPORT_SNAPSHOT = <?php
    echo json_encode(
        $snapshot,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
?>;
</script>
<script src="<?php echo e(url('/assets/js/charts.js')); ?>"></script>

<?php
render_footer();

