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
$isAdmin = $role === 'ADMIN';

if ($isAdmin) {
    $projectsStmt = $pdo->query('SELECT id, name, show_sales_section, created_at FROM projects ORDER BY name ASC');
    $projects = $projectsStmt->fetchAll();
} else {
    $stmt = $pdo->prepare('
        SELECT p.id, p.name, p.show_sales_section, p.created_at
        FROM projects p
        INNER JOIN user_project up ON up.project_id = p.id
        WHERE up.user_id = ?
        ORDER BY p.name ASC
    ');
    $stmt->execute([$userId]);
    $projects = $stmt->fetchAll();
}

$projectIds = array_map(fn($p) => (int)$p['id'], $projects);
$reportsByProject = [];
if ($projectIds) {
    $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
    $sql = "
        SELECT id, project_id, year, month, status, generated_at
        FROM monthly_reports
        WHERE project_id IN ($placeholders)
        ORDER BY year DESC, month DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($projectIds);
    $rows = $stmt->fetchAll();
    foreach ($rows as $r) {
        $pid = (int)$r['project_id'];
        $reportsByProject[$pid][] = $r;
    }
}

render_header('Dashboard');
?>

<?php if (!$projects): ?>
  <div class="card">
    <p>No projects available for your account yet.</p>
    <?php if ($isAdmin): ?>
      <p><a class="btn btn--primary" href="<?php echo e(url('/admin/projects.php')); ?>">Create your first project</a></p>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php foreach ($projects as $p): ?>
  <?php
    $pid = (int)$p['id'];
    $reports = $reportsByProject[$pid] ?? [];
  ?>
  <div class="card">
    <div class="card__header">
      <div>
        <div class="card__title"><?php echo e((string)$p['name']); ?></div>
        <div class="muted">Project ID: <?php echo e((string)$pid); ?></div>
      </div>
      <?php if ($isAdmin): ?>
        <div class="card__actions">
          <a class="btn btn--small" href="<?php echo e(url('/generate.php')) . '?project_id=' . e((string)$pid); ?>">Generate</a>
        </div>
      <?php endif; ?>
    </div>

    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Month</th>
            <th>Status</th>
            <th>Generated</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$reports): ?>
          <tr>
            <td colspan="4" class="muted">No reports generated yet.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($reports as $r): ?>
            <?php
              $status = (string)$r['status'];
              $label = (string)$r['year'] . '-' . str_pad((string)$r['month'], 2, '0', STR_PAD_LEFT);
              $generatedAt = $r['generated_at'] ? (string)$r['generated_at'] : '';
              $rid = (int)$r['id'];
            ?>
            <tr>
              <td><?php echo e($label); ?></td>
              <td>
                <span class="badge badge--<?php echo e(strtolower($status)); ?>"><?php echo e($status); ?></span>
              </td>
              <td class="muted"><?php echo e($generatedAt); ?></td>
              <td>
                <?php if ($status === 'READY'): ?>
                  <a class="btn btn--small btn--primary" href="<?php echo e(url('/report.php')) . '?id=' . e((string)$rid); ?>">View report</a>
                <?php else: ?>
                  <span class="muted">—</span>
                <?php endif; ?>
                <?php if ($isAdmin): ?>
                  <a class="btn btn--small" href="<?php echo e(url('/generate.php')) . '?project_id=' . e((string)$pid) . '&year=' . e((string)$r['year']) . '&month=' . e((string)$r['month']); ?>">Regenerate</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endforeach; ?>

<?php
render_footer();

