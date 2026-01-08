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
        upsert_monthly_report($pdo, $projectId, $year, $month, $status, $snapshot);

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

