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
$notes = (array)($snapshot['notes'] ?? []);
$workSummary = (string)($notes['work_summary'] ?? '');
$indexedPagesManual = $notes['indexed_pages_manual'] ?? null;
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

function lt_format_pct(?float $ratio): string
{
    if ($ratio === null) {
        return '—';
    }
    $pct = round($ratio * 100.0, 1);
    $sign = $pct > 0 ? '+' : '';
    return $sign . (string)$pct . '%';
}

function lt_fmt_number(mixed $v, int $decimals = 0): string
{
    if ($v === null || $v === '') {
        return '—';
    }
    if (!is_numeric($v)) {
        return '—';
    }
    return number_format((float)$v, $decimals, '.', ' ');
}

function lt_fmt_duration_sec(mixed $sec): string
{
    if ($sec === null || $sec === '' || !is_numeric($sec)) {
        return '—';
    }
    $s = (int)round((float)$sec);
    if ($s < 0) {
        $s = 0;
    }
    $m = intdiv($s, 60);
    $r = $s % 60;
    return ($m > 0 ? ($m . 'm ') : '') . $r . 's';
}

function get_snapshot_pages_or_fallback(array $snapshot): array
{
    $analytics = isset($snapshot['analytics']) && is_array($snapshot['analytics']) ? (array)$snapshot['analytics'] : [];
    $pages = isset($analytics['pages']) && is_array($analytics['pages']) ? (array)$analytics['pages'] : [];
    if ($pages) {
        return $pages;
    }

    // Fallback: older snapshots → synthesize only "all" from visitors_overview (if present).
    $vo = isset($analytics['visitors_overview']) && is_array($analytics['visitors_overview']) ? (array)$analytics['visitors_overview'] : [];
    if (!$vo && isset($snapshot['visitorsOverview']) && is_array($snapshot['visitorsOverview'])) {
        $vo = (array)$snapshot['visitorsOverview'];
    }
    $totals = isset($vo['totals']) && is_array($vo['totals']) ? (array)$vo['totals'] : $vo;
    $ly = isset($vo['last_year']) && is_array($vo['last_year']) ? (array)$vo['last_year'] : [];
    $lyTotals = isset($ly['totals']) && is_array($ly['totals']) ? (array)$ly['totals'] : [];
    $chg = isset($vo['change_pct']) && is_array($vo['change_pct']) ? (array)$vo['change_pct'] : [];

    return [
        'all' => [
            'labelLT' => 'Visi lankytojai',
            'thisMonth' => [
                'visits' => [
                    'users' => $totals['users'] ?? null,
                    'new_users' => $totals['new_users'] ?? null,
                    'sessions' => $totals['sessions'] ?? null,
                ],
                'behavior' => [
                    'engagement_rate' => $totals['engagement_rate'] ?? null,
                    'pages_per_session' => $totals['pages_per_session'] ?? null,
                    'avg_session_duration_sec' => $totals['avg_session_duration_sec'] ?? ($totals['avg_engagement_time_sec'] ?? null),
                ],
            ],
            'lastYearSameMonth' => [
                'visits' => [
                    'users' => $lyTotals['users'] ?? null,
                    'new_users' => $lyTotals['new_users'] ?? null,
                    'sessions' => $lyTotals['sessions'] ?? null,
                ],
                'behavior' => [
                    'engagement_rate' => $lyTotals['engagement_rate'] ?? null,
                    'pages_per_session' => $lyTotals['pages_per_session'] ?? null,
                    'avg_session_duration_sec' => $lyTotals['avg_session_duration_sec'] ?? null,
                ],
            ],
            'changePct' => [
                'visits' => [
                    'users' => $chg['users'] ?? null,
                    'new_users' => $chg['new_users'] ?? null,
                    'sessions' => $chg['sessions'] ?? null,
                ],
            ],
            'daily' => [
                'thisMonth' => (array)($vo['daily'] ?? []),
                'lastYear' => (array)($ly['daily'] ?? []),
            ],
        ],
    ];
}

function get_pages_meta_or_default(array $snapshot, array $pages): array
{
    $meta = isset($snapshot['meta']) && is_array($snapshot['meta']) ? (array)$snapshot['meta'] : [];
    $pagesMeta = isset($meta['pages']) && is_array($meta['pages']) ? (array)$meta['pages'] : [];
    if ($pagesMeta) {
        return $pagesMeta;
    }
    return [
        'availablePages' => array_keys($pages),
        'defaultPage' => 'all',
    ];
}

function render_yoy_table_visits(array $page): void
{
    $cur = (array)($page['thisMonth']['visits'] ?? []);
    $prev = (array)($page['lastYearSameMonth']['visits'] ?? $page['lastYear']['visits'] ?? []);
    $chg = (array)($page['changePct']['visits'] ?? []);
    echo '<div class="table-wrap">';
    echo '<table class="table table--compact">';
    echo '<thead><tr><th></th><th class="right">Vartotojai</th><th class="right">Nauji vartotojai</th><th class="right">Sesijos</th></tr></thead>';
    echo '<tbody>';
    echo '<tr><td><strong>Šis mėnuo</strong></td><td class="right">' . e(lt_fmt_number($cur['users'] ?? null)) . '</td><td class="right">' . e(lt_fmt_number($cur['new_users'] ?? null)) . '</td><td class="right">' . e(lt_fmt_number($cur['sessions'] ?? null)) . '</td></tr>';
    echo '<tr><td><strong>Praeitų metų tas pats mėnuo</strong></td><td class="right">' . e(lt_fmt_number($prev['users'] ?? null)) . '</td><td class="right">' . e(lt_fmt_number($prev['new_users'] ?? null)) . '</td><td class="right">' . e(lt_fmt_number($prev['sessions'] ?? null)) . '</td></tr>';
    echo '<tr><td><strong>Pokytis</strong></td><td class="right">' . e(lt_format_pct(isset($chg['users']) && is_numeric($chg['users']) ? (float)$chg['users'] : null)) . '</td><td class="right">' . e(lt_format_pct(isset($chg['new_users']) && is_numeric($chg['new_users']) ? (float)$chg['new_users'] : null)) . '</td><td class="right">' . e(lt_format_pct(isset($chg['sessions']) && is_numeric($chg['sessions']) ? (float)$chg['sessions'] : null)) . '</td></tr>';
    echo '</tbody></table></div>';
}

function render_yoy_table_behavior(array $page): void
{
    $cur = (array)($page['thisMonth']['behavior'] ?? []);
    $prev = (array)($page['lastYearSameMonth']['behavior'] ?? $page['lastYear']['behavior'] ?? []);
    $chg = (array)($page['changePct']['behavior'] ?? []);

    $curEr = isset($cur['engagement_rate']) && is_numeric($cur['engagement_rate']) ? (float)$cur['engagement_rate'] : null;
    $prevEr = isset($prev['engagement_rate']) && is_numeric($prev['engagement_rate']) ? (float)$prev['engagement_rate'] : null;

    echo '<div class="table-wrap">';
    echo '<table class="table table--compact">';
    echo '<thead><tr><th></th><th class="right">Įsitraukimo rodiklis</th><th class="right">Puslapiai / sesija</th><th class="right">Vid. sesijos trukmė</th></tr></thead>';
    echo '<tbody>';
    echo '<tr><td><strong>Šis mėnuo</strong></td><td class="right">' . e($curEr === null ? '—' : (string)round($curEr * 100.0, 1) . '%') . '</td><td class="right">' . e(lt_fmt_number($cur['pages_per_session'] ?? null, 2)) . '</td><td class="right">' . e(lt_fmt_duration_sec($cur['avg_session_duration_sec'] ?? null)) . '</td></tr>';
    echo '<tr><td><strong>Praeitų metų tas pats mėnuo</strong></td><td class="right">' . e($prevEr === null ? '—' : (string)round($prevEr * 100.0, 1) . '%') . '</td><td class="right">' . e(lt_fmt_number($prev['pages_per_session'] ?? null, 2)) . '</td><td class="right">' . e(lt_fmt_duration_sec($prev['avg_session_duration_sec'] ?? null)) . '</td></tr>';
    echo '<tr><td><strong>Pokytis</strong></td><td class="right">' . e(lt_format_pct(isset($chg['engagement_rate']) && is_numeric($chg['engagement_rate']) ? (float)$chg['engagement_rate'] : null)) . '</td><td class="right">' . e(lt_format_pct(isset($chg['pages_per_session']) && is_numeric($chg['pages_per_session']) ? (float)$chg['pages_per_session'] : null)) . '</td><td class="right">' . e(lt_format_pct(isset($chg['avg_session_duration_sec']) && is_numeric($chg['avg_session_duration_sec']) ? (float)$chg['avg_session_duration_sec'] : null)) . '</td></tr>';
    echo '</tbody></table></div>';
}

function render_sales_table(array $page): void
{
    $cur = (array)($page['thisMonth']['sales'] ?? []);
    $prev = (array)($page['lastYearSameMonth']['sales'] ?? $page['lastYear']['sales'] ?? []);
    $chg = (array)($page['changePct']['sales'] ?? []);

    echo '<div class="table-wrap">';
    echo '<table class="table table--compact">';
    echo '<thead><tr><th></th><th class="right">Pirkimai</th><th class="right">Operacijos</th><th class="right">Pajamos</th><th class="right">Konversijų rodiklis</th></tr></thead>';
    echo '<tbody>';
    echo '<tr><td><strong>Šis mėnuo</strong></td><td class="right">' . e(lt_fmt_number($cur['purchases'] ?? null)) . '</td><td class="right">' . e(lt_fmt_number($cur['transactions'] ?? null)) . '</td><td class="right">' . e(lt_fmt_number($cur['revenue'] ?? null, 2)) . '</td><td class="right">' . e(isset($cur['conversion_rate']) && is_numeric($cur['conversion_rate']) ? (string)round(((float)$cur['conversion_rate']) * 100.0, 2) . '%' : '—') . '</td></tr>';
    echo '<tr><td><strong>Praeitų metų tas pats mėnuo</strong></td><td class="right">' . e(lt_fmt_number($prev['purchases'] ?? null)) . '</td><td class="right">' . e(lt_fmt_number($prev['transactions'] ?? null)) . '</td><td class="right">' . e(lt_fmt_number($prev['revenue'] ?? null, 2)) . '</td><td class="right">' . e(isset($prev['conversion_rate']) && is_numeric($prev['conversion_rate']) ? (string)round(((float)$prev['conversion_rate']) * 100.0, 2) . '%' : '—') . '</td></tr>';
    echo '<tr><td><strong>Pokytis</strong></td><td class="right">' . e(lt_format_pct(isset($chg['purchases']) && is_numeric($chg['purchases']) ? (float)$chg['purchases'] : null)) . '</td><td class="right">' . e(lt_format_pct(isset($chg['transactions']) && is_numeric($chg['transactions']) ? (float)$chg['transactions'] : null)) . '</td><td class="right">' . e(lt_format_pct(isset($chg['revenue']) && is_numeric($chg['revenue']) ? (float)$chg['revenue'] : null)) . '</td><td class="right">' . e(lt_format_pct(isset($chg['conversion_rate']) && is_numeric($chg['conversion_rate']) ? (float)$chg['conversion_rate'] : null)) . '</td></tr>';
    echo '</tbody></table></div>';
}

function render_goals_list(array $page): void
{
    $hasGoals = isset($page['thisMonth']) && is_array($page['thisMonth']) && array_key_exists('goals', (array)$page['thisMonth']);
    $goals = (array)($page['thisMonth']['goals'] ?? []);
    $events = (array)($goals['events'] ?? []);
    if (!$events) {
        echo '<p class="muted">' . ($hasGoals ? 'Konversijų nerasta.' : 'Not configured yet.') . '</p>';
        return;
    }
    echo '<div class="table-wrap">';
    echo '<table class="table table--compact">';
    echo '<thead><tr><th>Įvykis</th><th class="right">Konversijos</th></tr></thead><tbody>';
    foreach ($events as $ev) {
        if (!is_array($ev)) {
            continue;
        }
        $name = (string)($ev['eventName'] ?? $ev['name'] ?? '');
        $cnt = $ev['conversions'] ?? $ev['count'] ?? null;
        echo '<tr><td><code>' . e($name) . '</code></td><td class="right">' . e(lt_fmt_number($cnt)) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}

$pages = get_snapshot_pages_or_fallback($snapshot);
$pagesMeta = get_pages_meta_or_default($snapshot, $pages);
$availablePages = (array)($pagesMeta['availablePages'] ?? array_keys($pages));
$defaultPage = (string)($pagesMeta['defaultPage'] ?? 'all');

$pageKey = strtolower(trim(safe_string($_GET['page'] ?? '', $defaultPage)));
if ($pageKey === '') {
    $pageKey = $defaultPage !== '' ? $defaultPage : 'all';
}
if (!in_array($pageKey, $availablePages, true)) {
    $pageKey = $defaultPage !== '' ? $defaultPage : 'all';
}
$page = isset($pages[$pageKey]) && is_array($pages[$pageKey]) ? (array)$pages[$pageKey] : [];
$pageLabel = (string)($page['labelLT'] ?? '');

// Provide meta.sections to JS even for old snapshots (read-only in-memory augmentation).
$snapshotForJs = $snapshot;
if (!isset($snapshotForJs['meta']) || !is_array($snapshotForJs['meta'])) {
    $snapshotForJs['meta'] = [];
}
$snapshotForJs['meta']['sections'] = $sectionsMeta;
if (!isset($snapshotForJs['meta']['pages']) || !is_array($snapshotForJs['meta']['pages'])) {
    $snapshotForJs['meta']['pages'] = $pagesMeta;
}
if (!isset($snapshotForJs['analytics']) || !is_array($snapshotForJs['analytics'])) {
    $snapshotForJs['analytics'] = [];
}
$snapshotForJs['analytics']['pages'] = $pages;
?>

<?php if ($status === 'PARTIAL'): ?>
  <div class="alert alert--warn">
    <?php
      $ga4Err = (array)($errors['ga4'] ?? []);
      $msg = (string)($ga4Err['message'] ?? 'Report is PARTIAL.');
      echo e($msg);
    ?>
    <?php if (!empty($ga4Err['details'])): ?>
      <details class="muted" style="margin-top:8px">
        <summary>Details</summary>
        <pre style="white-space:pre-wrap; margin:8px 0 0"><?php echo e(json_encode($ga4Err['details'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?></pre>
      </details>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="report-layout">
  <aside class="report-sidebar" aria-label="Report navigation">
    <div class="card sidebar-card">
      <div class="sidebar-title">Puslapiai</div>
      <div class="muted sidebar-meta">
        <?php
          $genAt = (string)($snapshot['meta']['generatedAt'] ?? ($row['generated_at'] ?? ''));
          $mode = (string)($snapshot['meta']['mode'] ?? '');
        ?>
        <div>Generated: <?php echo e($genAt !== '' ? $genAt : '—'); ?></div>
        <div>Mode: <?php echo e($mode !== '' ? $mode : '—'); ?></div>
      </div>
      <nav class="sidebar-nav">
        <?php
          $pageDefs = [
            'all' => 'Visi lankytojai',
            'seo' => 'SEO / organinis iš paieškos',
            'ppc' => 'Mokama paieška',
            'direct' => 'Tiesiogiai atėję',
            'organic_social' => 'Natūralus srautas iš soc. tinklų',
            'paid_social' => 'Mokamas socialinių tinklų reklamos srautas',
            'pmax' => 'Google Ads Performance Max / Smart Shopping',
            'email' => 'Lankytojai iš el. pašto',
            'referral' => 'Iš kitų svetainių',
            'display' => 'Vaizdinės reklamos srautas',
            'unassigned' => 'Nepriskirtas srautas',
          ];
        ?>
        <?php foreach ($availablePages as $k): ?>
          <?php
            $k = (string)$k;
            if ($k === '' || !isset($pageDefs[$k])) {
                continue;
            }
            $href = url('/report.php') . '?id=' . e((string)$reportId) . '&page=' . e($k);
            $cls = 'sidebar-link' . ($k === $pageKey ? ' is-active' : '');
          ?>
          <a class="<?php echo e($cls); ?>" href="<?php echo e($href); ?>"><?php echo e($pageDefs[$k]); ?></a>
        <?php endforeach; ?>
        <a class="sidebar-link" href="#notes">Pastabos</a>
      </nav>
    </div>
  </aside>

  <div class="report-content">
    <?php
      $salesAvailable = $projectCfg['show_sales_section'] && isset($page['thisMonth']['sales']) && is_array($page['thisMonth']['sales']);
      $hasVisits = isset($page['thisMonth']['visits']) && is_array($page['thisMonth']['visits']);
      $hasBehavior = isset($page['thisMonth']['behavior']) && is_array($page['thisMonth']['behavior']);
      $allowShowVisits = ($pageKey === 'all') ? snapshot_section_is_real($sectionsMeta, 'visitors_overview') : $hasVisits;
      $allowShowBehavior = ($pageKey === 'all') ? snapshot_section_is_real($sectionsMeta, 'visitors_overview') : $hasBehavior;
    ?>

    <div class="card report-page-header">
      <div class="card__header">
        <div>
          <div class="card__title"><?php echo e($pageLabel !== '' ? $pageLabel : ($pageDefs[$pageKey] ?? '—')); ?></div>
          <div class="muted"><?php echo e((string)$year); ?>-<?php echo e(str_pad((string)$month, 2, '0', STR_PAD_LEFT)); ?></div>
        </div>
        <div class="card__actions">
          <a class="btn" href="<?php echo e(url('/dashboard.php')); ?>">Atgal</a>
        </div>
      </div>
      <div class="report-tabs" role="tablist" aria-label="Report sections">
        <button type="button" class="report-tab is-active" data-target="#visits">Apsilankymų duomenys</button>
        <button type="button" class="report-tab" data-target="#behavior">Lankytojų elgesys</button>
        <?php if ($salesAvailable): ?>
          <button type="button" class="report-tab" data-target="#sales">Pardavimų duomenys</button>
        <?php endif; ?>
        <button type="button" class="report-tab" data-target="#goals">Įgyvendinti tikslai</button>
      </div>
    </div>

    <section id="visits" class="report-section">
      <div class="report-section__title">Apsilankymų duomenys</div>
      <div class="card">
        <div class="card__header">
          <div class="card__title">Suvestinė <?php echo snapshot_section_badge($sectionsMeta, 'visitors_overview'); ?></div>
        </div>
        <?php if (!$allowShowVisits): ?>
          <p class="muted">Not configured yet.</p>
        <?php else: ?>
          <?php render_yoy_table_visits($page); ?>
          <div style="margin-top:12px">
            <canvas id="chartPageDailyUsers" height="140"></canvas>
          </div>
        <?php endif; ?>
      </div>

      <?php if ($pageKey === 'all' && isset($page['tableRows']) && is_array($page['tableRows']) && $page['tableRows']): ?>
        <div class="card">
          <div class="card__header">
            <div class="card__title">Srauto kanalai</div>
          </div>
          <canvas id="chartChannelsUsers" height="220"></canvas>
          <div class="table-wrap">
            <table class="table table--compact">
              <thead><tr><th>Kanalas</th><th class="right">Vartotojai</th><th class="right">Sesijos</th></tr></thead>
              <tbody>
                <?php foreach ((array)$page['tableRows'] as $r): ?>
                  <?php if (!is_array($r)) continue; ?>
                  <tr>
                    <td><?php echo e((string)($r['labelLT'] ?? $r['channel'] ?? '')); ?></td>
                    <td class="right"><?php echo e(lt_fmt_number($r['users'] ?? null)); ?></td>
                    <td class="right"><?php echo e(lt_fmt_number($r['sessions'] ?? null)); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($pageKey === 'seo'): ?>
        <div class="card">
          <div class="card__header">
            <div class="card__title">Indeksavimas (rankiniu būdu)</div>
          </div>
          <div class="kpis">
            <div class="kpi">
              <div class="kpi__label">Indeksuotų puslapių kiekis Google</div>
              <div class="kpi__value"><?php echo e(lt_fmt_number($indexedPagesManual)); ?></div>
            </div>
          </div>
          <p class="muted">Kiti SEO (GSC) rodikliai: Not configured yet.</p>
        </div>
      <?php endif; ?>
    </section>

    <section id="behavior" class="report-section">
      <div class="report-section__title">Lankytojų elgesys</div>
      <div class="card">
        <div class="card__header">
          <div class="card__title">Suvestinė</div>
        </div>
        <?php if (!$allowShowBehavior): ?>
          <p class="muted">Not configured yet.</p>
        <?php else: ?>
          <?php render_yoy_table_behavior($page); ?>
        <?php endif; ?>
      </div>
    </section>

    <?php if ($salesAvailable): ?>
      <section id="sales" class="report-section">
        <div class="report-section__title">Pardavimų duomenys</div>
        <div class="card">
          <div class="card__header">
            <div class="card__title">Suvestinė</div>
          </div>
          <?php render_sales_table($page); ?>
        </div>
      </section>
    <?php endif; ?>

    <section id="goals" class="report-section">
      <div class="report-section__title">Įgyvendinti tikslai</div>
      <div class="card">
        <div class="card__header">
          <div class="card__title">Konversijų įvykiai</div>
        </div>
        <?php render_goals_list($page); ?>
      </div>
    </section>

    <section id="notes" class="report-section">
      <div class="report-section__title">Pastabos</div>
      <div class="card">
        <div class="card__title">Mėnesio darbų suvestinė (įrašyta generavimo metu)</div>
        <?php if (trim($workSummary) === ''): ?>
          <p class="muted">Generuojant ataskaitą nebuvo įrašyta darbų suvestinė.</p>
        <?php else: ?>
          <div class="prose"><?php echo nl2br(e($workSummary)); ?></div>
        <?php endif; ?>
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
  // Smooth scroll for top tabs.
  var tabs = document.querySelectorAll('.report-tab[data-target]');
  function setActiveTab(target) {
    for (var i = 0; i < tabs.length; i++) {
      var b = tabs[i];
      if (b.getAttribute('data-target') === target) b.classList.add('is-active');
      else b.classList.remove('is-active');
    }
  }
  for (var j = 0; j < tabs.length; j++) {
    tabs[j].addEventListener('click', function (e) {
      e.preventDefault();
      var t = this.getAttribute('data-target');
      if (!t) return;
      var el = document.querySelector(t);
      if (!el) return;
      setActiveTab(t);
      try { window.history.replaceState(null, '', t); } catch (_) {}
      el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  }
  if (window.location.hash) {
    setActiveTab(window.location.hash);
  }
})();
</script>

<?php
render_footer();

