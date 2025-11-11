<?php

namespace mini\Metadata;

use mini\Util\InstanceStore;
use mini\Mini;

/**
 * Registry for metadata instances with auto-building from attributes
 *
 * Stores Metadata by class name or custom identifiers.
 * Automatically builds metadata from class attributes when accessing unknown classes.
 *
 * Accessed via Mini::$mini->get(MetadataStore::class) or the metadata() helper.
 *
 * Example:
 * ```php
 * $store = Mini::$mini->get(MetadataStore::class);
 * $store[User::class] = (new Metadata())->title('User')->description('User account');
 *
 * // Or access directly to auto-build from attributes:
 * $userMeta = $store->get(User::class); // Builds from attributes if not cached
 * ```
 *
 * @extends InstanceStore<Metadata>
 */
class MetadataStore extends InstanceStore
{
    public function __construct()
    {
        parent::__construct(Metadata::class);
    }

    /**
     * Get metadata by key, auto-building from class attributes if needed
     *
     * @param string $key Class name or custom identifier
     * @return Metadata|null Metadata instance, or null if not found and not a class
     */
    public function get(mixed $key): mixed
    {
        // Return cached if exists
        if ($this->has($key)) {
            return parent::get($key);
        }

        // If it's a class or interface, build from attributes
        if (class_exists($key) || interface_exists($key)) {
            $factory = Mini::$mini->get(AttributeMetadataFactory::class);
            $metadata = $factory->forClass($key);

            // Cache it
            $this->set($key, $metadata);

            return $metadata;
        }

        // Not found and not a class
        return null;
    }

    /**
     * Magic getter - auto-builds from attributes if needed
     *
     * @param string $key
     * @return Metadata
     * @throws \RuntimeException If key not found and not a valid class
     */
    public function __get(mixed $key): mixed
    {
        $metadata = $this->get($key);

        if ($metadata === null) {
            throw new \RuntimeException("Metadata '$key' not found. Register it in MetadataStore or ensure the class exists.");
        }

        return $metadata;
    }
}
