# Zabbix · Server: Vista general

Widget de dashboard para **Zabbix 7.0** que muestra en una sola tabla los servidores standalone (Linux y Windows)
monitoreados con **Zabbix Agent**: estado, CPU, RAM, discos, uptime, red y alertas, con filtros, ordenación,
paginación y ventanas de detalle (gráficos históricos y diagnósticos de red).

Está basado en la estructura de [lab24com/vmware_overview_widget](https://github.com/lab24com/vmware_overview_widget)
y se distribuye bajo la misma licencia (GPL-3.0).

## Requisitos

- Zabbix frontend 7.0 (manifest v2). Hosts con el ítem `agent.ping` (plantillas *Linux/Windows by Zabbix agent*).
- Para los diagnósticos: `ping`, `traceroute` y, opcionalmente, `nmap` instalados en el servidor del frontend.

## Instalación

```bash
cd /usr/share/zabbix/modules
git clone https://github.com/<usuario>/<repositorio>.git /opt/zabbix-server-overview
cp -r /opt/zabbix-server-overview/server_overview_widget .
chown -R www-data:www-data server_overview_widget   # usuario de tu PHP-FPM
```

Después, en Zabbix: *Administración → General → Módulos → Escanear directorio* y habilita **Server: Vista general**.
Recarga el dashboard con Ctrl+F5.

## Configuración del widget

| Campo | Descripción |
|---|---|
| Host groups | Limita los servidores mostrados (incluye subgrupos). |
| Rows per page | Filas por página (5–100). |
| Show hosts in maintenance | Incluye hosts en mantenimiento. |
| Show powered off / unreachable hosts | Incluye hosts sin respuesta del agente. **Actívalo para ver servidores caídos.** |
| Columns to display | OS, CPU, RAM, discos, uptime, red, alertas, última lectura. |
| Umbrales | Advertencia/crítico para CPU, RAM y disco (%). |

## Estructura

```
server_overview_widget/
├── manifest.json            # id, acciones y assets del módulo
├── Widget.php               # clase del widget
├── includes/WidgetForm.php  # campos del formulario (CWidgetField*)
├── actions/
│   ├── WidgetView.php       # datos de la tabla principal
│   ├── ServerDetails.php    # detalle de servidor (JSON)
│   ├── DiskDetails.php      # detalle de disco (JSON)
│   └── Diagnostics.php      # ping / traceroute / nmap (POST + CSRF)
├── views/
│   ├── widget.view.php      # HTML de la tabla
│   └── widget.edit.php      # formulario de edición
└── assets/{js,css}/         # lógica y estilos del cliente
tests/                       # pruebas de humo (vista PHP + JS en jsdom)
```

## Desarrollo

```bash
npm install
npm run check     # php -l, node --check y pruebas de humo
```

`tests/render.php` renderiza la vista con datos de ejemplo (sin necesitar Zabbix) y `tests/smoke.js` carga el JS
en jsdom y ejercita filtros, orden, modales y diagnóstico. El workflow de GitHub Actions ejecuta lo mismo en cada push.

Los errores del servidor se ven en el log de nginx/PHP-FPM; los del cliente, en la consola del navegador (F12).

## Licencia y créditos

GPL-3.0-or-later (ver `LICENSE`). Obra derivada de *VMware: Vista general* de LAKA Soluciones Tecnológicas / lab24com.
