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

// Defensive: ensure the scoped notes table exists (shared-hosting friendly).
try {
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS monthly_notes_scoped (
          project_id INT UNSIGNED NOT NULL,
          year INT NOT NULL,
          month INT NOT NULL,
          scope VARCHAR(64) NOT NULL,
          note_text TEXT NULL,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (project_id, year, month, scope),
          KEY idx_monthly_notes_scoped_project (project_id),
          CONSTRAINT fk_monthly_notes_scoped_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ');
} catch (Throwable $e) {
    // If the DB user lacks privileges, fail gracefully with a clear error.
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB init failed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_out(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    $projectId = safe_int($_GET['project_id'] ?? null, 0);
    $year = safe_int($_GET['year'] ?? null, 0);
    $month = safe_int($_GET['month'] ?? null, 0);
    $scope = safe_string($_GET['scope'] ?? null, '');

    if ($projectId <= 0 || $year < 2000 || $year > 2100 || $month < 1 || $month > 12 || $scope === '') {
        json_out(400, ['ok' => false, 'error' => 'Invalid input']);
    }
    if (!user_can_access_project($pdo, $userId, $role, $projectId)) {
        json_out(403, ['ok' => false, 'error' => 'Forbidden']);
    }

    $stmt = $pdo->prepare('
        SELECT note_text
        FROM monthly_notes_scoped
        WHERE project_id = ? AND year = ? AND month = ? AND scope = ?
        LIMIT 1
    ');
    $stmt->execute([$projectId, $year, $month, $scope]);
    $val = $stmt->fetchColumn();
    $text = is_string($val) ? $val : '';

    json_out(200, ['ok' => true, 'note_text' => $text]);
}

if ($method === 'POST') {
    require_post();
    csrf_verify_or_die();

    $projectId = safe_int($_POST['project_id'] ?? null, 0);
    $year = safe_int($_POST['year'] ?? null, 0);
    $month = safe_int($_POST['month'] ?? null, 0);
    $scope = safe_string($_POST['scope'] ?? null, '');
    $text = safe_string($_POST['note_text'] ?? null, '');

    if ($projectId <= 0 || $year < 2000 || $year > 2100 || $month < 1 || $month > 12 || $scope === '') {
        json_out(400, ['ok' => false, 'error' => 'Invalid input']);
    }
    if (!user_can_access_project($pdo, $userId, $role, $projectId)) {
        json_out(403, ['ok' => false, 'error' => 'Forbidden']);
    }

    $stmt = $pdo->prepare('
        INSERT INTO monthly_notes_scoped (project_id, year, month, scope, note_text)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE note_text = VALUES(note_text)
    ');
    $stmt->execute([$projectId, $year, $month, $scope, $text]);

    json_out(200, ['ok' => true]);
}

json_out(405, ['ok' => false, 'error' => 'Method not allowed']);

