# RabbitMQ Super Stream Publisher for PHP

`stream-lib/rabbitmq-super-stream` gives PHP applications a native-feeling API for publishing to RabbitMQ Super Streams while delegating stream-specific work to an internal Go helper binary.

The PHP package is the public surface. The Go helper is an implementation detail that is started and managed automatically.

## Features

- Native PHP API with no FFI and no custom PHP extension
- RabbitMQ Super Stream publishing powered by the official Go stream client
- Local HTTP+JSON protocol over Unix domain sockets when available
- Automatic fallback to `127.0.0.1` TCP for local helper transport
- Helper reuse across PHP requests per effective config hash
- Connects to an existing RabbitMQ super stream; production code does not declare streams
- Publish confirmations, helper health checks, retries, and structured error mapping
- Plain PHP and Laravel integration

## Requirements

- PHP `^8.2`
- `proc_open`, `flock`, `json`, and `stream_socket_client`
- Supported bundled helper targets:
  - `linux-amd64`
  - `linux-arm64`
  - `darwin-amd64`
  - `darwin-arm64`

Windows is not supported in v1. The protocol already supports TCP fallback, so Windows support can be added later without changing the public PHP API.

## Installation

```bash
composer require stream-lib/rabbitmq-super-stream
```

End users do not need Go installed if they use one of the bundled binary targets above.

If you want to override the helper binary or connect to a separately managed helper:

```bash
export SUPER_STREAM_HELPER_BINARY=/absolute/path/to/rabbitmq-super-stream-helper
export SUPER_STREAM_HELPER_ENDPOINT=tcp://127.0.0.1:19092
export SUPER_STREAM_HELPER_AUTH_TOKEN=your-token
```

## Basic Usage

```php
use StreamLib\RabbitMqSuperStream\SuperStreamClient;

$client = new SuperStreamClient([
    'host' => 'rabbitmq',
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
```

`publish()` waits for broker confirmation by default and throws a typed PHP exception on failure.

## Configuration

Supported options:

- `host`
- `port`
- `username`
- `password`
- `vhost`
- `super_stream`
- `use_tls`
- `verify_tls`
- `tls_server_name`
- `connect_timeout_ms`
- `confirm_timeout_ms`
- `helper_rpc_timeout_ms`
- `helper_startup_timeout_ms`
- `helper_shutdown_timeout_ms`
- `helper_max_queue_size`
- `helper_transport_preference`
- `helper_runtime_dir`
- `helper_binary`
- `helper_endpoint`
- `helper_auth_token`

## Laravel

The package includes a Laravel service provider and facade. See [examples/laravel.md](examples/laravel.md).

## Internal Architecture

- PHP resolves or launches one helper per config hash.
- The helper listens on a Unix socket when it can. If not, it binds a random localhost TCP port.
- By default on Unix hosts, helper runtime files live under `/tmp/ssrs` to keep Unix socket paths short enough for macOS and Linux limits.
- PHP talks to the helper with raw HTTP+JSON over `stream_socket_client`.
- The helper owns one RabbitMQ environment and a per-partition producer set for the configured super stream.
- On startup, the helper queries the configured super stream partitions and connects only to the partitions that already exist.
- Publish requests are serialized through a bounded in-memory queue.
- Confirmation callbacks map broker confirms back to the originating PHP request id.

## Local Development

1. Install PHP dependencies:

```bash
composer install
```

2. Build the helper for your local machine:

```bash
make build-helper
```

3. Start RabbitMQ:

```bash
docker compose -f docker/compose.yml up -d
./docker/setup.sh
```

4. Run unit tests:

```bash
composer test
```

5. Run integration tests:

```bash
RUN_STREAM_INTEGRATION_TESTS=1 \
SUPER_STREAM_HELPER_BINARY=$PWD/resources/bin/$(go env GOOS)-$(go env GOARCH)/rabbitmq-super-stream-helper \
composer test:integration
```

6. Run the end-to-end example:

```bash
php examples/plain-php.php
```

## Troubleshooting

- `HelperBinaryNotFoundException`:
  - Build the helper with `make build-helper` or point `SUPER_STREAM_HELPER_BINARY` at a valid binary.
- `HelperStartupException`:
  - Inspect the helper log in the runtime directory. By default this is `/tmp/ssrs` on Unix hosts.
- `AuthenticationException`:
  - Check RabbitMQ stream credentials and vhost permissions.
- `PublishIndeterminateException`:
  - The helper could not prove whether the broker confirmed the publish before the timeout. Your application should treat this as potentially published and make its own idempotency decision.
- Stale runtime files:
  - Remove the relevant runtime directory under `/tmp/ssrs` on Unix, or your configured `helper_runtime_dir`.

## Packaging Notes

- End users consume the package through Composer.
- The Go helper is bundled as a standalone binary.
- `scripts/release-binaries.sh` cross-compiles the supported targets and places them under `resources/bin/...`.
- `scripts/package-release-assets.sh` creates `.tar.gz` release assets plus `SHA256SUMS` from the bundled helper binaries.

## Publishing To Packagist

1. Sign in to Packagist and submit the GitHub repository URL:

```text
https://github.com/standard-librarian/rabbitmq-super-stream
```

2. After the package is created on Packagist, copy your Packagist API token from your Packagist profile.

3. Add a GitHub webhook on this repository:

```text
Payload URL: https://packagist.org/api/github?username=YOUR_PACKAGIST_USERNAME
Content-Type: application/json
Secret: YOUR_PACKAGIST_API_TOKEN
```

4. Keep SSL verification enabled and use the default push event.

5. For every new package version:

```bash
git tag v0.1.x
git push origin v0.1.x
```

Packagist should auto-update from the webhook. If it does not, use the package page on Packagist and trigger a manual update.

## Verification Summary

The repo includes:

- PHP unit tests for config validation, binary resolution, manifest persistence, transport framing, and helper error mapping
- Go unit tests for config validation, manifest writing, and stream error mapping
- Docker-based integration tests that declare a super stream, publish through PHP, and verify the message is observable through a Go consumer
