<?php

declare(strict_types=1);

namespace StreamLib\RabbitMqSuperStream\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StreamLib\RabbitMqSuperStream\Config\SuperStreamConfig;
use StreamLib\RabbitMqSuperStream\Internal\HelperProcessManager;

final class HelperProcessManagerTest extends TestCase
{
    public function test_it_normalizes_hostname_helper_endpoint_to_tcp(): void
    {
        $manager = new HelperProcessManager();
        $helper = $manager->ensureRunning(SuperStreamConfig::fromArray([
            'host' => 'rabbitmq-stg.example.internal',
            'port' => 5552,
            'username' => 'guest',
            'password' => 'guest',
            'vhost' => '/',
            'super_stream' => 'orders',
            'helper_endpoint' => 'helper.sidecar.internal:9081',
        ]));

        self::assertSame('tcp://helper.sidecar.internal:9081', $helper['endpoint']);
        self::assertSame('tcp', $helper['manifest']['transport']);
    }
}
