<?php

declare(strict_types=1);

namespace StreamLib\RabbitMqSuperStream\Internal;

use StreamLib\RabbitMqSuperStream\Config\SuperStreamConfig;
use StreamLib\RabbitMqSuperStream\Exception\AuthenticationException;
use StreamLib\RabbitMqSuperStream\Exception\ConnectionException;
use StreamLib\RabbitMqSuperStream\Exception\HelperStartupException;
use StreamLib\RabbitMqSuperStream\Exception\SuperStreamNotFoundException;

final class HelperProcessManager
{
    public function __construct(
        private readonly BinaryResolver $binaryResolver = new BinaryResolver(),
        private readonly HelperManifestStore $manifestStore = new HelperManifestStore(),
        private readonly HttpJsonTransport $transport = new HttpJsonTransport(),
    ) {
    }

    /**
     * @return array{endpoint:string,auth_token:?string,manifest:array<string,mixed>}
     */
    public function ensureRunning(SuperStreamConfig $config): array
    {
        if ($config->helperEndpoint !== null) {
            return [
                'endpoint' => $this->normalizeEndpoint($config->helperEndpoint),
                'auth_token' => $config->helperAuthToken,
                'manifest' => [
                    'endpoint' => $config->helperEndpoint,
                    'transport' => str_starts_with($config->helperEndpoint, 'unix://') ? 'unix' : 'tcp',
                    'pid' => 0,
                ],
            ];
        }

        $runtimeDir = $config->runtimeBaseDirectory() . '/' . substr($config->configHash(), 0, 24);
        $this->ensureDirectory($runtimeDir);
        $lockHandle = fopen($runtimeDir . '/helper.lock', 'c+');
        if ($lockHandle === false) {
            throw new HelperStartupException(sprintf('Unable to open helper lock file in "%s".', $runtimeDir));
        }

        try {
            if (!flock($lockHandle, LOCK_EX)) {
                throw new HelperStartupException('Unable to acquire helper startup lock.');
            }

            $manifestPath = $runtimeDir . '/manifest.json';
            $tokenPath = $runtimeDir . '/auth.token';
            $configPath = $runtimeDir . '/config.json';
            $socketPath = $runtimeDir . '/helper.sock';
            $logPath = $runtimeDir . '/helper.log';
            $authToken = $this->loadOrCreateAuthToken($tokenPath);

            $manifest = $this->manifestStore->read($manifestPath);
            if ($manifest !== null && $this->isHealthy($manifest, $authToken, $config->helperRpcTimeoutMs)) {
                return [
                    'endpoint' => $this->normalizeEndpoint((string) $manifest['endpoint']),
                    'auth_token' => $authToken,
                    'manifest' => $manifest,
                ];
            }

            $this->cleanupStaleRuntime($manifest, $socketPath, $manifestPath);
            file_put_contents(
                $configPath,
                json_encode(
                    $config->toHelperPayload($authToken, $manifestPath, $socketPath, $logPath),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                ),
            );

            $binary = $this->binaryResolver->resolve($config);
            $this->spawn($binary, $configPath, $logPath);

            $deadline = microtime(true) + ($config->helperStartupTimeoutMs / 1000);
            do {
                usleep(100_000);
                $manifest = $this->manifestStore->read($manifestPath);
                if ($manifest !== null && $this->isHealthy($manifest, $authToken, $config->helperRpcTimeoutMs)) {
                    return [
                        'endpoint' => $this->normalizeEndpoint((string) $manifest['endpoint']),
                        'auth_token' => $authToken,
                        'manifest' => $manifest,
                    ];
                }
            } while (microtime(true) < $deadline);

            $this->throwStartupFailureFromLog($logPath, $runtimeDir);

            throw new HelperStartupException('The helper did not become healthy before the startup timeout elapsed.', [
                'runtime_dir' => $runtimeDir,
                'log_path' => $logPath,
            ]);
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    public function shutdown(SuperStreamConfig $config): void
    {
        $helper = $this->ensureRunning($config);
        $this->transport->request(
            $helper['endpoint'],
            'POST',
            '/v1/shutdown',
            null,
            $helper['auth_token'],
            $config->helperRpcTimeoutMs,
        );
    }

    /**
     * @param array<string, mixed>|null $manifest
     */
    private function cleanupStaleRuntime(?array $manifest, string $socketPath, string $manifestPath): void
    {
        if ($manifest !== null && isset($manifest['pid']) && function_exists('posix_kill')) {
            @posix_kill((int) $manifest['pid'], defined('SIGTERM') ? constant('SIGTERM') : 15);
        }

        @unlink($socketPath);
        @unlink($manifestPath);
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new HelperStartupException(sprintf('Unable to create runtime directory "%s".', $directory));
        }

        @chmod($directory, 0700);
    }

    private function loadOrCreateAuthToken(string $path): string
    {
        if (is_file($path)) {
            $existing = trim((string) file_get_contents($path));
            if ($existing !== '') {
                return $existing;
            }
        }

        $token = bin2hex(random_bytes(32));
        if (file_put_contents($path, $token) === false) {
            throw new HelperStartupException(sprintf('Unable to write helper auth token "%s".', $path));
        }

        @chmod($path, 0600);

        return $token;
    }

    private function spawn(string $binary, string $configPath, string $logPath): void
    {
        if (!function_exists('proc_open')) {
            throw new HelperStartupException('The PHP function proc_open() is required to launch the helper process.');
        }

        $command = sprintf(
            'exec %s serve --config %s >> %s 2>&1 < /dev/null &',
            escapeshellarg($binary),
            escapeshellarg($configPath),
            escapeshellarg($logPath),
        );

        $process = proc_open(
            ['/bin/sh', '-lc', $command],
            [
                0 => ['pipe', 'r'],
                1 => ['file', $logPath, 'a'],
                2 => ['file', $logPath, 'a'],
            ],
            $pipes,
        );

        if (!is_resource($process)) {
            throw new HelperStartupException('Failed to start the helper process.');
        }

        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        proc_close($process);
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function isHealthy(array $manifest, string $authToken, int $timeoutMs): bool
    {
        if (!isset($manifest['endpoint'])) {
            return false;
        }

        try {
            $response = $this->transport->request(
                $this->normalizeEndpoint((string) $manifest['endpoint']),
                'GET',
                '/v1/health',
                null,
                $authToken,
                $timeoutMs,
            );
        } catch (\Throwable) {
            return false;
        }

        return (($response['status'] ?? null) === 'ok');
    }

    private function normalizeEndpoint(string $endpoint): string
    {
        if (str_starts_with($endpoint, 'unix://') || str_starts_with($endpoint, 'tcp://')) {
            return $endpoint;
        }

        if (preg_match('/^\d+\.\d+\.\d+\.\d+:\d+$/', $endpoint) === 1) {
            return 'tcp://' . $endpoint;
        }

        return 'unix://' . $endpoint;
    }

    private function throwStartupFailureFromLog(string $logPath, string $runtimeDir): void
    {
        if (!is_file($logPath)) {
            return;
        }

        $contents = trim((string) file_get_contents($logPath));
        if ($contents === '') {
            return;
        }

        $message = $this->lastLogLine($contents);
        $context = [
            'runtime_dir' => $runtimeDir,
            'log_path' => $logPath,
            'helper_log' => $contents,
        ];

        $normalized = strtolower($message);
        if (str_contains($normalized, 'authentication failed')) {
            throw new AuthenticationException($message, $context);
        }

        if (str_contains($normalized, 'does not exist')) {
            throw new SuperStreamNotFoundException($message, $context);
        }

        if (str_contains($normalized, 'connect to the broker')) {
            throw new ConnectionException($message, $context);
        }
    }

    private function lastLogLine(string $contents): string
    {
        $lines = preg_split('/\R/', $contents) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines), static fn (string $line): bool => $line !== ''));

        return $lines === [] ? $contents : (string) end($lines);
    }
}
