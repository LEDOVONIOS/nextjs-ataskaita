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
$me = current_user();
$myId = (int)$me['id'];

$action = safe_string($_GET['action'] ?? '');
$editId = safe_int($_GET['id'] ?? 0, 0);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_post();
    csrf_verify_or_die();

    $op = safe_string($_POST['op'] ?? '');
    if ($op === 'create') {
        $email = trim(strtolower(safe_string($_POST['email'] ?? '')));
        $name = trim(safe_string($_POST['name'] ?? ''));
        $role = safe_string($_POST['role'] ?? 'USER');
        $password = safe_string($_POST['password'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '' || !in_array($role, ['ADMIN', 'USER'], true) || strlen($password) < 6) {
            flash_set('error', 'Invalid input (password must be at least 6 characters).');
            redirect('/admin/users.php');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('INSERT INTO users (email, name, password_hash, role) VALUES (?, ?, ?, ?)');
        try {
            $stmt->execute([$email, $name, $hash, $role]);
            flash_set('success', 'User created.');
        } catch (Throwable $e) {
            flash_set('error', 'Failed to create user (email may already exist).');
        }
        redirect('/admin/users.php');
    }

    if ($op === 'update') {
        $id = safe_int($_POST['id'] ?? 0, 0);
        $email = trim(strtolower(safe_string($_POST['email'] ?? '')));
        $name = trim(safe_string($_POST['name'] ?? ''));
        $role = safe_string($_POST['role'] ?? 'USER');
        $password = safe_string($_POST['password'] ?? '');

        if ($id <= 0 || !filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '' || !in_array($role, ['ADMIN', 'USER'], true)) {
            flash_set('error', 'Invalid input.');
            redirect('/admin/users.php');
        }

        try {
            if ($password !== '') {
                if (strlen($password) < 6) {
                    flash_set('error', 'Password must be at least 6 characters.');
                    redirect('/admin/users.php?action=edit&id=' . $id);
                }
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('UPDATE users SET email = ?, name = ?, role = ?, password_hash = ? WHERE id = ?');
                $stmt->execute([$email, $name, $role, $hash, $id]);
            } else {
                $stmt = $pdo->prepare('UPDATE users SET email = ?, name = ?, role = ? WHERE id = ?');
                $stmt->execute([$email, $name, $role, $id]);
            }

            // If updating self, refresh session.
            if ($id === $myId) {
                $_SESSION['user']['email'] = $email;
                $_SESSION['user']['name'] = $name;
                $_SESSION['user']['role'] = $role;
            }

            flash_set('success', 'User updated.');
        } catch (Throwable $e) {
            flash_set('error', 'Failed to update user (email may already exist).');
        }
        redirect('/admin/users.php');
    }

    if ($op === 'delete') {
        $id = safe_int($_POST['id'] ?? 0, 0);
        if ($id <= 0) {
            flash_set('error', 'Invalid user.');
            redirect('/admin/users.php');
        }
        if ($id === $myId) {
            flash_set('error', 'You cannot delete your own account while logged in.');
            redirect('/admin/users.php');
        }

        // Prevent deleting the last admin
        $roleStmt = $pdo->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
        $roleStmt->execute([$id]);
        $role = $roleStmt->fetchColumn();
        if ($role === 'ADMIN') {
            $countAdmins = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'ADMIN'")->fetchColumn();
            if ($countAdmins <= 1) {
                flash_set('error', 'You cannot delete the last ADMIN user.');
                redirect('/admin/users.php');
            }
        }

        $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
        $stmt->execute([$id]);
        flash_set('success', 'User deleted.');
        redirect('/admin/users.php');
    }

    flash_set('error', 'Unknown operation.');
    redirect('/admin/users.php');
}

$users = $pdo->query('SELECT id, email, name, role, created_at FROM users ORDER BY created_at DESC')->fetchAll();
$editUser = null;
if ($action === 'edit' && $editId > 0) {
    $stmt = $pdo->prepare('SELECT id, email, name, role FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$editId]);
    $editUser = $stmt->fetch();
}

render_header('Admin: Users');
?>

<div class="grid-2">
  <div class="card">
    <div class="card__title"><?php echo $editUser ? 'Edit user' : 'Create user'; ?></div>
    <form method="post" action="<?php echo e(url('/admin/users.php')); ?>">
      <?php echo csrf_input(); ?>
      <input type="hidden" name="op" value="<?php echo e($editUser ? 'update' : 'create'); ?>">
      <?php if ($editUser): ?>
        <input type="hidden" name="id" value="<?php echo e((string)$editUser['id']); ?>">
      <?php endif; ?>

      <div class="form-row">
        <label>Email</label>
        <input name="email" type="email" required value="<?php echo e((string)($editUser['email'] ?? '')); ?>">
      </div>
      <div class="form-row">
        <label>Name</label>
        <input name="name" type="text" required value="<?php echo e((string)($editUser['name'] ?? '')); ?>">
      </div>
      <div class="form-row">
        <label>Role</label>
        <select name="role" required>
          <?php $r = (string)($editUser['role'] ?? 'USER'); ?>
          <option value="USER" <?php echo $r === 'USER' ? 'selected' : ''; ?>>USER</option>
          <option value="ADMIN" <?php echo $r === 'ADMIN' ? 'selected' : ''; ?>>ADMIN</option>
        </select>
      </div>
      <div class="form-row">
        <label><?php echo $editUser ? 'New password (leave blank to keep)' : 'Password'; ?></label>
        <input name="password" type="password" <?php echo $editUser ? '' : 'required'; ?> autocomplete="new-password">
      </div>

      <div class="form-actions">
        <button class="btn btn--primary" type="submit"><?php echo $editUser ? 'Save changes' : 'Create user'; ?></button>
        <?php if ($editUser): ?>
          <a class="btn" href="<?php echo e(url('/admin/users.php')); ?>">Cancel</a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <div class="card">
    <div class="card__title">All users</div>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Email</th>
            <th>Role</th>
            <th>Created</th>
            <th class="right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $u): ?>
            <?php $id = (int)$u['id']; ?>
            <tr>
              <td><?php echo e((string)$u['name']); ?></td>
              <td><?php echo e((string)$u['email']); ?></td>
              <td><span class="badge"><?php echo e((string)$u['role']); ?></span></td>
              <td class="muted"><?php echo e((string)$u['created_at']); ?></td>
              <td class="right">
                <a class="btn btn--small" href="<?php echo e(url('/admin/users.php')) . '?action=edit&id=' . e((string)$id); ?>">Edit</a>
                <form method="post" action="<?php echo e(url('/admin/users.php')); ?>" class="inline" onsubmit="return confirm('Delete this user?');">
                  <?php echo csrf_input(); ?>
                  <input type="hidden" name="op" value="delete">
                  <input type="hidden" name="id" value="<?php echo e((string)$id); ?>">
                  <button class="btn btn--small btn--danger" type="submit" <?php echo $id === $myId ? 'disabled' : ''; ?>>Delete</button>
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

