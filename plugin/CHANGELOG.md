# Changelog

## 2026-03-17

### Security

- Removed inline `onclick` copy handlers from the admin UI and replaced them with delegated JS using `data-copy`, closing the stored XSS vector from logged URLs.
- Changed REST API authentication to use the `X-WPSM-Key` header instead of `?key=...` query parameters.
- Added hashed storage for the REST API secret and migration support for legacy plaintext values.
- Tightened REST permission handling to return explicit `401` responses for unauthorized requests.
- Added payload size and value validation for the `log-push` endpoint.
- Sanitized request-derived values from `$_SERVER` and normalized month input validation for reporting queries.

### Database and Lifecycle

- Replaced raw `CREATE TABLE IF NOT EXISTS` calls with `dbDelta()`-based schema management.
- Added activation and deactivation handlers for scheduling and unscheduling maintenance tasks.
- Moved log cleanup from frontend/admin request paths to an hourly WP-Cron task.

### Admin and Reporting

- Updated the settings screen to describe header-based authentication and masked secret input behavior.
- Reworked date-based dashboard and REST queries to use prepared statements where dynamic values are involved.
- Added defensive handling for missing `ZipArchive` support and XLSX creation failures.

### Notes

- The main plugin file remains monolithic; no structural split into classes/files was done in this change set.
- PHP CLI was not available in the current environment, so no `php -l` syntax check was run locally.
