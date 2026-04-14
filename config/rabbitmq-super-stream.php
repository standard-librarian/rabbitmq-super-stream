<?php

return [
    'default' => 'default',
    'connections' => [
        'default' => [
            'host' => env('RABBITMQ_STREAM_HOST', '127.0.0.1'),
            'port' => (int) env('RABBITMQ_STREAM_PORT', 5552),
            'username' => env('RABBITMQ_STREAM_USERNAME', 'guest'),
            'password' => env('RABBITMQ_STREAM_PASSWORD', 'guest'),
            'vhost' => env('RABBITMQ_STREAM_VHOST', '/'),
            'super_stream' => env('RABBITMQ_SUPER_STREAM', 'orders'),
            'use_tls' => (bool) env('RABBITMQ_STREAM_TLS', false),
            'verify_tls' => (bool) env('RABBITMQ_STREAM_TLS_VERIFY', true),
            'tls_server_name' => env('RABBITMQ_STREAM_TLS_SERVER_NAME'),
            'connect_timeout_ms' => (int) env('RABBITMQ_STREAM_CONNECT_TIMEOUT_MS', 3000),
            'confirm_timeout_ms' => (int) env('RABBITMQ_STREAM_CONFIRM_TIMEOUT_MS', 10000),
            'helper_rpc_timeout_ms' => (int) env('RABBITMQ_STREAM_HELPER_RPC_TIMEOUT_MS', 15000),
            'helper_startup_timeout_ms' => (int) env('RABBITMQ_STREAM_HELPER_STARTUP_TIMEOUT_MS', 10000),
            'helper_shutdown_timeout_ms' => (int) env('RABBITMQ_STREAM_HELPER_SHUTDOWN_TIMEOUT_MS', 5000),
            'helper_max_queue_size' => (int) env('RABBITMQ_STREAM_HELPER_MAX_QUEUE_SIZE', 1024),
            'helper_transport_preference' => env('RABBITMQ_STREAM_HELPER_TRANSPORT', 'auto'),
            'helper_runtime_dir' => env('RABBITMQ_STREAM_HELPER_RUNTIME_DIR', DIRECTORY_SEPARATOR === '/' ? '/tmp/ssrs' : null),
            'helper_binary' => env('SUPER_STREAM_HELPER_BINARY'),
            'helper_endpoint' => env('SUPER_STREAM_HELPER_ENDPOINT'),
            'helper_auth_token' => env('SUPER_STREAM_HELPER_AUTH_TOKEN'),
        ],
    ],
];
