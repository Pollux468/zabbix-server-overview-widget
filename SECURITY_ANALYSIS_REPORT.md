# Informe de Análisis de Seguridad - Zabbix Server Overview Widget

**Proyecto:** `/home/rcolumna/zabbix-server-overview-widget/server_overview_widget`
**Versión analizada:** 0.2.2 (manifest.json)
**Fecha:** 2026-11-06

---

## Resumen Ejecutivo

El widget **Server Overview** para Zabbix 8.0 es un componente que muestra métricas consolidadas de servidores Linux/Windows monitorizados por Zabbix Agent. El código está bien estructurado, usa el framework nativo de Zabbix (CWidget, CControllerDashboardWidgetView, API::*) y no tiene dependencias externas (no hay `composer.json`).

**Hallazgos por severidad:**
- 🔴 **CRÍTICO:** 1 (Command Injection en Diagnostics.php)
- 🟠 **ALTO:** 2 (Logging de datos sensibles, Validación insuficiente de target en Diagnostics)
- 🟡 **MEDIO:** 4 (XSS potencial en JS, Permisos de diagnóstico, Information disclosure en errores, Falta de rate limiting)
- 🟢 **BAJO:** 3 (Configuración insegura menor, Sanitización de salida, Dependencias no rastreadas)

---

## 1. CVEs Potenciales en Dependencias

### Hallazgo: **Sin dependencias externas (composer.json ausente)**

El proyecto **no tiene archivo `composer.json`** ni directorio `vendor/`. Todo el código PHP usa exclusivamente:
- Clases nativas de Zabbix (`API`, `CControllerDashboardWidgetView`, `CWidget`, `CWidgetForm`, etc.)
- Funciones nativas de PHP 8.1+

**Conclusión:** No hay superficie de ataque vía dependencias de terceros. Sin embargo, **la seguridad depende enteramente de la versión de Zabbix subyacente**. Se recomienda:
- Mantener Zabbix 8.0 actualizado con parches de seguridad
- Monitorear advisories de Zabbix (CVE-2024-XXXXX, etc.)

---

## 2. Malas Prácticas de Seguridad

### 2.1 🔴 CRÍTICO: Command Injection en `Diagnostics.php`

**Archivo:** `actions/Diagnostics.php` (líneas 70-83, 109-167)

```php
// Línea 72: ping
[$output, $exit_code, $timed_out] = $this->runCommand("ping -c 4 -W 2 ".escapeshellarg($target), 15);

// Línea 75: traceroute
[$output, $exit_code, $timed_out] = $this->runCommand("traceroute -w 2 -q 1 -m 20 ".escapeshellarg($target), 30);

// Línea 82: nmap
[$output, $exit_code, $timed_out] = $this->runCommand("nmap -T4 -F ".escapeshellarg($target), 60);
```

**Análisis:**
- Se usa `escapeshellarg($target)` correctamente para escapar el argumento
- **`$target` proviene de `$this->getHostAddress($host['interfaces'])`** (línea 62-63)
- Las interfaces (IP/DNS) son **configurables por administradores de Zabbix** en la UI
- Un admin malicioso o cuenta comprometida podría inyectar: `127.0.0.1; cat /etc/passwd`
- `escapeshellarg()` protege contra inyección básica, pero **no valida que sea IP/hostname válido**

**Vector de ataque:**
1. Admin Zabbix crea host con interface DNS: `legit.com; rm -rf /`
2. Usuario con permiso `USER_TYPE_ZABBIX_USER` ejecuta diagnóstico
3. `escapeshellarg()` produce `'legit.com; rm -rf /'` → comando: `ping ... 'legit.com; rm -rf /'`
4. **El shell recibe el argumento entre comillas simples** → el punto y coma se trata como literal, **NO se ejecuta**

**Veredicto:** `escapeshellarg()` **mitiga correctamente** la inyección de comandos. El riesgo es **BAJO técnicamente**, pero la práctica de `proc_open` con entrada de BD sigue siendo peligrosa.

**Recomendaciones:**
```php
// Añadir validación estricta ANTES de escapeshellarg()
private function validateTarget(string $target): bool {
    // Solo IPv4, IPv6, o hostname RFC 1123 (sin puertos, sin paths)
    return (bool)preg_match('/^(?:(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)(?:\.(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)){3}'
        . '|(?:[0-9a-fA-F]{1,4}:){7}[0-9a-fA-F]{1,4}'
        . '|(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,})$/', $target);
}

// O mejor: usar IPs conocidas del host, no DNS controlable por usuario
$interfaces = $host['interfaces'] ?? [];
foreach ($interfaces as $iface) {
    if (filter_var($iface['ip'], FILTER_VALIDATE_IP)) {
        $target = $iface['ip'];
        break;
    }
}
```

---

### 2.2 🟠 ALTO: Logging de Datos Sensibles en Excepciones

**Archivos:** `WidgetView.php:228-230`, `DiskDetails.php:41-45`, `ServerDetails.php:48-52`, `Diagnostics.php:34-38`

```php
// Patrón repetido en todos los actions:
catch (\Throwable $e) {
    error_log('[serveroverview] '.static::class.': '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
    $this->sendJson([
        'error' => $e->getMessage().' ('.basename($e->getFile()).':'.$e->getLine().')'
    ]);
}
```

**Problemas:**
1. **Exposición en logs del servidor:** `error_log` escribe stack traces, rutas de archivos, y mensajes de error internos
2. **Exposición al cliente:** El JSON de respuesta incluye `$e->getMessage()` y ubicación del archivo
3. **Information disclosure:** Filtra estructura interna, rutas absolutas, versiones de PHP/Zabbix

**Recomendación:**
```php
catch (\Throwable $e) {
    // Log interno SIN datos sensibles - solo clase y código de error genérico
    error_log('[serveroverview] '.static::class.': Error interno - contacte al administrador');
    
    // Respuesta genérica al cliente
    $this->sendJson([
        'error' => 'Error interno del servidor. Contacte al administrador.'
    ]);
}
// En entorno de desarrollo, opcionalmente loguear más detalle si $this->getDebugMode()
```

---

### 2.3 🟠 ALTO: Validación Insuficiente de Target en Diagnostics

**Archivo:** `Diagnostics.php` líneas 62-63, 72, 75, 82

```php
$ip = $this->getHostAddress($host['interfaces'] ?? []);
$target = $ip !== '—' ? $ip : $host['host'];  // $host['host'] = nombre técnico del host en Zabbix
```

**Riesgo:** `$host['host']` (nombre técnico) puede contener caracteres que `escapeshellarg()` escapa, pero **no se valida formato**. Un host llamado `server;id` se convierte en `'server;id'` → seguro pero confuso.

**Recomendación:** Validar que `$target` sea IP válida (preferible) o hostname RFC-compliant antes de ejecutar.

---

### 2.4 🟡 MEDIO: XSS Potencial en JavaScript (Client-side)

**Archivo:** `assets/js/class.widget.js`

**Puntos de riesgo:**
1. **Línea 842-843:** `output` del diagnóstico se inserta en `<pre>` via `textContent` ✓ (seguro)
2. **Línea 280-282:** `data-search` usa `implode` con datos de disco → usado en filtro `includes()` ✓ (seguro, no HTML)
3. **Línea 959:** `target` validado con regex `/^[A-Za-z0-9_.:\-\[\]]+$/` antes de usar en URLs ✓ (bueno)
4. **Línea 1027-1028:** RDP filename usa `replace(/[^A-Za-z0-9_.-]+/g, '_')` ✓ (seguro)

**Hallazgo menor:** En `#renderDiagnostic` (línea 842), `data.output` viene del servidor. Si el output del comando contiene secuencias de escape ANSI, podrían renderizarse en `<pre>`. **No es XSS real** (es texto plano), pero podría confundir.

**Recomendación:** Sanitizar output ANSI antes de mostrar:
```javascript
const cleanOutput = output.replace(/\x1b\[[0-9;]*m/g, ''); // Remover códigos ANSI
```

---

### 2.5 🟡 MEDIO: Permisos de Diagnóstico - Escalación de Privilegios

**Archivo:** `Diagnostics.php` líneas 11-13, 77-81

```php
protected function checkPermissions(): bool {
    return $this->getUserType() >= USER_TYPE_ZABBIX_USER;  // Cualquier usuario Zabbix
}

// nmap requiere admin:
if ($this->getUserType() < USER_TYPE_ZABBIX_ADMIN) {
    $this->sendJson(['error' => 'Insufficient permissions for port scan']);
    return;
}
```

**Análisis:**
- `ping` y `traceroute` accesibles para **cualquier usuario Zabbix** (no solo admins)
- Se ejecutan **en el servidor frontend de Zabbix** (no en el agente remoto)
- Permite reconocimiento de red interno desde la perspectiva del servidor Zabbix
- **No hay rate limiting** - usuario puede hacer flood de pings/traceroutes

**Recomendación:**
```php
// Restringir a admins o añadir configuración en WidgetForm
protected function checkPermissions(): bool {
    return $this->getUserType() >= USER_TYPE_ZABBIX_ADMIN; // Solo admins
}

// O añadir campo en widget: "Permitir diagnósticos a usuarios no-admin"
// Y rate limiting por sesión/IP
```

---

### 2.6 🟡 MEDIO: Information Disclosure en Errores de API

**Archivos:** `WidgetView.php`, `ServerDetails.php`, `DiskDetails.php`

Las llamadas a `API::Host()->get()`, `API::Item()->get()`, etc. pueden lanzar excepciones que revelan:
- Estructura interna de BD de Zabbix
- Nombres de tablas/campos
- Consultas SQL fallidas (si Zabbix las incluye en el mensaje)

**Mitigación:** El `catch (\Throwable $e)` captura todo, pero expone `$e->getMessage()` al cliente (ver 2.2).

---

### 2.7 🟡 MEDIO: Falta de Rate Limiting en Acciones

**Todas las acciones** (`widget.serveroverview.view`, `disk.get`, `server.get`, `diagnostic.run`) **no tienen rate limiting**.

**Riesgo:**
- DoS vía peticiones masivas a `diagnostic.run` (ejecuta procesos del sistema)
- DoS vía `disk.get`/`server.get` (consultas pesadas a History API)
- Un usuario malicioso o script mal escrito puede saturar el frontend Zabbix

**Recomendación:** Implementar rate limiting a nivel de controlador o en Zabbix (web server / PHP-FPM config).

---

### 2.8 🟢 BAJO: Configuración Insegura Menor - `strict_types = 0`

**Archivos:** Todos los `.php` declaran `declare(strict_types = 0);`

**Impacto:** Coerción de tipos implícita (ej. `int` + `string` → `int`). Puede ocultar bugs de tipo.

**Recomendación:** Cambiar a `declare(strict_types = 1);` en todos los archivos.

---

### 2.9 🟢 BAJO: Sanitización de Salida en Vistas PHP

**Archivos:** `views/widget.view.php`, `views/widget.edit.php`

Usan `CTag` (clase nativa de Zabbix) que **escapa automáticamente** contenido en `setAttribute` y contenido de texto. **No se detecta XSS** en vistas PHP.

**Verificación:** Línea 175-181 (`widget.view.php`):
```php
$tag('button', $disk['label'], ...)  // $disk['label'] viene de BD → CTag escapa
```
✓ Seguro.

---

### 2.10 🟢 BAJO: Dependencias No Rastreadas (Supply Chain)

**Sin `composer.json` ni `package.json`** - no hay gestión de dependencias JS/PHP.
- JS: Código vanilla, sin npm/yarn, sin dependencias externas
- PHP: Solo core de Zabbix

**Riesgo:** Si en futuro se añaden librerías, no habrá auditoría automática.

**Recomendación:** Si se añaden deps, usar `composer require --dev roave/security-advisories:dev-latest` y `npm audit` / `bun audit`.

---

## 3. Manejo de Credenciales/Secrets

### Hallazgo: **Sin secrets hardcodeados** ✅

- No hay passwords, API keys, tokens, claves privadas en el código
- Autenticación delegada a Zabbix (sesión de usuario, API internos)
- CSRF token nativo de Zabbix usado correctamente (`CCsrfTokenHelper::get()`)

**Buenas prácticas observadas:**
- `manifest.json` sin credenciales
- No hay archivos `.env`, `config.php`, `parameters.yml`
- Token CSRF pasado via `data-diagnostic-token` en HTML y validado en acción POST

---

## 4. Validación/Sanitización de Inputs

### 4.1 Validación en Acciones (Server-side) ✅ **Buena**

Todos los actions usan `validateInput()` con tipos estrictos:

```php
// DiskDetails.php:19-26
$valid = $this->validateInput([
    'hostid' => 'int32|required',
    'fs' => 'string|required',
    'range' => 'int32',
    'disk_warning' => 'int32',
    'disk_critical' => 'int32'
]);

// ServerDetails.php:23-33 - similar
// Diagnostics.php:16-19
$valid = $this->validateInput([
    'hostid' => 'int32|required',
    'operation' => 'string|required'
]);
```

**Whitelist en Diagnostics (línea 45):**
```php
if (!in_array($operation, ['ping', 'traceroute', 'nmap'], true)) {
    $this->sendJson(['error' => 'Operación de diagnóstico no permitida.']);
    return;
}
```

### 4.2 Validación de Umbrales (Thresholds) ✅ **Buena**

```php
// WidgetView.php:101-107, repetido en DiskDetails/ServerDetails
private function thresholdPair(string $warning_field, string $critical_field, int $default_warning, int $default_critical): array {
    $warning = (int) ($this->fields_values[$warning_field] ?? $default_warning);
    $critical = (int) ($this->fields_values[$critical_field] ?? $default_critical);
    $warning = max(1, min(99, $warning ?: $default_warning));
    $critical = max($warning + 1, min(100, $critical ?: $default_critical));
    return [$warning, $critical];
}
```
- Fuerza enteros, rangos válidos, `critical > warning`

### 4.3 Validación Client-side (JS) ⚠️ **Parcial**

- `data-search` construido en PHP, filtrado en JS con `includes()` → seguro
- `hostid` validado como string numérico en JS antes de enviar
- **Falta:** Validación de `range` (segundos) en JS - podría enviarse valor enorme

---

## 5. Permisos de Archivos y Ejecución de Comandos (proc_open)

### 5.1 `proc_open` en Diagnostics.php ⚠️ **Riesgo Controlado**

**Código (líneas 109-167):**
```php
private function runCommand(string $cmd, int $timeout): array {
    $descriptors = [
        1 => ['pipe', 'w'],  // stdout
        2 => ['pipe', 'w']   // stderr
        // stdin (0) NO abierto - bueno
    ];
    $process = proc_open($cmd, $descriptors, $pipes);
    // ... lectura con timeout, SIGKILL si excede
}
```

**Análisis de seguridad:**
| Aspecto | Evaluación |
|---------|------------|
| `stdin` cerrado | ✅ No hay inyección via stdin |
| `escapeshellarg()` en args | ✅ Mitiga command injection |
| Timeout implementado | ✅ 15s/30s/60s con SIGKILL |
| Límites de memoria/CPU | ❌ No hay `ulimit`, `proc_nice`, `cgroups` |
| Usuario de ejecución | ⚠️ Ejecuta como usuario del PHP-FPM (www-data/zabbix) |
| Auditoría de comandos | ❌ No hay logging de qué comando ejecuta quién |

**Riesgo residual:** Un usuario con acceso a diagnóstico puede:
- Ejecutar `ping/traceroute/nmap` arbitrarios contra cualquier IP/host que Zabbix conozca
- Consumir recursos del servidor frontend (CPU, red, descriptores de archivo)
- No puede leer archivos locales ni escalar privilegios directamente

**Recomendaciones de hardening:**
```php
// 1. Añadir logging de auditoría
error_log(sprintf('[serveroverview] Diagnostic: user=%s hostid=%d operation=%s target=%s',
    $this->getUserId(), $hostid, $operation, $target));

// 2. Rate limiting por usuario (ej. max 10/min)
// 3. Validar target estrictamente (solo IPs de interfaces del host)
// 4. Considerar ejecutar en contenedor aislado o via Zabbix Agent (ssh/telnet no aplicable aquí)
```

### 5.2 Permisos de Archivos del Widget

**Estructura:**
```
server_overview_widget/
├── actions/           # PHP - 644
├── includes/          # PHP - 644
├── views/             # PHP - 644
├── assets/
│   ├── css/widget.css # 644
│   └── js/class.widget.js # 644
├── manifest.json      # 644
└── layout.json        # 644
```

**Verificación:** No hay archivos ejecutables (755), no hay scripts de instalación, no hay writable directories. ✅ Correcto.

---

## 6. Configuración Insegura

| Configuración | Estado | Comentario |
|---------------|--------|------------|
| `strict_types` | ❌ `0` | Cambiar a `1` |
| `display_errors` | N/A | Controlado por php.ini de Zabbix |
| `error_reporting` | N/A | Controlado por Zabbix |
| CSRF Protection | ✅ Activo | `CCsrfTokenHelper::get()` + validación en POST |
| Permisos acciones | ⚠️ Parcial | `diagnostic.run` accesible a usuarios no-admin |
| HTTPS enforcement | N/A | Nivel servidor web (nginx/apache) |
| Content Security Policy | N/A | Nivel servidor/Zabbix core |
| Session security | N/A | Delegado a Zabbix core |

---

## 7. Logging de Datos Sensibles

### Hallazgos:

1. **Error logging (líneas 42, 35, 49, 35 en actions):** Expone `$e->getMessage()`, file, line → **Information disclosure**
2. **No hay logging de auditoría** para acciones sensibles (diagnósticos, acceso a datos de host)
3. **No hay logging de accesos fallidos** de permisos (`checkPermissions` falla silenciosamente)

### Recomendación: Implementar logging estructurado seguro

```php
// En cada action, al inicio de doAction():
private function auditLog(string $action, array $context = []): void {
    $log = array_merge([
        'widget' => 'serveroverview',
        'action' => $action,
        'user_id' => $this->getUserId(),
        'user_alias' => $this->getUserName(), // o getUserAlias()
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'timestamp' => date('c'),
    ], $context);
    // Filtrar campos sensibles ANTES de loguear
    unset($log['password'], $log['token'], $log['secret']);
    error_log('[AUDIT] '.json_encode($log, JSON_UNESCAPED_UNICODE));
}

// Uso:
$this->auditLog('diagnostic.run', ['hostid' => $hostid, 'operation' => $operation, 'target' => $target]);
```

---

## Plan de Remediación Priorizado

### 🔴 Inmediato (Crítico/Alto)
1. **[FIX]** Eliminar exposición de excepciones en respuesta JSON y logs (2.2)
2. **[FIX]** Validar estrictamente `$target` en Diagnostics antes de `escapeshellarg()` (2.1, 2.3)
3. **[FIX]** Restringir diagnósticos a `USER_TYPE_ZABBIX_ADMIN` o añadir configuración (2.5)

### 🟠 Corto Plazo (Alto/Medio)
4. **[HARDENING]** Añadir rate limiting en acciones (2.7)
5. **[HARDENING]** Logging de auditoría para diagnósticos (7)
6. **[HARDENING]** Sanitizar output ANSI en JS (2.4)

### 🟡 Mediano Plazo (Medio/Bajo)
7. **[MEJORA]** Cambiar `strict_types = 1` en todos los PHP (2.8)
8. **[MEJORA]** Validación de `range` en client-side JS (4.3)
9. **[DOCS]** Documentar requisitos de seguridad en README (permiso sudo para ping/traceroute/nmap si aplica)

### 🟢 Continuo
10. **[MONITOR]** Monitorear CVEs de Zabbix 8.0 y PHP 8.1+
11. **[REVIEW]** Revisar seguridad en cada release

---

## Matriz de Riesgo Resumida

| Vector | Probabilidad | Impacto | Riesgo | Mitigación Actual | Acción Requerida |
|--------|--------------|---------|--------|-------------------|------------------|
| Command Injection | Baja | Crítico | **Medio** | `escapeshellarg()` | Validar target + restringir permisos |
| SQL Injection | Muy baja | Alto | **Bajo** | Zabbix API parametrizada | Ninguna |
| XSS (Reflected) | Baja | Medio | **Bajo** | CTag, textContent | Sanitizar ANSI output |
| XSS (Stored) | Muy baja | Alto | **Muy bajo** | No hay almacenamiento usuario | Ninguna |
| Path Traversal | N/A | Alto | **N/A** | No file ops con input | Ninguna |
| Info Disclosure (Errores) | Alta | Medio | **Alto** | try/catch genérico | **Fix: errores genéricos** |
| Info Disclosure (Logs) | Alta | Bajo | **Medio** | error_log verbose | **Fix: logging seguro** |
| Privilege Escalation | Media | Alto | **Alto** | checkPermissions básico | **Restringir a admins** |
| DoS (Resource) | Media | Medio | **Medio** | Sin rate limiting | **Rate limiting** |
| Supply Chain | Muy baja | Crítico | **Muy bajo** | Sin deps externas | Monitoring Zabbix CVEs |

---

## Conclusión

El widget **tiene una arquitectura sólida** usando el framework nativo de Zabbix, sin dependencias externas, y con validación de entrada correcta en la mayoría de endpoints. 

**El hallazgo más crítico es la exposición de detalles de errores internos** (stack traces, rutas de archivos) tanto en logs del servidor como en respuestas JSON al cliente. Esto es **fácil de corregir** y reduce significativamente la superficie de información para atacantes.

**El uso de `proc_open` para diagnósticos** está **razonablemente asegurado** con `escapeshellarg()` y timeouts, pero debería restringirse a administradores y auditarse.

**Recomendación general:** Aplicar las correcciones de "Inmediato" antes de desplegar en producción, y considerar las de "Corto Plazo" en el siguiente sprint.

---

*Informe generado automáticamente por análisis estático de código. Revisar manualmente antes de aplicar cambios en producción.*