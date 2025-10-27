<?php

namespace mini;

/**
 * Service lifetime for dependency injection container
 *
 * Defines how long a service instance should be reused.
 */
enum Lifetime
{
    /**
     * Singleton - One instance for the entire application lifetime
     *
     * Created once and reused across all requests in long-running applications.
     * Use for stateless services or services that manage their own state.
     */
    case Singleton;

    /**
     * Scoped - One instance per request scope
     *
     * Created once per request/fiber and reused within that scope.
     * Automatically cleaned up when request completes.
     * Use for services that should be fresh per request (database connections, etc.).
     */
    case Scoped;

    /**
     * Transient - New instance every time
     *
     * Created fresh on every call to get().
     * Use for lightweight objects or when you specifically need new instances.
     */
    case Transient;
}
