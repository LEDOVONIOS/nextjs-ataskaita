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

// GA4 diagnostics: if Composer autoload is missing, ga4_build_client() would return ok=false.
$ga4VendorAutoload = __DIR__ . '/vendor/autoload.php';
$ga4VendorMissing = !is_file($ga4VendorAutoload);

[$defaultYear, $defaultMonth] = current_year_month();
$projectId = safe_int($_GET['project_id'] ?? ($_POST['project_id'] ?? 0), 0);
$year = safe_int($_GET['year'] ?? ($_POST['year'] ?? $defaultYear), $defaultYear);
$month = safe_int($_GET['month'] ?? ($_POST['month'] ?? $defaultMonth), $defaultMonth);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_post();
    csrf_verify_or_die();

    $projectId = safe_int($_POST['project_id'] ?? 0, 0);
    $year = safe_int($_POST['year'] ?? $defaultYear, $defaultYear);
    $month = safe_int($_POST['month'] ?? $defaultMonth, $defaultMonth);

    if ($projectId <= 0 || $year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
        flash_set('error', 'Invalid input.');
        redirect('/generate.php');
    }

    $pstmt = $pdo->prepare('SELECT * FROM projects WHERE id = ? LIMIT 1');
    $pstmt->execute([$projectId]);
    $project = $pstmt->fetch();
    if (!$project) {
        flash_set('error', 'Project not found.');
        redirect('/generate.php');
    }

    try {
        $snapshot = generate_report_snapshot($pdo, $project, $year, $month);
        $meta = isset($snapshot['meta']) && is_array($snapshot['meta']) ? (array)$snapshot['meta'] : [];
        $status = (string)($meta['reportStatus'] ?? 'READY');
        $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            log_error('Snapshot json_encode failed', [
                'project_id' => $projectId,
                'year' => $year,
                'month' => $month,
                'status' => $status,
                'json_error' => json_last_error_msg(),
            ]);
            throw new RuntimeException('Failed to encode report snapshot JSON');
        }

        $jsonLen = strlen($json);
        log_info('Snapshot prepared', [
            'project_id' => $projectId,
            'year' => $year,
            'month' => $month,
            'status' => $status,
            'jsonLen' => $jsonLen,
        ]);
        if ($jsonLen < 5000) {
            log_warn('Snapshot JSON is smaller than expected', [
                'project_id' => $projectId,
                'year' => $year,
                'month' => $month,
                'status' => $status,
                'jsonLen' => $jsonLen,
                'top_keys' => array_slice(array_keys($snapshot), 0, 25),
            ]);
        }
        if ($jsonLen < 1000) {
            log_error('Snapshot JSON too small - refusing to write', [
                'project_id' => $projectId,
                'year' => $year,
                'month' => $month,
                'status' => $status,
                'jsonLen' => $jsonLen,
                'top_keys' => array_slice(array_keys($snapshot), 0, 25),
            ]);
            throw new RuntimeException('Generated snapshot JSON too small');
        }

        upsert_monthly_report_json($pdo, $projectId, $year, $month, $status, $json);

        // STEP 1 verification: immediately re-read and validate what we wrote.
        $verifyStmt = $pdo->prepare('
            SELECT id, status, generated_at, LENGTH(data_json) AS json_len, data_json
            FROM monthly_reports
            WHERE project_id = ? AND year = ? AND month = ?
            LIMIT 1
        ');
        $verifyStmt->execute([$projectId, $year, $month]);
        $vr = $verifyStmt->fetch();
        $dbLen = $vr ? (int)($vr['json_len'] ?? 0) : 0;
        log_info('Snapshot DB write check', [
            'project_id' => $projectId,
            'year' => $year,
            'month' => $month,
            'status' => $status,
            'expected_jsonLen' => $jsonLen,
            'db_jsonLen' => $dbLen,
            'report_id' => $vr ? (int)($vr['id'] ?? 0) : 0,
        ]);
        if (!$vr || !is_string($vr['data_json'] ?? null) || trim((string)$vr['data_json']) === '') {
            log_error('Snapshot DB row missing/empty after upsert', [
                'project_id' => $projectId,
                'year' => $year,
                'month' => $month,
                'status' => $status,
            ]);
            throw new RuntimeException('Snapshot DB write verification failed');
        }
        $decoded = json_decode((string)$vr['data_json'], true);
        if (!is_array($decoded)) {
            log_error('Snapshot JSON decode failed after upsert', [
                'project_id' => $projectId,
                'year' => $year,
                'month' => $month,
                'status' => $status,
                'json_error' => json_last_error_msg(),
                'db_jsonLen' => $dbLen,
            ]);
            throw new RuntimeException('Snapshot JSON decode verification failed');
        }

        $idStmt = $pdo->prepare('SELECT id FROM monthly_reports WHERE project_id = ? AND year = ? AND month = ? LIMIT 1');
        $idStmt->execute([$projectId, $year, $month]);
        $rid = (int)$idStmt->fetchColumn();

        flash_set('success', 'Report generated successfully.');
        redirect('/report.php?id=' . $rid);
    } catch (Throwable $e) {
        log_error('Report generation failed', [
            'project_id' => $projectId,
            'year' => $year,
            'month' => $month,
            'error' => $e->getMessage(),
        ]);

        // Best-effort: mark as ERROR in DB, but never crash while handling the failure.
        try {
            upsert_monthly_report_error($pdo, $projectId, $year, $month);
        } catch (Throwable $dbErr) {
            log_error('Failed to record report ERROR status', [
                'project_id' => $projectId,
                'year' => $year,
                'month' => $month,
                'error' => $dbErr->getMessage(),
            ]);
        }

        flash_set('error', 'Report generation failed. Check storage/logs/app.log');
        redirect('/generate.php?project_id=' . $projectId . '&year=' . $year . '&month=' . $month);
    }
}

$projects = $pdo->query('SELECT id, name FROM projects ORDER BY name ASC')->fetchAll();

render_header('Generate Report');
?>

<?php if ($ga4VendorMissing): ?>
  <div class="alert alert--warn">
    GA4 disabled: missing /public_html/vendor/autoload.php. Upload vendor/ (composer install) to enable GA4.
  </div>
<?php endif; ?>

<div class="card">
  <form method="post" action="<?php echo e(url('/generate.php')); ?>">
    <?php echo csrf_input(); ?>

    <div class="form-row">
      <label for="project_id">Project</label>
      <select id="project_id" name="project_id" required>
        <option value="">Select a project</option>
        <?php foreach ($projects as $p): ?>
          <?php $pid = (int)$p['id']; ?>
          <option value="<?php echo e((string)$pid); ?>" <?php echo $pid === $projectId ? 'selected' : ''; ?>>
            <?php echo e((string)$p['name']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

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
      <button class="btn btn--primary" type="submit">Generate snapshot</button>
      <a class="btn" href="<?php echo e(url('/dashboard.php')); ?>">Back</a>
    </div>
  </form>
</div>

<?php
render_footer();

