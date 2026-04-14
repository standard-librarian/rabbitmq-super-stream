<?php

declare(strict_types=1);

namespace StreamLib\RabbitMqSuperStream\Internal;

use StreamLib\RabbitMqSuperStream\Exception\AuthenticationException;
use StreamLib\RabbitMqSuperStream\Exception\ConfigurationException;
use StreamLib\RabbitMqSuperStream\Exception\ConnectionException;
use StreamLib\RabbitMqSuperStream\Exception\HelperStartupException;
use StreamLib\RabbitMqSuperStream\Exception\HelperTransportException;
use StreamLib\RabbitMqSuperStream\Exception\PublishIndeterminateException;
use StreamLib\RabbitMqSuperStream\Exception\PublishRejectedException;
use StreamLib\RabbitMqSuperStream\Exception\SuperStreamException;
use StreamLib\RabbitMqSuperStream\Exception\SuperStreamNotFoundException;

final class HelperErrorMapper
{
    /**
     * @param array<string, mixed> $payload
     */
    public function map(array $payload): SuperStreamException
    {
        $message = (string) ($payload['message'] ?? 'The helper returned an unknown error.');
        $context = isset($payload['details']) && is_array($payload['details']) ? $payload['details'] : [];

        return match ((string) ($payload['code'] ?? 'helper_transport_error')) {
            'configuration_error', 'validation_failed' => new ConfigurationException($message, $context),
            'authentication_failed', 'helper_unauthorized' => new AuthenticationException($message, $context),
            'connection_failed' => new ConnectionException($message, $context),
            'super_stream_not_found' => new SuperStreamNotFoundException($message, $context),
            'publish_rejected' => new PublishRejectedException($message, $context),
            'publish_indeterminate' => new PublishIndeterminateException($message, $context),
            'helper_startup_failed' => new HelperStartupException($message, $context),
            default => new HelperTransportException($message, $context),
        };
    }
}
