<?php
declare(strict_types=1);

/*
 * Creates a fresh database with the demo accounts used in the HTML demo.
 *   php tools/seed.php            -> creates data/portal.sqlite (fails if it already has users)
 *   php tools/seed.php --fresh    -> deletes the existing database first
 * Never run this on a live server with real data; use tools/migrate_json.php for that.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';

$fresh = in_array('--fresh', $argv ?? [], true);
$path = cfg('db_path');

if ($fresh && is_file($path)) {
    unlink($path);
    echo "Removed existing database.\n";
}
if (!$fresh && is_file($path) && user_count() > 0) {
    fwrite(STDERR, "Database already has users. Use --fresh to rebuild it, or migrate_json.php to import real data.\n");
    exit(1);
}

$pdo = db(); // creates the schema
$pdo->beginTransaction();

$now = gmdate('Y-m-d H:i:s');
$accounts = [
    ['admin',     'Administrator',     'admin@ibex.local',    '',        '',           'Admin',    null,      'AdminPass123!'],
    ['waseem',    'Waseem Ahmad',      'waseem@ibex.local',   'EMP-100', 'Operations', 'Manager',  null,      'ManagerPass1'],
    ['ali',       'Ali Raza',          'ali@ibex.local',      'EMP-104', 'Operations', 'Employee', 'waseem',  'EmployeePass1'],
    ['sara',      'Sara Khan',         'sara@ibex.local',     'EMP-107', 'Finance',    'Employee', 'waseem',  'EmployeePass1'],
    ['faizan',    'Muhammad Faizan',   'Faizan@ibex.co',      '',        '',           'Employee', 'waseem',  'FaizanPass1'],
    ['musharraf', 'Musharraf Amir',    'Musharraf@ibex.co',   '',        '',           'Manager',  null,      'MusharrafPass1'],
];

$ids = [];
$insert = $pdo->prepare('INSERT INTO users (id, full_name, email, employee_id, department, role, manager_id, password_hash, is_active)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)');
foreach ($accounts as [$key, $name, $email, $empId, $dept, $role, $managerKey, $password]) {
    $ids[$key] = uuid();
}
foreach ($accounts as [$key, $name, $email, $empId, $dept, $role, $managerKey, $password]) {
    $insert->execute([
        $ids[$key], $name, $email, $empId, $dept, $role,
        $managerKey ? $ids[$managerKey] : null,
        hash_password($password),
    ]);
}

// A few example records so the screens are not empty
$requests = $pdo->prepare('INSERT INTO overtime_requests
    (id, employee_user_id, work_date, start_time, end_time, hours, project, reason, status, manager_note,
     submitted_at_utc, decided_at_utc, decided_by_user_id)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$samples = [
    ['ali',       '2026-10-27', '18:00', '21:30', 3.5, 'Site handover',   'Handover after inspection', 'Approved', ''],
    ['ali',       '2026-10-29', '17:00', '20:00', 3.0, 'Stock count',     'Month-end stock count',     'Pending',  ''],
    ['faizan',    '2026-10-28', '19:00', '23:00', 4.0, 'Line maintenance', 'Breakdown repair',          'Approved', ''],
    ['faizan',    '2026-10-29', '08:00', '11:00', 3.0, 'Audit support',   'Audit document pull',       'Rejected', 'Please attach the work order.'],
    ['sara',      '2026-10-28', '17:30', '20:00', 2.5, 'Payroll run',     'Payroll cut-off',           'Pending',  ''],
];
foreach ($samples as $i => [$who, $date, $start, $end, $hours, $project, $reason, $status, $note]) {
    $decided = $status === 'Pending' ? null : $now;
    $requests->execute([
        uuid(), $ids[$who], $date, $start, $end, $hours, $project, $reason, $status, $note,
        $now, $decided, $decided ? $ids['waseem'] : null,
    ]);
}

$pdo->commit();
echo "Created " . count($accounts) . " demo accounts and " . count($samples) . " sample records in $path\n";
echo "Sign in with admin@ibex.local / AdminPass123!  (change these passwords before real use)\n";
