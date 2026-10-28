<?php declare(strict_types = 0);

/**
 * @var CView $this
 * @var array $data
 */

(new CWidgetFormView($data))
	->addField(new CWidgetFieldMultiSelectGroupView($data['fields']['groupids']))
	->addField(new CWidgetFieldIntegerBoxView($data['fields']['rows_per_page']))
	->addField(new CWidgetFieldCheckBoxView($data['fields']['show_maintenance']))
	->addField(new CWidgetFieldCheckBoxView($data['fields']['show_powered_off']))
	->addField(new CWidgetFieldCheckBoxListView($data['fields']['columns']))
	->addFieldset(
		(new CWidgetFormFieldsetCollapsibleView(_('Umbrales de uso')))
			->addField(new CWidgetFieldIntegerBoxView($data['fields']['cpu_warning']))
			->addField(new CWidgetFieldIntegerBoxView($data['fields']['cpu_critical']))
			->addField(new CWidgetFieldIntegerBoxView($data['fields']['memory_warning']))
			->addField(new CWidgetFieldIntegerBoxView($data['fields']['memory_critical']))
			->addField(new CWidgetFieldIntegerBoxView($data['fields']['disk_warning']))
			->addField(new CWidgetFieldIntegerBoxView($data['fields']['disk_critical']))
	)
	->show();
