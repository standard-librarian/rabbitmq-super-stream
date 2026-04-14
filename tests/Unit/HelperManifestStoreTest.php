<?php

declare(strict_types=1);

namespace StreamLib\RabbitMqSuperStream\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StreamLib\RabbitMqSuperStream\Internal\HelperManifestStore;

final class HelperManifestStoreTest extends TestCase
{
    public function test_it_writes_and_reads_a_manifest(): void
    {
        $directory = sys_get_temp_dir() . '/stream-lib-tests/' . bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        $path = $directory . '/manifest.json';

        $store = new HelperManifestStore();
        $store->write($path, [
            'endpoint' => 'tcp://127.0.0.1:9999',
            'transport' => 'tcp',
            'pid' => 1234,
        ]);

        self::assertSame('tcp://127.0.0.1:9999', $store->read($path)['endpoint']);
    }
}
