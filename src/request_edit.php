<?php
declare(strict_types=1);

/* Employees can change a request's date, times, project and reason while it is still pending. */

function page_request_edit(): void
{
    $me = require_role(['Employee', 'Manager']);
    $id = (string)($_GET['id'] ?? ($_POST['id'] ?? ''));

    $s = db()->prepare('SELECT * FROM overtime_requests WHERE id = ?');
    $s->execute([$id]);
    $req = $s->fetch();
    if (!$req || $req['employee_user_id'] !== $me['id']) {
        http_response_code(404);
        exit('Request not found.');
    }
    if ($req['status'] !== 'Pending') {
        flash('err', 'Only pending requests can be changed. Your manager has already decided this one.');
        redirect(url('home'));
    }

    $errors = [];
    $v = [
        'date' => $req['work_date'],
        'start' => substr($req['start_time'], 0, 5),
        'end' => substr($req['end_time'], 0, 5),
        'project' => $req['project'],
        'reason' => $req['reason'],
    ];

    if (request_method() === 'POST') {
        check_csrf();
        $v = [
            'date' => clean($_POST['date'] ?? '', 10),
            'start' => clean($_POST['start'] ?? '', 5),
            'end' => clean($_POST['end'] ?? '', 5),
            'project' => clean($_POST['project'] ?? '', 120),
            'reason' => clean($_POST['reason'] ?? '', 1000),
        ];
        $maxHours = (float)cfg('max_entry_hours');

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

        if (!$errors) {
            $hours = hours_between($v['start'], $v['end']);
            // The status check repeats in the WHERE clause so a decision made meanwhile can't be overwritten
            $u = db()->prepare("UPDATE overtime_requests SET work_date = ?, start_time = ?, end_time = ?, hours = ?,
                project = ?, reason = ? WHERE id = ? AND employee_user_id = ? AND status = 'Pending'");
            $u->execute([$v['date'], $v['start'], $v['end'], $hours, $v['project'], $v['reason'], $id, $me['id']]);
            flash('ok', 'Updated to ' . fmt_hours($hours) . ' hours. Your manager will review it.');
            redirect(url('home'));
        }
    }

    $err = fn(string $k): string => isset($errors[$k]) ? '<span class="field-error">' . h($errors[$k]) . '</span>' : '';
    $hoursText = ($v['start'] !== '' && $v['end'] !== '' && $v['start'] !== $v['end'])
        ? fmt_hours(hours_between($v['start'], $v['end'])) : '0.00';

    $body = flash_html() . '
    <h2 class="page-title">Edit overtime</h2>
    <p class="page-sub">You can change this request until your manager decides on it.</p>
    <div class="card">
        <form method="post" action="' . h(url('request', ['id' => $id])) . '">
            <input type="hidden" name="_csrf" value="' . h(csrf_token()) . '" />
            <input type="hidden" name="id" value="' . h($id) . '" />
            <div class="grid">
                <div><label for="f-date">Date worked</label><input id="f-date" name="date" type="date" max="' . date('Y-m-d') . '" value="' . h($v['date']) . '" required />' . $err('date') . '</div>
                <div><label for="f-start">Start time</label><input id="f-start" name="start" type="time" value="' . h($v['start']) . '" required />' . $err('start') . '</div>
                <div><label for="f-end">End time</label><input id="f-end" name="end" type="time" value="' . h($v['end']) . '" required />' . $err('end') . '</div>
                <div><label>Hours</label><div class="hours-out"><span id="hours-out">' . $hoursText . '</span> <span>hrs</span></div></div>
                <div class="full"><label for="f-project">Project / task</label><input id="f-project" name="project" maxlength="120" value="' . h($v['project']) . '" /></div>
                <div class="full"><label for="f-reason">Reason</label><textarea id="f-reason" name="reason" maxlength="1000" required>' . h($v['reason']) . '</textarea>' . $err('reason') . '</div>
            </div>
            <div class="actions">
                <button type="submit" class="btn-primary">Save changes</button>
                <a class="btn-ghost" href="' . h(url('home')) . '">Cancel</a>
            </div>
        </form>
    </div>
    <script src="assets/overtime-hours.js" defer></script>';
    render_page('Edit overtime', $body);
}
