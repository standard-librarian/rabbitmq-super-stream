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
}
