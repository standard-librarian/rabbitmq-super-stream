<?php

declare(strict_types=1);

namespace StreamLib\RabbitMqSuperStream\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StreamLib\RabbitMqSuperStream\Config\SuperStreamConfig;
use StreamLib\RabbitMqSuperStream\Exception\ConfigurationException;

final class SuperStreamConfigTest extends TestCase
{
    public function test_it_builds_a_valid_config_from_array(): void
    {
        $config = SuperStreamConfig::fromArray([
            'host' => 'rabbitmq',
            'port' => 5552,
            'username' => 'guest',
            'password' => 'guest',
            'vhost' => '/',
            'super_stream' => 'orders',
            'helper_transport_preference' => 'auto',
        ]);

        self::assertSame('rabbitmq', $config->host);
        self::assertSame('orders', $config->superStream);
        self::assertNotSame('', $config->configHash());
    }

    public function test_it_rejects_invalid_transport_preference(): void
    {
        $this->expectException(ConfigurationException::class);

        SuperStreamConfig::fromArray([
            'host' => 'rabbitmq',
            'port' => 5552,
            'username' => 'guest',
            'password' => 'guest',
            'vhost' => '/',
            'super_stream' => 'orders',
            'helper_transport_preference' => 'udp',
        ]);
    }

    public function test_it_rejects_tcp_helper_endpoint_without_a_port(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('helper_endpoint');

        SuperStreamConfig::fromArray([
            'host' => 'rabbitmq-stg.example.internal',
            'port' => 5552,
            'username' => 'guest',
            'password' => 'guest',
            'vhost' => '/',
            'super_stream' => 'orders',
            'helper_endpoint' => 'tcp://rabbitmq-stg.example.internal',
        ]);
    }

    public function test_it_accepts_hostname_helper_endpoint_with_a_port(): void
    {
        $config = SuperStreamConfig::fromArray([
            'host' => 'rabbitmq-stg.example.internal',
            'port' => 5552,
            'username' => 'guest',
            'password' => 'guest',
            'vhost' => '/',
            'super_stream' => 'orders',
            'helper_endpoint' => 'helper.sidecar.internal:9081',
        ]);

        self::assertSame('helper.sidecar.internal:9081', $config->helperEndpoint);
    }

    public function test_it_does_not_read_helper_endpoint_from_the_environment(): void
    {
        putenv('SUPER_STREAM_HELPER_ENDPOINT=tcp://rabbitmq-stg.example.internal');
        putenv('SUPER_STREAM_HELPER_AUTH_TOKEN=secret-token');

        try {
            $config = SuperStreamConfig::fromArray([
                'host' => 'rabbitmq-stg.example.internal',
                'port' => 5552,
                'username' => 'guest',
                'password' => 'guest',
                'vhost' => '/',
                'super_stream' => 'orders',
            ]);

            self::assertNull($config->helperEndpoint);
            self::assertNull($config->helperAuthToken);
        } finally {
            putenv('SUPER_STREAM_HELPER_ENDPOINT');
            putenv('SUPER_STREAM_HELPER_AUTH_TOKEN');
        }
    }
}
