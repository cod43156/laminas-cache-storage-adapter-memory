<?php

declare(strict_types=1);

namespace Laminas\Cache\Storage\Adapter;

use Laminas\Cache\Exception;
use Laminas\Stdlib\AbstractOptions;

use function ini_get;
use function is_numeric;
use function preg_match;
use function strtoupper;

final class MemoryOptions extends AdapterOptions
{
    public const UNLIMITED_MEMORY = 0;

    protected int $memoryLimit;
    private bool $defaultMemoryLimit = true;

    /**
     * @param iterable<string,mixed>|AbstractOptions|null $options
     */
    public function __construct(iterable|AbstractOptions|null $options = null)
    {
        // By default use half of PHP's memory limit if possible
        $memoryLimit = $this->normalizeMemoryLimit((string) ini_get('memory_limit'));
        if ($memoryLimit >= 0) {
            $this->memoryLimit = (int) ($memoryLimit / 2);
        } else {
            $this->memoryLimit = self::UNLIMITED_MEMORY;
        }

        parent::__construct($options);
    }

    /**
     * - A number less or equal 0 will disable the memory limit
     * - When a number is used, the value is measured in bytes. Shorthand notation may also be used.
     * - If the used memory of PHP exceeds this limit an OutOfSpaceException
     *   will be thrown.
     *
     * @link http://php.net/manual/faq.using.php#faq.using.shorthandbytes
     *
     * @psalm-api
     */
    public function setMemoryLimit(string|int $memoryLimit): MemoryOptions
    {
        $memoryLimit = $this->normalizeMemoryLimit($memoryLimit);

        if ($this->defaultMemoryLimit === false && $this->memoryLimit !== $memoryLimit) {
            $this->triggerOptionEvent('memory_limit', $memoryLimit);
        }
        $this->defaultMemoryLimit = false;
        $this->memoryLimit        = $memoryLimit;

        return $this;
    }

    /**
     * Get memory limit
     *
     * If the used memory of PHP exceeds this limit an OutOfSpaceException
     * will be thrown.
     */
    public function getMemoryLimit(): int
    {
        return $this->memoryLimit;
    }

    /**
     * Normalized a given value of memory limit into the number of bytes
     *
     * @throws Exception\InvalidArgumentException
     */
    protected function normalizeMemoryLimit(string|int $value): int
    {
        if (is_numeric($value)) {
            return (int) $value;
        }

        if (! preg_match('/(\-?\d+)\s*(\w*)/', (string) ini_get('memory_limit'), $matches)) {
            throw new Exception\InvalidArgumentException("Invalid  memory limit '{$value}'");
        }

        $value = (int) $matches[1];
        if ($value <= 0) {
            return 0;
        }

        switch (strtoupper($matches[2])) {
            case 'G':
                $value *= 1024;
                // no break

            case 'M':
                $value *= 1024;
                // no break

            case 'K':
                $value *= 1024;
                // no break
        }

        return $value;
    }
}
