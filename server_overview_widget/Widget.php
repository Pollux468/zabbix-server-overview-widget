<?php declare(strict_types = 0);

namespace Modules\ServerOverview;

use Zabbix\Core\CWidget;

class Widget extends CWidget {

	public function getDefaultName(): string {
		return _('Server: Vista general');
	}

	public function getTranslationStrings(): array {
		return [
			'class.widget.js' => [
				'Unable to load disk information.' => _('No se pudo cargar la información del disco.'),
				'Unable to load server information.' => _('No se pudo cargar la información del servidor.'),
				'No history in the selected period.' => _('No existe historial en el periodo seleccionado.')
			]
		];
	}
}