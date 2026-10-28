/*
 * Server: Vista general — lógica de cliente.
 *
 * Basado en la estructura del widget VMware de lab24com. Compatible con la API de widgets de Zabbix 7.0
 * (CWidget: setContents, hasPadding, onDeactivate, onReferredUpdate, isReferred, broadcast).
 */
class CWidgetServerOverview extends CWidget {

	/** Criterios generales; además, cada columna con data-sort se añade al selector "Ordenar". */
	static BASE_SORT_OPTIONS = [['consumption', 'Mayor consumo'], ['risk', 'Prioridad / alertas']];

	#root = null;
	#activeTab = 'servers';
	#page = {servers: 1};
	#sort = {servers: 'consumption'};
	#sortDirection = {servers: 'desc'};
	#search = '';
	#stateFilter = 'all';
	#selectedHostid = null;

	#modal = null;
	#modalReturnFocus = null;
	#escapeHandler = null;
	#modalViewportHandler = null;
	#requestController = null;
	#chartObservers = new Set();
	#chartSequence = 0;

	hasPadding() {
		return false;
	}

	setContents(response) {
		this.#closeModal();
		super.setContents(response);

		this.#root = this._body.querySelector('.srv-overview-root');
		if (this.#root === null) {
			return;
		}

		this.#restoreControls();
		this.#bindControls();
		this.#selectTab(this.#activeTab);
		this.#syncSelection();
	}

	onDeactivate() {
		this.#closeModal();
	}

	onReferredUpdate() {
		if (this.#root !== null && this.#selectedHostid === null) {
			this.#syncSelection(true);
		}
	}

	// -----------------------------------------------------------------------------------------------------------
	// Lista principal
	// -----------------------------------------------------------------------------------------------------------

	#restoreControls() {
		const search = this.#root.querySelector('.srv-search');
		const stateFilter = this.#root.querySelector('.srv-state-filter');

		if (search) search.value = this.#search;
		if (stateFilter) stateFilter.value = this.#stateFilter;
		this.#setActiveSummary(this.#activeTab === 'servers' ? this.#stateFilter : '');
	}

	#bindControls() {
		const search = this.#root.querySelector('.srv-search');
		const stateFilter = this.#root.querySelector('.srv-state-filter');
		const sortField = this.#root.querySelector('.srv-sort-field');
		const sortDirection = this.#root.querySelector('.srv-sort-direction');

		search?.addEventListener('input', () => {
			this.#search = search.value;
			this.#page[this.#activeTab] = 1;
			this.#updateRows();
		});

		stateFilter?.addEventListener('change', () => {
			this.#stateFilter = stateFilter.value;
			this.#page[this.#activeTab] = 1;
			this.#setActiveSummary(stateFilter.value);
			this.#updateRows();
		});

		sortField?.addEventListener('change', () => {
			this.#sort[this.#activeTab] = sortField.value;
			this.#sortDirection[this.#activeTab] = this.#defaultDirection(sortField.value, this.#activeTab);
			this.#page[this.#activeTab] = 1;
			this.#syncSortControls();
			this.#updateRows();
		});

		sortDirection?.addEventListener('click', () => {
			this.#sortDirection[this.#activeTab] = this.#sortDirection[this.#activeTab] === 'desc' ? 'asc' : 'desc';
			this.#page[this.#activeTab] = 1;
			this.#syncSortControls();
			this.#updateRows();
		});

		for (const tab of this.#root.querySelectorAll('[data-tab]')) {
			tab.addEventListener('click', () => this.#selectTab(tab.dataset.tab));
		}

		// Clic en el título de columna: ordena por ella; un segundo clic invierte el sentido.
		for (const th of this.#root.querySelectorAll('th[data-sort]')) {
			th.querySelector('.srv-sort-head')?.addEventListener('click', () => {
				const tab = th.closest('[data-panel]')?.dataset.panel ?? this.#activeTab;
				const field = th.dataset.sort;
				if (this.#sort[tab] === field) {
					this.#sortDirection[tab] = this.#sortDirection[tab] === 'desc' ? 'asc' : 'desc';
				}
				else {
					this.#sort[tab] = field;
					this.#sortDirection[tab] = this.#defaultDirection(field, tab);
				}
				this.#page[tab] = 1;
				this.#syncSortControls();
				this.#updateRows();
			});
		}

		for (const card of this.#root.querySelectorAll('.srv-summary-card')) {
			card.addEventListener('click', () => {
				if (!card.dataset.filter && !card.dataset.tabTarget) return;
				if (card.dataset.tabTarget) {
					this.#selectTab(card.dataset.tabTarget);
					return;
				}

				this.#selectTab('servers');
				this.#stateFilter = card.dataset.filter;
				if (stateFilter) stateFilter.value = card.dataset.filter;
				this.#page.servers = 1;
				this.#setActiveSummary(card.dataset.filter);
				this.#updateRows();
			});
		}

		for (const panel of this.#root.querySelectorAll('[data-panel]')) {
			panel.querySelector('[data-page-prev]')?.addEventListener('click', () => {
				this.#page[panel.dataset.panel] = Math.max(1, this.#page[panel.dataset.panel] - 1);
				this.#updateRows();
			});
			panel.querySelector('[data-page-next]')?.addEventListener('click', () => {
				this.#page[panel.dataset.panel]++;
				this.#updateRows();
			});
			panel.querySelector('tbody')?.addEventListener('click', event => {
				const row = event.target.closest('tr[data-row]');
				if (row?.dataset.hostid) this.#selectHost(row.dataset.hostid, true);
			});
		}

		const bind = (selector, handler) => {
			for (const element of this.#root.querySelectorAll(selector)) {
				element.addEventListener('click', event => handler(element, event));
			}
		};

		// Sólo botones: las filas <tr> también llevan data-disk/data-cpu… como claves de ordenación.
		bind('button[data-disk]', element => this.#loadDisk(element));
		bind('button[data-server-details]', element => this.#loadServerDetails(element));
	}

	#selectTab(tabName) {
		if (!['servers'].includes(tabName)) {
			tabName = 'servers';
		}

		this.#activeTab = tabName;
		if (tabName !== 'servers') {
			const stateFilter = this.#root.querySelector('.srv-state-filter');
			this.#stateFilter = 'all';
			if (stateFilter) stateFilter.value = 'all';
			this.#setActiveSummary('');
		}
		for (const tab of this.#root.querySelectorAll('[data-tab]')) {
			const active = tab.dataset.tab === tabName;
			tab.classList.toggle('active', active);
			tab.setAttribute('aria-selected', active ? 'true' : 'false');
		}
		for (const panel of this.#root.querySelectorAll('[data-panel]')) {
			panel.classList.toggle('active', panel.dataset.panel === tabName);
		}

		this.#configureSortOptions();
		this.#updateRows();
	}

	#sortColumns(tab) {
		return [...this.#root.querySelectorAll(`[data-panel="${tab}"] th[data-sort]`)];
	}

	#sortType(field, tab) {
		const th = this.#sortColumns(tab).find(column => column.dataset.sort === field);
		return th?.dataset.sortType ?? 'number';
	}

	#defaultDirection(field, tab) {
		return this.#sortType(field, tab) === 'text' ? 'asc' : 'desc';
	}

	#configureSortOptions() {
		const select = this.#root.querySelector('.srv-sort-field');
		if (!select) return;

		const options = [...CWidgetServerOverview.BASE_SORT_OPTIONS,
			...this.#sortColumns(this.#activeTab).map(th => [th.dataset.sort, th.textContent.trim()])];
		select.replaceChildren(...options.map(([value, label]) => {
			const option = document.createElement('option');
			option.value = value;
			option.textContent = label;
			return option;
		}));
		this.#syncSortControls();
	}

	#syncSortControls() {
		const select = this.#root.querySelector('.srv-sort-field');
		if (select) select.value = this.#sort[this.#activeTab];

		const field = this.#sort[this.#activeTab];
		const descending = this.#sortDirection[this.#activeTab] === 'desc';
		const text = this.#sortType(field, this.#activeTab) === 'text';
		const button = this.#root.querySelector('.srv-sort-direction');
		if (button) {
			button.textContent = text ? (descending ? '↓ Z → A' : '↑ A → Z') : (descending ? '↓ Mayor a menor' : '↑ Menor a mayor');
			button.title = 'Invertir el orden';
		}

		for (const tab of Object.keys(this.#sort)) {
			for (const th of this.#sortColumns(tab)) {
				const active = th.dataset.sort === this.#sort[tab];
				const direction = this.#sortDirection[tab];
				th.classList.toggle('srv-sorted', active);
				th.classList.toggle('asc', active && direction === 'asc');
				th.classList.toggle('desc', active && direction === 'desc');
				th.setAttribute('aria-sort', active ? (direction === 'asc' ? 'ascending' : 'descending') : 'none');
			}
		}
	}

	#setActiveSummary(filter) {
		for (const card of this.#root.querySelectorAll('.srv-summary-card[data-filter]')) {
			card.classList.toggle('active', card.dataset.filter === filter && this.#activeTab === 'servers');
		}
	}

	#updateRows() {
		const panel = this.#root.querySelector(`[data-panel="${this.#activeTab}"]`);
		if (panel === null) return;

		const search = this.#search.trim().toLocaleLowerCase();
		const state = this.#stateFilter;
		const pageSize = Math.max(1, Number(this.#root.dataset.rowsPerPage) || 25);
		const allRows = [...panel.querySelectorAll('tbody [data-row]')];
		const sortField = this.#sort[this.#activeTab];
		const direction = this.#sortDirection[this.#activeTab] === 'desc' ? -1 : 1;
		const key = `sort${sortField.charAt(0).toUpperCase()}${sortField.slice(1)}`;
		const isText = this.#sortType(sortField, this.#activeTab) === 'text';
		const byName = (left, right) => (left.dataset.sortName ?? '').localeCompare(right.dataset.sortName ?? '',
			undefined, {numeric: true, sensitivity: 'base'});

		allRows.sort((left, right) => {
			const a = left.dataset[key] ?? '';
			const b = right.dataset[key] ?? '';
			if (a === '' || b === '') {
				// Sin dato: siempre al final, sea cual sea el sentido.
				return a === b ? byName(left, right) : (a === '' ? 1 : -1);
			}
			const result = isText
				? a.localeCompare(b, undefined, {numeric: true, sensitivity: 'base'})
				: Number(a) - Number(b);
			return result === 0 ? byName(left, right) : direction * result;
		});
		panel.querySelector('tbody')?.append(...allRows);

		const matchingRows = allRows.filter(row => {
			const matchesSearch = search === '' || (row.dataset.search ?? '').toLocaleLowerCase().includes(search);
			const matchesState = (state === 'all' || (state === 'problems' ? row.dataset.problems === '1' : row.dataset.state === state));
			return matchesSearch && matchesState;
		});

		const pages = Math.max(1, Math.ceil(matchingRows.length / pageSize));
		this.#page[this.#activeTab] = Math.min(this.#page[this.#activeTab], pages);
		const page = this.#page[this.#activeTab];
		const first = (page - 1) * pageSize;
		const last = first + pageSize;

		for (const row of allRows) row.hidden = true;
		matchingRows.slice(first, last).forEach(row => row.hidden = false);

		const pagination = panel.querySelector('[data-pagination]');
		if (pagination !== null) {
			const shownFrom = matchingRows.length === 0 ? 0 : first + 1;
			const shownTo = Math.min(last, matchingRows.length);
			const filtered = matchingRows.length !== allRows.length ? ` (filtrado de ${allRows.length})` : '';
			pagination.querySelector('.srv-page-info').textContent =
				`Mostrando ${shownFrom}–${shownTo} de ${matchingRows.length}${filtered}`;
			const number = pagination.querySelector('.srv-page-number');
			if (number) number.textContent = `${page} / ${pages}`;
			pagination.querySelector('[data-page-prev]').disabled = page <= 1;
			pagination.querySelector('[data-page-next]').disabled = page >= pages;
			pagination.hidden = allRows.length === 0;
		}
	}

	#selectHost(hostid, broadcast) {
		if (!hostid) return;

		this.#selectedHostid = String(hostid);
		for (const row of this.#root?.querySelectorAll('tr[data-row][data-hostid]') ?? []) {
			row.classList.toggle('srv-selected', row.dataset.hostid === this.#selectedHostid);
		}

		if (!broadcast) return;

		try {
			const hostType = typeof CWidgetsData !== 'undefined' ? CWidgetsData.DATA_TYPE_HOST_ID : '_hostid';
			const hostsType = typeof CWidgetsData !== 'undefined' ? CWidgetsData.DATA_TYPE_HOST_IDS : '_hostids';
			this.broadcast({[hostType]: [this.#selectedHostid], [hostsType]: [this.#selectedHostid]});
		}
		catch (error) {
			// Versiones o configuraciones sin difusión entre widgets: la selección visual sigue funcionando.
		}
	}

	#syncSelection(forceBroadcast = false) {
		const rows = [...this.#root.querySelectorAll('tbody[data-table-body="servers"] tr[data-row][data-hostid]')];
		const exists = this.#selectedHostid !== null
			&& this.#root.querySelector(`tr[data-row][data-hostid="${CSS.escape(this.#selectedHostid)}"]`) !== null;
		let referred = false;

		try {
			referred = typeof this.isReferred === 'function' && this.isReferred();
		}
		catch (error) {
			referred = false;
		}

		if (exists) {
			this.#selectHost(this.#selectedHostid, forceBroadcast);
		}
		else if (referred || forceBroadcast) {
			const first = rows.find(row => !row.hidden) ?? rows[0];
			if (first) this.#selectHost(first.dataset.hostid, true);
		}
	}

	// -----------------------------------------------------------------------------------------------------------
	// Peticiones
	// -----------------------------------------------------------------------------------------------------------

	#thresholdArguments(curl) {
		const d = this.#root.dataset;
		curl.setArgument('disk_warning', d.diskWarning || 80);
		curl.setArgument('disk_critical', d.diskCritical || 90);
		curl.setArgument('cpu_warning', d.cpuWarning || 75);
		curl.setArgument('cpu_critical', d.cpuCritical || 90);
		curl.setArgument('memory_warning', d.memoryWarning || 80);
		curl.setArgument('memory_critical', d.memoryCritical || 90);
	}

	async #request(curl, options = {}) {
		this.#requestController?.abort();
		const controller = new AbortController();
		this.#requestController = controller;

		let response;
		try {
			response = await fetch(curl.getUrl(), {
				method: 'GET', credentials: 'same-origin', headers: {'Accept': 'application/json'},
				signal: controller.signal, ...options
			});
		}
		finally {
			if (this.#requestController === controller) this.#requestController = null;
		}

		if (!response.ok) throw new Error(`El servidor respondió HTTP ${response.status}.`);

		const text = await response.text();
		let data;
		try {
			data = JSON.parse(text);
			if (typeof data === 'string') data = JSON.parse(data);
		}
		catch (error) {
			throw new Error('Respuesta no válida del servidor. La sesión pudo haber expirado: recarga la página.');
		}

		if (data?.error) {
			throw new Error(typeof data.error === 'string' ? data.error : 'Error de respuesta del servidor.');
		}

		return data;
	}

	async #load(build, render, errorText, options = {}) {
		const modal = this.#modal;
		try {
			const curl = new Curl('zabbix.php');
			build(curl);
			const data = await this.#request(curl, options);
			if (this.#modal !== modal || modal === null) return;
			render(data);
		}
		catch (error) {
			if (error?.name === 'AbortError' || this.#modal !== modal) return;
			this.#renderModalError(error?.message || errorText);
		}
	}

	// -----------------------------------------------------------------------------------------------------------
	// Cargadores de detalle
	// -----------------------------------------------------------------------------------------------------------

	#loadDiagnostic(button, preserveModal = false) {
		const operation = button.dataset.diagnostic;
		if (!preserveModal) {
			this.#openModal(button.dataset.host, `Diagnóstico: ${operation}`, `Ejecutando ${operation}…`, true);
		}

		// POST con el token CSRF nativo de Zabbix (la acción lo valida por nombre de acción).
		const form = new URLSearchParams();
		form.set('hostid', button.dataset.hostid);
		form.set('operation', operation);
		form.set('_csrf_token', this.#root.dataset.diagnosticToken || '');

		this.#load(curl => {
			curl.setArgument('action', 'widget.serveroverview.diagnostic.run');
		}, data => this.#renderDiagnostic(data, button, operation), 'No se pudo ejecutar el diagnóstico.', {
			method: 'POST',
			headers: {'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded'},
			body: form.toString()
		});
	}

	#loadDisk(button, range = 3600, preserveModal = false) {
		if (!preserveModal) {
			this.#openModal(`${button.dataset.host} · Disco ${button.textContent}`, button.dataset.fs,
				'Cargando información del disco…');
		}
		this.#load(curl => {
			curl.setArgument('action', 'widget.serveroverview.disk.get');
			curl.setArgument('hostid', button.dataset.hostid);
			curl.setArgument('fs', button.dataset.fs);
			curl.setArgument('range', range);
			this.#thresholdArguments(curl);
		}, data => this.#renderDisk(data, button), t('Unable to load disk information.'));
	}

	#loadServerDetails(button, range = 3600, preserveModal = false) {
		if (!preserveModal) {
			this.#openModal(button.dataset.host, 'Servidor · vista operativa', 'Organizando los recursos descubiertos…',
				true);
		}
		this.#load(curl => {
			curl.setArgument('action', 'widget.serveroverview.server.get');
			curl.setArgument('hostid', button.dataset.hostid);
			curl.setArgument('range', range);
			this.#thresholdArguments(curl);
		}, data => this.#renderServerDetails(data, button), t('Unable to load server information.'));
	}

	// -----------------------------------------------------------------------------------------------------------
	// Ventana
	// -----------------------------------------------------------------------------------------------------------

	#openModal(title, subtitle, loadingText, wide = false) {
		this.#closeModal();
		this.#modalReturnFocus = document.activeElement;

		const overlay = document.createElement('div');
		overlay.className = 'srv-modal-overlay';

		const titleId = `srv-modal-title-${++this.#chartSequence}`;
		const dialog = this.#el('div', `srv-modal${wide ? ' srv-vm-modal' : ''}`);
		dialog.setAttribute('role', 'dialog');
		dialog.setAttribute('aria-modal', 'true');
		dialog.setAttribute('aria-labelledby', titleId);

		const heading = this.#el('div');
		const h3 = this.#el('h3', null, title);
		h3.id = titleId;
		heading.append(h3, this.#el('span', null, subtitle || ''));

		const close = this.#el('button', 'srv-modal-close', '×');
		close.type = 'button';
		close.title = 'Cerrar (Esc)';
		close.setAttribute('aria-label', 'Cerrar');
		close.addEventListener('click', () => this.#closeModal());

		const header = this.#el('div', 'srv-modal-header');
		header.append(heading, close);

		const body = this.#el('div', 'srv-modal-body');
		body.append(this.#el('div', 'srv-loading', loadingText));

		dialog.append(header, body);
		overlay.append(dialog);
		overlay.addEventListener('mousedown', event => {
			if (event.target === overlay) this.#closeModal();
		});

		document.body.append(overlay);
		this.#modal = overlay;
		overlay.body = body;
		this.#bindModalViewport();

		this.#escapeHandler = event => {
			if (event.key === 'Escape') {
				event.stopPropagation();
				this.#closeModal();
			}
			else if (event.key === 'Tab') {
				this.#trapFocus(event, dialog);
			}
		};
		document.addEventListener('keydown', this.#escapeHandler, true);
		close.focus({preventScroll: true});
	}

	#trapFocus(event, dialog) {
		const focusable = [...dialog.querySelectorAll('button:not(:disabled), a[href], summary, [tabindex]:not([tabindex="-1"])')]
			.filter(element => element.offsetParent !== null);
		if (!focusable.length) return;

		const first = focusable[0];
		const last = focusable[focusable.length - 1];
		if (event.shiftKey && document.activeElement === first) {
			event.preventDefault();
			last.focus();
		}
		else if (!event.shiftKey && document.activeElement === last) {
			event.preventDefault();
			first.focus();
		}
	}

	#bindModalViewport() {
		const viewport = window.visualViewport;
		this.#modalViewportHandler = () => {
			if (!this.#modal) return;
			const width = Math.max(280, Math.floor(viewport?.width || window.innerWidth || document.documentElement.clientWidth));
			const height = Math.max(240, Math.floor(viewport?.height || window.innerHeight || document.documentElement.clientHeight));
			const left = Math.max(0, Math.floor(viewport?.offsetLeft || 0));
			const top = Math.max(0, Math.floor(viewport?.offsetTop || 0));
			const padding = width <= 680 ? 6 : Math.min(22, Math.max(8, Math.floor(width * .02)));
			this.#modal.style.setProperty('inset', 'auto', 'important');
			this.#modal.style.setProperty('left', `${left}px`, 'important');
			this.#modal.style.setProperty('top', `${top}px`, 'important');
			this.#modal.style.setProperty('width', `${width}px`, 'important');
			this.#modal.style.setProperty('height', `${height}px`, 'important');
			this.#modal.style.setProperty('padding', `${padding}px`, 'important');
			this.#modal.classList.toggle('srv-modal-compact', width < 760 && !CSS.supports('container-type: inline-size'));
			const dialog = this.#modal.querySelector('.srv-modal');
			if (dialog) {
				// Ventana mediana: como máximo 960 px (detalle) / 820 px (disco) y 86 % del alto visible, dejando
				// el dashboard a la vista. En pantallas pequeñas ocupa el espacio disponible.
				const cap = dialog.classList.contains('srv-vm-modal') ? 960 : 820;
				const maxWidth = Math.min(cap, width - padding * 2);
				const maxHeight = width <= 680 ? height - padding * 2 : Math.min(height - padding * 2, Math.floor(height * .86));
				dialog.style.setProperty('max-width', `${Math.max(260, maxWidth)}px`, 'important');
				dialog.style.setProperty('max-height', `${Math.max(210, maxHeight)}px`, 'important');
			}
		};
		this.#modalViewportHandler();
		window.addEventListener('resize', this.#modalViewportHandler);
		viewport?.addEventListener('resize', this.#modalViewportHandler);
		viewport?.addEventListener('scroll', this.#modalViewportHandler);
	}

	#modalBody() {
		return this.#modal?.querySelector('.srv-modal-body') ?? null;
	}

	#resetBody() {
		const body = this.#modalBody();
		if (!body) return null;
		this.#disconnectCharts();
		const scroll = body.scrollTop;
		body.replaceChildren();
		requestAnimationFrame(() => body.scrollTop = scroll);
		return body;
	}

	#renderModalError(message) {
		const body = this.#modalBody();
		if (!body) return;
		this.#disconnectCharts();
		body.replaceChildren(this.#el('div', 'srv-modal-error', message));
	}

	#disconnectCharts() {
		for (const observer of this.#chartObservers) observer.disconnect();
		this.#chartObservers.clear();
	}

	#closeModal() {
		this.#requestController?.abort();
		this.#requestController = null;
		this.#disconnectCharts();

		if (this.#escapeHandler !== null) {
			document.removeEventListener('keydown', this.#escapeHandler, true);
			this.#escapeHandler = null;
		}
		if (this.#modalViewportHandler !== null) {
			window.removeEventListener('resize', this.#modalViewportHandler);
			window.visualViewport?.removeEventListener('resize', this.#modalViewportHandler);
			window.visualViewport?.removeEventListener('scroll', this.#modalViewportHandler);
			this.#modalViewportHandler = null;
		}

		if (this.#modal !== null) {
			this.#modal.remove();
			this.#modal = null;
			if (this.#modalReturnFocus instanceof HTMLElement && this.#modalReturnFocus.isConnected) {
				this.#modalReturnFocus.focus({preventScroll: true});
			}
		}
		this.#modalReturnFocus = null;
	}

	// -----------------------------------------------------------------------------------------------------------
	// Contenido de las ventanas y utilidades de interfaz
	// -----------------------------------------------------------------------------------------------------------

	#renderDisk(data, button) {
		const {disk, history, range, warning, critical} = data;
		const modal = this.#modal;
		if (!modal) return;

		const sections = [];

		// Cabecera con gauge
		const used_percent = disk.used_percent_raw ?? 0;
		const gauge = this.#makeGauge(
			`${disk.label} (${disk.fstype})`,
			used_percent,
			disk.used_percent,
			disk.status || 'unknown',
			`${disk.used} / ${disk.total}`,
			disk.free_percent !== null ? `Libre: ${disk.free} (${disk.free_percent})` : ''
		);
		sections.push(gauge);

		// Gráfico histórico
		const pused_history = history.pused || history.pfree ? (history.pused || history.pfree.map(p => ({clock: p.clock, value: 100 - p.value}))) : null;
		if (pused_history && pused_history.length >= 2) {
			const period = this.#formatPeriod(range);
			sections.push(this.#makeChartCard({
				title: 'Uso del disco',
				period,
				units: '%',
				percent: true,
				thresholds: {warning, critical},
				series: [{label: '% usado', history: pused_history, className: disk.status === 'critical' ? 'srv-series-critical' : (disk.status === 'warning' ? 'srv-series-warning' : 'srv-series-ok')}]
			}));
		}

		// Detalles técnicos
		const details = this.#makeTechnicalDetails('Detalles del filesystem', '', [{
			name: '',
			rows: [
				{name: 'Sistema de archivos', value: disk.filesystem, key: null, last_check: '—', supported: true, error: ''},
				{name: 'Etiqueta', value: disk.label, key: null, last_check: '—', supported: true, error: ''},
				{name: 'Tipo', value: disk.fstype, key: null, last_check: '—', supported: true, error: ''},
				{name: 'Tipo de unidad', value: disk.drivetype, key: null, last_check: '—', supported: true, error: ''},
				{name: 'Total', value: disk.total, key: null, last_check: '—', supported: true, error: ''},
				{name: 'Usado', value: disk.used, key: null, last_check: '—', supported: true, error: ''},
				{name: 'Libre', value: disk.free, key: null, last_check: '—', supported: true, error: ''},
				{name: '% Usado', value: disk.used_percent, key: null, last_check: '—', supported: true, error: ''},
				{name: '% Libre', value: disk.free_percent, key: null, last_check: '—', supported: true, error: ''}
			]
		}]);
		sections.push(details);

		// Acciones
		sections.push(this.#makeActions(data, 'server'));

		modal.body.replaceChildren(...sections);
	}

	#renderServerDetails(data, button) {
		const {server, cpu_history, memory_history, disk_histories, network_histories, range} = data;
		const modal = this.#modal;
		if (!modal) return;

		const sections = [];

		// Resumen general
		const overview = this.#el('div', 'srv-modal-overview');
		const cpu_status = server.cpu_alert ? (server.cpu_raw >= (this.#root.dataset.cpuCritical || 90) ? 'critical' : 'warning') : 'ok';
		const mem_status = server.memory_alert ? (server.memory_percent_raw >= (this.#root.dataset.memoryCritical || 90) ? 'critical' : 'warning') : 'ok';
		const disk_status = server.disk_alert ? 'critical' : 'ok';

		overview.append(
			this.#makeGauge('CPU', server.cpu_raw ?? 0, server.cpu, cpu_status, `${server.cpu}`),
			this.#makeGauge('RAM', server.memory_percent_raw ?? 0, server.memory_percent, mem_status, `${server.memory_used} / ${server.memory_total}`),
			this.#makeGauge('Disco (peor)', Math.max(...server.disk_badges.map(d => d.used_percent_raw ?? 0)), server.disk_badges[0]?.used_percent ?? '—', disk_status, server.disk_badges[0]?.label ?? '—')
		);
		sections.push(overview);

		// Gráficos de recursos
		const period = this.#formatPeriod(range);

		if (cpu_history && cpu_history.length >= 2) {
			sections.push(this.#makeChartCard({
				title: 'CPU',
				period,
				units: '%',
				percent: true,
				thresholds: {warning: Number(this.#root.dataset.cpuWarning || 75), critical: Number(this.#root.dataset.cpuCritical || 90)},
				series: [{label: 'CPU %', history: cpu_history, className: 'srv-series-ok'}]
			}));
		}

		if (memory_history && memory_history.length >= 2) {
			sections.push(this.#makeChartCard({
				title: 'Memoria',
				period,
				units: '%',
				percent: true,
				thresholds: {warning: Number(this.#root.dataset.memoryWarning || 80), critical: Number(this.#root.dataset.memoryCritical || 90)},
				series: [{label: 'RAM %', history: memory_history, className: 'srv-series-ok'}]
			}));
		}

		// Gráficos de discos
		for (const [fs, hist] of Object.entries(disk_histories)) {
			const pused_hist = hist.pused || hist.pfree ? (hist.pused || hist.pfree.map(p => ({clock: p.clock, value: 100 - p.value}))) : null;
			if (pused_hist && pused_hist.length >= 2) {
				const disk = server.disk_badges.find(d => d.filesystem === fs) || server.disks.find(d => d.filesystem === fs);
				sections.push(this.#makeChartCard({
					title: `Disco ${disk?.label ?? fs}`,
					period,
					units: '%',
					percent: true,
					thresholds: {warning: Number(this.#root.dataset.diskWarning || 80), critical: Number(this.#root.dataset.diskCritical || 90)},
					series: [{label: '% usado', history: pused_hist, className: disk?.status === 'critical' ? 'srv-series-critical' : (disk?.status === 'warning' ? 'srv-series-warning' : 'srv-series-ok')}]
				}));
			}
		}

		// Gráficos de red
		for (const [iface, hist] of Object.entries(network_histories)) {
			const series = [];
			if (hist.in && hist.in.length >= 2) {
				series.push({label: 'Entrada', history: hist.in, className: 'srv-series-ok'});
			}
			if (hist.out && hist.out.length >= 2) {
				series.push({label: 'Salida', history: hist.out, className: 'srv-series-warning'});
			}
			if (series.length) {
				sections.push(this.#makeChartCard({
					title: `Red ${iface}`,
					period,
					units: 'Bps',
					percent: false,
					series
				}));
			}
		}

		// Detalles técnicos
		const tech_sections = [];

		// OS / Inventory
		const inv = server.inventory || {};
		const inv_rows = [];
		if (server.os !== '—') inv_rows.push({name: 'Sistema operativo', value: server.os, key: 'system.sw.os', last_check: '—', supported: true, error: ''});
		if (server.agent_version !== '—') inv_rows.push({name: 'Zabbix Agent', value: server.agent_version, key: 'agent.version', last_check: '—', supported: true, error: ''});
		if (server.uptime !== '—') inv_rows.push({name: 'Uptime', value: server.uptime, key: 'system.uptime', last_check: '—', supported: true, error: ''});
		if (inv.os_full) inv_rows.push({name: 'OS (inventario)', value: inv.os_full, key: null, last_check: '—', supported: true, error: ''});
		if (inv.hardware) inv_rows.push({name: 'Hardware', value: inv.hardware, key: null, last_check: '—', supported: true, error: ''});
		if (inv.serialno_a) inv_rows.push({name: 'Serial', value: inv.serialno_a, key: null, last_check: '—', supported: true, error: ''});
		if (inv.asset_tag) inv_rows.push({name: 'Asset tag', value: inv.asset_tag, key: null, last_check: '—', supported: true, error: ''});
		if (inv.tag) inv_rows.push({name: 'Tag', value: inv.tag, key: null, last_check: '—', supported: true, error: ''});

		if (inv_rows.length) {
			tech_sections.push(this.#makeTechnicalDetails('Sistema e inventario', '', [{name: '', rows: inv_rows}]));
		}

		// Discos
		if (server.disks.length) {
			const disk_rows = server.disks.map(d => ({
				name: d.label,
				value: `${d.used} / ${d.total} (${d.used_percent})`,
				key: `vfs.fs.size[${d.filesystem},pused]`,
				last_check: '—',
				supported: true,
				error: d.status || ''
			}));
			tech_sections.push(this.#makeTechnicalDetails('Filesystems', '', [{name: '', rows: disk_rows}]));
		}

		// Red
		if (server.network.interfaces.length) {
			const net_rows = server.network.interfaces.map(i => ({
				name: i.name,
				value: `↓ ${i.in ? this.#formatValue(i.in, 'Bps') : '—'}  ↑ ${i.out ? this.#formatValue(i.out, 'Bps') : '—'}  ${i.speed ? `(${this.#formatValue(i.speed, 'bps')})` : ''}`,
				key: `net.if.in[${i.name}]`,
				last_check: '—',
				supported: true,
				error: ''
			}));
			tech_sections.push(this.#makeTechnicalDetails('Interfaces de red', '', [{name: '', rows: net_rows}]));
		}

		for (const ts of tech_sections) sections.push(ts);

		// Acciones
		sections.push(this.#makeActions(server, 'server'));

		modal.body.replaceChildren(...sections);
	}

	#renderDiagnostic(data, button, operation) {
		const modal = this.#modal;
		if (!modal) return;

		const output = data.output || 'La herramienta terminó sin generar salida.';
		const header = this.#el('div', 'srv-diagnostic-header',
			`${data.host} · ${data.target} · ${data.duration} s · código de salida ${data.exit_code}`
			+ (data.timed_out ? ' · tiempo agotado' : ''));
		const pre = this.#el('pre', null, output);
		const actions = this.#el('div', 'srv-action-grid');
		const retry = this.#el('button', 'srv-action-button', `Repetir ${operation}`);
		retry.type = 'button';
		retry.addEventListener('click', () => this.#loadDiagnostic(button, true));
		actions.append(retry);
		modal.body.replaceChildren(header, pre, actions);
	}

	#el(tag, className = null, text = null) {
		const el = document.createElement(tag);
		if (className) el.className = className;
		if (text !== null) el.textContent = text;
		return el;
	}

	#makeGauge(label, raw, text, status, statusText, note) {
		const wrap = this.#el('div');
		const overview = this.#el('div', 'srv-modal-overview');
		const gauge = this.#el('div', 'srv-disk-gauge');
		const header = this.#el('div');
		header.append(this.#el('strong', null, label), this.#el('span', null, text ?? '—'));
		const track = this.#el('div', 'srv-modal-progress');
		const fill = this.#el('span', status || 'unknown');
		fill.style.width = `${Math.max(0, Math.min(100, Number(raw) || 0))}%`;
		track.append(fill);
		gauge.append(header, track);
		overview.append(gauge, this.#el('span', `srv-modal-status ${status || ''}`, statusText));
		wrap.append(overview);
		if (note) wrap.append(this.#el('div', 'srv-modal-note', note));
		return wrap;
	}

	#makeSection(title, count = null) {
		const section = this.#el('div', 'srv-resource-section');
		const heading = this.#el('h4', null, title);
		if (count !== null) heading.append(this.#el('span', 'srv-section-count', String(count)));
		section.append(heading);
		return section;
	}

	#makeDetails(title, summaryText) {
		const details = this.#el('details', 'srv-detail-category');
		const summary = this.#el('summary');
		summary.append(this.#el('strong', null, title), this.#el('span', null, summaryText));
		details.append(summary);
		return details;
	}

	#makeTable(headers, rows, mapper, rowClass = null, rowTitle = null, className = '') {
		const wrap = this.#el('div', 'srv-detail-table-wrap');
		const table = this.#el('table', `srv-detail-table ${className}`.trim());
		const head = table.createTHead().insertRow();
		for (const header of headers) {
			const th = this.#el('th', header.num ? 'srv-num' : null, header.text);
			head.append(th);
		}
		const tbody = table.createTBody();
		for (const row of rows) {
			const tr = tbody.insertRow();
			const extraClass = rowClass ? rowClass(row) : '';
			if (extraClass) tr.classList.add(extraClass);
			const title = rowTitle ? rowTitle(row) : '';
			if (title) tr.title = title;
			mapper(row).forEach((value, index) => {
				const td = tr.insertCell();
				if (headers[index]?.num) td.className = 'srv-num srv-nowrap';
				if (value instanceof Node) td.append(value);
				else td.textContent = value === null || value === undefined || value === '' ? '—' : String(value);
			});
		}
		wrap.append(table);
		return wrap;
	}

	#makeDataSection(title, rows, headers, mapper, emptyText) {
		const section = this.#makeSection(title, rows.length);
		if (!rows.length) section.append(this.#el('div', 'srv-section-empty', emptyText));
		else section.append(this.#makeTable(headers, rows, mapper));
		return section;
	}

	#makeTechnicalDetails(title, summaryText, categories) {
		const details = this.#makeDetails(title, summaryText);
		for (const category of categories) {
			const table = this.#makeTable(
				[{text: 'Ítem'}, {text: 'Valor'}, {text: 'Última comprobación'}, {text: 'Estado'}],
				category.rows,
				row => {
					const item = this.#el('div');
					item.append(this.#el('strong', null, row.name));
					if (row.key) item.append(this.#el('code', 'srv-item-key', row.key));
					return [item, row.value, row.last_check ?? '—',
						this.#el('span', row.supported ? 'srv-state-ok' : 'srv-state-bad', row.supported ? 'Con datos' : 'Sin datos')];
				},
				null,
				row => row.error || '',
				'srv-inventory-table'
			);
			if (category.name) {
				const section = this.#makeSection(category.name, category.rows.length);
				section.append(table);
				details.append(section);
			}
			else {
				details.append(table);
			}
		}
		return details;
	}

	#makeActions(data, type = 'server') {
		const section = this.#makeSection('Acciones');
		section.classList.add('srv-actions');
		const grid = this.#el('div', 'srv-action-grid');
		const rawTarget = [data.console_target, data.address, data.name, data.host]
			.map(value => String(value || '').trim()).find(value => value && value !== '—') || '';
		const target = /^[A-Za-z0-9_.:\-\[\]]+$/.test(rawTarget) ? rawTarget : '';

		const addLink = (label, href, title = '', primary = false) => {
			if (!href) return;
			const link = this.#el('a', `srv-action-button${primary ? ' primary' : ''}`, label);
			link.href = href;
			if (title) link.title = title;
			if (href.startsWith('https://')) {
				link.target = '_blank';
				link.rel = 'noopener noreferrer';
			}
			grid.append(link);
		};

		if (target) {
			const urlTarget = target.includes(':') && !target.startsWith('[') ? `[${target}]` : target;
			const uriTarget = encodeURI(urlTarget);
			if (type !== 'hypervisor') this.#addRdpDownload(grid, target, data.name);
			addLink('SSH', `ssh://${uriTarget}`, 'Requiere un cliente SSH asociado al navegador.');
			addLink('HTTPS', `https://${uriTarget}/`, 'Abre la administración web en una pestaña nueva.');
			grid.append(this.#copyButton('Copiar IP / nombre', target));
		}
		addLink('Últimos datos', data.latest_url, 'Abre Últimos datos filtrado por este host.', true);
		addLink('Problemas', data.problems_url, 'Abre Problemas filtrado por este host.');
		addLink('Dashboard del host', data.host_url, 'Abre el dashboard de host de Zabbix.');

		if (!grid.children.length) {
			grid.append(this.#el('span', 'srv-section-empty', 'No hay un destino o enlaces disponibles.'));
		}
		section.append(grid);

		if (target) {
			const diagnostics = this.#el('details', 'srv-diagnostics');
			diagnostics.append(this.#el('summary', null, 'Diagnóstico de red desde el servidor Zabbix'));
			const diagnosticGrid = this.#el('div', 'srv-action-grid');
			const output = this.#el('div', 'srv-diagnostic-output');
			output.setAttribute('aria-live', 'polite');
			const addDiagnostic = (label, operation) => {
				if (!data.hostid) return;
				const btn = this.#el('button', 'srv-action-button', label);
				btn.type = 'button';
				btn.dataset.diagnostic = operation;
				btn.dataset.host = data.host || data.name;
				btn.dataset.hostid = data.hostid;
				btn.addEventListener('click', () => this.#loadDiagnostic(btn, true));
				diagnosticGrid.append(btn);
			};
			addDiagnostic('Ping', 'ping');
			addDiagnostic('Traceroute', 'traceroute');
			if (this.#root.dataset.canScanPorts === '1') addDiagnostic('Escanear puertos (Nmap)', 'nmap');
			if (!diagnosticGrid.children.length) {
				diagnosticGrid.append(this.#el('span', 'srv-section-empty',
					'El host debe estar visible en Zabbix para ejecutar diagnósticos.'));
			}
			diagnostics.append(diagnosticGrid, output);
			section.append(diagnostics);
		}

		section.append(this.#el('p', 'srv-action-note',
			'Los diagnósticos se ejecutan en el servidor del frontend de Zabbix y su salida se muestra aquí.'));
		return section;
	}

	#addRdpDownload(grid, host, name) {
		const button = this.#el('button', 'srv-action-button', 'Conexión RDP');
		button.type = 'button';
		button.title = 'Descarga un archivo .rdp para este equipo';
		button.addEventListener('click', () => {
			const content = ['screen mode id:i:2', 'use multimon:i:0', `full address:s:${host}`,
				'prompt for credentials:i:1', 'authentication level:i:2', 'redirectclipboard:i:1'].join('\r\n');
			const url = URL.createObjectURL(new Blob([content], {type: 'application/x-rdp'}));
			const anchor = document.createElement('a');
			anchor.href = url;
			anchor.download = `${String(name || 'server').replace(/[^A-Za-z0-9_.-]+/g, '_')}.rdp`;
			document.body.append(anchor);
			anchor.click();
			anchor.remove();
			setTimeout(() => URL.revokeObjectURL(url), 1000);
		});
		grid.append(button);
	}

	#copyButton(label, value) {
		const button = this.#el('button', 'srv-action-button', label);
		button.type = 'button';
		button.title = value;
		button.addEventListener('click', async () => {
			try {
				await navigator.clipboard.writeText(value);
			}
			catch (error) {
				const input = document.createElement('textarea');
				input.value = value;
				input.style.position = 'fixed';
				input.style.opacity = '0';
				document.body.append(input);
				input.select();
				document.execCommand('copy');
				input.remove();
			}
			button.textContent = 'Copiado';
			button.classList.add('copied');
			setTimeout(() => {
				button.textContent = label;
				button.classList.remove('copied');
			}, 1600);
		});
		return button;
	}

	#formatPeriod(seconds) {
		if (seconds >= 86400) return `${seconds / 86400} d`;
		if (seconds >= 3600) return `${seconds / 3600} h`;
		return `${seconds / 60} min`;
	}

	#formatValue(value, units = '', compact = false) {
		if (value === null || value === undefined || Number.isNaN(Number(value))) return '—';
		const number = Number(value);
		const raw = String(units || '');
		const unit = raw.replace(/^!/, '');
		const decimals = abs => abs === 0 ? 0 : (abs < 10 ? 2 : (abs < 100 ? 1 : 0));

		if (unit === '%') {
			return `${number.toFixed(compact ? (Math.abs(number) < 10 ? 1 : 0) : (Math.abs(number) < 10 ? 2 : 1))} %`;
		}

		if (unit === 'ms' || unit === 's') {
			const ms = unit === 's' ? number * 1000 : number;
			if (!raw.startsWith('!') && Math.abs(ms) >= 1000) return `${(ms / 1000).toFixed(2)} s`;
			return `${ms.toFixed(compact ? 0 : decimals(Math.abs(ms)))} ms`;
		}

		const binary = unit === 'B' || unit === 'Bps';
		const base = binary ? 1024 : 1000;
		const prefixes = ['', 'K', 'M', 'G', 'T', 'P'];
		let scaled = Math.abs(number);
		let index = 0;
		if (!raw.startsWith('!')) {
			while (scaled >= base && index < prefixes.length - 1) {
				scaled /= base;
				index++;
			}
		}
		const signed = number < 0 ? -scaled : scaled;
		const text = signed.toFixed(compact ? (scaled < 10 && scaled !== 0 && Math.round(scaled) !== scaled ? 1 : 0) : decimals(scaled));
		const suffix = unit === 'iops'
			? `${prefixes[index]} IOPS`
			: (unit ? ` ${prefixes[index]}${unit}` : (prefixes[index] ? ` ${prefixes[index]}` : ''));
		return `${text}${suffix}`;
	}

	// -----------------------------------------------------------------------------------------------------------
	// Gráficos
	// -----------------------------------------------------------------------------------------------------------

	#makeChartCard(options) {
		const section = this.#el('div', 'srv-chart-section');
		const head = this.#el('div', 'srv-chart-title');
		head.append(this.#el('strong', null, options.title), this.#el('span', null, options.period ?? ''));
		section.append(head);

		const series = (options.series || [])
			.map(item => ({...item, history: (item.history || [])
				.map(point => ({clock: Number(point.clock), value: Number(point.value)}))
				.filter(point => Number.isFinite(point.clock) && Number.isFinite(point.value))
				.sort((a, b) => a.clock - b.clock)}))
			.filter(item => item.history.length >= 2);

		if (!series.length) {
			section.append(this.#el('div', 'srv-chart-empty', t('No history in the selected period.')));
			return section;
		}

		const canvas = this.#el('div', 'srv-chart-canvas');
		if (options.height) canvas.style.height = `${options.height}px`;
		section.append(canvas, this.#makeLegend(series, options.units));

		let lastWidth = 0;
		const draw = () => {
			const width = Math.floor(canvas.clientWidth);
			if (width < 50 || width === lastWidth) return;
			lastWidth = width;
			this.#drawChart(canvas, series, {...options, width, height: canvas.clientHeight || 200});
		};

		if (typeof ResizeObserver !== 'undefined') {
			const observer = new ResizeObserver(() => draw());
			observer.observe(canvas);
			this.#chartObservers.add(observer);
		}
		requestAnimationFrame(draw);

		return section;
	}

	#makeLegend(series, units) {
		const table = this.#el('table', 'srv-chart-legend');
		const head = table.createTHead().insertRow();
		for (const text of ['', 'últ.', 'mín.', 'prom.', 'máx.']) head.append(this.#el('th', null, text));
		const body = table.createTBody();
		for (const item of series) {
			const values = item.history.map(point => point.value);
			const avg = values.reduce((sum, value) => sum + value, 0) / values.length;
			const row = body.insertRow();
			const name = row.insertCell();
			name.className = item.className || 'srv-series-1';
			name.append(this.#el('i'), document.createTextNode(item.label));
			for (const value of [values[values.length - 1], Math.min(...values), avg, Math.max(...values)]) {
				row.insertCell().textContent = this.#formatValue(value, units);
			}
		}
		return table;
	}

	#drawChart(canvas, series, options) {
		const ns = 'http://www.w3.org/2000/svg';
		const make = (tag, attributes = {}, className = null) => {
			const element = document.createElementNS(ns, tag);
			for (const [name, value] of Object.entries(attributes)) element.setAttribute(name, value);
			if (className) element.setAttribute('class', className);
			return element;
		};

		const {width, height, units, percent, thresholds} = options;
		const all = series.flatMap(item => item.history);
		const minClock = Math.min(...all.map(point => point.clock));
		const maxClock = Math.max(...all.map(point => point.clock));
		const span = Math.max(1, maxClock - minClock);

		let yMax;
		if (percent) {
			yMax = 100;
		}
		else {
			const dataMax = Math.max(0, ...all.map(point => point.value));
			yMax = dataMax > 0 ? dataMax * 1.08 : 1;
		}
		const tickCount = Math.max(3, Math.min(5, Math.floor(height / 45)));
		let yTicks;
		if (percent) {
			yTicks = [0, 25, 50, 75, 100];
		}
		else if (/^!?(B|Bps)$/.test(String(units || ''))) {
			// Unidades binarias: marcas redondas en KB/MB/GB (base 1024), igual que convertUnits() de Zabbix.
			const scale = 1024 ** Math.max(0, Math.floor(Math.log(yMax) / Math.log(1024)));
			yTicks = this.#niceTicks(yMax / scale, tickCount).map(value => value * scale);
		}
		else {
			yTicks = this.#niceTicks(yMax, tickCount);
		}
		yMax = yTicks[yTicks.length - 1];

		const tickLabels = yTicks.map(value => percent ? `${value}%` : this.#formatValue(value, units, true));
		const left = Math.ceil(Math.max(...tickLabels.map(label => label.length)) * 5.6) + 10;
		const right = percent && thresholds ? 30 : 10;
		const top = 8;
		const bottom = 22;
		const plotWidth = Math.max(10, width - left - right);
		const plotHeight = Math.max(10, height - top - bottom);
		const xOf = clock => left + (clock - minClock) / span * plotWidth;
		const yOf = value => top + plotHeight - Math.max(0, Math.min(yMax, value)) / yMax * plotHeight;

		const svg = make('svg', {
			viewBox: `0 0 ${width} ${height}`, width, height, role: 'img',
			'aria-label': `${options.title}: ${series.map(item => item.label).join(', ')}`
		}, 'srv-history-chart');

		// Rejilla y eje Y
		yTicks.forEach((value, index) => {
			const y = Math.round(yOf(value)) + .5;
			svg.append(make('line', {x1: left, x2: left + plotWidth, y1: y, y2: y}, index === 0 ? 'axis' : 'grid'));
			const label = make('text', {x: left - 6, y: y + 3.5, 'text-anchor': 'end'});
			label.textContent = tickLabels[index];
			svg.append(label);
		});

		// Eje X con marcas de tiempo alineadas
		for (const {clock, text} of this.#timeTicks(minClock, maxClock, plotWidth)) {
			const x = Math.round(xOf(clock)) + .5;
			svg.append(make('line', {x1: x, x2: x, y1: top, y2: top + plotHeight}, 'grid'));
			const label = make('text', {x, y: height - 6, 'text-anchor': 'middle'});
			label.textContent = text;
			svg.append(label);
		}

		// Umbrales
		if (percent && thresholds) {
			for (const [value, kind] of [[thresholds.warning, 'warning'], [thresholds.critical, 'critical']]) {
				if (value === undefined || value === null || Number(value) <= 0 || Number(value) >= 100) continue;
				const y = Math.round(yOf(Number(value))) + .5;
				svg.append(make('line', {x1: left, x2: left + plotWidth, y1: y, y2: y}, `threshold ${kind}`));
				const label = make('text', {x: left + plotWidth + 4, y: y + 3}, `threshold-label ${kind}`);
				label.textContent = `${value}%`;
				svg.append(label);
			}
		}

		// Series
		const defs = make('defs');
		svg.append(defs);
		const clipId = `srv-clip-${++this.#chartSequence}`;
		const clip = make('clipPath', {id: clipId});
		clip.append(make('rect', {x: left, y: top - 1, width: plotWidth, height: plotHeight + 2}));
		defs.append(clip);
		const plot = make('g', {'clip-path': `url(#${clipId})`});
		svg.append(plot);

		const prepared = series.map(item => {
			const buckets = this.#downsample(item.history, plotWidth, minClock, span);
			return {...item, buckets, segments: this.#segments(buckets)};
		});

		for (const item of prepared) {
			const seriesClass = item.className || 'srv-series-1';
			const gradientId = `srv-grad-${++this.#chartSequence}`;
			const gradient = make('linearGradient', {id: gradientId, x1: 0, y1: 0, x2: 0, y2: 1}, seriesClass);
			const stopTop = make('stop', {offset: '0%'});
			stopTop.style.stopColor = 'var(--srv-series-color)';
			stopTop.style.stopOpacity = prepared.length > 1 ? '.10' : '.22';
			const stopBottom = make('stop', {offset: '100%'});
			stopBottom.style.stopColor = 'var(--srv-series-color)';
			stopBottom.style.stopOpacity = '0';
			gradient.append(stopTop, stopBottom);
			defs.append(gradient);

			const group = make('g', {}, seriesClass);
			for (const segment of item.segments) {
				if (segment.some(point => point.min !== point.max)) {
					const upper = segment.map(point => `${xOf(point.clock).toFixed(1)},${yOf(point.max).toFixed(1)}`);
					const lower = segment.slice().reverse().map(point => `${xOf(point.clock).toFixed(1)},${yOf(point.min).toFixed(1)}`);
					group.append(make('polygon', {points: [...upper, ...lower].join(' ')}, 'series-range'));
				}
				const line = segment.map(point => `${xOf(point.clock).toFixed(1)},${yOf(point.value).toFixed(1)}`);
				if (segment.length > 1) {
					const baseline = yOf(0).toFixed(1);
					const area = make('polygon', {
						points: `${xOf(segment[0].clock).toFixed(1)},${baseline} ${line.join(' ')} `
							+ `${xOf(segment[segment.length - 1].clock).toFixed(1)},${baseline}`,
						fill: `url(#${gradientId})`
					}, 'area');
					group.append(area);
					group.append(make('polyline', {points: line.join(' ')}, 'series'));
				}
				else {
					group.append(make('circle', {cx: xOf(segment[0].clock), cy: yOf(segment[0].value), r: 1.8}, 'series'));
				}
			}
			plot.append(group);
		}

		canvas.replaceChildren(svg);
		this.#attachChartTooltip(canvas, svg, prepared, {left, top, plotWidth, plotHeight, minClock, span, xOf, yOf,
			units, width, make});
	}

	#attachChartTooltip(canvas, svg, series, geometry) {
		const {make, xOf, yOf} = geometry;
		const crosshair = make('line', {y1: geometry.top, y2: geometry.top + geometry.plotHeight}, 'srv-chart-crosshair');
		svg.append(crosshair);
		const markers = series.map(item => {
			const marker = make('circle', {r: 3.5}, `srv-chart-marker ${item.className || 'srv-series-1'}`);
			svg.append(marker);
			return marker;
		});
		const tooltip = this.#el('div', 'srv-chart-tooltip');
		canvas.append(tooltip);

		const nearest = (history, target) => {
			let low = 0;
			let high = history.length - 1;
			while (low < high) {
				const middle = (low + high) >> 1;
				if (history[middle].clock < target) low = middle + 1;
				else high = middle;
			}
			return low > 0 && Math.abs(history[low - 1].clock - target) < Math.abs(history[low].clock - target)
				? history[low - 1]
				: history[low];
		};

		const hide = () => canvas.classList.remove('tooltip-visible');
		svg.addEventListener('pointerleave', hide);
		svg.addEventListener('pointermove', event => {
			const rect = svg.getBoundingClientRect();
			if (!rect.width) return;
			const x = Math.max(geometry.left, Math.min(geometry.left + geometry.plotWidth,
				(event.clientX - rect.left) * (geometry.width / rect.width)));
			const target = geometry.minClock + (x - geometry.left) / geometry.plotWidth * geometry.span;

			const points = series.map((item, index) => {
				const point = nearest(item.history, target);
				markers[index].setAttribute('cx', xOf(point.clock));
				markers[index].setAttribute('cy', yOf(point.value));
				return {item, point};
			});
			const snapX = xOf(points[0].point.clock);
			crosshair.setAttribute('x1', snapX);
			crosshair.setAttribute('x2', snapX);

			tooltip.replaceChildren(this.#el('strong', null, this.#formatDateTime(points[0].point.clock)));
			for (const {item, point} of points) {
				const row = this.#el('span', item.className || 'srv-series-1');
				const name = this.#el('span');
				name.append(this.#el('i'), document.createTextNode(item.label));
				row.append(name, this.#el('b', null, this.#formatValue(point.value, geometry.units)));
				tooltip.append(row);
			}

			canvas.classList.add('tooltip-visible');
			const localX = event.clientX - canvas.getBoundingClientRect().left;
			const tooltipWidth = tooltip.offsetWidth || 170;
			const leftPosition = localX + 14 + tooltipWidth > canvas.clientWidth ? localX - tooltipWidth - 14 : localX + 14;
			tooltip.style.left = `${Math.max(0, leftPosition)}px`;
			tooltip.style.top = `${Math.max(0, event.clientY - canvas.getBoundingClientRect().top - tooltip.offsetHeight - 8)}px`;
		});
	}

	#downsample(history, plotWidth, minClock, span) {
		const target = Math.max(2, Math.floor(plotWidth));
		if (history.length <= target) {
			return history.map(point => ({clock: point.clock, value: point.value, min: point.value, max: point.value}));
		}
		const buckets = new Map();
		for (const point of history) {
			const index = Math.min(target - 1, Math.floor((point.clock - minClock) / span * target));
			const bucket = buckets.get(index);
			if (!bucket) {
				buckets.set(index, {clockSum: point.clock, sum: point.value, count: 1, min: point.value, max: point.value});
			}
			else {
				bucket.clockSum += point.clock;
				bucket.sum += point.value;
				bucket.count++;
				bucket.min = Math.min(bucket.min, point.value);
				bucket.max = Math.max(bucket.max, point.value);
			}
		}
		return [...buckets.entries()].sort((a, b) => a[0] - b[0]).map(([, bucket]) => ({
			clock: bucket.clockSum / bucket.count, value: bucket.sum / bucket.count, min: bucket.min, max: bucket.max
		}));
	}

	#segments(points) {
		if (points.length < 3) return [points];
		const deltas = [];
		for (let i = 1; i < points.length; i++) deltas.push(points[i].clock - points[i - 1].clock);
		const sorted = deltas.slice().sort((a, b) => a - b);
		const median = sorted[Math.floor(sorted.length / 2)] || 1;
		const limit = Math.max(median * 3, median + 120);
		const segments = [[points[0]]];
		for (let i = 1; i < points.length; i++) {
			if (points[i].clock - points[i - 1].clock > limit) segments.push([]);
			segments[segments.length - 1].push(points[i]);
		}
		return segments;
	}

	#niceTicks(max, count) {
		const rawStep = max / count;
		const magnitude = 10 ** Math.floor(Math.log10(rawStep));
		const residual = rawStep / magnitude;
		const step = (residual > 5 ? 10 : residual > 2 ? 5 : residual > 1 ? 2 : 1) * magnitude;
		const ticks = [];
		for (let value = 0; value <= max + step * .001; value += step) ticks.push(+value.toPrecision(12));
		if (ticks[ticks.length - 1] < max) ticks.push(+(ticks[ticks.length - 1] + step).toPrecision(12));
		return ticks;
	}

	#timeTicks(minClock, maxClock, plotWidth) {
		const span = Math.max(1, maxClock - minClock);
		const maxTicks = Math.max(2, Math.floor(plotWidth / 90));
		const steps = [300, 600, 900, 1800, 3600, 7200, 10800, 21600, 43200, 86400, 172800, 345600, 604800, 1209600];
		const step = steps.find(candidate => span / candidate <= maxTicks) ?? steps[steps.length - 1];
		const offset = new Date(minClock * 1000).getTimezoneOffset() * 60;
		const ticks = [];
		let clock = Math.ceil((minClock - offset) / step) * step + offset;
		if (step >= 86400) clock = Math.ceil((minClock - offset) / 86400) * 86400 + offset;

		const withDate = span > 86400;
		for (; clock <= maxClock; clock += step) {
			if (clock < minClock) continue;
			// Formato de las gráficas SVG de Zabbix: "HH:MM", "MM-DD HH:MM" o "MM-DD".
			const date = new Date(clock * 1000);
			const pad = value => String(value).padStart(2, '0');
			const hhmm = `${pad(date.getHours())}:${pad(date.getMinutes())}`;
			const mmdd = `${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
			ticks.push({clock, text: step >= 86400 ? mmdd : (withDate ? `${mmdd} ${hhmm}` : hhmm)});
		}
		return ticks;
	}

	#formatDateTime(clock) {
		const date = new Date(clock * 1000);
		const pad = value => String(value).padStart(2, '0');
		return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} `
			+ `${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
	}
}
