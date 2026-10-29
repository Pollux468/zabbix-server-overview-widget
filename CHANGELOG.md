# Changelog

## 0.2.1
- Las tarjetas «CPU alta», «RAM alta» y «Discos altos» ahora filtran la lista (también en el selector de estado).
- Los errores de las acciones se devuelven como JSON legible (mensaje y archivo:línea) en lugar de «Error de respuesta del servidor»; la interfaz muestra el título y los mensajes que Zabbix envía.
- Un disco sin nombre de volumen (p. ej. `C:`) muestra la letra de unidad en vez de quedar en blanco.
- Nueva prueba `tests/actions.php` (etiquetas de disco y manejo de excepciones).

## 0.2.0
- Reconstrucción de `views/widget.view.php`, `views/widget.edit.php` y de la clase JS `CWidgetServerOverview`.
- `WidgetForm` migrado a campos `CWidgetField*` (Zabbix 7.0).
- Acciones de detalle y diagnóstico convertidas a `CController` con respuesta JSON; umbrales por parámetros.
- Corregida la expresión regular del destino (SSH/HTTPS/diagnósticos nunca aparecían).
- Corregido `ServerDetails::lastUpdate()` inexistente (error de servidor al abrir el detalle).
- Añadidas pruebas de humo y CI.

## 0.1.0
- Versión inicial.
