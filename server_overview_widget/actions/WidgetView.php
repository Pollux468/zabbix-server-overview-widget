<?php declare(strict_types = 0);

namespace Modules\ServerOverview\Actions;

use API;
use CControllerDashboardWidgetView;
use CControllerResponseData;
use CCsrfTokenHelper;
use Modules\ServerOverview\Includes\WidgetForm;

class WidgetView extends CControllerDashboardWidgetView {

	private const AGENT_MARKER = 'agent.ping';

	private float $disk_warning = WidgetForm::DEFAULT_DISK_WARNING;
	private float $disk_critical = WidgetForm::DEFAULT_DISK_CRITICAL;
	private float $cpu_warning = WidgetForm::DEFAULT_CPU_WARNING;
	private float $cpu_critical = WidgetForm::DEFAULT_CPU_CRITICAL;
	private float $memory_warning = WidgetForm::DEFAULT_MEMORY_WARNING;
	private float $memory_critical = WidgetForm::DEFAULT_MEMORY_CRITICAL;

	protected function doAction(): void {
		$groupids = $this->fields_values['groupids']
			? getSubGroups($this->fields_values['groupids'])
			: null;

		[$this->disk_warning, $this->disk_critical] = $this->thresholdPair('disk_warning', 'disk_critical',
			WidgetForm::DEFAULT_DISK_WARNING, WidgetForm::DEFAULT_DISK_CRITICAL);
		[$this->cpu_warning, $this->cpu_critical] = $this->thresholdPair('cpu_warning', 'cpu_critical',
			WidgetForm::DEFAULT_CPU_WARNING, WidgetForm::DEFAULT_CPU_CRITICAL);
		[$this->memory_warning, $this->memory_critical] = $this->thresholdPair('memory_warning', 'memory_critical',
			WidgetForm::DEFAULT_MEMORY_WARNING, WidgetForm::DEFAULT_MEMORY_CRITICAL);

		$include_maintenance = (bool) $this->fields_values['show_maintenance'];
		$show_powered_off = (bool) $this->fields_values['show_powered_off'];
		$columns = array_values(array_map(
			static fn($id) => WidgetForm::COLUMN_NAMES[$id],
			array_filter((array) ($this->fields_values['columns'] ?? []),
				static fn($id) => isset(WidgetForm::COLUMN_NAMES[$id]))
		));

		$agent_hostids = $this->getHostIdsByMarker(self::AGENT_MARKER, $groupids);
		$hosts = $this->getHosts($agent_hostids, $include_maintenance);
		$visible_hostids = array_keys($hosts);

		$items_by_host = $this->getItemsByHost($visible_hostids);
		$problem_counts = $this->getProblemCounts($visible_hostids);

		$servers = [];
		foreach ($visible_hostids as $hostid) {
			$server = $this->makeServerRow(
				$hosts[$hostid],
				$items_by_host[$hostid] ?? [],
				$problem_counts[$hostid] ?? 0,
				$columns
			);

			if (!$show_powered_off && $server['agent_ping'] === 0) {
				continue;
			}

			$servers[] = $server;
		}

		usort($servers, static fn(array $a, array $b): int => ($b['risk_score'] <=> $a['risk_score'])
			?: strnatcasecmp($a['name'], $b['name']));

		$summary = [
			'total' => count($servers),
			'up' => count(array_filter($servers, static fn(array $s): bool => $s['agent_ping'] === 1)),
			'down' => count(array_filter($servers, static fn(array $s): bool => $s['agent_ping'] === 0)),
			'problems' => count(array_filter($servers, static fn(array $s): bool => $s['problems'] > 0)),
			'cpu_alerts' => count(array_filter($servers, static fn(array $s): bool => $s['cpu_alert'])),
			'memory_alerts' => count(array_filter($servers, static fn(array $s): bool => $s['memory_alert'])),
			'disk_alerts' => count(array_filter($servers, static fn(array $s): bool => $s['disk_alert'])),
			'windows' => count(array_filter($servers, static fn(array $s): bool => $s['os_family'] === 'Windows')),
			'linux' => count(array_filter($servers, static fn(array $s): bool => $s['os_family'] === 'Linux')),
		];

		$this->setResponse(new CControllerResponseData([
			'name' => $this->getInput('name', $this->widget->getDefaultName()),
			'servers' => $servers,
			'summary' => $summary,
			'rows_per_page' => (int) $this->fields_values['rows_per_page'],
			'disk_warning' => $this->disk_warning,
			'disk_critical' => $this->disk_critical,
			'cpu_warning' => $this->cpu_warning,
			'cpu_critical' => $this->cpu_critical,
			'memory_warning' => $this->memory_warning,
			'memory_critical' => $this->memory_critical,
			'columns' => $columns,
			'diagnostic_token' => CCsrfTokenHelper::get('widget.serveroverview.diagnostic.run'),
			'can_scan_ports' => $this->getUserType() >= USER_TYPE_ZABBIX_ADMIN,
			'generated' => zbx_date2str(DATE_TIME_FORMAT_SECONDS, time()),
			'user' => [
				'debug_mode' => $this->getDebugMode()
			]
		]));
	}

	private function thresholdPair(string $warning_field, string $critical_field, int $default_warning, int $default_critical): array {
		$warning = (int) ($this->fields_values[$warning_field] ?? $default_warning);
		$critical = (int) ($this->fields_values[$critical_field] ?? $default_critical);
		$warning = max(1, min(99, $warning ?: $default_warning));
		$critical = max($warning + 1, min(100, $critical ?: $default_critical));
		return [$warning, $critical];
	}

	private function getHostIdsByMarker(string $marker, ?array $groupids): array {
		$options = [
			'output' => ['hostid'],
			'search' => ['key_' => $marker],
			'templated' => false,
			'webitems' => true
		];

		if ($groupids !== null) {
			$options['groupids'] = $groupids;
		}

		$items = API::Item()->get($options);
		return array_values(array_unique(array_column($items, 'hostid')));
	}

	private function getHosts(array $hostids, bool $include_maintenance): array {
		if (!$hostids) {
			return [];
		}

		$options = [
			'output' => ['hostid', 'host', 'name', 'status', 'maintenance_status'],
			'hostids' => $hostids,
			'selectInterfaces' => ['interfaceid', 'ip', 'dns', 'useip', 'main', 'type'],
			'selectInventory' => ['os', 'os_full', 'type_full', 'hardware', 'serialno_a', 'serialno_b', 'tag', 'asset_tag'],
			'selectMacros' => ['macro', 'value'],
			'preservekeys' => true
		];

		if (!$include_maintenance) {
			$options['filter'] = ['maintenance_status' => HOST_MAINTENANCE_STATUS_OFF];
		}

		return API::Host()->get($options);
	}

	private function getItemsByHost(array $hostids): array {
		if (!$hostids) {
			return [];
		}

		$items = API::Item()->get([
			'output' => [
				'itemid', 'hostid', 'name', 'key_', 'lastvalue', 'lastclock', 'units', 'value_type', 'state', 'status', 'error'
			],
			'hostids' => $hostids,
			'search' => ['key_' => [
				'agent.ping',
				'agent.version',
				'agent.hostname',
				'system.cpu.util',
				'system.uptime',
				'system.hostname',
				'system.sw.os',
				'system.uname',
				'vm.memory.size',
				'vm.memory.util',
				'vfs.fs.dependent.size',
				'vfs.fs.dependent[',
				'vfs.fs.size',
				'vfs.fs.get',
				'net.if.in',
				'net.if.out',
				'net.if.speed',
				'net.if.status',
				'wmi.get',
				'system.sw.arch'
			]],
			'searchByAny' => true,
			'selectTags' => ['tag', 'value'],
			'templated' => false,
			'webitems' => true
		]);

		$result = [];
		foreach ($items as $item) {
			$result[$item['hostid']][] = $item;
		}

		return $result;
	}

	private function getProblemCounts(array $hostids): array {
		if (!$hostids) {
			return [];
		}

		$counts = array_fill_keys($hostids, 0);

		try {
			$problems = API::Problem()->get([
				'output' => ['eventid', 'objectid'],
				'hostids' => $hostids,
				'source' => EVENT_SOURCE_TRIGGERS,
				'object' => EVENT_OBJECT_TRIGGER,
				'recent' => false
			]);
			$events_by_trigger = [];
			foreach ($problems as $problem) {
				$events_by_trigger[(string) $problem['objectid']][(string) $problem['eventid']] = true;
			}
			if ($events_by_trigger) {
				$triggers = API::Trigger()->get([
					'output' => ['triggerid'],
					'triggerids' => array_keys($events_by_trigger),
					'selectHosts' => ['hostid'],
					'filter' => ['status' => TRIGGER_STATUS_ENABLED]
				]);
				foreach ($triggers as $trigger) {
					$event_count = count($events_by_trigger[(string) $trigger['triggerid']] ?? []);
					foreach ($trigger['hosts'] ?? [] as $host) {
						if (array_key_exists($host['hostid'], $counts)) {
							$counts[$host['hostid']] += $event_count;
						}
					}
				}
			}
		}
		catch (\Throwable $e) {
			// Trigger API fallback below
		}

		try {
			$trigger_counts = array_fill_keys($hostids, 0);
			$triggers = API::Trigger()->get([
				'output' => ['triggerid'],
				'hostids' => $hostids,
				'filter' => ['status' => TRIGGER_STATUS_ENABLED, 'value' => TRIGGER_VALUE_PROBLEM],
				'selectHosts' => ['hostid']
			]);
			foreach ($triggers as $trigger) {
				foreach ($trigger['hosts'] ?? [] as $host) {
					if (array_key_exists($host['hostid'], $trigger_counts)) {
						$trigger_counts[$host['hostid']]++;
					}
				}
			}
			foreach ($hostids as $hostid) {
				$counts[$hostid] += $trigger_counts[$hostid];
			}
		}
		catch (\Throwable $e) {
			// ignore
		}

		return $counts;
	}

	private function makeServerRow(array $host, array $items, int $problems, array $columns): array {
		$agent_ping = $this->numberValue($this->findItem($items, 'agent.ping'));
		$cpu = $this->numberValue($this->findItem($items, 'system.cpu.util'));
		$memory_total = $this->numberValue($this->findItem($items, 'vm.memory.size[total]'));
		$memory_used = $this->numberValue($this->findItem($items, 'vm.memory.size[used]'));
		$memory_util = $this->numberValue($this->findItem($items, 'vm.memory.util'));
		$memory_percent = $memory_total > 0 && $memory_used !== null
			? ($memory_used / $memory_total) * 100
			: $memory_util;
		$uptime = $this->numberValue($this->findItem($items, 'system.uptime'));
		$os = $this->textValue($this->findItem($items, 'system.sw.os'));
		if ($os === '—') {
			$os = $this->textValue($this->findItem($items, 'system.uname'));
		}
		$inventory = $host['inventory'] ?? [];
		if ($os === '—') {
			$os = $inventory['os_full'] ?? $inventory['os'] ?? $inventory['type_full'] ?? '—';
		}
		$os_family = $this->osFamily($os);

		$disks = $this->getServerDisks($items);
		$disk_badges = $this->getDiskBadges($disks);

		$network = $this->getNetworkSummary($items);

		$cpu_alert = $cpu !== null && $cpu >= $this->cpu_warning;
		$memory_alert = $memory_percent !== null && $memory_percent >= $this->memory_warning;
		$disk_alert = false;
		foreach ($disk_badges as $disk) {
			if (in_array($disk['status'] ?? '', ['warning', 'critical'], true)) {
				$disk_alert = true;
				break;
			}
		}

		$resource_alerts = (int) $cpu_alert + (int) $memory_alert + (int) $disk_alert;
		$max_disk_usage = 0.0;
		foreach ($disk_badges as $disk) {
			$max_disk_usage = max($max_disk_usage, (float) ($disk['used_percent_raw'] ?? 0));
		}
		$risk_score = ($problems + $resource_alerts) * 1000000
			+ max((float) ($cpu ?? 0), (float) ($memory_percent ?? 0), $max_disk_usage) * 100;

		$row_tone = ($problems > 0 || $cpu !== null && $cpu >= $this->cpu_critical
				|| $memory_percent !== null && $memory_percent >= $this->memory_critical
				|| in_array('critical', array_column($disk_badges, 'status'), true))
			? 'critical'
			: (($resource_alerts > 0) ? 'warning' : '');

		$last_update = $this->lastUpdate($items);
		$ip = $this->getHostAddress($host['interfaces'] ?? []);

		return [
			'hostid' => $host['hostid'],
			'name' => $host['name'],
			'host_url' => 'zabbix.php?action=host.dashboard.view&hostid='.$host['hostid'],
			'enabled' => (int) $host['status'] === HOST_STATUS_MONITORED,
			'maintenance' => (int) $host['maintenance_status'] === HOST_MAINTENANCE_STATUS_ON,
			'ip' => $ip,
			'os' => $os,
			'os_family' => $os_family,
			'agent_ping' => $agent_ping !== null ? (int) $agent_ping : 0,
			'agent_version' => $this->textValue($this->findItem($items, 'agent.version')),
			'cpu_raw' => $cpu,
			'cpu' => $this->formatPercent($cpu),
			'memory_percent_raw' => $memory_percent,
			'memory_percent' => $this->formatPercent($memory_percent),
			'memory_used_raw' => (float) ($memory_used ?? 0),
			'memory_total_raw' => (float) ($memory_total ?? 0),
			'memory_total' => $this->formatBytes($memory_total),
			'memory_used' => $this->formatBytes($memory_used),
			'uptime' => $this->formatUptime($uptime),
			'uptime_raw' => $uptime,
			'disks' => $disks,
			'disk_badges' => $disk_badges,
			'hidden_disks' => max(0, count($disks) - count($disk_badges)),
			'network' => $network,
			'row_tone' => $row_tone,
			'last_update' => $last_update,
			'item_count' => count($items),
			'zabbix_problems' => $problems,
			'cpu_alert' => $cpu_alert,
			'memory_alert' => $memory_alert,
			'disk_alert' => $disk_alert,
			'resource_alerts' => $resource_alerts,
			'problems' => $problems + $resource_alerts,
			'risk_score' => $risk_score
		];
	}

	private function getServerDisks(array $items): array {
		$disks = [];

		foreach ($items as $item) {
			$key = $item['key_'];

			if (str_starts_with($key, 'vfs.fs.dependent.size[')) {
				if (!preg_match('/vfs\.fs\.dependent\.size\[([^,\]]+),([^\]]+)\]/', $key, $matches)) {
					continue;
				}
				$fs = $matches[1];
				$metric = $matches[2];
			}
			elseif (str_starts_with($key, 'vfs.fs.size[')) {
				if (!preg_match('/vfs\.fs\.size\[([^,\]]+),([^\]]+)\]/', $key, $matches)) {
					continue;
				}
				$fs = $matches[1];
				$metric = $matches[2];
			}
			else {
				continue;
			}

			$disks[$fs]['filesystem'] = $fs;
			$disks[$fs][$metric] = $this->numberValue($item);
			$disks[$fs][$metric.'_itemid'] = $item['itemid'];
			$disks[$fs][$metric.'_supported'] = $this->isItemValueAvailable($item);
		}

		foreach ($items as $item) {
			if (!str_starts_with($item['key_'], 'vfs.fs.dependent[')) {
				continue;
			}
			$data = json_decode((string) $item['lastvalue'], true);
			if (!is_array($data)) continue;
			$fs = $data['fsname'] ?? '';
			if ($fs === '') continue;
			$disks[$fs]['label'] = $data['fslabel'] ?? $this->filesystemLabel($fs);
			$disks[$fs]['fstype'] = $data['fstype'] ?? '—';
			$disks[$fs]['drivetype'] = $data['fsdrivetype'] ?? '—';
		}

		foreach ($disks as &$disk) {
			$fs = $disk['filesystem'];

			$free = $disk['free'] ?? $disk['available'] ?? null;
			$total = $disk['total'] ?? null;
			$used = $disk['used'] ?? null;
			$pused = $disk['pused'] ?? null;
			$pfree = $disk['pfree'] ?? null;

			if ($pfree !== null) {
				$free_percent = $pfree;
			}
			elseif ($pused !== null) {
				$free_percent = max(0, min(100, 100 - $pused));
			}
			elseif ($free !== null && $total !== null && $total > 0) {
				$free_percent = ($free / $total) * 100;
			}
			else {
				$free_percent = null;
			}

			$used_percent = $free_percent !== null ? max(0, min(100, 100 - $free_percent)) : null;
			$disk['used_percent_raw'] = $used_percent;
			$disk['used_percent'] = $this->formatPercent($used_percent);
			$disk['free_percent_raw'] = $free_percent;
			$disk['free_percent'] = $this->formatPercent($free_percent);
			$disk['used_raw'] = $used ?? ($total !== null && $free !== null ? $total - $free : null);
			$disk['total_raw'] = $total;
			$disk['free_raw'] = $free;
			$disk['used'] = $this->formatBytes($disk['used_raw']);
			$disk['total'] = $this->formatBytes($total);
			$disk['free'] = $this->formatBytes($free);
			$disk['label'] = $disk['label'] ?? $this->filesystemLabel($fs);
			$disk['status'] = $used_percent === null
				? 'unknown'
				: ($used_percent >= $this->disk_critical ? 'critical' : ($used_percent >= $this->disk_warning ? 'warning' : 'ok'));
		}
		unset($disk);

		$disks = array_values($disks);
		usort($disks, static fn(array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));

		return $disks;
	}

	private function getDiskBadges(array $disks): array {
		$preferred = [];
		$secondary = [];

		foreach ($disks as $disk) {
			$fs = $disk['filesystem'];
			$is_windows_drive = preg_match('/^[A-Za-z]:[\\\\\\/]?$/', $fs) === 1;
			$is_linux_root = $fs === '/' || $fs === '\\';

			if ($is_windows_drive || $is_linux_root) {
				$preferred[] = $disk;
			}
			else {
				$secondary[] = $disk;
			}
		}

		$status_weight = ['critical' => 0, 'warning' => 1, 'unknown' => 2, 'ok' => 3];
		$sort = static function(array $a, array $b) use ($status_weight): int {
			$status = ($status_weight[$a['status']] ?? 9) <=> ($status_weight[$b['status']] ?? 9);
			return $status !== 0 ? $status : strnatcasecmp($a['label'], $b['label']);
		};
		usort($preferred, $sort);
		usort($secondary, $sort);

		$badges = array_merge(array_slice($preferred, 0, 5), array_slice($secondary, 0, 2));
		return array_slice($badges, 0, 5);
	}

	private function getNetworkSummary(array $items): array {
		$interfaces = [];

		foreach ($items as $item) {
			if (!str_starts_with($item['key_'], 'net.if.') || str_contains($item['key_'], '.discovery[')) {
				continue;
			}

			$iface = '';
			foreach ($item['tags'] ?? [] as $tag) {
				if (in_array($tag['tag'], ['interface', 'network'], true) && $tag['value'] !== '') {
					$iface = $tag['value'];
					break;
				}
			}
			if ($iface === '' && preg_match('/net\.if\.\w+\[([^,\]]+)/', $item['key_'], $matches)) {
				$iface = trim($matches[1], '"');
			}
			if ($iface === '') continue;

			$key = $item['key_'];
			if (str_starts_with($key, 'net.if.in[') && !str_contains($key, ',errors') && !str_contains($key, ',dropped')) {
				$interfaces[$iface]['in'] = $this->numberValue($item);
			}
			elseif (str_starts_with($key, 'net.if.out[') && !str_contains($key, ',errors') && !str_contains($key, ',dropped')) {
				$interfaces[$iface]['out'] = $this->numberValue($item);
			}
			elseif (str_starts_with($key, 'net.if.speed[')) {
				$interfaces[$iface]['speed'] = $this->numberValue($item);
			}
			elseif (str_starts_with($key, 'net.if.status[')) {
				$interfaces[$iface]['status'] = $this->numberValue($item);
			}
			$interfaces[$iface]['name'] = $iface;
		}

		$total_in = 0;
		$total_out = 0;
		$up_count = 0;
		foreach ($interfaces as $iface) {
			$total_in += $iface['in'] ?? 0;
			$total_out += $iface['out'] ?? 0;
			if (($iface['status'] ?? 0) === 1) $up_count++;
		}

		return [
			'interfaces' => array_values($interfaces),
			'total_in' => $this->formatBytes($total_in).'/s',
			'total_out' => $this->formatBytes($total_out).'/s',
			'total_in_raw' => $total_in,
			'total_out_raw' => $total_out,
			'up_count' => $up_count,
			'total_count' => count($interfaces)
		];
	}

	private function lastUpdate(array $items): string {
		$lastclock = 0;
		foreach ($items as $item) {
			$lastclock = max($lastclock, (int) $item['lastclock']);
		}
		return $lastclock > 0 ? zbx_date2str(DATE_TIME_FORMAT_SECONDS, $lastclock) : '—';
	}

	private function findItem(array $items, string $prefix, ?string $suffix = null): ?array {
		foreach ($items as $item) {
			if (str_starts_with($item['key_'], $prefix) && ($suffix === null || str_ends_with($item['key_'], $suffix))) {
				return $item;
			}
		}
		return null;
	}

	private function isItemValueAvailable(?array $item): bool {
		return $item !== null
			&& (int) $item['state'] === ITEM_STATE_NORMAL
			&& (int) $item['status'] === ITEM_STATUS_ACTIVE
			&& (int) $item['lastclock'] > 0
			&& $item['lastvalue'] !== '';
	}

	private function numberValue(?array $item): ?float {
		return $this->isItemValueAvailable($item) && is_numeric($item['lastvalue'])
			? (float) $item['lastvalue']
			: null;
	}

	private function textValue(?array $item): string {
		return $this->isItemValueAvailable($item) ? (string) $item['lastvalue'] : '—';
	}

	private function osFamily(string $value): string {
		$value = mb_strtolower($value);
		if (str_contains($value, 'windows')) return 'Windows';
		foreach (['linux', 'ubuntu', 'debian', 'centos', 'red hat', 'rhel', 'rocky', 'alma', 'suse', 'oracle linux', 'fedora'] as $marker) {
			if (str_contains($value, $marker)) return 'Linux';
		}
		return '—';
	}

	private function getHostAddress(array $interfaces): string {
		if (!$interfaces) return '—';
		usort($interfaces, static fn(array $a, array $b): int => (int) $b['main'] <=> (int) $a['main']);
		foreach ($interfaces as $interface) {
			$address = (int) $interface['useip'] === 1 ? $interface['ip'] : $interface['dns'];
			if ($address !== '') return $address;
		}
		return '—';
	}

	private function filesystemLabel(string $filesystem): string {
		if (preg_match('/^([A-Za-z]):(?:\\\\\\\\)?$/', $filesystem, $matches)) {
			return strtoupper($matches[1]).':';
		}
		return $filesystem;
	}

	private function formatPercent(?float $value): string {
		return $value !== null ? number_format($value, 1, '.', '').' %' : '—';
	}

	private function formatBytes(?float $bytes): string {
		if ($bytes === null) return '—';
		$units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
		$index = 0;
		$value = abs($bytes);
		while ($value >= 1024 && $index < count($units) - 1) {
			$value /= 1024;
			$index++;
		}
		$value = $bytes < 0 ? -$value : $value;
		return number_format($value, $index > 1 ? 1 : 0, '.', '').' '.$units[$index];
	}

	private function formatUptime(?float $seconds): string {
		if ($seconds === null) return '—';
		$seconds = max(0, (int) $seconds);
		$days = intdiv($seconds, 86400);
		$hours = intdiv($seconds % 86400, 3600);
		$minutes = intdiv($seconds % 3600, 60);
		return ($days > 0 ? $days.'d ' : '').$hours.'h '.$minutes.'m';
	}
}