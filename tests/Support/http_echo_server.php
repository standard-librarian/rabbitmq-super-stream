<?php

declare(strict_types=1);

$endpoint = $argv[1] ?? null;
$authToken = $argv[2] ?? '';
$readyFile = $argv[3] ?? null;
$responseJson = getenv('TEST_RESPONSE_JSON');

if ($endpoint === null || $readyFile === null || $responseJson === false) {
    fwrite(STDERR, "usage: php http_echo_server.php <endpoint> <auth-token> <ready-file>\n");
    exit(2);
}

$server = stream_socket_server($endpoint, $errorCode, $errorMessage);
if (!is_resource($server)) {
    fwrite(STDERR, "server error: {$errorMessage}\n");
    exit(1);
}

$boundAddress = stream_socket_get_name($server, false);
file_put_contents($readyFile, (string) $boundAddress);

$connection = @stream_socket_accept($server, 10);
if (!is_resource($connection)) {
    fclose($server);
    exit(1);
}

$request = '';
while (!str_contains($request, "\r\n\r\n")) {
    $chunk = fread($connection, 8192);
    if ($chunk === false || $chunk === '') {
        break;
    }
    $request .= $chunk;
}

[$rawHeaders] = array_pad(explode("\r\n\r\n", $request, 2), 2, '');
$headers = explode("\r\n", $rawHeaders);
$authHeader = '';
foreach ($headers as $headerLine) {
    if (stripos($headerLine, 'Authorization:') === 0) {
        $authHeader = trim(substr($headerLine, strlen('Authorization:')));
        break;
    }
}

$status = $authHeader === 'Bearer ' . $authToken ? '200 OK' : '401 Unauthorized';
$payload = $authHeader === 'Bearer ' . $authToken
    ? $responseJson
    : json_encode([
        'status' => 'error',
        'error' => [
            'code' => 'helper_unauthorized',
            'message' => 'invalid token',
            'retryable' => false,
        ],
    ], JSON_THROW_ON_ERROR);

$response = implode("\r\n", [
    'HTTP/1.1 ' . $status,
    'Content-Type: application/json',
    'Connection: close',
    'Content-Length: ' . strlen($payload),
    '',
    $payload,
]);

fwrite($connection, $response);
fclose($connection);
fclose($server);
