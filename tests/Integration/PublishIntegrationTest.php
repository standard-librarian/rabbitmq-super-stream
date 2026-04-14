<?php

declare(strict_types=1);

namespace StreamLib\RabbitMqSuperStream\Tests\Integration;

use PHPUnit\Framework\TestCase;
use StreamLib\RabbitMqSuperStream\Exception\AuthenticationException;
use StreamLib\RabbitMqSuperStream\SuperStreamClient;

final class PublishIntegrationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (getenv('RUN_STREAM_INTEGRATION_TESTS') !== '1') {
            return;
        }

        $command = sprintf(
            'cd %s && go run ./cmd/declare-super-stream --host %s --port %s --username %s --password %s --vhost %s --super-stream %s',
            escapeshellarg(dirname(__DIR__, 2) . '/go'),
            escapeshellarg(getenv('RABBITMQ_STREAM_HOST') ?: '127.0.0.1'),
            escapeshellarg(getenv('RABBITMQ_STREAM_PORT') ?: '5552'),
            escapeshellarg(getenv('RABBITMQ_STREAM_USERNAME') ?: 'guest'),
            escapeshellarg(getenv('RABBITMQ_STREAM_PASSWORD') ?: 'guest'),
            escapeshellarg(getenv('RABBITMQ_STREAM_VHOST') ?: '/'),
            escapeshellarg(getenv('RABBITMQ_SUPER_STREAM') ?: 'orders'),
        );

        exec($command, $output, $exitCode);
        self::assertSame(0, $exitCode, implode("\n", $output));
    }

    public function test_it_publishes_to_rabbitmq_super_stream_and_confirms_delivery(): void
    {
        if (getenv('RUN_STREAM_INTEGRATION_TESTS') !== '1') {
            $this->markTestSkipped('Integration tests are disabled. Set RUN_STREAM_INTEGRATION_TESTS=1.');
        }

        $messageId = 'test-' . bin2hex(random_bytes(8));
        $client = new SuperStreamClient($this->baseConfig());

        $result = $client->publish(
            body: json_encode(['message_id' => $messageId], JSON_THROW_ON_ERROR),
            routingKey: 'customer-123',
            messageId: $messageId,
            contentType: 'application/json',
        );

        self::assertTrue($result->confirmed);
        self::assertSame($messageId, $result->messageId);

        $command = sprintf(
            'cd %s && go run ./cmd/consume-once --host %s --port %s --username %s --password %s --vhost %s --super-stream %s --message-id %s',
            escapeshellarg(dirname(__DIR__, 2) . '/go'),
            escapeshellarg(getenv('RABBITMQ_STREAM_HOST') ?: '127.0.0.1'),
            escapeshellarg(getenv('RABBITMQ_STREAM_PORT') ?: '5552'),
            escapeshellarg(getenv('RABBITMQ_STREAM_USERNAME') ?: 'guest'),
            escapeshellarg(getenv('RABBITMQ_STREAM_PASSWORD') ?: 'guest'),
            escapeshellarg(getenv('RABBITMQ_STREAM_VHOST') ?: '/'),
            escapeshellarg(getenv('RABBITMQ_SUPER_STREAM') ?: 'orders'),
            escapeshellarg($messageId),
        );

        exec($command, $output, $exitCode);
        self::assertSame(0, $exitCode, implode("\n", $output));
    }

    public function test_it_maps_authentication_failures_to_php_exceptions(): void
    {
        if (getenv('RUN_STREAM_INTEGRATION_TESTS') !== '1') {
            $this->markTestSkipped('Integration tests are disabled. Set RUN_STREAM_INTEGRATION_TESTS=1.');
        }

        $this->expectException(AuthenticationException::class);

        $config = $this->baseConfig();
        $config['password'] = 'definitely-wrong';

        $client = new SuperStreamClient($config);
        $client->publish(body: '{"ping":true}', routingKey: 'customer-123');
    }

    /**
     * @return array<string, mixed>
     */
    private function baseConfig(): array
    {
        return [
            'host' => getenv('RABBITMQ_STREAM_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('RABBITMQ_STREAM_PORT') ?: 5552),
            'username' => getenv('RABBITMQ_STREAM_USERNAME') ?: 'guest',
            'password' => getenv('RABBITMQ_STREAM_PASSWORD') ?: 'guest',
            'vhost' => getenv('RABBITMQ_STREAM_VHOST') ?: '/',
            'super_stream' => getenv('RABBITMQ_SUPER_STREAM') ?: 'orders',
            'helper_binary' => getenv('SUPER_STREAM_HELPER_BINARY') ?: null,
        ];
    }
}
