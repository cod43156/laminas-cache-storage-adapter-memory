<?php

declare(strict_types=1);

namespace LaminasTest\Cache\Storage\Adapter;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Laminas\Cache\Exception\OutOfSpaceException;
use Laminas\Cache\Storage\Adapter\Memory;
use Laminas\Cache\Storage\Adapter\MemoryOptions;
use Lcobucci\Clock\FrozenClock;

use function assert;
use function memory_get_usage;

/**
 * @template-extends AbstractCommonAdapterTest<Memory, MemoryOptions>
 */
final class MemoryTest extends AbstractCommonAdapterTest
{
    public function setUp(): void
    {
        // instantiate memory adapter
        $this->options = new MemoryOptions();
        $this->storage = new Memory($this->options);

        parent::setUp();
    }

    public function testThrowOutOfSpaceException()
    {
        $this->options->setMemoryLimit(memory_get_usage(true) - 8);

        $this->expectException(OutOfSpaceException::class);
        $this->storage->addItem('test', 'test');
    }

    public function testMetadataIsCreatedAndModifiedAccordingly(): void
    {
        $clock   = new FrozenClock(new DateTimeImmutable('2024-06-15 21:55:31', new DateTimeZone('Europe/Berlin')));
        $storage = new Memory(clock: $clock);

        $storage->setItem('foo', 'bar');
        $metadata = $storage->getMetadata('foo');
        self::assertNotNull($metadata);
        $now = $clock->now();
        self::assertSame($now->getTimestamp(), $metadata->ctime);
        self::assertSame($now->getTimestamp(), $metadata->mtime);

        $interval = DateInterval::createFromDateString('2 days');
        assert($interval !== false);

        $clock->setTo($now->add($interval));
        $storage->touchItem('foo');

        $future   = $clock->now();
        $metadata = $storage->getMetadata('foo');
        self::assertNotNull($metadata);
        self::assertSame($now->getTimestamp(), $metadata->ctime);
        self::assertSame($future->getTimestamp(), $metadata->mtime);
    }
}
