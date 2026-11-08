<?php

declare(strict_types=1);

// =========================================================================
// BOOTSTRAP: Stubs y constantes de Zabbix (sin namespaces bracketed)
// =========================================================================

if (!defined('USER_TYPE_ZABBIX_USER')) {
    define('USER_TYPE_ZABBIX_USER', 1);
}
if (!defined('USER_TYPE_ZABBIX_ADMIN')) {
    define('USER_TYPE_ZABBIX_ADMIN', 2);
}
if (!defined('USER_TYPE_ZABBIX_SUPER_ADMIN')) {
    define('USER_TYPE_ZABBIX_SUPER_ADMIN', 3);
}
if (!defined('HOST_MAINTENANCE_STATUS_OFF')) {
    define('HOST_MAINTENANCE_STATUS_OFF', 0);
}
if (!defined('HOST_MAINTENANCE_STATUS_ON')) {
    define('HOST_MAINTENANCE_STATUS_ON', 1);
}
if (!defined('HOST_STATUS_MONITORED')) {
    define('HOST_STATUS_MONITORED', 0);
}
if (!defined('ITEM_STATE_NORMAL')) {
    define('ITEM_STATE_NORMAL', 0);
}
if (!defined('ITEM_STATUS_ACTIVE')) {
    define('ITEM_STATUS_ACTIVE', 0);
}
if (!defined('ITEM_STATE_NOTSUPPORTED')) {
    define('ITEM_STATE_NOTSUPPORTED', 1);
}
if (!defined('DATE_TIME_FORMAT_SECONDS')) {
    define('DATE_TIME_FORMAT_SECONDS', 'Y-m-d H:i:s');
}
if (!defined('EVENT_SOURCE_TRIGGERS')) {
    define('EVENT_SOURCE_TRIGGERS', 0);
}
if (!defined('EVENT_OBJECT_TRIGGER')) {
    define('EVENT_OBJECT_TRIGGER', 0);
}
if (!defined('TRIGGER_STATUS_ENABLED')) {
    define('TRIGGER_STATUS_ENABLED', 0);
}
if (!defined('TRIGGER_VALUE_PROBLEM')) {
    define('TRIGGER_VALUE_PROBLEM', 1);
}

if (!function_exists('zbx_date2str')) {
    function zbx_date2str($format, $timestamp) { return date($format, $timestamp); }
}
if (!function_exists('get_and_clear_messages')) {
    function get_and_clear_messages() { return []; }
}
if (!function_exists('getSubGroups')) {
    function getSubGroups($groupids) { return $groupids; }
}

// Stub classes en namespace global (simulan Zabbix\Widgets)
class CWidgetForm {}
class CWidgetField {
    public const FLAG_NOT_EMPTY = 1;
    public const FLAG_LABEL_ASTERISK = 2;
}

// Stub classes para Zabbix core
class CControllerResponseData {
    public $data;
    public $view = true;
    public function __construct($d) { $this->data = $d; }
    public function disableView() { $this->view = false; return $this; }
}

class CController {
    public $response;
    public $input = [];
    public $fields_values = [];
    public function getInput($k, $d = null) { return $this->input[$k] ?? $d; }
    public function hasInput($k) { return array_key_exists($k, $this->input); }
    public function setResponse($r) { $this->response = $r; }
    public function getUserType() { return 1; }
    public function getDebugMode() { return 0; }
    public function validateInput(array $rules): bool {
        foreach ($rules as $field => $rule) {
            $required = str_contains($rule, 'required');
            $isInt = str_contains($rule, 'int32');
            $value = $this->input[$field] ?? null;
            
            if ($required && ($value === null || $value === '')) {
                return false;
            }
            if ($isInt && $value !== null && $value !== '' && !is_numeric($value)) {
                return false;
            }
        }
        return true;
    }
}

class CControllerDashboardWidgetView extends CController {}

class CCsrfTokenHelper {
    public static function get($name) { return 'mock_token'; }
}

class API {
    public static function __callStatic($name, $arguments) {
        if (isset($GLOBALS['MOCK_API_HOST_GET_EXCEPTION'])) {
            throw $GLOBALS['MOCK_API_HOST_GET_EXCEPTION'];
        }
        return new class {
            public function get($options) {
                return $GLOBALS['MOCK_API_HOST_GET'] ?? [];
            }
        };
    }
}

// Cargar la clase Diagnostics
require_once __DIR__ . '/../../server_overview_widget/Actions/Diagnostics.php';