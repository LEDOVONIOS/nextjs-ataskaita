<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/report_generator.php';
require_once __DIR__ . '/includes/layout.php';

require_admin();
$pdo = db();

[$defaultYear, $defaultMonth] = current_year_month();
$year = safe_int($_GET['year'] ?? ($_POST['year'] ?? $defaultYear), $defaultYear);
$month = safe_int($_GET['month'] ?? ($_POST['month'] ?? $defaultMonth), $defaultMonth);

$results = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_post();
    csrf_verify_or_die();

    $year = safe_int($_POST['year'] ?? $defaultYear, $defaultYear);
    $month = safe_int($_POST['month'] ?? $defaultMonth, $defaultMonth);

    if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
        flash_set('error', 'Invalid year/month.');
        redirect('/generate_all.php');
    }

    $projects = $pdo->query('SELECT * FROM projects ORDER BY id ASC')->fetchAll();
    $ok = 0;
    $fail = 0;
    foreach ($projects as $project) {
        $pid = (int)$project['id'];
        try {
            $snapshot = generate_report_snapshot($pdo, $project, $year, $month);
            $meta = isset($snapshot['meta']) && is_array($snapshot['meta']) ? (array)$snapshot['meta'] : [];
            $status = (string)($meta['reportStatus'] ?? 'READY');

            $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                log_error('Snapshot json_encode failed', [
                    'project_id' => $pid,
                    'year' => $year,
                    'month' => $month,
                    'status' => $status,
                    'json_error' => json_last_error_msg(),
                ]);
                throw new RuntimeException('Failed to encode report snapshot JSON');
            }
            $jsonLen = strlen($json);
            log_info('Snapshot prepared', [
                'project_id' => $pid,
                'year' => $year,
                'month' => $month,
                'status' => $status,
                'jsonLen' => $jsonLen,
            ]);
            if ($jsonLen < 5000) {
                log_warn('Snapshot JSON is smaller than expected', [
                    'project_id' => $pid,
                    'year' => $year,
                    'month' => $month,
                    'status' => $status,
                    'jsonLen' => $jsonLen,
                    'top_keys' => array_slice(array_keys($snapshot), 0, 25),
                ]);
            }
            if ($jsonLen < 1000) {
                log_error('Snapshot JSON too small - refusing to write', [
                    'project_id' => $pid,
                    'year' => $year,
                    'month' => $month,
                    'status' => $status,
                    'jsonLen' => $jsonLen,
                    'top_keys' => array_slice(array_keys($snapshot), 0, 25),
                ]);
                throw new RuntimeException('Generated snapshot JSON too small');
            }

            upsert_monthly_report_json($pdo, $pid, $year, $month, $status, $json);

            // STEP 1 verification: immediately re-read and validate what we wrote.
            $verifyStmt = $pdo->prepare('
                SELECT id, status, generated_at, LENGTH(data_json) AS json_len, data_json
                FROM monthly_reports
                WHERE project_id = ? AND year = ? AND month = ?
                LIMIT 1
            ');
            $verifyStmt->execute([$pid, $year, $month]);
            $vr = $verifyStmt->fetch();
            $dbLen = $vr ? (int)($vr['json_len'] ?? 0) : 0;
            log_info('Snapshot DB write check', [
                'project_id' => $pid,
                'year' => $year,
                'month' => $month,
                'status' => $status,
                'expected_jsonLen' => $jsonLen,
                'db_jsonLen' => $dbLen,
                'report_id' => $vr ? (int)($vr['id'] ?? 0) : 0,
            ]);
            if (!$vr || !is_string($vr['data_json'] ?? null) || trim((string)$vr['data_json']) === '') {
                log_error('Snapshot DB row missing/empty after upsert', [
                    'project_id' => $pid,
                    'year' => $year,
                    'month' => $month,
                    'status' => $status,
                ]);
                throw new RuntimeException('Snapshot DB write verification failed');
            }
            $decoded = json_decode((string)$vr['data_json'], true);
            if (!is_array($decoded)) {
                log_error('Snapshot JSON decode failed after upsert', [
                    'project_id' => $pid,
                    'year' => $year,
                    'month' => $month,
                    'status' => $status,
                    'json_error' => json_last_error_msg(),
                    'db_jsonLen' => $dbLen,
                ]);
                throw new RuntimeException('Snapshot JSON decode verification failed');
            }

            $ok++;
            $results[] = ['project' => (string)$project['name'], 'status' => $status === 'PARTIAL' ? 'PARTIAL' : 'OK'];
        } catch (Throwable $e) {
            $fail++;
            upsert_monthly_report_error($pdo, $pid, $year, $month);
            $results[] = ['project' => (string)$project['name'], 'status' => 'ERROR'];
        }
    }

    if ($fail === 0) {
        flash_set('success', "Generated $ok report(s).");
    } else {
        flash_set('error', "Generated $ok report(s), $fail failed.");
    }
}

render_header('Generate All Projects');
?>

<div class="card">
  <form method="post" action="<?php echo e(url('/generate_all.php')); ?>">
    <?php echo csrf_input(); ?>

    <div class="form-grid">
      <div class="form-row">
        <label for="year">Year</label>
        <input id="year" name="year" type="number" min="2000" max="2100" value="<?php echo e((string)$year); ?>" required>
      </div>
      <div class="form-row">
        <label for="month">Month</label>
        <input id="month" name="month" type="number" min="1" max="12" value="<?php echo e((string)$month); ?>" required>
      </div>
    </div>

    <div class="form-actions">
      <button class="btn btn--primary" type="submit">Generate all snapshots</button>
      <a class="btn" href="<?php echo e(url('/dashboard.php')); ?>">Back</a>
    </div>
  </form>
</div>

<?php if ($results): ?>
  <div class="card">
    <div class="card__title">Results</div>
    <ul class="list">
      <?php foreach ($results as $r): ?>
        <li>
          <strong><?php echo e($r['project']); ?></strong>:
          <span class="badge badge--<?php echo e(strtolower($r['status'])); ?>"><?php echo e($r['status']); ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php
render_footer();

