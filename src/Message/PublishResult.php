<?php

declare(strict_types=1);

namespace StreamLib\RabbitMqSuperStream\Message;

final readonly class PublishResult
{
    public function __construct(
        public string $requestId,
        public ?string $messageId,
        public bool $confirmed,
        public string $transport,
        public int $helperPid,
        public string $acceptedAt,
        public ?string $confirmedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            requestId: (string) $payload['request_id'],
            messageId: isset($payload['message_id']) ? (string) $payload['message_id'] : null,
            confirmed: (bool) ($payload['confirmed'] ?? false),
            transport: (string) $payload['transport'],
            helperPid: (int) $payload['helper_pid'],
            acceptedAt: (string) $payload['accepted_at'],
            confirmedAt: isset($payload['confirmed_at']) ? (string) $payload['confirmed_at'] : null,
        );
    }
}
