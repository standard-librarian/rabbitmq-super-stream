<?php

declare(strict_types=1);

namespace StreamLib\RabbitMqSuperStream\Internal;

use StreamLib\RabbitMqSuperStream\Exception\HelperTransportException;

final class HttpJsonTransport
{
    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    public function request(string $endpoint, string $method, string $path, ?array $payload, ?string $authToken, int $timeoutMs): array
    {
        $body = $payload === null ? '' : json_encode($payload, JSON_THROW_ON_ERROR);
        $stream = @stream_socket_client(
            $endpoint,
            $errorCode,
            $errorMessage,
            max($timeoutMs / 1000, 1),
        );

        if (!is_resource($stream)) {
            throw new HelperTransportException(sprintf('Unable to connect to helper endpoint "%s": %s', $endpoint, $errorMessage), [
                'endpoint' => $endpoint,
                'error_code' => $errorCode,
            ]);
        }

        stream_set_timeout($stream, (int) ceil($timeoutMs / 1000));

        $headers = [
            sprintf('%s %s HTTP/1.1', strtoupper($method), $path),
            'Host: local-helper',
            'Connection: close',
            'Accept: application/json',
            'Content-Type: application/json',
            'Content-Length: ' . strlen($body),
        ];
        if ($authToken !== null && $authToken !== '') {
            $headers[] = 'Authorization: Bearer ' . $authToken;
        }

        $request = implode("\r\n", $headers) . "\r\n\r\n" . $body;
        $written = 0;
        $length = strlen($request);
        while ($written < $length) {
            $bytes = fwrite($stream, substr($request, $written));
            if ($bytes === false || $bytes === 0) {
                fclose($stream);
                throw new HelperTransportException('Failed to write the complete request to the helper socket.', [
                    'endpoint' => $endpoint,
                    'written' => $written,
                    'expected' => $length,
                ]);
            }
            $written += $bytes;
        }

        $response = stream_get_contents($stream);
        $meta = stream_get_meta_data($stream);
        fclose($stream);

        if ($response === false || $response === '') {
            throw new HelperTransportException('The helper returned an empty response.', [
                'endpoint' => $endpoint,
                'timed_out' => $meta['timed_out'] ?? false,
            ]);
        }

        [$rawHeaders, $rawBody] = array_pad(explode("\r\n\r\n", $response, 2), 2, '');
        $statusLine = strtok($rawHeaders, "\r\n");
        if (!is_string($statusLine) || !preg_match('/^HTTP\/1\.[01]\s+(\d{3})/', $statusLine, $matches)) {
            throw new HelperTransportException('The helper returned an invalid HTTP response.', [
                'endpoint' => $endpoint,
            ]);
        }

        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            throw new HelperTransportException('The helper returned invalid JSON.', [
                'endpoint' => $endpoint,
                'status_code' => (int) $matches[1],
                'body' => $rawBody,
            ]);
        }

        $decoded['_http_status'] = (int) $matches[1];

        return $decoded;
    }
}
