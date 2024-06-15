<?php

declare(strict_types=1);

namespace LaminasTest\Cache\Storage\Adapter;

use Laminas\Cache\Exception\OutOfSpaceException;
use Laminas\Cache\Storage\Adapter\Memory;
use Laminas\Cache\Storage\Adapter\MemoryOptions;

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
        $this->storage = new Memory();
        $this->storage->setOptions($this->options);

        parent::setUp();
    }

    public function testThrowOutOfSpaceException()
    {
        $this->options->setMemoryLimit(memory_get_usage(true) - 8);

        $this->expectException(OutOfSpaceException::class);
        $this->storage->addItem('test', 'test');
    }
}
