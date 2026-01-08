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

$projectId = safe_int($_GET['project_id'] ?? ($_POST['project_id'] ?? 0), 0);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_post();
    csrf_verify_or_die();

    $projectId = safe_int($_POST['project_id'] ?? 0, 0);
    if ($projectId <= 0) {
        flash_set('error', 'Select a project.');
        redirect('/admin/assignments.php');
    }

    $selected = $_POST['user_ids'] ?? [];
    if (!is_array($selected)) {
        $selected = [];
    }
    $selectedIds = [];
    foreach ($selected as $v) {
        $id = safe_int($v, 0);
        if ($id > 0) {
            $selectedIds[] = $id;
        }
    }
    $selectedIds = array_values(array_unique($selectedIds));

    $pdo->beginTransaction();
    try {
        $del = $pdo->prepare('DELETE FROM user_project WHERE project_id = ?');
        $del->execute([$projectId]);

        if ($selectedIds) {
            $ins = $pdo->prepare('INSERT INTO user_project (user_id, project_id) VALUES (?, ?)');
            foreach ($selectedIds as $uid) {
                $ins->execute([$uid, $projectId]);
            }
        }

        $pdo->commit();
        flash_set('success', 'Assignments saved.');
    } catch (Throwable $e) {
        $pdo->rollBack();
        flash_set('error', 'Failed to save assignments.');
    }
    redirect('/admin/assignments.php?project_id=' . $projectId);
}

$projects = $pdo->query('SELECT id, name FROM projects ORDER BY name ASC')->fetchAll();
$users = $pdo->query('SELECT id, name, email, role FROM users ORDER BY name ASC')->fetchAll();

$assigned = [];
$project = null;
if ($projectId > 0) {
    $pstmt = $pdo->prepare('SELECT id, name FROM projects WHERE id = ? LIMIT 1');
    $pstmt->execute([$projectId]);
    $project = $pstmt->fetch();

    if ($project) {
        $astmt = $pdo->prepare('SELECT user_id FROM user_project WHERE project_id = ?');
        $astmt->execute([$projectId]);
        foreach ($astmt->fetchAll() as $r) {
            $assigned[(int)$r['user_id']] = true;
        }
    }
}

render_header('Admin: Assignments');
?>

<div class="card">
  <form method="get" action="<?php echo e(url('/admin/assignments.php')); ?>" class="form-inline">
    <div class="form-row">
      <label for="project_id">Project</label>
      <select id="project_id" name="project_id" onchange="this.form.submit()">
        <option value="">Select a project</option>
        <?php foreach ($projects as $p): ?>
          <?php $pid = (int)$p['id']; ?>
          <option value="<?php echo e((string)$pid); ?>" <?php echo $pid === $projectId ? 'selected' : ''; ?>>
            <?php echo e((string)$p['name']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <noscript><button class="btn btn--small" type="submit">Load</button></noscript>
  </form>
</div>

<?php if ($projectId > 0 && !$project): ?>
  <div class="card"><p class="muted">Project not found.</p></div>
<?php endif; ?>

<?php if ($project): ?>
  <div class="card">
    <div class="card__title">Assign users to: <?php echo e((string)$project['name']); ?></div>
    <form method="post" action="<?php echo e(url('/admin/assignments.php')); ?>">
      <?php echo csrf_input(); ?>
      <input type="hidden" name="project_id" value="<?php echo e((string)$projectId); ?>">

      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>Assigned</th>
              <th>User</th>
              <th>Email</th>
              <th>Role</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($users as $u): ?>
              <?php
                $uid = (int)$u['id'];
                $checked = isset($assigned[$uid]);
              ?>
              <tr>
                <td>
                  <input type="checkbox" name="user_ids[]" value="<?php echo e((string)$uid); ?>" <?php echo $checked ? 'checked' : ''; ?>>
                </td>
                <td><?php echo e((string)$u['name']); ?></td>
                <td><?php echo e((string)$u['email']); ?></td>
                <td><span class="badge"><?php echo e((string)$u['role']); ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="form-actions">
        <button class="btn btn--primary" type="submit">Save assignments</button>
      </div>
    </form>
  </div>
<?php endif; ?>

<?php
render_footer();

