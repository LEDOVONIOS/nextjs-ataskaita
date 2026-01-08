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

$notes = (array)($snapshot['notes'] ?? []);
$workSummary = (string)($notes['work_summary'] ?? '');
$errors = (array)($snapshot['errors'] ?? []);

// ---- Phase 3 helpers (stable schema + backward compatibility) ----
function fmt_pct(?float $p): string
{
    if ($p === null) {
        return '—';
    }
    return (string)round($p * 100, 1) . '%';
}

function fmt_int(?float $v): string
{
    if ($v === null) {
        return '—';
    }
    return number_format((float)$v, 0, '.', ' ');
}

function fmt_money(?float $v): string
{
    if ($v === null) {
        return '—';
    }
    return '€' . number_format((float)$v, 0, '.', ' ');
}

function phase3_pct_change(?float $current, ?float $previous): ?float
{
    if ($current === null || $previous === null) {
        return null;
    }
    if ($previous == 0.0) {
        return null;
    }
    return ($current - $previous) / $previous;
}

function phase3_channel_list(): array
{
    return [
        'Paid Search',
        'Direct',
        'Organic Social',
        'Google Ads Performance Max / Smart Shopping',
        'Paid Social',
        'Referral',
        'Email',
        'Display',
    ];
}

function phase3_build_channels_from_total(float $thisTotal, float $lastTotal): array
{
    // Fixed, plausible shares (kept stable; Phase 4 will map real GA buckets).
    $shares = [
        'Paid Search' => 0.14,
        'Direct' => 0.20,
        'Organic Social' => 0.08,
        'Google Ads Performance Max / Smart Shopping' => 0.10,
        'Paid Social' => 0.09,
        'Referral' => 0.07,
        'Email' => 0.05,
        'Display' => 0.04,
    ];
    $sum = array_sum($shares) ?: 1.0;
    $rows = [];

    $remainingThis = (float)$thisTotal;
    $remainingLast = (float)$lastTotal;
    $i = 0;
    $keys = array_keys($shares);
    foreach ($keys as $k) {
        $isLast = ($i === count($keys) - 1);
        $share = $shares[$k] / $sum;
        $vThis = $isLast ? $remainingThis : floor($thisTotal * $share);
        $vLast = $isLast ? $remainingLast : floor($lastTotal * $share);
        $remainingThis -= $vThis;
        $remainingLast -= $vLast;
        $rows[] = [
            'channel' => $k,
            'this_month' => (float)max(0, $vThis),
            'last_year' => (float)max(0, $vLast),
            'change_pct' => phase3_pct_change((float)$vThis, (float)$vLast),
        ];
        $i++;
    }

    array_unshift($rows, [
        'channel' => 'All visitors',
        'this_month' => (float)$thisTotal,
        'last_year' => (float)$lastTotal,
        'change_pct' => phase3_pct_change((float)$thisTotal, (float)$lastTotal),
    ]);

    return $rows;
}

function phase3_empty_section(string $title, string $metricLabel, string $metricFormat): array
{
    return [
        'title' => $title,
        'metric' => ['label' => $metricLabel, 'format' => $metricFormat],
        'summary' => ['change_pct' => null, 'this_month' => null, 'last_year' => null],
        'chart' => ['labels' => [], 'this_month' => [], 'last_year' => [], 'label_this_month' => '', 'label_last_year' => ''],
        'channels' => [],
    ];
}

function normalize_snapshot_phase3(array $snapshot, array $row): array
{
    // If already Phase 3 schema, just enforce required keys.
    if (isset($snapshot['meta'], $snapshot['visitors'], $snapshot['behavior'], $snapshot['sales'], $snapshot['goals'], $snapshot['seo'])) {
        foreach (['meta', 'overview', 'visitors', 'behavior', 'sales', 'goals', 'seo', 'notes', 'errors'] as $k) {
            if (!array_key_exists($k, $snapshot)) {
                $snapshot[$k] = null;
            }
        }
        if (!isset($snapshot['notes']) || !is_array($snapshot['notes'])) {
            $snapshot['notes'] = ['work_summary' => ''];
        }
        if (!isset($snapshot['errors']) || !is_array($snapshot['errors'])) {
            $snapshot['errors'] = [];
        }
        return $snapshot;
    }

    // Older snapshots: best-effort upgrade into Phase 3 shape (structure-first).
    $year = (int)($row['year'] ?? 0);
    $month = (int)($row['month'] ?? 0);
    $projectId = (int)($row['project_id'] ?? 0);
    $showSales = ((int)($row['show_sales_section'] ?? 1)) === 1;

    $analytics = isset($snapshot['analytics']) && is_array($snapshot['analytics']) ? (array)$snapshot['analytics'] : [];
    $vis = isset($analytics['visitors_overview']) && is_array($analytics['visitors_overview']) ? (array)$analytics['visitors_overview'] : [];
    $visTotals = isset($vis['totals']) && is_array($vis['totals']) ? (array)$vis['totals'] : $vis;
    $usersThis = (float)($visTotals['users'] ?? 0);
    $usersLast = 0.0;
    if (isset($vis['last_year']['totals']['users'])) {
        $usersLast = (float)$vis['last_year']['totals']['users'];
    } elseif ($usersThis > 0) {
        $usersLast = (float)round($usersThis / 1.12, 0);
    }

    $salesOld = $analytics['sales'] ?? null;
    $revThis = ($showSales && is_array($salesOld)) ? (float)($salesOld['revenue'] ?? 0) : null;

    $workSummary = '';
    if (isset($snapshot['notes']) && is_array($snapshot['notes'])) {
        $workSummary = (string)($snapshot['notes']['work_summary'] ?? '');
    }

    $meta = [
        'generatedAt' => (string)($snapshot['meta']['generatedAt'] ?? ($snapshot['generated_at_utc'] ?? '')),
        'schemaVersion' => 'phase3',
        'mode' => 'MOCK',
        'reportStatus' => 'READY',
        'project' => [
            'id' => $projectId,
            'name' => (string)($row['project_name'] ?? ''),
            'showSalesSection' => $showSales,
        ],
        'period' => [
            'year' => $year,
            'month' => $month,
        ],
    ];

    $visitors = [
        'title' => 'Apsilankymų duomenys',
        'metric' => ['label' => 'Users', 'format' => 'int'],
        'summary' => [
            'change_pct' => phase3_pct_change($usersThis, $usersLast),
            'this_month' => $usersThis,
            'last_year' => $usersLast,
        ],
        'chart' => [
            'labels' => [],
            'this_month' => [],
            'last_year' => [],
            'label_this_month' => sprintf('%04d-%02d', $year, $month),
            'label_last_year' => sprintf('%04d-%02d', $year - 1, $month),
        ],
        'channels' => phase3_build_channels_from_total($usersThis, $usersLast),
    ];

    $behavior = phase3_empty_section('Lankytojų elgesys', 'Engagement rate', 'pct');
    $sales = $showSales
        ? phase3_empty_section('Pardavimų duomenys', 'Revenue', 'money')
        : ['title' => 'Pardavimų duomenys', 'visible' => false] + phase3_empty_section('Pardavimų duomenys', 'Revenue', 'money');
    if ($showSales && $revThis !== null) {
        $sales['summary']['this_month'] = $revThis;
        $sales['summary']['last_year'] = $revThis > 0 ? round($revThis / 1.10, 0) : 0.0;
        $sales['summary']['change_pct'] = phase3_pct_change((float)$sales['summary']['this_month'], (float)$sales['summary']['last_year']);
        $sales['channels'] = phase3_build_channels_from_total((float)$sales['summary']['this_month'], (float)$sales['summary']['last_year']);
    }

    $goals = phase3_empty_section('Įgyvendinti tikslai', 'Goal completions', 'int');
    $seo = phase3_empty_section('SEO', 'Clicks', 'int');

    return [
        'meta' => $meta,
        'overview' => [
            'title' => 'Overview',
            'kpis' => [
                ['label' => 'Users', 'value' => $usersThis],
                ['label' => 'Revenue', 'value' => $revThis],
            ],
        ],
        'visitors' => $visitors,
        'behavior' => $behavior,
        'sales' => $sales,
        'goals' => $goals,
        'seo' => $seo,
        'notes' => ['work_summary' => $workSummary],
        'errors' => [],
    ];
}

// Project config from DB (more reliable for old snapshots).
$projectCfg = [
    'show_sales_section' => ((int)($row['show_sales_section'] ?? 1)) === 1,
    'gsc_site_url' => trim((string)($row['gsc_site_url'] ?? '')),
];

$snapshotPhase3 = normalize_snapshot_phase3($snapshot, $row);

$salesVisible = $projectCfg['show_sales_section'];
if (isset($snapshotPhase3['sales']) && is_array($snapshotPhase3['sales'])) {
    if (array_key_exists('visible', $snapshotPhase3['sales']) && $snapshotPhase3['sales']['visible'] === false) {
        $salesVisible = false;
    }
}

$showSales = $salesVisible;
$showSeo = true; // Phase 3: SEO is always shown (MOCK for now).
$showPpc = true;
$showEmail = true;
$showAffiliate = true;
?>

<div class="report-layout">
  <aside class="report-sidebar" aria-label="Report navigation">
    <div class="card sidebar-card">
      <div class="sidebar-title">On this report</div>
      <div class="muted sidebar-meta">
        <?php
          $genAt = (string)($snapshotPhase3['meta']['generatedAt'] ?? ($row['generated_at'] ?? ''));
          $mode = (string)($snapshotPhase3['meta']['mode'] ?? 'MOCK');
        ?>
        <div>Generated: <?php echo e($genAt !== '' ? $genAt : '—'); ?></div>
        <div>Mode: <?php echo e($mode !== '' ? $mode : '—'); ?></div>
      </div>
      <nav class="sidebar-nav">
        <a class="sidebar-link" href="#overview">Overview</a>
        <a class="sidebar-link" href="#visitors">Apsilankymų duomenys</a>
        <a class="sidebar-link" href="#behavior">Lankytojų elgesys</a>
        <?php if ($showSales): ?><a class="sidebar-link" href="#sales">Pardavimų duomenys</a><?php endif; ?>
        <a class="sidebar-link" href="#goals">Įgyvendinti tikslai</a>
        <a class="sidebar-link" href="#seo">SEO</a>
        <a class="sidebar-link" href="#ppc">PPC</a>
        <a class="sidebar-link" href="#email">Email marketing</a>
        <a class="sidebar-link" href="#affiliate">Affiliate</a>
        <a class="sidebar-link" href="#notes">Notes</a>
      </nav>
    </div>
  </aside>

  <div class="report-content">
    <div class="report-quick-tabs" role="navigation" aria-label="Quick navigation">
      <a class="quick-tab" href="#visitors">Apsilankymai</a>
      <a class="quick-tab" href="#behavior">Elgesys</a>
      <?php if ($showSales): ?><a class="quick-tab" href="#sales">Pardavimai</a><?php endif; ?>
      <a class="quick-tab" href="#goals">Tikslai</a>
    </div>

    <section id="overview" class="report-section">
      <div class="report-section__title">Overview</div>
      <div class="card">
        <div class="card__title">Final report structure (Phase 3)</div>
        <p class="muted">Data is currently MOCK. HTML structure + snapshot JSON schema are final for Phase 4 integrations.</p>
      </div>
    </section>

    <?php
      $visitors = is_array($snapshotPhase3['visitors'] ?? null) ? (array)$snapshotPhase3['visitors'] : phase3_empty_section('Apsilankymų duomenys', 'Users', 'int');
      $behavior = is_array($snapshotPhase3['behavior'] ?? null) ? (array)$snapshotPhase3['behavior'] : phase3_empty_section('Lankytojų elgesys', 'Engagement rate', 'pct');
      $salesSection = is_array($snapshotPhase3['sales'] ?? null) ? (array)$snapshotPhase3['sales'] : phase3_empty_section('Pardavimų duomenys', 'Revenue', 'money');
      $goals = is_array($snapshotPhase3['goals'] ?? null) ? (array)$snapshotPhase3['goals'] : phase3_empty_section('Įgyvendinti tikslai', 'Goal completions', 'int');
      $seo = is_array($snapshotPhase3['seo'] ?? null) ? (array)$snapshotPhase3['seo'] : phase3_empty_section('SEO', 'Clicks', 'int');

      function format_metric(?float $v, string $format): string {
          return match ($format) {
              'money' => fmt_money($v),
              'pct' => ($v === null ? '—' : (string)round($v * 100, 1) . '%'),
              'seconds' => ($v === null ? '—' : fmt_int($v) . 's'),
              default => fmt_int($v),
          };
      }
    ?>

    <section id="visitors" class="report-section">
      <div class="report-section__title"><?php echo e((string)($visitors['title'] ?? 'Apsilankymų duomenys')); ?></div>
      <div class="card">
        <div class="card__header">
          <div class="card__title"><?php echo e((string)($visitors['title'] ?? 'Apsilankymų duomenys')); ?></div>
        </div>
        <canvas id="chartVisitorsLine" height="160"></canvas>

        <div class="card__subtitle">Summary</div>
        <div class="table-wrap">
          <table class="table table--compact">
            <thead><tr><th></th><th class="right">Change %</th><th class="right">This month</th><th class="right">Last year same month</th></tr></thead>
            <tbody>
              <?php
                $fmt = (string)(($visitors['metric']['format'] ?? 'int'));
                $label = (string)(($visitors['metric']['label'] ?? 'Value'));
                $sum = is_array($visitors['summary'] ?? null) ? (array)$visitors['summary'] : [];
              ?>
              <tr>
                <td><strong><?php echo e($label); ?></strong></td>
                <td class="right"><?php echo e(fmt_pct(isset($sum['change_pct']) ? (float)$sum['change_pct'] : null)); ?></td>
                <td class="right"><?php echo e(format_metric(isset($sum['this_month']) ? (float)$sum['this_month'] : null, $fmt)); ?></td>
                <td class="right"><?php echo e(format_metric(isset($sum['last_year']) ? (float)$sum['last_year'] : null, $fmt)); ?></td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="card__subtitle">Channel breakdown</div>
        <div class="table-wrap">
          <table class="table table--compact">
            <thead><tr><th>Channel</th><th class="right">Change %</th><th class="right">This month</th><th class="right">Last year same month</th></tr></thead>
            <tbody>
              <?php foreach ((array)($visitors['channels'] ?? []) as $r): ?>
                <tr>
                  <td><?php echo e((string)($r['channel'] ?? '')); ?></td>
                  <td class="right"><?php echo e(fmt_pct(isset($r['change_pct']) ? (float)$r['change_pct'] : null)); ?></td>
                  <td class="right"><?php echo e(format_metric(isset($r['this_month']) ? (float)$r['this_month'] : null, 'int')); ?></td>
                  <td class="right"><?php echo e(format_metric(isset($r['last_year']) ? (float)$r['last_year'] : null, 'int')); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <section id="behavior" class="report-section">
      <div class="report-section__title"><?php echo e((string)($behavior['title'] ?? 'Lankytojų elgesys')); ?></div>
      <div class="card">
        <div class="card__header">
          <div class="card__title"><?php echo e((string)($behavior['title'] ?? 'Lankytojų elgesys')); ?></div>
        </div>
        <canvas id="chartBehaviorLine" height="160"></canvas>

        <div class="card__subtitle">Summary</div>
        <div class="table-wrap">
          <table class="table table--compact">
            <thead><tr><th></th><th class="right">Change %</th><th class="right">This month</th><th class="right">Last year same month</th></tr></thead>
            <tbody>
              <?php
                $fmt = (string)(($behavior['metric']['format'] ?? 'pct'));
                $label = (string)(($behavior['metric']['label'] ?? 'Value'));
                $sum = is_array($behavior['summary'] ?? null) ? (array)$behavior['summary'] : [];
              ?>
              <tr>
                <td><strong><?php echo e($label); ?></strong></td>
                <td class="right"><?php echo e(fmt_pct(isset($sum['change_pct']) ? (float)$sum['change_pct'] : null)); ?></td>
                <td class="right"><?php echo e(format_metric(isset($sum['this_month']) ? (float)$sum['this_month'] : null, $fmt)); ?></td>
                <td class="right"><?php echo e(format_metric(isset($sum['last_year']) ? (float)$sum['last_year'] : null, $fmt)); ?></td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="card__subtitle">Channel breakdown</div>
        <div class="table-wrap">
          <table class="table table--compact">
            <thead><tr><th>Channel</th><th class="right">Change %</th><th class="right">This month</th><th class="right">Last year same month</th></tr></thead>
            <tbody>
              <?php foreach ((array)($behavior['channels'] ?? []) as $r): ?>
                <tr>
                  <td><?php echo e((string)($r['channel'] ?? '')); ?></td>
                  <td class="right"><?php echo e(fmt_pct(isset($r['change_pct']) ? (float)$r['change_pct'] : null)); ?></td>
                  <td class="right"><?php echo e(format_metric(isset($r['this_month']) ? (float)$r['this_month'] : null, 'int')); ?></td>
                  <td class="right"><?php echo e(format_metric(isset($r['last_year']) ? (float)$r['last_year'] : null, 'int')); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <?php if ($showSales): ?>
      <section id="sales" class="report-section">
        <div class="report-section__title"><?php echo e((string)($salesSection['title'] ?? 'Pardavimų duomenys')); ?></div>
        <div class="card">
          <div class="card__header">
            <div class="card__title"><?php echo e((string)($salesSection['title'] ?? 'Pardavimų duomenys')); ?></div>
          </div>
          <canvas id="chartSalesLine" height="160"></canvas>

          <div class="card__subtitle">Summary</div>
          <div class="table-wrap">
            <table class="table table--compact">
              <thead><tr><th></th><th class="right">Change %</th><th class="right">This month</th><th class="right">Last year same month</th></tr></thead>
              <tbody>
                <?php
                  $fmt = (string)(($salesSection['metric']['format'] ?? 'money'));
                  $label = (string)(($salesSection['metric']['label'] ?? 'Value'));
                  $sum = is_array($salesSection['summary'] ?? null) ? (array)$salesSection['summary'] : [];
                ?>
                <tr>
                  <td><strong><?php echo e($label); ?></strong></td>
                  <td class="right"><?php echo e(fmt_pct(isset($sum['change_pct']) ? (float)$sum['change_pct'] : null)); ?></td>
                  <td class="right"><?php echo e(format_metric(isset($sum['this_month']) ? (float)$sum['this_month'] : null, $fmt)); ?></td>
                  <td class="right"><?php echo e(format_metric(isset($sum['last_year']) ? (float)$sum['last_year'] : null, $fmt)); ?></td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="card__subtitle">Channel breakdown</div>
          <div class="table-wrap">
            <table class="table table--compact">
              <thead><tr><th>Channel</th><th class="right">Change %</th><th class="right">This month</th><th class="right">Last year same month</th></tr></thead>
              <tbody>
                <?php foreach ((array)($salesSection['channels'] ?? []) as $r): ?>
                  <tr>
                    <td><?php echo e((string)($r['channel'] ?? '')); ?></td>
                    <td class="right"><?php echo e(fmt_pct(isset($r['change_pct']) ? (float)$r['change_pct'] : null)); ?></td>
                    <td class="right"><?php echo e(format_metric(isset($r['this_month']) ? (float)$r['this_month'] : null, 'money')); ?></td>
                    <td class="right"><?php echo e(format_metric(isset($r['last_year']) ? (float)$r['last_year'] : null, 'money')); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </section>
    <?php endif; ?>

    <section id="goals" class="report-section">
      <div class="report-section__title"><?php echo e((string)($goals['title'] ?? 'Įgyvendinti tikslai')); ?></div>
      <div class="card">
        <div class="card__header">
          <div class="card__title"><?php echo e((string)($goals['title'] ?? 'Įgyvendinti tikslai')); ?></div>
        </div>
        <canvas id="chartGoalsLine" height="160"></canvas>

        <div class="card__subtitle">Summary</div>
        <div class="table-wrap">
          <table class="table table--compact">
            <thead><tr><th></th><th class="right">Change %</th><th class="right">This month</th><th class="right">Last year same month</th></tr></thead>
            <tbody>
              <?php
                $fmt = (string)(($goals['metric']['format'] ?? 'int'));
                $label = (string)(($goals['metric']['label'] ?? 'Value'));
                $sum = is_array($goals['summary'] ?? null) ? (array)$goals['summary'] : [];
              ?>
              <tr>
                <td><strong><?php echo e($label); ?></strong></td>
                <td class="right"><?php echo e(fmt_pct(isset($sum['change_pct']) ? (float)$sum['change_pct'] : null)); ?></td>
                <td class="right"><?php echo e(format_metric(isset($sum['this_month']) ? (float)$sum['this_month'] : null, $fmt)); ?></td>
                <td class="right"><?php echo e(format_metric(isset($sum['last_year']) ? (float)$sum['last_year'] : null, $fmt)); ?></td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="card__subtitle">Channel breakdown</div>
        <div class="table-wrap">
          <table class="table table--compact">
            <thead><tr><th>Channel</th><th class="right">Change %</th><th class="right">This month</th><th class="right">Last year same month</th></tr></thead>
            <tbody>
              <?php foreach ((array)($goals['channels'] ?? []) as $r): ?>
                <tr>
                  <td><?php echo e((string)($r['channel'] ?? '')); ?></td>
                  <td class="right"><?php echo e(fmt_pct(isset($r['change_pct']) ? (float)$r['change_pct'] : null)); ?></td>
                  <td class="right"><?php echo e(format_metric(isset($r['this_month']) ? (float)$r['this_month'] : null, 'int')); ?></td>
                  <td class="right"><?php echo e(format_metric(isset($r['last_year']) ? (float)$r['last_year'] : null, 'int')); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <section id="seo" class="report-section">
      <div class="report-section__title"><?php echo e((string)($seo['title'] ?? 'SEO')); ?></div>
      <div class="card">
        <div class="card__header">
          <div class="card__title"><?php echo e((string)($seo['title'] ?? 'SEO')); ?></div>
        </div>
        <canvas id="chartSeoLine" height="160"></canvas>

        <div class="card__subtitle">Summary</div>
        <div class="table-wrap">
          <table class="table table--compact">
            <thead><tr><th></th><th class="right">Change %</th><th class="right">This month</th><th class="right">Last year same month</th></tr></thead>
            <tbody>
              <?php
                $fmt = (string)(($seo['metric']['format'] ?? 'int'));
                $label = (string)(($seo['metric']['label'] ?? 'Value'));
                $sum = is_array($seo['summary'] ?? null) ? (array)$seo['summary'] : [];
              ?>
              <tr>
                <td><strong><?php echo e($label); ?></strong></td>
                <td class="right"><?php echo e(fmt_pct(isset($sum['change_pct']) ? (float)$sum['change_pct'] : null)); ?></td>
                <td class="right"><?php echo e(format_metric(isset($sum['this_month']) ? (float)$sum['this_month'] : null, $fmt)); ?></td>
                <td class="right"><?php echo e(format_metric(isset($sum['last_year']) ? (float)$sum['last_year'] : null, $fmt)); ?></td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="card__subtitle">Channel breakdown</div>
        <div class="table-wrap">
          <table class="table table--compact">
            <thead><tr><th>Channel</th><th class="right">Change %</th><th class="right">This month</th><th class="right">Last year same month</th></tr></thead>
            <tbody>
              <?php foreach ((array)($seo['channels'] ?? []) as $r): ?>
                <tr>
                  <td><?php echo e((string)($r['channel'] ?? '')); ?></td>
                  <td class="right"><?php echo e(fmt_pct(isset($r['change_pct']) ? (float)$r['change_pct'] : null)); ?></td>
                  <td class="right"><?php echo e(format_metric(isset($r['this_month']) ? (float)$r['this_month'] : null, 'int')); ?></td>
                  <td class="right"><?php echo e(format_metric(isset($r['last_year']) ? (float)$r['last_year'] : null, 'int')); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <section id="ppc" class="report-section">
      <div class="report-section__title">PPC</div>
      <div class="card">
        <div class="card__title">PPC</div>
        <p class="muted">Phase 3 placeholder. In Phase 4 this will reuse the channel breakdown + Google Ads data.</p>
      </div>
    </section>

    <section id="email" class="report-section">
      <div class="report-section__title">Email marketing</div>
      <div class="card">
        <div class="card__title">Email marketing</div>
        <p class="muted">Phase 3 placeholder. In Phase 4 this will reuse GA channel data (Email bucket) and ESP metrics.</p>
      </div>
    </section>

    <section id="affiliate" class="report-section">
      <div class="report-section__title">Affiliate</div>
      <div class="card">
        <div class="card__title">Affiliate</div>
        <p class="muted">Phase 3 placeholder. In Phase 4 this will reuse GA channel data (Referral/Affiliate mapping) and partner stats.</p>
      </div>
    </section>

    <section id="notes" class="report-section">
      <div class="report-section__title">Notes</div>
      <div class="card">
        <div class="card__title">Monthly work summary (snapshot)</div>
        <?php
          $notes = is_array($snapshotPhase3['notes'] ?? null) ? (array)$snapshotPhase3['notes'] : [];
          $workSummary = (string)($notes['work_summary'] ?? '');
        ?>
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
window.REPORT_SNAPSHOT = <?php echo json_encode(
    $snapshotPhase3,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
); ?>;
</script>
<script src="<?php echo e(url('/assets/js/charts.js')); ?>"></script>
<script>
(function () {
  'use strict';
  function scrollToHash(hash) {
    var el = document.querySelector(hash);
    if (!el) return;
    el.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  // Smooth-scroll for sidebar links + quick tabs.
  document.addEventListener('click', function (e) {
    var a = e.target && e.target.closest ? e.target.closest('a[href^="#"]') : null;
    if (!a) return;
    var href = a.getAttribute('href');
    if (!href || href === '#') return;
    var target = document.querySelector(href);
    if (!target) return;
    e.preventDefault();
    history.replaceState(null, '', href);
    scrollToHash(href);
  });

  // Active section highlight on scroll.
  var links = Array.prototype.slice.call(document.querySelectorAll('.sidebar-link'));
  var sections = Array.prototype.slice.call(document.querySelectorAll('.report-section[id]'));
  function setActiveById(id) {
    var hash = '#' + id;
    links.forEach(function (a) {
      a.classList.toggle('is-active', a.getAttribute('href') === hash);
    });
  }

  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (en.isIntersecting) {
          setActiveById(en.target.id);
        }
      });
    }, { root: null, rootMargin: '-30% 0px -65% 0px', threshold: 0.01 });
    sections.forEach(function (s) { io.observe(s); });
  } else {
    window.addEventListener('scroll', function () {
      var best = null;
      var bestTop = -Infinity;
      for (var i = 0; i < sections.length; i++) {
        var r = sections[i].getBoundingClientRect();
        if (r.top < 140 && r.top > bestTop) {
          bestTop = r.top;
          best = sections[i];
        }
      }
      if (best) setActiveById(best.id);
    }, { passive: true });
  }
})();
</script>

<?php
render_footer();

