<?php

declare(strict_types=1);

namespace StreamLib\RabbitMqSuperStream\Laravel\Facades;

use StreamLib\RabbitMqSuperStream\SuperStreamManager;

class SuperStream extends \Illuminate\Support\Facades\Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SuperStreamManager::class;
    }
}
