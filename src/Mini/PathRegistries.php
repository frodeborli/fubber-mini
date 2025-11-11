<?php

namespace mini\Mini;

use mini\Util\InstanceStore;
use mini\Util\PathsRegistry;

/**
 * Container for path registries used by the framework
 *
 * Provides named PathsRegistry instances for different resource types (config, routes, views, translations).
 * Each registry supports priority-based file resolution with application paths taking precedence over
 * framework and bundle fallbacks.
 *
 * @extends InstanceStore<string, PathsRegistry>
 */
class PathRegistries extends InstanceStore
{
    public function __construct()
    {
        parent::__construct(PathsRegistry::class);
    }
}
