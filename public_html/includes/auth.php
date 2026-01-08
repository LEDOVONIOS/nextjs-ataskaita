<?php
declare(strict_types=1);

function is_logged_in(): bool
{
    return isset($_SESSION['user']['id'], $_SESSION['user']['role']);
}

function current_user(): ?array
{
    return is_logged_in() ? (array)$_SESSION['user'] : null;
}

function require_login(): void
{
    if (!is_logged_in()) {
        redirect('/index.php');
    }
}

function require_admin(): void
{
    require_login();
    $u = current_user();
    if (!$u || ($u['role'] ?? '') !== 'ADMIN') {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

function logout_user(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

function login_user(array $userRow): void
{
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id' => (int)$userRow['id'],
        'email' => (string)$userRow['email'],
        'name' => (string)$userRow['name'],
        'role' => (string)$userRow['role'],
    ];
}

function attempt_login(PDO $pdo, string $email, string $password): bool
{
    // Emails are ASCII in practice for login; avoid requiring mbstring on shared hosting.
    $email = trim(strtolower($email));
    if ($email === '' || $password === '') {
        return false;
    }

    $stmt = $pdo->prepare('SELECT id, email, name, password_hash, role FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user) {
        return false;
    }

    $stored = (string)$user['password_hash'];
    $verified = false;

    // One-time installer convenience: seed accounts can be stored as "LEGACY:<plain>".
    if (str_starts_with($stored, 'LEGACY:')) {
        $legacyPlain = substr($stored, 7);
        if (hash_equals($legacyPlain, $password)) {
            $verified = true;
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            $upd = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $upd->execute([$newHash, (int)$user['id']]);
            $user['password_hash'] = $newHash;
        }
    } else {
        $verified = password_verify($password, $stored);
    }

    if (!$verified) {
        return false;
    }

    // Opportunistic rehash
    $storedNow = (string)$user['password_hash'];
    if (!str_starts_with($storedNow, 'LEGACY:') && password_needs_rehash($storedNow, PASSWORD_DEFAULT)) {
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        $upd = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $upd->execute([$newHash, (int)$user['id']]);
    }

    login_user($user);
    return true;
}

function user_can_access_project(PDO $pdo, int $userId, string $role, int $projectId): bool
{
    if ($role === 'ADMIN') {
        return true;
    }
    $stmt = $pdo->prepare('SELECT 1 FROM user_project WHERE user_id = ? AND project_id = ? LIMIT 1');
    $stmt->execute([$userId, $projectId]);
    return (bool)$stmt->fetchColumn();
}

