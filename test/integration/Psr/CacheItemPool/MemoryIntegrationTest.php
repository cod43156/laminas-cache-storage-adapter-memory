<?php

declare(strict_types=1);

namespace LaminasTest\Cache\Psr\CacheItemPool;

use Laminas\Cache\Storage\Adapter\Memory;
use Laminas\Cache\Storage\StorageInterface;
use LaminasTest\Cache\Storage\Adapter\AbstractCacheItemPoolIntegrationTest;

final class MemoryIntegrationTest extends AbstractCacheItemPoolIntegrationTest
{
    private const TRANSIENT_STORAGE = 'Memory cache is not persistent and thus re-instantiating leads to data loss.';
    /** @var array<non-empty-string,non-empty-string> */
    protected array $skippedTests = [
        'testSaveWithoutExpire'         => self::TRANSIENT_STORAGE,
        'testDeferredSaveWithoutCommit' => self::TRANSIENT_STORAGE,
    ];

    protected function createStorage(): StorageInterface
    {
        return new Memory();
    }
}
