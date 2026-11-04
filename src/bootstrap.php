<?php
declare(strict_types=1);

/* Shared helpers: settings, database, login, security, and overtime rules. */

$GLOBALS['config'] = require dirname(__DIR__) . '/config.php';
date_default_timezone_set($GLOBALS['config']['timezone']);

function cfg(string $key): mixed
{
    return $GLOBALS['config'][$key] ?? null;
}

/* ---------- database ---------- */

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $path = cfg('db_path');
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0750, true);
        }
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec(file_get_contents(dirname(__DIR__) . '/data/schema.sql'));
        // Databases created before these columns existed get them added once
        $cols = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(), 'name');
        foreach (['location', 'designation', 'entity'] as $col) {
            if (!in_array($col, $cols, true)) {
                $pdo->exec("ALTER TABLE users ADD COLUMN $col TEXT NOT NULL DEFAULT ''");
            }
        }
    }
    return $pdo;
}

function all_users(): array
{
    return db()->query("SELECT * FROM users
        ORDER BY CASE role WHEN 'Employee' THEN 0 WHEN 'Manager' THEN 1 ELSE 2 END, full_name")->fetchAll();
}

function user_by_id(?string $id): ?array
{
    if (!$id) return null;
    $s = db()->prepare('SELECT * FROM users WHERE id = ?');
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

function user_by_email(string $email): ?array
{
    $s = db()->prepare('SELECT * FROM users WHERE email = ? COLLATE NOCASE');
    $s->execute([trim($email)]);
    return $s->fetch() ?: null;
}

function all_requests(): array
{
    return db()->query('SELECT * FROM overtime_requests ORDER BY work_date DESC, submitted_at_utc DESC')->fetchAll();
}

function user_count(): int
{
    return (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
}

/* ---------- passwords ---------- */

/**
 * Accepts two formats:
 *  - PHP hashes (used for every new password)
 *  - the .NET format "iterations.salt.key" from the previous portal, so existing passwords still work
 */
function verify_password(string $password, string $stored): bool
{
    if (str_starts_with($stored, '$')) {
        return password_verify($password, $stored);
    }
    $parts = explode('.', $stored);
    if (count($parts) !== 3 || !ctype_digit($parts[0])) {
        return false;
    }
    $salt = base64_decode($parts[1], true);
    $expected = base64_decode($parts[2], true);
    if ($salt === false || $expected === false || $expected === '') {
        return false;
    }
    $actual = hash_pbkdf2('sha256', $password, $salt, (int)$parts[0], strlen($expected), true);
    return hash_equals($expected, $actual);
}

function hash_password(string $password): string
{
    return password_hash($password, PASSWORD_DEFAULT);
}

/* ---------- session, login, security ---------- */

function start_session(): void
{
    session_name('ibex_auth');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => (bool)cfg('secure_cookies'),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    ini_set('session.gc_maxlifetime', (string)(8 * 3600));
    session_start();
    // Sign out after 30 minutes without activity
    if (isset($_SESSION['last_seen']) && time() - (int)$_SESSION['last_seen'] > 1800) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['last_seen'] = time();
}

function current_user(): ?array
{
    $id = $_SESSION['uid'] ?? null;
    if (!$id) return null;
    $u = user_by_id($id);
    if (!$u || !$u['is_active']) {
        unset($_SESSION['uid']);
        return null;
    }
    return $u;
}

function home_for_role(string $role): string
{
    return match ($role) {
        'Admin' => url('admin'),
        'Manager' => url('approvals'),
        default => url('home'),
    };
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function check_csrf(): void
{
    $sent = (string)($_POST['_csrf'] ?? '');
    if (!hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        exit('Your session has expired. Go back, refresh the page, and try again.');
    }
}

function request_method(): string
{
    return $_SERVER['REQUEST_METHOD'] ?? 'GET';
}

/* ---------- flash messages and redirects ---------- */

function flash(string $type, string $text): void
{
    $_SESSION['flash'] = ['type' => $type, 'text' => $text];
}

function take_flash(): ?array
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function redirect(string $location): never
{
    header('Location: ' . $location);
    exit;
}

function url(string $page, array $params = []): string
{
    return '?' . http_build_query(array_merge(['page' => $page], $params));
}

/* ---------- output helpers ---------- */

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function uuid(): string
{
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

function fmt_date(string $iso): string
{
    return date('d M Y', strtotime($iso));
}

function fmt_hours(float|string $n): string
{
    return number_format((float)$n, 2, '.', '');
}

/* ---------- overtime rules ---------- */

function minutes_of(string $hhmm): int
{
    [$h, $m] = array_map('intval', explode(':', substr($hhmm, 0, 5)));
    return $h * 60 + $m;
}

/** Hours between two times. An end earlier than the start is an overnight shift. */
function hours_between(string $start, string $end): float
{
    $minutes = minutes_of($end) - minutes_of($start);
    if ($minutes < 0) $minutes += 1440;
    return round($minutes / 60, 2);
}

/** Same month and year / custom range rules as the .NET portal. */
function read_filter(array $src): array
{
    $month = (int)($src['month'] ?? 0);
    $year = (int)($src['year'] ?? 0);
    return [
        'employee' => preg_match('/^[0-9a-f-]{36}$/i', (string)($src['employee'] ?? '')) ? (string)$src['employee'] : null,
        'month' => ($month >= 1 && $month <= 12) ? $month : null,
        'year' => ($year >= 2000 && $year <= 2100) ? $year : null,
        'from' => valid_iso_date($src['from'] ?? null),
        'to' => valid_iso_date($src['to'] ?? null),
    ];
}

function valid_iso_date(mixed $value): ?string
{
    $value = (string)$value;
    $d = DateTime::createFromFormat('!Y-m-d', $value);
    return ($d && $d->format('Y-m-d') === $value) ? $value : null;
}

function filter_active(array $f): bool
{
    return $f['employee'] || $f['month'] || $f['year'] || $f['from'] || $f['to'];
}

function filter_match(array $r, array $f): bool
{
    if ($f['employee'] && $r['employee_user_id'] !== $f['employee']) return false;
    if ($f['from'] || $f['to']) {
        if ($f['from'] && $r['work_date'] < $f['from']) return false;
        if ($f['to'] && $r['work_date'] > $f['to']) return false;
        return true;
    }
    if ($f['year'] && (int)substr($r['work_date'], 0, 4) !== $f['year']) return false;
    if ($f['month'] && (int)substr($r['work_date'], 5, 2) !== $f['month']) return false;
    return true;
}

/** Carries the current search into redirects, using only the known filter keys. */
function filter_query(array $src): array
{
    $out = [];
    foreach (['status', 'q', 'location', 'employee', 'month', 'year', 'from', 'to'] as $key) {
        if (isset($src[$key]) && $src[$key] !== '') $out[$key] = (string)$src[$key];
    }
    return $out;
}
