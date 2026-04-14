<?php

declare(strict_types=1);

namespace StreamLib\RabbitMqSuperStream\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StreamLib\RabbitMqSuperStream\Config\SuperStreamConfig;
use StreamLib\RabbitMqSuperStream\Internal\BinaryResolver;

final class BinaryResolverTest extends TestCase
{
    public function test_it_uses_an_explicit_helper_binary_override(): void
    {
        $directory = sys_get_temp_dir() . '/stream-lib-tests/' . bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        $binary = $directory . '/helper';
        file_put_contents($binary, "#!/bin/sh\nexit 0\n");
        chmod($binary, 0700);

        $config = SuperStreamConfig::fromArray([
            'host' => 'rabbitmq',
            'port' => 5552,
            'username' => 'guest',
            'password' => 'guest',
            'vhost' => '/',
            'super_stream' => 'orders',
            'helper_binary' => $binary,
        ]);

        self::assertSame($binary, (new BinaryResolver())->resolve($config));
    }
}
