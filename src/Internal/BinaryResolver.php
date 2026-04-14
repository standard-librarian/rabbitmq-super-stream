<?php

declare(strict_types=1);

namespace StreamLib\RabbitMqSuperStream\Internal;

use StreamLib\RabbitMqSuperStream\Config\SuperStreamConfig;
use StreamLib\RabbitMqSuperStream\Exception\HelperBinaryNotFoundException;

final class BinaryResolver
{
    public function resolve(SuperStreamConfig $config): string
    {
        $override = $config->helperBinary;
        if ($override !== null) {
            return $this->assertExecutable($override, true);
        }

        $platform = $this->platformKey();
        $source = dirname(__DIR__, 2) . '/resources/bin/' . $platform . '/rabbitmq-super-stream-helper';
        if (!is_file($source)) {
            throw new HelperBinaryNotFoundException(sprintf('No bundled helper binary is available for platform "%s".', $platform), [
                'platform' => $platform,
                'source' => $source,
            ]);
        }

        $cacheDir = sys_get_temp_dir() . '/stream-lib-rabbitmq-super-stream/bin/' . $platform;
        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0700, true) && !is_dir($cacheDir)) {
            throw new HelperBinaryNotFoundException(sprintf('Unable to create helper binary cache directory "%s".', $cacheDir));
        }

        $target = $cacheDir . '/rabbitmq-super-stream-helper';
        if (!is_file($target) || sha1_file($source) !== sha1_file($target)) {
            if (!copy($source, $target)) {
                throw new HelperBinaryNotFoundException(sprintf('Unable to copy helper binary from "%s" to "%s".', $source, $target));
            }
        }

        @chmod($target, 0700);

        return $this->assertExecutable($target, false);
    }

    private function platformKey(): string
    {
        $os = match (PHP_OS_FAMILY) {
            'Darwin' => 'darwin',
            'Linux' => 'linux',
            default => throw new HelperBinaryNotFoundException(sprintf('Unsupported operating system "%s".', PHP_OS_FAMILY)),
        };

        $arch = strtolower((string) php_uname('m'));
        $arch = match ($arch) {
            'x86_64', 'amd64' => 'amd64',
            'arm64', 'aarch64' => 'arm64',
            default => throw new HelperBinaryNotFoundException(sprintf('Unsupported architecture "%s".', $arch)),
        };

        return $os . '-' . $arch;
    }

    private function assertExecutable(string $path, bool $userProvided): string
    {
        if (!is_file($path)) {
            throw new HelperBinaryNotFoundException(sprintf('The helper binary "%s" does not exist.', $path));
        }

        if (!is_executable($path)) {
            @chmod($path, 0700);
        }

        if (!is_executable($path)) {
            throw new HelperBinaryNotFoundException(sprintf('The helper binary "%s" is not executable.', $path), [
                'path' => $path,
                'user_provided' => $userProvided,
            ]);
        }

        return $path;
    }
}
