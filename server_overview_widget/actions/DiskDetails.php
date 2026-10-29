<?php declare(strict_types = 0);

namespace Modules\ServerOverview\Actions;

use API;
use CController;
use CControllerResponseData;
use Modules\ServerOverview\Includes\WidgetForm;

class DiskDetails extends CController {

	private float $disk_warning = WidgetForm::DEFAULT_DISK_WARNING;
	private float $disk_critical = WidgetForm::DEFAULT_DISK_CRITICAL;

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function checkInput(): bool {
		$valid = $this->validateInput([
			'hostid' => 'int32|required',
			'fs' => 'string|required',
			'range' => 'int32',
			'disk_warning' => 'int32',
			'disk_critical' => 'int32'
		]);

		if (!$valid) {
			$this->sendJson([
				'error' => implode(' ', array_column(get_and_clear_messages(), 'message'))
			]);
		}

		return $valid;
	}

		protected function doAction(): void {
		try {
			$this->handle();
		}
		catch (\Throwable $e) {
			error_log('[serveroverview] '.static::class.': '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
			$this->sendJson([
				'error' => $e->getMessage().' ('.basename($e->getFile()).':'.$e->getLine().')'
			]);
		}
	}

	private function handle(): void {
		$hostid = $this->getInput('hostid');
		$fs = $this->getInput('fs');
		$range = (int) $this->getInput('range', 3600);

		[$this->disk_warning, $this->disk_critical] = $this->thresholdPair('disk_warning', 'disk_critical',
			WidgetForm::DEFAULT_DISK_WARNING, WidgetForm::DEFAULT_DISK_CRITICAL);

		$hosts = $this->getHosts([$hostid], true);
		$host = $hosts[$hostid] ?? null;
		if (!$host) {
			$this->sendJson(['error' => 'Host no encontrado o sin permisos de lectura.']);
			return;
		}

		$items = $this->getItemsByHost([$hostid]);
		$host_items = $items[$hostid] ?? [];

		$disk = $this->getDiskData($host_items, $fs);
		$history = $this->getDiskHistory($host_items, $fs, $range);

		$this->sendJson([
			'host' => $host['name'],
			'hostid' => $hostid,
			'fs' => $fs,
			'disk' => $disk,
			'history' => $history,
			'range' => $range,
			'warning' => $this->disk_warning,
			'critical' => $this->disk_critical
		]);
	}

	private function thresholdPair(string $warning_field, string $critical_field, int $default_warning, int $default_critical): array {
		$warning = (int) ($this->hasInput($warning_field) ? $this->getInput($warning_field) : $default_warning);
		$critical = (int) ($this->hasInput($critical_field) ? $this->getInput($critical_field) : $default_critical);
		$warning = max(1, min(99, $warning ?: $default_warning));
		$critical = max($warning + 1, min(100, $critical ?: $default_critical));
		return [$warning, $critical];
	}

	private function getHosts(array $hostids, bool $include_maintenance): array {
		if (!$hostids) return [];

		$options = [
			'output' => ['hostid', 'host', 'name', 'status', 'maintenance_status'],
			'hostids' => $hostids,
			'selectInterfaces' => ['interfaceid', 'ip', 'dns', 'useip', 'main', 'type'],
			'preservekeys' => true
		];

		if (!$include_maintenance) {
			$options['filter'] = ['maintenance_status' => HOST_MAINTENANCE_STATUS_OFF];
		}

		return API::Host()->get($options);
	}

	private function getItemsByHost(array $hostids): array {
		if (!$hostids) return [];

		$items = API::Item()->get([
			'output' => [
				'itemid', 'hostid', 'name', 'key_', 'lastvalue', 'lastclock', 'units', 'value_type', 'state', 'status', 'error'
			],
			'hostids' => $hostids,
			'search' => ['key_' => ['vfs.fs.dependent.size', 'vfs.fs.size', 'vfs.fs.dependent[']],
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

	private function getDiskData(array $items, string $fs): array {
		$disk = ['filesystem' => $fs, 'label' => $fs];

		foreach ($items as $item) {
			$key = $item['key_'];

			if (str_starts_with($key, 'vfs.fs.dependent.size[')) {
				if (!preg_match('/vfs\.fs\.dependent\.size\[([^,\]]+),([^\]]+)\]/', $key, $matches)) continue;
				if ($matches[1] !== $fs) continue;
				$metric = $matches[2];
			}
			elseif (str_starts_with($key, 'vfs.fs.size[')) {
				if (!preg_match('/vfs\.fs\.size\[([^,\]]+),([^\]]+)\]/', $key, $matches)) continue;
				if ($matches[1] !== $fs) continue;
				$metric = $matches[2];
			}
			else continue;

			$disk[$metric] = $this->numberValue($item);
			$disk[$metric.'_itemid'] = $item['itemid'];
			$disk[$metric.'_supported'] = $this->isItemValueAvailable($item);
		}

		foreach ($items as $item) {
			if (!str_starts_with($item['key_'], 'vfs.fs.dependent[')) continue;
			$data = json_decode((string) $item['lastvalue'], true);
			if (!is_array($data)) continue;
			if (($data['fsname'] ?? '') !== $fs) continue;
			$disk['label'] = (trim((string) ($data['fslabel'] ?? '')) !== '' ? trim((string) $data['fslabel']) : $this->filesystemLabel($fs));
			$disk['fstype'] = $data['fstype'] ?? '—';
			$disk['drivetype'] = $data['fsdrivetype'] ?? '—';
			break;
		}

		$free = $disk['free'] ?? $disk['available'] ?? null;
		$total = $disk['total'] ?? null;
		$used = $disk['used'] ?? null;
		$pused = $disk['pused'] ?? null;
		$pfree = $disk['pfree'] ?? null;

		if ($pfree !== null) $free_percent = $pfree;
		elseif ($pused !== null) $free_percent = max(0, min(100, 100 - $pused));
		elseif ($free !== null && $total !== null && $total > 0) $free_percent = ($free / $total) * 100;
		else $free_percent = null;

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
		$disk['status'] = $used_percent === null
			? 'unknown'
			: ($used_percent >= $this->disk_critical ? 'critical' : ($used_percent >= $this->disk_warning ? 'warning' : 'ok'));

		return $disk;
	}

	private function getDiskHistory(array $items, string $fs, int $range): array {
		$history = [];

		foreach ($items as $item) {
			if (!str_starts_with($item['key_'], 'vfs.fs.dependent.size[') && !str_starts_with($item['key_'], 'vfs.fs.size[')) continue;
			if (!preg_match('/(pused|pfree|used|free|total)/', $item['key_'], $matches)) continue;
			$metric = $matches[1];

			$item_fs = '';
			if (preg_match('/\[([^,\]]+)/', $item['key_'], $fs_matches)) {
				$item_fs = $fs_matches[1];
			}
			if ($item_fs !== $fs) continue;

			$item_history = $this->getItemHistory($item, $range);
			if ($item_history) {
				$history[$metric] = $item_history;
			}
		}

		return $history;
	}

	private function getItemHistory(?array $item, int $range): array {
		if (!$this->isItemValueAvailable($item)) return [];

		$itemid = $item['itemid'];
		$history = API::History()->get([
			'output' => ['clock', 'value'],
			'itemids' => [$itemid],
			'history' => $item['value_type'],
			'time_from' => time() - $range,
			'time_till' => time(),
			'sortfield' => 'clock',
			'sortorder' => 'ASC',
			'limit' => 1000
		]);

		return array_map(static fn(array $point): array => [
			'clock' => (int) $point['clock'],
			'value' => (float) $point['value']
		], $history);
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

	private function sendJson(array $data): void {
		$this->setResponse(
			(new CControllerResponseData([
				'main_block' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
					| JSON_INVALID_UTF8_SUBSTITUTE)
			]))->disableView()
		);
	}
}
