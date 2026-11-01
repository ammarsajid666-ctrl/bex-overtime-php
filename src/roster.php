<?php
declare(strict_types=1);

/* Roster: each employee's shift, site and allocation, plus the days they are on duty.
   Used by the roster page, the Excel export and the roster import. Uses only built-in PHP extensions. */

/** Active employees a viewer can schedule: a manager sees their team, an administrator sees everyone. */
function roster_team(array $me): array
{
    return array_values(array_filter(all_users(), function (array $u) use ($me): bool {
        if (!$u['is_active'] || $u['role'] !== 'Employee') return false;
        return $me['role'] === 'Admin' || $u['manager_id'] === $me['id'];
    }));
}

/** Year and month from the query string, defaulting to the current month. */
function roster_period(array $src): array
{
    $year = (int)($src['year'] ?? 0);
    $month = (int)($src['month'] ?? 0);
    if ($year < 2000 || $year > 2100) $year = (int)date('Y');
    if ($month < 1 || $month > 12) $month = (int)date('n');
    return [$year, $month];
}

/** Every date in a month as yyyy-mm-dd. */
function month_days(int $year, int $month): array
{
    $days = [];
    $count = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
    for ($d = 1; $d <= $count; $d++) {
        $days[] = sprintf('%04d-%02d-%02d', $year, $month, $d);
    }
    return $days;
}

/** Profile rows (shift, site, allocation) keyed by employee id. */
function roster_profiles(array $ids): array
{
    if (!$ids) return [];
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $s = db()->prepare("SELECT * FROM roster_profiles WHERE employee_user_id IN ($marks)");
    $s->execute($ids);
    $out = [];
    foreach ($s->fetchAll() as $row) {
        $out[$row['employee_user_id']] = $row;
    }
    return $out;
}

/** Duty flags for a date range, keyed by "employeeId|date" with 1 (on duty) or 0 (off). */
function roster_duty(array $ids, string $from, string $to): array
{
    if (!$ids) return [];
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $s = db()->prepare("SELECT employee_user_id, work_date, on_duty FROM roster_days
        WHERE employee_user_id IN ($marks) AND work_date BETWEEN ? AND ?");
    $s->execute([...$ids, $from, $to]);
    $out = [];
    foreach ($s->fetchAll() as $row) {
        $out[$row['employee_user_id'] . '|' . $row['work_date']] = (int)$row['on_duty'];
    }
    return $out;
}

function save_roster_profile(string $id, string $shift, string $site, string $allocation): void
{
    db()->prepare('INSERT INTO roster_profiles (employee_user_id, shift, site, allocation) VALUES (?, ?, ?, ?)
        ON CONFLICT(employee_user_id) DO UPDATE SET shift = excluded.shift, site = excluded.site,
        allocation = excluded.allocation')
        ->execute([$id, $shift, $site, $allocation]);
}

/** Sets a day to on duty (true), off (false), or clears it (null). */
function save_roster_day(string $id, string $date, ?bool $onDuty): void
{
    if ($onDuty === null) {
        db()->prepare('DELETE FROM roster_days WHERE employee_user_id = ? AND work_date = ?')->execute([$id, $date]);
        return;
    }
    db()->prepare('INSERT INTO roster_days (employee_user_id, work_date, on_duty) VALUES (?, ?, ?)
        ON CONFLICT(employee_user_id, work_date) DO UPDATE SET on_duty = excluded.on_duty')
        ->execute([$id, $date, $onDuty ? 1 : 0]);
}

/* ---------- import ---------- */

/**
 * Checks a roster file. Columns: Email or Employee ID, Date, On duty (Yes/No),
 * Shift, Site, Allocation. Leave On duty blank to clear that day. Shift, Site and Allocation
 * update the employee's profile when filled in.
 */
function process_roster_import(string $path, string $ext, array $team): array
{
    $rows = read_spreadsheet($path, $ext);
    if (count($rows) < 2) {
        throw new RuntimeException('The file needs a header row and at least one data row.');
    }

    foreach ($rows as $r) {
        if (in_array('resource', array_map(fn($c) => strtolower(trim((string)$c)), $r), true)) {
            return process_roster_grid($rows, $team);
        }
    }

    $header = array_map(fn($h) => strtolower(trim((string)$h)), $rows[0]);
    $find = function (string ...$names) use ($header): int {
        foreach ($names as $name) {
            $i = array_search($name, $header, true);
            if ($i !== false) return (int)$i;
        }
        return -1;
    };
    $cEmail = $find('email', 'work email');
    $cEmp = $find('employee id', 'employeeid');
    $cDate = $find('date');
    $cDuty = $find('on duty', 'duty');
    $cShift = $find('shift');
    $cSite = $find('site');
    $cAlloc = $find('allocation');

    if (($cEmail < 0 && $cEmp < 0) || $cDate < 0) {
        throw new RuntimeException('The header row needs Email or Employee ID, and Date. Optional: On duty, Shift, Site, Allocation.');
    }

    $byEmail = [];
    $byEmp = [];
    foreach ($team as $u) {
        $byEmail[strtolower($u['email'])] = $u;
        if ($u['employee_id'] !== '') $byEmp[strtolower($u['employee_id'])] = $u;
    }

    $out = [];
    for ($i = 1; $i < count($rows); $i++) {
        $r = $rows[$i];
        $cell = fn(int $c): string => ($c >= 0 && $c < count($r)) ? trim((string)$r[$c]) : '';

        $email = $cell($cEmail);
        $empId = $cell($cEmp);
        $who = $email !== '' ? $email : $empId;
        $user = $email !== '' ? ($byEmail[strtolower($email)] ?? null)
                              : ($empId !== '' ? ($byEmp[strtolower($empId)] ?? null) : null);
        $dateText = $cell($cDate);
        $date = parse_import_date($dateText);
        $dutyText = strtolower($cell($cDuty));
        $duty = match (true) {
            $dutyText === '' => null,
            in_array($dutyText, ['yes', 'y', 'on', '1', 'true'], true) => true,
            in_array($dutyText, ['no', 'n', 'off', '0', 'false'], true) => false,
            default => 'invalid',
        };

        $error = null;
        if ($who === '') $error = 'Email or Employee ID is missing.';
        elseif (!$user) $error = "No employee matches '$who' in your team.";
        elseif ($date === null) $error = "Date '$dateText' not recognised.";
        elseif ($duty === 'invalid') $error = 'On duty must be Yes or No.';

        if ($error !== null) {
            $out[] = ['line' => $i, 'who' => $who, 'date' => $dateText, 'result' => 'Error', 'message' => $error, 'record' => null];
            continue;
        }

        $message = match ($duty) {
            null => 'Clears this day.',
            true => 'Marked on duty.',
            false => 'Marked off.',
        };
        $out[] = [
            'line' => $i,
            'who' => $user['full_name'],
            'date' => fmt_date($date),
            'result' => 'Ready',
            'message' => $message,
            'record' => [
                'user' => $user['id'], 'date' => $date, 'duty' => $duty,
                'shift' => $cell($cShift), 'site' => $cell($cSite), 'allocation' => $cell($cAlloc),
            ],
        ];
    }
    return $out;
}

/** Saves checked roster rows in one transaction. */
function save_roster_records(array $records): void
{
    $pdo = db();
    $pdo->beginTransaction();
    foreach ($records as $rec) {
        if (isset($rec['days'])) {
            foreach ($rec['days'] as $date => $onDuty) {
                save_roster_day($rec['user'], (string)$date, $onDuty);
            }
        } else {
            save_roster_day($rec['user'], $rec['date'], $rec['duty']);
        }
        if ($rec['shift'] !== '' || $rec['site'] !== '' || $rec['allocation'] !== '') {
            $current = roster_profiles([$rec['user']])[$rec['user']] ?? ['shift' => '', 'site' => '', 'allocation' => ''];
            save_roster_profile(
                $rec['user'],
                $rec['shift'] !== '' ? $rec['shift'] : $current['shift'],
                $rec['site'] !== '' ? $rec['site'] : $current['site'],
                $rec['allocation'] !== '' ? $rec['allocation'] : $current['allocation']
            );
        }
    }
    $pdo->commit();
}

/* ---------- Excel export ---------- */

/** Excel column letter for a zero-based index: 0 = A, 25 = Z, 26 = AA. */
function xlsx_col(int $index): string
{
    $name = '';
    $n = $index + 1;
    while ($n > 0) {
        $name = chr(65 + (($n - 1) % 26)) . $name;
        $n = intdiv($n - 1, 26);
    }
    return $name;
}

/**
 * Roster grid in the same layout as the GSO schedule: shift, resource, name, site, allocation,
 * then one column per day with ✓ (on duty) or ✗ (off).
 * Returns [rows, bold row indexes, column widths].
 */
function roster_export_grid(array $team, int $year, int $month): array
{
    $days = month_days($year, $month);
    $ids = array_column($team, 'id');
    $profiles = roster_profiles($ids);
    $duty = roster_duty($ids, $days[0], $days[count($days) - 1]);

    $header = ['Shift', 'Resource', 'Name', 'Site', 'Allocation'];
    foreach ($days as $date) {
        $header[] = (new DateTime($date))->format('D d-M');
    }

    $rows = [['Operation team roster - ' . MONTH_NAMES[$month - 1] . ' ' . $year], [], $header];
    $totals = array_fill(0, count($days), 0);

    foreach ($team as $i => $u) {
        $p = $profiles[$u['id']] ?? ['shift' => '', 'site' => '', 'allocation' => ''];
        $row = [
            $p['shift'],
            $u['employee_id'] !== '' ? $u['employee_id'] : 'R' . ($i + 1),
            $u['full_name'],
            $p['site'],
            $p['allocation'],
        ];
        foreach ($days as $d => $date) {
            $key = $u['id'] . '|' . $date;
            if (!isset($duty[$key])) {
                $row[] = '';
                continue;
            }
            $row[] = $duty[$key] ? '✓' : '✗';
            if ($duty[$key]) $totals[$d]++;
        }
        $rows[] = $row;
    }

    $rows[] = array_merge(['', '', '', '', 'Total on duty'], $totals);
    return [$rows, [0, 2, count($rows) - 1], [10, 12, 22, 16, 24]];
}

/** Writes rows to a single-sheet .xlsx file. Rows listed in $boldRows are bold. */
function write_xlsx(string $path, array $rows, array $boldRows, array $widths): void
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not create the Excel file.');
    }

    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    $zip->addFromString('[Content_Types].xml', $xml
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>');

    $zip->addFromString('_rels/.rels', $xml
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="' . $rel . '/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>');

    $zip->addFromString('xl/workbook.xml', $xml
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="' . $rel . '">'
        . '<sheets><sheet name="Roster" sheetId="1" r:id="rId1"/></sheets></workbook>');

    $zip->addFromString('xl/_rels/workbook.xml.rels', $xml
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="' . $rel . '/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="' . $rel . '/styles" Target="styles.xml"/>'
        . '</Relationships>');

    $zip->addFromString('xl/styles.xml', $xml
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
        . '</styleSheet>');

    $sheet = $xml . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetViews><sheetView workbookViewId="0"/></sheetViews>';
    if ($widths) {
        $sheet .= '<cols>';
        foreach ($widths as $col => $w) {
            $sheet .= '<col min="' . ($col + 1) . '" max="' . ($col + 1) . '" width="' . $w . '" customWidth="1"/>';
        }
        $sheet .= '</cols>';
    }
    $sheet .= '<sheetData>';
    foreach ($rows as $r => $cells) {
        $sheet .= '<row r="' . ($r + 1) . '">';
        foreach ($cells as $c => $value) {
            if ($value === '' || $value === null) continue;
            $style = in_array($r, $boldRows, true) ? ' s="1"' : '';
            $sheet .= '<c r="' . xlsx_col($c) . ($r + 1) . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">'
                . htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8')
                . '</t></is></c>';
        }
        $sheet .= '</row>';
    }
    $sheet .= '</sheetData></worksheet>';
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);

    $zip->close();
}

/** Roster duty for each overtime request's employee and date, keyed by "employeeId|date" (1 = on duty, 0 = off). */
function roster_duty_for_requests(array $requests): array
{
    if (!$requests) return [];
    $dates = array_column($requests, 'work_date');
    sort($dates);
    $ids = array_values(array_unique(array_column($requests, 'employee_user_id')));
    return roster_duty($ids, $dates[0], $dates[count($dates) - 1]);
}


/**
 * Reads the monthly roster grid: Shift, Resource, Name, Site, Allocation, then one column per day
 * labelled like "Sun 01-Nov", with ✓ (on duty) or ✗ (off). Blank cells are left unchanged.
 * Employees are matched by name, or by resource code when there is no name.
 */
function process_roster_grid(array $rows, array $team): array
{
    $hdrIdx = null;
    foreach ($rows as $i => $r) {
        if (in_array('resource', array_map(fn($c) => strtolower(trim((string)$c)), $r), true)) {
            $hdrIdx = $i;
            break;
        }
    }
    $header = array_map(fn($c) => trim((string)$c), $rows[$hdrIdx]);
    $lower = array_map('strtolower', $header);
    $idx = fn(string $name): int => ($found = array_search($name, $lower, true)) === false ? -1 : (int)$found;
    $cResource = $idx('resource');
    $cName = $idx('name');
    $cShift = $idx('shift');
    $cSite = $idx('site');
    $cAlloc = $idx('allocation');

    // The year comes from the title row, for example "Operation team roster - November 2026"
    $year = 0;
    foreach ($rows as $i => $r) {
        if ($i >= $hdrIdx) break;
        if (preg_match('/(\d{4})/', implode(' ', array_map('strval', $r)), $m)) {
            $year = (int)$m[1];
        }
    }
    if ($year === 0) $year = (int)date('Y');

    $dayCols = [];
    foreach ($header as $c => $label) {
        if (preg_match('/^[A-Za-z]{3}\s+(\d{1,2})-([A-Za-z]{3})$/', $label, $m)) {
            $d = DateTime::createFromFormat('!j-M Y', $m[1] . '-' . $m[2] . ' ' . $year);
            if ($d) $dayCols[$c] = $d->format('Y-m-d');
        }
    }
    if (!$dayCols) {
        throw new RuntimeException('No day columns found. Day headers should look like "Sun 01-Nov".');
    }

    $byName = [];
    $byCode = [];
    foreach ($team as $u) {
        $byName[strtolower($u['full_name'])] = $u;
        if ($u['employee_id'] !== '') $byCode[strtolower($u['employee_id'])] = $u;
    }

    $out = [];
    $seenUsers = [];
    for ($i = $hdrIdx + 1; $i < count($rows); $i++) {
        $r = $rows[$i];
        $cell = fn(int $c): string => ($c >= 0 && isset($r[$c])) ? trim((string)$r[$c]) : '';
        $code = $cell($cResource);
        $name = $cell($cName);
        if ($code === '' && $name === '') continue;

        $marks = [];
        foreach ($dayCols as $c => $date) {
            $v = $cell($c);
            if ($v === '✓') $marks[$date] = true;
            elseif ($v === '✗') $marks[$date] = false;
        }
        $who = $name !== '' ? $name : $code;
        if (!$marks) {
            $out[] = ['line' => $i + 1, 'who' => $who, 'date' => '', 'result' => 'Skipped',
                'message' => 'No ✓ or ✗ marks in this row.', 'record' => null];
            continue;
        }

        // Match by name when there is one. Falling back to the code could attach marks to the wrong person.
        $user = $name !== ''
            ? ($byName[strtolower($name)] ?? null)
            : ($byCode[strtolower($code)] ?? null);
        if (!$user) {
            $out[] = ['line' => $i + 1, 'who' => $who, 'date' => '', 'result' => 'Error',
                'message' => "No employee in your team is named '$who'. Check the manager of this employee.", 'record' => null];
            continue;
        }
        if (isset($seenUsers[$user['id']])) {
            $out[] = ['line' => $i + 1, 'who' => $user['full_name'], 'date' => '', 'result' => 'Error',
                'message' => 'This employee appears more than once in the file. Only the first row was used.', 'record' => null];
            continue;
        }
        $seenUsers[$user['id']] = true;

        $dates = array_keys($marks);
        $on = count(array_filter($marks));
        $out[] = [
            'line' => $i + 1,
            'who' => $user['full_name'],
            'date' => fmt_date($dates[0]) . ' to ' . fmt_date($dates[count($dates) - 1]),
            'result' => 'Ready',
            'message' => $on . ' day(s) on duty, ' . (count($marks) - $on) . ' off.',
            'record' => [
                'user' => $user['id'], 'days' => $marks,
                'shift' => $cell($cShift), 'site' => $cell($cSite), 'allocation' => $cell($cAlloc),
            ],
        ];
    }
    return $out;
}
