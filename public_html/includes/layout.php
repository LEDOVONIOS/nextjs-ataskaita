<?php
declare(strict_types=1);

function render_header(string $title): void
{
    $u = current_user();
    $flash = flash_all();
    $isAdmin = $u && ($u['role'] ?? '') === 'ADMIN';

    echo '<!doctype html>';
    echo '<html lang="en">';
    echo '<head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($title) . ' - ' . e(APP_NAME) . '</title>';
    echo '<link rel="stylesheet" href="' . e(url('/assets/css/style.css')) . '">';
    echo '</head>';
    echo '<body>';
    echo '<header class="topbar">';
    echo '<div class="container topbar__inner">';
    echo '<div class="brand"><a href="' . e(url('/dashboard.php')) . '">' . e(APP_NAME) . '</a></div>';
    if ($u) {
        echo '<nav class="nav">';
        echo '<a class="nav__link" href="' . e(url('/dashboard.php')) . '">Dashboard</a>';
        if ($isAdmin) {
            echo '<a class="nav__link" href="' . e(url('/generate.php')) . '">Generate</a>';
            echo '<a class="nav__link" href="' . e(url('/generate_all.php')) . '">Generate All</a>';
            echo '<a class="nav__link" href="' . e(url('/admin/users.php')) . '">Users</a>';
            echo '<a class="nav__link" href="' . e(url('/admin/projects.php')) . '">Projects</a>';
            echo '<a class="nav__link" href="' . e(url('/admin/assignments.php')) . '">Assignments</a>';
            echo '<a class="nav__link" href="' . e(url('/admin/notes.php')) . '">Notes</a>';
        }
        echo '<span class="nav__sep"></span>';
        echo '<span class="nav__meta">' . e((string)$u['name']) . ' (' . e((string)$u['role']) . ')</span>';
        echo '<a class="nav__link" href="' . e(url('/logout.php')) . '">Logout</a>';
        echo '</nav>';
    }
    echo '</div>';
    echo '</header>';

    echo '<main class="container">';
    foreach ($flash as $k => $msg) {
        $class = 'alert alert--success';
        if ($k === 'error') {
            $class = 'alert alert--error';
        } elseif ($k === 'warn') {
            $class = 'alert alert--warn';
        }
        echo '<div class="' . e($class) . '">' . e((string)$msg) . '</div>';
    }
    echo '<h1 class="page-title">' . e($title) . '</h1>';
}

function render_footer(): void
{
    echo '</main>';
    echo '<footer class="footer"><div class="container footer__inner">Phase 2: GA4 optional (Visitors Overview) + mock for remaining sections.</div></footer>';
    echo '</body></html>';
}

