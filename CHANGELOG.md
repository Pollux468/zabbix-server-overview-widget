# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.3] - 2026-11-08

### Security
- **Error exposure fixed**: All actions (`WidgetView`, `DiskDetails`, `ServerDetails`, `Diagnostics`) now use `safeErrorMessage()` helper. In production (debug=off): returns generic `"Error interno del servidor"`; in debug mode: shows full exception details.
- **Target validation**: `Diagnostics.php` adds `validateTarget()` — strict allowlist (IPv4/IPv6 via `FILTER_VALIDATE_IP` or domain via `FILTER_VALIDATE_DOMAIN`) before `escapeshellarg()`. Rejects private/reserved IPs and invalid hostnames.
- **Admin-only diagnostics**: `checkPermissions()` now requires `USER_TYPE_ZABBIX_ADMIN` (was `USER_TYPE_ZABBIX_USER`). Ping, traceroute, nmap restricted to Super Admins.

### Fixed
- **Autoloader case-sensitivity**: Renamed `Actions/` → `actions/`, `Includes/` → `includes/` to match Zabbix autoloader expectations (lowercase namespace segments). Fixes "Class not found" on widget actions.
- **Widget form registration**: Added `"form_class": "WidgetForm"` to manifest.json so Zabbix loads custom form with `rows_per_page`, thresholds, columns, etc. Fixes Fatal error on widget edit.

### Testing
- **Validated on Zabbix 8.0**: Full integration test against local stack (Zabbix 8.0.0 + PHP 8.1 + nginx + php-fpm). All actions verified: `view`, `disk.get`, `server.get`, `diagnostic.run` (ping/traceroute/nmap).
- **Unit tests updated**: `DiagnosticsTest.php` (29 tests passing) reflects new admin-only permission and safe error messages.

### Changed
- Updated version to 0.2.3 in manifest.json

## [0.2.2] - 2026-11-03

### Fixed
- **Access denied on detail actions**: `ServerDetails`, `DiskDetails`, `Diagnostics` now extend `CControllerDashboardWidgetView` instead of `CController`. This allows Zabbix 7.0 widget framework to properly pass widget context and validate permissions for Super Admin users.

## [0.2.1] - 2026-11-03

### Fixed
- **manifest.json**: Corrected `author` (was "Hermes Agent"), `url` (now points to GitHub repo), and `version` (0.2.1 instead of 1.0)
- **Missing layout.json**: Added required layout file referenced by manifest.json for actions `server.get`, `disk.get`, and `diagnostic.run`. This was causing "Access denied" errors when clicking "Ver" button.

### Changed
- Updated version to 0.2.1 in manifest.json

## [0.2.0] - 2026-10-27

### Added
- Initial release of Server Overview Widget for Zabbix 7.0+
- Main widget view (`WidgetView.php`): consolidated server list with agent.ping, CPU, RAM, disk badges, network, uptime, problems
- Server details action (`ServerDetails.php`): operational view with history charts (CPU, RAM, disk, network) + inventory
- Disk details action (`DiskDetails.php`): per-filesystem detail with history
- Diagnostics action (`Diagnostics.php`): ping, traceroute, nmap (admin only)
- Widget form (`WidgetForm.php`): host groups, columns selection, thresholds (CPU/RAM/disk), rows per page
- Frontend (`class.widget.js`): client-side filtering, sorting, pagination, modals, Chart.js charts
- Styles (`widget.css`): Zabbix design tokens, gauges, badges, responsive tables, dark-mode diagnostics output
- Views (`widget.view.php`, `widget.edit.php`): server-side HTML generation with data-* attributes for JS
- Tests: PHP unit tests (actions.php), render test (render.php), JS smoke tests with jsdom (smoke.js)

### Technical
- Namespace: `Modules\ServerOverview`
- Widget ID: `serveroverview`
- JS class: `CWidgetServerOverview`
- Compatible with Zabbix 7.0 widget API (CWidget, actions, layouts)
- Follows VMware widget pattern from lab24com