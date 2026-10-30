<?php
/**
 * Portal settings. Edit these values before going live.
 */
return [
    // SQLite database file. Keep it OUTSIDE the public folder.
    'db_path' => __DIR__ . '/data/portal.sqlite',

    // Local time zone for dates and overtime rules (same as the .NET portal's server time).
    'timezone' => 'Asia/Karachi',

    // Send the session cookie only over HTTPS. Set to false only for local testing over plain http.
    'secure_cookies' => true,

    // Maximum overtime entry length, in hours. Longer entries need HR approval.
    'max_entry_hours' => 12,
];
