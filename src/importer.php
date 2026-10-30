<?php
declare(strict_types=1);

/* Reads .xlsx and .csv files and checks each row for the import page. Uses only built-in PHP extensions. */

const XLSX_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

function read_spreadsheet(string $path, string $ext): array
{
    return match ($ext) {
        '.xlsx' => read_xlsx($path),
        '.csv' => read_csv($path),
        default => throw new RuntimeException('Upload an Excel (.xlsx) or CSV (.csv) file.'),
    };
}

function read_csv(string $path): array
{
    $rows = [];
    $fh = fopen($path, 'rb');
    if ($fh === false) throw new RuntimeException('Could not open the file.');
    $first = true;
    while (($cells = fgetcsv($fh)) !== false) {
        if ($first) {
            // Remove a byte-order mark added by Excel
            if (isset($cells[0])) $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cells[0]);
            $first = false;
        }
        $cells = array_map(fn($c) => trim((string)$c), $cells);
        if (implode('', $cells) === '') continue;
        $rows[] = $cells;
    }
    fclose($fh);
    return $rows;
}

function read_xlsx(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('That file is not a valid .xlsx workbook.');
    }

    $shared = [];
    if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $dom = new DOMDocument();
        $dom->loadXML($xml);
        foreach ($dom->getElementsByTagNameNS(XLSX_NS, 'si') as $si) {
            $text = '';
            foreach ($si->childNodes as $child) {
                if ($child->localName === 't') {
                    $text .= $child->textContent;
                } elseif ($child->localName === 'r') {
                    foreach ($child->childNodes as $rc) {
                        if ($rc->localName === 't') $text .= $rc->textContent;
                    }
                }
            }
            $shared[] = $text;
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) {
                $sheetXml = $zip->getFromName($name);
                break;
            }
        }
    }
    $zip->close();
    if ($sheetXml === false) throw new RuntimeException('The workbook has no worksheet.');

    $dom = new DOMDocument();
    $dom->loadXML($sheetXml);
    $rows = [];
    foreach ($dom->getElementsByTagNameNS(XLSX_NS, 'row') as $rowEl) {
        $cells = [];
        foreach ($rowEl->getElementsByTagNameNS(XLSX_NS, 'c') as $cell) {
            $ref = $cell->getAttribute('r');
            $col = $ref === '' ? count($cells) : xlsx_column_index($ref);
            while (count($cells) <= $col) $cells[] = '';
            $cells[$col] = xlsx_cell_text($cell, $shared);
        }
        if (array_filter($cells, fn($v) => $v !== '')) $rows[] = $cells;
    }
    return $rows;
}

function xlsx_column_index(string $ref): int
{
    $n = 0;
    for ($i = 0, $len = strlen($ref); $i < $len; $i++) {
        $c = ord(strtoupper($ref[$i]));
        if ($c < 65 || $c > 90) break;
        $n = $n * 26 + ($c - 64);
    }
    return $n - 1;
}

function xlsx_cell_text(DOMElement $cell, array $shared): string
{
    $type = $cell->getAttribute('t');
    $v = '';
    foreach ($cell->childNodes as $child) {
        if ($child->localName === 'v') $v = $child->textContent;
    }
    switch ($type) {
        case 's':
            return $shared[(int)$v] ?? '';
        case 'inlineStr':
            return trim($cell->textContent);
        case 'b':
            return $v === '1' ? 'TRUE' : 'FALSE';
        default:
            return $v; // text, error, or number (Excel dates and times are serial numbers)
    }
}

/** Accepts Excel serial dates, ISO dates, and day-first dates. */
function parse_import_date(string $t): ?string
{
    if ($t === '') return null;
    if (is_numeric($t) && !str_contains($t, '-') && !str_contains($t, '/')) {
        $serial = (float)$t;
        if ($serial >= 1 && $serial < 2958466) {
            return gmdate('Y-m-d', ((int)floor($serial) - 25569) * 86400);
        }
    }
    foreach (['Y-m-d', 'j/n/Y', 'j-n-Y', 'j-M-Y', 'j M Y', 'M j, Y', 'F j, Y'] as $format) {
        $d = DateTime::createFromFormat('!' . $format, $t);
        $errors = DateTime::getLastErrors();
        if ($d && (!$errors || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
            return $d->format('Y-m-d');
        }
    }
    return null;
}

/** Accepts Excel time fractions and text such as 17:00, 5:00 PM. Returns HH:MM. */
function parse_import_time(string $t): ?string
{
    if ($t === '') return null;
    if (is_numeric($t) && (float)$t >= 0) {
        $fraction = (float)$t - floor((float)$t);
        $minutes = (int)round($fraction * 1440) % 1440;
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
    if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?\s*([AaPp][Mm])?$/', $t, $m)) {
        $hour = (int)$m[1];
        $minute = (int)$m[2];
        if (isset($m[3]) && $m[3] !== '') {
            $hour %= 12;
            if (strtolower($m[3]) === 'pm') $hour += 12;
        }
        if ($hour < 24 && $minute < 60) return sprintf('%02d:%02d', $hour, $minute);
    }
    return null;
}

/**
 * Checks every data row. Returns rows with status Ready, Skipped or Error.
 * Ready rows carry a record ready to save.
 */
function process_import(string $path, string $ext, string $adminId): array
{
    $rows = read_spreadsheet($path, $ext);
    if (count($rows) < 2) {
        throw new RuntimeException('The file needs a header row and at least one data row.');
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
    $cEmp = $find('employee id', 'employeeid', 'emp id');
    $cDate = $find('date', 'date worked', 'work date');
    $cStart = $find('start', 'start time');
    $cEnd = $find('end', 'end time');
    $cProject = $find('project', 'project / task');
    $cReason = $find('reason');
    $cStatus = $find('status');

    if (($cEmail < 0 && $cEmp < 0) || $cDate < 0 || $cStart < 0 || $cEnd < 0) {
        throw new RuntimeException('The header row needs: Email or Employee ID, Date, Start, End. Optional: Project, Reason, Status. Use the template.');
    }

    $users = all_users();
    $byEmail = [];
    $byEmp = [];
    foreach ($users as $u) {
        $byEmail[strtolower($u['email'])] = $u;
        if ($u['employee_id'] !== '') $byEmp[strtolower($u['employee_id'])] = $u;
    }
    $existing = all_requests();
    $today = date('Y-m-d');
    $now = gmdate('Y-m-d H:i:s');
    $seen = [];
    $out = [];

    for ($i = 1; $i < count($rows); $i++) {
        $r = $rows[$i];
        $cell = fn(int $c): string => ($c >= 0 && $c < count($r)) ? trim((string)$r[$c]) : '';

        $email = $cell($cEmail);
        $empId = $cell($cEmp);
        $who = $email !== '' ? $email : $empId;
        $dateText = $cell($cDate);
        $startText = $cell($cStart);
        $endText = $cell($cEnd);
        $statusText = $cell($cStatus);

        $user = $email !== '' ? ($byEmail[strtolower($email)] ?? null)
                              : ($empId !== '' ? ($byEmp[strtolower($empId)] ?? null) : null);

        $error = null;
        if ($who === '') $error = 'Email or Employee ID is missing.';
        elseif (!$user) $error = "No user matches '$who'.";
        elseif (!$user['is_active']) $error = $user['full_name'] . ' is deactivated.';
        elseif ($user['role'] === 'Admin') $error = 'Administrators do not file overtime.';

        $date = parse_import_date($dateText);
        $start = parse_import_time($startText);
        $end = parse_import_time($endText);
        if ($error === null && $date === null) $error = "Date '$dateText' not recognised.";
        elseif ($error === null && $start === null) $error = "Start time '$startText' not recognised.";
        elseif ($error === null && $end === null) $error = "End time '$endText' not recognised.";
        elseif ($error === null && $date > $today) $error = 'Date is in the future.';
        elseif ($error === null && $start === $end) $error = 'End time must differ from start time.';

        $hours = 0.0;
        if ($error === null) {
            $hours = hours_between($start, $end);
            if ($hours > cfg('max_entry_hours')) $error = 'Longer than ' . cfg('max_entry_hours') . ' hours. Split the entry.';
        }

        $status = $statusText === '' ? 'Approved' : ucfirst(strtolower($statusText));
        if ($error === null && !in_array($status, ['Approved', 'Pending', 'Rejected'], true)) {
            $error = "Status '$statusText' must be Approved, Pending or Rejected.";
        }

        if ($error !== null) {
            $out[] = ['line' => $i, 'who' => $who, 'date' => $dateText, 'hours' => '', 'result' => 'Error', 'message' => $error, 'record' => null];
            continue;
        }

        $key = $user['id'] . "|$date|$start|$end";
        $alreadyRecorded = false;
        foreach ($existing as $e) {
            if ($e['employee_user_id'] === $user['id'] && $e['work_date'] === $date
                && substr($e['start_time'], 0, 5) === $start && substr($e['end_time'], 0, 5) === $end) {
                $alreadyRecorded = true;
                break;
            }
        }

        if ($alreadyRecorded || isset($seen[$key])) {
            $out[] = ['line' => $i, 'who' => $user['full_name'], 'date' => fmt_date($date), 'hours' => fmt_hours($hours),
                      'result' => 'Skipped', 'message' => $alreadyRecorded ? 'Already recorded.' : 'Repeated in this file.', 'record' => null];
            continue;
        }
        $seen[$key] = true;

        $decided = $status !== 'Pending';
        $record = [
            'id' => uuid(),
            'employee_user_id' => $user['id'],
            'work_date' => $date,
            'start_time' => $start,
            'end_time' => $end,
            'hours' => $hours,
            'project' => $cell($cProject),
            'reason' => $cell($cReason) !== '' ? $cell($cReason) : 'Previous overtime (imported)',
            'status' => $status,
            'manager_note' => '',
            'submitted_at_utc' => $now,
            'decided_at_utc' => $decided ? $now : null,
            'decided_by_user_id' => $decided ? $adminId : null,
        ];
        $out[] = ['line' => $i, 'who' => $user['full_name'], 'date' => fmt_date($date), 'hours' => fmt_hours($hours),
                  'result' => 'Ready', 'message' => "Will be saved as $status.", 'record' => $record];
    }
    return $out;
}

/** Saves a list of records in one transaction. */
function save_requests(array $records): void
{
    $pdo = db();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('INSERT INTO overtime_requests
        (id, employee_user_id, work_date, start_time, end_time, hours, project, reason, status, manager_note,
         submitted_at_utc, decided_at_utc, decided_by_user_id)
        VALUES (:id, :employee_user_id, :work_date, :start_time, :end_time, :hours, :project, :reason, :status,
                :manager_note, :submitted_at_utc, :decided_at_utc, :decided_by_user_id)');
    foreach ($records as $rec) {
        $stmt->execute($rec);
    }
    $pdo->commit();
}
