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

$action = safe_string($_GET['action'] ?? '');
$editId = safe_int($_GET['id'] ?? 0, 0);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_post();
    csrf_verify_or_die();

    $op = safe_string($_POST['op'] ?? '');
    if ($op === 'create') {
        $name = trim(safe_string($_POST['name'] ?? ''));
        $ga4 = trim(safe_string($_POST['ga4_property_id'] ?? ''));
        $gsc = trim(safe_string($_POST['gsc_site_url'] ?? ''));
        $showSales = isset($_POST['show_sales_section']) ? 1 : 0;

        if ($name === '') {
            flash_set('error', 'Project name is required.');
            redirect('/admin/projects.php');
        }

        $stmt = $pdo->prepare('INSERT INTO projects (name, ga4_property_id, gsc_site_url, show_sales_section) VALUES (?, ?, ?, ?)');
        $stmt->execute([
            $name,
            $ga4 !== '' ? $ga4 : null,
            $gsc !== '' ? $gsc : null,
            $showSales,
        ]);
        flash_set('success', 'Project created.');
        redirect('/admin/projects.php');
    }

    if ($op === 'update') {
        $id = safe_int($_POST['id'] ?? 0, 0);
        $name = trim(safe_string($_POST['name'] ?? ''));
        $ga4 = trim(safe_string($_POST['ga4_property_id'] ?? ''));
        $gsc = trim(safe_string($_POST['gsc_site_url'] ?? ''));
        $showSales = isset($_POST['show_sales_section']) ? 1 : 0;

        if ($id <= 0 || $name === '') {
            flash_set('error', 'Invalid input.');
            redirect('/admin/projects.php');
        }

        $stmt = $pdo->prepare('UPDATE projects SET name = ?, ga4_property_id = ?, gsc_site_url = ?, show_sales_section = ? WHERE id = ?');
        $stmt->execute([
            $name,
            $ga4 !== '' ? $ga4 : null,
            $gsc !== '' ? $gsc : null,
            $showSales,
            $id,
        ]);
        flash_set('success', 'Project updated.');
        redirect('/admin/projects.php');
    }

    if ($op === 'delete') {
        $id = safe_int($_POST['id'] ?? 0, 0);
        if ($id <= 0) {
            flash_set('error', 'Invalid project.');
            redirect('/admin/projects.php');
        }
        $stmt = $pdo->prepare('DELETE FROM projects WHERE id = ?');
        $stmt->execute([$id]);
        flash_set('success', 'Project deleted.');
        redirect('/admin/projects.php');
    }

    flash_set('error', 'Unknown operation.');
    redirect('/admin/projects.php');
}

$projects = $pdo->query('SELECT id, name, ga4_property_id, gsc_site_url, show_sales_section, created_at FROM projects ORDER BY created_at DESC')->fetchAll();
$editProject = null;
if ($action === 'edit' && $editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM projects WHERE id = ? LIMIT 1');
    $stmt->execute([$editId]);
    $editProject = $stmt->fetch();
}

render_header('Admin: Projects');
?>

<div class="grid-2">
  <div class="card">
    <div class="card__title"><?php echo $editProject ? 'Edit project' : 'Create project'; ?></div>
    <form method="post" action="<?php echo e(url('/admin/projects.php')); ?>">
      <?php echo csrf_input(); ?>
      <input type="hidden" name="op" value="<?php echo e($editProject ? 'update' : 'create'); ?>">
      <?php if ($editProject): ?>
        <input type="hidden" name="id" value="<?php echo e((string)$editProject['id']); ?>">
      <?php endif; ?>

      <div class="form-row">
        <label>Name</label>
        <input name="name" type="text" required value="<?php echo e((string)($editProject['name'] ?? '')); ?>">
      </div>
      <div class="form-row">
        <label>GA4 Property ID (stored only)</label>
        <input name="ga4_property_id" type="text" value="<?php echo e((string)($editProject['ga4_property_id'] ?? '')); ?>">
      </div>
      <div class="form-row">
        <label>GSC Site URL (stored only)</label>
        <input name="gsc_site_url" type="text" placeholder="https://example.com/" value="<?php echo e((string)($editProject['gsc_site_url'] ?? '')); ?>">
      </div>
      <div class="form-row">
        <label class="checkbox">
          <input type="checkbox" name="show_sales_section" value="1" <?php echo ((int)($editProject['show_sales_section'] ?? 1) === 1) ? 'checked' : ''; ?>>
          Show sales section
        </label>
      </div>

      <div class="form-actions">
        <button class="btn btn--primary" type="submit"><?php echo $editProject ? 'Save changes' : 'Create project'; ?></button>
        <?php if ($editProject): ?>
          <a class="btn" href="<?php echo e(url('/admin/projects.php')); ?>">Cancel</a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <div class="card">
    <div class="card__title">All projects</div>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Sales</th>
            <th class="right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($projects as $p): ?>
            <?php $id = (int)$p['id']; ?>
            <tr>
              <td>
                <div><strong><?php echo e((string)$p['name']); ?></strong></div>
                <div class="muted">ID: <?php echo e((string)$id); ?> · Created: <?php echo e((string)$p['created_at']); ?></div>
                <div class="muted">GA4: <?php echo e((string)($p['ga4_property_id'] ?? '—')); ?> · GSC: <?php echo e((string)($p['gsc_site_url'] ?? '—')); ?></div>
              </td>
              <td>
                <span class="badge"><?php echo ((int)$p['show_sales_section'] === 1) ? 'ON' : 'OFF'; ?></span>
              </td>
              <td class="right">
                <a class="btn btn--small" href="<?php echo e(url('/admin/projects.php')) . '?action=edit&id=' . e((string)$id); ?>">Edit</a>
                <a class="btn btn--small" href="<?php echo e(url('/generate.php')) . '?project_id=' . e((string)$id); ?>">Generate</a>
                <form method="post" action="<?php echo e(url('/admin/projects.php')); ?>" class="inline" onsubmit="return confirm('Delete this project and all associated data (reports, notes, assignments)?');">
                  <?php echo csrf_input(); ?>
                  <input type="hidden" name="op" value="delete">
                  <input type="hidden" name="id" value="<?php echo e((string)$id); ?>">
                  <button class="btn btn--small btn--danger" type="submit">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php
render_footer();

