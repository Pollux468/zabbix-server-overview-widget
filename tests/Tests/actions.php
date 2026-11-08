<?php
/**
 * Pruebas de las acciones PHP sin necesitar Zabbix: stubs mínimos de CController/API/CWidgetForm.
 * Comprueba (1) la etiqueta de disco cuando fslabel viene vacío y (2) que una excepción se devuelve como JSON legible.
 */
namespace Zabbix\Widgets { class CWidgetForm {} class CWidgetField { const FLAG_NOT_EMPTY = 1; const FLAG_LABEL_ASTERISK = 2; } }
namespace {
	const HOST_MAINTENANCE_STATUS_OFF = 0; const HOST_MAINTENANCE_STATUS_ON = 1; const HOST_STATUS_MONITORED = 0;
	const USER_TYPE_ZABBIX_USER = 1; const DATE_TIME_FORMAT_SECONDS = 'Y-m-d H:i:s';
	const ITEM_STATE_NORMAL = 0; const ITEM_STATUS_ACTIVE = 0; const ITEM_STATE_NOTSUPPORTED = 1;
	function zbx_date2str($f, $t) { return date($f, $t); }
	function get_and_clear_messages() { return []; }

	class CControllerResponseData { public $data; public $view = true;
		function __construct($d) { $this->data = $d; } function disableView() { $this->view = false; return $this; } }
	class CController { public $response; public $input = [];
		function getInput($k, $d = null) { return $this->input[$k] ?? $d; }
		function hasInput($k) { return array_key_exists($k, $this->input); }
		function setResponse($r) { $this->response = $r; } function getUserType() { return 1; } }
	class CControllerDashboardWidgetView extends CController { public $fields_values = []; }
	class API { static function __callStatic($n, $a) { return new class { function get($o) { throw new RuntimeException('API caída'); } }; } }

	require __DIR__.'/../server_overview_widget/includes/WidgetForm.php';
	require __DIR__.'/../server_overview_widget/actions/ServerDetails.php';
	require __DIR__.'/../server_overview_widget/actions/WidgetView.php';

	$fail = 0;
	$check = function (string $name, bool $ok) use (&$fail) { echo ($ok ? 'ok   ' : 'FAIL ').$name."\n"; $fail += $ok ? 0 : 1; };

	$items = fn(string $label) => [
		['itemid' => '1', 'key_' => 'vfs.fs.dependent[C:]', 'lastvalue' => json_encode(['fsname' => 'C:', 'fslabel' => $label, 'fstype' => 'NTFS', 'fsdrivetype' => 'fixed']), 'lastclock' => '1', 'value_type' => '4', 'state' => '0', 'status' => '0', 'error' => ''],
		['itemid' => '2', 'key_' => 'vfs.fs.dependent.size[C:,pused]', 'lastvalue' => '85', 'lastclock' => '1', 'value_type' => '0', 'state' => '0', 'status' => '0', 'error' => '']
	];

	foreach (['Modules\ServerOverview\Actions\ServerDetails', 'Modules\ServerOverview\Actions\WidgetView'] as $class) {
		$short = substr(strrchr($class, '\\'), 1);
		$obj = (new ReflectionClass($class))->newInstanceWithoutConstructor();
		$m = new ReflectionMethod($class, 'getServerDisks'); $m->setAccessible(true);
		foreach ([$short.': fslabel vacío' => ['', 'C:'], $short.': fslabel con nombre' => ['DATA', 'DATA'], $short.': fslabel solo espacios' => ['  ', 'C:']] as $name => [$in, $expected]) {
			try { $label = $m->invoke($obj, $items($in))[0]['label'] ?? null; } catch (Throwable $e) { $label = 'EXC '.$e->getMessage(); }
			$check("$name -> «{$expected}» (obtenido: ".var_export($label, true).")", $label === $expected);
		}
	}

	$obj = new Modules\ServerOverview\Actions\ServerDetails();
	$obj->input = ['hostid' => '10001'];
	$m = new ReflectionMethod($obj, 'doAction'); $m->setAccessible(true); $m->invoke($obj);
	$json = json_decode($obj->response->data['main_block'] ?? '', true);
	$check('excepción -> JSON con "error" legible', isset($json['error']) && str_contains($json['error'], 'API caída'));
	$check('la respuesta no usa vista HTML', $obj->response->view === false);

	exit($fail ? 1 : 0);
}
