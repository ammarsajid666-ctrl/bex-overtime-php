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

/** Time dropdown options in 5-minute steps, labelled like "9:00 AM" and submitted as HH:MM. */
/** Reads a time posted by time_field(): the exact clock value if one was typed, else the list value. */
function read_time(string $key): string
{
    $exact = clean($_POST[$key . '_exact'] ?? '', 5);
    if ($exact !== '') return $exact;
    $value = clean($_POST[$key] ?? '', 5);
    return $value === '__clock' ? '' : $value;
}

/**
 * Start or end time: a dropdown in 5-minute steps, plus a "Clock" option that shows a
 * clock input for any exact minute. A time that is not on the 5-minute list opens in clock mode.
 */
function time_field(string $key, string $label, string $selected): string
{
    $clock = $selected !== '' && !(preg_match('/^(\d{2}):(\d{2})$/', $selected, $m) && (int)$m[2] % 5 === 0 && (int)$m[1] < 24);
    $html = '<label for="f-' . $key . '">' . h($label) . '</label>'
        . '<select id="f-' . $key . '" name="' . $key . '"' . ($clock ? ' disabled' : ' required') . '>'
        . '<option value="">Select time</option>'
        . '<option value="__clock"' . ($clock ? ' selected' : '') . '>Clock: type exact time</option>';
    for ($m2 = 0; $m2 < 1440; $m2 += 5) {
        $h = intdiv($m2, 60);
        $value = sprintf('%02d:%02d', $h, $m2 % 60);
        $text = sprintf('%d:%02d %s', $h % 12 === 0 ? 12 : $h % 12, $m2 % 60, $h < 12 ? 'AM' : 'PM');
        $html .= '<option value="' . $value . '"' . (!$clock && $value === $selected ? ' selected' : '') . '>' . $text . '</option>';
    }
    $html .= '</select>';
    $html .= '<div class="clock-row" id="clock-' . $key . '"' . ($clock ? '' : ' hidden') . '>'
        . '<input type="time" id="f-' . $key . '-clock" name="' . $key . '_exact" step="60"'
        . ($clock ? ' value="' . h($selected) . '"' : ' disabled') . ' aria-label="' . h($label) . ' (exact time)" />'
        . '<button type="button" class="btn-ghost time-back" data-time-back="' . $key . '">Use list</button>'
        . '</div>';
    return $html;
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
    <link rel="stylesheet" href="assets/premium.css" />
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
    <footer class="foot">IBEX Overtime Portal · Authorized users only · Secure connection</footer>
</body>
</html>';
}

function render_error_page(string $title, string $message): void
{
    render_page($title, '<h2 class="page-title">' . h($title) . '</h2><div class="card"><p>' . h($message) . '</p></div>');
}
