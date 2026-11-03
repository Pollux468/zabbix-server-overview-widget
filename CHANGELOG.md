# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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