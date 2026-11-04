<?php
declare(strict_types=1);

/* Page handlers. Each handler runs for GET and POST, checks the role, and renders or redirects. */

function require_role(array $roles): array
{
    $user = current_user();
    if (!$user) redirect(url('login'));
    if (!in_array($user['role'], $roles, true)) redirect(home_for_role($user['role']));
    return $user;
}

function clean(mixed $value, int $max): string
{
    $s = trim((string)($value ?? ''));
    return mb_substr($s, 0, $max);
}

/* ---------- sign in and out ---------- */

function page_login(): void
{
    if (current_user()) redirect(home_for_role(current_user()['role']));
    prune_attempts();

    $error = null;
    $email = '';
    $ip = 'ip:' . client_ip();
    if (request_method() === 'POST') {
        check_csrf();
        $email = clean($_POST['email'] ?? '', 200);
        $password = (string)($_POST['password'] ?? '');
        $userKey = 'user:' . strtolower($email);

        if (throttled($ip, 20, 900) || throttled($userKey, 5, 900)) {
            $error = 'Too many sign-in attempts. Wait 15 minutes and try again.';
        } elseif (looks_automated('login')) {
            record_failure($ip);
            $error = 'Please wait a moment and try again.';
        } else {
            $user = user_by_email($email);
            if (!$user || !$user['is_active'] || !verify_password($password, $user['password_hash'])) {
                record_failure($ip);
                record_failure($userKey);
                $error = 'Email or password is incorrect.';
            } else {
                clear_failures($userKey);
                session_regenerate_id(true);
                $_SESSION['uid'] = $user['id'];
                redirect(home_for_role($user['role']));
            }
        }
    }

    $site = h($_SERVER['HTTP_HOST'] ?? 'this site');
    $body = '<div class="login-wrap">
    <div class="login-intro">
        <span class="eyebrow">Secure staff portal</span>
        <h2 class="page-title">Welcome back</h2>
        <p class="page-sub">Sign in to file overtime and review your team\'s requests.</p>
        <div class="notice">
            <strong>Check the address first.</strong> Sign in only at <strong>' . $site . '</strong>.
            IBEX will never ask for your password by email, chat or phone.
        </div>
    </div>
    <div class="card login">
        <form method="post" action="' . h(url('login')) . '" autocomplete="on">
            <input type="hidden" name="_csrf" value="' . h(csrf_token()) . '" />
            ' . bot_trap_fields('login') . '
            ' . ($error ? '<div class="flash err">' . h($error) . '</div>' : '') . '
            <div class="grid">
                <div class="full">
                    <label for="email">Work email</label>
                    <input id="email" name="email" type="email" autocomplete="username" value="' . h($email) . '" required />
                </div>
                <div class="full">
                    <label for="password">Password</label>
                    <input id="password" name="password" type="password" autocomplete="current-password" required />
                </div>
            </div>
            <div class="actions"><button type="submit" class="btn-primary btn-block">Sign in</button></div>
        </form>
    </div>
    </div>';
    render_page('Sign in', $body);
}

function page_logout(): void
{
    if (request_method() === 'POST') {
        check_csrf();
    }
    $_SESSION = [];
    session_destroy();
    redirect(url('login'));
}

/* ---------- employee: my overtime ---------- */

function page_home(): void
{
    $me = require_role(['Employee', 'Manager']);
    $mine = array_values(array_filter(all_requests(), fn($r) => $r['employee_user_id'] === $me['id']));

    $thisMonth = date('Y-m');
    $approvedMonth = 0.0;
    $pending = 0;
    foreach ($mine as $r) {
        if ($r['status'] === 'Approved' && str_starts_with($r['work_date'], $thisMonth)) $approvedMonth += (float)$r['hours'];
        if ($r['status'] === 'Pending') $pending++;
    }

    $f = read_filter($_GET);
    $shown = array_values(array_filter($mine, fn($r) => filter_match($r, $f)));
    $shownHours = array_sum(array_map(fn($r) => (float)$r['hours'], $shown));
    $years = years_in($mine);

    $rows = '';
    foreach ($shown as $r) {
        $rows .= '<tr>
            <td class="title">' . h(fmt_date($r['work_date'])) . '</td>
            <td data-label="Time">' . h(substr($r['start_time'], 0, 5)) . ' – ' . h(substr($r['end_time'], 0, 5)) . '</td>
            <td data-label="Hours">' . fmt_hours($r['hours']) . '</td>
            <td data-label="Project">' . h($r['project']) . '</td>
            <td data-label="Status"><div class="status-actions"><span class="badge ' . h(strtolower($r['status'])) . '">' . h($r['status']) . '</span>'
                . ($r['status'] === 'Pending' ? '<a class="btn-small" href="' . h(url('request', ['id' => $r['id']])) . '">Edit</a>' : '') . '</div></td>
            <td data-label="Note" class="block muted">' . h($r['manager_note']) . '</td>
        </tr>';
    }

    $list = $shown
        ? '<div class="table-wrap"><table class="stack-table">
            <thead><tr><th>Date</th><th>Time</th><th>Hours</th><th>Project</th><th>Status</th><th>Manager note</th></tr></thead>
            <tbody>' . $rows . '</tbody></table></div>'
        : '<div class="empty">' . (filter_active($f) ? 'No submissions match these filters.' : 'No submissions yet.') . '</div>';

    $body = flash_html() . '
    <h2 class="page-title">My overtime</h2>
    <p class="page-sub">Track your submitted hours and their approval status.</p>
    <div class="summary">
        <div class="stat gold"><div class="label">Awaiting approval</div><div class="n">' . $pending . '</div></div>
        <div class="stat"><div class="label">Approved this month</div><div class="n">' . fmt_hours($approvedMonth) . ' <span class="unit">hrs</span></div></div>
        <div class="stat"><div class="label">Total submissions</div><div class="n">' . count($mine) . '</div></div>
    </div>
    <div class="card">
        <div class="card-head"><h2>Submissions</h2></div>
        <form method="get" class="filter-form">
            <input type="hidden" name="page" value="home" />
            <div class="field"><label for="f-month">Month</label><select id="f-month" name="month">' . month_options($f['month']) . '</select></div>
            <div class="field"><label for="f-year">Year</label><select id="f-year" name="year">' . year_options($years, $f['year']) . '</select></div>
            <div class="field"><label for="f-from">From date</label><input id="f-from" type="date" name="from" value="' . h($f['from']) . '" /></div>
            <div class="field"><label for="f-to">To date</label><input id="f-to" type="date" name="to" value="' . h($f['to']) . '" /></div>
            <div class="filter-actions">
                <button type="submit" class="btn-primary">Search</button>
                <a class="btn-small" href="' . h(url('home')) . '">Clear</a>
            </div>
        </form>
        <p class="filter-note">Custom dates override month and year. Showing ' . count($shown) . ' record(s), ' . fmt_hours($shownHours) . ' hrs.</p>
        ' . $list . '
    </div>';
    render_page('My overtime', $body);
}

function page_file(): void
{
    $me = require_role(['Employee', 'Manager']);
    $errors = [];
    $v = ['date' => date('Y-m-d'), 'start' => '', 'end' => '', 'project' => '', 'reason' => ''];

    if (request_method() === 'POST') {
        check_csrf();
        $v = [
            'date' => clean($_POST['date'] ?? '', 10),
            'start' => read_time('start'),
            'end' => read_time('end'),
            'project' => clean($_POST['project'] ?? '', 120),
            'reason' => clean($_POST['reason'] ?? '', 1000),
        ];
        $maxHours = (float)cfg('max_entry_hours');

        if (looks_automated('file')) $errors['form'] = 'That was submitted too quickly. Check the details and submit again.';
        if (!valid_iso_date($v['date'])) $errors['date'] = 'Choose the date you worked.';
        elseif ($v['date'] > date('Y-m-d')) $errors['date'] = 'The date worked cannot be in the future.';

        $validTimes = preg_match('/^\d{2}:\d{2}$/', $v['start']) && preg_match('/^\d{2}:\d{2}$/', $v['end']);
        if (!preg_match('/^\d{2}:\d{2}$/', $v['start'])) $errors['start'] = 'Enter your start time.';
        if (!preg_match('/^\d{2}:\d{2}$/', $v['end'])) $errors['end'] = 'Enter your end time.';
        if ($validTimes && $v['start'] === $v['end']) {
            $errors['end'] = 'End time must differ from start time.';
        } elseif ($validTimes && hours_between($v['start'], $v['end']) > $maxHours) {
            $errors['end'] = 'Entries over ' . (int)$maxHours . ' hours need HR approval. Split the entry or contact HR.';
        }

        if ($v['reason'] === '') $errors['reason'] = 'Describe why the overtime was needed.';
        if (!$me['manager_id']) $errors['form'] = 'No manager is assigned to your account. Ask an administrator to assign one.';

        if (!$errors) {
            $hours = hours_between($v['start'], $v['end']);
            save_requests([[
                'id' => uuid(),
                'employee_user_id' => $me['id'],
                'work_date' => $v['date'],
                'start_time' => $v['start'],
                'end_time' => $v['end'],
                'hours' => $hours,
                'project' => $v['project'],
                'reason' => $v['reason'],
                'status' => 'Pending',
                'manager_note' => '',
                'submitted_at_utc' => gmdate('Y-m-d H:i:s'),
                'decided_at_utc' => null,
                'decided_by_user_id' => null,
            ]]);
            flash('ok', 'Submitted ' . fmt_hours($hours) . ' hours for approval.');
            redirect(url('home'));
        }
    }

    $hoursText = ($v['start'] !== '' && $v['end'] !== '' && $v['start'] !== $v['end'])
        ? fmt_hours(hours_between($v['start'], $v['end'])) : '0.00';
    $manager = user_by_id($me['manager_id']);
    $err = fn(string $k): string => isset($errors[$k]) ? '<span class="field-error">' . h($errors[$k]) . '</span>' : '';

    $body = flash_html() . '
    <h2 class="page-title">File overtime</h2>
    <p class="page-sub">Enter the hours you worked. Your manager' . ($manager ? ' (' . h($manager['full_name']) . ')' : '') . ' will review them.</p>
    <div class="card">
        <form method="post" action="' . h(url('file')) . '">
            ' . bot_trap_fields('file') . '
            <input type="hidden" name="_csrf" value="' . h(csrf_token()) . '" />
            ' . (isset($errors['form']) ? '<div class="flash err">' . h($errors['form']) . '</div>' : '') . '
            <div class="grid">
                <div><label for="f-date">Date worked</label><input id="f-date" name="date" type="date" max="' . date('Y-m-d') . '" value="' . h($v['date']) . '" required />' . $err('date') . '</div>
                <div>' . time_field('start', 'Start time', $v['start']) . $err('start') . '</div>
                <div>' . time_field('end', 'End time', $v['end']) . $err('end') . '</div>
                <div><label>Hours</label><div class="hours-out"><span id="hours-out" data-max="' . (int)cfg('max_entry_hours') . '">' . $hoursText . '</span> <span>hrs</span></div><div id="hours-warn" class="field-error"></div></div>
                <div class="full"><label for="f-project">Project / task</label><input id="f-project" name="project" maxlength="120" value="' . h($v['project']) . '" /></div>
                <div class="full"><label for="f-reason">Reason</label><textarea id="f-reason" name="reason" maxlength="1000" required>' . h($v['reason']) . '</textarea>' . $err('reason') . '</div>
            </div>
            <div class="actions">
                <button type="submit" class="btn-primary">Submit for approval</button>
                <a class="btn-ghost" href="' . h(url('home')) . '">Cancel</a>
            </div>
        </form>
    </div>
    <script src="assets/overtime-hours.js" defer></script>';
    render_page('File overtime', $body);
}

/* ---------- manager: approvals ---------- */

/** Requests from the signed-in manager's team, with the current filter applied. */
function team_requests(array $me): array
{
    $team = [];
    foreach (all_users() as $u) {
        if ($u['manager_id'] === $me['id']) $team[$u['id']] = $u;
    }
    $requests = array_values(array_filter(all_requests(), fn($r) => isset($team[$r['employee_user_id']])));
    return [$team, $requests];
}

/** True when the employee's name, email or Employee ID contains the search text (empty search matches everyone). */
function person_match(array $e, string $q): bool
{
    if ($q === '') return true;
    $needle = mb_strtolower($q);
    foreach ([$e['full_name'], $e['email'], $e['employee_id']] as $field) {
        if (str_contains(mb_strtolower((string)$field), $needle)) return true;
    }
    return false;
}

function page_approvals(): void
{
    $me = require_role(['Manager']);
    [$team, $all] = team_requests($me);

    $status = strtolower(clean($_GET['status'] ?? 'pending', 10));
    if (!in_array($status, ['pending', 'approved', 'rejected', 'all'], true)) $status = 'pending';
    $f = read_filter($_GET);

    $count = fn(string $s) => count(array_filter($all, fn($r) => $r['status'] === $s));
    $approvedHours = array_sum(array_map(fn($r) => $r['status'] === 'Approved' ? (float)$r['hours'] : 0, $all));

    $q = clean($_GET['q'] ?? '', 120);
    $loc = clean($_GET['location'] ?? '', 120);
    $items = array_values(array_filter($all, fn($r) => ($status === 'all' || strtolower($r['status']) === $status) && filter_match($r, $f) && person_match($team[$r['employee_user_id']], $q) && ($loc === '' || $team[$r['employee_user_id']]['location'] === $loc)));
    usort($items, fn($a, $b) => strcmp($a['submitted_at_utc'], $b['submitted_at_utc']));
    $shownHours = array_sum(array_map(fn($r) => (float)$r['hours'], $items));

    $sortedTeam = $team;
    uasort($sortedTeam, fn($a, $b) => strcmp($a['full_name'], $b['full_name']));
    $statusOptions = '';
    foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $value => $label) {
        $statusOptions .= '<option value="' . $value . '"' . ($status === $value ? ' selected' : '') . '>' . $label . '</option>';
    }
    $locationOptions = '<option value="">All locations</option>';
    $locations = array_unique(array_filter(array_column($team, 'location'), fn($l) => $l !== ''));
    sort($locations);
    foreach ($locations as $l) {
        $locationOptions .= '<option value="' . h($l) . '"' . ($loc === $l ? ' selected' : '') . '>' . h($l) . '</option>';
    }
    $employeeOptions = '<option value="">All employees</option>';
    foreach ($sortedTeam as $u) {
        $employeeOptions .= '<option value="' . h($u['id']) . '"' . ($f['employee'] === $u['id'] ? ' selected' : '') . '>' . h($u['full_name']) . '</option>';
    }
    $returnQuery = http_build_query(filter_query(array_merge($_GET, ['status' => $status])));

    $rows = '';
    foreach ($items as $r) {
        $e = $team[$r['employee_user_id']];
        $decision = $r['status'] === 'Pending'
            ? '<div class="decide">
                    <form method="post" action="' . h(url('decide')) . '">
                        <input type="hidden" name="_csrf" value="' . h(csrf_token()) . '" />
                        <input type="hidden" name="id" value="' . h($r['id']) . '" />
                        <input type="hidden" name="return" value="' . h($returnQuery) . '" />
                        <input name="note" placeholder="Note (required to reject)" maxlength="500" />
                        <div class="row-actions">
                            <button type="submit" name="decision" value="approve" class="btn-approve">Approve</button>
                            <button type="submit" name="decision" value="reject" class="btn-reject">Reject</button>
                        </div>
                    </form>
                </div>'
            : '<span class="muted">' . h(fmt_date(substr((string)$r['decided_at_utc'], 0, 10))) . '</span>';
        $rows .= '<tr>
            <td class="title"><div class="name">' . h($e['full_name']) . '</div><div class="muted">' . h($e['employee_id']) . ' · ' . h($e['department']) . ($e['location'] !== '' ? ' · ' . h($e['location']) : '') . '</div></td>
            <td data-label="Date">' . h(fmt_date($r['work_date'])) . '</td>
            <td data-label="Time">' . h(substr($r['start_time'], 0, 5)) . ' – ' . h(substr($r['end_time'], 0, 5)) . '</td>
            <td data-label="Hours">' . fmt_hours($r['hours']) . '</td>
            <td data-label="Reason" class="block">' . h($r['reason']) . ($r['project'] !== '' ? '<div class="muted">' . h($r['project']) . '</div>' : '') . '</td>
            <td data-label="Status"><span class="badge ' . h(strtolower($r['status'])) . '">' . h($r['status']) . '</span>' . ($r['manager_note'] !== '' ? '<div class="muted">' . h($r['manager_note']) . '</div>' : '') . '</td>
            <td class="actions-cell">' . $decision . '</td>
        </tr>';
    }

    $list = $items
        ? '<div class="table-wrap"><table class="stack-table">
            <thead><tr><th>Employee</th><th>Date</th><th>Time</th><th>Hours</th><th>Reason / project</th><th>Status</th><th>Decision</th></tr></thead>
            <tbody>' . $rows . '</tbody></table></div>'
        : '<div class="empty">Nothing to review.</div>';

    $exportHref = url('export', filter_query(array_merge($_GET, ['status' => $status])));

    $body = flash_html() . '
    <h2 class="page-title">Approvals</h2>
    <p class="page-sub">Review overtime submitted by your team. Only your own team\'s requests appear here.</p>
    <div class="summary">
        <a class="stat gold" href="' . h(url('approvals', ['status' => 'pending'])) . '"><div class="label">Pending</div><div class="n">' . $count('Pending') . '</div></a>
        <a class="stat" href="' . h(url('approvals', ['status' => 'approved'])) . '"><div class="label">Approved</div><div class="n">' . $count('Approved') . '</div></a>
        <a class="stat" href="' . h(url('approvals', ['status' => 'rejected'])) . '"><div class="label">Rejected</div><div class="n">' . $count('Rejected') . '</div></a>
        <div class="stat"><div class="label">Approved hours</div><div class="n">' . fmt_hours($approvedHours) . '</div></div>
    </div>
    <div class="card">
        <div class="card-head"><h2>Requests</h2><span class="result-count">' . count($items) . ' shown · ' . fmt_hours($shownHours) . ' hrs</span></div>
        <form method="get" class="filter-form">
            <input type="hidden" name="page" value="approvals" />
            <div class="field field-wide"><label for="f-q">Name, email or Employee ID</label><input id="f-q" type="search" name="q" value="' . h($q) . '" placeholder="e.g. Faizan, EMP-104" /></div>
            <div class="field"><label for="f-status">Status</label><select id="f-status" name="status">' . $statusOptions . '</select></div>
            <div class="field"><label for="f-emp">Employee</label><select id="f-emp" name="employee">' . $employeeOptions . '</select></div>
            <div class="field"><label for="f-loc">Location</label><select id="f-loc" name="location">' . $locationOptions . '</select></div>
            <div class="field"><label for="f-month">Month</label><select id="f-month" name="month">' . month_options($f['month']) . '</select></div>
            <div class="field"><label for="f-year">Year</label><select id="f-year" name="year">' . year_options(years_in($all), $f['year']) . '</select></div>
            <div class="field"><label for="f-from">From date</label><input id="f-from" type="date" name="from" value="' . h($f['from']) . '" /></div>
            <div class="field"><label for="f-to">To date</label><input id="f-to" type="date" name="to" value="' . h($f['to']) . '" /></div>
            <div class="filter-actions">
                <button type="submit" class="btn-primary">Search</button>
                <a class="btn-small" href="' . h(url('approvals')) . '">Clear</a>
                <a class="btn-ghost" href="' . h($exportHref) . '">Export CSV</a>
            </div>
        </form>
        <p class="filter-note">Custom dates override month and year. Export CSV includes the same filters.</p>
        ' . $list . '
    </div>';
    render_page('Approvals', $body);
}

function page_decide(): void
{
    $me = require_role(['Manager']);
    if (request_method() !== 'POST') redirect(url('approvals'));
    check_csrf();

    parse_str((string)($_POST['return'] ?? ''), $back);
    $backUrl = url('approvals', filter_query($back));

    $request = null;
    foreach (all_requests() as $r) {
        if ($r['id'] === ($_POST['id'] ?? '')) { $request = $r; break; }
    }
    $employee = $request ? user_by_id($request['employee_user_id']) : null;
    // Managers can only decide requests from their own team
    if (!$request || !$employee || $employee['manager_id'] !== $me['id']) {
        http_response_code(404);
        exit('Request not found.');
    }

    if ($request['status'] !== 'Pending') {
        flash('err', 'This request has already been decided.');
        redirect($backUrl);
    }

    $reject = strtolower((string)($_POST['decision'] ?? '')) === 'reject';
    $note = clean($_POST['note'] ?? '', 500);
    if ($reject && $note === '') {
        flash('err', 'Add a reason before rejecting, so the employee knows what to fix.');
        redirect($backUrl);
    }

    $s = db()->prepare('UPDATE overtime_requests SET status = ?, manager_note = ?, decided_at_utc = ?, decided_by_user_id = ? WHERE id = ?');
    $s->execute([$reject ? 'Rejected' : 'Approved', $note, gmdate('Y-m-d H:i:s'), $me['id'], $request['id']]);

    flash('ok', ($reject ? 'Rejected' : 'Approved') . ' ' . fmt_hours($request['hours']) . ' hours for ' . $employee['full_name'] . '.');
    redirect($backUrl);
}

function page_export(): void
{
    $me = require_role(['Manager']);
    [$team, $all] = team_requests($me);

    $status = strtolower(clean($_GET['status'] ?? 'pending', 10));
    if (!in_array($status, ['pending', 'approved', 'rejected', 'all'], true)) $status = 'pending';
    $f = read_filter($_GET);

    $q = clean($_GET['q'] ?? '', 120);
    $loc = clean($_GET['location'] ?? '', 120);
    $items = array_values(array_filter($all, fn($r) => ($status === 'all' || strtolower($r['status']) === $status) && filter_match($r, $f) && person_match($team[$r['employee_user_id']], $q) && ($loc === '' || $team[$r['employee_user_id']]['location'] === $loc)));
    usort($items, fn($a, $b) => strcmp($a['submitted_at_utc'], $b['submitted_at_utc']));

    // Summary for payroll: approved hours per employee per month, in the same layout as the OT summary sheet.
    $approved = array_values(array_filter($items, fn($r) => strtolower($r['status']) === 'approved'));
    $groups = [];
    foreach ($approved as $r) {
        $e = $team[$r['employee_user_id']];
        $key = $e['id'] . '|' . substr($r['work_date'], 0, 7);
        if (!isset($groups[$key])) {
            $groups[$key] = ['employee' => $e, 'month' => substr($r['work_date'], 0, 7), 'hours' => 0.0, 'projects' => []];
        }
        $groups[$key]['hours'] += (float)$r['hours'];
        $project = trim((string)$r['project']);
        if ($project !== '' && !in_array($project, $groups[$key]['projects'], true)) $groups[$key]['projects'][] = $project;
    }
    uasort($groups, fn($a, $b) => [$a['month'], strtolower($a['employee']['full_name'])] <=> [$b['month'], strtolower($b['employee']['full_name'])]);

    $months = array_unique(array_column($groups, 'month'));
    sort($months);
    if (count($months) === 1) {
        $title = 'Summary of OT for the month of ' . date('F Y', strtotime($months[0] . '-01'));
    } elseif ($months) {
        $title = 'Summary of OT for the months of ' . date('F Y', strtotime($months[0] . '-01')) . ' to ' . date('F Y', strtotime(end($months) . '-01'));
    } else {
        $title = 'Summary of OT';
    }

    $lines = [csv_plain($title) . str_repeat(',', 7), 'Employee ID,Name,Designation,Project / Task Description,Entity,Site,OT Month,OT Hours'];
    foreach ($groups as $g) {
        $e = $g['employee'];
        $parts = preg_split('/\s+/', trim((string)$e['full_name']));
        $last = array_pop($parts);
        $name = $parts ? $last . ', ' . implode(' ', $parts) : $last;
        $lines[] = implode(',', [
            csv_plain($e['employee_id']), csv_plain($name), csv_plain($e['designation']),
            csv_plain(implode(', ', $g['projects'])), csv_plain('IBEX'), csv_plain($e['location']),
            csv_plain(date('F', strtotime($g['month'] . '-01'))), csv_plain(rtrim(rtrim(number_format($g['hours'], 2, '.', ''), '0'), '.')),
        ]);
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ot-summary-' . ($months ? implode('-', array_unique([$months[0], end($months)])) : date('Ym')) . '.csv"');
    echo "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n\r\n";
    exit;
}

/** CSV field that is quoted only when it needs quoting, as in the OT summary sheet. */
function csv_plain(mixed $value): string
{
    $text = (string)$value;
    return preg_match('/[",\r\n]/', $text) ? '"' . str_replace('"', '""', $text) . '"' : $text;
}

/* ---------- administrator ---------- */

/** Shared checks for adding and editing users. Returns [errors, cleaned values]. */
function validate_user(array $in, ?array $existing, array $me, bool $isNew): array
{
    $v = [
        'name' => clean($in['name'] ?? '', 120),
        'email' => clean($in['email'] ?? '', 200),
        'password' => (string)($in['password'] ?? ''),
        'role' => (string)($in['role'] ?? 'Employee'),
        'emp_id' => clean($in['emp_id'] ?? '', 40),
        'dept' => clean($in['dept'] ?? '', 120),
        'location' => clean($in['location'] ?? '', 120),
        'designation' => clean($in['designation'] ?? '', 120),
        'manager_id' => clean($in['manager_id'] ?? '', 36),
    ];
    $errors = [];
    $users = all_users();
    $existingId = $existing['id'] ?? null;

    if ($v['name'] === '') $errors['name'] = 'Enter the full name.';

    if ($v['email'] === '') {
        $errors['email'] = 'Enter a work email.';
    } elseif (!filter_var($v['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
    } else {
        foreach ($users as $u) {
            if (strcasecmp($u['email'], $v['email']) === 0 && $u['id'] !== $existingId) {
                $errors['email'] = 'That email is already registered.';
            }
        }
    }

    if ($isNew || $v['password'] !== '') {
        if (strlen($v['password']) < 8) $errors['password'] = 'Use at least 8 characters.';
    }

    if (!in_array($v['role'], ['Employee', 'Manager', 'Admin'], true)) {
        $errors['role'] = 'Choose a valid role.';
    }

    if ($v['emp_id'] !== '') {
        foreach ($users as $u) {
            if ($u['employee_id'] !== '' && strcasecmp($u['employee_id'], $v['emp_id']) === 0 && $u['id'] !== $existingId) {
                $errors['emp_id'] = 'That employee ID is already in use.';
            }
        }
    }

    if ($v['role'] === 'Employee') {
        if ($v['manager_id'] === '') {
            $errors['manager_id'] = 'Employees need a manager to approve their overtime.';
        } else {
            $manager = user_by_id($v['manager_id']);
            if (!$manager || $manager['role'] !== 'Manager' || !$manager['is_active'] || $manager['id'] === $existingId) {
                $errors['manager_id'] = 'Choose an active manager.';
            }
        }
    }

    if ($existing) {
        if ($existing['id'] === $me['id'] && $v['role'] !== 'Admin') {
            $errors['role'] = 'You cannot remove your own administrator role.';
        }
        $activeAdmins = count(array_filter($users, fn($u) => $u['role'] === 'Admin' && $u['is_active']));
        if ($existing['role'] === 'Admin' && $v['role'] !== 'Admin' && $existing['is_active'] && $activeAdmins <= 1) {
            $errors['role'] = 'At least one active administrator is required.';
        }
        if ($existing['role'] === 'Manager' && $v['role'] !== 'Manager') {
            foreach ($users as $u) {
                if ($u['manager_id'] === $existing['id'] && $u['is_active']) {
                    $errors['role'] = $existing['full_name'] . ' still has active employees. Reassign them first.';
                    break;
                }
            }
        }
    }

    return [$errors, $v];
}

function user_form(array $v, array $errors, ?array $existing, array $me): string
{
    $err = fn(string $k): string => isset($errors[$k]) ? '<span class="field-error">' . h($errors[$k]) . '</span>' : '';
    $isSelf = $existing && $existing['id'] === $me['id'];

    $roleField = $isSelf
        ? '<input type="hidden" name="role" value="Admin" /><div class="muted">Administrator (you cannot change your own role)</div>'
        : '<select id="role" name="role">' . implode('', array_map(
            fn($r) => '<option value="' . $r . '"' . ($v['role'] === $r ? ' selected' : '') . '>' . ($r === 'Admin' ? 'Administrator' : $r) . '</option>',
            ['Employee', 'Manager', 'Admin'])) . '</select>';

    $managers = '<option value="">— Select manager —</option>';
    foreach (all_users() as $m) {
        if ($m['role'] === 'Manager' && $m['is_active'] && (!$existing || $m['id'] !== $existing['id'])) {
            $managers .= '<option value="' . h($m['id']) . '"' . ($v['manager_id'] === $m['id'] ? ' selected' : '') . '>' . h($m['full_name']) . '</option>';
        }
    }

    return '
        <div class="grid">
            <div class="full"><label for="name">Full name</label><input id="name" name="name" maxlength="120" value="' . h($v['name']) . '" required />' . $err('name') . '</div>
            <div><label for="email">Work email</label><input id="email" name="email" type="email" value="' . h($v['email']) . '" required />' . $err('email') . '</div>
            <div><label for="password">' . ($existing ? 'New password' : 'Temporary password') . '</label>
                <input id="password" name="password" type="password" autocomplete="new-password" ' . ($existing ? 'placeholder="Leave blank to keep"' : 'required') . ' />' . $err('password') . '</div>
            <div><label for="role">Role</label>' . $roleField . $err('role') . '</div>
            <div><label for="emp_id">Employee ID</label><input id="emp_id" name="emp_id" maxlength="40" value="' . h($v['emp_id']) . '" />' . $err('emp_id') . '</div>
            <div><label for="dept">Department</label><input id="dept" name="dept" maxlength="120" value="' . h($v['dept']) . '" /></div>
            <div><label for="designation">Designation</label><input id="designation" name="designation" maxlength="120" value="' . h($v['designation']) . '" placeholder="e.g. Senior Server Administrator" /></div>
            <div><label for="location">Location</label><input id="location" name="location" maxlength="120" value="' . h($v['location']) . '" placeholder="e.g. Karachi, Lahore" /></div>
            <div><label for="manager_id">Reports to</label><select id="manager_id" name="manager_id">' . $managers . '</select>' . $err('manager_id') . '
                <span class="muted">Required for employees. Managers and administrators leave this blank.</span></div>
        </div>';
}

function page_admin(): void
{
    $me = require_role(['Admin']);
    $errors = [];
    $v = ['name' => '', 'email' => '', 'password' => '', 'role' => 'Employee', 'emp_id' => '', 'dept' => '', 'manager_id' => ''];

    if (request_method() === 'POST') {
        check_csrf();
        [$errors, $v] = validate_user($_POST, null, $me, true);
        if (!$errors) {
            $s = db()->prepare('INSERT INTO users (id, full_name, email, employee_id, department, designation, location, role, manager_id, password_hash, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)');
            $s->execute([
                uuid(), $v['name'], $v['email'], $v['emp_id'], $v['dept'], $v['designation'], $v['location'], $v['role'],
                $v['role'] === 'Employee' ? $v['manager_id'] : null,
                hash_password($v['password']),
            ]);
            flash('ok', 'Added ' . $v['name'] . ' as ' . ($v['role'] === 'Admin' ? 'Administrator' : $v['role']) . '.');
            redirect(url('admin'));
        }
    }

    $rows = '';
    $users = all_users();
    foreach ($users as $u) {
        $mgr = user_by_id($u['manager_id']);
        $rows .= '<tr>
            <td class="title"><div class="name">' . h($u['full_name']) . '</div></td>
            <td data-label="Email">' . h($u['email']) . '</td>
            <td data-label="Role">' . h($u['role']) . '</td>
            <td data-label="Employee ID">' . h($u['employee_id']) . '</td>
            <td data-label="Department">' . h($u['department']) . ($u['location'] !== '' ? '<div class="muted">' . h($u['location']) . '</div>' : '') . '</td>
            <td data-label="Reports to">' . h($mgr['full_name'] ?? '—') . '</td>
            <td data-label="Status"><span class="badge ' . ($u['is_active'] ? 'approved' : 'inactive') . '">' . ($u['is_active'] ? 'Active' : 'Deactivated') . '</span></td>
            <td class="actions-cell">
                <a class="btn-small" href="' . h(url('edit', ['id' => $u['id']])) . '">Edit</a>
                <form method="post" action="' . h(url('toggle')) . '" style="display:inline">
                    <input type="hidden" name="_csrf" value="' . h(csrf_token()) . '" />
                    <input type="hidden" name="id" value="' . h($u['id']) . '" />
                    <button type="submit" class="btn-small ' . ($u['is_active'] ? 'btn-danger' : '') . '">' . ($u['is_active'] ? 'Deactivate' : 'Reactivate') . '</button>
                </form>
            </td>
        </tr>';
    }

    $body = flash_html() . '
    <h2 class="page-title">Administration</h2>
    <p class="page-sub">Register managers, employees, and administrators, and control who can sign in.</p>
    <div class="actions" style="margin-top:0"><a class="btn-ghost" href="' . h(url('import')) . '">Import previous overtime (Excel)</a></div>
    <div class="card">
        <div class="card-head"><h2>Add user</h2></div>
        <form method="post" action="' . h(url('admin')) . '" autocomplete="off">
            <input type="hidden" name="_csrf" value="' . h(csrf_token()) . '" />
            ' . user_form($v, $errors, null, $me) . '
            <div class="actions"><button type="submit" class="btn-primary">Add user</button></div>
        </form>
    </div>
    <div class="card">
        <div class="card-head"><h2>Users (' . count($users) . ')</h2></div>
        <div class="table-wrap"><table class="stack-table">
            <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Employee ID</th><th>Department</th><th>Reports to</th><th>Status</th><th></th></tr></thead>
            <tbody>' . $rows . '</tbody>
        </table></div>
    </div>';
    render_page('Administration', $body);
}

function page_toggle(): void
{
    $me = require_role(['Admin']);
    if (request_method() !== 'POST') redirect(url('admin'));
    check_csrf();

    $user = user_by_id($_POST['id'] ?? null);
    if (!$user) {
        http_response_code(404);
        exit('User not found.');
    }
    if ($user['id'] === $me['id']) {
        flash('err', 'You cannot deactivate your own account.');
        redirect(url('admin'));
    }
    if ($user['is_active']) {
        $activeAdmins = (int)db()->query("SELECT COUNT(*) FROM users WHERE role = 'Admin' AND is_active = 1")->fetchColumn();
        if ($user['role'] === 'Admin' && $activeAdmins <= 1) {
            flash('err', 'At least one active administrator is required.');
            redirect(url('admin'));
        }
        $s = db()->prepare('SELECT COUNT(*) FROM users WHERE manager_id = ? AND is_active = 1');
        $s->execute([$user['id']]);
        if ($user['role'] === 'Manager' && (int)$s->fetchColumn() > 0) {
            flash('err', $user['full_name'] . ' still has active employees. Reassign them before deactivating.');
            redirect(url('admin'));
        }
    }

    $newState = $user['is_active'] ? 0 : 1;
    db()->prepare('UPDATE users SET is_active = ? WHERE id = ?')->execute([$newState, $user['id']]);
    flash('ok', $user['full_name'] . ' is now ' . ($newState ? 'active' : 'deactivated') . '.');
    redirect(url('admin'));
}

function page_edit(): void
{
    $me = require_role(['Admin']);
    $user = user_by_id($_GET['id'] ?? ($_POST['id'] ?? null));
    if (!$user) {
        http_response_code(404);
        exit('User not found.');
    }

    $errors = [];
    $v = [
        'name' => $user['full_name'], 'email' => $user['email'], 'password' => '', 'role' => $user['role'],
        'emp_id' => $user['employee_id'], 'dept' => $user['department'], 'designation' => $user['designation'], 'location' => $user['location'], 'manager_id' => (string)$user['manager_id'],
    ];

    if (request_method() === 'POST') {
        check_csrf();
        [$errors, $v] = validate_user($_POST, $user, $me, false);
        if (!$errors) {
            $s = db()->prepare('UPDATE users SET full_name = ?, email = ?, employee_id = ?, department = ?, designation = ?, location = ?, role = ?, manager_id = ?, password_hash = ? WHERE id = ?');
            $s->execute([
                $v['name'], $v['email'], $v['emp_id'], $v['dept'], $v['designation'], $v['location'], $v['role'],
                $v['role'] === 'Employee' ? $v['manager_id'] : null,
                $v['password'] !== '' ? hash_password($v['password']) : $user['password_hash'],
                $user['id'],
            ]);
            flash('ok', 'Saved changes to ' . $v['name'] . '.');
            redirect(url('admin'));
        }
    }

    $body = '
    <h2 class="page-title">Edit user</h2>
    <p class="page-sub">Change details, role, manager, or set a new password for <strong>' . h($user['full_name']) . '</strong>.</p>
    <div class="card">
        <form method="post" action="' . h(url('edit', ['id' => $user['id']])) . '" autocomplete="off">
            <input type="hidden" name="_csrf" value="' . h(csrf_token()) . '" />
            <input type="hidden" name="id" value="' . h($user['id']) . '" />
            ' . user_form($v, $errors, $user, $me) . '
            <div class="actions">
                <button type="submit" class="btn-primary">Save changes</button>
                <a class="btn-ghost" href="' . h(url('admin')) . '">Cancel</a>
            </div>
        </form>
    </div>';
    render_page('Edit user', $body);
}

/* ---------- administrator: import previous overtime ---------- */

function imports_dir(): string
{
    return dirname(__DIR__) . '/data/imports';
}

function page_import(): void
{
    $me = require_role(['Admin']);
    $error = null;
    $preview = null;

    if (request_method() === 'POST') {
        check_csrf();
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'check') {
            $file = $_FILES['upload'] ?? null;
            $ext = $file ? strtolower('.' . pathinfo((string)$file['name'], PATHINFO_EXTENSION)) : '';
            if (!$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] === 0) {
                $error = 'Choose a file to upload. Very large files may need the upload limit raised in php.ini.';
            } elseif (!in_array($ext, ['.xlsx', '.csv'], true)) {
                $error = 'Upload an Excel (.xlsx) or CSV (.csv) file.';
            } else {
                if (!is_dir(imports_dir())) mkdir(imports_dir(), 0750, true);
                $token = bin2hex(random_bytes(16));
                $path = imports_dir() . '/' . $token . $ext;
                if (!move_uploaded_file($file['tmp_name'], $path)) {
                    $error = 'Could not store the upload. Check the data folder permissions.';
                } else {
                    try {
                        $rows = process_import($path, $ext, $me['id']);
                        $preview = ['token' => $token, 'file' => (string)$file['name'], 'rows' => $rows];
                    } catch (RuntimeException $e) {
                        $error = $e->getMessage();
                        @unlink($path);
                    } catch (Throwable $e) {
                        $error = 'Could not read that file. Save it as .xlsx or .csv and try again.';
                        @unlink($path);
                    }
                }
            }
        } elseif ($action === 'commit') {
            $token = (string)($_POST['token'] ?? '');
            $matches = preg_match('/^[a-f0-9]{32}$/', $token) ? glob(imports_dir() . '/' . $token . '.*') : [];
            if (!$matches) {
                flash('err', 'That preview has expired. Upload the file again.');
                redirect(url('import'));
            }
            $path = $matches[0];
            $ext = '.' . pathinfo($path, PATHINFO_EXTENSION);
            try {
                $rows = process_import($path, $ext, $me['id']);
                $ready = array_values(array_map(fn($r) => $r['record'], array_filter($rows, fn($r) => $r['result'] === 'Ready')));
                save_requests($ready);
                @unlink($path);
                flash('ok', 'Imported ' . count($ready) . ' record(s). ' . (count($rows) - count($ready)) . ' row(s) were skipped or need fixing.');
            } catch (Throwable $e) {
                flash('err', 'The file could not be imported. Upload it again.');
            }
            redirect(url('admin'));
        }
    }

    $previewHtml = '';
    if ($preview) {
        $ready = count(array_filter($preview['rows'], fn($r) => $r['result'] === 'Ready'));
        $rowsHtml = '';
        foreach ($preview['rows'] as $r) {
            $badge = $r['result'] === 'Ready' ? 'approved' : ($r['result'] === 'Skipped' ? 'inactive' : 'rejected');
            $rowsHtml .= '<tr>
                <td data-label="Line">' . (int)$r['line'] . '</td>
                <td data-label="Employee">' . h($r['who']) . '</td>
                <td data-label="Date">' . h($r['date']) . '</td>
                <td data-label="Hours">' . h($r['hours']) . '</td>
                <td data-label="Result"><span class="badge ' . $badge . '">' . h($r['result']) . '</span><div class="muted">' . h($r['message']) . '</div></td>
            </tr>';
        }
        $previewHtml = '
        <div class="card">
            <div class="card-head"><h2>2. Review: ' . h($preview['file']) . '</h2>
                <span class="muted">' . $ready . ' ready · ' . (count($preview['rows']) - $ready) . ' to skip or fix</span></div>
            ' . ($preview['rows'] ? '<div class="table-wrap"><table class="stack-table">
                <thead><tr><th>Line</th><th>Employee</th><th>Date</th><th>Hours</th><th>Result</th></tr></thead>
                <tbody>' . $rowsHtml . '</tbody></table></div>' : '<div class="empty">No data rows found in this file.</div>') . '
            <form method="post" action="' . h(url('import')) . '">
                <input type="hidden" name="_csrf" value="' . h(csrf_token()) . '" />
                <input type="hidden" name="action" value="commit" />
                <input type="hidden" name="token" value="' . h($preview['token']) . '" />
                <div class="actions"><button type="submit" class="btn-primary" ' . ($ready === 0 ? 'disabled' : '') . '>Import ' . $ready . ' record(s)</button></div>
            </form>
        </div>';
    }

    $body = ($error ? '<div class="flash err">' . h($error) . '</div>' : '') . '
    <h2 class="page-title">Import previous overtime</h2>
    <p class="page-sub">Load overtime records from an Excel or CSV file. Imported rows are saved as approved unless the Status column says otherwise.</p>
    <div class="card">
        <div class="card-head"><h2>1. Upload file</h2><a class="btn-ghost" href="' . h(url('template')) . '">Download template</a></div>
        <form method="post" action="' . h(url('import')) . '" enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="' . h(csrf_token()) . '" />
            <input type="hidden" name="action" value="check" />
            <div class="grid"><div class="full"><label for="upload">Excel (.xlsx) or CSV (.csv) file</label>
                <input type="file" id="upload" name="upload" accept=".xlsx,.csv" required /></div></div>
            <div class="actions"><button type="submit" class="btn-primary">Check file</button></div>
        </form>
        <div class="sub">Columns</div>
        <ul class="muted">
            <li><strong>Email</strong> or <strong>Employee ID</strong>: must match an existing user (required).</li>
            <li><strong>Date</strong>: for example 14/09/2026, 2026-09-14 or an Excel date. Slashed dates are read day first.</li>
            <li><strong>Start</strong> and <strong>End</strong>: for example 17:00 or 5:00 PM. An end earlier than the start is an overnight shift.</li>
            <li><strong>Project</strong> and <strong>Reason</strong>: optional. Reason defaults to "Previous overtime (imported)".</li>
            <li><strong>Status</strong>: optional. Approved (default), Pending or Rejected.</li>
        </ul>
        <p class="muted">Each row is checked against the filing rules: no future dates, no more than ' . (int)cfg('max_entry_hours') . ' hours, and no duplicates of existing records.</p>
    </div>
    ' . $previewHtml;
    render_page('Import previous overtime', $body);
}

function page_template(): void
{
    require_role(['Admin']);
    $csv = "\xEF\xBB\xBF" . "Email,Employee ID,Date,Start,End,Project,Reason,Status\r\n"
        . "employee@ibex.local,,14/09/2026,17:00,20:30,Site handover,Month-end close,Approved\r\n"
        . ",E-1023,15/09/2026,21:00,01:00,Warehouse audit,Stock count,Approved\r\n";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="overtime-import-template.csv"');
    echo $csv;
    exit;
}
