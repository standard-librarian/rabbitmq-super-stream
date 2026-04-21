<?php

declare(strict_types=1);

namespace StreamLib\RabbitMqSuperStream\Config;

use StreamLib\RabbitMqSuperStream\Exception\ConfigurationException;

final readonly class SuperStreamConfig
{
    public function __construct(
        public string $host,
        public int $port,
        public string $username,
        public string $password,
        public string $vhost,
        public string $superStream,
        public bool $useTls = false,
        public bool $verifyTls = true,
        public ?string $tlsServerName = null,
        public int $connectTimeoutMs = 3000,
        public int $confirmTimeoutMs = 10000,
        public int $helperRpcTimeoutMs = 15000,
        public int $helperStartupTimeoutMs = 10000,
        public int $helperShutdownTimeoutMs = 5000,
        public int $helperMaxQueueSize = 1024,
        public string $helperTransportPreference = 'auto',
        public ?string $helperRuntimeDir = null,
        public ?string $helperBinary = null,
        public ?string $helperEndpoint = null,
        public ?string $helperAuthToken = null,
    ) {
        $this->assertValid();
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $host = self::string($config, 'host', '127.0.0.1');
        $port = self::int($config, 'port', 5552);
        $explicitHelperEndpoint = self::nullableString($config, 'helper_endpoint');

        return new self(
            host: $host,
            port: $port,
            username: self::string($config, 'username', 'guest'),
            password: self::stringAllowEmpty($config, 'password', 'guest'),
            vhost: self::string($config, 'vhost', '/'),
            superStream: self::string($config, 'super_stream'),
            useTls: self::bool($config, 'use_tls', false),
            verifyTls: self::bool($config, 'verify_tls', true),
            tlsServerName: self::nullableString($config, 'tls_server_name'),
            connectTimeoutMs: self::int($config, 'connect_timeout_ms', 3000),
            confirmTimeoutMs: self::int($config, 'confirm_timeout_ms', 10000),
            helperRpcTimeoutMs: self::int($config, 'helper_rpc_timeout_ms', 15000),
            helperStartupTimeoutMs: self::int($config, 'helper_startup_timeout_ms', 10000),
            helperShutdownTimeoutMs: self::int($config, 'helper_shutdown_timeout_ms', 5000),
            helperMaxQueueSize: self::int($config, 'helper_max_queue_size', 1024),
            helperTransportPreference: self::string($config, 'helper_transport_preference', 'auto'),
            helperRuntimeDir: self::nullableString($config, 'helper_runtime_dir'),
            helperBinary: self::nullableString($config, 'helper_binary') ?? self::envOrNull('SUPER_STREAM_HELPER_BINARY'),
            helperEndpoint: $explicitHelperEndpoint ?? self::sanitizeHelperEndpointFromEnv(self::envOrNull('SUPER_STREAM_HELPER_ENDPOINT'), $host, $port),
            helperAuthToken: self::nullableString($config, 'helper_auth_token') ?? self::envOrNull('SUPER_STREAM_HELPER_AUTH_TOKEN'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function localHelperSignature(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'password' => $this->password,
            'vhost' => $this->vhost,
            'super_stream' => $this->superStream,
            'use_tls' => $this->useTls,
            'verify_tls' => $this->verifyTls,
            'tls_server_name' => $this->tlsServerName,
            'connect_timeout_ms' => $this->connectTimeoutMs,
            'confirm_timeout_ms' => $this->confirmTimeoutMs,
            'helper_rpc_timeout_ms' => $this->helperRpcTimeoutMs,
            'helper_shutdown_timeout_ms' => $this->helperShutdownTimeoutMs,
            'helper_max_queue_size' => $this->helperMaxQueueSize,
            'helper_transport_preference' => $this->helperTransportPreference,
        ];
    }

    public function configHash(): string
    {
        return hash('sha256', json_encode($this->localHelperSignature(), JSON_THROW_ON_ERROR));
    }

    public function runtimeBaseDirectory(): string
    {
        $base = $this->helperRuntimeDir ?: self::defaultRuntimeDirectory();

        return rtrim($base, '/');
    }

    /**
     * @return array<string, mixed>
     */
    public function toHelperPayload(string $authToken, string $manifestPath, string $socketPath, string $logPath): array
    {
        return [
            'connection' => [
                'host' => $this->host,
                'port' => $this->port,
                'username' => $this->username,
                'password' => $this->password,
                'vhost' => $this->vhost,
                'super_stream' => $this->superStream,
                'use_tls' => $this->useTls,
                'verify_tls' => $this->verifyTls,
                'tls_server_name' => $this->tlsServerName,
                'connect_timeout_ms' => $this->connectTimeoutMs,
                'confirm_timeout_ms' => $this->confirmTimeoutMs,
            ],
            'runtime' => [
                'protocol_version' => 1,
                'transport_preference' => $this->helperTransportPreference,
                'socket_path' => $socketPath,
                'manifest_path' => $manifestPath,
                'log_path' => $logPath,
                'auth_token' => $authToken,
                'rpc_timeout_ms' => $this->helperRpcTimeoutMs,
                'shutdown_timeout_ms' => $this->helperShutdownTimeoutMs,
                'max_queue_size' => $this->helperMaxQueueSize,
            ],
        ];
    }

    private function assertValid(): void
    {
        if ($this->host === '') {
            throw new ConfigurationException('The "host" option must not be empty.');
        }

        if ($this->port < 1 || $this->port > 65535) {
            throw new ConfigurationException('The "port" option must be between 1 and 65535.');
        }

        if ($this->username === '') {
            throw new ConfigurationException('The "username" option must not be empty.');
        }

        if ($this->superStream === '') {
            throw new ConfigurationException('The "super_stream" option must not be empty.');
        }

        if (!in_array($this->helperTransportPreference, ['auto', 'unix', 'tcp'], true)) {
            throw new ConfigurationException('The "helper_transport_preference" option must be one of: auto, unix, tcp.');
        }

        if ($this->helperEndpoint !== null) {
            self::assertValidHelperEndpoint($this->helperEndpoint);
        }

        foreach ([
            'connectTimeoutMs' => $this->connectTimeoutMs,
            'confirmTimeoutMs' => $this->confirmTimeoutMs,
            'helperRpcTimeoutMs' => $this->helperRpcTimeoutMs,
            'helperStartupTimeoutMs' => $this->helperStartupTimeoutMs,
            'helperShutdownTimeoutMs' => $this->helperShutdownTimeoutMs,
            'helperMaxQueueSize' => $this->helperMaxQueueSize,
        ] as $name => $value) {
            if ($value < 1) {
                throw new ConfigurationException(sprintf('The "%s" option must be greater than zero.', $name));
            }
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function string(array $config, string $key, ?string $default = null): string
    {
        $value = $config[$key] ?? $default;

        if (!is_string($value) || $value === '') {
            throw new ConfigurationException(sprintf('The "%s" option must be a non-empty string.', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function nullableString(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value)) {
            throw new ConfigurationException(sprintf('The "%s" option must be a string or null.', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function stringAllowEmpty(array $config, string $key, ?string $default = null): string
    {
        $value = $config[$key] ?? $default;

        if (!is_string($value)) {
            throw new ConfigurationException(sprintf('The "%s" option must be a string.', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function int(array $config, string $key, ?int $default = null): int
    {
        $value = $config[$key] ?? $default;

        if (!is_int($value) && !(is_string($value) && is_numeric($value))) {
            throw new ConfigurationException(sprintf('The "%s" option must be an integer.', $key));
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function bool(array $config, string $key, bool $default): bool
    {
        $value = $config[$key] ?? $default;

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value) || is_int($value)) {
            $converted = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($converted !== null) {
                return $converted;
            }
        }

        throw new ConfigurationException(sprintf('The "%s" option must be a boolean.', $key));
    }

    private static function envOrNull(string $name): ?string
    {
        $value = getenv($name);

        return $value === false || $value === '' ? null : $value;
    }

    private static function sanitizeHelperEndpointFromEnv(?string $endpoint, string $brokerHost, int $brokerPort): ?string
    {
        if ($endpoint === null) {
            return null;
        }

        $endpoint = trim($endpoint);
        if ($endpoint === '') {
            return null;
        }

        if (self::matchesBrokerEndpoint($endpoint, $brokerHost, $brokerPort)) {
            return null;
        }

        if (!self::isWellFormedHelperEndpoint($endpoint)) {
            return null;
        }

        return $endpoint;
    }

    private static function assertValidHelperEndpoint(string $endpoint): void
    {
        if (!self::isWellFormedHelperEndpoint($endpoint)) {
            throw new ConfigurationException(
                'The "helper_endpoint" option must be a Unix socket path or a TCP endpoint with a host and port. ' .
                'Leave it unset to let the package launch its own local helper. Do not point it at the RabbitMQ broker host.',
            );
        }
    }

    private static function assertValidTcpHelperEndpoint(string $endpoint): void
    {
        $parts = parse_url($endpoint);
        $host = $parts['host'] ?? null;
        $port = $parts['port'] ?? null;

        if (!is_string($host) || $host === '' || !is_int($port) || $port < 1 || $port > 65535) {
            throw new ConfigurationException(
                'The "helper_endpoint" option must include both a host and port for TCP endpoints, for example ' .
                '"tcp://127.0.0.1:9081". Leave it unset to use the built-in local helper.',
            );
        }
    }

    private static function looksLikeHostPort(string $value): bool
    {
        if (preg_match('/^\[[0-9a-f:.]+\]:\d+$/i', $value) === 1) {
            return true;
        }

        return preg_match('/^[A-Za-z0-9.-]+:\d+$/', $value) === 1;
    }

    private static function isWellFormedHelperEndpoint(string $endpoint): bool
    {
        if (str_starts_with($endpoint, 'unix://')) {
            return substr($endpoint, 7) !== '';
        }

        if (str_starts_with($endpoint, 'tcp://')) {
            try {
                self::assertValidTcpHelperEndpoint($endpoint);

                return true;
            } catch (ConfigurationException) {
                return false;
            }
        }

        if (DIRECTORY_SEPARATOR === '/' && str_starts_with($endpoint, '/')) {
            return true;
        }

        if (self::looksLikeHostPort($endpoint)) {
            try {
                self::assertValidTcpHelperEndpoint('tcp://' . $endpoint);

                return true;
            } catch (ConfigurationException) {
                return false;
            }
        }

        return false;
    }

    private static function matchesBrokerEndpoint(string $endpoint, string $brokerHost, int $brokerPort): bool
    {
        $brokerTargets = [
            $brokerHost,
            sprintf('%s:%d', $brokerHost, $brokerPort),
            sprintf('tcp://%s', $brokerHost),
            sprintf('tcp://%s:%d', $brokerHost, $brokerPort),
        ];

        return in_array($endpoint, $brokerTargets, true);
    }

    private static function defaultRuntimeDirectory(): string
    {
        if (DIRECTORY_SEPARATOR === '/') {
            return '/tmp/ssrs';
        }

        return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ssrs';
    }
}
