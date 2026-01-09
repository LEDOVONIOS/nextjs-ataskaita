<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

require_admin();

function ga4_req_pass_fail(bool $ok): string
{
    return $ok ? 'PASS' : 'FAIL';
}

function ga4_req_row(string $label, bool $ok, string $details = ''): string
{
    $status = ga4_req_pass_fail($ok);
    $class = $ok ? 'badge badge--ready' : 'badge badge--error';
    $detailsHtml = $details !== '' ? '<div class="muted" style="margin-top:4px"><code>' . e($details) . '</code></div>' : '';
    return '<tr>'
        . '<td>' . e($label) . '</td>'
        . '<td><span class="' . e($class) . '">' . e($status) . '</span></td>'
        . '<td>' . $detailsHtml . '</td>'
        . '</tr>';
}

$autoloadPath = __DIR__ . '/vendor/autoload.php';
$autoloadOk = is_file($autoloadPath);

$keyPathSource = 'GOOGLE_SA_KEY_PATH';
$rawKeyPath = trim((string)(getenv('GOOGLE_SA_KEY_PATH') ?: ''));
if ($rawKeyPath !== '') {
    $keyPathSource = 'env:GOOGLE_SA_KEY_PATH';
} elseif (defined('GOOGLE_SA_KEY_PATH')) {
    $rawKeyPath = trim((string)GOOGLE_SA_KEY_PATH);
    $keyPathSource = 'const:GOOGLE_SA_KEY_PATH';
}

$resolvedKeyPath = $rawKeyPath;
if ($resolvedKeyPath !== '' && $resolvedKeyPath[0] !== '/' && !preg_match('/^[A-Za-z]:\\\\/', $resolvedKeyPath)) {
    // Match includes/ga4_client.php: relative paths resolve relative to /includes.
    $resolvedKeyPath = __DIR__ . '/includes/' . ltrim($resolvedKeyPath, '/');
}

$keyOk = ($resolvedKeyPath !== '') && is_file($resolvedKeyPath);

render_header('GA4 Requirements');
?>

<div class="card">
  <p class="muted">Admin-only diagnostics for GA4 prerequisites.</p>

  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>Check</th>
          <th>Status</th>
          <th>Path / Details</th>
        </tr>
      </thead>
      <tbody>
        <?php
          echo ga4_req_row('vendor/autoload.php exists', $autoloadOk, $autoloadPath);
          echo ga4_req_row($keyPathSource . ' file exists', $keyOk, $resolvedKeyPath !== '' ? $resolvedKeyPath : '(empty)');
        ?>
      </tbody>
    </table>
  </div>

  <div class="card__actions">
    <a class="btn" href="<?php echo e(url('/dashboard.php')); ?>">Back</a>
  </div>
</div>

<?php
render_footer();

