<?php

declare(strict_types=1);

namespace StreamLib\RabbitMqSuperStream;

use StreamLib\RabbitMqSuperStream\Config\SuperStreamConfig;
use StreamLib\RabbitMqSuperStream\Contract\SuperStreamClientInterface;
use StreamLib\RabbitMqSuperStream\Internal\HelperErrorMapper;
use StreamLib\RabbitMqSuperStream\Internal\HelperProcessManager;
use StreamLib\RabbitMqSuperStream\Internal\HttpJsonTransport;
use StreamLib\RabbitMqSuperStream\Message\PublishResult;

final class SuperStreamClient implements SuperStreamClientInterface
{
    private readonly SuperStreamConfig $config;

    public function __construct(
        array|SuperStreamConfig $config,
        private readonly HttpJsonTransport $transport = new HttpJsonTransport(),
        private readonly HelperProcessManager $helperProcessManager = new HelperProcessManager(),
        private readonly HelperErrorMapper $errorMapper = new HelperErrorMapper(),
    ) {
        $this->config = is_array($config) ? SuperStreamConfig::fromArray($config) : $config;
    }

    public function publish(
        string $body,
        string $routingKey,
        ?string $messageId = null,
        array $headers = [],
        ?string $contentType = null,
        ?string $correlationId = null,
        bool $waitForConfirm = true,
        ?int $confirmTimeoutMs = null,
    ): PublishResult {
        $helper = $this->helperProcessManager->ensureRunning($this->config);
        $requestId = bin2hex(random_bytes(16));

        $response = $this->transport->request(
            $helper['endpoint'],
            'POST',
            '/v1/publish',
            [
                'protocol_version' => 1,
                'request_id' => $requestId,
                'message' => [
                    'body_base64' => base64_encode($body),
                    'routing_key' => $routingKey,
                    'message_id' => $messageId,
                    'correlation_id' => $correlationId,
                    'content_type' => $contentType,
                    'headers' => $headers === [] ? (object) [] : $headers,
                ],
                'options' => [
                    'wait_for_confirm' => $waitForConfirm,
                    'confirm_timeout_ms' => $confirmTimeoutMs ?? $this->config->confirmTimeoutMs,
                ],
            ],
            $helper['auth_token'],
            $this->config->helperRpcTimeoutMs,
        );

        if (($response['status'] ?? null) !== 'ok') {
            throw $this->errorMapper->map(is_array($response['error'] ?? null) ? $response['error'] : []);
        }

        $result = is_array($response['result'] ?? null) ? $response['result'] : [];
        $result['request_id'] = $requestId;

        return PublishResult::fromArray($result);
    }

    public function healthCheck(): array
    {
        $helper = $this->helperProcessManager->ensureRunning($this->config);
        $response = $this->transport->request(
            $helper['endpoint'],
            'GET',
            '/v1/health',
            null,
            $helper['auth_token'],
            $this->config->helperRpcTimeoutMs,
        );

        if (($response['status'] ?? null) !== 'ok') {
            throw $this->errorMapper->map(is_array($response['error'] ?? null) ? $response['error'] : []);
        }

        return $response;
    }

    public function closeHelper(): void
    {
        $this->helperProcessManager->shutdown($this->config);
    }
}
