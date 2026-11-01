<?php
declare(strict_types=1);

/* Page layout and shared HTML pieces. Same look and structure as the .NET portal. */

const MONTH_NAMES = ['January', 'February', 'March', 'April', 'May', 'June', 'July',
    'August', 'September', 'October', 'November', 'December'];

function month_options(?int $selected): string
{
    $html = '<option value="">All months</option>';
    foreach (MONTH_NAMES as $i => $name) {
        $n = $i + 1;
        $html .= '<option value="' . $n . '"' . ($selected === $n ? ' selected' : '') . '>' . $name . '</option>';
    }
    return $html;
}

function year_options(array $years, ?int $selected): string
{
    $html = '<option value="">All years</option>';
    foreach ($years as $y) {
        $html .= '<option value="' . $y . '"' . ($selected === $y ? ' selected' : '') . '>' . $y . '</option>';
    }
    return $html;
}

/** Years that appear in the data, plus the current year. */
function years_in(array $requests): array
{
    $years = [(int)date('Y')];
    foreach ($requests as $r) $years[] = (int)substr($r['work_date'], 0, 4);
    $years = array_values(array_unique($years));
    rsort($years);
    return $years;
}

function flash_html(): string
{
    $f = take_flash();
    if (!$f) return '';
    return '<div class="flash ' . h($f['type']) . '">' . h($f['text']) . '</div>';
}

function render_page(string $title, string $body): void
{
    $user = current_user();
    $nav = '';
    $who = '';
    $current = $_GET['page'] ?? '';

    if ($user) {
        $links = [];
        if ($user['role'] === 'Admin') $links[] = ['admin', 'Administration'];
        if ($user['role'] === 'Manager') $links[] = ['approvals', 'Approvals'];
        if (in_array($user['role'], ['Manager', 'Admin'], true)) $links[] = ['roster', 'Roster'];
        if ($user['role'] !== 'Admin') {
            $links[] = ['home', 'My overtime'];
            $links[] = ['file', 'File overtime'];
        }
        foreach ($links as [$page, $label]) {
            $nav .= '<a href="' . h(url($page)) . '"' . ($current === $page ? ' class="active"' : '') . '>' . h($label) . '</a>';
        }
        $who = '<span class="who">' . h($user['full_name']) . '</span>
            <form method="post" action="' . h(url('logout')) . '" class="signout">
                <input type="hidden" name="_csrf" value="' . h(csrf_token()) . '" />
                <button type="submit" class="link-button">Sign out</button>
            </form>';
    }

    echo '<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
    <meta name="theme-color" content="#e0174f" />
    <title>' . h($title) . ' · IBEX Overtime Portal</title>
    <link rel="stylesheet" href="assets/site.css" />
</head>
<body>
    <header class="topbar">
        <div class="bar">
            <a class="brand" href="' . h(url('home')) . '">
                <img src="assets/ibex-logo.png" alt="IBEX" class="logo" />
                <span class="brand-text">Overtime Portal</span>
            </a>
            ' . ($user ? '<nav class="nav">' . $nav . '</nav>' : '') . '
            ' . ($user ? '<div class="signout-wrap">' . $who . '</div>' : '') . '
        </div>
    </header>
    <main class="wrap">
' . $body . '
    </main>
</body>
</html>';
}

function render_error_page(string $title, string $message): void
{
    render_page($title, '<h2 class="page-title">' . h($title) . '</h2><div class="card"><p>' . h($message) . '</p></div>');
}
