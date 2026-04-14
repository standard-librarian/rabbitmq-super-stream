<?php

declare(strict_types=1);

namespace StreamLib\RabbitMqSuperStream\Internal;

use StreamLib\RabbitMqSuperStream\Exception\HelperStartupException;

final class HelperManifestStore
{
    /**
     * @return array<string, mixed>|null
     */
    public function read(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false || $contents === '') {
            return null;
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    public function write(string $path, array $manifest): void
    {
        $temporary = $path . '.tmp';
        $written = file_put_contents($temporary, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        if ($written === false) {
            throw new HelperStartupException(sprintf('Unable to write helper manifest at "%s".', $path));
        }

        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new HelperStartupException(sprintf('Unable to atomically replace helper manifest at "%s".', $path));
        }
    }
}
