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

// Extra assets for this page (shared-hosting friendly).
$GLOBALS['EXTRA_CSS'] = ['/assets/css/report.css'];
$GLOBALS['EXTRA_BODY_CLASS'] = 'page--report';
$GLOBALS['EXTRA_MAIN_CLASS'] = 'container--wide';

function report_month_date_ranges_utc(int $year, int $month): array
{
    $start = sprintf('%04d-%02d-01', $year, $month);
    $days = (int)date('t', strtotime($start));
    $end = sprintf('%04d-%02d-%02d', $year, $month, $days);
    $lastStart = sprintf('%04d-%02d-01', $year - 1, $month);
    $lastDays = (int)date('t', strtotime($lastStart));
    $lastEnd = sprintf('%04d-%02d-%02d', $year - 1, $month, $lastDays);
    return [$start, $end, $lastStart, $lastEnd];
}

function report_fetch_row_by_id(PDO $pdo, int $reportId): ?array
{
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
    return $row ? (array)$row : null;
}

function report_fetch_row_by_project_period(PDO $pdo, int $projectId, int $year, int $month): ?array
{
    $stmt = $pdo->prepare('
        SELECT r.id, r.project_id, r.year, r.month, r.status, r.generated_at, r.data_json,
               p.name AS project_name, p.show_sales_section, p.ga4_property_id, p.gsc_site_url
        FROM monthly_reports r
        INNER JOIN projects p ON p.id = r.project_id
        WHERE r.project_id = ? AND r.year = ? AND r.month = ?
        LIMIT 1
    ');
    $stmt->execute([$projectId, $year, $month]);
    $row = $stmt->fetch();
    return $row ? (array)$row : null;
}

function report_fetch_latest_ready(PDO $pdo, int $projectId): ?array
{
    $stmt = $pdo->prepare('
        SELECT r.id, r.project_id, r.year, r.month, r.status, r.generated_at, r.data_json,
               p.name AS project_name, p.show_sales_section, p.ga4_property_id, p.gsc_site_url
        FROM monthly_reports r
        INNER JOIN projects p ON p.id = r.project_id
        WHERE r.project_id = ? AND r.status IN ("READY","PARTIAL")
        ORDER BY r.year DESC, r.month DESC
        LIMIT 1
    ');
    $stmt->execute([$projectId]);
    $row = $stmt->fetch();
    return $row ? (array)$row : null;
}

function report_contract_pct_change(?float $thisValue, ?float $last): ?float
{
    if ($thisValue === null || $last === null) {
        return null;
    }
    if ($last == 0.0) {
        return null;
    }
    return ($thisValue - $last) / $last;
}

function report_contract_make_timeseries(array $labels, array $thisArr, array $lastArr): array
{
    return [
        'labels' => array_values($labels),
        'this' => array_values($thisArr),
        'last' => array_values($lastArr),
    ];
}

function report_contract_make_metric(string $label, mixed $thisValue, mixed $last, string $format, string $unit = ''): array
{
    return [
        'label' => $label,
        'this' => $thisValue,
        'last' => $last,
        'format' => $format,
        'unit' => $unit,
    ];
}

/**
 * Normalize any snapshot into the Phase 3 UI contract:
 *   sections.traffic[preset_key][tab_key] = { timeseries, totals }
 * This keeps old saved snapshots viewable even after we switch the UI.
 */
function normalize_report_contract(array $snapshot, array $row): array
{
    $projectId = (int)($row['project_id'] ?? 0);
    $projectName = (string)($row['project_name'] ?? '');
    $year = (int)($row['year'] ?? 0);
    $month = (int)($row['month'] ?? 0);
    $currency = 'EUR';
    $timezone = 'Europe/Vilnius';
    $generatedUtc = (string)($row['generated_at'] ?? '');

    [$thisStart, $thisEnd, $lastStart, $lastEnd] = report_month_date_ranges_utc($year, $month);

    $base = [
        'meta' => [
            'schema_version' => 1,
            'currency' => $currency,
            'timezone' => $timezone,
            'generated_utc' => $generatedUtc,
        ],
        'project' => [
            'id' => $projectId,
            'name' => $projectName,
            'show_sales_section' => ((int)($row['show_sales_section'] ?? 1)) === 1,
        ],
        'period' => [
            'year' => $year,
            'month' => $month,
            'label' => sprintf('%04d-%02d', $year, $month),
            'compare_to' => [
                'year' => $year - 1,
                'month' => $month,
                'label' => sprintf('%04d-%02d', $year - 1, $month),
            ],
            'date_ranges' => [
                'this_start' => $thisStart,
                'this_end' => $thisEnd,
                'last_start' => $lastStart,
                'last_end' => $lastEnd,
            ],
        ],
        'sections' => [
            'traffic' => [],
        ],
    ];

    // If already in the contract shape, trust it (but ensure required keys exist).
    if (isset($snapshot['sections']) && is_array($snapshot['sections']) && isset($snapshot['sections']['traffic']) && is_array($snapshot['sections']['traffic'])) {
        $base['meta'] = is_array($snapshot['meta'] ?? null) ? (array)$snapshot['meta'] + $base['meta'] : $base['meta'];
        $base['period'] = is_array($snapshot['period'] ?? null) ? (array)$snapshot['period'] + $base['period'] : $base['period'];
        $base['project'] = is_array($snapshot['project'] ?? null) ? (array)$snapshot['project'] + $base['project'] : $base['project'];
        // Preserve any additional section payloads (e.g. Phase 3 "all visitors report" extras).
        $base['sections'] = (array)$snapshot['sections'] + $base['sections'];
        $base['sections']['traffic'] = (array)$snapshot['sections']['traffic'];
        return $base;
    }

    // Backward compatibility: map older Phase 3 snapshot keys (visitors/behavior/sales/goals) into the new contract (preset=all only).
    // Old generator used: visitors.summary, visitors.chart, behavior.summary, etc.
    $trafficAll = [];

    $mapOldSectionToTab = function (string $oldKey, string $tabKey, array $metricMap) use ($snapshot): array {
        $sec = isset($snapshot[$oldKey]) && is_array($snapshot[$oldKey]) ? (array)$snapshot[$oldKey] : [];
        $chart = isset($sec['chart']) && is_array($sec['chart']) ? (array)$sec['chart'] : [];
        $labels = (array)($chart['labels'] ?? []);
        $thisArr = (array)($chart['this_month'] ?? []);
        $lastArr = (array)($chart['last_year'] ?? []);

        $summary = isset($sec['summary']) && is_array($sec['summary']) ? (array)$sec['summary'] : [];
        $thisMain = isset($summary['this_month']) ? (float)$summary['this_month'] : null;
        $lastMain = isset($summary['last_year']) ? (float)$summary['last_year'] : null;

        $totals = [];
        foreach ($metricMap as $k => $def) {
            $totals[$k] = report_contract_make_metric($def['label'], $def['this'] ?? $thisMain, $def['last'] ?? $lastMain, $def['format'], $def['unit'] ?? '');
        }

        return [
            'timeseries' => report_contract_make_timeseries($labels, $thisArr, $lastArr),
            'totals' => $totals,
        ];
    };

    $trafficAll['visits'] = $mapOldSectionToTab('visitors', 'visits', [
        'users' => ['label' => 'Users', 'format' => 'int', 'unit' => ''],
        'new_users' => ['label' => 'New Users', 'format' => 'int', 'unit' => ''],
        'sessions' => ['label' => 'Sessions', 'format' => 'int', 'unit' => ''],
    ]);
    $trafficAll['behavior'] = $mapOldSectionToTab('behavior', 'behavior', [
        'engagement_rate' => ['label' => 'Engagement rate', 'format' => 'pct', 'unit' => '%'],
        'pages_per_session' => ['label' => 'Pages / session', 'format' => 'float1', 'unit' => ''],
        'avg_session_duration_sec' => ['label' => 'Avg session duration', 'format' => 'seconds', 'unit' => 's'],
    ]);
    $trafficAll['sales'] = $mapOldSectionToTab('sales', 'sales', [
        'conversion_rate' => ['label' => 'Conversion rate', 'format' => 'pct', 'unit' => '%'],
        'purchases' => ['label' => 'Purchases', 'format' => 'int', 'unit' => ''],
        'revenue' => ['label' => 'Revenue', 'format' => 'money', 'unit' => 'EUR'],
    ]);
    $trafficAll['goals'] = $mapOldSectionToTab('goals', 'goals', [
        'goal_1' => ['label' => 'Goal: Newsletter', 'format' => 'int', 'unit' => ''],
        'goal_2' => ['label' => 'Goal: Contact form', 'format' => 'int', 'unit' => ''],
        'goal_conversion_rate' => ['label' => 'Goal conversion rate', 'format' => 'pct', 'unit' => '%'],
    ]);

    $base['sections']['traffic'] = [
        'all' => $trafficAll,
    ];
    return $base;
}

$reportId = safe_int($_GET['id'] ?? null, 0);
$projectIdParam = safe_int($_GET['project_id'] ?? null, 0);
$yearParam = safe_int($_GET['year'] ?? null, 0);
$monthParam = safe_int($_GET['month'] ?? null, 0);
$hasExplicitPeriod = array_key_exists('year', $_GET) || array_key_exists('month', $_GET);
$validPeriod = ($yearParam >= 2000 && $yearParam <= 2100 && $monthParam >= 1 && $monthParam <= 12);

$row = null;
if ($reportId > 0) {
    $row = report_fetch_row_by_id($pdo, $reportId);
} elseif ($projectIdParam > 0 && $hasExplicitPeriod && $validPeriod) {
    $row = report_fetch_row_by_project_period($pdo, $projectIdParam, $yearParam, $monthParam);
} elseif ($projectIdParam > 0 && $hasExplicitPeriod && !$validPeriod) {
    http_response_code(200);
    render_header('Report');
    echo '<div class="card"><p>Report not available for selected month.</p><p><a class="btn" href="' . e(url('/dashboard.php')) . '">Back</a></p></div>';
    render_footer();
    exit;
} elseif ($projectIdParam > 0) {
    $row = report_fetch_latest_ready($pdo, $projectIdParam);
} else {
    http_response_code(400);
    render_header('Report');
    echo '<div class="card"><p>Missing report selector. Provide <code>id</code> or <code>project_id</code> (optional <code>year</code>, <code>month</code>).</p><p><a class="btn" href="' . e(url('/dashboard.php')) . '">Back</a></p></div>';
    render_footer();
    exit;
}

if (!$row) {
    http_response_code(200);
    render_header('Report');
    echo '<div class="card"><p>Report not available for selected month.</p><p><a class="btn" href="' . e(url('/dashboard.php')) . '">Back</a></p></div>';
    render_footer();
    exit;
}

$projectId = (int)$row['project_id'];
if (!user_can_access_project($pdo, $userId, $role, $projectId)) {
    http_response_code(403);
    render_header('Report');
    echo '<div class="card"><p>Forbidden.</p><p><a class="btn" href="' . e(url('/dashboard.php')) . '">Back</a></p></div>';
    render_footer();
    exit;
}

$status = (string)$row['status'];
$year = (int)$row['year'];
$month = (int)$row['month'];
$projectName = (string)$row['project_name'];
$title = 'Ataskaita: ' . $projectName . ' — ' . $year . '-' . str_pad((string)$month, 2, '0', STR_PAD_LEFT);

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
    echo '<div class="card"><p>Report data is invalid/corrupted.</p><p><a class="btn" href="' . e(url('/dashboard.php')) . '">Back</a></p></div>';
    render_footer();
    exit;
}

$reportData = normalize_report_contract($snapshot, $row);
$showSales = ((int)($reportData['project']['show_sales_section'] ?? 1)) === 1;
?>

<div class="report3" id="reportApp">
  <div class="report3__top card">
    <div class="report3__topTitle">
      <?php echo e($projectName); ?>
      <span class="report3__pill"><?php echo e(sprintf('%04d-%02d', $year, $month)); ?></span>
    </div>
    <?php
      $dr = (array)($reportData['period']['date_ranges'] ?? []);
      $thisRange = (string)($dr['this_start'] ?? '') . ' – ' . (string)($dr['this_end'] ?? '');
      $lastRange = (string)($dr['last_start'] ?? '') . ' – ' . (string)($dr['last_end'] ?? '');
    ?>
    <div class="report3__topSub">
      <span class="muted">Laikotarpis:</span> <?php echo e($thisRange); ?>
      <span class="muted">· Palyginimas:</span> <?php echo e($lastRange); ?>
    </div>
  </div>

  <div class="report3__layout">
    <aside class="report3__sidebar card" aria-label="Report navigation">
      <div class="report3__sidebarTitle">Ataskaita</div>
      <nav class="report3__presetNav">
        <a class="report3__presetLink is-active" href="#" aria-current="page">
          <span class="report3__presetLabel">Visų tinklalapio lankytojų ataskaita</span>
          <span class="report3__presetChevron">›</span>
        </a>
      </nav>
    </aside>

    <div class="report3__content">
      <div class="report3__tabs" role="navigation" aria-label="Quick scroll">
        <a class="report3__tab" href="#visits">Apsilankymų duomenys</a>
        <a class="report3__tab" href="#behavior">Lankytojų elgesys</a>
        <?php if ($showSales): ?>
          <a class="report3__tab" href="#sales">Pardavimų duomenys</a>
        <?php endif; ?>
        <a class="report3__tab" href="#goals">Įgyvendinti tikslai</a>
      </div>

      <section class="report3__section report-section" id="visits">
        <div class="card">
          <div class="report3__sectionHead">
            <div class="report3__sectionTitle">Apsilankymų duomenys</div>
            <div class="report3__sectionRange">
              <?php echo e($thisRange); ?> · <?php echo e($lastRange); ?>
            </div>
          </div>
          <div class="report3__chartWrap">
            <canvas id="chart-visits-line" height="160"></canvas>
          </div>
          <div class="report3__compare">
            <div class="report3__tableTitle">Visų lankytojų apsilankymų duomenys</div>
            <div class="table-wrap">
              <table class="table table--compact" id="table-visits"></table>
            </div>
          </div>
        </div>
      </section>

      <section class="report3__section report-section" id="behavior">
        <div class="card">
          <div class="report3__sectionHead">
            <div class="report3__sectionTitle">Lankytojų elgesys</div>
            <div class="report3__sectionRange">
              <?php echo e($thisRange); ?> · <?php echo e($lastRange); ?>
            </div>
          </div>
          <div class="table-wrap">
            <table class="table table--compact" id="table-behavior"></table>
          </div>
        </div>
      </section>

      <?php if ($showSales): ?>
      <section class="report3__section report-section" id="sales">
        <div class="card">
          <div class="report3__sectionHead">
            <div class="report3__sectionTitle">Pardavimų duomenys</div>
            <div class="report3__sectionRange">
              <?php echo e($thisRange); ?> · <?php echo e($lastRange); ?>
            </div>
          </div>
          <div class="report3__compare">
            <div class="report3__tableTitle">Pardavimų duomenys visiems lankytojams</div>
            <div class="table-wrap">
              <table class="table table--compact" id="table-sales"></table>
            </div>
          </div>
        </div>
      </section>
      <?php endif; ?>

      <section class="report3__section report-section" id="goals">
        <div class="card">
          <div class="report3__sectionHead">
            <div class="report3__sectionTitle">Įgyvendinti tikslai</div>
            <div class="report3__sectionRange">
              <?php echo e($thisRange); ?> · <?php echo e($lastRange); ?>
            </div>
          </div>
          <div class="table-wrap">
            <table class="table table--compact" id="table-goals"></table>
          </div>
        </div>
      </section>

      <section class="report3__section report-section" id="bottom-charts">
        <div class="card">
          <div class="report3__sectionHead">
            <div class="report3__sectionTitle">Lankytojai</div>
            <div class="report3__sectionRange">Vieno laikotarpio pjūvis</div>
          </div>
          <div class="report3__donuts">
            <div class="report3__donut card card--flat">
              <div class="report3__donutTitle">Lankytojų lytis</div>
              <canvas id="chart-donut-gender" height="180"></canvas>
              <div class="report3__donutLegend" id="legend-donut-gender"></div>
            </div>
            <div class="report3__donut card card--flat">
              <div class="report3__donutTitle">Lankytojų naudojamos naršyklės</div>
              <canvas id="chart-donut-browsers" height="180"></canvas>
              <div class="report3__donutLegend" id="legend-donut-browsers"></div>
            </div>
            <div class="report3__donut card card--flat">
              <div class="report3__donutTitle">Lankytojų įrenginiai</div>
              <canvas id="chart-donut-devices" height="180"></canvas>
              <div class="report3__donutLegend" id="legend-donut-devices"></div>
            </div>
            <div class="report3__donut card card--flat">
              <div class="report3__donutTitle">Lankytojų amžius</div>
              <canvas id="chart-donut-age" height="180"></canvas>
              <div class="report3__donutLegend" id="legend-donut-age"></div>
            </div>
          </div>
        </div>
      </section>

      <div class="card">
        <div class="card__actions">
          <a class="btn" href="<?php echo e(url('/dashboard.php')); ?>">Back to dashboard</a>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
window.REPORT_DATA = <?php echo json_encode(
    $reportData,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
); ?>;
</script>
<script src="<?php echo e(url('/assets/js/report_ui.js')); ?>"></script>

<?php
render_footer();

