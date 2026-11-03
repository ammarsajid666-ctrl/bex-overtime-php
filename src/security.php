<?php
declare(strict_types=1);

/* Protection against password guessing, automated bots, and form abuse. */

function client_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/** True when the key has reached $max failures within the last $windowSeconds. */
function throttled(string $key, int $max, int $windowSeconds): bool
{
    $s = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE attempt_key = ? AND at > ?');
    $s->execute([$key, time() - $windowSeconds]);
    return (int)$s->fetchColumn() >= $max;
}

function record_failure(string $key): void
{
    db()->prepare('INSERT INTO login_attempts (attempt_key, at) VALUES (?, ?)')->execute([$key, time()]);
}

function clear_failures(string $key): void
{
    db()->prepare('DELETE FROM login_attempts WHERE attempt_key = ?')->execute([$key]);
}

function prune_attempts(): void
{
    db()->prepare('DELETE FROM login_attempts WHERE at < ?')->execute([time() - 86400]);
}

/** A hidden field people never see (bots fill it in) and a render time stored in the session. */
function bot_trap_fields(string $form): string
{
    $_SESSION['form_started'][$form] = time();
    return '<div class="hp-wrap" aria-hidden="true"><label>Leave this field empty
        <input type="text" name="website" tabindex="-1" autocomplete="off" /></label></div>';
}

/** True when a submission looks automated: the trap field is filled, or it was sent in under 2 seconds. */
function looks_automated(string $form): bool
{
    if (($_POST['website'] ?? '') !== '') return true;
    $started = $_SESSION['form_started'][$form] ?? 0;
    return $started === 0 || (time() - $started) < 2;
}
