<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

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
    $work = safe_string($_POST['work_summary'] ?? '');

    if ($projectId <= 0 || $year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
        flash_set('error', 'Invalid input.');
        redirect('/admin/notes.php');
    }

    $stmt = $pdo->prepare('
        INSERT INTO monthly_notes (project_id, year, month, work_summary)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE work_summary = VALUES(work_summary)
    ');
    $stmt->execute([$projectId, $year, $month, $work]);

    flash_set('success', 'Monthly note saved.');
    redirect('/admin/notes.php?project_id=' . $projectId . '&year=' . $year . '&month=' . $month);
}

$projects = $pdo->query('SELECT id, name FROM projects ORDER BY name ASC')->fetchAll();
$project = null;
if ($projectId > 0) {
    $pstmt = $pdo->prepare('SELECT id, name FROM projects WHERE id = ? LIMIT 1');
    $pstmt->execute([$projectId]);
    $project = $pstmt->fetch();
}

$existing = '';
if ($project) {
    $stmt = $pdo->prepare('SELECT work_summary FROM monthly_notes WHERE project_id = ? AND year = ? AND month = ? LIMIT 1');
    $stmt->execute([$projectId, $year, $month]);
    $val = $stmt->fetchColumn();
    $existing = is_string($val) ? $val : '';
}

$history = [];
if ($project) {
    $stmt = $pdo->prepare('
        SELECT year, month, LEFT(work_summary, 180) AS snippet
        FROM monthly_notes
        WHERE project_id = ?
        ORDER BY year DESC, month DESC
        LIMIT 12
    ');
    $stmt->execute([$projectId]);
    $history = $stmt->fetchAll();
}

render_header('Admin: Monthly Notes');
?>

<div class="card">
  <form method="get" action="<?php echo e(url('/admin/notes.php')); ?>" class="form-inline">
    <div class="form-row">
      <label for="project_id">Project</label>
      <select id="project_id" name="project_id">
        <option value="">Select a project</option>
        <?php foreach ($projects as $p): ?>
          <?php $pid = (int)$p['id']; ?>
          <option value="<?php echo e((string)$pid); ?>" <?php echo $pid === $projectId ? 'selected' : ''; ?>>
            <?php echo e((string)$p['name']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-row">
      <label for="year">Year</label>
      <input id="year" name="year" type="number" min="2000" max="2100" value="<?php echo e((string)$year); ?>">
    </div>
    <div class="form-row">
      <label for="month">Month</label>
      <input id="month" name="month" type="number" min="1" max="12" value="<?php echo e((string)$month); ?>">
    </div>
    <button class="btn btn--small" type="submit">Load</button>
  </form>
</div>

<?php if ($projectId > 0 && !$project): ?>
  <div class="card"><p class="muted">Project not found.</p></div>
<?php endif; ?>

<?php if ($project): ?>
  <div class="grid-2">
    <div class="card">
      <div class="card__title">Work summary — <?php echo e((string)$project['name']); ?> (<?php echo e((string)$year); ?>-<?php echo e(str_pad((string)$month, 2, '0', STR_PAD_LEFT)); ?>)</div>
      <p class="muted">Note: the report snapshot will include whatever is saved here at generation time.</p>
      <form method="post" action="<?php echo e(url('/admin/notes.php')); ?>">
        <?php echo csrf_input(); ?>
        <input type="hidden" name="project_id" value="<?php echo e((string)$projectId); ?>">
        <input type="hidden" name="year" value="<?php echo e((string)$year); ?>">
        <input type="hidden" name="month" value="<?php echo e((string)$month); ?>">
        <div class="form-row">
          <label for="work_summary">Work summary</label>
          <textarea id="work_summary" name="work_summary" rows="10"><?php echo e($existing); ?></textarea>
        </div>
        <div class="form-actions">
          <button class="btn btn--primary" type="submit">Save note</button>
          <a class="btn" href="<?php echo e(url('/generate.php')) . '?project_id=' . e((string)$projectId) . '&year=' . e((string)$year) . '&month=' . e((string)$month); ?>">Generate report for this month</a>
        </div>
      </form>
    </div>

    <div class="card">
      <div class="card__title">Recent notes</div>
      <?php if (!$history): ?>
        <p class="muted">No notes saved yet.</p>
      <?php else: ?>
        <ul class="list">
          <?php foreach ($history as $h): ?>
            <?php
              $yl = (int)$h['year'];
              $ml = (int)$h['month'];
              $label = $yl . '-' . str_pad((string)$ml, 2, '0', STR_PAD_LEFT);
              $snippet = (string)($h['snippet'] ?? '');
            ?>
            <li>
              <a href="<?php echo e(url('/admin/notes.php')) . '?project_id=' . e((string)$projectId) . '&year=' . e((string)$yl) . '&month=' . e((string)$ml); ?>">
                <strong><?php echo e($label); ?></strong>
              </a>
              <div class="muted"><?php echo e($snippet); ?><?php echo (strlen($snippet) >= 180) ? '…' : ''; ?></div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php
render_footer();

