<?php
declare(strict_types=1);

/*
 * Imports data from the .NET portal's JSON store (App_Data/portal.json) into the PHP database.
 *   php tools/migrate_json.php path/to/portal.json            -> only runs on an empty database
 *   php tools/migrate_json.php path/to/portal.json --replace  -> deletes all current users and records first
 *
 * Existing passwords keep working: the .NET PBKDF2 hashes are kept as they are and
 * verify_password() checks them. Users and requests with the same id are updated, not duplicated.
 * Run this once while the .NET site is stopped, so no new records are written during the copy.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';

$source = $argv[1] ?? '';
if ($source === '' || !is_file($source)) {
    fwrite(STDERR, "Usage: php tools/migrate_json.php path/to/portal.json [--replace]\n");
    exit(1);
}
$replace = in_array('--replace', $argv, true);
if (user_count() > 0 && !$replace) {
    fwrite(STDERR, "The database already has users (for example the demo accounts from seed.php).\n"
        . "Add --replace to clear them and import the real data, or delete data/portal.sqlite first.\n");
    exit(1);
}

$data = json_decode((string)file_get_contents($source), true, 512, JSON_THROW_ON_ERROR);
$users = $data['Users'] ?? [];
$requests = $data['Requests'] ?? [];

$roles = ['Employee', 'Manager', 'Admin'];
$statuses = ['Pending', 'Approved', 'Rejected'];

/** "18:00:00" or "18:00" -> "18:00"; anything else is rejected. */
$clock = function (?string $t): ?string {
    if ($t === null || !preg_match('/^(\d{2}):(\d{2})/', $t, $m)) return null;
    return $m[1] . ':' . $m[2];
};

/** ISO timestamp with fractions and a Z suffix -> "Y-m-d H:i:s" UTC. */
$utc = function (?string $t): ?string {
    if ($t === null || $t === '') return null;
    return (new DateTimeImmutable($t))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
};

$pdo = db();
$pdo->beginTransaction();
if ($replace) {
    $pdo->exec('DELETE FROM overtime_requests');
    $pdo->exec('DELETE FROM users');
}

$userCount = 0;
$userStmt = $pdo->prepare('INSERT INTO users (id, full_name, email, employee_id, department, role, manager_id, password_hash, is_active)
    VALUES (:id, :full_name, :email, :employee_id, :department, :role, :manager_id, :password_hash, :is_active)
    ON CONFLICT(id) DO UPDATE SET full_name = excluded.full_name, email = excluded.email, employee_id = excluded.employee_id,
        department = excluded.department, role = excluded.role, manager_id = excluded.manager_id,
        password_hash = excluded.password_hash, is_active = excluded.is_active');

// Insert managers before employees so manager_id references exist
usort($users, fn($a, $b) => ($a['Role'] === 'Employee') <=> ($b['Role'] === 'Employee'));
foreach ($users as $u) {
    if (!in_array($u['Role'] ?? '', $roles, true)) {
        throw new RuntimeException('Unknown role for ' . ($u['Email'] ?? '?') . ': ' . ($u['Role'] ?? 'missing'));
    }
    $userStmt->execute([
        ':id' => strtolower((string)$u['Id']),
        ':full_name' => (string)$u['FullName'],
        ':email' => trim((string)$u['Email']),
        ':employee_id' => (string)($u['EmployeeId'] ?? ''),
        ':department' => (string)($u['Department'] ?? ''),
        ':role' => $u['Role'],
        ':manager_id' => $u['ManagerId'] ? strtolower((string)$u['ManagerId']) : null,
        ':password_hash' => (string)$u['PasswordHash'],
        ':is_active' => !empty($u['IsActive']) ? 1 : 0,
    ]);
    $userCount++;
}

$requestStmt = $pdo->prepare('INSERT INTO overtime_requests
    (id, employee_user_id, work_date, start_time, end_time, hours, project, reason, status, manager_note,
     submitted_at_utc, decided_at_utc, decided_by_user_id)
    VALUES (:id, :employee_user_id, :work_date, :start_time, :end_time, :hours, :project, :reason, :status, :manager_note,
     :submitted_at_utc, :decided_at_utc, :decided_by_user_id)
    ON CONFLICT(id) DO UPDATE SET status = excluded.status, manager_note = excluded.manager_note,
        decided_at_utc = excluded.decided_at_utc, decided_by_user_id = excluded.decided_by_user_id');

$requestCount = 0;
$skipped = [];
foreach ($requests as $r) {
    $start = $clock($r['StartTime'] ?? null);
    $end = $clock($r['EndTime'] ?? null);
    if (!in_array($r['Status'] ?? '', $statuses, true) || !$start || !$end) {
        $skipped[] = (string)($r['Id'] ?? '?');
        continue;
    }
    $requestStmt->execute([
        ':id' => strtolower((string)$r['Id']),
        ':employee_user_id' => strtolower((string)$r['EmployeeUserId']),
        ':work_date' => substr((string)$r['WorkDate'], 0, 10),
        ':start_time' => $start,
        ':end_time' => $end,
        ':hours' => (float)$r['Hours'],
        ':project' => (string)($r['Project'] ?? ''),
        ':reason' => (string)($r['Reason'] ?? ''),
        ':status' => $r['Status'],
        ':manager_note' => (string)($r['ManagerNote'] ?? ''),
        ':submitted_at_utc' => $utc($r['SubmittedAtUtc'] ?? null) ?? gmdate('Y-m-d H:i:s'),
        ':decided_at_utc' => $utc($r['DecidedAtUtc'] ?? null),
        ':decided_by_user_id' => $r['DecidedByUserId'] ? strtolower((string)$r['DecidedByUserId']) : null,
    ]);
    $requestCount++;
}

$pdo->commit();
echo "Imported $userCount user(s) and $requestCount overtime record(s) into " . cfg('db_path') . "\n";
if ($skipped) {
    echo "Skipped " . count($skipped) . " record(s) with an unknown status or time:\n  " . implode("\n  ", $skipped) . "\n";
}
