<?php
declare(strict_types=1);

/* Roster pages: the monthly grid, Excel export, and roster import. */

function page_roster(): void
{
    $me = require_role(['Manager', 'Admin']);
    [$year, $month] = roster_period($_GET);
    $team = roster_team($me);
    $days = month_days($year, $month);

    if (request_method() === 'POST') {
        check_csrf();
        $pdo = db();
        $pdo->beginTransaction();
        foreach ($team as $u) {
            $p = $_POST['profile'][$u['id']] ?? null;
            if (is_array($p)) {
                save_roster_profile($u['id'], clean($p['shift'] ?? '', 40), clean($p['site'] ?? '', 60), clean($p['allocation'] ?? '', 120));
            }
            foreach ($days as $date) {
                $v = $_POST['duty'][$u['id']][$date] ?? null;
                if ($v === 'on') save_roster_day($u['id'], $date, true);
                elseif ($v === 'off') save_roster_day($u['id'], $date, false);
                elseif ($v === '') save_roster_day($u['id'], $date, null);
            }
        }
        $pdo->commit();
        flash('ok', 'Roster saved for ' . MONTH_NAMES[$month - 1] . ' ' . $year . '.');
        redirect(url('roster', ['year' => $year, 'month' => $month]));
    }

    $ids = array_column($team, 'id');
    $profiles = roster_profiles($ids);
    $duty = roster_duty($ids, $days[0], $days[count($days) - 1]);

    $monthOptions = '';
    foreach (MONTH_NAMES as $i => $name) {
        $monthOptions .= '<option value="' . ($i + 1) . '"' . ($i + 1 === $month ? ' selected' : '') . '>' . $name . '</option>';
    }

    $head = '<th>Shift</th><th>Resource</th><th>Name</th><th>Site</th><th>Allocation</th>';
    foreach ($days as $date) {
        $head .= '<th class="day">' . h((new DateTime($date))->format('D j')) . '</th>';
    }

    $body = '';
    foreach ($team as $i => $u) {
        $id = h($u['id']);
        $p = $profiles[$u['id']] ?? ['shift' => '', 'site' => '', 'allocation' => ''];
        $resource = $u['employee_id'] !== '' ? $u['employee_id'] : 'R' . ($i + 1);
        $row = '<tr>
            <td><input class="cell-input" name="profile[' . $id . '][shift]" value="' . h($p['shift']) . '" maxlength="40" /></td>
            <td>' . h($resource) . '</td>
            <td class="title">' . h($u['full_name']) . '</td>
            <td><input class="cell-input" name="profile[' . $id . '][site]" value="' . h($p['site']) . '" maxlength="60" /></td>
            <td><input class="cell-input" name="profile[' . $id . '][allocation]" value="' . h($p['allocation']) . '" maxlength="120" /></td>';
        foreach ($days as $date) {
            $key = $u['id'] . '|' . $date;
            $v = isset($duty[$key]) ? ($duty[$key] ? 'on' : 'off') : '';
            $row .= '<td class="day"><select class="cell-select" name="duty[' . $id . '][' . h($date) . ']" aria-label="' . h($u['full_name'] . ' ' . $date) . '">'
                . '<option value=""' . ($v === '' ? ' selected' : '') . '>—</option>'
                . '<option value="on"' . ($v === 'on' ? ' selected' : '') . '>✓</option>'
                . '<option value="off"' . ($v === 'off' ? ' selected' : '') . '>✗</option>'
                . '</select></td>';
        }
        $body .= $row . '</tr>';
    }

    $period = ['year' => $year, 'month' => $month];
    $grid = $team
        ? '<form method="post" action="' . h(url('roster', $period)) . '">
            <input type="hidden" name="_csrf" value="' . h(csrf_token()) . '" />
            <div class="table-wrap"><table class="roster-table">
                <thead><tr>' . $head . '</tr></thead>
                <tbody>' . $body . '</tbody>
            </table></div>
            <div class="actions">
                <button type="submit" class="btn-primary">Save roster</button>
            </div>
        </form>'
        : '<div class="empty">No employees to schedule yet. Add employees under Administration first.</div>';

    $body = flash_html() . '
    <h2 class="page-title">Roster</h2>
    <p class="page-sub">Set each employee\'s shift, site and allocation, then mark the days they are on duty (✓) or off (✗). Blank means not scheduled. Overtime requests on an off day are flagged for review.</p>
    <div class="card">
        <form method="get" class="filter-form">
            <input type="hidden" name="page" value="roster" />
            <div class="field"><label for="r-month">Month</label><select id="r-month" name="month">' . $monthOptions . '</select></div>
            <div class="field"><label for="r-year">Year</label><input id="r-year" type="number" name="year" min="2000" max="2100" value="' . $year . '" /></div>
            <div class="filter-actions">
                <button type="submit" class="btn-primary">Show</button>
                <a class="btn-ghost" href="' . h(url('roster_export', $period)) . '">Download Excel</a>
                <a class="btn-ghost" href="' . h(url('roster_import')) . '">Import roster</a>
            </div>
        </form>
        ' . $grid . '
    </div>';
    render_page('Roster', $body);
}

function page_roster_export(): void
{
    $me = require_role(['Manager', 'Admin']);
    [$year, $month] = roster_period($_GET);
    [$rows, $bold, $widths] = roster_export_grid(roster_team($me), $year, $month);

    $file = tempnam(sys_get_temp_dir(), 'roster');
    write_xlsx($file, $rows, $bold, $widths);

    $name = 'roster-' . $year . '-' . sprintf('%02d', $month) . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($file));
    readfile($file);
    unlink($file);
    exit;
}

function page_roster_import(): void
{
    $me = require_role(['Manager', 'Admin']);
    $team = roster_team($me);
    $error = null;
    $preview = null;

    if (request_method() === 'POST') {
        check_csrf();
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'check') {
            $file = $_FILES['upload'] ?? null;
            $ext = $file ? strtolower('.' . pathinfo((string)$file['name'], PATHINFO_EXTENSION)) : '';
            if (!$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] === 0) {
                $error = 'Choose a file to upload.';
            } elseif (!in_array($ext, ['.xlsx', '.csv'], true)) {
                $error = 'Upload an Excel (.xlsx) or CSV (.csv) file.';
            } else {
                if (!is_dir(imports_dir())) mkdir(imports_dir(), 0750, true);
                $token = bin2hex(random_bytes(16));
                $path = imports_dir() . '/roster-' . $token . $ext;
                if (!move_uploaded_file($file['tmp_name'], $path)) {
                    $error = 'Could not store the upload. Check the data folder permissions.';
                } else {
                    try {
                        $rows = process_roster_import($path, $ext, $team);
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
            $matches = preg_match('/^[a-f0-9]{32}$/', $token) ? glob(imports_dir() . '/roster-' . $token . '.*') : [];
            if (!$matches) {
                flash('err', 'That preview has expired. Upload the file again.');
                redirect(url('roster_import'));
            }
            $path = $matches[0];
            $ext = '.' . pathinfo($path, PATHINFO_EXTENSION);
            $rows = process_roster_import($path, $ext, $team);
            $ready = array_values(array_map(fn($r) => $r['record'], array_filter($rows, fn($r) => $r['result'] === 'Ready')));
            save_roster_records($ready);
            @unlink($path);
            flash('ok', 'Imported ' . count($ready) . ' roster row(s). ' . (count($rows) - count($ready)) . ' row(s) were skipped.');
            redirect(url('roster'));
        }
    }

    $previewHtml = '';
    if ($preview) {
        $ready = count(array_filter($preview['rows'], fn($r) => $r['result'] === 'Ready'));
        $rowsHtml = '';
        foreach ($preview['rows'] as $r) {
            $badge = $r['result'] === 'Ready' ? 'approved' : 'rejected';
            $rowsHtml .= '<tr>
                <td data-label="Line">' . (int)$r['line'] . '</td>
                <td data-label="Employee">' . h($r['who']) . '</td>
                <td data-label="Date">' . h($r['date']) . '</td>
                <td data-label="Result"><span class="badge ' . $badge . '">' . h($r['result']) . '</span><div class="muted">' . h($r['message']) . '</div></td>
            </tr>';
        }
        $previewHtml = '
        <div class="card">
            <div class="card-head"><h2>2. Review: ' . h($preview['file']) . '</h2>
                <span class="muted">' . $ready . ' ready · ' . (count($preview['rows']) - $ready) . ' to fix</span></div>
            ' . ($preview['rows'] ? '<div class="table-wrap"><table class="stack-table">
                <thead><tr><th>Line</th><th>Employee</th><th>Date</th><th>Result</th></tr></thead>
                <tbody>' . $rowsHtml . '</tbody></table></div>' : '<div class="empty">No data rows found in this file.</div>') . '
            <form method="post" action="' . h(url('roster_import')) . '">
                <input type="hidden" name="_csrf" value="' . h(csrf_token()) . '" />
                <input type="hidden" name="action" value="commit" />
                <input type="hidden" name="token" value="' . h($preview['token']) . '" />
                <div class="actions"><button type="submit" class="btn-primary" ' . ($ready === 0 ? 'disabled' : '') . '>Import ' . $ready . ' row(s)</button></div>
            </form>
        </div>';
    }

    $body = flash_html() . ($error ? '<div class="flash err">' . h($error) . '</div>' : '') . '
    <h2 class="page-title">Import roster</h2>
    <p class="page-sub">Load a roster from Excel or CSV. Each row sets one employee\'s duty for one day.</p>
    <div class="card">
        <div class="card-head"><h2>1. Upload file</h2><a class="btn-ghost" href="' . h(url('roster')) . '">Back to roster</a></div>
        <form method="post" action="' . h(url('roster_import')) . '" enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="' . h(csrf_token()) . '" />
            <input type="hidden" name="action" value="check" />
            <div class="grid"><div class="full"><label for="upload">Excel (.xlsx) or CSV (.csv) file</label>
                <input type="file" id="upload" name="upload" accept=".xlsx,.csv" required /></div></div>
            <div class="actions"><button type="submit" class="btn-primary">Check file</button></div>
        </form>
        <div class="sub">Columns</div>
        <ul class="muted">
            <li><strong>Email</strong> or <strong>Employee ID</strong>: must match an employee in your team (required).</li>
            <li><strong>Date</strong>: for example 14/09/2026 or 2026-09-14 (required).</li>
            <li><strong>On duty</strong>: Yes or No. Leave blank to clear that day.</li>
            <li><strong>Shift</strong>, <strong>Site</strong>, <strong>Allocation</strong>: optional. Filled-in values update the employee\'s roster profile.</li>
        </ul>
    </div>
    ' . $previewHtml;
    render_page('Import roster', $body);
}
