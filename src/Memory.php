<?php

declare(strict_types=1);

namespace Laminas\Cache\Storage\Adapter;

use Laminas\Cache\Exception;
use Laminas\Cache\Storage\AbstractMetadataCapableAdapter;
use Laminas\Cache\Storage\Adapter\Memory\Metadata;
use Laminas\Cache\Storage\AvailableSpaceCapableInterface;
use Laminas\Cache\Storage\Capabilities;
use Laminas\Cache\Storage\ClearByNamespaceInterface;
use Laminas\Cache\Storage\ClearByPrefixInterface;
use Laminas\Cache\Storage\ClearExpiredInterface;
use Laminas\Cache\Storage\FlushableInterface;
use Laminas\Cache\Storage\IterableInterface;
use Laminas\Cache\Storage\TaggableInterface;
use Laminas\Cache\Storage\TotalSpaceCapableInterface;
use Traversable;

use function array_diff;
use function array_keys;
use function count;
use function max;
use function memory_get_usage;
use function round;
use function strpos;
use function time;

/**
 * @template-extends AbstractAdapter<MemoryOptions, Metadata>
 */
final class Memory extends AbstractMetadataCapableAdapter implements
    AvailableSpaceCapableInterface,
    ClearByPrefixInterface,
    ClearByNamespaceInterface,
    ClearExpiredInterface,
    FlushableInterface,
    IterableInterface,
    TaggableInterface,
    TotalSpaceCapableInterface
{
    /** @var array<string,array{0:mixed,1:int,tags?:list<string>}> */
    private array $data = [];

    /**
     * {@inheritDoc}
     */
    public function setOptions(array|Traversable|AdapterOptions|MemoryOptions $options): self
    {
        if (! $options instanceof MemoryOptions) {
            $options = new MemoryOptions($options);
        }

        parent::setOptions($options);
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getOptions(): MemoryOptions
    {
        if (! $this->options) {
            $this->setOptions(new MemoryOptions());
        }
        return $this->options;
    }

    /* TotalSpaceCapableInterface */

    /**
     * {@inheritDoc}
     */
    public function getTotalSpace(): int
    {
        return $this->getOptions()->getMemoryLimit();
    }

    /* AvailableSpaceCapableInterface */

    /**
     * {@inheritDoc}
     */
    public function getAvailableSpace(): int
    {
        $total = $this->getOptions()->getMemoryLimit();
        $avail = $total - (float) memory_get_usage(true);
        return (int) round(max($avail, 0));
    }

    /* IterableInterface */

    /**
     * {@inheritDoc}
     */
    public function getIterator(): KeyListIterator
    {
        $ns   = $this->getOptions()->getNamespace();
        $keys = [];

        if (isset($this->data[$ns])) {
            foreach ($this->data[$ns] as $key => &$tmp) {
                if ($this->internalHasItem($key)) {
                    $keys[] = $key;
                }
            }
        }

        return new KeyListIterator($this, $keys);
    }

    /* FlushableInterface */

    /**
     * {@inheritDoc}
     */
    public function flush(): bool
    {
        $this->data = [];
        return true;
    }

    /* ClearExpiredInterface */

    /**
     * {@inheritDoc}
     */
    public function clearExpired(): bool
    {
        $ttl = $this->getOptions()->getTtl();
        if ($ttl <= 0) {
            return true;
        }

        $ns = $this->getOptions()->getNamespace();
        if (! isset($this->data[$ns])) {
            return true;
        }

        $data = &$this->data[$ns];
        foreach ($data as $key => &$item) {
            if (time() >= $data[$key][1] + $ttl) {
                unset($data[$key]);
            }
        }

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function clearByNamespace(string $namespace): bool
    {
        if ($namespace === '') {
            throw new Exception\InvalidArgumentException('No namespace given');
        }

        unset($this->data[$namespace]);
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function clearByPrefix(string $prefix): bool
    {
        if ($prefix === '') {
            throw new Exception\InvalidArgumentException('No prefix given');
        }

        $ns = $this->getOptions()->getNamespace();
        if (! isset($this->data[$ns])) {
            return true;
        }

        $data = &$this->data[$ns];
        foreach ($data as $key => &$item) {
            if (strpos($key, $prefix) === 0) {
                unset($data[$key]);
            }
        }

        return true;
    }

    /* TaggableInterface */

    /**
     * {@inheritDoc}
     */
    public function setTags(string $key, array $tags): bool
    {
        $ns = $this->getOptions()->getNamespace();
        if (! isset($this->data[$ns][$key])) {
            return false;
        }

        $this->data[$ns][$key]['tags'] = $tags;
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function getTags(string $key): array|false
    {
        $ns = $this->getOptions()->getNamespace();
        if (! isset($this->data[$ns][$key])) {
            return false;
        }

        return $this->data[$ns][$key]['tags'] ?? [];
    }

    /**
     * {@inheritDoc}
     */
    public function clearByTags(array $tags, $disjunction = false): bool
    {
        $ns = $this->getOptions()->getNamespace();
        if (! isset($this->data[$ns])) {
            return true;
        }

        $tagCount = count($tags);
        $data     = &$this->data[$ns];
        foreach ($data as $key => &$item) {
            if (isset($item['tags'])) {
                $diff = array_diff($tags, $item['tags']);
                if (($disjunction && count($diff) < $tagCount) || (! $disjunction && ! $diff)) {
                    unset($data[$key]);
                }
            }
        }

        return true;
    }

    /* reading */

    /**
     * {@inheritDoc}
     */
    protected function internalGetItem(string $normalizedKey, bool|null &$success = null, mixed &$casToken = null): mixed
    {
        $options = $this->getOptions();
        $ns      = $options->getNamespace();
        $success = isset($this->data[$ns][$normalizedKey]);
        if ($success) {
            $data = &$this->data[$ns][$normalizedKey];
            $ttl  = $options->getTtl();
            if ($ttl && time() >= $data[1] + $ttl) {
                $success = false;
            }
        }

        if (! $success) {
            return null;
        }

        $casToken = $data[0];
        return $data[0];
    }

    /**
     * {@inheritDoc}
     */
    protected function internalGetItems(array $normalizedKeys): array
    {
        $options = $this->getOptions();
        $ns      = $options->getNamespace();
        if (! isset($this->data[$ns])) {
            return [];
        }

        $data = &$this->data[$ns];
        $ttl  = $options->getTtl();
        $now  = time();

        $result = [];
        foreach ($normalizedKeys as $normalizedKey) {
            if (isset($data[$normalizedKey])) {
                if (! $ttl || $now < $data[$normalizedKey][1] + $ttl) {
                    $result[$normalizedKey] = $data[$normalizedKey][0];
                }
            }
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    protected function internalHasItem(string $normalizedKey): bool
    {
        $options = $this->getOptions();
        $ns      = $options->getNamespace();
        if (! isset($this->data[$ns][$normalizedKey])) {
            return false;
        }

        // check if expired
        $ttl = $options->getTtl();
        if ($ttl && time() >= $this->data[$ns][$normalizedKey][1] + $ttl) {
            return false;
        }

        return true;
    }

    /**
     * {@inheritDoc}
     */
    protected function internalHasItems(array $normalizedKeys): array
    {
        $options = $this->getOptions();
        $ns      = $options->getNamespace();
        if (! isset($this->data[$ns])) {
            return [];
        }

        $data = &$this->data[$ns];
        $ttl  = $options->getTtl();
        $now  = time();

        $result = [];
        foreach ($normalizedKeys as $normalizedKey) {
            if (isset($data[$normalizedKey])) {
                if (! $ttl || $now < $data[$normalizedKey][1] + $ttl) {
                    $result[] = $normalizedKey;
                }
            }
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    protected function internalGetMetadata(string $normalizedKey): Metadata|null
    {
        if (! $this->internalHasItem($normalizedKey)) {
            return null;
        }

        $ns = $this->getOptions()->getNamespace();
        return new Metadata(
            mtime: $this->data[$ns][$normalizedKey][1],
        );
    }

    /* writing */

    /**
     * {@inheritDoc}
     */
    protected function internalSetItem(string $normalizedKey, mixed $value): bool
    {
        $options = $this->getOptions();

        if (! $this->hasAvailableSpace()) {
            $memoryLimit = $options->getMemoryLimit();
            throw new Exception\OutOfSpaceException(
                "Memory usage exceeds limit ({$memoryLimit})."
            );
        }

        $ns                              = $options->getNamespace();
        $this->data[$ns][$normalizedKey] = [$value, time()];

        return true;
    }

    /**
     * {@inheritDoc}
     */
    protected function internalSetItems(array $normalizedKeyValuePairs): array
    {
        $options = $this->getOptions();

        if (! $this->hasAvailableSpace()) {
            $memoryLimit = $options->getMemoryLimit();
            throw new Exception\OutOfSpaceException(
                "Memory usage exceeds limit ({$memoryLimit})."
            );
        }

        $ns = $options->getNamespace();
        if (! isset($this->data[$ns])) {
            $this->data[$ns] = [];
        }

        $data = &$this->data[$ns];
        $now  = time();
        foreach ($normalizedKeyValuePairs as $normalizedKey => $value) {
            $data[$normalizedKey] = [$value, $now];
        }

        return [];
    }

    /**
     * {@inheritDoc}
     */
    protected function internalAddItem(string $normalizedKey, mixed $value): bool
    {
        $options = $this->getOptions();

        if (! $this->hasAvailableSpace()) {
            $memoryLimit = $options->getMemoryLimit();
            throw new Exception\OutOfSpaceException(
                "Memory usage exceeds limit ({$memoryLimit})."
            );
        }

        $ns = $options->getNamespace();
        if (isset($this->data[$ns][$normalizedKey])) {
            return false;
        }

        $this->data[$ns][$normalizedKey] = [$value, time()];
        return true;
    }

    /**
     * {@inheritDoc}
     */
    protected function internalAddItems(array $normalizedKeyValuePairs): array
    {
        $options = $this->getOptions();

        if (! $this->hasAvailableSpace()) {
            $memoryLimit = $options->getMemoryLimit();
            throw new Exception\OutOfSpaceException(
                "Memory usage exceeds limit ({$memoryLimit})."
            );
        }

        $ns = $options->getNamespace();
        if (! isset($this->data[$ns])) {
            $this->data[$ns] = [];
        }

        $result = [];
        $data   = &$this->data[$ns];
        $now    = time();
        foreach ($normalizedKeyValuePairs as $normalizedKey => $value) {
            if (isset($data[$normalizedKey])) {
                $result[] = $normalizedKey;
            } else {
                $data[$normalizedKey] = [$value, $now];
            }
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    protected function internalReplaceItem(string $normalizedKey, mixed $value): bool
    {
        $ns = $this->getOptions()->getNamespace();
        if (! isset($this->data[$ns][$normalizedKey])) {
            return false;
        }
        $this->data[$ns][$normalizedKey] = [$value, time()];

        return true;
    }

    /**
     * {@inheritDoc}
     */
    protected function internalReplaceItems(array $normalizedKeyValuePairs): array
    {
        $ns = $this->getOptions()->getNamespace();
        if (! isset($this->data[$ns])) {
            return array_keys($normalizedKeyValuePairs);
        }

        $result = [];
        $data   = &$this->data[$ns];
        foreach ($normalizedKeyValuePairs as $normalizedKey => $value) {
            if (! isset($data[$normalizedKey])) {
                $result[] = $normalizedKey;
            } else {
                $data[$normalizedKey] = [$value, time()];
            }
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    protected function internalTouchItem(string $normalizedKey): bool
    {
        $ns = $this->getOptions()->getNamespace();

        if (! isset($this->data[$ns][$normalizedKey])) {
            return false;
        }

        $this->data[$ns][$normalizedKey][1] = time();
        return true;
    }

    /**
     * {@inheritDoc}
     */
    protected function internalRemoveItem(string $normalizedKey): bool
    {
        $ns = $this->getOptions()->getNamespace();
        if (! isset($this->data[$ns][$normalizedKey])) {
            return false;
        }

        unset($this->data[$ns][$normalizedKey]);

        // remove empty namespace
        if (! $this->data[$ns]) {
            unset($this->data[$ns]);
        }

        return true;
    }

    /* status */

    /**
     * {@inheritDoc}
     */
    protected function internalGetCapabilities(): Capabilities
    {
        return $this->capabilities ??= new Capabilities(
            maxKeyLength: Capabilities::UNLIMITED_KEY_LENGTH,
            ttlSupported: true,
            namespaceIsPrefix: false,
            supportedDataTypes: [
                'NULL'     => true,
                'boolean'  => true,
                'integer'  => true,
                'double'   => true,
                'string'   => true,
                'array'    => true,
                'object'   => true,
                'resource' => true,
            ],
            ttlPrecision: 1,
        );
    }

    /* internal */

    private function hasAvailableSpace(): bool
    {
        $total = $this->getOptions()->getMemoryLimit();

        // check memory limit disabled
        if ($total <= 0) {
            return true;
        }

        $free = $total - (float) memory_get_usage(true);
        return $free > 0;
    }
}
