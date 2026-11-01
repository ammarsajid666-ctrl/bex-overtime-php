<?php
declare(strict_types=1);

/*
 * Creates the GSO operation team as employee accounts, with their shift, site and allocation.
 *   php tools/create_roster_accounts.php <manager-email> <temporary-password>
 * Accounts that already exist are skipped. Employees must change the temporary password.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';

$managerEmail = $argv[1] ?? '';
$password = $argv[2] ?? '';
if ($managerEmail === '' || strlen($password) < 8) {
    fwrite(STDERR, "Usage: php tools/create_roster_accounts.php <manager-email> <temporary-password (8+ characters)>\n");
    exit(1);
}
$manager = user_by_email($managerEmail);
if (!$manager || $manager['role'] !== 'Manager') {
    fwrite(STDERR, "No manager account found for $managerEmail.\n");
    exit(1);
}

// Resource, name, email, shift, site, allocation (from the GSO Q4 2026 operation team schedule)
$team = [
    ['R1',  'Rukne Alam',         'Rukne.Alam@ibex.co',     'Shift A', 'LHR',    'Ops (win) / CH / BHT'],
    ['R2',  'Zahid Khan',         'Zahid.Khan@ibex.co',     'Shift A', 'LHR',    'Ops (Win) / QM / CH / BHT'],
    ['R3',  'Waleed Ahmed',       'Waleed.Ahmed@ibex.co',   'Shift A', 'ISB',    'Ops (Lin) / CH (Lin) / BHT'],
    ['R4',  'Asadullah',          'Asad@ibex.co',           'Shift A', 'LHR',    'Ops (Win) / CH / BHT'],
    ['R5',  'Ammar Sajid',        'Ammar.Sajid@ibex.co',    'Shift A', 'LHR',    'CH / Ops (Win) / BHT-Weekend'],
    ['R6',  'Lennox Tate',        'Lennox.Tate@ibex.co',    'Shift A', 'JAM',    'Ops / CH'],
    ['R7',  'Muhammad Faizan',    'Faizan@ibex.co',         'Shift B', 'KHI',    'Ops (win) / BHT / IM'],
    ['R8',  'Maqsood Ahmad',      'Maqsood.Ahmad@ibex.co',  'Shift B', 'LHR',    'Ops (Hyb) / IM / CM / BHT'],
    ['R9',  'Hitesh Kumar Acharya', 'Hitesh@ibex.co',       'Shift B', 'KHI',    'Ops (win) / QM / BHT'],
    ['R10', 'Jayr',               'Jayr@ibex.co',           'Shift B', 'MANILA', 'Ops (Win)'],
    ['R11', 'Muhammad Ahsan Adil', 'Ahsan.Adil@ibex.co',    'Shift B', 'KHI',    'Ops (Win) / BHT'],
    ['R12', 'Rashid Mumtaz',      'Rashid.Mumtaz@ibex.co',  'Shift B', 'LHR',    'Ops (Win) / BHT-Weekend'],
    ['R13', 'Richard Buenviaje',  'Richard.Buenviaje@ibex.co', 'Shift B', 'MANILA', 'Ops (Win) / BHT-Weekend'],
    ['R14', 'Muhammad Usama',     'Usama@ibex.co',          'Shift C', 'LHR',    'Ops (Lin)'],
    ['R16', 'Ali Arifin',         'Ali.Arifin@ibex.co',     'Shift C', 'LHR',    'Ops (Win) / BHT Weekend'],
];

$pdo = db();
$pdo->beginTransaction();
$created = 0;
$skipped = [];
foreach ($team as [$resource, $name, $email, $shift, $site, $allocation]) {
    $existing = user_by_email($email);
    if ($existing) {
        $id = $existing['id'];
        $skipped[] = "$email (already exists; roster details updated)";
    } else {
        $id = uuid();
        $pdo->prepare('INSERT INTO users (id, full_name, email, employee_id, department, role, manager_id, password_hash, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)')
            ->execute([$id, $name, $email, $resource, 'GSO Operations', 'Employee', $manager['id'], hash_password($password)]);
        $created++;
    }
    $pdo->prepare('INSERT INTO roster_profiles (employee_user_id, shift, site, allocation) VALUES (?, ?, ?, ?)
        ON CONFLICT(employee_user_id) DO UPDATE SET shift = excluded.shift, site = excluded.site, allocation = excluded.allocation')
        ->execute([$id, $shift, $site, $allocation]);
}
$pdo->commit();

echo "Created $created account(s) reporting to {$manager['full_name']}.\n";
foreach ($skipped as $line) echo "Skipped: $line\n";
echo "R15 (Muhammad Ilyas Memon) is not created yet: confirm the email first.\n";
