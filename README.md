# IBEX Overtime Portal (PHP 8.3 + SQLite)

PHP version of the overtime portal. Same screens, rules, and look as the .NET version.
Data is stored in one SQLite file: `data/portal.sqlite`.

## Demo accounts (from `tools/seed.php`)

| Role     | Email               | Password        |
|----------|---------------------|-----------------|
| Admin    | admin@ibex.local    | AdminPass123!   |
| Manager  | waseem@ibex.local   | ManagerPass1    |
| Manager  | Musharraf@ibex.co   | MusharrafPass1  |
| Employee | ali@ibex.local      | EmployeePass1   |
| Employee | sara@ibex.local     | EmployeePass1   |
| Employee | Faizan@ibex.co      | FaizanPass1     |

Change every password before real use (Administration → Edit user).

## Requirements on the Windows Server

1. **PHP 8.3 NTS x64** from windows.php.net, extracted to `C:\PHP`.
   - Copy `php.ini-production` to `php.ini`.
   - Enable extensions: `pdo_sqlite`, `sqlite3`, `zip`, `fileinfo`, `openssl`, `mbstring`.
   - Set `upload_max_filesize = 20M` and `post_max_size = 20M` (for Excel imports).
   - Set `date.timezone = Asia/Karachi`.
2. **IIS CGI / FastCGI**: install the "CGI" role service (Web Server → Application Development),
   then add a handler in IIS: `FastCgiModule` for `*.php` → `C:\PHP\php-cgi.exe`.
   `public/web.config` already contains this mapping. Update the path if PHP is installed elsewhere.
3. **HTTPS**: the existing binding on 8443 can be reused. Keep `secure_cookies` = true in `config.php`.

## Install

1. Copy this folder to `C:\inetpub\ibex-overtime-php\` (the whole folder, including `public`, `src`, `data`).
2. In IIS, create a site or application whose **physical path is `C:\inetpub\ibex-overtime-php\public`**.
3. Give the IIS user (`IIS AppPool\<app pool name>`) **Modify** rights on the `data` folder
   so SQLite can write the database and uploaded imports.
4. Browse to `https://overtime.ibex.local:8443/`.

## Moving real data from the .NET portal

1. Stop the .NET site in IIS so no new records are written.
2. Copy `App_Data\portal.json` from the .NET site to the server.
3. In a command prompt:
   ```
   cd C:\inetpub\ibex-overtime-php
   C:\PHP\php.exe tools\migrate_json.php C:\path\to\portal.json --replace
   ```
   The `--replace` flag clears the demo accounts first. Existing passwords still work.
4. Start the site again and sign in with an existing account.

## Maintenance

- **Backup**: copy `data\portal.sqlite` (stop the site first, or use a copy when no one is signed in).
- **Import previous overtime**: Administration → Import previous overtime. Templates are available on that page.
- **Reset to demo data** (testing only, deletes everything): `C:\PHP\php.exe tools\seed.php --fresh`

## Local testing on a Mac or Linux machine

```
php -S localhost:8088 -t public
```

Then open http://localhost:8088. Session cookies are marked Secure, so set `secure_cookies` to
`false` in `config.php` for plain-HTTP testing, and back to `true` on the server.

## Files

- `public/index.php`: front controller (`?page=...` routes)
- `public/web.config`: IIS handler and upload limit
- `src/bootstrap.php`: database, sign-in, security (CSRF), overtime rules, filters
- `src/pages.php`: every screen and form handler
- `src/views.php`: shared layout and filter dropdowns
- `src/importer.php`: Excel (.xlsx) and CSV reader, row checks, import
- `data/schema.sql`: database tables (created automatically on first run)
- `tools/seed.php`: demo accounts and sample records
- `tools/migrate_json.php`: import from the .NET portal's JSON store
