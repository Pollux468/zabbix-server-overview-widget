<?php declare(strict_types = 0);

namespace Modules\ServerOverview\Includes;

use Zabbix\Widgets\{
	CWidgetField,
	CWidgetForm
};

use Zabbix\Widgets\Fields\{
	CWidgetFieldCheckBox,
	CWidgetFieldCheckBoxList,
	CWidgetFieldIntegerBox,
	CWidgetFieldMultiSelectGroup
};

class WidgetForm extends CWidgetForm {

	public const DEFAULT_DISK_WARNING = 80;
	public const DEFAULT_DISK_CRITICAL = 90;
	public const DEFAULT_CPU_WARNING = 75;
	public const DEFAULT_CPU_CRITICAL = 90;
	public const DEFAULT_MEMORY_WARNING = 80;
	public const DEFAULT_MEMORY_CRITICAL = 90;
	public const DEFAULT_ROWS_PER_PAGE = 25;

	// CWidgetFieldCheckBoxList solo admite claves enteras.
	public const COLUMN_OS = 0;
	public const COLUMN_CPU = 1;
	public const COLUMN_MEMORY = 2;
	public const COLUMN_DISK = 3;
	public const COLUMN_UPTIME = 4;
	public const COLUMN_NETWORK = 5;
	public const COLUMN_PROBLEMS = 6;
	public const COLUMN_LAST_CHECK = 7;

	public const COLUMN_NAMES = [
		self::COLUMN_OS => 'os',
		self::COLUMN_CPU => 'cpu',
		self::COLUMN_MEMORY => 'memory',
		self::COLUMN_DISK => 'disk',
		self::COLUMN_UPTIME => 'uptime',
		self::COLUMN_NETWORK => 'network',
		self::COLUMN_PROBLEMS => 'problems',
		self::COLUMN_LAST_CHECK => 'last_check'
	];

	public function addFields(): self {
		return $this
			->addField(
				new CWidgetFieldMultiSelectGroup('groupids', _('Host groups'))
			)
			->addField(
				(new CWidgetFieldIntegerBox('rows_per_page', _('Rows per page'), 5, 100))
					->setDefault(self::DEFAULT_ROWS_PER_PAGE)
					->setFlags(CWidgetField::FLAG_NOT_EMPTY | CWidgetField::FLAG_LABEL_ASTERISK)
			)
			->addField(
				new CWidgetFieldCheckBox('show_maintenance', _('Show hosts in maintenance'))
			)
			->addField(
				new CWidgetFieldCheckBox('show_powered_off', _('Show powered off / unreachable hosts'))
			)
			->addField(
				(new CWidgetFieldIntegerBox('disk_warning', _('Disk usage warning threshold (%)'), 1, 99))
					->setDefault(self::DEFAULT_DISK_WARNING)
					->setFlags(CWidgetField::FLAG_NOT_EMPTY | CWidgetField::FLAG_LABEL_ASTERISK)
			)
			->addField(
				(new CWidgetFieldIntegerBox('disk_critical', _('Disk usage critical threshold (%)'), 2, 100))
					->setDefault(self::DEFAULT_DISK_CRITICAL)
					->setFlags(CWidgetField::FLAG_NOT_EMPTY | CWidgetField::FLAG_LABEL_ASTERISK)
			)
			->addField(
				(new CWidgetFieldIntegerBox('cpu_warning', _('CPU usage warning threshold (%)'), 1, 99))
					->setDefault(self::DEFAULT_CPU_WARNING)
					->setFlags(CWidgetField::FLAG_NOT_EMPTY | CWidgetField::FLAG_LABEL_ASTERISK)
			)
			->addField(
				(new CWidgetFieldIntegerBox('cpu_critical', _('CPU usage critical threshold (%)'), 2, 100))
					->setDefault(self::DEFAULT_CPU_CRITICAL)
					->setFlags(CWidgetField::FLAG_NOT_EMPTY | CWidgetField::FLAG_LABEL_ASTERISK)
			)
			->addField(
				(new CWidgetFieldIntegerBox('memory_warning', _('Memory usage warning threshold (%)'), 1, 99))
					->setDefault(self::DEFAULT_MEMORY_WARNING)
					->setFlags(CWidgetField::FLAG_NOT_EMPTY | CWidgetField::FLAG_LABEL_ASTERISK)
			)
			->addField(
				(new CWidgetFieldIntegerBox('memory_critical', _('Memory usage critical threshold (%)'), 2, 100))
					->setDefault(self::DEFAULT_MEMORY_CRITICAL)
					->setFlags(CWidgetField::FLAG_NOT_EMPTY | CWidgetField::FLAG_LABEL_ASTERISK)
			)
			->addField(
				(new CWidgetFieldCheckBoxList('columns', _('Columns to display'), [
					self::COLUMN_OS => _('OS'),
					self::COLUMN_CPU => _('CPU'),
					self::COLUMN_MEMORY => _('Memory'),
					self::COLUMN_DISK => _('Disk'),
					self::COLUMN_UPTIME => _('Uptime'),
					self::COLUMN_NETWORK => _('Network'),
					self::COLUMN_PROBLEMS => _('Problems'),
					self::COLUMN_LAST_CHECK => _('Last check')
				]))->setDefault([
					self::COLUMN_OS, self::COLUMN_CPU, self::COLUMN_MEMORY, self::COLUMN_DISK,
					self::COLUMN_UPTIME, self::COLUMN_NETWORK
				])
			);
	}
}
