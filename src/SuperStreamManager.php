<?php

declare(strict_types=1);

namespace StreamLib\RabbitMqSuperStream;

use InvalidArgumentException;
use StreamLib\RabbitMqSuperStream\Config\SuperStreamConfig;

final class SuperStreamManager
{
    /**
     * @var array<string, array<string, mixed>|SuperStreamConfig>
     */
    private array $configurations;

    /**
     * @var array<string, SuperStreamClient>
     */
    private array $clients = [];

    /**
     * @param array<string, array<string, mixed>|SuperStreamConfig> $configurations
     */
    public function __construct(array $configurations)
    {
        $this->configurations = $configurations;
    }

    public function connection(string $name = 'default'): SuperStreamClient
    {
        if (isset($this->clients[$name])) {
            return $this->clients[$name];
        }

        if (!isset($this->configurations[$name])) {
            throw new InvalidArgumentException(sprintf('Undefined RabbitMQ super stream connection "%s".', $name));
        }

        return $this->clients[$name] = new SuperStreamClient($this->configurations[$name]);
    }
}
