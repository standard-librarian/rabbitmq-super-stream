<?php

declare(strict_types=1);

namespace StreamLib\RabbitMqSuperStream\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StreamLib\RabbitMqSuperStream\Exception\AuthenticationException;
use StreamLib\RabbitMqSuperStream\Exception\PublishIndeterminateException;
use StreamLib\RabbitMqSuperStream\Exception\SuperStreamNotFoundException;
use StreamLib\RabbitMqSuperStream\Internal\HelperErrorMapper;

final class HelperErrorMapperTest extends TestCase
{
    public function test_it_maps_super_stream_not_found_errors(): void
    {
        $exception = (new HelperErrorMapper())->map([
            'code' => 'super_stream_not_found',
            'message' => 'missing',
            'details' => ['foo' => 'bar'],
        ]);

        self::assertInstanceOf(SuperStreamNotFoundException::class, $exception);
        self::assertSame(['foo' => 'bar'], $exception->context());
    }

    public function test_it_maps_authentication_errors(): void
    {
        $exception = (new HelperErrorMapper())->map([
            'code' => 'authentication_failed',
            'message' => 'bad creds',
        ]);

        self::assertInstanceOf(AuthenticationException::class, $exception);
    }

    public function test_it_maps_indeterminate_publish_errors(): void
    {
        $exception = (new HelperErrorMapper())->map([
            'code' => 'publish_indeterminate',
            'message' => 'timeout',
        ]);

        self::assertInstanceOf(PublishIndeterminateException::class, $exception);
    }
}
