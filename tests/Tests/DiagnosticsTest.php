<?php

declare(strict_types=1);

namespace Modules\ServerOverview\Tests\Actions;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Modules\ServerOverview\Actions\Diagnostics;

/**
 * Subclase de prueba que permite mockear métodos privados
 */
class TestableDiagnostics extends Diagnostics
{
    public array $runCommandResults = [];
    public int $userType = 1;
    public ?\Throwable $apiException = null;
    public ?string $lastCommand = null;
    public ?int $lastTimeout = null;

    public function getUserType(): int
    {
        return $this->userType;
    }

    protected function runCommand(string $cmd, int $timeout): array
    {
        $this->lastCommand = $cmd;
        $this->lastTimeout = $timeout;
        
        $key = $cmd . '|' . $timeout;
        if (isset($this->runCommandResults[$key])) {
            $r = $this->runCommandResults[$key];
            return [$r['output'], $r['exit_code'], $r['timed_out']];
        }
        // Fallback para tests de timeout
        if (isset($this->runCommandResults['*'])) {
            $r = $this->runCommandResults['*'];
            return [$r['output'], $r['exit_code'], $r['timed_out']];
        }
        return ['default output', 0, false];
    }
}

/**
 * Pruebas unitarias para la clase Diagnostics
 * Cubre: validateInput, checkPermissions, getHostAddress, runCommand, doAction
 */
class DiagnosticsTest extends TestCase
{
    private const USER_TYPE_ZABBIX_USER = 1;
    private const USER_TYPE_ZABBIX_ADMIN = 2;
    private const USER_TYPE_ZABBIX_SUPER_ADMIN = 3;

    private ReflectionClass $reflection;
    private TestableDiagnostics $diagnostics;

    protected function setUp(): void
    {
        $this->reflection = new ReflectionClass(TestableDiagnostics::class);
        $this->diagnostics = $this->reflection->newInstanceWithoutConstructor();

        // Configurar propiedades privadas/protegidas necesarias
        $this->setProperty('input', []);
        $this->setProperty('response', null);
        $this->setProperty('fields_values', []);

        // Resetear mocks
        $this->diagnostics->runCommandResults = [];
        $this->diagnostics->userType = self::USER_TYPE_ZABBIX_USER;
        $this->diagnostics->apiException = null;

        // Limpiar mocks globales de API
        unset($GLOBALS['MOCK_API_HOST_GET']);
        unset($GLOBALS['MOCK_API_HOST_GET_EXCEPTION']);
    }

    private function setProperty(string $name, $value): void
    {
        $prop = $this->reflection->getProperty($name);
        $prop->setAccessible(true);
        $prop->setValue($this->diagnostics, $value);
    }

    private function getProperty(string $name)
    {
        $prop = $this->reflection->getProperty($name);
        $prop->setAccessible(true);
        return $prop->getValue($this->diagnostics);
    }

    private function invokeMethod(string $name, array $args = [])
    {
        $method = $this->reflection->getMethod($name);
        $method->setAccessible(true);
        return $method->invoke($this->diagnostics, ...$args);
    }

    private function setInput(array $input): void
    {
        $this->setProperty('input', $input);
    }

    private function getResponse(): ?array
    {
        $response = $this->getProperty('response');
        if ($response === null) {
            return null;
        }
        $data = $response->data['main_block'] ?? '';
        return json_decode($data, true);
    }

    private function mockApiHostGet(array $hosts): void
    {
        $GLOBALS['MOCK_API_HOST_GET'] = $hosts;
        unset($GLOBALS['MOCK_API_HOST_GET_EXCEPTION']);
    }

    private function mockApiHostGetException(\Throwable $exception): void
    {
        $GLOBALS['MOCK_API_HOST_GET_EXCEPTION'] = $exception;
        unset($GLOBALS['MOCK_API_HOST_GET']);
    }

    private function mockRunCommand(array $results): void
    {
        $this->diagnostics->runCommandResults = $results;
    }

    // =========================================================================
    // TESTS: validateInput / checkInput
    // =========================================================================

    public function testCheckInputValidHostidAndOperation(): void
    {
        $this->setInput(['hostid' => 10001, 'operation' => 'ping']);

        $method = $this->reflection->getMethod('checkInput');
        $method->setAccessible(true);
        $result = $method->invoke($this->diagnostics);

        $this->assertTrue($result);
    }

    public function testCheckInputMissingHostid(): void
    {
        $this->setInput(['operation' => 'ping']);

        $method = $this->reflection->getMethod('checkInput');
        $method->setAccessible(true);
        $result = $method->invoke($this->diagnostics);

        $this->assertFalse($result);
        $response = $this->getResponse();
        $this->assertNotNull($response);
        $this->assertArrayHasKey('error', $response);
    }

    public function testCheckInputMissingOperation(): void
    {
        $this->setInput(['hostid' => 10001]);

        $method = $this->reflection->getMethod('checkInput');
        $method->setAccessible(true);
        $result = $method->invoke($this->diagnostics);

        $this->assertFalse($result);
        $response = $this->getResponse();
        $this->assertNotNull($response);
        $this->assertArrayHasKey('error', $response);
    }

    public function testCheckInputInvalidHostidNonNumeric(): void
    {
        $this->setInput(['hostid' => 'abc', 'operation' => 'ping']);

        $method = $this->reflection->getMethod('checkInput');
        $method->setAccessible(true);
        $result = $method->invoke($this->diagnostics);

        $this->assertFalse($result);
    }

    public function testCheckInputEmptyOperation(): void
    {
        $this->setInput(['hostid' => 10001, 'operation' => '']);

        $method = $this->reflection->getMethod('checkInput');
        $method->setAccessible(true);
        $result = $method->invoke($this->diagnostics);

        $this->assertFalse($result);
    }

    // =========================================================================
    // TESTS: checkPermissions
    // =========================================================================

    public function testCheckPermissionsZabbixUser(): void
    {
        $this->setProperty('input', []);
        $this->diagnostics->userType = self::USER_TYPE_ZABBIX_USER;

        $method = $this->reflection->getMethod('checkPermissions');
        $method->setAccessible(true);
        $result = $method->invoke($this->diagnostics);

        $this->assertFalse($result); // Solo ADMIN tiene permisos
    }

    public function testCheckPermissionsZabbixAdmin(): void
    {
        $this->diagnostics->userType = self::USER_TYPE_ZABBIX_ADMIN;

        $method = $this->reflection->getMethod('checkPermissions');
        $method->setAccessible(true);
        $result = $method->invoke($this->diagnostics);

        $this->assertTrue($result);
    }

    public function testCheckPermissionsGuestUser(): void
    {
        $this->diagnostics->userType = 0;

        $method = $this->reflection->getMethod('checkPermissions');
        $method->setAccessible(true);
        $result = $method->invoke($this->diagnostics);

        $this->assertFalse($result);
    }

    // =========================================================================
    // TESTS: getHostAddress
    // =========================================================================

    public function testGetHostAddressIpv4(): void
    {
        $interfaces = [
            ['ip' => '192.168.1.10', 'dns' => '', 'useip' => '1', 'main' => '1'],
        ];

        $result = $this->invokeMethod('getHostAddress', [$interfaces]);
        $this->assertEquals('192.168.1.10', $result);
    }

    public function testGetHostAddressIpv6(): void
    {
        $interfaces = [
            ['ip' => '2001:db8::1', 'dns' => '', 'useip' => '1', 'main' => '1'],
        ];

        $result = $this->invokeMethod('getHostAddress', [$interfaces]);
        $this->assertEquals('2001:db8::1', $result);
    }

    public function testGetHostAddressDns(): void
    {
        $interfaces = [
            ['ip' => '', 'dns' => 'server.example.com', 'useip' => '0', 'main' => '1'],
        ];

        $result = $this->invokeMethod('getHostAddress', [$interfaces]);
        $this->assertEquals('server.example.com', $result);
    }

    public function testGetHostAddressFallbackToHostName(): void
    {
        $interfaces = [
            ['ip' => '', 'dns' => '', 'useip' => '1', 'main' => '1'],
        ];

        $result = $this->invokeMethod('getHostAddress', [$interfaces]);
        $this->assertEquals('—', $result);
    }

    public function testGetHostAddressMultipleInterfacesPrefersMain(): void
    {
        $interfaces = [
            ['ip' => '10.0.0.1', 'dns' => '', 'useip' => '1', 'main' => '0'],
            ['ip' => '192.168.1.10', 'dns' => '', 'useip' => '1', 'main' => '1'],
        ];

        $result = $this->invokeMethod('getHostAddress', [$interfaces]);
        $this->assertEquals('192.168.1.10', $result);
    }

    public function testGetHostAddressEmptyInterfaces(): void
    {
        $result = $this->invokeMethod('getHostAddress', [[]]);
        $this->assertEquals('—', $result);
    }

    public function testGetHostAddressEmptyArray(): void
    {
        $result = $this->invokeMethod('getHostAddress', [[]]);
        $this->assertEquals('—', $result);
    }

    public function testGetHostAddressSkipsEmptyAddress(): void
    {
        $interfaces = [
            ['ip' => '', 'dns' => '', 'useip' => '1', 'main' => '1'],
            ['ip' => '192.168.1.20', 'dns' => '', 'useip' => '1', 'main' => '0'],
        ];

        $result = $this->invokeMethod('getHostAddress', [$interfaces]);
        $this->assertEquals('192.168.1.20', $result);
    }

    // =========================================================================
    // TESTS: doAction / handle - Operaciones válidas
    // =========================================================================

    public function testDoActionPingSuccess(): void
    {
        $this->setInput(['hostid' => 10001, 'operation' => 'ping']);
        $this->diagnostics->userType = self::USER_TYPE_ZABBIX_USER;

        $this->mockApiHostGet([
            [
                'hostid' => '10001',
                'host' => 'test-host',
                'name' => 'Test Server',
                'interfaces' => [
                    ['ip' => '192.168.1.10', 'dns' => '', 'useip' => '1', 'main' => '1'],
                ],
            ],
        ]);

        $cmd = "ping -c 4 -W 2 " . escapeshellarg('192.168.1.10');
        $this->mockRunCommand([
            $cmd . '|15' => [
                'output' => "PING 192.168.1.10 (192.168.1.10) 56(84) bytes of data.\n64 bytes from 192.168.1.10: icmp_seq=1 ttl=64 time=0.5 ms\n--- 192.168.1.10 ping statistics ---\n4 packets transmitted, 4 received, 0% packet loss, time 3004ms\n",
                'exit_code' => 0,
                'timed_out' => false,
            ],
        ]);

        $method = $this->reflection->getMethod('doAction');
        $method->setAccessible(true);
        $method->invoke($this->diagnostics);

        $response = $this->getResponse();
        $this->assertNotNull($response);
        $this->assertEquals('Test Server', $response['host']);
        $this->assertEquals('192.168.1.10', $response['target']);
        $this->assertEquals('ping', $response['operation']);
        $this->assertEquals(0, $response['exit_code']);
        $this->assertFalse($response['timed_out']);
        $this->assertArrayHasKey('duration', $response);
    }

    public function testDoActionTracerouteSuccess(): void
    {
        $this->setInput(['hostid' => 10001, 'operation' => 'traceroute']);
        $this->diagnostics->userType = self::USER_TYPE_ZABBIX_USER;

        $this->mockApiHostGet([
            [
                'hostid' => '10001',
                'host' => 'test-host',
                'name' => 'Test Server',
                'interfaces' => [
                    ['ip' => '192.168.1.10', 'dns' => '', 'useip' => '1', 'main' => '1'],
                ],
            ],
        ]);

        $cmd = "traceroute -w 2 -q 1 -m 20 " . escapeshellarg('192.168.1.10');
        $this->mockRunCommand([
            $cmd . '|30' => [
                'output' => "traceroute to 192.168.1.10 (192.168.1.10), 20 hops max, 60 byte packets\n 1  gateway (192.168.1.1)  0.5 ms\n 2  test-host (192.168.1.10)  1.2 ms\n",
                'exit_code' => 0,
                'timed_out' => false,
            ],
        ]);

        $method = $this->reflection->getMethod('doAction');
        $method->setAccessible(true);
        $method->invoke($this->diagnostics);

        $response = $this->getResponse();
        $this->assertEquals('traceroute', $response['operation']);
        $this->assertEquals(0, $response['exit_code']);
    }

    public function testDoActionNmapSuccessAsAdmin(): void
    {
        $this->setInput(['hostid' => 10001, 'operation' => 'nmap']);
        $this->diagnostics->userType = self::USER_TYPE_ZABBIX_ADMIN;

        $this->mockApiHostGet([
            [
                'hostid' => '10001',
                'host' => 'test-host',
                'name' => 'Test Server',
                'interfaces' => [
                    ['ip' => '192.168.1.10', 'dns' => '', 'useip' => '1', 'main' => '1'],
                ],
            ],
        ]);

        $cmd = "nmap -T4 -F " . escapeshellarg('192.168.1.10');
        $this->mockRunCommand([
            $cmd . '|60' => [
                'output' => "Starting Nmap 7.94 at 2024-01-15 10:00 UTC\nNmap scan report for 192.168.1.10\nPORT     STATE SERVICE\n22/tcp   open  ssh\n80/tcp   open  http\n443/tcp  open  https\n",
                'exit_code' => 0,
                'timed_out' => false,
            ],
        ]);

        $method = $this->reflection->getMethod('doAction');
        $method->setAccessible(true);
        $method->invoke($this->diagnostics);

        $response = $this->getResponse();
        $this->assertEquals('nmap', $response['operation']);
        $this->assertEquals(0, $response['exit_code']);
    }

    // =========================================================================
    // TESTS: doAction - Operaciones inválidas
    // =========================================================================

    public function testDoActionInvalidOperation(): void
    {
        $this->setInput(['hostid' => 10001, 'operation' => 'invalid']);
        $this->diagnostics->userType = self::USER_TYPE_ZABBIX_USER;

        $method = $this->reflection->getMethod('doAction');
        $method->setAccessible(true);
        $method->invoke($this->diagnostics);

        $response = $this->getResponse();
        $this->assertNotNull($response);
        $this->assertArrayHasKey('error', $response);
        $this->assertEquals('Operación de diagnóstico no permitida.', $response['error']);
    }

    public function testDoActionCaseInsensitiveOperation(): void
    {
        $this->setInput(['hostid' => 10001, 'operation' => 'PING']);
        $this->diagnostics->userType = self::USER_TYPE_ZABBIX_USER;

        $this->mockApiHostGet([
            [
                'hostid' => '10001',
                'host' => 'test-host',
                'name' => 'Test Server',
                'interfaces' => [
                    ['ip' => '192.168.1.10', 'dns' => '', 'useip' => '1', 'main' => '1'],
                ],
            ],
        ]);

        $cmd = "ping -c 4 -W 2 " . escapeshellarg('192.168.1.10');
        $this->mockRunCommand([
            $cmd . '|15' => [
                'output' => 'PING output',
                'exit_code' => 0,
                'timed_out' => false,
            ],
        ]);

        $method = $this->reflection->getMethod('doAction');
        $method->setAccessible(true);
        $method->invoke($this->diagnostics);

        $response = $this->getResponse();
        $this->assertEquals('ping', $response['operation']);
    }

    public function testDoActionOperationWithWhitespace(): void
    {
        $this->setInput(['hostid' => 10001, 'operation' => '  ping  ']);
        $this->diagnostics->userType = self::USER_TYPE_ZABBIX_USER;

        $this->mockApiHostGet([
            [
                'hostid' => '10001',
                'host' => 'test-host',
                'name' => 'Test Server',
                'interfaces' => [
                    ['ip' => '192.168.1.10', 'dns' => '', 'useip' => '1', 'main' => '1'],
                ],
            ],
        ]);

        $cmd = "ping -c 4 -W 2 " . escapeshellarg('192.168.1.10');
        $this->mockRunCommand([
            $cmd . '|15' => [
                'output' => 'PING output',
                'exit_code' => 0,
                'timed_out' => false,
            ],
        ]);

        $method = $this->reflection->getMethod('doAction');
        $method->setAccessible(true);
        $method->invoke($this->diagnostics);

        $response = $this->getResponse();
        $this->assertEquals('ping', $response['operation']);
    }

    // =========================================================================
    // TESTS: doAction - Permisos
    // =========================================================================

    public function testDoActionNoPermissionsGuest(): void
    {
        $this->setInput(['hostid' => 10001, 'operation' => 'ping']);
        $this->diagnostics->userType = 0;

        $method = $this->reflection->getMethod('doAction');
        $method->setAccessible(true);
        $method->invoke($this->diagnostics);

        $response = $this->getResponse();
        $this->assertNotNull($response);
        $this->assertArrayHasKey('error', $response);
    }

    // =========================================================================
    // TESTS: doAction - Errores de API / Host
    // =========================================================================

    public function testDoActionHostNotFound(): void
    {
        $this->setInput(['hostid' => 99999, 'operation' => 'ping']);
        $this->diagnostics->userType = self::USER_TYPE_ZABBIX_USER;

        $this->mockApiHostGet([]);

        $method = $this->reflection->getMethod('doAction');
        $method->setAccessible(true);
        $method->invoke($this->diagnostics);

        $response = $this->getResponse();
        $this->assertNotNull($response);
        $this->assertArrayHasKey('error', $response);
        $this->assertEquals('Host no encontrado o sin permisos de lectura.', $response['error']);
    }

    public function testDoActionHostNoInterfaces(): void
    {
        $this->setInput(['hostid' => 10001, 'operation' => 'ping']);
        $this->diagnostics->userType = self::USER_TYPE_ZABBIX_USER;

        $this->mockApiHostGet([
            [
                'hostid' => '10001',
                'host' => 'test-host',
                'name' => 'Test Server',
                'interfaces' => [],
            ],
        ]);

        $cmd = "ping -c 4 -W 2 " . escapeshellarg('test-host');
        $this->mockRunCommand([
            $cmd . '|15' => [
                'output' => 'PING output',
                'exit_code' => 0,
                'timed_out' => false,
            ],
        ]);

        $method = $this->reflection->getMethod('doAction');
        $method->setAccessible(true);
        $method->invoke($this->diagnostics);

        $response = $this->getResponse();
        $this->assertEquals('test-host', $response['target']);
    }

    public function testDoActionApiException(): void
    {
        $this->setInput(['hostid' => 10001, 'operation' => 'ping']);
        $this->diagnostics->userType = self::USER_TYPE_ZABBIX_ADMIN; // Necesario para pasar checkPermissions

        $this->mockApiHostGetException(new \RuntimeException('API connection failed'));

        $method = $this->reflection->getMethod('doAction');
        $method->setAccessible(true);
        $method->invoke($this->diagnostics);

        $response = $this->getResponse();
        $this->assertNotNull($response);
        $this->assertArrayHasKey('error', $response);
        $this->assertEquals('Error interno del servidor', $response['error']); // Mensaje seguro
    }

    // =========================================================================
    // TESTS: Timeouts por operación
    // =========================================================================

    public function testHandleUsesCorrectTimeoutForPing(): void
    {
        $this->setInput(['hostid' => 10001, 'operation' => 'ping']);
        $this->diagnostics->userType = self::USER_TYPE_ZABBIX_USER;

        $this->mockApiHostGet([
            [
                'hostid' => '10001',
                'host' => 'test-host',
                'name' => 'Test Server',
                'interfaces' => [
                    ['ip' => '192.168.1.10', 'dns' => '', 'useip' => '1', 'main' => '1'],
                ],
            ],
        ]);

        $this->mockRunCommand([
            '*' => [
                'output' => 'output',
                'exit_code' => 0,
                'timed_out' => false,
            ],
        ]);

        $method = $this->reflection->getMethod('doAction');
        $method->setAccessible(true);
        $method->invoke($this->diagnostics);

        $this->assertStringContainsString('ping -c 4 -W 2', $this->diagnostics->lastCommand);
        $this->assertEquals(15, $this->diagnostics->lastTimeout);
    }

    public function testHandleUsesCorrectTimeoutForTraceroute(): void
    {
        $this->setInput(['hostid' => 10001, 'operation' => 'traceroute']);
        $this->diagnostics->userType = self::USER_TYPE_ZABBIX_USER;

        $this->mockApiHostGet([
            [
                'hostid' => '10001',
                'host' => 'test-host',
                'name' => 'Test Server',
                'interfaces' => [
                    ['ip' => '192.168.1.10', 'dns' => '', 'useip' => '1', 'main' => '1'],
                ],
            ],
        ]);

        $this->mockRunCommand([
            '*' => [
                'output' => 'output',
                'exit_code' => 0,
                'timed_out' => false,
            ],
        ]);

        $method = $this->reflection->getMethod('doAction');
        $method->setAccessible(true);
        $method->invoke($this->diagnostics);

        $this->assertStringContainsString('traceroute -w 2 -q 1 -m 20', $this->diagnostics->lastCommand);
        $this->assertEquals(30, $this->diagnostics->lastTimeout);
    }

    public function testHandleUsesCorrectTimeoutForNmap(): void
    {
        $this->setInput(['hostid' => 10001, 'operation' => 'nmap']);
        $this->diagnostics->userType = self::USER_TYPE_ZABBIX_ADMIN;

        $this->mockApiHostGet([
            [
                'hostid' => '10001',
                'host' => 'test-host',
                'name' => 'Test Server',
                'interfaces' => [
                    ['ip' => '192.168.1.10', 'dns' => '', 'useip' => '1', 'main' => '1'],
                ],
            ],
        ]);

        $this->mockRunCommand([
            '*' => [
                'output' => 'output',
                'exit_code' => 0,
                'timed_out' => false,
            ],
        ]);

        $method = $this->reflection->getMethod('doAction');
        $method->setAccessible(true);
        $method->invoke($this->diagnostics);

        $this->assertStringContainsString('nmap -T4 -F', $this->diagnostics->lastCommand);
        $this->assertEquals(60, $this->diagnostics->lastTimeout);
    }
}