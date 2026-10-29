<?php declare(strict_types = 0);

/**
 * Server: Vista general — vista principal del widget.
 *
 * Genera el HTML que consume class.widget.js: tarjetas resumen, barra de herramientas, tabla de servidores con
 * filtros/ordenación/paginación en cliente y atributos data-* con los umbrales y el token CSRF.
 *
 * @var CView $this
 * @var array $data
 */

$tag = static function(string $name, $content = null, ?string $class = null, array $attributes = []): CTag {
	$element = new CTag($name, !in_array($name, ['input'], true), $content);
	if ($class !== null && $class !== '') {
		$element->addClass($class);
	}
	foreach ($attributes as $attribute => $value) {
		$element->setAttribute($attribute, $value);
	}
	return $element;
};

$columns = array_flip($data['columns']);
$has = static fn(string $name): bool => isset($columns[$name]);

$cpu_warning = (float) $data['cpu_warning'];
$cpu_critical = (float) $data['cpu_critical'];
$memory_warning = (float) $data['memory_warning'];
$memory_critical = (float) $data['memory_critical'];

$tone = static function(?float $value, float $warning, float $critical): string {
	return $value === null ? 'unknown' : ($value >= $critical ? 'critical' : ($value >= $warning ? 'warning' : 'ok'));
};

$progress = static function(?float $value, string $kind) use ($tag): CTag {
	$width = $value !== null ? max(0, min(100, $value)) : 0;
	return $tag('div', [
		$tag('span', null, 'srv-progress-fill '.$kind, ['style' => 'width: '.round($width, 1).'%'])
	], 'srv-progress-track', ['aria-hidden' => 'true']);
};

$metric = static function(?float $value, string $formatted, string $detail, float $warning, float $critical)
		use ($tag, $progress, $tone): CTag {
	$kind = $tone($value, $warning, $critical);
	return $tag('div', [
		$tag('div', [
			$tag('span', $formatted, 'srv-metric-value '.($kind === 'ok' ? '' : $kind)),
			$detail !== '' ? $tag('span', $detail, 'srv-metric-detail') : null
		], 'srv-metric-line'),
		$progress($value, $kind)
	], 'srv-metric');
};

$status = static function(string $text, string $class) use ($tag): CTag {
	return $tag('span', [$tag('span', null, 'srv-status-dot'), $text], 'srv-status '.$class);
};

// Un valor vacío significa "sin dato" y siempre queda al final de la ordenación, en cualquier dirección.
$sort_value = static fn($value): string => $value === null || $value === '' || $value === '—' ? '' : (string) $value;

$th = static function(string $label, ?string $field = null, string $type = 'number', ?string $class = null,
		array $attributes = []) use ($tag): CTag {
	if ($field === null) {
		return $tag('th', $label, $class, $attributes);
	}
	return $tag('th', $tag('button', [$label, $tag('span', null, 'srv-sort-icon', ['aria-hidden' => 'true'])],
		'srv-sort-head', [
			'type' => 'button',
			'title' => $type === 'text' ? 'Ordenar alfabéticamente (clic de nuevo para invertir)'
				: 'Ordenar de mayor a menor (clic de nuevo para invertir)'
		]), $class, $attributes + ['data-sort' => $field, 'data-sort-type' => $type, 'aria-sort' => 'none']);
};

$summary = $data['summary'];
$percent_of = static fn(int $part, int $total): float => $total > 0 ? round($part / $total * 100, 1) : 0.0;

// Tarjetas resumen ------------------------------------------------------------------------------------------------

$card = static function(string $label, $value, string $tone_class, array $attributes, string $sub = '',
		string $sub_class = '', ?float $bar = null) use ($tag): CTag {
	return $tag('button', [
		$tag('span', $label, 'srv-card-label'),
		$tag('span', [
			$tag('span', (string) $value, 'srv-card-value'),
			$sub !== '' ? $tag('span', $sub, 'srv-card-sub '.$sub_class) : null
		], 'srv-card-main'),
		$bar !== null
			? $tag('span', $tag('span', null, null, ['style' => 'width: '.$bar.'%']), 'srv-card-bar', ['aria-hidden' => 'true'])
			: null
	], 'srv-summary-card '.$tone_class, ['type' => 'button'] + $attributes);
};

$summary_cards = $tag('div', [
	$card('Servidores', $summary['total'], 'accent active', ['data-filter' => 'all'],
		$summary['total'] > 0 ? $percent_of($summary['up'], $summary['total']).' % activos' : ''),
	$card('Activos', $summary['up'], 'ok', ['data-filter' => 'up'], '', '',
		$percent_of($summary['up'], $summary['total'])),
	$card('Sin respuesta', $summary['down'], $summary['down'] > 0 ? 'critical' : 'muted', ['data-filter' => 'down'], '', '',
		$percent_of($summary['down'], $summary['total'])),
	$card('Con alertas', $summary['problems'], $summary['problems'] > 0 ? 'critical' : 'ok',
		['data-filter' => 'problems'], '', '', $percent_of($summary['problems'], $summary['total'])),
	$card('CPU alta', $summary['cpu_alerts'], $summary['cpu_alerts'] > 0 ? 'warning' : 'ok', ['data-filter' => 'cpu'],
		'sobre '.$cpu_warning.' %'),
	$card('RAM alta', $summary['memory_alerts'], $summary['memory_alerts'] > 0 ? 'warning' : 'ok',
		['data-filter' => 'memory'], 'sobre '.$memory_warning.' %'),
	$card('Discos altos', $summary['disk_alerts'], $summary['disk_alerts'] > 0 ? 'warning' : 'ok',
		['data-filter' => 'disk'], 'sobre '.$data['disk_warning'].' %')
], 'srv-summary');

// Barra de herramientas --------------------------------------------------------------------------------------------

$resource_total = static fn(string $value, string $label): CTag =>
	$tag('span', [$tag('strong', $value), $label], 'srv-resource-total');

$toolbar = $tag('div', [
	$tag('div', [
		$tag('div', $tag('input', null, 'srv-search', [
			'type' => 'search',
			'placeholder' => 'Buscar servidor, IP o sistema operativo…',
			'aria-label' => 'Buscar servidores',
			'autocomplete' => 'off'
		]), 'srv-search-wrap'),
		$tag('select', [
			$tag('option', 'Todos los estados', null, ['value' => 'all']),
			$tag('option', 'Activos', null, ['value' => 'up']),
			$tag('option', 'Sin respuesta', null, ['value' => 'down']),
			$tag('option', 'Con alertas', null, ['value' => 'problems']),
			$tag('option', 'CPU alta', null, ['value' => 'cpu']),
			$tag('option', 'RAM alta', null, ['value' => 'memory']),
			$tag('option', 'Discos altos', null, ['value' => 'disk'])
		], 'srv-state-filter', ['aria-label' => 'Filtrar por estado']),
		$tag('span', 'Ordenar', 'srv-sort-label'),
		$tag('select', [
			$tag('option', 'Mayor consumo', null, ['value' => 'consumption']),
			$tag('option', 'Prioridad / alertas', null, ['value' => 'risk'])
		], 'srv-sort-field', ['aria-label' => 'Ordenar servidores por']),
		$tag('button', '↓ Mayor a menor', 'srv-sort-direction', [
			'type' => 'button', 'title' => 'Invertir el orden'
		])
	], 'srv-filter-tools'),
	$tag('div', [
		$resource_total((string) $summary['linux'], 'Linux'),
		$resource_total((string) $summary['windows'], 'Windows')
	], 'srv-resource-totals')
], 'srv-toolbar');

// Pestaña única (el JS mantiene el mismo modelo de paneles que el widget VMware) ------------------------------------

$tabs = $tag('div', [
	$tag('button', ['Servidores', $tag('span', (string) $summary['total'], 'srv-tab-count'),
		$summary['problems'] > 0 ? $tag('span', null, 'srv-tab-alert', ['title' => 'Hay servidores con alertas']) : null
	], 'srv-tab active', [
		'type' => 'button', 'data-tab' => 'servers', 'role' => 'tab', 'aria-selected' => 'true'
	])
], 'srv-tabs', ['role' => 'tablist']);

// Tabla de servidores ----------------------------------------------------------------------------------------------

$tbody = $tag('tbody', null, null, ['data-table-body' => 'servers']);

foreach ($data['servers'] as $server) {
	$network = $server['network'] ?? [];

	$host_badges = [];
	if (!$server['enabled']) {
		$host_badges[] = $tag('span', 'Deshabilitado', 'srv-inline-badge muted');
	}
	if ($server['maintenance']) {
		$host_badges[] = $tag('span', 'Mantenimiento', 'srv-inline-badge warning');
	}

	$disk_cells = [];
	foreach ($server['disk_badges'] as $disk) {
		$disk_cells[] = $tag('button', $disk['label'], 'srv-disk '.($disk['status'] ?? 'unknown'), [
			'type' => 'button',
			'data-disk' => '1',
			'data-hostid' => $server['hostid'],
			'data-host' => $server['name'],
			'data-fs' => $disk['filesystem'],
			'title' => $disk['filesystem'].' · uso '.($disk['used_percent'] ?? '—')
		]);
	}
	if ($server['hidden_disks'] > 0) {
		$disk_cells[] = $tag('button', '+'.$server['hidden_disks'], 'srv-more-disks', [
			'type' => 'button',
			'data-server-details' => '1',
			'data-hostid' => $server['hostid'],
			'data-host' => $server['name'],
			'title' => 'Ver los '.count($server['disks']).' sistemas de archivos descubiertos'
		]);
	}
	if (!$disk_cells) {
		$disk_cells[] = $tag('span', 'Sin datos de disco', 'srv-no-disks');
	}

	$disk_values = array_filter(array_map(static fn(array $disk): ?float => $disk['used_percent_raw'] ?? null,
		$server['disk_badges']), static fn(?float $value): bool => $value !== null);
	$disk_max = $disk_values ? max($disk_values) : null;
	$consumption_values = array_filter([$server['cpu_raw'], $server['memory_percent_raw'], $disk_max],
		static fn($value): bool => $value !== null);
	$consumption = $consumption_values ? max($consumption_values) : null;

	$net_total = ($network['total_in_raw'] ?? 0) + ($network['total_out_raw'] ?? 0);

	$cells = [
		$tag('td', [
			$tag('button', $server['name'], 'srv-resource-link', [
				'type' => 'button', 'data-server-details' => '1', 'data-hostid' => $server['hostid'],
				'data-host' => $server['name'], 'title' => $server['name']
			]),
			$tag('div', array_merge([$tag('span', $server['ip'], 'srv-subtext srv-mono')], $host_badges), 'srv-host-meta')
		], 'srv-host-cell'),
		$tag('td', $server['agent_ping'] === 1 ? $status('Activo', 'ok') : $status('Sin respuesta', 'critical'))
	];

	if ($has('os')) {
		$cells[] = $tag('td', $server['os_family'] !== '—' && $server['os_family'] !== ''
			? $tag('span', $server['os_family'], 'srv-os-badge '.mb_strtolower($server['os_family']),
				['title' => $server['os']])
			: $tag('span', '—', 'srv-subtext', ['title' => $server['os'] ?: 'Sistema operativo no reportado']));
	}
	if ($has('cpu')) {
		$cells[] = $tag('td', $metric($server['cpu_raw'], $server['cpu'], '', $cpu_warning, $cpu_critical), 'srv-bar-cell');
	}
	if ($has('memory')) {
		$cells[] = $tag('td', $metric($server['memory_percent_raw'], $server['memory_percent'],
			$server['memory_used'].' / '.$server['memory_total'], $memory_warning, $memory_critical), 'srv-bar-cell');
	}
	if ($has('disk')) {
		$cells[] = $tag('td', $tag('div', $disk_cells, 'srv-disk-list'), 'srv-disk-cell');
	}
	if ($has('uptime')) {
		$cells[] = $tag('td', $server['uptime'], 'srv-num srv-nowrap');
	}
	if ($has('network')) {
		$cells[] = $tag('td', [
			$tag('span', '↓ '.($network['total_in'] ?? '—'), 'srv-primary-text'),
			$tag('span', '↑ '.($network['total_out'] ?? '—'), 'srv-subtext')
		], 'srv-nowrap');
	}
	if ($has('problems')) {
		$cells[] = $tag('td', $server['problems'] > 0
			? $tag('button', (string) $server['problems'],
				'srv-problem-count'.($server['zabbix_problems'] > 0 ? '' : ' warning'), [
				'type' => 'button', 'data-server-details' => '1', 'data-hostid' => $server['hostid'],
				'data-host' => $server['name'],
				'title' => $server['zabbix_problems'].' problema(s) Zabbix · '.$server['resource_alerts']
					.' advertencia(s) de recursos'
			])
			: $tag('span', '0', 'srv-zero-problems'), 'srv-center');
	}
	if ($has('last_check')) {
		$cells[] = $tag('td', $server['last_update'], 'srv-nowrap srv-subtext');
	}
	$cells[] = $tag('td', $tag('button', 'Ver', 'srv-details-button', [
		'type' => 'button', 'data-server-details' => '1', 'data-hostid' => $server['hostid'],
		'data-host' => $server['name'], 'title' => $server['item_count'].' ítems · última actualización '.$server['last_update']
	]), 'srv-center');

	$tbody->addItem($tag('tr', $cells, ($server['row_tone'] ?? '') !== '' ? 'srv-row-'.$server['row_tone'] : null, [
		'data-row' => '1',
		'data-hostid' => $server['hostid'],
		'data-state' => $server['agent_ping'] === 1 ? 'up' : 'down',
		'data-problems' => $server['problems'] > 0 ? '1' : '0',
		'data-cpu-alert' => $server['cpu_alert'] ? '1' : '0',
		'data-memory-alert' => $server['memory_alert'] ? '1' : '0',
		'data-disk-alert' => $server['disk_alert'] ? '1' : '0',
		'data-sort-risk' => $server['risk_score'],
		'data-sort-consumption' => $sort_value($consumption),
		'data-sort-name' => mb_strtolower($server['name']),
		'data-sort-state' => $server['agent_ping'],
		'data-sort-os' => $sort_value($server['os_family'] !== '—' ? mb_strtolower($server['os_family'].' '.$server['os']) : ''),
		'data-sort-cpu' => $sort_value($server['cpu_raw']),
		'data-sort-memory' => $sort_value($server['memory_percent_raw']),
		'data-sort-disk' => $sort_value($disk_max),
		'data-sort-uptime' => $sort_value($server['uptime_raw']),
		'data-sort-network' => $sort_value($net_total > 0 ? $net_total : null),
		'data-sort-alerts' => $server['problems'],
		'data-search' => implode(' ', [
			$server['name'], $server['ip'], $server['os'], implode(' ', array_column($server['disks'], 'filesystem'))
		])
	]));
}

$head = [
	$th('Servidor', 'name', 'text'),
	$th('Estado', 'state')
];
if ($has('os')) $head[] = $th('SO', 'os', 'text');
if ($has('cpu')) $head[] = $th('CPU', 'cpu');
if ($has('memory')) $head[] = $th('RAM', 'memory');
if ($has('disk')) $head[] = $th('Discos', 'disk');
if ($has('uptime')) $head[] = $th('Uptime', 'uptime', 'number', 'srv-num');
if ($has('network')) $head[] = $th('Red', 'network');
if ($has('problems')) $head[] = $th('Alertas', 'alerts', 'number', 'srv-center');
if ($has('last_check')) $head[] = $th('Última lectura');
$head[] = $th('', null, 'number', 'srv-center', ['aria-label' => 'Detalle']);

$table = $tag('table', [$tag('thead', $tag('tr', $head)), $tbody], 'srv-table');

// Estado vacío, paginación y montaje -------------------------------------------------------------------------------

$empty = $data['servers'] ? null : $tag('div', [
	$tag('strong', 'No se encontraron servidores.'),
	$tag('span', 'Verifica que los hosts del grupo seleccionado tengan datos del Zabbix Agent (ítem agent.ping) '
		.'y que no estén filtrados por mantenimiento o apagado.')
], 'srv-empty');

$pagination = $tag('div', [
	$tag('span', '', 'srv-page-info', ['aria-live' => 'polite']),
	$tag('div', [
		$tag('button', '‹ Anterior', 'srv-page-button', ['type' => 'button', 'data-page-prev' => '1']),
		$tag('span', '', 'srv-page-number'),
		$tag('button', 'Siguiente ›', 'srv-page-button', ['type' => 'button', 'data-page-next' => '1'])
	], 'srv-page-actions')
], 'srv-pagination', ['data-pagination' => 'servers']);

$panels = $tag('div', [
	$tag('div', [
		$tag('div', $table, 'srv-table-scroll'),
		$empty,
		$pagination
	], 'srv-panel active', ['data-panel' => 'servers', 'role' => 'tabpanel'])
], 'srv-panels');

$footer = $tag('div', [
	$tag('span', 'Actualizado '.$data['generated'])
], 'srv-copyright');

$root = $tag('div', [
	$summary_cards,
	$toolbar,
	$tabs,
	$panels,
	$footer
], 'srv-overview-root', [
	'data-rows-per-page' => $data['rows_per_page'],
	'data-disk-warning' => $data['disk_warning'],
	'data-disk-critical' => $data['disk_critical'],
	'data-cpu-warning' => $data['cpu_warning'],
	'data-cpu-critical' => $data['cpu_critical'],
	'data-memory-warning' => $data['memory_warning'],
	'data-memory-critical' => $data['memory_critical'],
	'data-diagnostic-token' => $data['diagnostic_token'],
	'data-can-scan-ports' => $data['can_scan_ports'] ? '1' : '0'
]);

(new CWidgetView($data))
	->addItem($root)
	->show();
