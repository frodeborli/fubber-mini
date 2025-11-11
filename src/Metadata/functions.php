<?php

namespace mini;

use mini\Metadata\Metadata;
use mini\Metadata\MetadataStore;
use mini\Metadata\AttributeMetadataFactory;

// Register Metadata services
Mini::$mini->addService(Metadata::class, Lifetime::Transient, fn() => new Metadata());
Mini::$mini->addService(MetadataStore::class, Lifetime::Singleton, fn() => new MetadataStore());
Mini::$mini->addService(AttributeMetadataFactory::class, Lifetime::Singleton, fn() => new AttributeMetadataFactory());

/**
 * Get or create a Metadata instance
 *
 * With no arguments: Returns a new Metadata for building annotations.
 * With class name: Returns metadata built from class attributes (auto-cached by MetadataStore).
 * With custom identifier: Returns cached metadata from the store.
 *
 * Examples:
 * ```php
 * // New metadata
 * $m = metadata()->title('Username')->description('User login identifier');
 *
 * // From class attributes (auto-built and cached)
 * $userMeta = metadata(User::class);
 *
 * // Access property metadata
 * $usernameMeta = metadata(User::class)->username;
 *
 * // Manually store metadata
 * Mini::$mini->get(MetadataStore::class)[User::class] = metadata()
 *     ->title('User')
 *     ->properties([
 *         'username' => metadata()->title('Username')->readOnly(true)
 *     ]);
 * ```
 *
 * @param class-string|string|null $classOrName Class name or custom identifier
 * @return Metadata Metadata instance (empty if not found and not a class)
 */
function metadata(?string $classOrName = null): Metadata
{
    // No argument: return new metadata
    if ($classOrName === null) {
        return Mini::$mini->get(Metadata::class);
    }

    $store = Mini::$mini->get(MetadataStore::class);

    // Get from store (auto-builds from attributes if class/interface)
    $metadata = $store->get($classOrName);

    // Return metadata or empty instance if not found
    // This enables reading from unregistered identifiers without throwing exceptions
    return $metadata ?? new Metadata();
}
