<?php

declare(strict_types=1);

namespace StreamLib\RabbitMqSuperStream\Contract;

use StreamLib\RabbitMqSuperStream\Message\PublishResult;

interface SuperStreamClientInterface
{
    /**
     * @param array<string, scalar|null> $headers
     */
    public function publish(
        string $body,
        string $routingKey,
        ?string $messageId = null,
        array $headers = [],
        ?string $contentType = null,
        ?string $correlationId = null,
        bool $waitForConfirm = true,
        ?int $confirmTimeoutMs = null,
    ): PublishResult;

    /**
     * @return array<string, mixed>
     */
    public function healthCheck(): array;

    public function closeHelper(): void;
}
