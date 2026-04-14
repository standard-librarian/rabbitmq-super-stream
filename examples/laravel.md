# Laravel Usage

1. Publish the package config:

```bash
php artisan vendor:publish --tag=rabbitmq-super-stream-config
```

2. Configure `config/rabbitmq-super-stream.php`.

3. Resolve the manager or use the facade:

```php
use StreamLib\RabbitMqSuperStream\Laravel\Facades\SuperStream;

$result = SuperStream::connection('default')->publish(
    body: json_encode(['order_id' => 123], JSON_THROW_ON_ERROR),
    routingKey: 'customer-123',
    messageId: 'msg-123',
    contentType: 'application/json',
);
```
