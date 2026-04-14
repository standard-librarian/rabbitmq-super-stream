<?php

declare(strict_types=1);

namespace StreamLib\RabbitMqSuperStream\Laravel;

use StreamLib\RabbitMqSuperStream\SuperStreamManager;

class SuperStreamServiceProvider extends \Illuminate\Support\ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__, 2) . '/config/rabbitmq-super-stream.php', 'rabbitmq-super-stream');

        $this->app->singleton(SuperStreamManager::class, function ($app): SuperStreamManager {
            $config = (array) $app['config']->get('rabbitmq-super-stream.connections', []);

            return new SuperStreamManager($config);
        });
    }

    public function boot(): void
    {
        $this->publishes([
            dirname(__DIR__, 2) . '/config/rabbitmq-super-stream.php' => config_path('rabbitmq-super-stream.php'),
        ], 'rabbitmq-super-stream-config');
    }
}
