<?php

declare(strict_types=1);

namespace StreamLib\RabbitMqSuperStream\Exception;

use RuntimeException;
use Throwable;

class SuperStreamException extends RuntimeException
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(string $message, private readonly array $context = [], int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }
}
