# Changelog

## 0.2.0
- Reconstrucción de `views/widget.view.php`, `views/widget.edit.php` y de la clase JS `CWidgetServerOverview`.
- `WidgetForm` migrado a campos `CWidgetField*` (Zabbix 7.0).
- Acciones de detalle y diagnóstico convertidas a `CController` con respuesta JSON; umbrales por parámetros.
- Corregida la expresión regular del destino (SSH/HTTPS/diagnósticos nunca aparecían).
- Corregido `ServerDetails::lastUpdate()` inexistente (error de servidor al abrir el detalle).
- Añadidas pruebas de humo y CI.

## 0.1.0
- Versión inicial.
