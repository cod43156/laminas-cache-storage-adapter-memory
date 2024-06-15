<?php

declare(strict_types=1);

namespace Laminas\Cache\Storage\Adapter\Memory;

final class Metadata
{
    public function __construct(
        public readonly int $mtime,
    ) {
    }
}
