<?php declare(strict_types = 0);

namespace Modules\ServerOverview\Actions;

use API;
use CController;
use CControllerResponseData;

class Diagnostics extends CController {

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function checkInput(): bool {
		$valid = $this->validateInput([
			'hostid' => 'int32|required',
			'operation' => 'string|required'
		]);

		if (!$valid) {
			$this->sendJson([
				'error' => implode(' ', array_column(get_and_clear_messages(), 'message'))
			]);
		}

		return $valid;
	}

	protected function doAction(): void {
		$hostid = $this->getInput('hostid');
		$operation = strtolower(trim((string) $this->getInput('operation')));
		if (!in_array($operation, ['ping', 'traceroute', 'nmap'], true)) {
			$this->sendJson(['error' => 'Operación de diagnóstico no permitida.']);
			return;
		}

		$hosts = API::Host()->get([
			'output' => ['hostid', 'host', 'name'],
			'hostids' => [$hostid],
			'selectInterfaces' => ['ip', 'dns', 'useip', 'main']
		]);

		$host = $hosts[0] ?? null;
		if (!$host) {
			$this->sendJson(['error' => 'Host no encontrado o sin permisos de lectura.']);
			return;
		}

		$ip = $this->getHostAddress($host['interfaces'] ?? []);
		$target = $ip !== '—' ? $ip : $host['host'];

		$output = '';
		$exit_code = -1;
		$timed_out = false;
		$start = microtime(true);

		switch ($operation) {
			case 'ping':
				[$output, $exit_code, $timed_out] = $this->runCommand("ping -c 4 -W 2 ".escapeshellarg($target), 15);
				break;
			case 'traceroute':
				[$output, $exit_code, $timed_out] = $this->runCommand("traceroute -w 2 -q 1 -m 20 ".escapeshellarg($target), 30);
				break;
			case 'nmap':
				if ($this->getUserType() < USER_TYPE_ZABBIX_ADMIN) {
					$this->sendJson(['error' => 'Insufficient permissions for port scan']);
					return;
				}
				[$output, $exit_code, $timed_out] = $this->runCommand("nmap -T4 -F ".escapeshellarg($target), 60);
				break;
		}

		$duration = round(microtime(true) - $start, 2);

		$this->sendJson([
			'host' => $host['name'],
			'target' => $target,
			'operation' => $operation,
			'output' => $output,
			'exit_code' => $exit_code,
			'duration' => $duration,
			'timed_out' => $timed_out
		]);
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

	private function runCommand(string $cmd, int $timeout): array {
		$descriptors = [
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w']
		];

		$process = proc_open($cmd, $descriptors, $pipes);
		if (!is_resource($process)) {
			return ['Failed to execute command', -1, false];
		}

		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);

		$output = '';
		$timed_out = false;
		$start = time();

		while (true) {
			$status = proc_get_status($process);
			if (!$status['running']) break;

			if (time() - $start >= $timeout) {
				proc_terminate($process, SIGKILL);
				$timed_out = true;
				break;
			}

			$read = [$pipes[1], $pipes[2]];
			$write = null;
			$except = null;
			stream_select($read, $write, $except, 0, 100000);

			foreach ($read as $pipe) {
				$data = fread($pipe, 8192);
				if ($data !== false && $data !== '') {
					$output .= $data;
				}
			}

			usleep(50000);
		}

		foreach ([$pipes[1], $pipes[2]] as $pipe) {
			$data = stream_get_contents($pipe);
			if ($data !== false && $data !== '') {
				$output .= $data;
			}
			fclose($pipe);
		}

		$exit_code = proc_close($process);

		if ($timed_out) {
			$output .= "\n[Command timed out after {$timeout}s]";
		}

		return [$output, $exit_code, $timed_out];
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
