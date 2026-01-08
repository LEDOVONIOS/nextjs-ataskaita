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
            $status = (string)($snapshot['_report_status'] ?? 'READY');
            upsert_monthly_report($pdo, $pid, $year, $month, $status, $snapshot);
            $ok++;
            $results[] = ['project' => (string)$project['name'], 'status' => $status === 'PARTIAL' ? 'PARTIAL' : 'OK'];
        } catch (Throwable $e) {
            $fail++;
            error_log('Report generation failed (project_id=' . $pid . ', ' . $year . '-' . $month . '): ' . $e->getMessage());
            try {
                $err = $pdo->prepare("
                    INSERT INTO monthly_reports (project_id, year, month, status, generated_at, data_json)
                    VALUES (?, ?, ?, 'ERROR', UTC_TIMESTAMP(), NULL)
                    ON DUPLICATE KEY UPDATE status = 'ERROR', generated_at = UTC_TIMESTAMP(), data_json = NULL
                ");
                $err->execute([$pid, $year, $month]);
            } catch (Throwable $e2) {
                error_log('Failed to persist ERROR status for monthly report (project_id=' . $pid . ', ' . $year . '-' . $month . '): ' . $e2->getMessage());
            }
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

