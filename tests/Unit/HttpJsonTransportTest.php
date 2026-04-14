<?php

declare(strict_types=1);

namespace StreamLib\RabbitMqSuperStream\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StreamLib\RabbitMqSuperStream\Internal\HttpJsonTransport;

final class HttpJsonTransportTest extends TestCase
{
    public function test_it_sends_requests_over_unix_domain_sockets(): void
    {
        $directory = sys_get_temp_dir() . '/stream-lib-tests/' . bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);

        $socketPath = $directory . '/helper.sock';
        $readyFile = $directory . '/unix.ready';
        $responseJson = json_encode([
            'status' => 'ok',
            'result' => ['transport' => 'unix'],
        ], JSON_THROW_ON_ERROR);

        $process = $this->spawnEchoServer('unix://' . $socketPath, 'secret', $readyFile, $responseJson);
        try {
            $this->waitForReadyFile($readyFile);
            $response = (new HttpJsonTransport())->request('unix://' . $socketPath, 'GET', '/v1/health', null, 'secret', 2000);

            self::assertSame('ok', $response['status']);
            self::assertSame('unix', $response['result']['transport']);
        } finally {
            $this->stopProcess($process);
        }
    }

    public function test_it_sends_requests_over_tcp(): void
    {
        $directory = sys_get_temp_dir() . '/stream-lib-tests/' . bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);

        $readyFile = $directory . '/tcp.ready';
        $responseJson = json_encode([
            'status' => 'ok',
            'result' => ['transport' => 'tcp'],
        ], JSON_THROW_ON_ERROR);

        $process = $this->spawnEchoServer('tcp://127.0.0.1:0', 'secret', $readyFile, $responseJson);
        try {
            $this->waitForReadyFile($readyFile);
            $endpoint = 'tcp://' . trim((string) file_get_contents($readyFile));
            $response = (new HttpJsonTransport())->request($endpoint, 'GET', '/v1/health', null, 'secret', 2000);

            self::assertSame('ok', $response['status']);
            self::assertSame('tcp', $response['result']['transport']);
        } finally {
            $this->stopProcess($process);
        }
    }

    private function spawnEchoServer(string $endpoint, string $token, string $readyFile, string $responseJson)
    {
        $command = [
            PHP_BINARY,
            dirname(__DIR__) . '/Support/http_echo_server.php',
            $endpoint,
            $token,
            $readyFile,
        ];

        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            dirname(__DIR__, 2),
            ['TEST_RESPONSE_JSON' => $responseJson],
        );

        self::assertIsResource($process);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        return $process;
    }

    private function waitForReadyFile(string $path): void
    {
        $deadline = microtime(true) + 3;
        while (microtime(true) < $deadline) {
            if (is_file($path) && trim((string) file_get_contents($path)) !== '') {
                return;
            }
            usleep(50_000);
        }

        self::fail(sprintf('Timed out waiting for ready file "%s".', $path));
    }

    private function stopProcess($process): void
    {
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
    }
}
