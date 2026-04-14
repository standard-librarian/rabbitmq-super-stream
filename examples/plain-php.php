<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use StreamLib\RabbitMqSuperStream\SuperStreamClient;

$client = new SuperStreamClient([
    'host' => '127.0.0.1',
    'port' => 5552,
    'username' => 'guest',
    'password' => 'guest',
    'vhost' => '/',
    'super_stream' => 'orders',
]);

$result = $client->publish(
    body: json_encode(['order_id' => 123], JSON_THROW_ON_ERROR),
    routingKey: 'customer-123',
    messageId: 'msg-123',
    contentType: 'application/json',
);

var_dump($result);
