<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

if (is_logged_in()) {
    redirect('/dashboard.php');
}

$pdo = db();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_post();
    csrf_verify_or_die();

    $email = safe_string($_POST['email'] ?? '');
    $password = safe_string($_POST['password'] ?? '');

    // Small delay to reduce brute-force efficiency (shared hosting friendly).
    usleep(200000);

    if (attempt_login($pdo, $email, $password)) {
        flash_set('success', 'Welcome back.');
        redirect('/dashboard.php');
    }
    flash_set('error', 'Invalid email or password.');
    redirect('/index.php');
}

render_header('Login');
?>

<div class="card">
  <form method="post" action="<?php echo e(url('/index.php')); ?>">
    <?php echo csrf_input(); ?>

    <div class="form-row">
      <label for="email">Email</label>
      <input id="email" name="email" type="email" required autocomplete="username" inputmode="email">
    </div>

    <div class="form-row">
      <label for="password">Password</label>
      <input id="password" name="password" type="password" required autocomplete="current-password">
    </div>

    <div class="form-actions">
      <button class="btn btn--primary" type="submit">Login</button>
    </div>
  </form>
</div>

<?php
render_footer();

