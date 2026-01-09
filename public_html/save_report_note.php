<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/auth.php';

require_login();
$pdo = db();
$u = current_user();
$userId = (int)($u['id'] ?? 0);
$role = (string)($u['role'] ?? '');

header('Content-Type: application/json; charset=utf-8');

function json_out(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

require_post();
csrf_verify_or_die();

$projectId = safe_int($_POST['project_id'] ?? null, 0);
$year = safe_int($_POST['year'] ?? null, 0);
$month = safe_int($_POST['month'] ?? null, 0);
$scope = trim(safe_string($_POST['scope'] ?? null, ''));
$content = safe_string($_POST['content'] ?? null, '');

$allowedScopes = ['all_visitors', 'seo', 'ppc', 'social_organic', 'social_paid', 'referral', 'email'];
if ($projectId <= 0 || $year < 2000 || $year > 2100 || $month < 1 || $month > 12 || $scope === '' || !in_array($scope, $allowedScopes, true)) {
    json_out(400, ['ok' => false, 'error' => 'Invalid input']);
}

if (!user_can_access_project($pdo, $userId, $role, $projectId)) {
    json_out(403, ['ok' => false, 'error' => 'Forbidden']);
}

$canEdit = ($role === 'ADMIN' || $role === 'SPECIALIST');
if (!$canEdit) {
    json_out(403, ['ok' => false, 'error' => 'Read-only']);
}

// UPSERT note (project_id + year + month + scope) without relying on a unique index.
$upd = $pdo->prepare('
    UPDATE notes
    SET content = ?, updated_at = NOW()
    WHERE project_id = ? AND year = ? AND month = ? AND scope = ?
');
$upd->execute([$content, $projectId, $year, $month, $scope]);
if ($upd->rowCount() === 0) {
    $ins = $pdo->prepare('
        INSERT INTO notes (project_id, year, month, scope, content, created_by, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ');
    $ins->execute([$projectId, $year, $month, $scope, $content, $userId]);
}

json_out(200, ['ok' => true]);

